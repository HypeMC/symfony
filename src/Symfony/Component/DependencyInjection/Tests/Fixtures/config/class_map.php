<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return new class() {
    public function __invoke(ContainerConfigurator $c)
    {
        $c->services()
            ->set('class_map_argument', 'stdClass')
                ->public()
                ->args([class_map(
                    'Symfony\Component\DependencyInjection\Tests\Fixtures\ClassMap\Valid',
                    __DIR__.'/../ClassMap/Valid'),
                ])
        ;
    }
};
