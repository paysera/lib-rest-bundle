<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Tests\Cache;

use DateTimeImmutable;
use Paysera\Bundle\RestBundle\Cache\DefaultCacheStrategy;
use Paysera\Bundle\RestBundle\Cache\ModificationDateProviderInterface;
use PHPUnit\Framework\TestCase;

class DefaultCacheStrategyTest extends TestCase
{
    /**
     * @dataProvider strategyDataProvider
     * @param array<string, DateTimeImmutable> $result
     * @param array{int, DateTimeImmutable|null} $expected
     */
    public function testReportsTheMaxAgeAndTheProvidersModificationDate(
        DefaultCacheStrategy $strategy,
        array $result,
        array $expected
    ): void {
        $this->assertSame($expected, [$strategy->getMaxAge(), $strategy->getModifiedAt($result)]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function strategyDataProvider(): array
    {
        $result = ['modified_at' => new DateTimeImmutable('2026-09-30 12:00:00')];
        $provider = new class implements ModificationDateProviderInterface {
            public function getModifiedAt($result)
            {
                return $result['modified_at'];
            }
        };

        return [
            'the defaults' => [new DefaultCacheStrategy(), $result, [0, null]],
            'a max age without a provider' => [new DefaultCacheStrategy(60, null), $result, [60, null]],
            'a max age and a provider' => [
                new DefaultCacheStrategy(60, $provider),
                $result,
                [60, $result['modified_at']],
            ],
        ];
    }
}
