<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Metadata\ResourceCollection\Factory;

use ApiPlatform\Core\Exception\ResourceClassNotFoundException;
use ApiPlatform\Core\Metadata\ResourceCollection\ResourceCollection;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Extractor\ExtractorInterface;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource;

/**
 * Creates a resource metadata from {@see Resource} extractors (XML, YAML).
 *
 * @author Vincent Chalamon <vincentchalamon@gmail.com>
 * @experimental
 */
final class ExtractorResourceCollectionMetadataFactory implements ResourceCollectionMetadataFactoryInterface
{
    private $extractor;
    private $decorated;
    private $defaults;

    public function __construct(ExtractorInterface $extractor, ResourceCollectionMetadataFactoryInterface $decorated = null, array $defaults = [])
    {
        $this->extractor = $extractor;
        $this->decorated = $decorated;
        $this->defaults = $defaults;
    }

    /**
     * {@inheritdoc}
     */
    public function create(string $resourceClass): ResourceCollection
    {
        $resourceMetadataCollection = [];

        $parentResources = null;
        if ($this->decorated) {
            try {
                $parentResources = $this->decorated->create($resourceClass);
            } catch (ResourceClassNotFoundException) {
                // Ignore not found exception from decorated factories
            }
        }

        if (!(class_exists($resourceClass) || interface_exists($resourceClass)) || !$resources = $this->extractor->getResources()[$resourceClass] ?? false) {
            return $this->handleNotFound($parentResources, $resourceClass);
        }

        foreach ($this->buildResourceOperations($resources, $resourceClass) as $i => $resource) {
            foreach ($this->defaults as $key => $value) {
                if (!$resource->{$key}) {
                    $resource->{$key} = $value;
                }
            }

            $resourceMetadataCollection[$i] = $resource;
        }

        if (!$resourceMetadataCollection) {
            throw new ResourceClassNotFoundException(sprintf('Resource "%s" not found.', $resourceClass));
        }

        return new ResourceCollection($resourceMetadataCollection);
    }

    /**
     * Builds resource operations to support:.
     *
     * Resource
     * Get
     * Post
     * Resource
     * Put
     * Get
     *
     * @return Resource[]
     */
    private function buildResourceOperations(array $nodes, string $resourceClass): array
    {
        $shortName = (false !== $pos = strrpos($resourceClass, '\\')) ? substr($resourceClass, $pos + 1) : $resourceClass;
        $resources = [];
        foreach ($nodes as $node) {
            $resource = new Resource(
                $node['uriTemplate'] ?? null,
                $shortName,
                $node['description'] ?? null,
                $resourceClass,
                $node['types'] ?? null,
                null,
                $node['cacheHeaders'] ?? null,
                $node['denormalizationContext'] ?? null,
                $node['deprecationReason'] ?? null,
                $node['elasticsearch'] ?? null,
                $node['fetchPartial'] ?? null,
                $node['forceEager'] ?? null,
                $node['formats'] ?? null,
                $node['inputFormats'] ?? null,
                $node['outputFormats'] ?? null,
                $node['filters'] ?? null,
                $node['hydraContext'] ?? null,
                $node['input'] ?? null,
                $node['mercure'] ?? null,
                $node['messenger'] ?? null,
                $node['normalizationContext'] ?? null,
                $node['openapiContext'] ?? null,
                $node['order'] ?? null,
                $node['output'] ?? null,
                $node['paginationClientEnabled'] ?? null,
                $node['paginationClientItemsPerPage'] ?? null,
                $node['paginationClientPartial'] ?? null,
                $node['paginationViaCursor'] ?? null,
                $node['paginationEnabled'] ?? null,
                $node['paginationFetchJoinCollection'] ?? null,
                $node['paginationItemsPerPage'] ?? null,
                $node['paginationMaximumItemsPerPage'] ?? null,
                $node['paginationPartial'] ?? null,
                $node['routePrefix'] ?? null,
                $node['security'] ?? null,
                $node['securityMessage'] ?? null,
                $node['securityPostDenormalize'] ?? null,
                $node['securityPostDenormalizeMessage'] ?? null,
                $node['stateless'] ?? null,
                $node['sunset'] ?? null,
                $node['swaggerContext'] ?? null,
                $node['validationGroups'] ?? null,
                $node['urlGenerationStrategy'] ?? null,
                $node['compositeIdentifier'] ?? null,
                $node['identifiers'] ?? null,
                $node['graphQl'] ?? null
            );
            $resource->operations = $this->parseOperations($node['operations'] ?? null, $resource);

            $resources[] = $resource;
        }

        return $resources;
    }

    private function parseOperations(?array $data, Resource $resource): ?array
    {
        $operations = [];

        if (null === $data) {
            foreach ([new Get(), new GetCollection(), new Post(), new Put(), new Patch(), new Delete()] as $operation) {
                [$key, $operation] = $this->getOperationWithDefaults($resource, $operation);
                $operations[$key] = $operation;
            }

            return $operations;
        }

        foreach ($data as $shortName => $attributes) {
            if (!\class_exists($attributes['class'])) {
                throw new \InvalidArgumentException(sprintf('Operation "%s" does not exist.', $attributes['class']));
            }

            /** @var Operation $operation */
            $operation = new $attributes['class']();
            if (is_string($shortName)) {
                $operation->shortName = $shortName;
            }
            foreach ($attributes as $name => $value) {
                $operation->$name = $value;
            }

            [$key, $operation] = $this->getOperationWithDefaults($resource, $operation);
            $operations[$key] = $operation;
        }

        return $operations;
    }

    private function getOperationWithDefaults(Resource $resource, Operation $operation): array
    {
        // @phpstan-ignore-next-line
        foreach ($resource as $property => $value) {
            if ('operations' === $property) {
                continue;
            }

            if ($operation->{$property} || !$value) {
                continue;
            }

            $operation->{$property} = $value;
        }

        $key = sprintf('_api_%s_%s%s', $operation->uriTemplate ?: $operation->shortName, strtolower($operation->method), $operation instanceof GetCollection ? '_collection' : '');

        return [$key, $operation];
    }

    /**
     * Returns the metadata from the decorated factory if available or throws an exception.
     *
     * @throws ResourceClassNotFoundException
     */
    private function handleNotFound(?ResourceCollection $parentResources, string $resourceClass): ResourceCollection
    {
        if (null !== $parentResources) {
            return $parentResources;
        }

        throw new ResourceClassNotFoundException(sprintf('Resource "%s" not found.', $resourceClass));
    }
}
