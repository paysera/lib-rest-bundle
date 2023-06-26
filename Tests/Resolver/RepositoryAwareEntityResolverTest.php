<?php

namespace Paysera\Bundle\RestBundle\Resolver;

use stdClass;
use PHPUnit\Framework\TestCase;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;

class RepositoryAwareEntityResolverTest extends TestCase
{
    private const KEY_SEARCH_FIELD = 'field';
    private const VALUE_SEARCH_FIELD = 'value';

    private MockObject $objectRepositoryMock;

    private RepositoryAwareEntityResolver $resolver;

    protected function setUp(): void
    {
        $this->objectRepositoryMock = $this->createMock(ObjectRepository::class);

        $this->resolver = new RepositoryAwareEntityResolver(
            $this->objectRepositoryMock,
            self::KEY_SEARCH_FIELD
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
                    self::KEY_SEARCH_FIELD => self::VALUE_SEARCH_FIELD,
                ]
            )->willReturn($object)
        ;

        $this->assertEquals(
            $object,
            $this->resolver->resolveFrom(self::VALUE_SEARCH_FIELD)
        );
    }
}
