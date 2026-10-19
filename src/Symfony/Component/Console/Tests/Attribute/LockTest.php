<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Tests\Attribute;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\Lock;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class LockTest extends TestCase
{
    #[TestWith([0.0])]
    #[TestWith([-1.0])]
    public function testTheTtlMustBeGreaterThanZero(float $ttl)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be greater than 0 or null');

        new Lock(null, null, $ttl);
    }

    #[TestWith([-1])]
    #[TestWith([256])]
    public function testTheExitCodeMustBeBetween0And255(int $exitCode)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be between 0 and 255');

        new Lock(null, null, null, false, false, $exitCode);
    }
}
