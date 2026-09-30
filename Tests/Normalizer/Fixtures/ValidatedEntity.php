<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Tests\Normalizer\Fixtures;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Mapping\ClassMetadata;

class ValidatedEntity
{
    public string $name;

    public string $code;

    public function __construct(string $name, string $code)
    {
        $this->name = $name;
        $this->code = $code;
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $codeConstraint = new NotBlank();
        $codeConstraint->groups = ['api'];

        $metadata->addPropertyConstraint('name', new NotBlank());
        $metadata->addPropertyConstraint('code', $codeConstraint);
    }
}
