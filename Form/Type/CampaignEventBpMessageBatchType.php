<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<array<mixed>>
 */
class CampaignEventBpMessageBatchType extends AbstractType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Service type selector: 1=WhatsApp, 2=SMS, 4=RCS
        $builder->add(
            'ServiceType',
            ChoiceType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.service_type',
                'label_attr' => ['class' => 'control-label'],
                'choices'    => [
                    'WhatsApp' => 1,
                    'SMS'      => 2,
                    'RCS'      => 4,
                ],
                'placeholder'=> '',
                'attr'       => ['class' => 'form-control'],
            ]
        );

        // Campo 'name' removido: usamos o nome da ação (evento) automaticamente

        // Campos de datas removidos: startDate e endDate

        $builder->add(
            'user',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.user',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'idQuotaSettings',
            IntegerType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.id_quota_settings',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'idServiceSettings',
            IntegerType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.id_service_settings',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        // Foreign business identifier
        $builder->add(
            'idForeignBookBusiness',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.id_foreign_book_business',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'idBookBusinessSendGroup',
            IntegerType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.id_book_business_send_group',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'imageUrl',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.image_url',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'imageName',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.image_name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        // Variables key/value list
        $builder->add(
            'data',
            SortableListType::class,
            [
                'required'        => false,
                'label'           => 'mautic.bpmessage.event.data',
                'label_attr'      => ['class' => 'control-label'],
                'option_required' => false,
                'with_labels'     => true,
                // Persist as associative array: key => value
                'key_value_pairs' => true,
            ]
        );

        $builder->add(
            'text',
            TextareaType::class,
            [
                'required'    => false,
                'label'       => 'mautic.bpmessage.event.text',
                'label_attr'  => ['class' => 'control-label'],
                'attr'        => ['class' => 'form-control', 'rows' => 6],
                'help'        => $this->translator->trans('mautic.bpmessage.event.payload_template.help'),
            ]
        );

        // Template ID
        $builder->add(
            'idTemplate',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.id_template',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        // Variables key/value list
        $builder->add(
            'variables',
            SortableListType::class,
            [
                'required'        => false,
                'label'           => 'mautic.bpmessage.event.variables',
                'label_attr'      => ['class' => 'control-label'],
                'option_required' => false,
                'with_labels'     => true,
                // Persist as associative array: key => value
                'key_value_pairs' => true,
            ]
        );
    }
}