<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Spatie\LaravelData\Optional;

/**
 * Base API resource around an output DTO (not a model).
 *
 * Fields are read from the DTO through the resource proxy: $this->userId.
 * Relations of an output DTO are Optional when they were not loaded, so
 * whenLoaded() (which asks the model) does not apply: use whenLoadedData().
 */
abstract class DataResource extends JsonResource
{
    /**
     * Related DTO(s) wrapped in their resource:
     *  - not loaded (Optional)  -> left out of the response;
     *  - loaded, empty          -> null / [];
     *  - one DTO                -> $resource::make($dto);
     *  - collection of DTOs     -> $resource::collection($dtos).
     *
     * @param  class-string<JsonResource>  $resource
     */
    protected function whenLoadedData(mixed $value, string $resource): mixed
    {
        if ($value instanceof Optional) {
            return new MissingValue;
        }

        if ($value === null) {
            return null;
        }

        return is_iterable($value) ? $resource::collection($value) : $resource::make($value);
    }
}
