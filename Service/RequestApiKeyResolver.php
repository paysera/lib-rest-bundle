<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Service;

use Symfony\Component\HttpFoundation\Request;

class RequestApiKeyResolver
{
    const DEFAULT_ROUTING_ATTRIBUTE = 'api_key';

    private $routingAttribute;

    public function __construct(string $routingAttribute = self::DEFAULT_ROUTING_ATTRIBUTE)
    {
        $this->routingAttribute = $routingAttribute;
    }

    /**
     * @param Request $request
     *
     * @return string|null
     */
    public function getApiKeyForRequest(Request $request)
    {
        return $request->attributes->get($this->routingAttribute);
    }
}
