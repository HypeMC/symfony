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

use Symfony\Component\DependencyInjection\Argument\ClassMapArgument;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ClassMapConsumer
{
    public function __construct(
        #[Autowire(new ClassMapArgument(
            'Symfony\Component\DependencyInjection\Tests\Fixtures\ClassMap\Valid',
            '%fixtures_dir%/ClassMap/Valid',
        ))]
        public array $classMap,
    ) {
    }
}
