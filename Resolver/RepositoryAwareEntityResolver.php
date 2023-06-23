<?php

namespace Paysera\Bundle\RestBundle\Resolver;

use Doctrine\Persistence\ObjectRepository;

class RepositoryAwareEntityResolver implements EntityResolverInterface
{
    private ObjectRepository $repository;
    private string $searchField;

    public function __construct(
        ObjectRepository $repository,
        string $searchField
    ) {
        $this->repository = $repository;
        $this->searchField = $searchField;
    }

    public function resolveFrom($value)
    {
        return $this->repository->findOneBy(
            [
                $this->searchField => $value,
            ]
        );
    }
}
