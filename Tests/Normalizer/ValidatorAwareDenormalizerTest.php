<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Tests\Normalizer;

use Paysera\Bundle\RestBundle\Normalizer\ValidatorAwareDenormalizer;
use Paysera\Bundle\RestBundle\Tests\Normalizer\Fixtures\ValidatedEntity;
use Paysera\Component\Serializer\Exception\InvalidDataException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class ValidatorAwareDenormalizerTest extends TestCase
{
    /**
     * @dataProvider validateDataProvider
     * @param string[]|null $groups
     * @param array{string, array<string, string[]>}|null $expectedError
     */
    public function testValidateChecksTheConstraintsOfTheGivenGroups(
        ValidatedEntity $entity,
        ?array $groups,
        ?array $expectedError
    ): void {
        $denormalizer = new class extends ValidatorAwareDenormalizer {
            public function mapToEntity($data)
            {
                $this->validate($data['entity'], $data['groups']);

                return $data['entity'];
            }
        };
        $denormalizer->setValidator(
            Validation::createValidatorBuilder()->addMethodMapping('loadValidatorMetadata')->getValidator()
        );

        $error = null;
        try {
            $denormalizer->mapToEntity(['entity' => $entity, 'groups' => $groups]);
        } catch (InvalidDataException $exception) {
            $error = [$exception->getMessage(), $exception->getProperties()];
        }

        $this->assertSame($expectedError, $error);
    }

    /**
     * @return array<string, array{ValidatedEntity, string[]|null, array{string, array<string, string[]>}|null}>
     */
    public static function validateDataProvider(): array
    {
        $blank = 'This value should not be blank.';

        return [
            'no groups: the Default group' => [
                new ValidatedEntity('', ''),
                null,
                [$blank, ['name' => [$blank]]],
            ],
            'an empty list of groups: the Default group' => [
                new ValidatedEntity('', ''),
                [],
                [$blank, ['name' => [$blank]]],
            ],
            'one group' => [
                new ValidatedEntity('', ''),
                ['api'],
                [$blank, ['code' => [$blank]]],
            ],
            'two groups' => [
                new ValidatedEntity('', ''),
                ['Default', 'api'],
                [$blank, ['name' => [$blank], 'code' => [$blank]]],
            ],
            'a valid entity' => [
                new ValidatedEntity('Item', 'I-1'),
                ['Default', 'api'],
                null,
            ],
        ];
    }
}
