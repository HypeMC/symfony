<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Attribute;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * Prevents concurrent runs of a command.
 *
 * While another process holds the lock, the code of the command is not called and $exitCode is used instead, unless $blocking is enabled, in which case it waits for the lock to be released.
 * Read locks are shared between processes, and exclusive of write locks.
 *
 * The lock is acquired once the input is bound, before the command runs, or once its arguments are resolved when the key reads them: a Closure, or an Expression using `args`.
 * Only an invokable command resolves arguments, so such a key is rejected on any other command.
 * An Expression that only reads `input` is evaluated before the command interacts, so it sees the arguments and options given on the command line but not the answers to its questions: read an argument that a question can fill through `args`.
 * It is released after the command has terminated, whatever its exit code.
 *
 * @see https://symfony.com/doc/current/lock.html
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Lock
{
    /**
     * @param string|Expression|\Closure|null $key      The processes sharing the same key contend for the lock: a literal string, or an Expression or a Closure evaluating to a string, an integer or a \Stringable object. The Closure is called with the resolved arguments, the input and the command, the Expression is evaluated with the "args", "input" and "command" variables. Defaults to the name of the command
     * @param string|null                     $factory  The name of the lock factory to use, as configured under the "lock" resources. Defaults to the factory of the resource named "console", then to the one named "default", falling back to a local semaphore- or file-based one when neither exists
     * @param float|null                      $ttl      The maximum expected duration of the command in seconds, after which the lock expires. A command that outlives it has to inject the lock and refresh it itself. Null uses the default TTL of the store, 300 seconds for the stores that expire locks. Stores that don't expire locks ignore the TTL
     * @param bool                            $blocking Whether to wait for the lock to be released instead of skipping the command
     * @param bool                            $read     Whether to acquire a read lock, shared with the other read locks of the same key. Falls back to a write lock when the store does not support read locks
     * @param int                             $exitCode The code the command returns when another process holds the lock, which the terminate event can still change
     */
    public function __construct(
        public readonly string|Expression|\Closure|null $key = null,
        public readonly ?string $factory = null,
        public readonly ?float $ttl = null,
        public readonly bool $blocking = false,
        public readonly bool $read = false,
        public readonly int $exitCode = Command::SUCCESS,
    ) {
        if (null !== $ttl && $ttl <= 0) {
            throw new InvalidArgumentException(\sprintf('The "$ttl" argument of "%s" must be greater than 0 or null, "%s" given.', self::class, $ttl));
        }

        if (0 > $exitCode || 255 < $exitCode) {
            throw new InvalidArgumentException(\sprintf('The "$exitCode" argument of "%s" must be between 0 and 255, "%s" given.', self::class, $exitCode));
        }
    }
}
