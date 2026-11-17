<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Adds the errors an input DTO can produce before the controller runs,
 * the way Scramble does for form requests: 422 when the DTO has validation
 * rules, 403 when it declares authorize().
 *
 * Runs before Scramble's ResponseExtension, which turns these exceptions
 * into responses.
 */
class InputDataErrorResponses extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! ($class = InputDataParametersExtractor::inputClass($routeInfo)) || ! $methodType = $routeInfo->getActionType()) {
            return;
        }

        $exceptions = [];

        if (InputDataRules::for($class, $routeInfo->route->parameterNames()) !== []) {
            $exceptions[] = new ObjectType(ValidationException::class);
        }

        if (method_exists($class, 'authorize')) {
            $exceptions[] = new ObjectType(AuthorizationException::class);
        }

        $methodType->exceptions = [...$methodType->exceptions, ...$exceptions];
    }
}
