<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Toshkq93\LaravelLayers\Generation\Action;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Generation\LayerGenerator;
use Toshkq93\LaravelLayers\Generation\Naming;
use Toshkq93\LaravelLayers\Generation\RouteGroup;
use Toshkq93\LaravelLayers\Generation\RouteWriter;
use Toshkq93\LaravelLayers\Generation\StubRenderer;
use Toshkq93\LaravelLayers\Schema\ModelInspector;

use function Laravel\Prompts\multiselect;

class MakeLayersCommand extends Command
{
    protected $signature = 'make:layers
        {model : Model name (Post, Blog/Post) or its fully qualified class}
        {--all : Generate every layer except the gate (add --gate for policies)}
        {--input : Input DTOs, one per action (validation)}
        {--output : Output DTO}
        {--resource : API resource returned by the controller}
        {--repository : Repository}
        {--service : Service}
        {--controller : Controller}
        {--gate : Policy + authorize() in the input DTOs}
        {--middleware : Middleware for the model routes}
        {--route : Routes in routes/api.php}
        {--factory : Model factory with Faker values matching the schema}
        {--test : Feature tests for the generated API}
        {--actions= : Comma-separated actions: index,show,store,update,destroy}
        {--force : Overwrite existing files and the route block}
        {--dry-run : Show what would be generated without writing anything}';

    protected $description = 'Generate architectural layers (DTOs, API resources, repository, service, controller, gate, middleware, routes, factory, tests) for a model';

    /** Command option => layer */
    private const OPTIONS = [
        'input' => Layer::Input,
        'output' => Layer::Output,
        'resource' => Layer::Resource,
        'repository' => Layer::Repository,
        'service' => Layer::Service,
        'controller' => Layer::Controller,
        'gate' => Layer::Policy,
        'middleware' => Layer::Middleware,
        'route' => Layer::Route,
        'factory' => Layer::Factory,
        'test' => Layer::Test,
    ];

    /** Layers left out of --all and the defaults: generated only when asked for. */
    private const OPT_IN = [Layer::Policy];

