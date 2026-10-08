<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use MauticPlugin\WeComCampaignAlertBundle\Form\Type\ConfigType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConfigSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ConfigEvents::CONFIG_ON_GENERATE => ['onConfigGenerate', 0],
        ];
    }

    public function onConfigGenerate(ConfigBuilderEvent $event): void
    {
        $event->addForm([
            'formAlias'  => 'wecomcampaignalertconfig',
            'formTheme'  => '@WeComCampaignAlert/FormTheme/Config/_config_wecomcampaignalertconfig_widget.html.twig',
            'formType'   => ConfigType::class,
            'parameters' => $event->getParametersFromConfig('WeComCampaignAlertBundle'),
        ]);
    }
}
