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

namespace ApiPlatform\State;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use ApiPlatform\Core\DataProvider\SubresourceDataProviderInterface;

class LegacyDataProviderState implements ProviderInterface
{
    public function __construct(private ItemDataProviderInterface $itemDataProvider, private CollectionDataProviderInterface $collectionDataProvider, private SubresourceDataProviderInterface $subresourceDataProvider)
    {
    }

    public function provide(string $resourceClass, array $identifiers = [], array $context = [])
    {
        if ($context['extra_properties']['is_legacy_subresource'] ?? false) {
            return $this->subresourceDataProvider->getSubresource($resourceClass, $identifiers, $context, $context['operation_name']);
        }

        if ($identifiers) {
            return $this->itemDataProvider->getItem($resourceClass, $identifiers, $context['operation_name'], $context);
        }

        if ($this->collectionDataProvider instanceof ContextAwareCollectionDataProviderInterface) {
            return $this->collectionDataProvider->getCollection($resourceClass, $context['operation_name'] ?? null, $context);
        }

        return $this->collectionDataProvider->getCollection($resourceClass, $context['operation_name'] ?? null);
    }

    public function supports(string $resourceClass, array $identifiers = [], array $context = []): bool
    {
        if (!$this->collectionDataProvider instanceof RestrictedDataProviderInterface && !$this->itemDataProvider instanceof RestrictedDataProviderInterface) {
            return false;
        }

        if ($identifiers) {
            return $this->itemDataProvider->supports($resourceClass, $context['operation_name'] ?? null, $context);
        }

        return $this->collectionDataProvider->supports($resourceClass, $context['operation_name'] ?? null, $context);
    }
}