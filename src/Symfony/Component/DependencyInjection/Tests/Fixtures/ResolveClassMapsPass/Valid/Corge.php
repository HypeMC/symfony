<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Valid;

class Corge implements FooInterface
{
    public const key = 'corge-const';
    protected static $key = 'corge-prop';

    private static function key()
    {
        return 'corge-method';
    }
}
