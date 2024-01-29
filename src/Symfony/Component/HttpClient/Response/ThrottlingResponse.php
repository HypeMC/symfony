<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Response;

use Symfony\Component\HttpClient\ThrottlingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @internal
 */
class ThrottlingResponse implements ResponseInterface, StreamableInterface
{
    public function __construct(
        private readonly ResponseInterface $response,
        private float $waitDuration,
    ) {
    }

    public function __sleep(): array
    {
        throw new \BadMethodCallException('Cannot serialize '.__CLASS__);
    }

    public function __wakeup(): void
    {
        throw new \BadMethodCallException('Cannot unserialize '.__CLASS__);
    }

    public function __destruct()
    {
        if (method_exists($this->response, '__destruct')) {
            $this->response->__destruct();
        }
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->response->getContent($throw);
    }

    public function toArray(bool $throw = true): array
    {
        return $this->response->toArray($throw);
    }

    public function cancel(): void
    {
        $this->response->cancel();
    }

    public function getInfo(string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    public function toStream(bool $throw = true)
    {
        return $this->response->toStream($throw);
    }

    /**
     * @internal
     */
    public static function stream(HttpClientInterface $client, iterable $responses, ?float $timeout): \Generator
    {
        $wrappedResponses = [];
        $throttlingMap = new \SplObjectStorage();

        foreach ($responses as $r) {
            if (!$r instanceof self) {
                throw new \TypeError(sprintf('"%s::stream()" expects parameter 1 to be an iterable of ThrottlingResponse objects, "%s" given.', ThrottlingHttpClient::class, get_debug_type($r)));
            }

            $throttlingMap[$r->response] = $r;
            $wrappedResponses[] = $r->response;
        }

        foreach ($client->stream($wrappedResponses, $timeout) as $r => $chunk) {
            yield $throttlingMap[$r] => $chunk;
        }
    }

    public function initializePauseHandler(): void
    {
        if (0 < $this->waitDuration) {
            $this->response->getInfo('pause_handler')($this->waitDuration);
            $this->waitDuration = 0;
        }
    }
}
