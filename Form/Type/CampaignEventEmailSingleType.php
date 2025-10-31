<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<array<mixed>>
 */
class CampaignEventEmailSingleType extends AbstractType
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
    }
}