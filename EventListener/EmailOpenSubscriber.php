<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use MauticPlugin\WeComCampaignAlertBundle\Helper\MessageFormatter;
use MauticPlugin\WeComCampaignAlertBundle\Helper\NoticeTracker;
use MauticPlugin\WeComCampaignAlertBundle\Helper\WeComSender;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * One WeCom notice per (campaign, email) on first open of a campaign-sent email.
 */
class EmailOpenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly WeComSender $weComSender,
        private readonly MessageFormatter $messageFormatter,
        private readonly NoticeTracker $noticeTracker,
        private readonly Connection $db,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_ON_OPEN => ['onEmailOpen', 0],
        ];
    }

    public function onEmailOpen(EmailOpenEvent $event): void
    {
        if (!filter_var($this->coreParametersHelper->get('wecom_campaign_alert_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (!filter_var($this->coreParametersHelper->get('wecom_campaign_alert_email_open_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // Only first-time opens (avoid re-open noise)
        if (!$event->isFirstTime()) {
            return;
        }

        $stat = $event->getStat();
        $source = $stat->getSource();
        if (!is_array($source) || ($source[0] ?? null) !== 'campaign.event') {
            return;
        }

        $campaignEventId = isset($source[1]) ? (int) $source[1] : 0;
        if ($campaignEventId <= 0) {
            return;
        }

        $email = $event->getEmail() ?? $stat->getEmail();
        if (null === $email) {
            return;
        }

        $emailId = (int) $email->getId();
        if ($emailId <= 0) {
            return;
        }

        $campaignId = $this->resolveCampaignId($campaignEventId);
        if (null === $campaignId) {
            return;
        }

        if ($this->noticeTracker->wasNotified(NoticeTracker::TYPE_EMAIL_OPEN, $campaignId, $emailId)) {
            return;
        }

        $this->noticeTracker->markNotified(NoticeTracker::TYPE_EMAIL_OPEN, $campaignId, $emailId);

        $lead = $stat->getLead();
        $data = [
            'campaign_id'   => $campaignId,
            'campaign_name' => $this->resolveCampaignName($campaignId),
            'email_id'      => $emailId,
            'email_name'    => (string) ($email->getName() ?? $email->getSubject() ?? 'Unknown'),
            'contact_id'    => $lead ? (int) $lead->getId() : '',
        ];

        $content = $this->messageFormatter->formatEmailOpenAlert($data, $this->weComSender->getMsgType());

        $this->weComSender->send($content, [
            'type'        => NoticeTracker::TYPE_EMAIL_OPEN,
            'campaign_id' => $campaignId,
            'email_id'    => $emailId,
        ]);
    }

    private function resolveCampaignId(int $eventId): ?int
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $result = $this->db->fetchAssociative(
            'SELECT campaign_id FROM '.$prefix.'campaign_events WHERE id = ?',
            [$eventId]
        );

        if (!$result || !isset($result['campaign_id'])) {
            return null;
        }

        return (int) $result['campaign_id'];
    }

    private function resolveCampaignName(int $campaignId): string
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';
        $name   = $this->db->fetchOne(
            'SELECT name FROM '.$prefix.'campaigns WHERE id = ?',
            [$campaignId]
        );

        return $name ? (string) $name : 'Unknown';
    }
}
