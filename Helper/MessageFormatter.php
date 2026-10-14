<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Helper;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formats alert content for WeCom group robot (text or markdown).
 * Message templates live in Translations/*/messages.ini.
 */
class MessageFormatter
{
    public const DEFAULT_TIMEZONE = 'Asia/Shanghai';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly CoreParametersHelper $coreParametersHelper,
    ) {
    }

    /**
     * @param array{campaign_name?: string, email_name?: string} $data
     */
    public function formatEmailSendAlert(array $data, string $msgType = 'markdown'): string
    {
        $params = [
            '%campaign%' => $this->name($data['campaign_name'] ?? ''),
            '%email%'    => $this->name($data['email_name'] ?? ''),
        ];

        $key = 'text' === $msgType
            ? 'wecomcampaignalert.msg.email_send.text'
            : 'wecomcampaignalert.msg.email_send.markdown';

        return $this->trans($key, $params);
    }

    /**
     * @param array{campaign_name?: string, email_name?: string} $data
     */
    public function formatEmailOpenAlert(array $data, string $msgType = 'markdown'): string
    {
        $params = [
            '%campaign%' => $this->name($data['campaign_name'] ?? ''),
            '%email%'    => $this->name($data['email_name'] ?? ''),
        ];

        $key = 'text' === $msgType
            ? 'wecomcampaignalert.msg.email_open.text'
            : 'wecomcampaignalert.msg.email_open.markdown';

        return $this->trans($key, $params);
    }

    /**
     * @param array{campaign_name?: string, publish_up?: string, publish_down?: string} $data
     */
    public function formatPublishAlert(array $data, bool $published, string $msgType = 'markdown', string $reason = 'flag'): string
    {
        $statusKey = $published
            ? 'wecomcampaignalert.msg.status.published'
            : 'wecomcampaignalert.msg.status.unpublished';
        $status = $this->trans($statusKey);
        $via    = 'schedule' === $reason
            ? $this->trans('wecomcampaignalert.msg.publish.via_schedule')
            : '';

        $params = [
            '%emoji%'    => $published ? '🟢' : '🔴',
            '%status%'   => $status,
            '%via%'      => $via,
            '%campaign%' => $this->name($data['campaign_name'] ?? ''),
            '%color%'    => $published ? 'info' : 'warning',
        ];

        $key = 'text' === $msgType
            ? 'wecomcampaignalert.msg.publish.text'
            : 'wecomcampaignalert.msg.publish.markdown';

        return $this->trans($key, $params);
    }

    /**
     * @param array{campaign_name?: string, publish_up?: string, publish_down?: string} $data
     */
    public function formatPublishScheduleAlert(array $data, string $msgType = 'markdown'): string
    {
        $campaign = $this->name($data['campaign_name'] ?? '');
        $up       = $this->formatLocalTime($data['publish_up'] ?? null);
        $down     = $this->formatLocalTime($data['publish_down'] ?? null);

        $lines = [];
        $lines[] = $this->trans(
            'text' === $msgType
                ? 'wecomcampaignalert.msg.publish_schedule.title.text'
                : 'wecomcampaignalert.msg.publish_schedule.title.markdown'
        );
        $lines[] = $this->trans(
            'text' === $msgType
                ? 'wecomcampaignalert.msg.line.campaign.text'
                : 'wecomcampaignalert.msg.line.campaign.markdown',
            ['%campaign%' => $campaign]
        );

        if (null !== $up) {
            $lines[] = $this->trans(
                'text' === $msgType
                    ? 'wecomcampaignalert.msg.line.start.text'
                    : 'wecomcampaignalert.msg.line.start.markdown',
                ['%time%' => $up]
            );
        }
        if (null !== $down) {
            $lines[] = $this->trans(
                'text' === $msgType
                    ? 'wecomcampaignalert.msg.line.end.text'
                    : 'wecomcampaignalert.msg.line.end.markdown',
                ['%time%' => $down]
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{campaign_name?: string, contact_count?: int|string, rotation?: int|string} $data
     */
    public function formatRestartAlert(array $data, string $msgType = 'markdown'): string
    {
        $params = ['%campaign%' => $this->name($data['campaign_name'] ?? '')];

        $key = 'text' === $msgType
            ? 'wecomcampaignalert.msg.restart.text'
            : 'wecomcampaignalert.msg.restart.markdown';

        return $this->trans($key, $params);
    }

    /**
     * @param array{since?: string|\DateTimeInterface|null} $data
     */
    public function formatStallAlert(array $data, string $msgType = 'markdown'): string
    {
        $since = $this->parseDate($data['since'] ?? null);
        $ago   = $since ? $this->formatAgo($since) : $this->trans('wecomcampaignalert.msg.ago.unknown');
        $start = $since ? $this->formatLocalTime($since) : null;

        $lines = [];
        $lines[] = $this->trans(
            'text' === $msgType
                ? 'wecomcampaignalert.msg.stall.title.text'
                : 'wecomcampaignalert.msg.stall.title.markdown'
        );
        $lines[] = $this->trans(
            'text' === $msgType
                ? 'wecomcampaignalert.msg.line.unchanged.text'
                : 'wecomcampaignalert.msg.line.unchanged.markdown',
            ['%ago%' => $ago]
        );

        if (null !== $start) {
            $lines[] = $this->trans(
                'text' === $msgType
                    ? 'wecomcampaignalert.msg.line.since.text'
                    : 'wecomcampaignalert.msg.line.since.markdown',
                ['%time%' => $start]
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{since?: string|\DateTimeInterface|null, locks?: list<string>} $data
     */
    public function formatMaintenanceLockStallAlert(array $data, string $msgType = 'markdown'): string
    {
        $since = $this->parseDate($data['since'] ?? null);
        $ago   = $since ? $this->formatAgo($since) : $this->trans('wecomcampaignalert.msg.ago.unknown');
        $start = $since ? $this->formatLocalTime($since) : null;

        $lines = [];
        $lines[] = $this->trans(
            'text' === $msgType
                ? 'wecomcampaignalert.msg.maintenance_stall.title.text'
                : 'wecomcampaignalert.msg.maintenance_stall.title.markdown'
        );
        $lines[] = $this->trans(
            'text' === $msgType
                ? 'wecomcampaignalert.msg.line.unchanged.text'
                : 'wecomcampaignalert.msg.line.unchanged.markdown',
            ['%ago%' => $ago]
        );

        if (null !== $start) {
            $lines[] = $this->trans(
                'text' === $msgType
                    ? 'wecomcampaignalert.msg.line.since.text'
                    : 'wecomcampaignalert.msg.line.since.markdown',
                ['%time%' => $start]
            );
        }

        return implode("\n", $lines);
    }

    public function formatAgo(\DateTimeInterface $since, ?\DateTimeInterface $now = null): string
    {
        $nowTs   = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        $sinceTs = $since->getTimestamp();
        $sec     = max(0, $nowTs - $sinceTs);

        $hours = intdiv($sec, 3600);
        $mins  = intdiv($sec % 3600, 60);

        if ($hours > 0 && $mins > 0) {
            return $this->trans('wecomcampaignalert.msg.ago.hm', [
                '%hours%' => (string) $hours,
                '%mins%'  => (string) $mins,
            ]);
        }
        if ($hours > 0) {
            return $this->trans('wecomcampaignalert.msg.ago.h', ['%hours%' => (string) $hours]);
        }
        if ($mins > 0) {
            return $this->trans('wecomcampaignalert.msg.ago.m', ['%mins%' => (string) $mins]);
        }

        return $this->trans('wecomcampaignalert.msg.ago.now');
    }

    /**
     * Configured IANA timezone (falls back to Asia/Shanghai).
     */
    public function getTimezone(): string
    {
        $tz = trim((string) $this->coreParametersHelper->get(
            'wecom_campaign_alert_timezone',
            self::DEFAULT_TIMEZONE
        ));

        if ('' === $tz) {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Exception) {
            return self::DEFAULT_TIMEZONE;
        }
    }

    /**
     * Format instant in configured timezone, e.g. "2026-01-01 08:00 (Asia/Shanghai)".
     */
    private function formatLocalTime(mixed $value): ?string
    {
        $date = $this->parseDate($value);
        if (null === $date) {
            return null;
        }

        $tzName = $this->getTimezone();
        $local  = $date->setTimezone(new \DateTimeZone($tzName))->format('Y-m-d H:i');

        return $this->trans('wecomcampaignalert.msg.datetime', [
            '%datetime%' => $local,
            '%timezone%' => $tzName,
        ]);
    }

    private function name(string $value): string
    {
        $trimmed = trim($value);

        return $this->escape('' !== $trimmed ? $trimmed : $this->trans('wecomcampaignalert.msg.unknown'));
    }

    private function escape(string $value): string
    {
        return str_replace(['`', '*', '<', '>', '#'], ['\'', '', '', '', ''], $value);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }

        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'messages');
    }
}
