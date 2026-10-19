<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Tests\EventListener;

use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ArgumentResolver\ArgumentResolver;
use Symfony\Component\Console\ArgumentResolver\ValueResolver\LockValueResolver;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Lock;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\TraceableCommand;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\EventListener\LockAttributeListener;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Tests\Fixtures\LockClosureKeyCommand;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Stopwatch\Stopwatch;

class LockAttributeListenerTest extends TestCase
{
    private ?ContainerInterface $lockFactories = null;
    private EventDispatcher $dispatcher;
    private Application $application;

    public function testTheLockIsReleasedOnceTheCommandHasTerminated()
    {
        $tester = $this->createTester(new FooLockCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock']));
        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock']));
    }

    public function testTheCommandIsSkippedWhileAnotherProcessHoldsTheLock()
    {
        $lock = $this->createLock('foo:lock');
        $lock->acquire();

        $tester = $this->createTester(new FooLockCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock'], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('The command is already running in another process', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    public function testTheExitCodeOfASkippedCommandIsTheOneTheAttributeDeclares()
    {
        $lock = $this->createLock('foo:lock:failure');
        $lock->acquire();

        $tester = $this->createTester(new FooLockFailureCommand());

        $this->assertSame(Command::FAILURE, $tester->run(['command' => 'foo:lock:failure']));
    }

    #[TestWith([['console', 'default'], 'foo:lock', 'console'])]
    #[TestWith([['default'], 'foo:lock', 'default'])]
    #[TestWith([['console', 'default', 'other'], 'foo:lock:named-factory', 'other'])]
    public function testTheLockComesFromTheNamedResourceElseConsoleElseDefault(array $resources, string $name, string $expected)
    {
        $lock = $this->createStub(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        $factories = [];
        foreach ($resources as $resource) {
            $factory = $this->createMock(LockFactory::class);
            $factory->expects($expected === $resource ? $this->once() : $this->never())->method('createLock')->willReturn($lock);
            $factories[$resource] = static fn () => $factory;
        }
        $this->lockFactories = new ServiceLocator($factories);

        $tester = $this->createTester(new FooLockCommand(), new FooLockNamedFactoryCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => $name]));
    }

    public function testTheResourceTheAttributeNamesMustExist()
    {
        $this->lockFactories = new ServiceLocator([]);
        $tester = $this->createTester(new FooLockNamedFactoryCommand());
        $this->application->setCatchExceptions(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lock factory "other" does not exist.');

        $tester->run(['command' => 'foo:lock:named-factory']);
    }

    #[TestWith(['foo:lock:blocking', 'acquire', true, 60.0])]
    #[TestWith(['foo:lock:read', 'acquireRead', false, null])]
    public function testTheLockIsAcquiredTheWayTheAttributeSays(string $name, string $method, bool $blocking, ?float $ttl)
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method($method)->with($blocking)->willReturn(true);
        $lock->expects($this->never())->method('acquire' === $method ? 'acquireRead' : 'acquire');

        $factory = $this->createMock(LockFactory::class);
        $factory->expects($this->once())->method('createLock')->with($name, $ttl)->willReturn($lock);
        $this->lockFactories = new ServiceLocator(['default' => static fn () => $factory]);

        $tester = $this->createTester(new FooLockBlockingCommand(), new FooLockReadCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => $name]));
    }

    public function testAWriteLockOnTheSameKeyPromotesTheReadLockWithoutWaiting()
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquireRead')->with(false)->willReturn(true);
        $lock->expects($this->once())->method('acquire')->with(false)->willReturn(true);

        $factory = $this->createMock(LockFactory::class);
        $factory->expects($this->once())->method('createLock')->willReturn($lock);
        $this->lockFactories = new ServiceLocator(['default' => static fn () => $factory]);

        $tester = $this->createTester(new FooLockReadThenWriteCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:read-write']));
        $this->assertSame('executed', $tester->getDisplay());
    }

    #[TestWith(['foo:lock:write-read', 'acquire'])]
    #[TestWith(['foo:lock:read-read', 'acquireRead'])]
    public function testALockOnTheSameKeyThatNeedsNothingMoreKeepsTheFirstOne(string $name, string $method)
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method($method)->with(false)->willReturn(true);
        $lock->expects($this->never())->method('acquire' === $method ? 'acquireRead' : 'acquire');

