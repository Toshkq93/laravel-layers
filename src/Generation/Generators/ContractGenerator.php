<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Illuminate\Container\Attributes\Bind;
use Toshkq93\LaravelLayers\Contracts\RepositoryInterface;
use Toshkq93\LaravelLayers\Contracts\SoftDeletesRepositoryInterface;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;

/**
 * Repository interface: the CRUD methods come from the package contracts,
 * the repository implements it, and services depend on it.
 *
 * On Laravel 12+ the interface binds itself to the repository with
 * #[Bind]; on older versions the command registers the binding in a
 * service provider (see ProviderWriter).
 */
class ContractGenerator extends Generator
{
    public function layer(): Layer
    {
        return Layer::Contract;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Contract);
        $imports = new Imports($namespace);

        $output = $imports->add($naming->fqcn(Layer::Output));
        $interfaces = [$imports->add(RepositoryInterface::class)];

        if ($blueprint->model->softDeletes) {
            $interfaces[] = $imports->add(SoftDeletesRepositoryInterface::class);
        }

        $attributes = $blueprint->bindAttribute
            ? '#['.$imports->add(Bind::class).'('.$imports->add($naming->fqcn(Layer::Repository)).'::class)]'."\n"
            : '';

        $contents = $this->stubs->render('contract', [
            'namespace' => $namespace,
            'class' => $naming->class(Layer::Contract),
            'extends' => implode(', ', $interfaces),
            'generics' => implode("\n", array_map(fn (string $i) => " * @extends {$i}<{$output}>", $interfaces)),
            'attributes' => $attributes,
            'output' => $output,
            'imports' => $imports->render(),
        ]);

        return [$this->file($blueprint, $contents)];
    }
}
