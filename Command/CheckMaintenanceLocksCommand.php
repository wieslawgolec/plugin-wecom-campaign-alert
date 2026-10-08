<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Command;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\WeComCampaignAlertBundle\Helper\CommandGuard;
use MauticPlugin\WeComCampaignAlertBundle\Helper\MessageFormatter;
use MauticPlugin\WeComCampaignAlertBundle\Helper\NoticeTracker;
use MauticPlugin\WeComCampaignAlertBundle\Helper\WeComSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron: mautic:wecom:check-maintenance-locks.
 *
 * During the configured hour window, if any maintenance lock file has been present
 * for >= stall_hours, send one WeCom alert per calendar day (Asia/Shanghai).
 *
 * Intentionally ignores the usual "skip when lock exists" gate so it can run while locked.
 */
#[AsCommand(
    name: 'mautic:wecom:check-maintenance-locks',
    description: 'Alert if maintenance lock files persist beyond the stall threshold during the watch window.'
)]
class CheckMaintenanceLocksCommand extends Command
{
    private const TZ = 'Asia/Shanghai';

    private const STATE_SINCE   = 'maintenance_lock_since';
    private const STATE_ALERTED = 'maintenance_lock_alerted_date';

    public function __construct(
        private readonly CommandGuard $commandGuard,
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly NoticeTracker $noticeTracker,
        private readonly WeComSender $weComSender,
        private readonly MessageFormatter $messageFormatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bypass plugin disabled; also re-alert same day')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not send, only report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        // Must ignore maintenance locks — this command exists to watch them
        if ($reason = $this->commandGuard->getBlockReason($force, true)) {
            $output->writeln('<comment>'.$reason.'</comment>');

            return Command::SUCCESS;
        }

        if (!filter_var($this->coreParametersHelper->get('wecom_campaign_alert_maintenance_stall_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $output->writeln('<comment>Maintenance lock stall alerts disabled.</comment>');

            return Command::SUCCESS;
        }

        $startHour = $this->clampHour($this->coreParametersHelper->get('wecom_campaign_alert_maintenance_window_start', 9));
        $endHour   = $this->clampHour($this->coreParametersHelper->get('wecom_campaign_alert_maintenance_window_end', 18));
        $stallHours = max(1, (int) $this->coreParametersHelper->get('wecom_campaign_alert_stall_hours', 1));

        $tz  = new \DateTimeZone(self::TZ);
        $now = new \DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');

        if (!$this->isInWindow($now, $startHour, $endHour)) {
            $output->writeln(sprintf(
                '<comment>Outside watch window (%02d:00–%02d:00 %s). Now %s.</comment>',
                $startHour,
                $endHour,
                self::TZ,
                $now->format('Y-m-d H:i T')
            ));
            // Outside window: clear "since" so a lock that spans overnight is measured from next window entry
            $this->noticeTracker->setState(self::STATE_SINCE, '');

            return Command::SUCCESS;
        }

        $locks = $this->commandGuard->getActiveMaintenanceLocks();
        if ([] === $locks) {
            $output->writeln('<info>No maintenance lock files present.</info>');
            $this->noticeTracker->setState(self::STATE_SINCE, '');

            return Command::SUCCESS;
        }

        $output->writeln('<comment>Active locks:</comment>');
        foreach ($locks as $lock) {
            $output->writeln('  - '.$lock);
        }

        $sinceRaw = $this->noticeTracker->getState(self::STATE_SINCE);
        $since    = null;
        if (null !== $sinceRaw && '' !== $sinceRaw) {
            try {
                $since = new \DateTimeImmutable($sinceRaw);
            } catch (\Exception) {
                $since = null;
            }
        }

        // Anchor: later of first observation and today's window start (local)
        $windowStart = $now->setTime($startHour, 0, 0);
        // If window wraps midnight and we are in the early-morning segment, window started yesterday
        if ($startHour > $endHour && (int) $now->format('G') < $endHour) {
            $windowStart = $windowStart->modify('-1 day');
        }

        if (null === $since) {
            // Prefer oldest lock mtime if available and still inside window
            $mtimeSince = $this->oldestLockMtime($locks);
            $since      = $mtimeSince ?? $now;
            if ($since < $windowStart) {
                $since = $windowStart;
            }
            $this->noticeTracker->setState(self::STATE_SINCE, $since->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
            $output->writeln(sprintf('<info>Lock watch started at %s</info>', $since->setTimezone($tz)->format('Y-m-d H:i T')));
        }

        $elapsed = $now->getTimestamp() - $since->getTimestamp();
        $threshold = $stallHours * 3600;
        $output->writeln(sprintf(
            '<comment>Unchanged since %s (%ds / threshold %ds).</comment>',
            $since->setTimezone($tz)->format('Y-m-d H:i T'),
            $elapsed,
            $threshold
        ));

        if ($elapsed < $threshold) {
            $output->writeln('<info>Below stall threshold — no alert.</info>');

            return Command::SUCCESS;
        }

        $alertedDate = $this->noticeTracker->getState(self::STATE_ALERTED);
        if (!$force && $alertedDate === $today) {
            $output->writeln('<comment>Already alerted for maintenance locks today.</comment>');

            return Command::SUCCESS;
        }

        $content = $this->messageFormatter->formatMaintenanceLockStallAlert([
            'since' => $since,
            'locks' => $locks,
        ], $this->weComSender->getMsgType());

        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('<comment>Dry-run — message:</comment>');
            $output->writeln($content);

            return Command::SUCCESS;
        }

        $ok = $this->weComSender->send($content, [
            'type' => 'maintenance_lock_stall',
            'date' => $today,
        ]);

        if ($ok) {
            $this->noticeTracker->setState(self::STATE_ALERTED, $today);
            $output->writeln('<info>Maintenance lock stall alert sent.</info>');
        } else {
            $output->writeln('<error>Failed to send maintenance lock stall alert.</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function clampHour(mixed $value): int
    {
        $h = (int) $value;

        return max(0, min(23, $h));
    }

    private function isInWindow(\DateTimeImmutable $nowLocal, int $startHour, int $endHour): bool
    {
        $hour = (int) $nowLocal->format('G');
        if ($startHour === $endHour) {
            // Full day
            return true;
        }
        if ($startHour < $endHour) {
            return $hour >= $startHour && $hour < $endHour;
        }

        // Wraps midnight: e.g. 22–6
        return $hour >= $startHour || $hour < $endHour;
    }

    /**
     * @param list<string> $locks
     */
    private function oldestLockMtime(array $locks): ?\DateTimeImmutable
    {
        $oldest = null;
        foreach ($locks as $path) {
            $mtime = @filemtime($path);
            if (false === $mtime) {
                continue;
            }
            if (null === $oldest || $mtime < $oldest) {
                $oldest = $mtime;
            }
        }

        if (null === $oldest) {
            return null;
        }

        return (new \DateTimeImmutable('@'.$oldest))->setTimezone(new \DateTimeZone(self::TZ));
    }
}
