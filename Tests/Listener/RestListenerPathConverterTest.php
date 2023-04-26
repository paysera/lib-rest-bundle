<?php

namespace Paysera\Bundle\RestBundle\Tests;

use Mockery;
use Paysera\Bundle\RestBundle\ApiManager;
use Paysera\Bundle\RestBundle\Exception\ApiException;
use Paysera\Bundle\RestBundle\Listener\RestListener;
use Paysera\Bundle\RestBundle\Normalizer\ErrorNormalizer;
use Paysera\Bundle\RestBundle\Normalizer\NameAwareDenormalizerInterface;
use Paysera\Bundle\RestBundle\RestApi;
use Paysera\Bundle\RestBundle\Service\ExceptionLogger;
use Paysera\Bundle\RestBundle\Service\FormatDetector;
use Paysera\Bundle\RestBundle\Service\ParameterToEntityMapBuilder;
use Paysera\Bundle\RestBundle\Service\RequestApiResolver;
use Paysera\Bundle\RestBundle\Service\RequestLogger;
use Paysera\Component\Serializer\Converter\CamelCaseToSnakeCaseConverter;
use Paysera\Component\Serializer\Converter\NoOpConverter;
use Paysera\Component\Serializer\Encoding\Json;
use Paysera\Component\Serializer\Entity\Violation;
use Paysera\Component\Serializer\Factory\ContextAwareNormalizerFactory;
use Paysera\Component\Serializer\Normalizer\ArrayNormalizer;
use Paysera\Component\Serializer\Normalizer\ViolationNormalizer;
use Paysera\Component\Serializer\Validation\PropertyPathConverterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RestListenerPathConverterTest extends TestCase
{
    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsExceptionWithCamelCasePathConverter()
    {
        $exceptionThrown = false;
        try {
            $this
                ->createRestListener(new CamelCaseToSnakeCaseConverter())
                ->onKernelController($this->getControllerEvent());
        } catch (ApiException $apiException) {
            $exceptionThrown = true;
            $this->assertEquals(
                [
                    'first_name' => ['firstName message'],
                    'last_name' => ['lastName message'],
                ],
                $apiException->getProperties()
            );

            $this->assertEquals(
                [
                    (new Violation())->setField('first_name')->setMessage('firstName message'),
                    (new Violation())->setField('last_name')->setMessage('lastName message'),
                ],
                $apiException->getViolations()
            );
        }

        $this->assertTrue($exceptionThrown);
    }

    /**
     * @param PropertyPathConverterInterface $pathConverter
     *
     * @return RestListener
     */
    private function createRestListener(PropertyPathConverterInterface $pathConverter)
    {
        $parameterBag = new ParameterBag();
        $queryParameterBag = new ParameterBag();

        $entity = [
            'firstName' => 1,
            'last_name' => 2,
        ];

        $parameterBag->add($entity);
        $parameterBag->set('api_key', 'api');

        $queryParameterBag->add($entity);

        $request = Mockery::mock(Request::class);
        $request->allows('getContent')->andReturns('{}');
        $request->attributes = $parameterBag;
        $request->query = $queryParameterBag;

        $validator = Mockery::mock(ValidatorInterface::class);

        $violationList = new ConstraintViolationList([
            new ConstraintViolation('firstName message', '', [], '', 'firstName', '1'),
            new ConstraintViolation('lastName message', '', [], '', 'last_name', '2'),
        ]);

        $validator->allows('validate')->andReturns($violationList);

        $formatDetector = Mockery::mock(FormatDetector::class);
        $formatDetector->allows('getRequestFormat')->andReturns('json');

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->allows('mapToEntity')->andReturns([]);
        $requestMapper->allows('getName')->andReturns('name');

        $container = Mockery::mock(ContainerInterface::class);
        $container->allows('get')->andReturns($requestMapper);

        $api = new RestApi($container, new NullLogger());
        $api->dontLogRequest('controller');
        $api->addRequestMapper('api', 'controller', 'property');
        $api->setPropertyPathConverter($pathConverter);

        $requestApiResolver = Mockery::mock(RequestApiResolver::class);
        $requestApiResolver->allows('getApiForRequest')->andReturns($api);
        $requestApiResolver->allows('getApiKeyForRequest')->andReturns($parameterBag->get('api_key'));

        $apiManager = new ApiManager(
            $formatDetector,
            new NullLogger(),
            $validator,
            new ErrorNormalizer(
                new ArrayNormalizer(new ViolationNormalizer()),
                new ArrayNormalizer(new ViolationNormalizer())
            ),
            $requestApiResolver
        );

        $apiManager->addDecoder(new Json(), 'json');

        $parameterToEntityMapBuilder = Mockery::mock(ParameterToEntityMapBuilder::class);
        $parameterToEntityMapBuilder->allows('buildParameterToEntityMap')->andReturns([]);

        return new RestListener(
            $apiManager,
            Mockery::mock(ContextAwareNormalizerFactory::class),
            new NullLogger(),
            $parameterToEntityMapBuilder,
            new RequestLogger(new NullLogger()),
            new ExceptionLogger(),
            $requestApiResolver,
            []
        );
    }

    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsExceptionWithNoOpConverter()
    {
        $exceptionThrown = false;
        try {
            $this->createRestListener(new NoOpConverter())
                ->onKernelController($this->getControllerEvent());
            $this->expectException(ApiException::class);
        } catch (ApiException $apiException) {
            $exceptionThrown = true;
            $this->assertEquals(
                [
                    'firstName' => ['firstName message'],
                    'last_name' => ['lastName message'],
                ],
                $apiException->getProperties()
            );

            $this->assertEquals(
                [
                    (new Violation())->setField('firstName')->setMessage('firstName message'),
                    (new Violation())->setField('last_name')->setMessage('lastName message'),
                ],
                $apiException->getViolations()
            );
        }

        $this->assertTrue($exceptionThrown);
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
