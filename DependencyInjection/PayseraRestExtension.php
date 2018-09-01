<?php

namespace Paysera\Bundle\RestBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class PayseraRestExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        if (
            isset($config['property_path_converter'])
            && $config['property_path_converter'] !== null
            && $container->hasDefinition('paysera_rest.api_manager')
        ) {
            $apiManagerDefinition = $container->getDefinition('paysera_rest.api_manager');
            $apiManagerDefinition->addMethodCall(
                'setPropertyPathConverter',
                [
                    new Reference($config['property_path_converter'])
                ]
            );
        }

        $container->setParameter('paysera_rest.locales', $config['locales']);
    }
}
