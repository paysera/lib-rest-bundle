<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Tests\DependencyInjection;

use Paysera\Bundle\RestBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    /**
     * @dataProvider processConfigurationDataProvider
     * @param array<array<string, mixed>> $configs
     * @param array<string, mixed> $expected
     */
    public function testProcessConfiguration(array $configs, array $expected): void
    {
        $this->assertSame($expected, (new Processor())->processConfiguration(new Configuration(), $configs));
    }

    /**
     * @return array<string, array{array<array<string, mixed>>, array<string, mixed>}>
     */
    public static function processConfigurationDataProvider(): array
    {
        return [
            'no configuration' => [
                [],
                ['property_path_converter' => null, 'locales' => []],
            ],
            'locales' => [
                [['locales' => ['en', 'lt']]],
                ['locales' => ['en', 'lt'], 'property_path_converter' => null],
            ],
            'property path converter and locales' => [
                [['property_path_converter' => 'app.property_path_converter', 'locales' => ['en']]],
                ['property_path_converter' => 'app.property_path_converter', 'locales' => ['en']],
            ],
        ];
    }
}
