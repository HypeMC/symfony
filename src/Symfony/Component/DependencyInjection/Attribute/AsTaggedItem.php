<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Attribute;

use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * An attribute to tell under which index and priority a service class should be found in tagged iterators/locators.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class AsTaggedItem
{
    public ?string $index;
    public ?int $priority;

    /**
     * @param string|array|\Closure|null $index    The index at which the service will be found when consuming tagged iterators/locators.
     *                                             To compute it, pass a closure returning a string (requires PHP 8.5) or a
     *                                             [class-string, method] callable to a public static method (works on PHP 8.4)
     * @param int|array|\Closure|null    $priority The priority of the service in iterators/locators; the higher the number, the earlier it will
     *                                             be found. To compute it, pass a closure returning an int (requires PHP 8.5) or a
     *                                             [class-string, method] callable to a public static method (works on PHP 8.4)
     */
    public function __construct(string|array|\Closure|null $index = null, int|array|\Closure|null $priority = null)
    {
        if (null !== $index && !\is_string($index)) {
            if (!\is_callable($index)) {
                throw new InvalidArgumentException(\sprintf('The value passed to the "$index" argument of attribute "%s" must be a callable, "%s" given.', self::class, get_debug_type($index)));
            }
            if (!\is_string($index = $index())) {
                throw new InvalidArgumentException(\sprintf('The callable passed to the "$index" argument of attribute "%s" must return a string, "%s" returned.', self::class, get_debug_type($index)));
            }
        }

        if (null !== $priority && !\is_int($priority)) {
            if (!\is_callable($priority)) {
                throw new InvalidArgumentException(\sprintf('The value passed to the "$priority" argument of attribute "%s" must be a callable, "%s" given.', self::class, get_debug_type($priority)));
            }
            if (!\is_int($priority = $priority())) {
                throw new InvalidArgumentException(\sprintf('The callable passed to the "$priority" argument of attribute "%s" must return an int, "%s" returned.', self::class, get_debug_type($priority)));
            }
        }

        $this->index = $index;
        $this->priority = $priority;
    }
}
