<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Helper;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Doctrine\DatabasePlatform;

/**
 * Tracks once-only notices and scheduled-count state.
 *
 * Notice key: (event_type, campaign_id, email_id) — email_id=0 for campaign-level events.
 */
class NoticeTracker
{
    public const TYPE_EMAIL_SEND       = 'email_send';
    public const TYPE_EMAIL_OPEN       = 'email_open';
    public const TYPE_CAMPAIGN_RESTART = 'campaign_restart';

    private const TABLE       = 'wecom_campaign_alert_notices';
    private const STATE_TABLE = 'wecom_campaign_alert_state';

    public function __construct(private readonly Connection $db)
    {
    }

    public function ensureSchema(): void
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';

        $this->db->executeStatement(
            'CREATE TABLE IF NOT EXISTS '.$prefix.self::TABLE.' (
                event_type VARCHAR(32) NOT NULL DEFAULT \'email_send\',
                campaign_id INT NOT NULL,
                email_id INT NOT NULL DEFAULT 0,
                notified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_type, campaign_id, email_id)
            )'
        );

        $this->db->executeStatement(
            'CREATE TABLE IF NOT EXISTS '.$prefix.self::STATE_TABLE.' (
                state_key VARCHAR(64) NOT NULL PRIMARY KEY,
                state_value TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function wasNotified(string $eventType, int $campaignId, int $emailId = 0): bool
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $result = $this->db->fetchOne(
            'SELECT 1 FROM '.$prefix.self::TABLE.'
             WHERE event_type = ? AND campaign_id = ? AND email_id = ?',
            [$eventType, $campaignId, $emailId]
        );

        return false !== $result && null !== $result;
    }

    public function markNotified(string $eventType, int $campaignId, int $emailId = 0): void
    {
        $prefix   = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $platform = $this->db->getDatabasePlatform();
        $table    = $prefix.self::TABLE;

        $sql = DatabasePlatform::getSummarizeUpsertStatement(
            $platform,
            $table,
            'event_type, campaign_id, email_id, notified_at',
            'SELECT ? AS event_type, ? AS campaign_id, ? AS email_id, CURRENT_TIMESTAMP AS notified_at',
            'event_type, campaign_id, email_id',
            ['notified_at']
        );

        $this->db->executeStatement($sql, [$eventType, $campaignId, $emailId]);
    }

    public function getState(string $key): ?string
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $value  = $this->db->fetchOne(
            'SELECT state_value FROM '.$prefix.self::STATE_TABLE.' WHERE state_key = ?',
            [$key]
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    public function setState(string $key, string $value): void
    {
        $prefix   = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $platform = $this->db->getDatabasePlatform();
        $table    = $prefix.self::STATE_TABLE;

        $sql = DatabasePlatform::getSummarizeUpsertStatement(
            $platform,
            $table,
            'state_key, state_value, updated_at',
            'SELECT ? AS state_key, ? AS state_value, CURRENT_TIMESTAMP AS updated_at',
            'state_key',
            ['state_value', 'updated_at']
        );

        $this->db->executeStatement($sql, [$key, $value]);
    }

    public function countScheduledEmailSends(): int
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';

        $count = $this->db->fetchOne(
            'SELECT COUNT(*)
             FROM '.$prefix.'campaign_lead_event_log ll
             INNER JOIN '.$prefix.'campaign_events e ON e.id = ll.event_id
             INNER JOIN '.$prefix.'campaigns c ON c.id = e.campaign_id
             WHERE ll.is_scheduled = 1
               AND e.type = ?
               AND c.is_published = 1',
            ['email.send']
        );

        return (int) $count;
    }

    /**
     * Max rotation for a contact in a campaign (0 if not found).
     */
    public function getContactCampaignRotation(int $campaignId, int $leadId): int
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $value  = $this->db->fetchOne(
            'SELECT rotation FROM '.$prefix.'campaign_leads
             WHERE campaign_id = ? AND lead_id = ?
             ORDER BY rotation DESC',
            [$campaignId, $leadId]
        );

        return (int) ($value ?: 0);
    }

    public function getCampaignEffectivePublished(int $campaignId): ?bool
    {
        $value = $this->getState('campaign_effective_pub:'.$campaignId);
        if (null === $value) {
            return null;
        }

        return '1' === $value;
    }

    public function setCampaignEffectivePublished(int $campaignId, bool $effective): void
    {
        $this->setState('campaign_effective_pub:'.$campaignId, $effective ? '1' : '0');
    }

    /**
     * Campaigns that may change effective publish status soon or are currently flag-published.
     *
     * @return list<array{id: int, name: string, is_published: int, publish_up: ?string, publish_down: ?string}>
     */
    public function fetchCampaignsForPublishCheck(): array
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, is_published, publish_up, publish_down
             FROM '.$prefix.'campaigns
             WHERE deleted IS NULL
               AND (
                    is_published = 1
                    OR publish_up IS NOT NULL
                    OR publish_down IS NOT NULL
               )'
        );

        return array_map(static function (array $row): array {
            return [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'is_published' => (int) $row['is_published'],
                'publish_up'   => $row['publish_up'] ?? null,
                'publish_down' => $row['publish_down'] ?? null,
            ];
        }, $rows ?: []);
    }

    /**
     * Remove once-only notices for a campaign (optionally filtered by event type).
     *
     * @param list<string>|null $eventTypes null = all types for this campaign
     *
     * @return int Rows deleted
     */
    public function clearCampaignNotices(int $campaignId, ?array $eventTypes = null): int
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $table  = $prefix.self::TABLE;

        if (null === $eventTypes || [] === $eventTypes) {
            return (int) $this->db->executeStatement(
                'DELETE FROM '.$table.' WHERE campaign_id = ?',
                [$campaignId]
            );
        }

        $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
        $params       = array_merge([$campaignId], array_values($eventTypes));

        return (int) $this->db->executeStatement(
            'DELETE FROM '.$table.' WHERE campaign_id = ? AND event_type IN ('.$placeholders.')',
            $params
        );
    }

    /**
     * Delete notices older than the given datetime (UTC recommended).
     *
     * @return int Rows deleted
     */
    public function clearNoticesOlderThan(\DateTimeInterface $before): int
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';

        return (int) $this->db->executeStatement(
            'DELETE FROM '.$prefix.self::TABLE.' WHERE notified_at < ?',
            [$before->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Clear all notices (all campaigns / types).
     *
     * @return int Rows deleted
     */
    public function clearAllNotices(): int
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';

        return (int) $this->db->executeStatement('DELETE FROM '.$prefix.self::TABLE);
    }

    /**
     * Drop campaign restart dedup keys so the next restart can alert again.
     */
    public function clearCampaignRestartNotices(int $campaignId): int
    {
        return $this->clearCampaignNotices($campaignId, [self::TYPE_CAMPAIGN_RESTART]);
    }

    /**
     * @param list<string> $eventTypes
     *
     * @return int Rows deleted
     */
    public function clearNoticesByTypes(array $eventTypes): int
    {
        if ([] === $eventTypes) {
            return 0;
        }

        $prefix       = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));

        return (int) $this->db->executeStatement(
            'DELETE FROM '.$prefix.self::TABLE.' WHERE event_type IN ('.$placeholders.')',
            array_values($eventTypes)
        );
    }
}
