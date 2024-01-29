<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Component\HttpClient\Response\ThrottlingResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Limits the number of requests within a certain period.
 */
class ThrottlingHttpClient implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait {
        reset as private traitReset;
    }

    /** @var list<\WeakReference<ThrottlingResponse>> */
    private array $responses = [];

    public function __construct(
        HttpClientInterface $client,
        private readonly LimiterInterface $rateLimiter,
    ) {
        $this->client = $client;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = new ThrottlingResponse(
            $this->client->request($method, $url, $options),
            $this->rateLimiter->reserve()->getWaitDuration(),
        );

        $this->responses[] = \WeakReference::create($response);

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, float $timeout = null): ResponseStreamInterface
    {
        $this->initializePauseHandlers();

        if ($responses instanceof ThrottlingResponse) {
            $responses = [$responses];
        }

        return new ResponseStream(ThrottlingResponse::stream($this->client, $responses, $timeout));
    }

    public function reset(): void
    {
        $this->traitReset();
        $this->rateLimiter->reset();
    }

    private function initializePauseHandlers(): void
    {
        [$responses, $this->responses] = [$this->responses, []];

        foreach ($responses as $response) {
            $response->get()->initializePauseHandler();
        }
    }
}
