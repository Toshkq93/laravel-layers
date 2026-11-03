<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Data;

use Spatie\LaravelData\Data;
use Toshkq93\LaravelLayers\Contracts\InputDataInterface;

/**
 * Base class for input DTOs.
 *
 * Validation is described by property attributes / rules(), access checks
 * by the static authorize() method (laravel-data calls it before validation
 * when the DTO is resolved from a request), e.g.:
 *
 *     public static function authorize(): bool
 *     {
 *         return Gate::allows('create', Post::class);
 *     }
 */
abstract class InputData extends Data implements InputDataInterface
{
}
