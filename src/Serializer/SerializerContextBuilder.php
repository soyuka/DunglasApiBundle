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

namespace ApiPlatform\Core\Serializer;

use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\Exception\ResourceClassNotFoundException;
use ApiPlatform\Core\Exception\RuntimeException;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\ResourceCollection\Factory\ResourceCollectionMetadataFactoryInterface;
use ApiPlatform\Core\Swagger\Serializer\DocumentationNormalizer;
use ApiPlatform\Core\Util\RequestAttributesExtractor;
use ApiPlatform\Metadata\Resource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\CsvEncoder;

/**
 * {@inheritdoc}
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class SerializerContextBuilder implements SerializerContextBuilderInterface
{
    private $resourceMetadataFactory;
    private $resourceCollectionMetadataFactory;

    public function __construct(ResourceMetadataFactoryInterface $resourceMetadataFactory, ResourceCollectionMetadataFactoryInterface $resourceCollectionMetadataFactory = null)
    {
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->resourceCollectionMetadataFactory = $resourceCollectionMetadataFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function createFromRequest(Request $request, bool $normalization, array $attributes = null): array
    {
        if (null === $attributes && !$attributes = RequestAttributesExtractor::extractAttributes($request)) {
            throw new RuntimeException('Request attributes are not valid.');
        }

        if ($this->resourceCollectionMetadataFactory && isset($attributes['operation_name'])) {
            try {
                $resourceCollection = $this->resourceCollectionMetadataFactory->create($attributes['resource_class']);
                $operation = $resourceCollection->getOperation($attributes['operation_name']);
                $context = $normalization ? $operation->normalizationContext : $operation->denormalizationContext;
                $context['uri_template'] = $operation->uriTemplate;
                $context['identifiers'] = $operation->identifiers;
                // TODO: remove in 3.0, operation type will not exist anymore
                $context['operation_type'] = ($attributes['identifiers'] ?? []) ? OperationType::ITEM : OperationType::COLLECTION;
                $context['operation_name'] = $attributes['operation_name'];
                $context['resource_class'] = $attributes['resource_class'];
                $context['skip_null_values'] = $context['skip_null_values'] ?? true;
                $context['iri_only'] = $context['iri_only'] ?? false;
                $context['input'] = $operation->input;
                $context['output'] = $operation->output;
                $context['request_uri'] = $request->getRequestUri();
                $context['uri'] = $request->getUri();

                if (!$normalization) {
                    if (!isset($context['api_allow_update'])) {
                        $context['api_allow_update'] = \in_array($method = $request->getMethod(), ['PUT', 'PATCH'], true);

                        if ($context['api_allow_update'] && 'PATCH' === $method) {
                            $context['deep_object_to_populate'] = $context['deep_object_to_populate'] ?? true;
                        }
                    }

                    if ('csv' === $request->getContentType()) {
                        $context[CsvEncoder::AS_COLLECTION_KEY] = false;
                    }
                }

                return $context;
            } catch (ResourceClassNotFoundException $e) {
                $resourceMetadata = $this->resourceMetadataFactory->create($attributes['resource_class']);
            }
        } else {
            $resourceMetadata = $this->resourceMetadataFactory->create($attributes['resource_class']);
        }

        $key = $normalization ? 'normalization_context' : 'denormalization_context';

        // TODO: remove in 3.0
        if (isset($attributes['operation_name'])) {
            $operationKey = 'operation_name';
            $operationType = ($attributes['identifiers'] ?? []) ? OperationType::ITEM : OperationType::COLLECTION;
        } elseif (isset($attributes['collection_operation_name'])) {
            $operationKey = 'collection_operation_name';
            $operationType = OperationType::COLLECTION;
        } elseif (isset($attributes['item_operation_name'])) {
            $operationKey = 'item_operation_name';
            $operationType = OperationType::ITEM;
        } else {
            $operationKey = 'subresource_operation_name';
            $operationType = OperationType::SUBRESOURCE;
        }

        $context = $resourceMetadata->getTypedOperationAttribute($operationType, $attributes[$operationKey], $key, [], true);
        $context['operation_type'] = $operationType;
        $context[$operationKey] = $attributes[$operationKey];

        if (!$normalization) {
            if (!isset($context['api_allow_update'])) {
                $context['api_allow_update'] = \in_array($method = $request->getMethod(), ['PUT', 'PATCH'], true);

                if ($context['api_allow_update'] && 'PATCH' === $method) {
                    $context['deep_object_to_populate'] = $context['deep_object_to_populate'] ?? true;
                }
            }

            if ('csv' === $request->getContentType()) {
                $context[CsvEncoder::AS_COLLECTION_KEY] = false;
            }
        }

        $context['resource_class'] = $attributes['resource_class'];
        $context['iri_only'] = $resourceMetadata->getAttribute('normalization_context')['iri_only'] ?? false;
        $context['input'] = $resourceMetadata->getTypedOperationAttribute($operationType, $attributes[$operationKey], 'input', null, true);
        $context['output'] = $resourceMetadata->getTypedOperationAttribute($operationType, $attributes[$operationKey], 'output', null, true);
        $context['request_uri'] = $request->getRequestUri();
        $context['uri'] = $request->getUri();

        if (isset($attributes['subresource_context'])) {
            $context['subresource_identifiers'] = [];

            foreach ($attributes['subresource_context']['identifiers'] as $parameterName => [$resourceClass]) {
                if (!isset($context['subresource_resources'][$resourceClass])) {
                    $context['subresource_resources'][$resourceClass] = [];
                }

                $context['subresource_identifiers'][$parameterName] = $context['subresource_resources'][$resourceClass][$parameterName] = $request->attributes->get($parameterName);
            }
        }

        if (isset($attributes['subresource_property'])) {
            $context['subresource_property'] = $attributes['subresource_property'];
            $context['subresource_resource_class'] = $attributes['subresource_resource_class'] ?? null;
        }

        unset($context[DocumentationNormalizer::SWAGGER_DEFINITION_NAME]);

        if (isset($context['skip_null_values'])) {
            return $context;
        }

        // TODO: We should always use `skip_null_values` but changing this would be a BC break, for now use it only when `merge-patch+json` is activated on a Resource
        foreach ($resourceMetadata->getItemOperations() as $operation) {
            if ('PATCH' === ($operation['method'] ?? '') && \in_array('application/merge-patch+json', $operation['input_formats']['json'] ?? [], true)) {
                $context['skip_null_values'] = true;

                break;
            }
        }

        return $context;
    }
}
