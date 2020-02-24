<?php

namespace Paysera\Bundle\RestBundle\Tests;

use Mockery;
use Paysera\Bundle\RestBundle\RestApi;
use Paysera\Bundle\RestBundle\Service\RestApiRegistry;
use PHPUnit\Framework\TestCase;

class RestApiRegistryTest extends TestCase
{
    public function testGetApiByUriPattern()
    {
        $restApi = Mockery::mock(RestApi::class);

        $registry = new RestApiRegistry();
        $registry->addApiByUriPattern($restApi, '#^\/foo\/bar$#');

        $this->assertSame($restApi, $registry->getApiByUriPattern('/foo/bar'));
        $this->assertNull($registry->getApiByUriPattern('/foo/bar/baz'));
        $this->assertNull($registry->getApiByUriPattern(''));
    }

    public function testGetApiByKey()
    {
        $restApi = Mockery::mock(RestApi::class);

        $registry = new RestApiRegistry();
        $registry->addApiByKey($restApi, 'key');

        $this->assertSame($restApi, $registry->getApiByKey('key'));
        $this->assertNull($registry->getApiByKey('unregistered_key'));
    }
}
