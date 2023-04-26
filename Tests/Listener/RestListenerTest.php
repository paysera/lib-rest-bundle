<?php

namespace Paysera\Bundle\RestBundle\Tests;

use Mockery;
use Mockery\MockInterface;
use Paysera\Bundle\RestBundle\ApiManager;
use Paysera\Bundle\RestBundle\Exception\ApiException;
use Paysera\Bundle\RestBundle\Listener\RestListener;
use Paysera\Bundle\RestBundle\Normalizer\NameAwareDenormalizerInterface;
use Paysera\Bundle\RestBundle\RestApi;
use Paysera\Bundle\RestBundle\Service\ExceptionLogger;
use Paysera\Bundle\RestBundle\Service\ParameterToEntityMapBuilder;
use Paysera\Bundle\RestBundle\Service\RequestApiResolver;
use Paysera\Bundle\RestBundle\Service\RequestLogger;
use Paysera\Component\Serializer\Exception\EncodingException;
use Paysera\Component\Serializer\Exception\InvalidDataException;
use Paysera\Component\Serializer\Factory\ContextAwareNormalizerFactory;
use Paysera\Component\Serializer\Validation\PropertiesAwareValidator;
use Paysera\Component\Serializer\Validation\PropertyPathConverterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Paysera\Component\Serializer\Entity\Violation;

/**
 * These tests use heavy object mocking, however it makes sure that as much code as possible is executed
 * These tests are used for refactoring RestListener
 */
class RestListenerTest extends TestCase
{
    /**
     * @var MockInterface|ApiManager
     */
    private $apiManager;

    /**
     * @var MockInterface|ContextAwareNormalizerFactory
     */
    private $normalizerFactory;

    /**
     * @var MockInterface|LoggerInterface
     */
    private $logger;

    /**
     * @var MockInterface|ParameterToEntityMapBuilder
     */
    private $parameterToEntityMapBuilder;

    /**
     * @var MockInterface|RequestLogger
     */
    private $requestLogger;

    /**
     * @var MockInterface|ControllerEvent
     */
    private $ControllerEvent;

    /**
     * @var ExceptionLogger
     */
    private $exceptionLogger;

    /**
     * @var MockInterface|RequestApiResolver
     */
    protected $requestApiResolver;

    private $storedLoggerMessages = [];

    private $storedContext = [];

    public function setUp(): void
    {
        $this->apiManager = Mockery::mock(ApiManager::class);

        $this->normalizerFactory = Mockery::mock(ContextAwareNormalizerFactory::class);

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->allows('debug')->andReturnUsing($this->storeLoggerMessage());

        $this->parameterToEntityMapBuilder = Mockery::mock(ParameterToEntityMapBuilder::class);

        $this->requestLogger = Mockery::mock(RequestLogger::class);

        $this->ControllerEvent = $this->getControllerEvent();

        $this->exceptionLogger = Mockery::mock(ExceptionLogger::class);

        $this->requestApiResolver = Mockery::mock(RequestApiResolver::class);
    }

    public function testOnKernelControllerNoMappersOnlyParameterToEntityMap()
    {
        $parameterToEntityMap = ['key' => 'entity'];

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->allows('getRequestMapper')->andReturnNull();
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns($parameterToEntityMap);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
        $key = key($parameterToEntityMap);
        $this->assertEquals($parameterToEntityMap[$key], $this->ControllerEvent->getRequest()->attributes->get($key));
    }

