<?php

namespace Paysera\Bundle\RestBundle\Tests;

use ArrayObject;
use Closure;
use DateTime;
use Mockery;
use Mockery\MockInterface;
use Paysera\Bundle\RestBundle\ApiManager;
use Paysera\Bundle\RestBundle\Cache\CacheStrategyInterface;
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
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
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
     * @var MockInterface|KernelEvent
     */
    private $kernelEvent;

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

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function setUp(): void
    {
        $this->apiManager = Mockery::mock(ApiManager::class);

        $this->normalizerFactory = Mockery::mock(ContextAwareNormalizerFactory::class);

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('debug')->andReturnUsing($this->storeLoggerMessage());

        $this->parameterToEntityMapBuilder = Mockery::mock(ParameterToEntityMapBuilder::class);

        $this->requestLogger = Mockery::mock(RequestLogger::class);

        $this->kernelEvent = Mockery::mock(KernelEvent::class);

        $this->exceptionLogger = Mockery::mock(ExceptionLogger::class);

        $this->requestApiResolver = Mockery::mock(RequestApiResolver::class);
    }

    public function testOnKernelControllerNoMappersOnlyParameterToEntityMap()
    {
        $parameterToEntityMap = ['key' => 'entity'];
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn($parameterToEntityMap);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
        $key = key($parameterToEntityMap);
        $this->assertEquals($parameterToEntityMap[$key], $parameterBag->get($key));
    }

    public function testOnKernelControllerWithMapperAndParameterToEntityMap()
    {
        $entity = [1];
        $parameterToEntityMap = ['key' => $entity];
        $name = 'requestName';
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn($parameterToEntityMap);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
        $key = key($parameterToEntityMap);
        $this->assertEquals($parameterToEntityMap[$key], $parameterBag->get($key));
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestMapperWhenDecodingFails()
    {
        $this->expectException(ApiException::class);
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent')->andReturn('a=b&c=d');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;


        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity');
        $requestMapper->shouldReceive('getName')->andReturn('name');

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getDecoder')->andThrow(EncodingException::class);
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingFails()
    {
        $this->expectException(ApiException::class);
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andThrow(InvalidDataException::class);
        $requestMapper->shouldReceive('getName')->andReturn('name');

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingSucceedsWithoutValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingSucceedsWithValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([]);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestMapperValidationThrowsException()
    {
        $this->expectException(ApiException::class);
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperWhenMappingFails()
    {
        $this->expectException(ApiException::class);
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = (new Request())->query;
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andThrow(InvalidDataException::class);
        $requestMapper->shouldReceive('getName')->andReturn('name');

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
    }

    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsException()
    {
        $this->expectException(ApiException::class);
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = (new Request())->query;
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperWhenMappingSucceedsWithValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = (new Request())->query;
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate');
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->kernelEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsExceptionWithPathConverter()
    {
        $name = 'requestName';
        $entity = [
            'firstName' => 1,
            'last_name' => 2,
        ];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = (new Request())->query;
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $validator = Mockery::mock(ValidatorInterface::class);
        $violationList = new ConstraintViolationList([
            new ConstraintViolation('firstName message', '', [], '', 'firstName', '1'),
            new ConstraintViolation('lastName message', '', [], '', 'last_name', '2'),
        ]);
        $validator->shouldReceive('validate')->andReturn($violationList);
        $propertyPathConverter = Mockery::mock(PropertyPathConverterInterface::class);
        $propertyPathConverter->shouldReceive('convert')->andReturnUsing(function ($path) {
            return strtoupper($path);
        });
        $propertiesAwareValidator = new PropertiesAwareValidator($validator, $propertyPathConverter);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $exceptionThrowed = false;
        try {
            $restListener->onKernelController($this->kernelEvent);
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
        $this->assertNull($parameterBag->get($name));
    }

    public function testOnKernelViewResponseHasXFrameOptionsHeader()
    {
        $restListener = $this->createRestListener();

        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getCacheStrategy');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $restApi = Mockery::mock(RestApi::class);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn($restApi);

        $httpKernelMock = Mockery::mock(HttpKernelInterface::class);
        $requestMock = Mockery::mock(Request::class);

        $event = new ViewEvent(
            $httpKernelMock,
            $requestMock,
            $this->getMainRequestType(),
            null
        );

        $restListener->onKernelView($event);

        $responseHeaders = $event->getResponse()->headers;
        $headerName = 'x-frame-options';

        $this->assertTrue($responseHeaders->has($headerName));
        $this->assertEquals('DENY', $responseHeaders->get($headerName));
    }

    public function testOnKernelViewLogsOnlyTheResultClassAndTheModificationDate()
    {
        $cacheStrategy = Mockery::mock(CacheStrategyInterface::class);
        $cacheStrategy->shouldReceive('getMaxAge')->andReturn(0);
        $cacheStrategy->shouldReceive('getModifiedAt')->andReturn(new DateTime('2026-11-20T10:00:00+00:00'));

        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getCacheStrategy')->andReturn($cacheStrategy);
        $this->apiManager->shouldReceive('getResponseMapper')->andReturnNull();
        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(Mockery::mock(RestApi::class));

        $event = new ViewEvent(
            Mockery::mock(HttpKernelInterface::class),
            Request::create('/api/v1/cards'),
            $this->getMainRequestType(),
            new ArrayObject(['card_number' => 'secret-card-number'])
        );

        $this->createRestListener()->onKernelView($event);

        $this->assertSame(
            ['Handling kernel.view', 'Setting modified at', 'No response mapper set'],
            $this->storedLoggerMessages
        );
        $this->assertSame(
            [[ArrayObject::class], ['2026-11-20T10:00:00+00:00', null], null],
            $this->storedContext
        );
    }

    /**
     * @dataProvider controllerDataProvider
     */
    public function testOnKernelControllerLogsOnlyTheClassesOfMappedEntities($controller, string $loggedController)
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent')->andReturn('');
        $request->attributes = new ParameterBag(['_controller' => $controller]);
        $request->query = (new Request(['card_number' => 'secret-card-number']))->query;

        $queryMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $queryMapper->shouldReceive('mapToEntity')->andReturn(new ArrayObject(['card_number' => 'secret-card-number']));
        $queryMapper->shouldReceive('getName')->andReturn('filter');
        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn(['password' => 'secret-password']);
        $requestMapper->shouldReceive('getName')->andReturn('data');
        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->twice();

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($queryMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $this->createRestListener()->onKernelController($this->kernelEvent);

        $this->assertSame(
            [
                'Handling kernel.controller',
                'Mapped query data to entity',
                'Validating entity',
                'Mapped data to entity',
                'Validating entity',
            ],
            $this->storedLoggerMessages
        );
        $this->assertSame(
            [
                [$loggedController],
                ['entity' => ArrayObject::class],
                [ArrayObject::class],
                ['entity' => 'array'],
                ['array'],
            ],
            $this->storedContext
        );
    }

    public function controllerDataProvider(): array
    {
        return [
            'controller service name' => ['card_controller:getCardAction', 'card_controller:getCardAction'],
            'controller class and method' => [['CardController', 'getCardAction'], 'CardController::getCardAction'],
            'controller object and method' => [
                [new ArrayObject(['card_number' => 'secret-card-number']), 'getCardAction'],
                'ArrayObject::getCardAction',
            ],
            'controller nested array' => [['CardController', ['getCardAction', 'previewAction']], 'array'],
            'controller closure' => [function () {
                return 'secret-card-number';
            }, Closure::class],
        ];
    }

    public function testOnKernelExceptionLogsOnlyTheExceptionClass()
    {
        $restListener = $this->createRestListener();

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getResponseForException')->andReturn(null);

        $event = new ExceptionEvent(
            Mockery::mock(HttpKernelInterface::class),
            Mockery::mock(Request::class),
            $this->getMainRequestType(),
            new RuntimeException('Authorization: MAC id="client", nonce="secret-nonce", mac="secret-mac"')
        );

        $restListener->onKernelException($event);

        $this->assertSame(['Handling kernel.exception', 'Exception class'], $this->storedLoggerMessages);
        $this->assertSame([null, [RuntimeException::class]], $this->storedContext);
    }

    public function testOnKernelExceptionWithErrorResponseLogsOnlyTheStatusCode()
    {
        $restListener = $this->createRestListener();
        $exception = new RuntimeException('Authorization: MAC id="client", nonce="secret-nonce", mac="secret-mac"');
        $response = new Response('{"error_description":"nonce=\"secret-nonce\""}', Response::HTTP_UNAUTHORIZED);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getResponseForException')->andReturn($response);
        $this->exceptionLogger->shouldReceive('log')->once()->with($this->logger, $response, $exception);

        $event = new ExceptionEvent(
            Mockery::mock(HttpKernelInterface::class),
            Mockery::mock(Request::class),
            $this->getMainRequestType(),
            $exception
        );

        $restListener->onKernelException($event);

        $this->assertSame(
            ['Handling kernel.exception', 'Exception class', 'Setting error response'],
            $this->storedLoggerMessages
        );
        $this->assertSame(
            [null, [RuntimeException::class], [Response::HTTP_UNAUTHORIZED]],
            $this->storedContext
        );
        $this->assertSame($response, $event->getResponse());
    }

    /**
     * @dataProvider invalidPropertiesDataProvider
     */
    public function testOnKernelControllerLogsOnlyTheInvalidPropertyNames(array $properties, array $loggedPropertyNames)
    {
        $invalidDataException = new InvalidDataException('Value nonce="secret-nonce" is invalid');
        $invalidDataException->setProperties($properties);
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn([1]);
        $requestMapper->shouldReceive('getName')->andReturn('requestName');

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->kernelEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->andThrow($invalidDataException);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        try {
            $this->createRestListener()->onKernelController($this->kernelEvent);
            $this->fail('ApiException was not thrown');
        } catch (ApiException $exception) {
            $this->assertSame(
                [
                    'Handling kernel.controller',
                    'Mapped data to entity',
                    'Validating entity',
                    'Invalid data exception caught',
                ],
                $this->storedLoggerMessages
            );
            $this->assertSame(
                [
                    ['controller'],
                    ['entity' => 'array'],
                    ['array'],
                    ['invalid properties' => $loggedPropertyNames],
                ],
                $this->storedContext
            );
        }
    }

    public function invalidPropertiesDataProvider(): array
    {
        return [
            'messages that quote other fields' => [
                [
                    'password_repeat' => ['This value should be equal to "secret-password".'],
                    'bic' => ['This BIC does not match the IBAN "secret-iban".', 'This value is not valid.'],
                ],
                ['password_repeat', 'bic'],
            ],
            'paths with submitted array keys and indexes' => [
                [
                    '[jane.doe@example.com].email' => ['This value is not a valid email address.'],
                    '[john.doe@example.com].email' => ['This value is not a valid email address.'],
                    'contacts[secret.contact@example.com]' => ['This value should not be blank.'],
                    'children[0].iban' => ['This is not a valid IBAN.'],
                    '[secret-key]' => ['This value should be of type string.'],
                ],
                ['email', 'contacts', 'children.iban'],
            ],
            'submitted array keys that contain brackets' => [
                [
                    'contacts[a]LT601010012345678901]' => ['This value should not be blank.'],
                    '[x]secret[y]' => ['This value should be of type string.'],
                    'children[a]jane.doe@example.com].iban' => ['This is not a valid IBAN.'],
                    'orders[[1] John Doe].amount' => ['This value should be positive.'],
                    'cards[secret-pan' => ['This value is not valid.'],
                ],
                ['contacts', 'children.iban', 'orders.amount', 'cards'],
            ],
        ];
    }

    private function getMainRequestType(): int
    {
        if (defined(HttpKernelInterface::class . '::MAIN_REQUEST')) {
            return HttpKernelInterface::MAIN_REQUEST;
        }

        return HttpKernelInterface::MASTER_REQUEST;
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
}
