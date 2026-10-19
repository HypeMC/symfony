<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\ArgumentResolver\ValueResolver;

use Symfony\Component\Console\Attribute\Reflection\ReflectionMember;
use Symfony\Component\Console\Event\ConsoleCommandArgumentsEvent;
use Symfony\Component\Console\EventListener\LockAttributeListener;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Resolves the LockInterface and SharedLockInterface arguments of a command to the lock acquired by its #[Lock] attribute.
 *
 * The command can then refresh() or release() it.
 * A nullable argument gets null when no lock is acquired.
 *
 * The resolver must also be registered as an event subscriber: the lock can be acquired once the arguments are
 * resolved, so it first resolves a placeholder, which it replaces on the arguments event. Without the subscriber,
 * the command receives the placeholder.
 */
final class LockValueResolver implements ValueResolverInterface, EventSubscriberInterface
{
    /**
     * The arguments to replace with the lock once it is acquired.
     *
     * @var list<ReflectionMember>
     */
    private array $lockArguments = [];

    public function __construct(
        private readonly LockAttributeListener $lockAttributeListener,
    ) {
    }

    public function resolve(string $argumentName, InputInterface $input, ReflectionMember $member): iterable
    {
        $type = $member->getType();

        if (!$type instanceof \ReflectionNamedType || !\in_array($type->getName(), [LockInterface::class, SharedLockInterface::class], true)) {
            return [];
        }

        // the key of the attribute can read the arguments, in which case the lock is acquired once they are resolved
        $this->lockArguments[] = $member;

        return [$member];
    }

    public function onConsoleCommandArguments(ConsoleCommandArgumentsEvent $event): void
    {
        $lockArguments = $this->lockArguments;
        $this->lockArguments = [];

        // the command is not called when a listener gave its exit code, e.g. because another process holds its lock
        if (!$lockArguments || false !== $event->getExitCode()) {
            return;
        }

        $locks = $this->lockAttributeListener->getAcquiredLocks($event->getCommand(), $event->getInput());
        $arguments = $event->getArguments();

        foreach ($arguments as $i => $argument) {
            if (\in_array($argument, $lockArguments, true)) {
                $arguments[$i] = self::getLock($argument, $locks);
            }
        }

        $event->setArguments($arguments);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // runs after the listener that acquires the locks
            ConsoleCommandArgumentsEvent::class => ['onConsoleCommandArguments', -1024],
        ];
    }

    /**
     * @param list<SharedLockInterface> $locks
     */
    private static function getLock(ReflectionMember $member, array $locks): ?SharedLockInterface
    {
        if (1 < \count($locks)) {
            throw new LogicException(\sprintf('Cannot resolve the "$%s" argument of "%s": several locks are acquired by the command.', $member->getName(), $member->getSourceName()));
        }

        if (!$locks && !$member->isNullable()) {
            throw new LogicException(\sprintf('Cannot resolve the "$%s" argument of "%s": no lock is acquired by the command, because no "#[Lock]" attribute applies to it or because it is not run by "Application::run()" or "Application::doRun()". Make the argument nullable if this is expected.', $member->getName(), $member->getSourceName()));
        }

        return $locks[0] ?? null;
    }
}
