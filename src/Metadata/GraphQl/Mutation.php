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

namespace ApiPlatform\Metadata\GraphQl;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Mutation extends Operation
{
    /**
     * {@inheritdoc}
     */
    public function __construct(
        protected ?string $query = null,
        protected ?string $mutation = null,
        protected bool $collection = false,
        protected array $args = [],
        protected ?string $shortName = null,
        protected ?string $class = null,
        protected mixed $identifiers = [],
        protected ?bool $compositeIdentifier = null,
        protected ?bool $paginationEnabled = null,
        protected ?string $paginationType = null,
        protected ?int $paginationItemsPerPage = null,
        protected ?int $paginationMaximumItemsPerPage = null,
        protected ?bool $paginationPartial = null,
        protected ?bool $paginationClientEnabled = null,
        protected ?bool $paginationClientItemsPerPage = null,
        protected ?bool $paginationClientPartial = null,
        protected ?bool $paginationFetchJoinCollection = null,
        protected ?bool $paginationUseOutputWalkers = null,
        protected array $order = [],
        protected ?string $description = null,
        protected array $normalizationContext = [],
        protected array $denormalizationContext = [],
        protected ?string $security = null,
        protected ?string $securityMessage = null,
        protected ?string $securityPostDenormalize = null,
        protected ?string $securityPostDenormalizeMessage = null,
        protected ?string $deprecationReason = null,
        protected array $filters = [],
        protected array $validationContext = [],
        protected mixed $input = null,
        protected mixed $output = null,
        protected mixed $mercure = null,
        protected mixed $messenger = null,
        protected ?bool $elasticsearch = null,
        protected ?int $urlGenerationStrategy = null,
        protected bool $read = true,
        protected bool $deserialize = true,
        protected bool $validate = true,
        protected bool $write = true,
        protected bool $serialize = true,
        protected ?bool $fetchPartial = null,
        protected ?bool $forceEager = null,
        protected int $priority = 0,
        protected string $name = '',
        protected array $extraProperties = [],
    ) {
        parent::__construct(...\func_get_args());
    }
}
