<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Tests\Event;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ConsoleCommandEventTest extends TestCase
{
    #[TestWith([-1])]
    #[TestWith([256])]
    public function testTheExitCodeMustBeBetween0And255(int $exitCode)
    {
        $event = new ConsoleCommandEvent(new Command('foo'), new ArrayInput([]), new NullOutput());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exit code must be between 0 and 255.');

        $event->setExitCode($exitCode);
    }
}
