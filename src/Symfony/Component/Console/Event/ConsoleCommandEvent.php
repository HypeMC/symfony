<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Event;

/**
 * Allows to do things before the command is executed, like skipping the command or executing code before the command is
 * going to be executed.
 *
 * Changing the input arguments will have no effect.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class ConsoleCommandEvent extends ConsoleEvent
{
    /**
     * The return code for skipped commands, this will also be passed into the terminate event.
     */
    public const RETURN_CODE_DISABLED = 113;

    /**
     * Indicates if the command should be run or skipped.
     */
    private bool $commandShouldRun = true;

    /**
     * The code a listener gave for the command, false when it is run.
     */
    private int|false $exitCode = false;

    /**
     * Disables the command, so it won't be run.
     */
    public function disableCommand(): bool
    {
        return $this->commandShouldRun = false;
    }

    public function enableCommand(): bool
    {
        $this->exitCode = false;

        return $this->commandShouldRun = true;
    }

    /**
     * Returns true if the command is runnable, false otherwise.
     */
    public function commandShouldRun(): bool
    {
        return $this->commandShouldRun;
    }

    /**
     * Keeps the command from being run, and gives the code the application exits with instead.
     *
     * The terminate event can still change it. A command disabled with disableCommand() returns
     * "RETURN_CODE_DISABLED" whatever is set here.
     */
    public function setExitCode(int $exitCode): void
    {
        if ($exitCode < 0 || $exitCode > 255) {
            throw new \InvalidArgumentException('Exit code must be between 0 and 255.');
        }

        $this->exitCode = $exitCode;
    }

    /**
     * Returns the code a listener gave for the command, or false when none did.
     */
    public function getExitCode(): int|false
    {
        return $this->exitCode;
    }
}
