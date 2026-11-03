<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use Toshkq93\LaravelLayers\Generation\Generators\ControllerGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\FactoryGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\Generator;
use Toshkq93\LaravelLayers\Generation\Generators\InputDataGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\MiddlewareGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\OutputDataGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\PolicyGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\RepositoryGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\ServiceGenerator;
use Toshkq93\LaravelLayers\Generation\Generators\TestGenerator;

/**
 * Produces the files of every selected class layer (routes are handled
 * separately by RouteWriter because they edit an existing file).
 */
final class LayerGenerator
{
    /** @var array<string, Generator> */
    private array $generators;

    public function __construct(StubRenderer $stubs)
    {
        $this->generators = [];

        foreach ([
            new InputDataGenerator($stubs),
            new OutputDataGenerator($stubs),
            new RepositoryGenerator($stubs),
            new ServiceGenerator($stubs),
            new ControllerGenerator($stubs),
            new PolicyGenerator($stubs),
            new MiddlewareGenerator($stubs),
            new FactoryGenerator($stubs),
            new TestGenerator($stubs),
        ] as $generator) {
            $this->generators[$generator->layer()->value] = $generator;
        }
    }

    /**
     * @return list<GeneratedFile>
     */
    public function generate(Blueprint $blueprint): array
    {
        $files = [];

        foreach ($blueprint->layers as $layer) {
            if (isset($this->generators[$layer->value])) {
                array_push($files, ...$this->generators[$layer->value]->generate($blueprint));
            }
        }

        return $files;
    }
}
