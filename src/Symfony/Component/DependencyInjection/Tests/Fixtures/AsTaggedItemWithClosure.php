<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: static function (): string {
    return AsTaggedItemWithClosure::getKey();
}, priority: static function (): int {
    return 20;
})]
class AsTaggedItemWithClosure
{
    public static function getKey(): string
    {
        return 'closure_key';
    }
}
