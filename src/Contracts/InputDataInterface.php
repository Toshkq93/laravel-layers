<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Contracts;

/**
 * Validated input that enters the application at the controller boundary.
 *
 * Everything below the controller (services, repositories) receives
 * only implementations of this interface, never the raw Request.
 */
interface InputDataInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
