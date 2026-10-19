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
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandArgumentsEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConsoleCommandArgumentsEventTest extends TestCase
{
    public function testTheArgumentsAreTheOnesResolvedFromTheInput()
    {
        $state = new \stdClass();
        $event = null;
        $tester = $this->createTester($state, static function (ConsoleCommandArgumentsEvent $e) use (&$event) { $event = $e; });

        $this->assertSame(0, $tester->run(['command' => 'foo', 'name' => 'bar']));
        $this->assertSame('bar', $state->received);
        $this->assertSame(['bar'], $event->getArguments());
        $this->assertSame(['name' => 'bar'], $event->getNamedArguments());
    }

    public function testTheCommandIsCalledWithTheReplacedArguments()
    {
        $state = new \stdClass();
        $tester = $this->createTester($state, static function (ConsoleCommandArgumentsEvent $event) { $event->setArguments(['replaced']); });

        $this->assertSame(0, $tester->run(['command' => 'foo', 'name' => 'bar']));
        $this->assertSame('replaced', $state->received);
    }

    public function testAnExitCodeKeepsTheCommandFromBeingCalled()
    {
        $state = new \stdClass();
        $state->received = null;
        $tester = $this->createTester($state, static function (ConsoleCommandArgumentsEvent $event) { $event->setExitCode(3); });

        $this->assertSame(3, $tester->run(['command' => 'foo', 'name' => 'bar']));
        $this->assertNull($state->received);
    }

    #[TestWith([-1])]
    #[TestWith([256])]
    public function testTheExitCodeMustBeBetween0And255(int $exitCode)
    {
        $event = new ConsoleCommandArgumentsEvent(new Command('foo'), new ArrayInput([]), new NullOutput(), [], new \ReflectionFunction(static function () {}));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exit code must be between 0 and 255.');

        $event->setExitCode($exitCode);
    }

    private function createTester(object $state, callable $listener): ApplicationTester
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConsoleCommandArgumentsEvent::class, $listener);

        $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        $command = new Command('foo');
        $command->setCode(static function (#[Argument] string $name = '') use ($state): int {
            $state->received = $name;

            return 0;
        });

        $application->addCommand($command);

        return new ApplicationTester($application);
    }
}
