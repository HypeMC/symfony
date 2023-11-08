<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Beanstalkd\Transport;

use Pheanstalk\Contract\PheanstalkInterface;
use Pheanstalk\Exception;
use Pheanstalk\Pheanstalk;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Exception\TransportException;

class_alias(interface_exists(PheanstalkInterface::class) ? Connection4Trait::class : Connection5Trait::class, __NAMESPACE__.'\\ConnectionTrait');

/**
 * @author Antonio Pauletich <antonio.pauletich95@gmail.com>
 *
 * @internal
 *
 * @final
 */
class Connection
{
    use ConnectionTrait;

    private const DEFAULT_OPTIONS = [
        'tube_name' => 'default',
        'timeout' => 0,
        'ttr' => 90,
    ];

    /**
     * Available options:.
     *
     * * tube_name: name of the tube
     * * timeout: message reservation timeout (in seconds)
     * * ttr: the message time to run before it is put back in the ready queue (in seconds)
     */
    private array $configuration;
    private int $timeout;
    private int $ttr;

    public static function fromDsn(#[\SensitiveParameter] string $dsn, array $options = []): self
    {
        if (false === $components = parse_url($dsn)) {
            throw new InvalidArgumentException('The given Beanstalkd DSN is invalid.');
        }

        $connectionCredentials = [
            'host' => $components['host'],
            'port' => $components['port'] ?? self::$defaultPort,
        ];

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        $configuration = [];
        $configuration += $options + $query + self::DEFAULT_OPTIONS;

        // check for extra keys in options
        $optionsExtraKeys = array_diff(array_keys($options), array_keys(self::DEFAULT_OPTIONS));
        if (0 < \count($optionsExtraKeys)) {
            throw new InvalidArgumentException(sprintf('Unknown option found : [%s]. Allowed options are [%s].', implode(', ', $optionsExtraKeys), implode(', ', array_keys(self::DEFAULT_OPTIONS))));
        }

        // check for extra keys in options
        $queryExtraKeys = array_diff(array_keys($query), array_keys(self::DEFAULT_OPTIONS));
        if (0 < \count($queryExtraKeys)) {
            throw new InvalidArgumentException(sprintf('Unknown option found in DSN: [%s]. Allowed options are [%s].', implode(', ', $queryExtraKeys), implode(', ', array_keys(self::DEFAULT_OPTIONS))));
        }

        return new self(
            $configuration,
            Pheanstalk::create($connectionCredentials['host'], $connectionCredentials['port'])
        );
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getTube(): string
    {
        return (string) $this->tube;
    }

    /**
     * @param int $delay The delay in milliseconds
     *
     * @return string The inserted id
     */
    public function send(string $body, array $headers, int $delay = 0): string
    {
        $message = json_encode([
            'body' => $body,
            'headers' => $headers,
        ]);

        if (false === $message) {
            throw new TransportException(json_last_error_msg());
        }

        try {
            $this->client->useTube($this->tube);
            $job = $this->client->put(
                $message,
                self::$defaultPriority,
                $delay / 1000,
                $this->ttr
            );
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return $job->getId();
    }

    public function get(): ?array
    {
        $job = $this->getFromTube();

        if (null === $job) {
            return null;
        }

        $data = $job->getData();

        $beanstalkdEnvelope = json_decode($data, true);

        return [
            'id' => (string) $job->getId(),
            'body' => $beanstalkdEnvelope['body'],
            'headers' => $beanstalkdEnvelope['headers'],
        ];
    }

    private function getFromTube(): ?object
    {
        try {
            $this->client->watch($this->tube);

            return $this->client->reserveWithTimeout($this->timeout);
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function ack(string $id): void
    {
        try {
            $this->client->useTube($this->tube);
            $this->client->delete($this->getJob((int) $id));
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function reject(string $id): void
    {
        try {
            $this->client->useTube($this->tube);
            $this->client->delete($this->getJob((int) $id));
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function getMessageCount(): int
    {
        try {
            $tubeStats = $this->client->statsTube($this->tube);
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return $tubeStats->currentJobsReady;
    }
}
