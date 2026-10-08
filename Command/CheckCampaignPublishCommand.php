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
 * Cron: mautic:wecom:check-campaign-publish.
 *
 * Detects effective publish/unpublish when publish_up / publish_down dates elapse.
 */
#[AsCommand(
    name: 'mautic:wecom:check-campaign-publish',
    description: 'Send WeCom alert when campaign effective publish status changes due to schedule.'
)]
class CheckCampaignPublishCommand extends Command
{
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
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Run even if plugin disabled or maintenance lock exists');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not send, only report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        if ($reason = $this->commandGuard->getBlockReason($force)) {
            $output->writeln('<comment>'.$reason.'</comment>');

            return Command::SUCCESS;
        }

        if (!filter_var($this->coreParametersHelper->get('wecom_campaign_alert_publish_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $output->writeln('<comment>Publish alerts disabled.</comment>');

            return Command::SUCCESS;
        }

        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows  = $this->noticeTracker->fetchCampaignsForPublishCheck();
        $sent  = 0;

        foreach ($rows as $row) {
            $effective = $this->isEffectivelyPublished(
                (bool) $row['is_published'],
                $row['publish_up'],
                $row['publish_down'],
                $now
            );

            $previous = $this->noticeTracker->getCampaignEffectivePublished($row['id']);
            $this->noticeTracker->setCampaignEffectivePublished($row['id'], $effective);

            // First observation: store baseline only
            if (null === $previous) {
                $output->writeln(sprintf(
                    '<comment>Campaign %d baseline effective=%s</comment>',
                    $row['id'],
                    $effective ? 'yes' : 'no'
                ));
                continue;
            }

            if ($previous === $effective) {
                continue;
            }

            $data = [
                'campaign_id'   => $row['id'],
                'campaign_name' => $row['name'],
                'publish_up'    => $row['publish_up'] ? (string) $row['publish_up'] : '',
                'publish_down'  => $row['publish_down'] ? (string) $row['publish_down'] : '',
            ];

            $content = $this->messageFormatter->formatPublishAlert(
                $data,
                $effective,
                $this->weComSender->getMsgType(),
                'schedule'
            );

            $output->writeln(sprintf(
                '<info>Campaign %d effective %s → %s</info>',
                $row['id'],
                $previous ? 'published' : 'unpublished',
                $effective ? 'published' : 'unpublished'
            ));

            if ($input->getOption('dry-run')) {
                $output->writeln("[dry-run]\n".$content);
                continue;
            }

            if ($this->weComSender->send($content, [
                'type'        => $effective ? 'campaign_publish' : 'campaign_unpublish',
                'campaign_id' => $row['id'],
                'reason'      => 'schedule',
            ])) {
                ++$sent;
            }
        }

        $output->writeln(sprintf('<info>Done. Alerts sent: %d</info>', $sent));

        return Command::SUCCESS;
    }

    private function isEffectivelyPublished(
        bool $isPublished,
        ?string $publishUp,
        ?string $publishDown,
        \DateTimeImmutable $now,
    ): bool {
        if (!$isPublished) {
            return false;
        }

        if ($publishUp) {
            try {
                $up = new \DateTimeImmutable($publishUp);
                if ($up > $now) {
                    return false;
                }
            } catch (\Exception) {
                // ignore parse errors
            }
        }

        if ($publishDown) {
            try {
                $down = new \DateTimeImmutable($publishDown);
                if ($down <= $now) {
                    return false;
                }
            } catch (\Exception) {
                // ignore parse errors
            }
        }

        return true;
    }
}
