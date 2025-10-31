<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilder;

class BpMessageIntegration extends AbstractIntegration
{
    public function getName(): string
    {
        return 'BpMessage';
    }

    public function getDisplayName(): string
    {
        return 'BpMessage';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getSupportedFeatures(): array
    {
        return [];
    }

    /**
     * Exibe campos na aba de 'Features' da integração (feature flags).
     *
     * @param \Mautic\PluginBundle\Integration\Form|FormBuilder $builder
     * @param array                                                $data
     * @param string                                               $formArea
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' !== $formArea) {
            return;
        }

        /* @var FormBuilder $builder */
        $builder->add(
            'url',
            TextType::class,
            [
                'label'      => 'mautic.bpmessage.config.url',
                'required'   => true,
                'attr'       => ['class' => 'form-control'],
            ]
        );

        // Campos exclusivos da configuração do plugin: URLs dos endpoints e parâmetros de lote/tempo

        $builder->add(
            'batch_size',
            NumberType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.config.batch_size',
                'attr'       => ['class' => 'form-control', 'min' => 1],
                'data'       => isset($data['batch_size']) ? (int) $data['batch_size'] : 50,
            ]
        );

        $builder->add(
            'batch_interval',
            NumberType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.config.batch_interval',
                'attr'       => ['class' => 'form-control', 'min' => 0],
                'data'       => isset($data['batch_interval']) ? (int) $data['batch_interval'] : 0,
            ]
        );

        $builder->add(
            'retry_limit',
            NumberType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.config.retry_limit',
                'attr'       => ['class' => 'form-control', 'min' => 0],
                'data'       => isset($data['retry_limit']) ? (int) $data['retry_limit'] : 3,
            ]
        );

        $builder->add(
            'timeout',
            NumberType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.config.timeout',
                'attr'       => ['class' => 'form-control', 'min' => 1],
                'data'       => isset($data['timeout']) ? (int) $data['timeout'] : 10,
            ]
        );
    }
}