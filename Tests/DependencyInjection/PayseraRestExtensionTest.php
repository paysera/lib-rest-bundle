<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Tests\DependencyInjection;

use Paysera\Bundle\RestBundle\DependencyInjection\PayseraRestExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension as HttpKernelExtension;

class PayseraRestExtensionTest extends TestCase
{
    /**
     * @dataProvider loadDataProvider
     * @param array<array<string, mixed>> $configs
     * @param array<string, mixed> $expected
     */
    public function testLoadRegistersTheServices(array $configs, array $expected): void
    {
        $container = new ContainerBuilder();
        $empty = new ContainerBuilder();

        (new PayseraRestExtension())->load($configs, $container);

        $definitions = array_diff_key($container->getDefinitions(), $empty->getDefinitions());
        ksort($definitions);
        $this->assertSame($expected, [
            'definitions' => array_map(
                function (Definition $definition): array {
                    return $this->dumpDefinition($definition, false);
                },
                $definitions,
            ),
            'aliases' => array_keys(array_diff_key($container->getAliases(), $empty->getAliases())),
            'parameters' => $container->getParameterBag()->all(),
        ]);
    }

    /**
     * @return array<string, array{array<array<string, mixed>>, array<string, mixed>}>
     */
    public static function loadDataProvider(): array
    {
        $definitions = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/services.json'), true);
        $withConverter = $definitions;
        $withConverter['paysera_rest.api_manager']['calls'][] = [
            'setPropertyPathConverter',
            [[
                'reference' => 'app.property_path_converter',
                'invalid_behavior' => ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE,
            ]],
        ];

        return [
            'no configuration' => [
                [],
                [
                    'definitions' => $definitions,
                    'aliases' => [],
                    'parameters' => ['paysera_rest.locales' => []],
                ],
            ],
            'locales and a property path converter' => [
                [['locales' => ['en', 'lt'], 'property_path_converter' => 'app.property_path_converter']],
                [
                    'definitions' => $withConverter,
                    'aliases' => [],
                    'parameters' => ['paysera_rest.locales' => ['en', 'lt']],
                ],
            ],
        ];
    }

    public function testTheExtensionDoesNotExtendHttpKernelsInternalExtension(): void
    {
        $this->assertNotInstanceOf(HttpKernelExtension::class, new PayseraRestExtension());
    }

    /**
     * @return array<string, mixed>
     */
    private function dumpDefinition(Definition $definition, bool $inline): array
    {
        return array_filter(
            [
                'class' => $definition->getClass(),
                'parent' => $definition instanceof ChildDefinition ? $definition->getParent() : null,
                'public' => $inline ? null : $definition->isPublic(),
                'shared' => $definition->isShared() ? null : false,
                'lazy' => $definition->isLazy() ?: null,
                'abstract' => $definition->isAbstract() ?: null,
                'synthetic' => $definition->isSynthetic() ?: null,
                'autowired' => $definition->isAutowired() ?: null,
                'autoconfigured' => $definition->isAutoconfigured() ?: null,
                'deprecated' => $definition->isDeprecated() ?: null,
                'factory' => $this->export($definition->getFactory()),
                'arguments' => $this->export($definition->getArguments()),
                'calls' => $this->export($definition->getMethodCalls()),
                'properties' => $this->export($definition->getProperties()),
                'configurator' => $this->export($definition->getConfigurator()),
                'tags' => $definition->getTags(),
                'decorated' => $definition->getDecoratedService(),
            ],
            function ($value): bool {
                return $value !== null && $value !== [];
            },
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function export($value)
    {
        if ($value instanceof Reference) {
            return ['reference' => (string) $value, 'invalid_behavior' => $value->getInvalidBehavior()];
        }
        if ($value instanceof Definition) {
            return ['inline' => $this->dumpDefinition($value, true)];
        }
        if (is_array($value)) {
            return array_map([$this, 'export'], $value);
        }

        return $value;
    }
}
