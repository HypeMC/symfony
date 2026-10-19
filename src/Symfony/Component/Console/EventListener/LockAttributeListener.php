<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\EventListener;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\Lock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Command\TraceableCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandArgumentsEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Parser;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * Handles the Lock attribute on commands.
 *
 * A lock is acquired as soon as the input is bound, after the listeners that may skip the command, or once
 * the arguments of the command are resolved when the key reads them: a Closure, or an Expression using `args`.
 * The locks are released once the command has terminated, whatever its exit code.
 * When another process holds one of its locks, the code of the command is not called and the attribute's exit code is used instead.
 */
final class LockAttributeListener implements EventSubscriberInterface
{
    /**
     * The locks of each command being run with an attribute, the last frame being the innermost one.
     *
     * Each frame belongs to the run that opened it, a command and the input it was given, so that a nested
     * command neither releases nor extends the locks of the command running it. Neither is enough alone: a
     * command can run itself with another input, or hand its own input to another command.
     *
     * @var list<array{command: Command, input: InputInterface, locks: array<string, SharedLockInterface>}>
     */
    private array $locks = [];

    /** @var \WeakMap<SharedLockInterface, true> */
    private \WeakMap $readLocks;

    private ?LockFactory $localFactory = null;

    /**
     * @param ContainerInterface|null $lockFactories      The lock factories, keyed by the name of their resource
     * @param ExpressionLanguage|null $expressionLanguage The one that evaluates the Expression keys, built on demand when none is given
     */
    public function __construct(
        private readonly ?ContainerInterface $lockFactories = null,
        private ?ExpressionLanguage $expressionLanguage = null,
    ) {
        $this->readLocks = new \WeakMap();
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        // another listener skipped the command or gave its exit code: there is nothing to lock
        if (!$event->commandShouldRun() || false !== $event->getExitCode() || !$command = $event->getCommand()) {
            return;
        }

        if (!$attributes = self::getAttributes($command)) {
            return;
        }

        $this->locks[] = ['command' => self::unwrap($command), 'input' => $event->getInput(), 'locks' => []];

        foreach ($attributes as $attribute) {
            $key = $attribute->key ?? $command->getName() ?? throw new LogicException(\sprintf('Lock key missing: the command has no name, provide a key to the "%s" attribute.', Lock::class));

            if ($this->readsArguments($key)) {
                // only an invokable command resolves arguments, and so dispatches the event that locks on them
                if (null === self::unwrap($command)->getCode()) {
                    throw new LogicException(\sprintf('The key of the "%s" attribute of the "%s" command reads the arguments of the command, which only an invokable command resolves. Give it a key that reads the input instead.', Lock::class, $command->getName()));
                }

                continue;
            }

            if ($key instanceof Expression) {
                $key = $this->getExpressionLanguage()->evaluate($key, ['input' => $event->getInput(), 'command' => self::unwrap($command)]);
            }

            if (!$this->acquire($event, $attribute, $key)) {
                return;
            }
        }
    }

    public function onConsoleCommandArguments(ConsoleCommandArgumentsEvent $event): void
    {
        // a command run without "console.command", by a CommandTester or by another command calling its run()
        // method, has no frame of its own: nothing would release what is locked here
        if (false !== $event->getExitCode() || !($command = $event->getCommand()) || !$this->isRunning($command, $event->getInput())) {
            return;
        }

        foreach (self::getAttributes($command) as $attribute) {
            // a null key is the name of the command, which the "console.command" event already locked
            if (null === $key = $attribute->key) {
                continue;
            }

            if (!$this->readsArguments($key)) {
                continue;
            }

            // an Expression key had readsArguments() build the expression language it needs
            if (!$this->acquire($event, $attribute, $event->evaluate($key, $this->expressionLanguage))) {
                return;
            }
        }
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        // the command may have been skipped before it opened a frame, which then belongs to the command running it
        if (!$this->isRunning($event->getCommand(), $event->getInput())) {
            return;
        }

        foreach (array_pop($this->locks)['locks'] as $lock) {
            try {
                $lock->release();
            } catch (LockReleasingException) {
                // the lock logged it, and the stale key goes away with its TTL
            }
        }
    }