        $factory = $this->createMock(LockFactory::class);
        $factory->expects($this->once())->method('createLock')->willReturn($lock);
        $this->lockFactories = new ServiceLocator(['default' => static fn () => $factory]);

        $tester = $this->createTester(new FooLockWriteThenReadCommand(), new FooLockReadThenReadCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => $name]));
        $this->assertSame('executed', $tester->getDisplay());
    }

    public function testACommandWhoseReadLockCannotBePromotedIsSkipped()
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquireRead')->willReturn(true);
        $lock->expects($this->once())->method('acquire')->with(false)->willReturn(false);

        $factory = $this->createMock(LockFactory::class);
        $factory->expects($this->once())->method('createLock')->willReturn($lock);
        $this->lockFactories = new ServiceLocator(['default' => static fn () => $factory]);

        $tester = $this->createTester(new FooLockReadThenWriteCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:read-write'], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('The command is already running in another process', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    #[TestWith(['foo:lock:input'])]
    #[TestWith(['foo:lock:args'])]
    public function testTheKeyReadsTheInput(string $name)
    {
        $lock = $this->createLock('foo-lock-a');
        $lock->acquire();

        $tester = $this->createTester(new FooLockInputKeyCommand(), new FooLockArgsKeyCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => $name, 'tenant' => 'a']));
        $this->assertStringNotContainsString('executed', $tester->getDisplay());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => $name, 'tenant' => 'b']));
        $this->assertStringContainsString('executed', $tester->getDisplay());
    }

    #[TestWith(['foo:lock:inject', 'lock'])]
    #[TestWith(['foo:no-lock', 'null'])]
    public function testTheAcquiredLockIsInjectedIntoTheCommand(string $name, string $expected)
    {
        $tester = $this->createTester(new FooLockInjectCommand(), new FooNoLockCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => $name]));
        $this->assertSame($expected, $tester->getDisplay());
    }

    public function testTheLockIsInjectedWhenTheKeyReadsTheArguments()
    {
        $tester = $this->createTester(new FooLockArgsKeyInjectCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:args-inject', 'tenant' => 'a']));
        $this->assertSame('executed 1', $tester->getDisplay());
    }

    #[RequiresPhp('>=8.5.0')]
    public function testAClosureKeyReadsTheResolvedArguments()
    {
        $tester = $this->createTester(new LockClosureKeyCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:closure', 'tenant' => 'a']));
        $this->assertSame('executed 1', $tester->getDisplay());
    }

    public function testANestedCommandReleasesItsOwnLocksOnly()
    {
        $tester = $this->createTester(new FooLockOuterCommand(), new FooLockInnerCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:outer']));
        $this->assertSame('inner outer-lock-held', $tester->getDisplay());
    }

    public function testASkippedCommandIsNotCalledWithTheLockItWasDenied()
    {
        $lock = $this->createLock('foo-lock-a');
        $lock->acquire();

        $tester = $this->createTester(new FooLockArgsKeyInjectCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:args-inject', 'tenant' => 'a'], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('The command is already running in another process', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    public function testANestedCommandSkippedByAnotherListenerKeepsTheLockOfTheOuterOne()
    {
        $tester = $this->createTester(new FooLockOuterCommand(), new FooLockInnerCommand());
        $this->dispatcher->addListener('console.command', static function (ConsoleCommandEvent $event) {
            if ('foo:lock:inner' === $event->getCommand()->getName()) {
                $event->disableCommand();
            }
        }, 10);

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:outer']));
        $this->assertSame(' outer-lock-held', $tester->getDisplay());
    }

    public function testACommandRunByAnotherOneThroughItsRunMethodLocksNothing()
    {
        $tester = $this->createTester(new FooLockRunMethodOuterCommand(), new FooLockArgsKeyNullableCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:run-outer']));
        $this->assertSame('inner-null inner-lock-free outer-lock-held', $tester->getDisplay());
    }

    public function testACommandNestedInItselfKeepsItsLockWhenTheNestedRunIsSkipped()
    {
        $tester = $this->createTester(new FooLockSelfNestingCommand());
        $this->dispatcher->addListener('console.command', static function (ConsoleCommandEvent $event) {
            if ($event->getInput()->getOption('nested')) {
                $event->disableCommand();
            }
        }, 10);

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:self']));
        $this->assertSame('outer-lock-held', $tester->getDisplay());
    }

    public function testACommandGivenTheInputOfAnotherOneLocksNothing()
    {
        $tester = $this->createTester(new FooLockDelegatingCommand(), new FooLockArgsKeyNullableCommand());

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:delegate', 'tenant' => 'x']));
        $this->assertSame('inner-null inner-lock-free outer-lock-held', $tester->getDisplay());
    }

    public function testTheKeyOfAProfiledCommandSeesTheCommandItWraps()
    {
        $lock = $this->createLock('foo-lock-unwrapped');
        $lock->acquire();

        $tester = $this->createTester(new TraceableCommand(new Command(null, new FooLockCommandVariableKeyCommand()), new Stopwatch()));

        $this->assertSame(Command::SUCCESS, $tester->run(['command' => 'foo:lock:traced']));
        $this->assertStringNotContainsString('executed', $tester->getDisplay());
    }

    public function testACommandRunOutsideAnApplicationLocksNothing()
    {
        $this->createTester(new FooLockArgsKeyCommand());
        $command = $this->application->find('foo:lock:args');

        new CommandTester($command)->execute(['tenant' => 'a']);

        $this->assertTrue($this->createLock('foo-lock-a')->acquire());
    }

    public function testAKeyThatReadsTheArgumentsOfACommandWithoutThemIsRejected()
    {
        $tester = $this->createTester(new FooLockClassicCommand());
        $this->application->setCatchExceptions(false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('reads the arguments of the command');

        $tester->run(['command' => 'foo:lock:classic']);
    }

    public function testAKeyWithASyntaxErrorIsReported()
    {
        $tester = $this->createTester(new FooLockBrokenKeyCommand());
        $this->application->setCatchExceptions(false);

        $this->expectException(SyntaxError::class);

        $tester->run(['command' => 'foo:lock:broken']);
    }

    private function createTester(object ...$commands): ApplicationTester
    {
        $listener = new LockAttributeListener($this->lockFactories);

        $this->dispatcher = $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);
        $dispatcher->addSubscriber($resolver = new LockValueResolver($listener));

        $this->application = $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        $application->setArgumentResolver(new ArgumentResolver([
            $resolver,
            ...ArgumentResolver::getDefaultArgumentValueResolvers(),
        ]));
        $application->addCommands($commands);

        return new ApplicationTester($application);
    }

    private function createLock(string $key): SharedLockInterface
    {
        return (new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore()))->createLock($key);
    }
}

#[AsCommand('foo:lock')]
#[Lock]
class FooLockCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->write('executed');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:failure')]
#[Lock(null, null, null, false, false, Command::FAILURE)]
class FooLockFailureCommand
{
    public function __invoke(): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:input')]
#[Lock(new Expression('"foo-lock-" ~ input.getArgument("tenant")'))]
class FooLockInputKeyCommand
{
    public function __invoke(#[Argument] string $tenant, OutputInterface $output): int
    {
        $output->write('executed');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:args')]
#[Lock(new Expression('"foo-lock-" ~ args["tenant"]'))]
class FooLockArgsKeyCommand
{
    public function __invoke(#[Argument] string $tenant, OutputInterface $output): int
    {
        $output->write('executed');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:args-inject')]
#[Lock(new Expression('"foo-lock-" ~ args["tenant"]'))]
class FooLockArgsKeyInjectCommand
{
    public function __invoke(#[Argument] string $tenant, SharedLockInterface $lock, OutputInterface $output): int
    {
        $output->write('executed '.$lock->isAcquired());

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:inject')]
#[Lock]
class FooLockInjectCommand
{
    public function __invoke(?SharedLockInterface $lock, OutputInterface $output): int
    {
        $output->write($lock instanceof SharedLockInterface ? 'lock' : 'null');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:no-lock')]
class FooNoLockCommand
{
    public function __invoke(?SharedLockInterface $lock, OutputInterface $output): int
    {
        $output->write($lock instanceof SharedLockInterface ? 'lock' : 'null');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:outer')]
#[Lock('foo-lock-outer')]
class FooLockOuterCommand
{
    public function __invoke(Application $application, SharedLockInterface $lock, OutputInterface $output): int
    {
        $application->doRun(new ArrayInput(['command' => 'foo:lock:inner']), $output);
        $output->write(' outer-lock-'.($lock->isAcquired() ? 'held' : 'lost'));

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:inner')]
#[Lock('foo-lock-inner')]
class FooLockInnerCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->write('inner');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:classic')]
#[Lock(new Expression('"foo-lock-" ~ args["tenant"]'))]
class FooLockClassicCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:broken')]
#[Lock(new Expression('"foo-lock-" ~ nonsense('))]
class FooLockBrokenKeyCommand
{
    public function __invoke(): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:run-outer')]
#[Lock('foo-lock-run-outer')]
class FooLockRunMethodOuterCommand
{
    public function __invoke(Application $application, SharedLockInterface $lock, OutputInterface $output): int
    {
        $application->find('foo:lock:args-nullable')->run(new ArrayInput(['command' => 'foo:lock:args-nullable', 'tenant' => 'x']), $output);

        $innerLock = (new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore()))->createLock('foo-lock-inner-x');
        $output->write(' inner-lock-'.($innerLock->acquire() ? 'free' : 'taken'));
        $innerLock->release();

        $output->write(' outer-lock-'.($lock->isAcquired() ? 'held' : 'lost'));

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:args-nullable')]
#[Lock(new Expression('"foo-lock-inner-" ~ args["tenant"]'))]
class FooLockArgsKeyNullableCommand
{
    public function __invoke(#[Argument] string $tenant, ?SharedLockInterface $lock, OutputInterface $output): int
    {
        $output->write('inner-'.($lock ? 'lock' : 'null'));

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:self')]
#[Lock('foo-lock-self')]
class FooLockSelfNestingCommand
{
    public function __invoke(Application $application, ?SharedLockInterface $lock, OutputInterface $output, #[Option] bool $nested = false): int
    {
        if (!$nested) {
            $application->doRun(new ArrayInput(['command' => 'foo:lock:self', '--nested' => true]), $output);
            $output->write('outer-lock-'.($lock->isAcquired() ? 'held' : 'lost'));
        }

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:delegate')]
#[Lock('foo-lock-delegate')]
class FooLockDelegatingCommand
{
    public function __invoke(#[Argument] string $tenant, InputInterface $input, Application $application, SharedLockInterface $lock, OutputInterface $output): int
    {
        $application->find('foo:lock:args-nullable')->run($input, $output);

        $innerLock = (new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore()))->createLock('foo-lock-inner-'.$tenant);
        $output->write(' inner-lock-'.($innerLock->acquire() ? 'free' : 'taken'));
        $innerLock->release();

        $output->write(' outer-lock-'.($lock->isAcquired() ? 'held' : 'lost'));

        return Command::SUCCESS;
    }
}

// the profiler's TraceableCommand does not carry the aliases of the command it wraps
#[AsCommand('foo:lock:traced', null, ['foo:traced'])]
#[Lock(new Expression('command.getAliases() ? "foo-lock-unwrapped" : "foo-lock-wrapper"'))]
class FooLockCommandVariableKeyCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->write('executed');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:named-factory')]
#[Lock(null, 'other')]
class FooLockNamedFactoryCommand
{
    public function __invoke(): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:blocking')]
#[Lock(null, null, 60.0, true)]
class FooLockBlockingCommand
{
    public function __invoke(): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:read')]
#[Lock(null, null, null, false, true)]
class FooLockReadCommand
{
    public function __invoke(): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:read-write')]
#[Lock('foo-lock-rw', null, null, false, true)]
class FooLockReadThenWriteCommand
{
    #[Lock('foo-lock-rw', null, null, true)]
    public function __invoke(OutputInterface $output): int
    {
        $output->write('executed');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:write-read')]
#[Lock('foo-lock-rw')]
class FooLockWriteThenReadCommand
{
    #[Lock('foo-lock-rw', null, null, false, true)]
    public function __invoke(OutputInterface $output): int
    {
        $output->write('executed');

        return Command::SUCCESS;
    }
}

#[AsCommand('foo:lock:read-read')]
#[Lock('foo-lock-rw', null, null, false, true)]
class FooLockReadThenReadCommand
{
    #[Lock('foo-lock-rw', null, null, false, true)]
    public function __invoke(OutputInterface $output): int
    {
        $output->write('executed');

        return Command::SUCCESS;
    }
}
