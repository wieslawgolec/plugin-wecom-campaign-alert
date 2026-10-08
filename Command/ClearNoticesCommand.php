<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Command;

use MauticPlugin\WeComCampaignAlertBundle\Helper\CommandGuard;
use MauticPlugin\WeComCampaignAlertBundle\Helper\NoticeTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clear once-only WeCom notice markers so alerts can fire again after campaign reset.
 *
 * Cron example (daily after your campaign reset / email_stats purge):
 *   php bin/console mautic:wecom:clear-notices --campaign-id=12
 *   php bin/console mautic:wecom:clear-notices --all
 *   php bin/console mautic:wecom:clear-notices --older-than=24h
 */
#[AsCommand(
    name: 'mautic:wecom:clear-notices',
    description: 'Clear WeCom notice tracker rows (after recurring campaign reset).'
)]
class ClearNoticesCommand extends Command
{
    public function __construct(
        private readonly CommandGuard $commandGuard,
        private readonly NoticeTracker $noticeTracker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bypass plugin/maintenance gates')
            ->addOption('campaign-id', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Campaign ID(s) to clear')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Clear notices for all campaigns')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to event type(s): email_send, email_open, campaign_restart')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Clear notices older than duration (e.g. 24h, 1day, 7days)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be cleared without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        if ($reason = $this->commandGuard->getBlockReason($force)) {
            $output->writeln('<comment>'.$reason.'</comment>');

            return Command::SUCCESS;
        }

        $all        = (bool) $input->getOption('all');
        $campaignIds = array_map('intval', (array) $input->getOption('campaign-id'));
        $campaignIds = array_values(array_filter($campaignIds, static fn (int $id) => $id > 0));
        $types      = $this->normalizeTypes((array) $input->getOption('type'));
        $olderThan  = $input->getOption('older-than');
        $dryRun     = (bool) $input->getOption('dry-run');

        if (!$all && [] === $campaignIds && null === $olderThan) {
            $output->writeln('<error>Specify --campaign-id=ID, --all, and/or --older-than=24h</error>');

            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry-run: no rows will be deleted.</comment>');
        }

        $deleted = 0;

        if (null !== $olderThan && '' !== (string) $olderThan) {
            try {
                $before = $this->parseOlderThan((string) $olderThan);
            } catch (\InvalidArgumentException $e) {
                $output->writeln('<error>'.$e->getMessage().'</error>');

                return Command::FAILURE;
            }

            $output->writeln(sprintf(
                '<info>Clear notices with notified_at < %s</info>',
                $before->format(\DateTimeInterface::ATOM)
            ));

            if (!$dryRun) {
                $deleted += $this->noticeTracker->clearNoticesOlderThan($before);
            }
        }

        if ($all) {
            $output->writeln('<info>Clear notices for all campaigns'.($types ? ' types=['.implode(',', $types).']' : '').'</info>');
            if (!$dryRun) {
                if (null === $types) {
                    $deleted += $this->noticeTracker->clearAllNotices();
                } else {
                    $deleted += $this->noticeTracker->clearNoticesByTypes($types);
                }
            }
        } elseif ([] !== $campaignIds) {
            foreach ($campaignIds as $campaignId) {
                $output->writeln(sprintf(
                    '<info>Clear notices for campaign %d%s</info>',
                    $campaignId,
                    $types ? ' types=['.implode(',', $types).']' : ''
                ));
                if (!$dryRun) {
                    $deleted += $this->noticeTracker->clearCampaignNotices($campaignId, $types);
                }
            }
        }

        $output->writeln(sprintf('<info>Done. Rows deleted: %d</info>', $deleted));

        return Command::SUCCESS;
    }

    /**
     * @param list<mixed> $raw
     *
     * @return list<string>|null
     */
    private function normalizeTypes(array $raw): ?array
    {
        $allowed = [
            NoticeTracker::TYPE_EMAIL_SEND,
            NoticeTracker::TYPE_EMAIL_OPEN,
            NoticeTracker::TYPE_CAMPAIGN_RESTART,
        ];
        $out = [];
        foreach ($raw as $item) {
            foreach (preg_split('/[\s,]+/', strtolower(trim((string) $item))) ?: [] as $part) {
                if (in_array($part, $allowed, true) && !in_array($part, $out, true)) {
                    $out[] = $part;
                }
            }
        }

        return [] === $out ? null : $out;
    }

    private function parseOlderThan(string $value): \DateTimeImmutable
    {
        $value = trim(strtolower($value));
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (preg_match('/^(\d+)\s*(h|hour|hours)$/', $value, $m)) {
            return $now->modify('-'.((int) $m[1]).' hours');
        }
        if (preg_match('/^(\d+)\s*(d|day|days)$/', $value, $m)) {
            return $now->modify('-'.((int) $m[1]).' days');
        }
        if (preg_match('/^(\d+)\s*(w|week|weeks)$/', $value, $m)) {
            return $now->modify('-'.((int) $m[1]).' weeks');
        }

        // Absolute datetime
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid --older-than value. Use 24h, 1day, 7days, or a datetime.');
        }
    }
}
