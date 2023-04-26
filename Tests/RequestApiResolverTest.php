<?php

namespace Paysera\Bundle\RestBundle\Tests;

use Mockery;
use Paysera\Bundle\RestBundle\RestApi;
use Paysera\Bundle\RestBundle\Service\RequestApiKeyResolver;
use Paysera\Bundle\RestBundle\Service\RequestApiResolver;
use Paysera\Bundle\RestBundle\Service\RestApiRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

class RequestApiResolverTest extends TestCase
{
    /**
     * @var RestApiRegistry|Mockery\MockInterface
     */
    private $restApiRegistry;

    /**
     * @var RequestApiKeyResolver|Mockery\MockInterface
     */
    private $apiKeyResolver;

    /**
     * @var RequestApiResolver|Mockery\MockInterface
     */
    private $requestApiResolver;

    protected function setUp(): void
    {
        $this->restApiRegistry = Mockery::mock(RestApiRegistry::class);
        $this->apiKeyResolver = Mockery::mock(RequestApiKeyResolver::class);
        $this->requestApiResolver = new RequestApiResolver($this->restApiRegistry, $this->apiKeyResolver);
    }

    public function testGetApiForRequest_MissingApiForApiKey()
    {
        $this->apiKeyResolver->allows('getApiKeyForRequest')->andReturns('key');
        $this->restApiRegistry->allows('getApiByKey')->andReturnNull();

        $this->expectException(RuntimeException::class);

        $request = Mockery::mock(Request::class);
        $this->requestApiResolver->getApiForRequest($request);
    }

    public function testGetApiForRequest_ReturnsApiByKey()
    {
        $this->apiKeyResolver->allows('getApiKeyForRequest')->andReturns('key');

        $restApi = Mockery::mock(RestApi::class);
        $this->restApiRegistry->allows('getApiByKey')->andReturns($restApi);

        $request = Mockery::mock(Request::class);
        $result = $this->requestApiResolver->getApiForRequest($request);

        $this->assertSame($restApi, $result);
    }

    public function testGetApiForRequest_ReturnsApiByUriPattern()
    {
        $this->apiKeyResolver->allows('getApiKeyForRequest')->andReturnNull();

        $restApi = Mockery::mock(RestApi::class);
        $this->restApiRegistry->allows('getApiByUriPattern')->andReturns($restApi);

        $request = Mockery::mock(Request::class);
        $request->allows('getPathInfo')->andReturns('/foo/bar');

        $this->requestApiResolver->setGlobalApiUriPattern('#^(/foo/bar)$#');

        $result = $this->requestApiResolver->getApiForRequest($request);

        $this->assertSame($restApi, $result);
    }

    public function testGetApiForRequest_ReturnsNullWithoutGlobalUriPattern()
    {
        $this->apiKeyResolver->allows('getApiKeyForRequest')->andReturnNull();

        $this->restApiRegistry->allows('getApiByUriPattern')->never();

        $request = Mockery::mock(Request::class);
        $request->allows('getPathInfo')->andReturns('/foo/bar');

        $result = $this->requestApiResolver->getApiForRequest($request);

        $this->assertNull($result);
    }

    public function testGetApiForRequest_ReturnsNullWithoutPregMatch()
    {
        $this->apiKeyResolver->allows('getApiKeyForRequest')->andReturnNull();

        $this->restApiRegistry->allows('getApiByUriPattern')->never();

        $request = Mockery::mock(Request::class);
        $request->allows('getPathInfo')->andReturns('/bar/baz');

        $this->requestApiResolver->setGlobalApiUriPattern('#^(/foo/bar)$#');

        $result = $this->requestApiResolver->getApiForRequest($request);

        $this->assertNull($result);
    }
}
