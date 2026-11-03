<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Data;

use Spatie\LaravelData\Data;
use Toshkq93\LaravelLayers\Contracts\OutputDataInterface;

/**
 * Base class for output DTOs produced by repositories.
 *
 * Being a laravel-data object it can be returned straight from a controller
 * and is serialized to JSON automatically.
 */
abstract class OutputData extends Data implements OutputDataInterface
{
}
