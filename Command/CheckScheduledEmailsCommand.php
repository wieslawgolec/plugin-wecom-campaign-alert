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
 * Cron: mautic:wecom:check-scheduled-emails.
 *
 * Alert via WeCom if scheduled email.send count is unchanged for N hours.
 */
#[AsCommand(
    name: 'mautic:wecom:check-scheduled-emails',
    description: 'Send WeCom alert if scheduled campaign email count is unchanged for the stall period.'
)]
class CheckScheduledEmailsCommand extends Command
{
    private const STATE_COUNT   = 'scheduled_email_count';
    private const STATE_SINCE   = 'scheduled_email_count_since';
    private const STATE_ALERTED = 'scheduled_email_stalled_alerted';

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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bypass plugin/maintenance gates; also re-alert if already alerted for this stall period')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not send, only report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        if ($reason = $this->commandGuard->getBlockReason($force)) {
            $output->writeln('<comment>'.$reason.'</comment>');

            return Command::SUCCESS;
        }

        if (!$this->coreParametersHelper->get('wecom_campaign_alert_stall_enabled', true)) {
            $output->writeln('<comment>Stall detection disabled.</comment>');

            return Command::SUCCESS;
        }

        $currentCount = $this->noticeTracker->countScheduledEmailSends();
        $hours        = max(1, (int) $this->coreParametersHelper->get('wecom_campaign_alert_stall_hours', 1));
        $now          = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $prevCount = $this->noticeTracker->getState(self::STATE_COUNT);
        $sinceRaw  = $this->noticeTracker->getState(self::STATE_SINCE);
        $alerted   = $this->noticeTracker->getState(self::STATE_ALERTED);

        $output->writeln(sprintf('<info>Current scheduled email.send count: %d</info>', $currentCount));

        if (null === $prevCount || (int) $prevCount !== $currentCount) {
            $this->noticeTracker->setState(self::STATE_COUNT, (string) $currentCount);
            $this->noticeTracker->setState(self::STATE_SINCE, $now->format(\DateTimeInterface::ATOM));
            $this->noticeTracker->setState(self::STATE_ALERTED, '0');

            $output->writeln(sprintf(
                '<info>Count changed (was %s). Resetting stall window at %s.</info>',
                $prevCount ?? 'n/a',
                $now->format(\DateTimeInterface::ATOM)
            ));

            return Command::SUCCESS;
        }

        if (0 === $currentCount) {
            $output->writeln('<info>No scheduled emails; nothing stalled.</info>');

            return Command::SUCCESS;
        }

        $since = $sinceRaw
            ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $sinceRaw)
            : $now;
        if (!$since) {
            $since = $now;
        }

        $elapsedSeconds = $now->getTimestamp() - $since->getTimestamp();
        $threshold      = $hours * 3600;

        $output->writeln(sprintf(
            '<comment>Count unchanged since %s (%d s; threshold %d).</comment>',
            $since->format(\DateTimeInterface::ATOM),
            $elapsedSeconds,
            $threshold
        ));

        if ($elapsedSeconds < $threshold) {
            $output->writeln('<info>Still within stall window; no alert.</info>');

            return Command::SUCCESS;
        }

        if ('1' === $alerted && !$input->getOption('force')) {
            $output->writeln('<comment>Already alerted for this stall period; use --force to resend.</comment>');

            return Command::SUCCESS;
        }

        $content = $this->messageFormatter->formatStallAlert(
            [
                'count' => $currentCount,
                'hours' => $hours,
                'since' => $since->format(\DateTimeInterface::ATOM),
            ],
            $this->weComSender->getMsgType()
        );

        if ($input->getOption('dry-run')) {
            $output->writeln("<info>[dry-run] Would send:\n".$content.'</info>');

            return Command::SUCCESS;
        }

        $ok = $this->weComSender->send($content, [
            'type'            => 'scheduled_emails_stalled',
            'scheduled_count' => $currentCount,
        ]);

        if ($ok) {
            $this->noticeTracker->setState(self::STATE_ALERTED, '1');
            $output->writeln('<info>Stall WeCom alert sent.</info>');
        } else {
            $output->writeln('<error>Failed to send WeCom alert (check logs / key).</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
