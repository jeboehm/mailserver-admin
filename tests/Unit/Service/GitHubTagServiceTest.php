<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service;

use App\Service\GitHubTagService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitHubTagServiceTest extends TestCase
{
    private MockObject|HttpClientInterface $httpClient;
    private GitHubTagService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = new GitHubTagService($this->httpClient);
    }

    public function testGetLatestTagSuccessWithVPrefix(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['name' => 'v1.2.3'],
                ['name' => 'v1.2.2'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.github.com/repos/jeboehm/mailserver-admin/tags',
                [
                    'headers' => [
                        'Accept' => 'application/vnd.github.v3+json',
                    ],
                ]
            )
            ->willReturn($response);

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetLatestTagSuccessWithoutVPrefix(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['name' => '1.2.3'],
                ['name' => '1.2.2'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetLatestTagSuccessWithMultipleVPrefix(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['name' => 'vv1.2.3'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        // ltrim removes all leading 'v' characters
        $this->assertEquals('1.2.3', $result);
    }

    public function testGetLatestTagEmptyTagsArray(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTagMissingNameKey(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['other' => 'value'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTagNon200StatusCode(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(500);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTag404ClientException(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $exception = new class($response) extends \Exception implements ClientExceptionInterface, HttpExceptionInterface {
            public function __construct(private ResponseInterface $response)
            {
                parent::__construct('Not found', 404);
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTagNon404ClientException(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $exception = new class($response) extends \Exception implements ClientExceptionInterface, HttpExceptionInterface {
            public function __construct(private ResponseInterface $response)
            {
                parent::__construct('Forbidden', 403);
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->expectException(ClientExceptionInterface::class);

        $this->service->getLatestTag('jeboehm', 'mailserver-admin');
    }

    public function testGetLatestTagTransportException(): void
    {
        $exception = new class('Transport error') extends \Exception implements TransportExceptionInterface {
        };

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->expectException(TransportExceptionInterface::class);

        $this->service->getLatestTag('jeboehm', 'mailserver-admin');
    }

    public function testGetLatestTagServerException(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $exception = new class($response) extends \Exception implements ServerExceptionInterface, HttpExceptionInterface {
            public function __construct(private ResponseInterface $response)
            {
                parent::__construct('Server error');
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->expectException(ServerExceptionInterface::class);

        $this->service->getLatestTag('jeboehm', 'mailserver-admin');
    }

    public function testGetLatestTagRedirectionException(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $exception = new class($response) extends \Exception implements RedirectionExceptionInterface, HttpExceptionInterface {
            public function __construct(private ResponseInterface $response)
            {
                parent::__construct('Redirection error');
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $this->expectException(RedirectionExceptionInterface::class);

        $this->service->getLatestTag('jeboehm', 'mailserver-admin');
    }

    public function testGetLatestTagDecodingException(): void
    {
        $exception = new class('Decoding error') extends \Exception implements DecodingExceptionInterface {
        };

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willThrowException($exception);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(DecodingExceptionInterface::class);

        $this->service->getLatestTag('jeboehm', 'mailserver-admin');
    }

    public function testGetLatestTagFromPathSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['name' => 'v1.2.3'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.github.com/repos/jeboehm/mailserver-admin/tags',
                $this->anything()
            )
            ->willReturn($response);

        $result = $this->service->getLatestTagFromPath('jeboehm/mailserver-admin');

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetLatestTagFromPathInvalidFormat(): void
    {
        $result = $this->service->getLatestTagFromPath('invalid');

        $this->assertNull($result);
    }

    public function testGetLatestTagFromPathInvalidFormatTooManySlashes(): void
    {
        $result = $this->service->getLatestTagFromPath('owner/repo/subpath');

        $this->assertNull($result);
    }

    public function testGetLatestTagFromPathEmptyString(): void
    {
        $result = $this->service->getLatestTagFromPath('');

        $this->assertNull($result);
    }
}
