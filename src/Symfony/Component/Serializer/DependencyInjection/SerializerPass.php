<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Serializer\Debug\TraceableEncoder;
use Symfony\Component\Serializer\Debug\TraceableNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Adds all services with the tags "serializer.encoder" and "serializer.normalizer" as
 * encoders and normalizers to the "serializer" service.
 *
 * @author Javier Lopez <f12loalf@gmail.com>
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class SerializerPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private const NAME_CONVERTER_METADATA_AWARE_ID = 'serializer.name_converter.metadata_aware';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('serializer')) {
            return;
        }

        if (!$normalizers = $this->findAndSortTaggedServices('serializer.normalizer', $container)) {
            throw new RuntimeException('You must tag at least one service as "serializer.normalizer" to use the "serializer" service.');
        }

        if (!$encoders = $this->findAndSortTaggedServices('serializer.encoder', $container)) {
            throw new RuntimeException('You must tag at least one service as "serializer.encoder" to use the "serializer" service.');
        }

        $groupedNormalizers = $this->groupByScope($container, $normalizers, 'serializer.normalizer');
        $groupedEncoders = $this->groupByScope($container, $encoders, 'serializer.encoder');

        $defaultContext = [];
        if ($container->hasParameter('serializer.default_context')) {
            $defaultContext = $container->getParameter('serializer.default_context');
            $this->bindDefaultContext($container, $groupedNormalizers['default'], $groupedEncoders['default'], $defaultContext);
            $container->getParameterBag()->remove('serializer.default_context');
        }

        $this->configureSerializer($container, 'serializer', $groupedNormalizers['default'], $groupedEncoders['default'], 'default');
        $this->configureScopedSerializers($container, $groupedNormalizers, $groupedEncoders, $defaultContext);
    }

    private function groupByScope(ContainerBuilder $container, array $services, string $tagName): array
    {
        $grouped = [];

        foreach ($services as $id) {
            $definition = $container->getDefinition($id);

            $scopes = [];
            foreach ($definition->getTag($tagName) as $tag) {
                if (isset($tag['scope'])) {
                    $scopes = array_merge($scopes, (array) $tag['scope']);
                }
            }

            if (!$scopes) {
                $scopes[] = 'default';
            }

            foreach ($scopes as $scope) {
                $grouped[$scope] ??= [];
                $grouped[$scope][] = $id;
            }
        }

        return $grouped;
    }

    private function bindDefaultContext(ContainerBuilder $container, array $normalizers, array $encoders, array $defaultContext): void
    {
        foreach (array_merge($normalizers, $encoders) as $service) {
            $definition = $container->getDefinition($service);
            $definition->setBindings(['array $defaultContext' => new BoundArgument($defaultContext, false)] + $definition->getBindings());
        }
    }

    private function configureSerializer(ContainerBuilder $container, string $id, array $normalizers, array $encoders, string $scope): void
    {
        if (!$normalizers) {
            throw new RuntimeException(sprintf('You must set the scope of at least one service tagged as "serializer.normalizer" to "%s" to use the "%s" service.', $scope, $id));
        }

        if (!$encoders) {
            throw new RuntimeException(sprintf('You must set the scope of at least one service tagged as "serializer.encoder" to "%s" to use the "%s" service.', $scope, $id));
        }

        if ($container->getParameter('kernel.debug') && $container->hasDefinition('serializer.data_collector')) {
            foreach ($normalizers as $i => $normalizer) {
                $normalizers[$i] = $container->register('.debug.serializer.normalizer.'.$normalizer, TraceableNormalizer::class)
                    ->setArguments([$normalizer, new Reference('serializer.data_collector'), $scope]);
            }

            foreach ($encoders as $i => $encoder) {
                $encoders[$i] = $container->register('.debug.serializer.encoder.'.$encoder, TraceableEncoder::class)
                    ->setArguments([$encoder, new Reference('serializer.data_collector'), $scope]);
            }
        }

        $serializerDefinition = $container->getDefinition($id);
        $serializerDefinition->replaceArgument(0, $normalizers);
        $serializerDefinition->replaceArgument(1, $encoders);
    }

    private function configureScopedSerializers(ContainerBuilder $container, array $groupedNormalizers, array $groupedEncoders, array $defaultScopeDefaultContext): void
    {
        if (!$container->hasParameter('.serializer.scoped_serializers')) {
            return;
        }

        $defaultScopeNameConverter = $container->hasParameter('.serializer.name_converter')
            ? $container->getParameter('.serializer.name_converter')
            : null;

        foreach ($container->getParameter('.serializer.scoped_serializers') as $scope => $config) {
            if ('default' === $scope) {
                continue;
            }

            $normalizers = $groupedNormalizers[$scope] ?? [];
            if (isset($config['include_default_normalizers'])) {
                // TODO ovo sjebe priority + ako je taggiran sa default, svi ga dobe???
                $normalizers = array_unique(array_merge($normalizers, $this->resolveInheritance($config['include_default_normalizers'], $groupedNormalizers['default'])));
            }
            $encoders = array_unique(array_merge($groupedEncoders[$scope] ?? [], $groupedEncoders['default']));

            $nameConverter = $defaultScopeNameConverter !== ($config['name_converter'] ?? null)
                ? $this->buildChildNameConverterDefinition($container, $config['name_converter'])
                : self::NAME_CONVERTER_METADATA_AWARE_ID;

            if ($defaultScopeDefaultContext !== $config['default_context']) {
                $normalizers = $this->buildChildDefinitions($container, $config, $normalizers, $nameConverter);
                $encoders = $this->buildChildDefinitions($container, $config, $encoders);
            } elseif (self::NAME_CONVERTER_METADATA_AWARE_ID !== $nameConverter) {
                $normalizersWithNameConverter = [];
                foreach ($normalizers as $i => $id) {
                    if (null !== $this->findNameConverterIndex($container, $id)) {
                        $normalizersWithNameConverter[$i] = $id;
                    }
                }
                $normalizers = array_replace(
                    $normalizers,
                    $this->buildChildDefinitions($container, $config, $normalizersWithNameConverter, $nameConverter),
                );
            }

            $this->bindDefaultContext($container, $normalizers, $encoders, $config['default_context']);

            $container->setDefinition($id = 'serializer.'.$scope, new ChildDefinition('serializer'));
            $container->registerAliasForArgument($id, SerializerInterface::class, $scope.'.serializer');

            $this->configureSerializer($container, $id, $normalizers, $encoders, $scope);

            if ($container->getParameter('kernel.debug') && $container->hasDefinition('debug.serializer')) {
                $container->setDefinition($debugId = 'debug.'.$id, new ChildDefinition('debug.serializer'))
                    ->setDecoratedService($id)
                    ->replaceArgument(0, new Reference($debugId.'.inner'))
                    ->replaceArgument(2, $scope)
                ;
            }
        }
    }

    private function resolveInheritance(array $includeDefaultNormalizers, array $defaultScopeNormalizers): array
    {
        if ('all' === $includeDefaultNormalizers['type']) {
            return $defaultScopeNormalizers;
        }

        if ('inclusive' === $includeDefaultNormalizers['type']) {
            $normalizers = $includeDefaultNormalizers['normalizers'];

            if ($normalizers !== array_intersect($normalizers, $defaultScopeNormalizers)) {
                throw new RuntimeException(sprintf('TODO %s', implode(', ', array_diff($normalizers, $defaultScopeNormalizers))));
            }
        } else {
            $normalizers = array_diff($defaultScopeNormalizers, $includeDefaultNormalizers['normalizers']);
        }

        return $normalizers;
    }

    private function buildChildNameConverterDefinition(ContainerBuilder $container, ?string $nameConverter): ?string
    {
        $childId = self::NAME_CONVERTER_METADATA_AWARE_ID.'.'.ContainerBuilder::hash($nameConverter);

        if (!$container->hasDefinition($childId)) {
            $childDefinition = $container->setDefinition($childId, new ChildDefinition(self::NAME_CONVERTER_METADATA_AWARE_ID.'.abstract'));
            if (null !== $nameConverter) {
                $childDefinition->addArgument(new Reference($nameConverter));
            }
        }

        return $childId;
    }

    private function buildChildDefinitions(ContainerBuilder $container, ?array $config, array $services, ?string $nameConverter = null): array
    {
        foreach ($services as &$id) {
            $childId = $id.'.'.ContainerBuilder::hash($config); // todo usporedit hasheve samo??

            if (!$container->hasDefinition($childId)) {
                $definition = $container->setDefinition($childId, new ChildDefinition($id));

                if (null !== $nameConverter && null !== $nameConverterIndex = $this->findNameConverterIndex($container, $id)) {
                    $definition->replaceArgument($nameConverterIndex, new Reference($nameConverter));
                }
            }

            $id = new Reference($childId);
        }

        return $services;
    }

    private function findNameConverterIndex(ContainerBuilder $container, string $id): int|string|null
    {
        foreach ($container->getDefinition($id)->getArguments() as $index => $argument) {
            if ($argument instanceof Reference && self::NAME_CONVERTER_METADATA_AWARE_ID === (string) $argument) {
                return $index;
            }
        }

        return null;
    }
}