    public function handle(Filesystem $files, ModelInspector $inspector): int
    {
        try {
            $definition = $inspector->inspect($this->resolveModel((string) $this->argument('model')));
            $layers = $this->layers();
            $actions = $this->actions();
            $naming = new Naming(
                $definition,
                config('layers.layers'),
                $this->laravel->getNamespace(),
                app_path(),
                config('layers.properties', Naming::PROPERTIES_CAMEL),
                base_path(),
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($layers === [] || $actions === []) {
            $this->components->warn('Nothing to generate.');

            return self::SUCCESS;
        }

        $selected = new Blueprint($definition, $naming, $layers, $actions, RouteGroup::fromConfig(config('layers.routes.group')));
        $blueprint = $selected->withImplicitLayers();

        foreach ($blueprint->layers as $layer) {
            if (! in_array($layer, $layers, true)) {
                $this->components->info("Also generating {$layer->label()}: the selected layers depend on it.");
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $generator = new LayerGenerator(new StubRenderer(base_path('stubs/layers')));

        foreach ($generator->generate($blueprint) as $file) {
            $relative = $this->relative($file->path);
            $exists = $files->exists($file->path);

            if ($exists && ! $force) {
                $this->components->twoColumnDetail($relative, '<fg=yellow>exists, skipped</>');

                continue;
            }

            if ($dryRun) {
                $this->components->twoColumnDetail($relative, '<fg=cyan>'.($exists ? 'would overwrite' : 'would create').'</>');

                if ($this->output->isVerbose()) {
                    $this->line($file->contents);
                }

                $this->notes($file->notes);

                continue;
            }

            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
            $this->components->twoColumnDetail($relative, $exists ? '<fg=green>overwritten</>' : '<fg=green>created</>');
            $this->notes($file->notes);
        }

        if ($blueprint->generates(Layer::Route)) {
            $this->writeRoutes($files, $blueprint, $dryRun, $force);
        }

        $this->policyHint($blueprint);

        return self::SUCCESS;
    }

    /** @param list<string> $notes */
    private function notes(array $notes): void
    {
        foreach ($notes as $note) {
            $this->components->warn($note);
        }
    }

    /** @return list<Layer> */
    private function layers(): array
    {
        $defaults = array_filter(self::OPTIONS, fn (Layer $l) => ! in_array($l, self::OPT_IN, true));
        $layers = [];

        foreach (self::OPTIONS as $option => $layer) {
            if ($this->option($option) || ($this->option('all') && isset($defaults[$option]))) {
                $layers[] = $layer;
            }
        }

        if ($layers !== [] || ! $this->input->isInteractive()) {
            return $layers ?: array_values($defaults);
        }

        $chosen = multiselect(
            label: 'Which layers should be generated?',
            options: collect(self::OPTIONS)->mapWithKeys(fn (Layer $l, string $option) => [$option => $l->label()])->all(),
            default: array_keys($defaults),
            scroll: 10,
        );

        return array_values(array_map(fn (string $option) => self::OPTIONS[$option], $chosen));
    }

    /** @return list<Action> */
    private function actions(): array
    {
        if ($this->option('actions') !== null) {
            return Action::parse($this->option('actions'));
        }

        $default = Action::parse(config('layers.actions', []));

        if (! $this->input->isInteractive() || $this->option('all') || $this->hasLayerOptions()) {
            return $default;
        }

        $chosen = multiselect(
            label: 'Which actions?',
            options: collect(Action::cases())->mapWithKeys(fn (Action $a) => [$a->value => $a->value])->all(),
            default: array_map(fn (Action $a) => $a->value, $default),
        );

        return Action::parse($chosen);
    }

    private function hasLayerOptions(): bool
    {
        foreach (array_keys(self::OPTIONS) as $option) {
            if ($this->option($option)) {
                return true;
            }
        }

        return false;
    }

    /** @return class-string */
    private function resolveModel(string $name): string
    {
        $name = trim(str_replace('/', '\\', $name), '\\');
        $root = $this->laravel->getNamespace();

        foreach ([$name, $root.'Models\\'.$name, $root.$name] as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        throw new InvalidArgumentException("Model [{$name}] not found.");
    }

    private function writeRoutes(Filesystem $files, Blueprint $blueprint, bool $dryRun, bool $force): void
    {
        if (! $blueprint->has(Layer::Controller)) {
            $this->components->warn('Routes skipped: '.$blueprint->naming->fqcn(Layer::Controller).' does not exist. Generate it with --controller.');

            return;
        }

        $writer = new RouteWriter($files);
        $path = base_path(config('layers.routes.file', 'routes/api.php'));

        if ($dryRun) {
            $this->components->twoColumnDetail($this->relative($path), '<fg=cyan>would add routes</>');
            $this->line($writer->block($blueprint));

            return;
        }

        $result = $writer->write($blueprint, $path, $force);

        $this->components->twoColumnDetail($this->relative($path), match ($result['status']) {
            RouteWriter::SKIPPED => '<fg=yellow>routes exist, skipped</>',
            RouteWriter::REPLACED => '<fg=green>routes replaced</>',
            default => '<fg=green>routes added</>',
        });

        $registered = $writer->ensureRegistered(base_path('bootstrap/app.php'), $path, base_path());

        if ($registered === true) {
            $this->components->twoColumnDetail('bootstrap/app.php', '<fg=green>api routes registered</>');
        } elseif ($registered === false) {
            $this->components->warn("Register {$this->relative($path)} in bootstrap/app.php (withRouting(api: ...)) or run `php artisan install:api`.");
        }
    }

    /**
     * Laravel discovers App\Policies\{Model}Policy for App\Models\{Model};
     * any other location must be registered by hand.
     */
    private function policyHint(Blueprint $blueprint): void
    {
        if (! $blueprint->generates(Layer::Policy)) {
            return;
        }

        $policy = $blueprint->naming->fqcn(Layer::Policy);
        $model = $blueprint->model->class;
        $root = $this->laravel->getNamespace();
        $name = $blueprint->model->name();

        $discovered = in_array($model, [$root.'Models\\'.$name, $root.$name], true)
            && $policy === $root.'Policies\\'.$name.'Policy';

        if (! $discovered) {
            $this->components->warn("Register the policy in a service provider: Gate::policy(\\{$model}::class, \\{$policy}::class);");
        }
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