    /**
     * Returns the locks the "#[Lock]" attributes acquired for this run of the command.
     *
     * @return list<SharedLockInterface>
     *
     * @internal
     */
    public function getAcquiredLocks(Command $command, InputInterface $input): array
    {
        return $this->isRunning($command, $input) ? array_values($this->locks[array_key_last($this->locks)]['locks']) : [];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // runs last, so that a command another listener skips is not locked
            ConsoleEvents::COMMAND => ['onConsoleCommand', -1024],
            ConsoleCommandArgumentsEvent::class => 'onConsoleCommandArguments',
            // runs last, so that the other listeners still run while the locks are held
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -1024],
        ];
    }

    /**
     * @return list<Lock>
     */
    private static function getAttributes(Command $command): array
    {
        $command = self::unwrap($command);

        if (null === $code = $command->getCode()) {
            $attributes = new \ReflectionClass($command)->getAttributes(Lock::class);
        } else {
            $function = new \ReflectionFunction($code(...));
            $attributes = [...($function->getClosureScopeClass()?->getAttributes(Lock::class) ?? []), ...$function->getAttributes(Lock::class)];
        }

        return array_map(static fn (\ReflectionAttribute $attribute): Lock => $attribute->newInstance(), $attributes);
    }

    /**
     * Tells whether the innermost frame belongs to this run of the command.
     */
    private function isRunning(?Command $command, InputInterface $input): bool
    {
        if (!$command || !$this->locks) {
            return false;
        }

        $frame = $this->locks[array_key_last($this->locks)];

        return self::unwrap($command) === $frame['command'] && $input === $frame['input'];
    }

    private static function unwrap(Command $command): Command
    {
        while ($command instanceof LazyCommand || $command instanceof TraceableCommand) {
            $command = $command instanceof LazyCommand ? $command->getCommand() : $command->command;
        }

        return $command;
    }

    /**
     * Tells whether the key can only be evaluated once the arguments of the command are resolved.
     */
    private function readsArguments(string|Expression|\Closure $key): bool
    {
        if (!$key instanceof Expression) {
            return $key instanceof \Closure;
        }

        $expressionLanguage = $this->getExpressionLanguage();
        $expressionLanguage->lint($key, ['args', 'input', 'command'], Parser::IGNORE_UNKNOWN_FUNCTIONS);

        try {
            $expressionLanguage->lint($key, ['input', 'command'], Parser::IGNORE_UNKNOWN_FUNCTIONS);
        } catch (SyntaxError) {
            return true;
        }

        return false;
    }

    private function getExpressionLanguage(): ExpressionLanguage
    {
        if (!class_exists(ExpressionLanguage::class)) {
            throw new LogicException('To use an Expression as the key of a lock you must install the symfony/expression-language component. Try running "composer require symfony/expression-language".');
        }

        return $this->expressionLanguage ??= new ExpressionLanguage();
    }

    private function acquire(ConsoleCommandEvent|ConsoleCommandArgumentsEvent $event, Lock $attribute, mixed $key): bool
    {
        if (!\is_string($key) && !\is_int($key) && !$key instanceof \Stringable) {
            throw new \TypeError(\sprintf('The value of the "$key" option of the "%s" attribute must evaluate to a string, an integer or a "Stringable" object, "%s" given.', Lock::class, get_debug_type($key)));
        }

        $key = (string) $key;
        $factory = $this->getFactory($attribute->factory);
        $id = spl_object_id($factory)."\0".$key;
        $frame = array_key_last($this->locks);

        // the same lock is already held, e.g. when the attribute is set on both the class and its __invoke() method
        if ($lock = $this->locks[$frame]['locks'][$id] ?? null) {
            if ($attribute->read || !isset($this->readLocks[$lock])) {
                return true;
            }

            // a write lock is needed while only a read lock is held: promote it without waiting,
            // as two processes promoting their read locks would wait for each other
            if (!self::take($lock, false, false)) {
                $this->skip($event, $attribute, $key);

                return false;
            }

            unset($this->readLocks[$lock]);

            return true;
        }

        $lock = $factory->createLock($key, $attribute->ttl);

        if (!self::take($lock, $attribute->read, $attribute->blocking)) {
            $this->skip($event, $attribute, $key);

            return false;
        }

        if ($attribute->read) {
            $this->readLocks[$lock] = true;
        }

        $this->locks[$frame]['locks'][$id] = $lock;

        return true;
    }

    private static function take(SharedLockInterface $lock, bool $read, bool $blocking): bool
    {
        try {
            return $read ? $lock->acquireRead($blocking) : $lock->acquire($blocking);
        } catch (LockConflictedException) {
            return false;
        }
    }

    private function skip(ConsoleCommandEvent|ConsoleCommandArgumentsEvent $event, Lock $attribute, string $key): void
    {
        $event->setExitCode($attribute->exitCode);

        $output = $event->getOutput();
        $output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $output->writeln(\sprintf('<comment>The command is already running in another process, the lock "%s" is held.</comment>', $key), OutputInterface::VERBOSITY_NORMAL);
    }

    private function getFactory(?string $name): LockFactory
    {
        if (!class_exists(SemaphoreStore::class)) {
            throw new LogicException('To enable the locking feature you must install the symfony/lock component. Try running "composer require symfony/lock".');
        }

        if (null !== $name) {
            if (!$this->lockFactories?->has($name)) {
                throw new InvalidArgumentException(\sprintf('Lock factory "%s" does not exist. Did you forget to configure it?', $name));
            }

            return $this->lockFactories->get($name);
        }

        // the resource LockableTrait uses for commands, else the one of the application
        foreach (['console', 'default'] as $name) {
            if ($this->lockFactories?->has($name)) {
                return $this->lockFactories->get($name);
            }
        }

        return $this->localFactory ??= new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore());
    }
}
