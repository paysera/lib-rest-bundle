<?php

namespace Paysera\Bundle\RestBundle\Tests\Service;

use ArrayObject;
use Mockery;
use Mockery\MockInterface;
use Paysera\Bundle\RestBundle\ApiManager;
use Paysera\Bundle\RestBundle\Exception\ApiException;
use Paysera\Bundle\RestBundle\Resolver\AttributeResolverInterface;
use Paysera\Bundle\RestBundle\Service\ParameterToEntityMapBuilder;
use Paysera\Component\Serializer\Exception\InvalidDataException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class ParameterToEntityMapBuilderTest extends TestCase
{
    /** @var MockInterface|LoggerInterface */
    private $logger;

    /** @var MockInterface|AttributeResolverInterface */
    private $attributeResolver;

    /** @var ParameterToEntityMapBuilder */
    private $builder;

    private $records = [];

    public function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->attributeResolver = Mockery::mock(AttributeResolverInterface::class);
        $this->attributeResolver->shouldReceive('getAttributeName')->andReturn('iban');
        $this->attributeResolver->shouldReceive('getParameterName')->andReturn('account');

        $apiManager = Mockery::mock(ApiManager::class);
        $apiManager->shouldReceive('getRequestAttributeResolvers')->andReturn([$this->attributeResolver]);

        $this->builder = new ParameterToEntityMapBuilder($this->logger, $apiManager);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testBuildParameterToEntityMapLogsOnlyTheEntityClass()
    {
        $entity = new ArrayObject(['iban' => 'secret-iban']);
        $this->attributeResolver->shouldReceive('resolveFromAttributeValue')->once()->with('secret-iban')->andReturn($entity);
        $this->logger->shouldReceive('debug')->once()->andReturnUsing($this->storeRecord('debug'));

        $map = $this->builder->buildParameterToEntityMap($this->createRequest(['iban' => 'secret-iban']));

        $this->assertSame(['account' => $entity], $map);
        $this->assertSame(
            [['debug', 'Resolved entity from attribute data to ', [ArrayObject::class]]],
            $this->records
        );
    }

    public function testBuildParameterToEntityMapLogsOnlyAttributeNamesWhenTheAttributeIsMissing()
    {
        $this->logger->shouldReceive('warning')->once()->andReturnUsing($this->storeRecord('warning'));

        try {
            $this->builder->buildParameterToEntityMap($this->createRequest(['card_number' => 'secret-card-number']));
            $this->fail('InvalidDataException was not thrown');
        } catch (InvalidDataException $exception) {
            $this->assertSame(
                [[
                    'warning',
                    'Could not find the specified attribute name in the request',
                    ['expected attribute' => 'iban', 'received attributes' => ['card_number']],
                ]],
                $this->records
            );
        }
    }

    public function testBuildParameterToEntityMapLogsOnlyTheAttributeNameWhenNoEntityIsResolved()
    {
        $this->attributeResolver->shouldReceive('resolveFromAttributeValue')->once()->with('secret-iban')->andReturnNull();
        $this->logger->shouldReceive('debug')->once()->andReturnUsing($this->storeRecord('debug'));
        $this->logger->shouldReceive('warning')->once()->andReturnUsing($this->storeRecord('warning'));

        try {
            $this->builder->buildParameterToEntityMap($this->createRequest(['iban' => 'secret-iban']));
            $this->fail('ApiException was not thrown');
        } catch (ApiException $exception) {
            $this->assertSame(
                [
                    ['debug', 'Resolved entity from attribute data to ', ['NULL']],
                    ['warning', 'Could not resolve entity from the attribute value', ['attribute name' => 'iban']],
                ],
                $this->records
            );
        }
    }

    private function createRequest(array $attributes)
    {
        return new Request([], [], $attributes);
    }

    private function storeRecord($level)
    {
        return function ($message, array $context = []) use ($level) {
            $this->records[] = [$level, $message, $context];
        };
    }
}
