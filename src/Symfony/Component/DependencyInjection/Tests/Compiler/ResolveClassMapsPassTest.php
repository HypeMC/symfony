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
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Valid\Bar;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Valid\Baz;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Valid\Corge;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Valid\Foo;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Valid\Qux;

class ResolveClassMapsPassTest extends TestCase
{
    private static ?string $projectDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$projectDir = \dirname(__DIR__).'/Fixtures/ResolveClassMapsPass';
    }

    public static function tearDownAfterClass(): void
    {
        self::$projectDir = null;
    }

    /**
     * @dataProvider provideClassMapArgumentCases
     */
    public function testClassMapIsResolved(?string $instanceOf, ?string $withAttribute, ?string $indexBy, array $expected)
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', self::$projectDir);
        $container->register('foo')->addArgument($arg = new ClassMapArgument(
            'Symfony\Component\DependencyInjection\Tests\Fixtures\ResolveClassMapsPass\Valid',
            '%kernel.project_dir%/Valid',
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
    }
}
