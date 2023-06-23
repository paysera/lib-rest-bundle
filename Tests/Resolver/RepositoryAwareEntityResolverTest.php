<?php

namespace Paysera\Bundle\RestBundle\Resolver;

use stdClass;
use PHPUnit\Framework\TestCase;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;

class RepositoryAwareEntityResolverTest extends TestCase
{
    private const SEARCH_FIELD = 'field';
    private const VALUE = 'value';

    private MockObject $objectRepositoryMock;

    private RepositoryAwareEntityResolver $resolver;

    protected function setUp(): void
    {
        $this->objectRepositoryMock = $this->createMock(ObjectRepository::class);

        $this->resolver = new RepositoryAwareEntityResolver(
            $this->objectRepositoryMock,
            self::SEARCH_FIELD
        );
    }

    public function testResolveFrom(): void
    {
        $object = new stdClass();
        $this->objectRepositoryMock
            ->expects($this->once())
            ->method('findOneBy')
            ->with(
                [
                    self::SEARCH_FIELD => self::VALUE,
                ]
            )->willReturn($object)
        ;

        $this->assertEquals(
            $object,
            $this->resolver->resolveFrom(self::VALUE)
        );
    }
}
