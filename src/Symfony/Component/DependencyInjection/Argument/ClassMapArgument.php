<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Argument;

final class ClassMapArgument implements ArgumentInterface
{
    private array $values = [];
    public readonly string $namespace;

    /**
     * @param class-string|null $instanceOf
     * @param class-string|null $withAttribute
     */
    public function __construct(
        string $namespace,
        public readonly string $path,
        public readonly ?string $instanceOf = null,
        public readonly ?string $withAttribute = null,
        public readonly ?string $indexBy = null,
    ) {
        $this->namespace = ltrim($namespace, '\\').('\\' !== $namespace[-1] ? '\\' : '');
    }

    public function getValues(): array
    {
        return $this->values;
    }

    public function setValues(array $values): void
    {
        $this->values = $values;
    }

    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'path' => $this->path,
            'instance_of' => $this->instanceOf,
            'with_attribute' => $this->withAttribute,
            'index_by' => $this->indexBy,
        ];
    }
}
