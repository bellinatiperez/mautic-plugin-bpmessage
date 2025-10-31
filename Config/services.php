<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\MauticBpMessageBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    // Ensure Doctrine can fetch repository services from the container
    $services->load('MauticPlugin\\MauticBpMessageBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    // Event subscribers will be registered via Config/config.php 'services' → 'events'.

    // Aliases for easier service access
    $services->alias('mautic.bpmessage.model', MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel::class);
    $services->alias('mautic.bpmessage.integration', MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration::class);
    $services->alias('mautic.bpmessage.repository.queue', MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueueRepository::class);
};