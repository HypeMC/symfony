<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Tests\Fixtures;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Lock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * A closure in an attribute requires PHP >= 8.5, so this command lives in a file of its own.
 */
#[AsCommand('foo:lock:closure')]
#[Lock(static function (array $args): string { return 'foo-lock-'.$args['tenant']; })]
class LockClosureKeyCommand
{
    public function __invoke(#[Argument] string $tenant, SharedLockInterface $lock, OutputInterface $output): int
    {
        $output->write('executed '.$lock->isAcquired());

        return Command::SUCCESS;
    }
}
