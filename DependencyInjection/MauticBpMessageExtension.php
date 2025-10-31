<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class MauticBpMessageExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Config')
        );

        // Load the bundle's service definitions (autowiring + autoconfiguration)
        $loader->load('services.php');
    }
}