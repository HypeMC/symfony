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
use Pheanstalk\JobId;

trait Connection4Trait
{
    private static int $defaultPort = PheanstalkInterface::DEFAULT_PORT;
    private static int $defaultPriority = PheanstalkInterface::DEFAULT_PRIORITY;

    private PheanstalkInterface $client;
    private string $tube;

    public function __construct(array $configuration, PheanstalkInterface $client)
    {
        //@TODO parent::__construct($configuration);
        //$this->configuration = array_replace_recursive(self::DEFAULT_OPTIONS, $configuration);
        $this->client = $client;
        $this->tube = $configuration['tube_name'];
        //$this->timeout = $this->configuration['timeout'];
        //$this->ttr = $this->configuration['ttr'];
    }

    private function getJob(int $id): JobId
    {
        return new JobId($id);
    }
}
