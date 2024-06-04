<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ClassMapArgument;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassMapsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Bar;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Baz;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Corge;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Foo;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\FooInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Qux;

class ResolveClassMapsPassTest extends TestCase
{
    private static ?string $fixturesPath = null;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesPath = \dirname(__DIR__).'/Fixtures';
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixturesPath = null;
    }

    /**
     * @dataProvider provideClassMapArgumentCases
     */
    public function testClassMapIsResolved(?string $instanceOf, ?string $withAttribute, ?string $indexBy, array $expected)
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', self::$fixturesPath);
        $container->register('foo')->addArgument($arg = new ClassMapArgument(
            'Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass',
            '%kernel.project_dir%/ResolveClassMapsPass',
            $instanceOf,
            $withAttribute,
            $indexBy,
        ));

        (new ResolveClassMapsPass())->process($container);

        self::assertEquals($expected, $arg->getValues());
    }

    public function provideClassMapArgumentCases(): iterable
    {
        yield [null, null, null, [
            0 => Bar::class,
            1 => Baz::class,
            2 => Corge::class,
            'foo-attribute' => Foo::class,
            3 => Qux::class,
        ]];

        yield [null, null, 'key', [
            'foo-attribute' => Foo::class,
            'bar-method' => Bar::class,
            'baz-prop' => Baz::class,
            'qux-const' => Qux::class,
            'corge-const' => Corge::class,
        ]];

        yield [FooInterface::class, null, null, [
            0 => Baz::class,
            1 => Corge::class,
            'foo-attribute' => Foo::class,
        ]];

        yield [null, AsFoo::class, 'key', [
            'bar-method' => Bar::class,
            'baz-prop' => Baz::class,
            'qux-const' => Qux::class,
        ]];

        yield [FooInterface::class, AsFoo::class, null, [
            0 => Baz::class,
        ]];
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class AsFoo
{
}
