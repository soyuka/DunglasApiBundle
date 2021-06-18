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

namespace ApiPlatform\Core\Metadata\Resource;

use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\ResourceCollection\Factory\ResourceCollectionMetadataFactoryInterface;

/**
 * @internal
 */
trait ToggleableOperationAttributeTrait
{
    /**
     * @var ResourceCollectionMetadataFactoryInterface|ResourceMetadataFactoryInterface|null
     */
    private $resourceMetadataFactory;

    private function isOperationAttributeDisabled(array $attributes, string $attribute, bool $default = false, bool $resourceFallback = true): bool
    {
        if (null === $this->resourceMetadataFactory) {
            return $default;
        }

        if ($this->resourceMetadataFactory instanceof ResourceCollectionMetadataFactoryInterface) {
            $resourceMetadata = $this->resourceMetadataFactory->create($attributes['resource_class'])->getOperation($attributes['operation_name']);
            return !$resourceMetadata->{'get'.ucfirst($attribute)}();
        }

        $resourceMetadata = $this->resourceMetadataFactory->create($attributes['resource_class']);

        return !((bool) $resourceMetadata->getOperationAttribute($attributes, $attribute, !$default, $resourceFallback));
    }
}
