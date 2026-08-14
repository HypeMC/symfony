<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

class AsTaggedItemTest extends TestCase
{
    public function testStringIndexAndIntPriority()
    {
        $attribute = new AsTaggedItem('foo', 10);

        $this->assertSame('foo', $attribute->index);
        $this->assertSame(10, $attribute->priority);
    }

    public function testClosureIndexAndPriority()
    {
        $attribute = new AsTaggedItem(static fn (): string => 'foo', static fn (): int => 10);

        $this->assertSame('foo', $attribute->index);
        $this->assertSame(10, $attribute->priority);
    }

    public function testCallableIndexAndPriority()
    {
        $attribute = new AsTaggedItem([self::class, 'index'], [self::class, 'priority']);

        $this->assertSame('foo', $attribute->index);
        $this->assertSame(10, $attribute->priority);
    }

    public function testInvalidIndexCallable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The value passed to the "$index" argument of attribute "%s" must be a callable, "array" given.', AsTaggedItem::class));

        new AsTaggedItem([self::class, 'unknownMethod']);
    }

    public function testInvalidIndexReturnValue()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The callable passed to the "$index" argument of attribute "%s" must return a string, "int" returned.', AsTaggedItem::class));

        new AsTaggedItem(static fn (): int => 10);
    }

    public function testInvalidPriorityCallable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The value passed to the "$priority" argument of attribute "%s" must be a callable, "array" given.', AsTaggedItem::class));

        new AsTaggedItem(null, [self::class, 'unknownMethod']);
    }

    public function testInvalidPriorityReturnValue()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The callable passed to the "$priority" argument of attribute "%s" must return an int, "string" returned.', AsTaggedItem::class));

        new AsTaggedItem(null, static fn (): string => 'foo');
    }

    public static function index(): string
    {
        return 'foo';
    }

    public static function priority(): int
    {
        return 10;
    }
}
