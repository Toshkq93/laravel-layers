<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

enum Layer: string
{
    case Input = 'input';
    case Output = 'output';
    case Repository = 'repository';
    case Service = 'service';
    case Controller = 'controller';
    case Policy = 'policy';
    case Middleware = 'middleware';
    case Route = 'route';
    case Factory = 'factory';
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Input => 'Input DTO (validation)',
            self::Output => 'Output DTO',
            self::Repository => 'Repository',
            self::Service => 'Service',
            self::Controller => 'Controller',
            self::Policy => 'Gate (policy + authorize() in input DTOs)',
            self::Middleware => 'Middleware',
            self::Route => 'Routes (routes/api.php)',
            self::Factory => 'Model factory',
            self::Test => 'Feature tests for the API',
        };
    }

    /** Whether the layer produces a class (Route only edits a file). */
    public function isClass(): bool
    {
        return $this !== self::Route;
    }

    /** Whether one class is generated per action (input DTOs). */
    public function isPerAction(): bool
    {
        return $this === self::Input;
    }
}
