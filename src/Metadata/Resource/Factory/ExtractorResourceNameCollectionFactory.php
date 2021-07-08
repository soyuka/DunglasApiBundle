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

namespace ApiPlatform\Core\Metadata\Resource\Factory;

use ApiPlatform\Core\Exception\InvalidArgumentException;
use ApiPlatform\Core\Metadata\Extractor\ExtractorInterface as LegacyExtractorInterface;
use ApiPlatform\Core\Metadata\Resource\ResourceNameCollection;
use ApiPlatform\Metadata\Extractor\ExtractorInterface;

/**
 * Creates a resource name collection from {@see ApiResource} configuration files.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class ExtractorResourceNameCollectionFactory implements LegacyResourceNameCollectionFactoryInterface
{
    private $extractor;
    private $legacyExtractor;
    private $decorated;

    public function __construct(ExtractorInterface $extractor, LegacyExtractorInterface $legacyExtractor, ResourceNameCollectionFactoryInterface $decorated = null)
    {
        $this->extractor = $extractor;
        $this->legacyExtractor = $legacyExtractor;
        $this->decorated = $decorated;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException
     */
    public function create(bool $legacy = true): ResourceNameCollection
    {
        if (true === $legacy) {
            @trigger_error(sprintf('Using a legacy %s is deprecated since 2.7 and will not be possible in 3.0.', __CLASS__), \E_USER_DEPRECATED);
        }

        $classes = [];

        if ($this->decorated) {
            foreach ($this->decorated instanceof LegacyResourceNameCollectionFactoryInterface ? $this->decorated->create($legacy) : $this->decorated->create() as $resourceClass) {
                $classes[$resourceClass] = true;
            }
        }

        foreach (($legacy ? $this->legacyExtractor : $this->extractor)->getResources() as $resourceClass => $resource) {
            $classes[$resourceClass] = true;
        }

        return new ResourceNameCollection(array_keys($classes));
    }
}
