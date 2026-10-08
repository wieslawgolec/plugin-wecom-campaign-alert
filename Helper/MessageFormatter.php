<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Helper;

/**
 * Formats alert content for WeCom group robot (text or markdown).
 * Keep messages short: names + status, no IDs / counts noise.
 */
class MessageFormatter
{
    private const TZ = 'Asia/Shanghai'; // UTC+0800

    /**
     * @param array{campaign_name?: string, email_name?: string} $data
     */
    public function formatEmailSendAlert(array $data, string $msgType = 'markdown'): string
    {
        $campaign = $this->name($data['campaign_name'] ?? 'Unknown');
        $email    = $this->name($data['email_name'] ?? 'Unknown');

        if ('text' === $msgType) {
            return "📧 Campaign email started sending\nCampaign: {$campaign}\nEmail: {$email}";
        }

        return "📧 **Campaign email started sending**\n"
            ."> Campaign: **{$campaign}**\n"
            ."> Email: **{$email}**";
    }

    /**
     * @param array{campaign_name?: string, email_name?: string} $data
     */
    public function formatEmailOpenAlert(array $data, string $msgType = 'markdown'): string
    {
        $campaign = $this->name($data['campaign_name'] ?? 'Unknown');
        $email    = $this->name($data['email_name'] ?? 'Unknown');

        if ('text' === $msgType) {
            return "👀 Campaign email opened\nCampaign: {$campaign}\nEmail: {$email}";
        }

        return "👀 **Campaign email opened**\n"
            ."> Campaign: **{$campaign}**\n"
            ."> Email: **{$email}**";
    }

    /**
     * @param array{campaign_name?: string, publish_up?: string, publish_down?: string} $data
     */
    public function formatPublishAlert(array $data, bool $published, string $msgType = 'markdown', string $reason = 'flag'): string
    {
        $campaign = $this->name($data['campaign_name'] ?? 'Unknown');
        $emoji    = $published ? '🟢' : '🔴';
        $status   = $published ? 'published' : 'unpublished';
        $via      = 'schedule' === $reason ? ' (schedule)' : '';

        if ('text' === $msgType) {
            return "{$emoji} Campaign {$status}{$via}\nCampaign: {$campaign}";
        }

        $color = $published ? 'info' : 'warning';

        return "{$emoji} **Campaign {$status}**{$via}\n"
            ."> <font color=\"{$color}\">{$status}</font>\n"
            ."> Campaign: **{$campaign}**";
    }

    /**
     * @param array{campaign_name?: string, publish_up?: string, publish_down?: string} $data
     */
    public function formatPublishScheduleAlert(array $data, string $msgType = 'markdown'): string
    {
        $campaign = $this->name($data['campaign_name'] ?? 'Unknown');
        $up       = $this->formatLocalTime($data['publish_up'] ?? null);
        $down     = $this->formatLocalTime($data['publish_down'] ?? null);

        if ('text' === $msgType) {
            $lines = ['🗓️ Publish schedule updated', "Campaign: {$campaign}"];
            if (null !== $up) {
                $lines[] = "Start: {$up}";
            }
            if (null !== $down) {
                $lines[] = "End: {$down}";
            }

            return implode("\n", $lines);
        }

        $lines = [
            '🗓️ **Publish schedule updated**',
            "> Campaign: **{$campaign}**",
        ];
        if (null !== $up) {
            $lines[] = "> Start: {$up}";
        }
        if (null !== $down) {
            $lines[] = "> End: {$down}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{campaign_name?: string, contact_count?: int|string, rotation?: int|string} $data
     */
    public function formatRestartAlert(array $data, string $msgType = 'markdown'): string
    {
        $campaign = $this->name($data['campaign_name'] ?? 'Unknown');

        if ('text' === $msgType) {
            return "🔄 Campaign restarting\nCampaign: {$campaign}";
        }

        return "🔄 **Campaign restarting**\n"
            ."> Campaign: **{$campaign}**";
    }

    /**
     * @param array{since?: string|\DateTimeInterface|null} $data
     */
    public function formatStallAlert(array $data, string $msgType = 'markdown'): string
    {
        $since = $this->parseDate($data['since'] ?? null);
        $ago   = $since ? $this->formatAgo($since) : 'unknown duration';
        $start = $since ? $this->formatLocalTime($since) : null;

        if ('text' === $msgType) {
            $lines = ['⚠️ Scheduled campaign emails stalled', "Unchanged for: {$ago}"];
            if (null !== $start) {
                $lines[] = "Since: {$start}";
            }

            return implode("\n", $lines);
        }

        $lines = [
            '⚠️ **Scheduled campaign emails stalled**',
            "> Unchanged for: <font color=\"warning\">**{$ago}**</font>",
        ];
        if (null !== $start) {
            $lines[] = "> Since: {$start}";
        }

        return implode("\n", $lines);
    }

    private function name(string $value): string
    {
        return $this->escape('' !== trim($value) ? trim($value) : 'Unknown');
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
     * Human "ago" from $since until now (UTC math, display independent).
     */

    /**
     * @param array{since?: string|\DateTimeInterface|null, locks?: list<string>} $data
     */
    public function formatMaintenanceLockStallAlert(array $data, string $msgType = 'markdown'): string
    {
        $since = $this->parseDate($data['since'] ?? null);
        $ago   = $since ? $this->formatAgo($since) : 'unknown duration';
        $start = $since ? $this->formatLocalTime($since) : null;

        if ('text' === $msgType) {
            $lines = ['🛠️ Maintenance mode stalled', "Unchanged for: {$ago}"];
            if (null !== $start) {
                $lines[] = "Since: {$start}";
            }

            return implode("\n", $lines);
        }

        $lines = [
            '🛠️ **Maintenance mode stalled**',
            "> Unchanged for: <font color=\"warning\">**{$ago}**</font>",
        ];
        if (null !== $start) {
            $lines[] = "> Since: {$start}";
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
            return sprintf('%dh %dm ago', $hours, $mins);
        }
        if ($hours > 0) {
            return sprintf('%dh ago', $hours);
        }
        if ($mins > 0) {
            return sprintf('%dm ago', $mins);
        }

        return 'just now';
    }

    /**
     * Format instant in Asia/Shanghai (+0800/local time).
     */
    private function formatLocalTime(mixed $value): ?string
    {
        $date = $this->parseDate($value);
        if (null === $date) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d H:i').' (local time)';
    }
}
