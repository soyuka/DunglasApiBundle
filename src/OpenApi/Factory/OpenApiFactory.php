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

namespace ApiPlatform\Core\OpenApi\Factory;

use ApiPlatform\Core\Api\FilterLocatorTrait;
use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\DataProvider\PaginationOptions;
use ApiPlatform\Core\JsonSchema\Schema;
use ApiPlatform\Core\JsonSchema\SchemaFactoryInterface;
use ApiPlatform\Core\JsonSchema\TypeFactoryInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\ResourceMetadata;
use ApiPlatform\Core\OpenApi\Model;
use ApiPlatform\Core\OpenApi\OpenApi;
use ApiPlatform\Core\OpenApi\Options;
use ApiPlatform\Core\Operation\Factory\SubresourceOperationFactoryInterface;
use ApiPlatform\Core\PathResolver\OperationPathResolverInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\PropertyInfo\Type;

/**
 * Generates an OpenAPI v3 specification.
 */
final class OpenApiFactory implements OpenApiFactoryInterface
{
    use FilterLocatorTrait;

    public const BASE_URL = 'base_url';

    private $resourceNameCollectionFactory;
    private $resourceMetadataFactory;
    private $propertyNameCollectionFactory;
    private $propertyMetadataFactory;
    private $operationPathResolver;
    private $subresourceOperationFactory;
    private $formats;
    private $jsonSchemaFactory;
    private $jsonSchemaTypeFactory;
    private $openApiOptions;
    private $paginationOptions;

    public function __construct(ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory, ResourceMetadataFactoryInterface $resourceMetadataFactory, PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory, PropertyMetadataFactoryInterface $propertyMetadataFactory, SchemaFactoryInterface $jsonSchemaFactory, TypeFactoryInterface $jsonSchemaTypeFactory, OperationPathResolverInterface $operationPathResolver, ContainerInterface $filterLocator, SubresourceOperationFactoryInterface $subresourceOperationFactory, array $formats = [], Options $openApiOptions, PaginationOptions $paginationOptions)
    {
        $this->resourceNameCollectionFactory = $resourceNameCollectionFactory;
        $this->jsonSchemaFactory = $jsonSchemaFactory;
        $this->jsonSchemaTypeFactory = $jsonSchemaTypeFactory;
        $this->formats = $formats;
        $this->setFilterLocator($filterLocator, true);
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->propertyNameCollectionFactory = $propertyNameCollectionFactory;
        $this->propertyMetadataFactory = $propertyMetadataFactory;
        $this->operationPathResolver = $operationPathResolver;
        $this->openApiOptions = $openApiOptions;
        $this->paginationOptions = $paginationOptions;
        $this->subresourceOperationFactory = $subresourceOperationFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $context = []): OpenApi
    {
        $baseUrl = $context[self::BASE_URL] ?? '/';
        $info = new Model\Info($this->openApiOptions->getTitle(), $this->openApiOptions->getVersion(), trim($this->openApiOptions->getDescription()));
        $servers = '/' !== $baseUrl && '' !== $baseUrl ? [new Model\Server($baseUrl)] : [];
        $paths = new Model\Paths();
        $links = [];
        $schemas = [];

        foreach ($this->resourceNameCollectionFactory->create() as $resourceClass) {
            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);
            $resourceShortName = $resourceMetadata->getShortName();

            // Items needs to be parsed first to be able to reference the lines from the collection operation
            list($itemOperationLinks, $itemOperationSchemas) = $this->collectPaths($resourceMetadata, $resourceClass, OperationType::ITEM, $context, $paths, $links, $schemas);
            $links = array_merge($links, $itemOperationLinks);
            $schemas += $itemOperationSchemas;
            list($collectionOperationLinks, $collectionOperationSchemas) = $this->collectPaths($resourceMetadata, $resourceClass, OperationType::COLLECTION, $context, $paths, $links, $schemas);
            $links = array_merge($links, $collectionOperationLinks);
            $schemas += $collectionOperationSchemas;
        }

        $securitySchemes = $this->getSecuritySchemes();
        $securityRequirements = [];

        foreach (array_keys($securitySchemes) as $key) {
            $securityRequirements[$key] = [];
        }

