<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event as Events;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\BpMessageEvents;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventBpMessageType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventBpMessageBatchType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventBpMessageSingleType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventEmailBatchType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\CampaignEventEmailSingleType;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BpMessageModel $model,
        private IntegrationHelper $integrationHelper,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD         => ['onCampaignBuild', 0],
            BpMessageEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 0],
        ];
    }

    public function onCampaignTriggerAction(CampaignExecutionEvent $event): void
    {
        $flowMap = [
            'campaign.bpmessage'                 => null, // legacy single action
            'campaign.bpmessage.messages_batch'  => 'messages_batch',
            'campaign.bpmessage.messages_single' => 'messages_single',
            'campaign.bpmessage.emails_batch'    => 'emails_batch',
            'campaign.bpmessage.emails_single'   => 'emails_single',
        ];

        foreach ($flowMap as $context => $flow) {
            if (!$event->checkContext($context)) {
                continue;
            }
            try {
                $config = $event->getConfig();
                // Use the campaign action's name as default if the form does not provide one
                if (empty($config['name'])) {
                    $eventArray = $event->getEvent();
                    if (!empty($eventArray['name'])) {
                        $config['name'] = (string) $eventArray['name'];
                    }
                }
                if (null !== $flow) {
                    $config['flow'] = $flow;
                }
                $integration = $this->integrationHelper->getIntegrationObject('BpMessage');
                if ($integration) {
                    $config = $integration->mergeConfigToFeatureSettings([
                        'integration' => 'BpMessage',
                        'config'      => $config,
                    ]);
                }

                $this->model->queueContact($config, $event->getLead());
                $event->setResult(true);
            } catch (\Exception $e) {
                $this->logger->error('BpMessage queue error: '.$e->getMessage());
                $event->setFailed($e->getMessage());
            }
            return; // handled
        }
    }

    public function onCampaignBuild(Events\CampaignBuilderEvent $event): void
    {
        // Legacy single action for backward compatibility
        $legacy = [
            'label'              => 'mautic.bpmessage.event.label',
            'description'        => 'mautic.bpmessage.event.desc',
            'formType'           => CampaignEventBpMessageType::class,
            'formTypeCleanMasks' => 'clean',
            'eventName'          => BpMessageEvents::ON_CAMPAIGN_TRIGGER_ACTION,
        ];
        $event->addAction('campaign.bpmessage', $legacy);

        // New actions
        $event->addAction('campaign.bpmessage.messages_batch', [
            'label'              => 'mautic.bpmessage.event.messages_batch.label',
            'description'        => 'mautic.bpmessage.event.messages_batch.desc',
            'formType'           => CampaignEventBpMessageBatchType::class,
            'formTypeCleanMasks' => 'clean',
            'eventName'          => BpMessageEvents::ON_CAMPAIGN_TRIGGER_ACTION,
        ]);

        $event->addAction('campaign.bpmessage.messages_single', [
            'label'              => 'mautic.bpmessage.event.messages_single.label',
            'description'        => 'mautic.bpmessage.event.messages_single.desc',
            'formType'           => CampaignEventBpMessageSingleType::class,
            'formTypeCleanMasks' => 'clean',
            'eventName'          => BpMessageEvents::ON_CAMPAIGN_TRIGGER_ACTION,
        ]);

        $event->addAction('campaign.bpmessage.emails_batch', [
            'label'              => 'mautic.bpmessage.event.emails_batch.label',
            'description'        => 'mautic.bpmessage.event.emails_batch.desc',
            'formType'           => CampaignEventEmailBatchType::class,
            'formTypeCleanMasks' => 'clean',
            'eventName'          => BpMessageEvents::ON_CAMPAIGN_TRIGGER_ACTION,
        ]);

        $event->addAction('campaign.bpmessage.emails_single', [
            'label'              => 'mautic.bpmessage.event.emails_single.label',
            'description'        => 'mautic.bpmessage.event.emails_single.desc',
            'formType'           => CampaignEventEmailSingleType::class,
            'formTypeCleanMasks' => 'clean',
            'eventName'          => BpMessageEvents::ON_CAMPAIGN_TRIGGER_ACTION,
        ]);
    }
}