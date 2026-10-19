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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Allows filtering of the arguments of an invokable command.
 *
 * You can call getArguments() to retrieve the arguments resolved from the input,
 * and setArguments() to replace the ones the command is called with. They must be
 * compatible with the signature of the command.
 *
 * With setExitCode() you can keep the command from being called at all, and give
 * the code it returns instead.
 *
 * Changing the input at this point will have no effect, the arguments are already resolved from it.
 */
final class ConsoleCommandArgumentsEvent extends ConsoleEvent
{
    /**
     * @var array<string, mixed>
     */
    private array $namedArguments;

    private int|false $exitCode = false;

    /**
     * @param list<mixed> $arguments
     */
    public function __construct(
        Command $command,
        InputInterface $input,
        OutputInterface $output,
        private array $arguments,
        private readonly \ReflectionFunctionAbstract $reflector,
    ) {
        parent::__construct($command, $input, $output);
    }

    /**
     * @return list<mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
        unset($this->namedArguments);
    }

    /**
     * Returns the arguments keyed by the name of the parameter they are passed to.
     *
     * @return array<string, mixed>
     */
    public function getNamedArguments(): array
    {
        if (isset($this->namedArguments)) {
            return $this->namedArguments;
        }

        $namedArguments = [];

        foreach ($this->reflector->getParameters() as $i => $param) {
            if ($param->isVariadic()) {
                $namedArguments[$param->name] = \array_slice($this->arguments, $i);
                break;
            }
            if (\array_key_exists($i, $this->arguments)) {
                $namedArguments[$param->name] = $this->arguments[$i];
            } elseif ($param->isDefaultValueAvailable()) {
                $namedArguments[$param->name] = $param->getDefaultValue();
            }
        }

        return $this->namedArguments = $namedArguments;
    }

    /**
     * Keeps the command from being called, and gives the code it returns instead.
     */
    public function setExitCode(int $exitCode): void
    {
        if ($exitCode < 0 || $exitCode > 255) {
            throw new \InvalidArgumentException('Exit code must be between 0 and 255.');
        }

        $this->exitCode = $exitCode;
    }

    /**
     * Returns the code a listener gave for the command, or false when it is called as usual.
     */
    public function getExitCode(): int|false
    {
        return $this->exitCode;
    }

    /**
     * Evaluates a Closure or an Expression against the arguments of the command.
     *
     * The Closure is called with the named arguments, the input and the command.
     * The Expression is evaluated with the "args", "input" and "command" variables.
     */
    public function evaluate(mixed $value, ?ExpressionLanguage $expressionLanguage = null): mixed
    {
        if ($value instanceof \Closure) {
            return $value($this->getNamedArguments(), $this->getInput(), $this->getCommand());
        }

        if (!$value instanceof Expression) {
            return $value;
        }

        if (!$expressionLanguage) {
            throw new \LogicException('Cannot evaluate an Expression for a command since no ExpressionLanguage was provided.');
        }

        return $expressionLanguage->evaluate($value, [
            'args' => $this->getNamedArguments(),
            'input' => $this->getInput(),
            'command' => $this->getCommand(),
        ]);
    }
}
