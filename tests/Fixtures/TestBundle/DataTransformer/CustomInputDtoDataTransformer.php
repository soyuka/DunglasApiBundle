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

namespace ApiPlatform\Core\Tests\Fixtures\TestBundle\DataTransformer;

use ApiPlatform\Core\DataTransformer\DataTransformerInterface;
use ApiPlatform\Core\Serializer\AbstractItemNormalizer;
use ApiPlatform\Core\Tests\Fixtures\TestBundle\Dto\CustomInputDto;

final class CustomInputDtoDataTransformer implements DataTransformerInterface
{
    /**
     * {@inheritdoc}
     */
    public function transform($object, array $context = [])
    {
        if (!$object instanceof CustomInputDto) {
            throw new \InvalidArgumentException();
        }

        /**
         * @var \ApiPlatform\Core\Tests\Fixtures\TestBundle\Entity\DummyDtoCustom
         */
        $resourceObject = $context[AbstractItemNormalizer::OBJECT_TO_POPULATE] ?? new $context['resource_class']();
        $resourceObject->lorem = $object->foo;
        $resourceObject->ipsum = (string) $object->bar;

        return $resourceObject;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransformation($object, array $context = []): bool
    {
        return CustomInputDto::class === ($context['input']['class'] ?? null);
    }
}
