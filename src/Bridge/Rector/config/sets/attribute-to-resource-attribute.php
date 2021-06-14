<?php

declare(strict_types=1);

use ApiPlatform\Core\Bridge\Rector\Rules\ApiResourceAttributeToResourceAttributeRector;
use Rector\Core\Configuration\Option;
use Rector\Core\ValueObject\PhpVersion;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $parameters = $containerConfigurator->parameters();
    $parameters->set(Option::PHP_VERSION_FEATURES, PhpVersion::PHP_80);
    $parameters->set(Option::AUTO_IMPORT_NAMES, true);

    $services = $containerConfigurator->services();
    $services->set(ApiResourceAttributeToResourceAttributeRector::class)
        ->call('configure', [[
            ApiResourceAttributeToResourceAttributeRector::REMOVE_INITIAL_ATTRIBUTE => true
        ]]);
};
