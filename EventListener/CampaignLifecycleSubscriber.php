<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Event\CampaignEvent;
use Mautic\CampaignBundle\Event\CampaignLeadChangeEvent;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\WeComCampaignAlertBundle\Helper\MessageFormatter;
use MauticPlugin\WeComCampaignAlertBundle\Helper\NoticeTracker;
use MauticPlugin\WeComCampaignAlertBundle\Helper\WeComSender;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * WeCom alerts for campaign publish/unpublish (flag + schedule) and contact restarts.
 */
class CampaignLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly WeComSender $weComSender,
        private readonly MessageFormatter $messageFormatter,
        private readonly NoticeTracker $noticeTracker,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_POST_SAVE         => ['onCampaignPostSave', 0],
            CampaignEvents::CAMPAIGN_ON_LEADCHANGE     => ['onCampaignLeadChange', 0],
            CampaignEvents::LEAD_CAMPAIGN_BATCH_CHANGE => ['onCampaignLeadChange', 0],
        ];
    }

    public function onCampaignPostSave(CampaignEvent $event): void
    {
        if (!filter_var($this->coreParametersHelper->get('wecom_campaign_alert_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $campaign = $event->getCampaign();
        $changes  = $campaign->getChanges(true);
        $msgType  = $this->weComSender->getMsgType();
        $data     = [
            'campaign_id'   => (int) $campaign->getId(),
            'campaign_name' => (string) ($campaign->getName() ?? 'Unknown'),
            'publish_up'    => $this->formatDate($campaign->getPublishUp()),
            'publish_down'  => $this->formatDate($campaign->getPublishDown()),
        ];

        // Keep cron baseline in sync with current effective status
        $effective = $this->isEffectivelyPublished($campaign);
        $this->noticeTracker->setCampaignEffectivePublished($data['campaign_id'], $effective);

        $flagChanged = isset($changes['isPublished']);
        $scheduleChanged = isset($changes['publishUp']) || isset($changes['publishDown']);

        if (!$flagChanged && !$scheduleChanged) {
            return;
        }

        if ($flagChanged
            && filter_var($this->coreParametersHelper->get('wecom_campaign_alert_publish_enabled', true), FILTER_VALIDATE_BOOLEAN)
        ) {
            $old = $changes['isPublished'][0] ?? null;
            $new = $changes['isPublished'][1] ?? null;
            if (null !== $old && null !== $new && (bool) $old !== (bool) $new) {
                $published = (bool) $new;
                $content   = $this->messageFormatter->formatPublishAlert(
                    $data,
                    $published,
                    $msgType,
                    'flag'
                );
                $this->weComSender->send($content, [
                    'type'        => $published ? 'campaign_publish' : 'campaign_unpublish',
                    'campaign_id' => $data['campaign_id'],
                    'reason'      => 'flag',
                ]);
            }
        }

        if ($scheduleChanged
            && filter_var($this->coreParametersHelper->get('wecom_campaign_alert_publish_schedule_enabled', true), FILTER_VALIDATE_BOOLEAN)
        ) {
            $content = $this->messageFormatter->formatPublishScheduleAlert($data, $msgType);
            $this->weComSender->send($content, [
                'type'        => 'campaign_publish_schedule',
                'campaign_id' => $data['campaign_id'],
            ]);
        }
    }

    public function onCampaignLeadChange(CampaignLeadChangeEvent $event): void
    {
        if (!filter_var($this->coreParametersHelper->get('wecom_campaign_alert_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (!filter_var($this->coreParametersHelper->get('wecom_campaign_alert_restart_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (!$event->wasAdded()) {
            return;
        }

        $campaign = $event->getCampaign();
        if (!$campaign->getAllowRestart()) {
            return;
        }

        $campaignId = (int) $campaign->getId();
        $leads      = $event->getLeads();
        if (empty($leads) && $event->getLead()) {
            $leads = [$event->getLead()];
        }

        $restarting  = 0;
        $maxRotation = 1;
        foreach ($leads as $lead) {
            if (!$lead || !$lead->getId()) {
                continue;
            }
            $rotation = $this->noticeTracker->getContactCampaignRotation($campaignId, (int) $lead->getId());
            if ($rotation > 1) {
                ++$restarting;
                $maxRotation = max($maxRotation, $rotation);
            }
        }

        if ($restarting < 1) {
            return;
        }

        // Single notice per campaign until notices are cleared (e.g. daily reset)
        if ($this->noticeTracker->wasNotified(NoticeTracker::TYPE_CAMPAIGN_RESTART, $campaignId, 0)) {
            return;
        }
        $this->noticeTracker->markNotified(NoticeTracker::TYPE_CAMPAIGN_RESTART, $campaignId, 0);

        // Allow send/open alerts again for this restart cycle
        $this->noticeTracker->clearCampaignNotices($campaignId, [
            NoticeTracker::TYPE_EMAIL_SEND,
            NoticeTracker::TYPE_EMAIL_OPEN,
        ]);

        $data = [
            'campaign_id'   => $campaignId,
            'campaign_name' => (string) ($campaign->getName() ?? 'Unknown'),
        ];

        $content = $this->messageFormatter->formatRestartAlert($data, $this->weComSender->getMsgType());

        $this->weComSender->send($content, [
            'type'        => NoticeTracker::TYPE_CAMPAIGN_RESTART,
            'campaign_id' => $campaignId,
        ]);
    }

    private function isEffectivelyPublished(Campaign $campaign): bool
    {
        if (!$campaign->getIsPublished()) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $up = $campaign->getPublishUp();
        if ($up instanceof \DateTimeInterface && $up > $now) {
            return false;
        }

        $down = $campaign->getPublishDown();
        if ($down instanceof \DateTimeInterface && $down <= $now) {
            return false;
        }

        return true;
    }

    private function formatDate(mixed $date): string
    {
        if (!$date instanceof \DateTimeInterface) {
            return '';
        }

        return $date->format(\DateTimeInterface::ATOM);
    }
}