    public function testOnKernelControllerWithMapperAndParameterToEntityMap()
    {
        $entity = [1];
        $parameterToEntityMap = ['key' => $entity];
        $name = 'requestName';

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns($entity);
        $requestMapper->allows('getName')->andReturns($name);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->allows('getValidationGroups');
        $this->apiManager->allows('getRequestMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns($parameterToEntityMap);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
        $key = key($parameterToEntityMap);
        $this->assertEquals($parameterToEntityMap[$key], $this->ControllerEvent->getRequest()->attributes->get($key));
        $this->assertEquals($entity, $this->ControllerEvent->getRequest()->attributes->get($key));
    }

    public function testOnKernelControllerWithRequestMapperWhenDecodingFails()
    {
        $this->expectException(ApiException::class);

        $request = Mockery::mock(Request::class);
        $request->allows('getContent')->andReturns('a=b&c=d');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $controllerEvent = new ControllerEvent(
            Mockery::mock(HttpKernelInterface::class),
            function () {
            },
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity');
        $requestMapper->allows('getName')->andReturns('name');

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getDecoder')->andThrow(EncodingException::class);
        $this->apiManager->allows('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->allows('getRequestMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getValidationGroups');
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($controllerEvent);
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingFails()
    {
        $this->expectException(ApiException::class);
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->allows('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andThrow(InvalidDataException::class);
        $requestMapper->allows('getName')->andReturns('name');

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->allows('getRequestMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getValidationGroups');
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingSucceedsWithoutValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns($entity);
        $requestMapper->allows('getName')->andReturns($name);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->allows('getRequestMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getValidationGroups');
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
        $this->assertEquals($entity, $this->ControllerEvent->getRequest()->attributes->get($name));
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingSucceedsWithValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns($entity);
        $requestMapper->allows('getName')->andReturns($name);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->allows('getRequestMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getValidationGroups')->andReturns([]);
        $this->apiManager->allows('createPropertiesValidator')->andReturnNull();
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->allows('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->allows('createPropertiesValidator')->andReturns($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
        $this->assertEquals($entity, $this->ControllerEvent->getRequest()->attributes->get($name));
    }

    public function testOnKernelControllerWithRequestMapperValidationThrowsException()
    {
        $this->expectException(ApiException::class);
        $name = 'requestName';
        $entity = [1];
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns($entity);
        $requestMapper->allows('getName')->andReturns($name);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->allows('getRequestMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getValidationGroups')->andReturns([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->allows('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->allows('createPropertiesValidator')->andReturns($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
        $this->assertEquals($entity, $this->ControllerEvent->getRequest()->attributes->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperWhenMappingFails()
    {
        $this->expectException(ApiException::class);
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andThrow(InvalidDataException::class);
        $requestMapper->allows('getName')->andReturns('name');

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getRequestMapper')->andReturnNull();
        $this->apiManager->allows('getValidationGroups');
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
    }

    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsException()
    {
        $this->expectException(ApiException::class);
        $name = 'requestName';
        $entity = [1];
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns($entity);
        $requestMapper->allows('getName')->andReturns($name);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getRequestMapper')->andReturnNull();
        $this->apiManager->allows('getValidationGroups')->andReturns([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->allows('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->allows('createPropertiesValidator')->andReturns($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
        $this->assertEquals($entity, $this->ControllerEvent->getRequest()->attributes->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperWhenMappingSucceedsWithValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns($entity);
        $requestMapper->allows('getName')->andReturns($name);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getRequestMapper')->andReturnNull();
        $this->apiManager->allows('getValidationGroups')->andReturns([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->allows('createPropertiesValidator')->andReturnNull();
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->allows('validate');
        $this->apiManager->allows('createPropertiesValidator')->andReturns($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->ControllerEvent);
        $this->assertEquals($entity, $this->ControllerEvent->getRequest()->attributes->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsExceptionWithPathConverter()
    {
        $name = 'requestName';
        $entity = [
            'firstName' => 1,
            'last_name' => 2,
        ];
        $this->logger->allows('notice')->andReturnUsing($this->storeLoggerMessage());

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns($entity);
        $requestMapper->allows('getName')->andReturns($name);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns(null);

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getSecurityStrategy')->andReturnNull();
        $this->apiManager->allows('getRequestQueryMapper')->andReturns($requestMapper);
        $this->apiManager->allows('getRequestMapper')->andReturnNull();
        $this->apiManager->allows('getValidationGroups')->andReturns([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();

        $validator = Mockery::mock(ValidatorInterface::class);
        $violationList = new ConstraintViolationList([
            new ConstraintViolation('firstName message', '', [], '', 'firstName', '1'),
            new ConstraintViolation('lastName message', '', [], '', 'last_name', '2'),
        ]);
        $validator->allows('validate')->andReturns($violationList);
        $propertyPathConverter = Mockery::mock(PropertyPathConverterInterface::class);
        $propertyPathConverter->allows('convert')->andReturnUsing(function ($path) {
            return strtoupper($path);
        });
        $propertiesAwareValidator = new PropertiesAwareValidator($validator, $propertyPathConverter);
        $this->apiManager->allows('createPropertiesValidator')->andReturns($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        $restListener = $this->createRestListener();

        $exceptionThrowed = false;
        try {
            $restListener->onKernelController($this->ControllerEvent);
        } catch (ApiException $apiException) {
            $exceptionThrowed = true;
            $this->assertEquals(
                [
                    'FIRSTNAME' => ['firstName message'],
                    'LAST_NAME' => ['lastName message'],
                ],
                $apiException->getProperties()
            );

            $this->assertEquals(
                [
                    (new Violation())->setField('FIRSTNAME')->setMessage('firstName message'),
                    (new Violation())->setField('LAST_NAME')->setMessage('lastName message'),
                ],
                $apiException->getViolations()
            );
        }

        $this->assertTrue($exceptionThrowed);
        $this->assertNull($this->ControllerEvent->getRequest()->attributes->get($name));
    }

    public function testOnKernelViewResponseHasXFrameOptionsHeader()
    {
        $restListener = $this->createRestListener();

        $this->apiManager->allows('getLogger');
        $this->apiManager->allows('getCacheStrategy');
        $this->apiManager->allows('getRequestLoggingParts')->andReturnNull();

        $restApi = Mockery::mock(RestApi::class);

        $this->requestApiResolver->allows('getApiKeyForRequest');
        $this->requestApiResolver->allows('getApiForRequest')->andReturns($restApi);

        $httpKernelMock = Mockery::mock(HttpKernelInterface::class);
        $requestMock = Mockery::mock(Request::class);

        $event = new ViewEvent(
            $httpKernelMock,
            $requestMock,
            HttpKernelInterface::MASTER_REQUEST,
            null
        );

        $restListener->onKernelView($event);

        $responseHeaders = $event->getResponse()->headers;
        $headerName = 'x-frame-options';

        $this->assertTrue($responseHeaders->has($headerName));
        $this->assertEquals('DENY', $responseHeaders->get($headerName));
    }

    private function storeLoggerMessage()
    {
        return function($value, $context = null) {
            $this->storedLoggerMessages[] = $value;
            $this->storedContext[] = $context;
        };
    }

    /**
     * @return RestListener
     */
    private function createRestListener()
    {
        return new RestListener(
            $this->apiManager,
            $this->normalizerFactory,
            $this->logger,
            $this->parameterToEntityMapBuilder,
            $this->requestLogger,
            $this->exceptionLogger,
            $this->requestApiResolver,
            []
        );
    }

    private function createPropertiesAwareValidator()
    {
        return Mockery::mock(PropertiesAwareValidator::class);
    }

    private function getControllerEvent(): ControllerEvent
    {
        $request = Mockery::mock(Request::class);
        $request->allows('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = new ParameterBag();
        $request->query = $queryParameterBag;

        return new ControllerEvent(
            Mockery::mock(HttpKernelInterface::class),
            function () {
            },
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );
    }
}
