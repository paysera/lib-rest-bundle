<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Tests;

use Paysera\Bundle\RestBundle\DependencyInjection\Compiler\ApiCompilerPass;
use Paysera\Bundle\RestBundle\PayseraRestBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PayseraRestBundleTest extends TestCase
{
    public function testBuildRegistersTheApiCompilerPass(): void
    {
        $container = new ContainerBuilder();

        (new PayseraRestBundle())->build($container);

        $passes = array_values(array_filter(
            $container->getCompilerPassConfig()->getBeforeOptimizationPasses(),
            function ($pass): bool {
                return $pass instanceof ApiCompilerPass;
            }
        ));
        $this->assertEquals([new ApiCompilerPass()], $passes);
    }
}
