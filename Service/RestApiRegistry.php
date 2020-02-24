<?php

declare(strict_types=1);

namespace Paysera\Bundle\RestBundle\Service;

use Paysera\Bundle\RestBundle\RestApi;

class RestApiRegistry
{
    private $apiByKey;
    private $apiByUriPattern;

    public function __construct()
    {
        $this->apiByKey = [];
        $this->apiByUriPattern = [];
    }

    public function addApiByKey(RestApi $restApi, string $apiKey)
    {
        $this->apiByKey[$apiKey] = $restApi;
    }

    public function addApiByUriPattern(RestApi $restApi, string $uriPattern)
    {
        $this->apiByUriPattern[$uriPattern] = $restApi;
    }

    /**
     * @param string $apiKey
     *
     * @return RestApi|null
     */
    public function getApiByKey(string $apiKey)
    {
        return $this->apiByKey[$apiKey] ?? null;
    }

    /**
     * @param string $path
     *
     * @return RestApi|null
     */
    public function getApiByUriPattern(string $path)
    {
        foreach ($this->apiByUriPattern as $pattern => $api) {
            if (preg_match($pattern, $path) === 1) {
                return $api;
            }
        }

        return null;
    }
}
