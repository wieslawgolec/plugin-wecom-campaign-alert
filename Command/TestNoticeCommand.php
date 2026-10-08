<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Command;

use MauticPlugin\WeComCampaignAlertBundle\Helper\CommandGuard;
use MauticPlugin\WeComCampaignAlertBundle\Helper\MessageFormatter;
use MauticPlugin\WeComCampaignAlertBundle\Helper\WeComSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual: mautic:wecom:test-notice.
 *
 * Sends dummy WeCom messages for each alert type (does not touch notice tracker).
 */
#[AsCommand(
    name: 'mautic:wecom:test-notice',
    description: 'Send dummy WeCom test notices for each alert event type.'
)]
class TestNoticeCommand extends Command
{
    /** @var list<string> */
    private const TYPES = [
        'email_send',
        'email_open',
        'publish',
        'unpublish',
        'publish_schedule',
        'restart',
        'stall',
        'maintenance_stall',
    ];

    public function __construct(
        private readonly CommandGuard $commandGuard,
        private readonly WeComSender $weComSender,
        private readonly MessageFormatter $messageFormatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Event type(s) to test: '.implode(', ', self::TYPES).', or all',
                ['all']
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print message only, do not send')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run even if plugin disabled or maintenance lock exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        if ($reason = $this->commandGuard->getBlockReason($force)) {
            $output->writeln('<comment>'.$reason.'</comment>');

            return Command::SUCCESS;
        }

        $requested = $input->getOption('type');
        $types     = $this->resolveTypes(is_array($requested) ? $requested : [$requested]);
        if ([] === $types) {
            $output->writeln('<error>No valid types. Use: '.implode(', ', self::TYPES).', all</error>');

            return Command::FAILURE;
        }

        $msgType = $this->weComSender->getMsgType();
        $dryRun  = (bool) $input->getOption('dry-run');
        $sent    = 0;
        $failed  = 0;

        foreach ($types as $type) {
            $content = $this->buildContent($type, $msgType);
            $output->writeln(sprintf('<info>[%s]</info>', $type));
            $output->writeln($content);
            $output->writeln('');

            if ($dryRun) {
                continue;
            }

            $ok = $this->weComSender->send($content, [
                'type' => 'test_'.$type,
                'test' => true,
            ]);

            if ($ok) {
                ++$sent;
                $output->writeln(sprintf('<info>Sent: %s</info>', $type));
            } else {
                ++$failed;
                $output->writeln(sprintf('<error>Failed: %s</error>', $type));
            }

            if (count($types) > 1) {
                usleep(300000);
            }
        }

        if ($dryRun) {
            $output->writeln(sprintf('<comment>Dry-run only. Types: %s</comment>', implode(', ', $types)));
        } else {
            $output->writeln(sprintf('<info>Done. sent=%d failed=%d</info>', $sent, $failed));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $requested
     *
     * @return list<string>
     */
    private function resolveTypes(array $requested): array
    {
        $flat = [];
        foreach ($requested as $item) {
            foreach (preg_split('/[\s,]+/', strtolower(trim((string) $item))) ?: [] as $part) {
                if ('' === $part) {
                    continue;
                }
                $flat[] = $part;
            }
        }

        if (in_array('all', $flat, true)) {
            return self::TYPES;
        }

        $valid = [];
        foreach ($flat as $type) {
            if (in_array($type, self::TYPES, true) && !in_array($type, $valid, true)) {
                $valid[] = $type;
            }
        }

        return $valid;
    }

    private function buildContent(string $type, string $msgType): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $campaign = [
            'campaign_id'   => 999001,
            'campaign_name' => 'Sample Campaign',
            'publish_up'    => $now,
            'publish_down'  => '',
        ];

        $email = $campaign + [
            'email_id'   => 999002,
            'email_name' => 'Welcome Email',
            'event_id'   => 999003,
            'contact_id' => 999004,
        ];

        return match ($type) {
            'email_send' => $this->messageFormatter->formatEmailSendAlert($email, $msgType),
            'email_open' => $this->messageFormatter->formatEmailOpenAlert($email, $msgType),
            'publish' => $this->messageFormatter->formatPublishAlert($campaign, true, $msgType, 'flag'),
            'unpublish' => $this->messageFormatter->formatPublishAlert($campaign, false, $msgType, 'flag'),
            'publish_schedule' => $this->messageFormatter->formatPublishScheduleAlert($campaign + [
                'publish_down' => (new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ], $msgType),
            'restart' => $this->messageFormatter->formatRestartAlert([
                'campaign_id'   => $campaign['campaign_id'],
                'campaign_name' => $campaign['campaign_name'],
            ], $msgType),
            'stall' => $this->messageFormatter->formatStallAlert([
                'since' => $now,
            ], $msgType),
            'maintenance_stall' => $this->messageFormatter->formatMaintenanceLockStallAlert([
                'since' => $now,
            ], $msgType),
            default => 'Unknown test type: '.$type,
        };
    }
}
