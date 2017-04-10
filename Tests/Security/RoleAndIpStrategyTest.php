<?php

namespace Paysera\Bundle\RestBundle\Tests\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\AnonymousToken;
use Paysera\Bundle\RestBundle\Security\RoleAndIpStrategy;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit_Framework_MockObject_MockObject;
use PHPUnit_Framework_TestCase;
use Psr\Log\NullLogger;

class RoleAndIpStrategyTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var RoleAndIpStrategy
     */
    private $strategy;

    public function setUp()
    {
        /** @var TokenStorageInterface|PHPUnit_Framework_MockObject_MockObject $tokenStorageMock */
        $tokenStorageMock = $this->getMockBuilder(TokenStorageInterface::class)->getMock();
        $tokenStorageMock
            ->expects($this->any())
            ->method('getToken')
            ->willReturn(new AnonymousToken('secret', 'user', ['ROLE_API_USER']))
        ;

        $this->strategy = new RoleAndIpStrategy(
            $tokenStorageMock,
            new NullLogger()
        );
    }

    /**
     * @param boolean $expected
     * @param Request $request
     * @param array   $roles
     * @param array   $ips
     *
     * @dataProvider dataProviderForIsAllowed
     */
    public function testIsAllowed($expected, Request $request, array $roles = [], array $ips = [])
    {
        $this->strategy->setRoles($roles);
        $this->strategy->setIps($ips);

        $this->assertEquals($expected, $this->strategy->isAllowed($request));
    }

    /**
     * @return array
     */
    public function dataProviderForIsAllowed()
    {
        return [
            'case_no_role_restriction' => [
                true,
                $this->createRequest()
            ],
            'case_invalid_role' => [
                false,
                $this->createRequest(),
                ['ROLE_ACCESS_DIFFERENT_RESOURCE']
            ],
            'case_valid_role' => [
                true,
                $this->createRequest(),
                ['ROLE_API_USER']
            ],
            'case_invalid_ip' => [
                false,
                $this->createRequest(['REMOTE_ADDR' => '127.0.0.2']),
                [],
                ['127.0.0.1']
            ],
            'case_valid_ip' => [
                true,
                $this->createRequest(['REMOTE_ADDR' => '127.0.0.1']),
                [],
                ['127.0.0.1']
            ],
            'case_valid_role_and_ip' => [
                true,
                $this->createRequest(['REMOTE_ADDR' => '127.0.0.1']),
                ['ROLE_API_USER'],
                ['127.0.0.1']
            ]
        ];
    }

    /**
     * @param array $server
     *
     * @return Request
     */
    private function createRequest(array $server = [])
    {
        return new Request(
            [],
            [],
            [],
            [],
            [],
            $server
        );
    }
}
