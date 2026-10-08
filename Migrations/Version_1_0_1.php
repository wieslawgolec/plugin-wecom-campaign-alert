<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Upgrade notices table to include event_type in primary key (if installed as 1.0.0 without it).
 */
class Version_1_0_1 extends AbstractMigration
{
    private string $noticesTable = 'wecom_campaign_alert_notices';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix($this->noticesTable));

            return !$table->hasColumn('event_type');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $notices = $this->concatPrefix($this->noticesTable);

        // Recreate with new schema (safe for small tracker tables)
        $this->addSql("DROP TABLE IF EXISTS {$notices}");
        $this->addSql(
            "CREATE TABLE {$notices} (
                event_type VARCHAR(32) NOT NULL DEFAULT 'email_send',
                campaign_id INT NOT NULL,
                email_id INT NOT NULL DEFAULT 0,
                notified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_type, campaign_id, email_id)
            )"
        );
    }
}
