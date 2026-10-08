<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Creates WeCom campaign alert tracker tables on plugin install/upgrade.
 */
class Version_1_0_0 extends AbstractMigration
{
    private string $noticesTable = 'wecom_campaign_alert_notices';
    private string $stateTable   = 'wecom_campaign_alert_state';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $schema->getTable($this->concatPrefix($this->noticesTable));
            $schema->getTable($this->concatPrefix($this->stateTable));

            return false;
        } catch (SchemaException) {
            return true;
        }
    }

    protected function up(): void
    {
        $notices = $this->concatPrefix($this->noticesTable);
        $state   = $this->concatPrefix($this->stateTable);

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS {$notices} (
                event_type VARCHAR(32) NOT NULL DEFAULT 'email_send',
                campaign_id INT NOT NULL,
                email_id INT NOT NULL DEFAULT 0,
                notified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_type, campaign_id, email_id)
            )"
        );

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS {$state} (
                state_key VARCHAR(64) NOT NULL PRIMARY KEY,
                state_value TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }
}
