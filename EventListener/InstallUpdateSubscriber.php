<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\EventListener;

use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\WeComCampaignAlertBundle\Helper\NoticeTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ensures plugin tables exist on install/upgrade (in addition to Migrations).
 */
class InstallUpdateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NoticeTracker $noticeTracker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstall', 0],
            PluginEvents::ON_PLUGIN_UPDATE  => ['onPluginUpdate', 0],
        ];
    }

    public function onPluginInstall(PluginInstallEvent $event): void
    {
        if (!$event->checkContext('WeCom Campaign Alert')) {
            return;
        }

        $this->createSchema();
    }

    public function onPluginUpdate(PluginUpdateEvent $event): void
    {
        if (!$event->checkContext('WeCom Campaign Alert')) {
            return;
        }

        $this->createSchema();
    }

    private function createSchema(): void
    {
        try {
            $this->noticeTracker->ensureSchema();
        } catch (\Throwable $e) {
            $this->logger->error('WeComCampaignAlert: schema create on install/update failed: '.$e->getMessage());
        }
    }
}
