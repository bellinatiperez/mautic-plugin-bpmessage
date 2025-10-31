<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<array<mixed>>
 */
class CampaignEventEmailBatchType extends AbstractType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'headers',
            SortableListType::class,
            [
                'required'        => false,
                'label'           => 'mautic.bpmessage.event.headers',
                'option_required' => false,
                'with_labels'     => true,
            ]
        );

        $builder->add(
            'payload_template',
            TextareaType::class,
            [
                'required'    => false,
                'label'       => 'mautic.bpmessage.event.payload_template',
                'label_attr'  => ['class' => 'control-label'],
                'attr'        => ['class' => 'form-control', 'rows' => 6],
                'help'        => $this->translator->trans('mautic.bpmessage.event.payload_template.help'),
            ]
        );

        // Additional requested parameters for Email Batch
        $builder->add(
            'name',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'startDate',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.start_date',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control', 'placeholder' => 'YYYY-MM-DDThh:mm:ssZ'],
                'help'       => $this->translator->trans('mautic.bpmessage.event.datetime_iso.help'),
            ]
        );

        $builder->add(
            'endDate',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.end_date',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control', 'placeholder' => 'YYYY-MM-DDThh:mm:ssZ'],
                'help'       => $this->translator->trans('mautic.bpmessage.event.datetime_iso.help'),
            ]
        );

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
            'idServiceSettings',
            IntegerType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.id_service_settings',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'crmId',
            IntegerType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.crm_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'bookBusinessForeignId',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.book_business_foreign_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'stepForeignId',
            TextType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.step_foreign_id',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
            ]
        );

        $builder->add(
            'isRadarLot',
            CheckboxType::class,
            [
                'required'   => false,
                'label'      => 'mautic.bpmessage.event.is_radar_lot',
                'label_attr' => ['class' => 'control-label'],
            ]
        );
    }
}