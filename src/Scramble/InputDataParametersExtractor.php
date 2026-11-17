<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Scramble;

use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\GeneratesParametersFromRules;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use Toshkq93\LaravelLayers\Contracts\InputDataInterface;

/**
 * Documents the request of a controller action that takes an input DTO:
 * a JSON body for POST / PUT / PATCH, query parameters for GET / DELETE,
 * built from the DTO's validation rules by Scramble's own rule converter.
 */
class InputDataParametersExtractor implements ParameterExtractor
{
    use GeneratesParametersFromRules;

    public function __construct(
        private readonly TypeTransformer $openApiTransformer,
    ) {}

    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        if (! $class = self::inputClass($routeInfo)) {
            return $parameterExtractionResults;
        }

        $rules = InputDataRules::for($class, $routeInfo->route->parameterNames());

        if ($rules === []) {
            return $parameterExtractionResults;
        }

        $in = in_array(mb_strtolower($routeInfo->method), RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY, true) ? 'query' : 'body';

        $parameterExtractionResults[] = new ParametersExtractionResult(
            parameters: $this->makeParameters($rules, $this->openApiTransformer, in: $in),
            schemaName: $in === 'body' ? class_basename($class) : null,
            sourceClass: $class,
        );

        return $parameterExtractionResults;
    }

    /**
     * The input DTO the action takes, if any.
     *
     * @return class-string<Data&InputDataInterface>|null
     */
    public static function inputClass(RouteInfo $routeInfo): ?string
    {
        foreach ($routeInfo->reflectionAction()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_a($type->getName(), InputDataInterface::class, true) && is_a($type->getName(), Data::class, true)) {
                return $type->getName();
            }
        }

        return null;
    }
}
