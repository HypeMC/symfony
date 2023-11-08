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

use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Contract\SocketFactoryInterface;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\TubeName;

trait Connection5Trait
{
    private static int $defaultPort = SocketFactoryInterface::DEFAULT_PORT;
    private static int $defaultPriority = PheanstalkPublisherInterface::DEFAULT_PRIORITY;

    private PheanstalkPublisherInterface&PheanstalkSubscriberInterface&PheanstalkManagerInterface $client;
    private TubeName $tube;

    public function __construct(
        array $configuration,
        PheanstalkPublisherInterface&PheanstalkSubscriberInterface&PheanstalkManagerInterface $client,
    ) {
        $this->configuration = array_replace_recursive(self::DEFAULT_OPTIONS, $configuration);
        $this->client = $client;
        $this->tube = new TubeName($this->configuration['tube_name']);
        $this->timeout = $this->configuration['timeout'];
        $this->ttr = $this->configuration['ttr'];
    }

    private function getJob(int $id): JobId
    {
        return new JobId($id);
    }
}
