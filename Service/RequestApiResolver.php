<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Service;

use RuntimeException;
use Paysera\Bundle\RestBundle\RestApi;
use Symfony\Component\HttpFoundation\Request;

class RequestApiResolver
{
    private $registry;
    private $resolver;
    private $globalApiUriPattern;

    public function __construct(
        RestApiRegistry $registry,
        RequestApiKeyResolver $resolver
    ) {
        $this->registry = $registry;
        $this->resolver = $resolver;
    }

    public function setGlobalApiUriPattern(string $globalApiUriPattern)
    {
        $this->globalApiUriPattern = $globalApiUriPattern;
    }

    /**
     * @param Request $request
     * @return RestApi|null
     *
     * @throws RuntimeException
     */
    public function getApiForRequest(Request $request)
    {
        $apiKey = $this->resolver->getApiKeyForRequest($request);
        if ($apiKey !== null) {
            $api = $this->registry->getApiByKey($apiKey);
            if ($api === null) {
                throw new RuntimeException('Api not registered with such key: ' . $apiKey);
            }

            return $api;
        }
        if (
            $this->globalApiUriPattern !== null
            && preg_match($this->globalApiUriPattern, $request->getPathInfo()) === 1
        ) {
            return $this->registry->getApiByUriPattern($request->getPathInfo());
        }
        return null;
    }

    public function getApiKeyForRequest(Request $request)
    {
        return $this->resolver->getApiKeyForRequest($request);
    }
}
