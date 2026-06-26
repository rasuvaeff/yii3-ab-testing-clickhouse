<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingClickHouse\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 */
final class FakePsrFactory
{
    public static function serverRequest(): ServerRequestInterface
    {
        return new class implements ServerRequestInterface {
            public function getRequestTarget(): string
            {
                return '/';
            }
            public function withRequestTarget($requestTarget): static
            {
                return $this;
            }
            public function getMethod(): string
            {
                return 'GET';
            }
            public function withMethod($method): static
            {
                return $this;
            }
            public function getUri(): \Psr\Http\Message\UriInterface
            {
                return new \GuzzleHttp\Psr7\Uri('http://localhost');
            }
            public function withUri(\Psr\Http\Message\UriInterface $uri, $preserveHost = false): static
            {
                return $this;
            }
            public function getProtocolVersion(): string
            {
                return '1.1';
            }
            public function withProtocolVersion($version): static
            {
                return $this;
            }
            public function getHeaders(): array
            {
                return [];
            }
            public function hasHeader($name): bool
            {
                return false;
            }
            public function getHeader($name): array
            {
                return [];
            }
            public function getHeaderLine($name): string
            {
                return '';
            }
            public function withHeader($name, $value): static
            {
                return $this;
            }
            public function withAddedHeader($name, $value): static
            {
                return $this;
            }
            public function withoutHeader($name): static
            {
                return $this;
            }
            public function getBody(): \Psr\Http\Message\StreamInterface
            {
                return new \GuzzleHttp\Psr7\Stream(fopen('php://temp', 'r+'));
            }
            public function withBody(\Psr\Http\Message\StreamInterface $body): static
            {
                return $this;
            }
            public function getServerParams(): array
            {
                return [];
            }
            public function getCookieParams(): array
            {
                return [];
            }
            public function withCookieParams(array $cookies): static
            {
                return $this;
            }
            public function getQueryParams(): array
            {
                return [];
            }
            public function withQueryParams(array $query): static
            {
                return $this;
            }
            public function getUploadedFiles(): array
            {
                return [];
            }
            public function withUploadedFiles(array $uploadedFiles): static
            {
                return $this;
            }
            public function getParsedBody(): ?array
            {
                return null;
            }
            public function withParsedBody($data): static
            {
                return $this;
            }
            public function getAttributes(): array
            {
                return [];
            }
            public function getAttribute($name, $default = null): mixed
            {
                return $default;
            }
            public function withAttribute($name, $value): static
            {
                return $this;
            }
            public function withoutAttribute($name): static
            {
                return $this;
            }
        };
    }

    public static function response(): ResponseInterface
    {
        return new class implements ResponseInterface {
            public function getProtocolVersion(): string
            {
                return '1.1';
            }
            public function withProtocolVersion($version): static
            {
                return $this;
            }
            public function getStatusCode(): int
            {
                return 200;
            }
            public function withStatus($code, $reasonPhrase = ''): static
            {
                return $this;
            }
            public function getReasonPhrase(): string
            {
                return 'OK';
            }
            public function getHeaders(): array
            {
                return [];
            }
            public function hasHeader($name): bool
            {
                return false;
            }
            public function getHeader($name): array
            {
                return [];
            }
            public function getHeaderLine($name): string
            {
                return '';
            }
            public function withHeader($name, $value): static
            {
                return $this;
            }
            public function withAddedHeader($name, $value): static
            {
                return $this;
            }
            public function withoutHeader($name): static
            {
                return $this;
            }
            public function getBody(): \Psr\Http\Message\StreamInterface
            {
                return new \GuzzleHttp\Psr7\Stream(fopen('php://temp', 'r+'));
            }
            public function withBody(\Psr\Http\Message\StreamInterface $body): static
            {
                return $this;
            }
        };
    }

    public static function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public static function throwingHandler(\Throwable $throwable): RequestHandlerInterface
    {
        return new class ($throwable) implements RequestHandlerInterface {
            public function __construct(
                private readonly \Throwable $throwable,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->throwable;
            }
        };
    }
}