        return new OpenApi($info, $servers, $paths, new Model\Components($schemas, [], [], [], [], [], $securitySchemes), $securityRequirements);
    }

    private function collectPaths(ResourceMetadata $resourceMetadata, string $resourceClass, string $operationType, array $context, Model\Paths $paths, array $links, array $schemas = []): array
    {
        $resourceShortName = $resourceMetadata->getShortName();
        if (null === $operations = OperationType::COLLECTION === $operationType ? $resourceMetadata->getCollectionOperations() : $resourceMetadata->getItemOperations()) {
            return [$links, $schemas];
        }

        foreach ($operations as $operationName => $operation) {
            $path = $this->getPath($resourceShortName, $operationName, $operation, $operationType);
            $method = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'method', 'GET');
            list($requestMimeTypes, $responseMimeTypes) = $this->getMimeTypes($resourceClass, $operationName, $operationType, $resourceMetadata);
            $operationId = $operation['openapi_context']['operationId'] ?? lcfirst($operationName).ucfirst($resourceShortName).ucfirst($operationType);
            $linkedOperationId = 'get'.ucfirst($resourceShortName).ucfirst(OperationType::ITEM);
            $pathItem = $paths->getPath($path) ?: new Model\PathItem();

            /** @TODO support multiple formats for schemas? */
            $operationFormat = array_values($responseMimeTypes)[0];
            $operationSchemaOutput = $this->jsonSchemaFactory->buildSchema($resourceClass, $operationFormat, Schema::TYPE_OUTPUT, $operationType, $operationName, new Schema('openapi'), $context);

            $schemas += $operationSchemaOutput->getDefinitions()->getArrayCopy();
            $parameters = [];
            $responses = [];

            // Set up parameters
            if (OperationType::ITEM === $operationType) {
                $parameters[] = new Model\Parameter('id', 'path', 'Resource identifier', true, false, false, ['type' => 'string']);
                $links[$operationId] = $this->getLink($resourceClass, $operationId, $path);
            } elseif (OperationType::COLLECTION === $operationType && 'GET' === $method) {
                $parameters = array_merge($parameters, $this->getPaginationParameters($resourceMetadata, $operationName), $this->getFiltersParameters($resourceMetadata, $operationName, $resourceClass));
            }

            // Create responses
            switch ($method) {
                case 'GET':
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '200');
                    $responseContent = $this->buildContent($responseMimeTypes, $operationSchemaOutput);
                    $responses[$successStatus] = new Model\Response(sprintf('%s %s', $resourceShortName, OperationType::COLLECTION === $operationType ? 'collection' : 'resource'), $responseContent);
                    break;
                case 'POST':
                    $responseLinks = isset($links[$linkedOperationId]) ? [ucfirst($linkedOperationId) => $links[$linkedOperationId]] : [];
                    $responseContent = $this->buildContent($responseMimeTypes, $operationSchemaOutput);
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '201');
                    $responses[$successStatus] = new Model\Response(sprintf('%s resource created', $resourceShortName), $responseContent, [], $responseLinks);
                    $responses['400'] = new Model\Response('Invalid input');
                    break;
                case 'PATCH':
                case 'PUT':
                    $responseLinks = isset($links[$linkedOperationId]) ? [ucfirst($linkedOperationId) => $links[$linkedOperationId]] : [];
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '200');
                    $responseContent = $this->buildContent($responseMimeTypes, $operationSchemaOutput);
                    $responses[$successStatus] = new Model\Response(sprintf('%s resource updated', $resourceShortName), $responseContent, [], $responseLinks);
                    $responses['400'] = new Model\Response('Invalid input');
                    break;
                case 'DELETE':
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '204');
                    $responses[$successStatus] = new Model\Response(sprintf('%s resource deleted', $resourceShortName));
                    break;
            }

            if (OperationType::ITEM === $operationType) {
                $responses['404'] = new Model\Response('Resource not found');
            }

            if (!$responses) {
                $responses['default'] = new Model\Response('Unexpected error');
            }

            $requestBody = null;
            if ('PUT' === $method || 'POST' === $method || 'PATCH' === $method) {
                /** @TODO support multiple input formats */
                $operationFormatInput = array_values($requestMimeTypes)[0];
                $operationSchemaInput = $this->jsonSchemaFactory->buildSchema($resourceClass, $operationFormatInput, Schema::TYPE_INPUT, $operationType, $operationName, new Schema('openapi'), $context);
                $schemas += $operationSchemaInput->getDefinitions()->getArrayCopy();
                $requestBody = new Model\RequestBody(sprintf('The %s %s resource', 'POST' === $method ? 'new' : 'updated', $resourceShortName), $this->buildContent($requestMimeTypes, $operationSchemaInput), true);
            }

            $pathItem = $pathItem->{'with'.ucfirst($method)}(new Model\Operation(
                $operationId,
                $operation['openapi_context']['tags'] ?? [$resourceShortName],
                $responses,
                $operation['openapi_context']['summary'] ?? '',
                $operation['openapi_context']['description'] ?? $this->getPathDescription($resourceShortName, $method, $operationType),
                $operation['openapi_context']['externalDocs'] ?? [],
                $parameters,
                $requestBody,
                $operation['openapi_context']['callbacks'] ?? [],
                $operation['openapi_context']['deprecated'] ?? (bool) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'deprecation_reason', false, true),
                $operation['openapi_context']['security'] ?? [],
                $operation['openapi_context']['servers'] ?? []
            ));

            $paths->addPath($path, $pathItem);
        }

        return [$links, $schemas];
    }

    /**
     * @TODO: support multiple format schemas
     */
    private function buildContent(array $responseMimeTypes, $operationSchema)
    {
        $content = [];

        foreach ($responseMimeTypes as $mimeType => $format) {
            $content[$mimeType] = new Model\MediaType($operationSchema->getArrayCopy(false));
        }

        return $content;
    }

    private function getMimeTypes($resourceClass, $operationName, $operationType, $resourceMetadata = null)
    {
        $requestFormats = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'input_formats', $this->formats, true);
        $responseFormats = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'output_formats', $this->formats, true);

        $requestMimeTypes = $this->flattenMimeTypes($requestFormats);
        $responseMimeTypes = $this->flattenMimeTypes($responseFormats);

        return [$requestMimeTypes, $responseMimeTypes];
    }

    private function flattenMimeTypes(array $responseFormats): array
    {
        $responseMimeTypes = [];
        foreach ($responseFormats as $responseFormat => $mimeTypes) {
            foreach ($mimeTypes as $mimeType) {
                $responseMimeTypes[$mimeType] = $responseFormat;
            }
        }

        return $responseMimeTypes;
    }

    /**
     * Gets the path for an operation.
     *
     * If the path ends with the optional _format parameter, it is removed
     * as optional path parameters are not yet supported.
     *
     * @see https://github.com/OAI/OpenAPI-Specification/issues/93
     */
    private function getPath(string $resourceShortName, string $operationName, array $operation, string $operationType): string
    {
        $path = $this->operationPathResolver->resolveOperationPath($resourceShortName, $operation, $operationType, $operationName);
        if ('.{_format}' === substr($path, -10)) {
            $path = substr($path, 0, -10);
        }

        return $path;
    }

    private function getPathDescription(string $resourceShortName, string $method, string $operationType): string
    {
        switch ($method) {
            case 'GET':
                $pathSummary = OperationType::COLLECTION === $operationType ? 'Retrieves the collection of %s resources.' : 'Retrieves a %s resource.';
                break;
            case 'POST':
                $pathSummary = 'Creates a %s resource.';
                break;
            case 'PATCH':
                $pathSummary = 'Updates the %s resource.';
                break;
            case 'PUT':
                $pathSummary = 'Replaces the %s resource.';
                break;
            case 'DELETE':
                $pathSummary = 'Removes the %s resource.';
                break;
            default:
                return $resourceShortName;
        }

        return sprintf($pathSummary, $resourceShortName);
    }

    /**
     * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md#linkObject.
     */
    private function getLink(string $resourceClass, string $operationId, string $path): Model\Link
    {
        $parameters = [];

        foreach ($this->propertyNameCollectionFactory->create($resourceClass) as $propertyName) {
            $propertyMetadata = $this->propertyMetadataFactory->create($resourceClass, $propertyName);
            if (!$propertyMetadata->isIdentifier()) {
                continue;
            }

            $parameters[$propertyName] = sprintf('$response.body#/%s', $propertyName);
        }

        return new Model\Link(
            $operationId,
            $parameters,
            [],
            1 === \count($parameters) ? sprintf('The `%1$s` value returned in the response can be used as the `%1$s` parameter in `GET %2$s`.', key($parameters), $path) : sprintf('The values returned in the response can be used in `GET %s`.', $path)
        );
    }

    /**
     * Gets parameters corresponding to enabled filters.
     */
    private function getFiltersParameters(ResourceMetadata $resourceMetadata, string $operationName, string $resourceClass): array
    {
        $parameters = [];
        $resourceFilters = $resourceMetadata->getCollectionOperationAttribute($operationName, 'filters', [], true);
        foreach ($resourceFilters as $filterId) {
            if (!$filter = $this->getFilter($filterId)) {
                continue;
            }

            foreach ($filter->getDescription($resourceClass) as $name => $data) {
                $schema = $data['schema'] ?? \in_array($data['type'], Type::$builtinTypes, true) ? $this->jsonSchemaTypeFactory->getType(new Type($data['type'], false, null, $data['is_collection'] ?? false)) : ['type' => 'string'];

                $parameters[] = new Model\Parameter($name, 'query', $data['description'] ?? '', $data['required'] ?? false, $data['openapi']['deprecated'] ?? false, $data['openapi']['allowEmptyValue'] ?? true, $schema, 'array' === $schema['type'] && \in_array($data['type'], [Type::BUILTIN_TYPE_ARRAY, Type::BUILTIN_TYPE_OBJECT], true) ? 'deepObject' : 'form', 'array' === $schema['type'], $data['openapi']['allowReserved'] ?? false, $data['openapi']['example'] ?? null, $data['openapi']['examples'] ?? []);
            }
        }

        return $parameters;
    }

    private function getPaginationParameters(ResourceMetadata $resourceMetadata, string $operationName)
    {
        if (!$this->paginationOptions->isPaginationEnabled()) {
            return [];
        }

        $parameters = [];

        if ($resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_enabled', true, true)) {
            $parameters[] = new Model\Parameter($this->paginationOptions->getPaginationPageParameterName(), 'query', 'The collection page number', false, false, true, ['type' => 'integer', 'default' => 1]);

            if ($resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_client_items_per_page', $this->paginationOptions->getClientItemsPerPage(), true)) {
                $schema = [
                    'type' => 'integer',
                    'default' => $resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_items_per_page', 30, true),
                    'minimum' => 0,
                ];

                if (null !== $maxItemsPerPage = $resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_maximum_items_per_page', null, true)) {
                    $schema['maximum'] = $maxItemsPerPage;
                }

                $parameters[] = new Model\Parameter($this->paginationOptions->getItemsPerPageParameterName(), 'query', 'The number of items per page', false, false, true, $schema);
            }
        }

        if ($resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_client_enabled', $this->paginationOptions->getPaginationClientEnabled(), true)) {
            $parameters[] = new Model\Parameter($this->paginationOptions->getPaginationClientEnabledParameterName(), 'query', 'Enable or disable pagination', false, false, true, ['type' => 'boolean']);
        }

        return $parameters;
    }

    private function getOauthSecurityScheme()
    {
        $oauthFlow = new Model\OAuthFlow($this->openApiOptions->getOAuthAuthorizationUrl(), $this->openApiOptions->getOAuthTokenUrl(), $this->openApiOptions->getOAuthRefreshUrl(), $this->openApiOptions->getOAuthScopes());
        $description = sprintf(
            'OAuth 2.0 %s Grant',
            strtolower(preg_replace('/[A-Z]/', ' \\0', lcfirst($this->openApiOptions->getOAuthFlow())))
        );
        list($implicit, $password, $clientCredentials, $authorizationCode) = [null, null, null, null];

        switch ($this->openApiOptions->getOAuthFlow()) {
            case 'implicit':
                $implicit = $oauthFlow;
                break;
            case 'password':
                $password = $oauthFlow;
                break;
            case 'application':
            case 'clientCredentials':
                $clientCredentials = $oauthFlow;
                break;
            case 'accessCode':
            case 'authorizationCode':
                $authorizationCode = $oauthFlow;
                break;
            default:
                throw new \LogicException('OAuth flow must be one of: implicit, password, clientCredentials, authorizationCode');
        }

        return new Model\SecurityScheme($this->openApiOptions->getOAuthType(), $description, null, null, null, null, new Model\OAuthFlows($implicit, $password, $clientCredentials, $authorizationCode));
    }

    private function getSecuritySchemes()
    {
        $securitySchemes = [];

        if ($this->openApiOptions->getOAuthEnabled()) {
            $securitySchemes['oauth'] = $this->getOauthSecurityScheme();
        }

        foreach ($this->openApiOptions->getApiKeys() as $key => $apiKey) {
            $description = sprintf('Value for the %s %s parameter.', $apiKey['name'], $apiKey['type']);
            $securitySchemes[$key] = new Model\SecurityScheme('apiKey', $description, $apiKey['name'], $apiKey['type']);
        }

        return $securitySchemes;
    }
}
