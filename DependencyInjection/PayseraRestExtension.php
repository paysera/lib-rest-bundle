<?php

namespace Paysera\Bundle\RestBundle\DependencyInjection;

use JsonpCallbackValidator;
use Paysera\Bundle\RestBundle\ApiManager;
use Paysera\Bundle\RestBundle\Cache\DefaultCacheStrategy;
use Paysera\Bundle\RestBundle\Entity\ErrorConfig;
use Paysera\Bundle\RestBundle\Exception\ApiException;
use Paysera\Bundle\RestBundle\Listener\RestListener;
use Paysera\Bundle\RestBundle\ModificationDateProvider\CollectionDateProvider;
use Paysera\Bundle\RestBundle\Normalizer\ErrorNormalizer;
use Paysera\Bundle\RestBundle\Normalizer\JsonpParamsQueryNormalizer;
use Paysera\Bundle\RestBundle\Repository\ResultProvider;
use Paysera\Bundle\RestBundle\Security\RoleAndIpStrategy;
use Paysera\Bundle\RestBundle\Service\ExceptionLogger;
use Paysera\Bundle\RestBundle\Service\FormatDetector;
use Paysera\Bundle\RestBundle\Service\ParameterToEntityMapBuilder;
use Paysera\Bundle\RestBundle\Service\RequestApiKeyResolver;
use Paysera\Bundle\RestBundle\Service\RequestApiResolver;
use Paysera\Bundle\RestBundle\Service\RequestLogger;
use Paysera\Bundle\RestBundle\Service\RestApiRegistry;
use Paysera\Component\Serializer\Converter\CamelCaseToSnakeCaseConverter;
use Paysera\Component\Serializer\Converter\NoOpConverter;
use Paysera\Component\Serializer\Encoding\Json;
use Paysera\Component\Serializer\Encoding\Plain;
use Paysera\Component\Serializer\Factory\ContextAwareNormalizerFactory;
use Paysera\Component\Serializer\Factory\EncoderFactory;
use Paysera\Component\Serializer\Factory\JsonpEncoderFactory;
use Paysera\Component\Serializer\Filter\FieldsFilter;
use Paysera\Component\Serializer\Filter\FieldsParser;
use Paysera\Component\Serializer\Normalizer\ArrayNormalizer;
use Paysera\Component\Serializer\Normalizer\DistributedNormalizer;
use Paysera\Component\Serializer\Normalizer\FilterNormalizer;
use Paysera\Component\Serializer\Normalizer\PlainNormalizer;
use Paysera\Component\Serializer\Normalizer\ResultMetadataNormalizer;
use Paysera\Component\Serializer\Normalizer\ResultNormalizer;
use Paysera\Component\Serializer\Normalizer\ViolationNormalizer;
use Paysera\Component\Serializer\Validation\PropertiesAwareValidator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

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
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->addDefinitions($this->createNormalizerDefinitions());
        $container->addDefinitions($this->createServiceDefinitions());

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

    /**
     * @return array<string, Definition>
     */
    private function createNormalizerDefinitions(): array
    {
        return [
            'paysera_rest.normalizer.filter' => $this->createPublicDefinition(FilterNormalizer::class),
            'paysera_rest.normalizer.result' => $this->createPublicDefinition(ResultNormalizer::class)
                ->setAbstract(true)
                ->addMethodCall('setMetadataNormalizer', [new Reference('paysera_rest.normalizer.result_metadata')]),
            'paysera_rest.normalizer.items_result' => (new ChildDefinition('paysera_rest.normalizer.result'))
                ->setPublic(true)
                ->setAbstract(true)
                ->setArguments(['items']),
            'paysera_rest.normalizer.result_metadata' => $this->createPublicDefinition(
                ResultMetadataNormalizer::class,
            ),
            'paysera_rest.normalizer.plain' => $this->createPublicDefinition(PlainNormalizer::class),
            'paysera_rest.normalizer.jsonp_params' => $this->createPublicDefinition(JsonpParamsQueryNormalizer::class),
            'paysera_rest.normalizer.violation' => $this->createPublicDefinition(ViolationNormalizer::class),
            'paysera_rest.normalizer.violations' => $this->createPublicDefinition(ArrayNormalizer::class, [
                new Reference('paysera_rest.normalizer.violation'),
            ]),
            'paysera_rest.normalizer.error' => $this->createPublicDefinition(ErrorNormalizer::class, [
                new Reference('paysera_rest.normalizer.violations'),
                new Reference('paysera_rest.normalizer.violations'),
            ]),
        ];
    }

    /**
     * @return array<string, Definition>
     */
    private function createServiceDefinitions(): array
    {
        $logger = new Reference('logger');
        $fieldsParser = new Reference('paysera_rest.serializer.fields_parser');
        $requestApiResolver = new Reference('paysera_rest.service.request_api_resolver');

        return [
            'paysera_rest.format_detector' => $this->createPublicDefinition(FormatDetector::class, [$logger]),
            'paysera_rest.api_manager' => $this->createPublicDefinition(ApiManager::class, [
                new Reference('paysera_rest.format_detector'),
                $logger,
                new Reference('validator'),
                new Reference('paysera_rest.normalizer.error'),
                $requestApiResolver,
            ])
                ->setLazy(true)
                ->addTag('monolog.logger', ['channel' => 'paysera_rest.api_manager'])
                ->addMethodCall('setErrorConfig', [new Reference('paysera_rest.error_config')]),
            'paysera_rest.error_config' => $this->createErrorConfigDefinition(),
            'paysera_rest.service.request_logger' => $this->createPublicDefinition(RequestLogger::class, [$logger])
                ->addTag('monolog.logger', ['channel' => 'paysera_rest.rest_logger']),
            'paysera_rest.service.exception_logger' => $this->createPublicDefinition(ExceptionLogger::class),
            'paysera_rest.rest_listener' => $this->createPublicDefinition(RestListener::class, [
                new Reference('paysera_rest.api_manager'),
                new Reference('paysera_rest.serializer.context_aware_normalizer_factory'),
                $logger,
                new Reference('paysera_rest.service.parameter_to_entity_map_builder'),
                new Reference('paysera_rest.service.request_logger'),
                new Reference('paysera_rest.service.exception_logger'),
                $requestApiResolver,
                '%paysera_rest.locales%',
            ])
                ->addTag('monolog.logger', ['channel' => 'paysera_rest.rest_listener'])
                ->addTag('kernel.event_listener', [
                    'event' => 'kernel.exception',
                    'method' => 'onKernelException',
                    'priority' => 10,
                ])
                ->addTag('kernel.event_listener', [
                    'event' => 'kernel.request',
                    'method' => 'onKernelRequest',
                    'priority' => 20,
                ])
                ->addTag('kernel.event_listener', [
                    'event' => 'kernel.controller',
                    'method' => 'onKernelController',
                    'priority' => 1,
                ])
                ->addTag('kernel.event_listener', ['event' => 'kernel.view', 'method' => 'onKernelView']),
            'paysera_rest.factory.encoder' => $this->createPublicDefinition(EncoderFactory::class),
            'paysera_rest.encoding.json' => $this->createEncodingDefinition(Json::class, 'json'),
            'paysera_rest.encoding.png' => $this->createEncodingDefinition(Plain::class, 'png', 'createPngEncoder'),
            'paysera_rest.encoding.gif' => $this->createEncodingDefinition(Plain::class, 'gif', 'createPngEncoder'),
            'paysera_rest.encoding.jpeg' => $this->createEncodingDefinition(Plain::class, 'jpg', 'createJpegEncoder'),
            'paysera_rest.encoding.plain_text' => $this->createEncodingDefinition(
                Plain::class,
                'txt',
                'createPlainTextEncoder',
            ),
            'paysera_rest.encoding.jsonp_factory' => $this->createPublicDefinition(JsonpEncoderFactory::class, [
                new Reference('paysera_rest.encoding.json'),
                new Definition(JsonpCallbackValidator::class),
            ]),
            'paysera_rest.result_provider' => $this->createPublicDefinition(ResultProvider::class)
                ->setAbstract(true),
            'paysera_rest.default_cache_strategy' => $this->createPublicDefinition(DefaultCacheStrategy::class),
            'paysera_rest.modification_date_provider.collection' => $this->createPublicDefinition(
                CollectionDateProvider::class,
            )
                ->setAbstract(true),
            'paysera_rest.serializer.fields_parser' => (new Definition(FieldsParser::class))->setPublic(false),
            'paysera_rest.serializer.fields_filter' => (new Definition(FieldsFilter::class, [$fieldsParser]))
                ->setPublic(false),
            'paysera_rest.serializer.context_aware_normalizer_factory' => $this->createPublicDefinition(
                ContextAwareNormalizerFactory::class,
                [$fieldsParser, new Reference('paysera_rest.serializer.fields_filter')],
            ),
            'paysera_rest.serializer.distributed_normalizer' => $this->createPublicDefinition(
                DistributedNormalizer::class,
            )
                ->setAbstract(true)
                ->setFactory([new Reference('paysera_rest.serializer.context_aware_normalizer_factory'), 'create']),
            'paysera_rest.serializer.validation.properties_aware_validator' => $this->createPublicDefinition(
                PropertiesAwareValidator::class,
                [new Reference('validator')],
            ),
            'paysera_rest.service.parameter_to_entity_map_builder' => $this->createPublicDefinition(
                ParameterToEntityMapBuilder::class,
                [$logger, new Reference('paysera_rest.api_manager')],
            ),
            'paysera_rest.service.property_path_converter.no_op_converter' => $this->createPublicDefinition(
                NoOpConverter::class,
            ),
            'paysera_rest.service.property_path_converter.camel_case_to_snake_case' => $this->createPublicDefinition(
                CamelCaseToSnakeCaseConverter::class,
            ),
            'paysera_rest.security_strategy.role_and_ip' => $this->createPublicDefinition(RoleAndIpStrategy::class, [
                new Reference('security.role_hierarchy'),
                new Reference('security.token_storage'),
                $logger,
            ])
                ->setAbstract(true),
            'paysera_rest.service.rest_api_registry' => $this->createPublicDefinition(RestApiRegistry::class)
                ->setLazy(true),
            'paysera_rest.service.request_api_key_resolver' => $this->createPublicDefinition(
                RequestApiKeyResolver::class,
            ),
            'paysera_rest.service.request_api_resolver' => $this->createPublicDefinition(RequestApiResolver::class, [
                new Reference('paysera_rest.service.rest_api_registry'),
                new Reference('paysera_rest.service.request_api_key_resolver'),
            ]),
        ];
    }

    private function createErrorConfigDefinition(): Definition
    {
        $definition = $this->createPublicDefinition(ErrorConfig::class);
        $errors = [
            [ApiException::INVALID_REQUEST, 400, 'Request content is invalid'],
            [ApiException::INVALID_PARAMETERS, 400, 'Some required parameter is missing or it\'s format is invalid'],
            [ApiException::INVALID_STATE, 409, 'Requested action cannot be made to the current state of resource'],
            [ApiException::UNAUTHORIZED, 401, 'You have not provided any credentials or they are invalid'],
            [ApiException::FORBIDDEN, 403, 'You have no rights to access requested resource or make requested action'],
            [ApiException::NOT_FOUND, 404, 'Resource was not found'],
            [ApiException::INTERNAL_SERVER_ERROR, 500, 'Unexpected internal system error'],
            [ApiException::NOT_ACCEPTABLE, 406, 'Unknown request or response format'],
        ];
        foreach ($errors as $error) {
            $definition->addMethodCall('configure', $error);
        }

        return $definition;
    }

    private function createEncodingDefinition(string $class, string $format, ?string $factoryMethod = null): Definition
    {
        $definition = $this->createPublicDefinition($class)
            ->addTag('paysera_rest.encoder', ['format' => $format])
            ->addTag('paysera_rest.decoder', ['format' => $format]);
        if ($factoryMethod !== null) {
            $definition->setFactory([new Reference('paysera_rest.factory.encoder'), $factoryMethod]);
        }

        return $definition;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function createPublicDefinition(string $class, array $arguments = []): Definition
    {
        return (new Definition($class, $arguments))->setPublic(true);
    }
}
