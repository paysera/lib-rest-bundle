<?php

namespace Paysera\Bundle\RestBundle\Tests\Service;

use Paysera\Bundle\RestBundle\Service\RequestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class RequestLoggerTest extends TestCase
{
    /**
     * @dataProvider logDataProvider
     */
    public function testLog(array $headers, array $parts, array $expectedRecords)
    {
        $records = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->method('debug')
            ->willReturnCallback(function ($message, array $context = []) use (&$records) {
                $records[] = [$message, $context];
            })
        ;
        $request = Request::create('/api/v1/items?limit=1', 'GET', [], [], [], [], '{"name":"item"}');
        $request->headers->add($headers);

        (new RequestLogger($logger))->log($request, $parts);

        $this->assertSame($expectedRecords, $records);
    }

    public function logDataProvider(): array
    {
        $credentialHeaders = [
            'Authorization' => 'Bearer bearer-credential-value',
            'Cookie' => 'SESSION=cookie-credential-value',
            'X-Api-Key' => 'api-key-credential-value',
            'Content-Type' => 'application/json',
        ];

        return [
            'Header record carries the content type and no credential header' => [
                'headers' => $credentialHeaders,
                'parts' => ['url' => false, 'header' => true, 'content' => false],
                'expectedRecords' => [
                    ['Request header', ['Content-Type' => 'application/json']],
                ],
            ],
            'Header record carries no MAC authorization value' => [
                'headers' => [
                    'Authorization' => 'MAC id="mac-id", ts="1", nonce="n", mac="mac-credential-value"',
                    'Content-Type' => 'application/json',
                ],
                'parts' => ['url' => false, 'header' => true, 'content' => false],
                'expectedRecords' => [
                    ['Request header', ['Content-Type' => 'application/json']],
                ],
            ],
            'Header record without a content type carries null' => [
                'headers' => ['Authorization' => 'Basic YmFzaWMtY3JlZGVudGlhbA=='],
                'parts' => ['url' => false, 'header' => true, 'content' => false],
                'expectedRecords' => [
                    ['Request header', ['Content-Type' => null]],
                ],
            ],
            'Every part is logged and no credential header reaches any record' => [
                'headers' => $credentialHeaders,
                'parts' => ['url' => true, 'header' => true, 'content' => true],
                'expectedRecords' => [
                    ['Request url', ['Url' => '/api/v1/items?limit=1']],
                    ['Request header', ['Content-Type' => 'application/json']],
                    ['Request content', ['{"name":"item"}']],
                ],
            ],
            'Nothing is logged when no part is enabled' => [
                'headers' => $credentialHeaders,
                'parts' => ['url' => false, 'header' => false, 'content' => false],
                'expectedRecords' => [],
            ],
        ];
    }
}
