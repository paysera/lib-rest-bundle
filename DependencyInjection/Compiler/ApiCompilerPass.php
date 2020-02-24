<?php

namespace Paysera\Bundle\RestBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ApiCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $this->processTags(
            $container,
            'paysera_rest.api_manager',
            'paysera_rest.encoder',
            'format',
            'addEncoder',
            'paysera_rest.service.request_api_resolver'
        );
        $this->processTags(
            $container,
            'paysera_rest.api_manager',
            'paysera_rest.decoder',
            'format',
            'addDecoder',
            'paysera_rest.service.request_api_resolver'
        );
        $this->processTags(
            $container,
            'paysera_rest.service.rest_api_registry',
            'paysera_rest.api',
            'uri_pattern',
            'addApiByUriPattern',
            'paysera_rest.service.request_api_resolver',
            true
        );
        $this->processTags(
            $container,
            'paysera_rest.service.rest_api_registry',
            'paysera_rest.api',
            'api_key',
            'addApiByKey',
            'paysera_rest.service.request_api_resolver',
            true
        );
    }

    protected function processTags(
        ContainerBuilder $container,
        string $serviceId,
        string $tag,
        string $attributeName,
        string $methodName,
        string $resolverId,
        bool $ignoreOnNoAttribute = false
    ) {
        if (!$container->hasDefinition($serviceId) || !$container->hasDefinition($resolverId)) {
            return;
        }
        $uriPatternRegexps = [];
        $definition = $container->getDefinition($serviceId);
        foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
            if (count($tags) > 1) {
                $exception = new InvalidConfigurationException(
                    'Service ' . $id . ' cannot have more than one tag ' . $tag
                );
                $exception->setPath($id);
                throw $exception;
            }
            $attributes = $tags[0];
            if (empty($attributes[$attributeName])) {
                if (!$ignoreOnNoAttribute) {
                    $exception = new InvalidConfigurationException(
                        'Service ' . $id . ' tag ' . $tag . ' is missing attribute ' . $attributeName
                    );
                    $exception->setPath($id);
                    throw $exception;
                }
            } else {
                $definition->addMethodCall($methodName, array(new Reference($id), $attributes[$attributeName]));
                if (!isset($attributes['api_key']) && isset($attributes['uri_pattern'])) {
                    $uriPatternRegexps[] = '(' . str_replace('#', '', $attributes['uri_pattern']) . ')';
                }
            }
        }
        if (count($uriPatternRegexps) > 0) {
            $apiResolverDefinition = $container->getDefinition($resolverId);
            $apiResolverDefinition->addMethodCall(
                'setGlobalApiUriPattern',
                ['#' . implode('|', $uriPatternRegexps) . '#']
            );
        }
    }
}
