<?php

declare(strict_types=1);

use MauticPlugin\MauticBpMessageBundle\EventListener\CampaignSubscriber;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventBpMessageType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventBpMessageBatchType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventBpMessageSingleType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventEmailBatchType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventEmailSingleType;
use MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;

return [
    'services' => [
        'forms' => [
            'mautic.bpmessage.type.campaign_event' => [
                'class' => CampaignEventBpMessageType::class,
                'arguments' => [
                    'translator',
                ],
                'alias' => 'bpmessage_campaign_event',
            ],
            'mautic.bpmessage.type.campaign_event.messages_batch' => [
                'class' => CampaignEventBpMessageBatchType::class,
                'arguments' => [
                    'translator',
                ],
                'alias' => 'bpmessage_campaign_event_messages_batch',
            ],
            'mautic.bpmessage.type.campaign_event.messages_single' => [
                'class' => CampaignEventBpMessageSingleType::class,
                'arguments' => [
                    'translator',
                ],
                'alias' => 'bpmessage_campaign_event_messages_single',
            ],
            'mautic.bpmessage.type.campaign_event.emails_batch' => [
                'class' => CampaignEventEmailBatchType::class,
                'arguments' => [
                    'translator',
                ],
                'alias' => 'bpmessage_campaign_event_emails_batch',
            ],
            'mautic.bpmessage.type.campaign_event.emails_single' => [
                'class' => CampaignEventEmailSingleType::class,
                'arguments' => [
                    'translator',
                ],
                'alias' => 'bpmessage_campaign_event_emails_single',
            ],
        ],
        'models' => [
            'mautic.bpmessage.model' => [
                'class' => BpMessageModel::class,
                'arguments' => [
                    'event_dispatcher',
                    'doctrine.orm.entity_manager',
                    'mautic.helper.core_parameters',
                    'monolog.logger.mautic',
                ],
            ],
        ],
        'events' => [
            'mautic.bpmessage.subscriber.campaign' => [
                'class' => CampaignSubscriber::class,
                'arguments' => [
                    'mautic.bpmessage.model',
                    'mautic.helper.integration',
                    'monolog.logger.mautic',
                ],
            ],
        ],
        'integrations' => [
            'mautic.integration.bpmessage' => [
                'class' => BpMessageIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],

    ],
];