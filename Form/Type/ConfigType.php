<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<mixed>
 */
class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = is_array($options['data'] ?? null) ? $options['data'] : [];

        $builder->add('wecom_campaign_alert_enabled', YesNoButtonGroupType::class, [
            'label'      => 'wecomcampaignalert.config.enabled',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
            'data'       => $this->toBool($data['wecom_campaign_alert_enabled'] ?? false),
            'required'   => false,
        ]);

        $builder->add('wecom_campaign_alert_key', TextType::class, [
            'label'      => 'wecomcampaignalert.config.key',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ],
            'required'   => false,
            'help'       => 'wecomcampaignalert.config.key.help',
        ]);

        $builder->add('wecom_campaign_alert_msgtype', ChoiceType::class, [
            'choices'    => [
                'wecomcampaignalert.config.msgtype.markdown' => 'markdown',
                'wecomcampaignalert.config.msgtype.text'     => 'text',
            ],
            'label'      => 'wecomcampaignalert.config.msgtype',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
            'required'   => true,
        ]);

        foreach ([
            'wecom_campaign_alert_email_send_enabled'       => 'wecomcampaignalert.config.email_send_enabled',
            'wecom_campaign_alert_email_open_enabled'       => 'wecomcampaignalert.config.email_open_enabled',
            'wecom_campaign_alert_publish_enabled'          => 'wecomcampaignalert.config.publish_enabled',
            'wecom_campaign_alert_publish_schedule_enabled' => 'wecomcampaignalert.config.publish_schedule_enabled',
            'wecom_campaign_alert_restart_enabled'          => 'wecomcampaignalert.config.restart_enabled',
            'wecom_campaign_alert_stall_enabled'            => 'wecomcampaignalert.config.stall_enabled',
            'wecom_campaign_alert_maintenance_stall_enabled' => 'wecomcampaignalert.config.maintenance_stall_enabled',
        ] as $field => $label) {
            $builder->add($field, YesNoButtonGroupType::class, [
                'label'      => $label,
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
                'data'       => $this->toBool($data[$field] ?? true),
                'required'   => false,
            ]);
        }

        $builder->add('wecom_campaign_alert_stall_hours', IntegerType::class, [
            'label'      => 'wecomcampaignalert.config.stall_hours',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
            'required'   => true,
        ]);

        $builder->add('wecom_campaign_alert_maintenance_window_start', IntegerType::class, [
            'label'      => 'wecomcampaignalert.config.maintenance_window_start',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control', 'min' => 0, 'max' => 23],
            'data'       => (int) ($data['wecom_campaign_alert_maintenance_window_start'] ?? 9),
            'required'   => true,
        ]);

        $builder->add('wecom_campaign_alert_maintenance_window_end', IntegerType::class, [
            'label'      => 'wecomcampaignalert.config.maintenance_window_end',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control', 'min' => 0, 'max' => 23],
            'data'       => (int) ($data['wecom_campaign_alert_maintenance_window_end'] ?? 18),
            'required'   => true,
        ]);

        $builder->add('wecom_campaign_alert_timeout', IntegerType::class, [
            'label'      => 'wecomcampaignalert.config.timeout',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
            'required'   => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'wecomcampaignalertconfig';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
