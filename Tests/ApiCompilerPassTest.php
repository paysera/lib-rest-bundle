<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Tests;

use Paysera\Bundle\RestBundle\DependencyInjection\Compiler\ApiCompilerPass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class ApiCompilerPassTest extends TestCase
{
    /**
     * @var Definition
     */
    private $serviceDefinition;

    /**
     * @var Definition|MockObject
     */
    private $resolverDefinition;

    /**
     * @var ContainerBuilder
     */
    private $containerBuilder;

    /**
     * @var ApiCompilerPass
     */
    private $apiCompilerPass;

    public function setUp()
    {
        $this->serviceDefinition = new Definition();
        $this->resolverDefinition = $this->createMock(Definition::class);
        $this->containerBuilder = new ContainerBuilder();

        $this->containerBuilder->setDefinition('paysera_rest.service.rest_api_registry', $this->serviceDefinition);
        $this->containerBuilder->setDefinition(
            'paysera_rest.service.request_api_resolver',
            $this->resolverDefinition
        );

        $this->resolverDefinition->expects($this->any())
            ->method('hasTag')
            ->willReturn(false)
        ;

        $this->apiCompilerPass = new ApiCompilerPass();
    }

    /**
     * @param array $patterns
     * @param string|null $expected
     *
     * @dataProvider dataProvider
     */
    public function testGlobalApiUriPatternBuilding(array $patterns, $expected)
    {
        foreach ($patterns as $id => $uri) {
            $fooBarRestApi = new Definition('stdClass');
            $fooBarRestApi->addTag('paysera_rest.api', ['uri_pattern' => $uri]);
            $this->containerBuilder->setDefinition($id, $fooBarRestApi);
        }

        if ($expected !== null) {
            $this->resolverDefinition->expects($this->once())
                ->method('addMethodCall')
                ->with('setGlobalApiUriPattern', [$expected])
            ;
        } else {
            $this->resolverDefinition->expects($this->never())
                ->method('addMethodCall')
            ;
        }

        $this->apiCompilerPass->process($this->containerBuilder);
    }

    public function dataProvider(): array
    {
        return [
            'Should not setGlobalUriPattern' => [
                [],
                null,
            ],
            'Correctly builds pattern with single rest api' => [
                [
                    'foo_bar_api' => '#^/foo/bar#',
                ],
                '#(^/foo/bar)#',
            ],
            'Correctly builds pattern with multiple rest apis' => [
                [
                    'foo_bar_api' => '#^/foo/bar#',
                    'foo_baz_api' => '#^/foo/bar/baz/[a-z]{2}#',
                ],
                '#(^/foo/bar)|(^/foo/bar/baz/[a-z]{2})#',
            ],
        ];
    }
}
