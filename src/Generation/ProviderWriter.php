<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the repository contract on Laravel versions without #[Bind]:
 * adds it to $bindings of a service provider created on first use, before
 * the "// layers:bindings" marker, and registers the provider in
 * bootstrap/providers.php.
 *
 *     public array $bindings = [
 *         \App\Repositories\Contracts\PostRepositoryInterface::class => \App\Repositories\PostRepository::class,
 *         // layers:bindings
 *     ];
 */
final class ProviderWriter
{
    public const CREATED = 'created';
    public const ADDED = 'added';
    public const SKIPPED = 'skipped';
    /** The provider exists but has no marker: the binding must be added by hand. */
    public const NO_MARKER = 'no-marker';

    private const MARKER = '// layers:bindings';

    public function __construct(
        private readonly Filesystem $files,
        private readonly StubRenderer $stubs,
    ) {}

    /** The line added to $bindings. */
    public function binding(Blueprint $blueprint): string
    {
        $naming = $blueprint->naming;

        return '\\'.$naming->fqcn(Layer::Contract).'::class => \\'.$naming->fqcn(Layer::Repository).'::class,';
    }

    /**
     * @param  class-string  $provider
     * @return array{status: string, registered: bool}
     */
    public function write(Blueprint $blueprint, string $path, string $provider, ?string $bootstrapProviders = null): array
    {
        $binding = $this->binding($blueprint);
        $contract = '\\'.$blueprint->naming->fqcn(Layer::Contract).'::class';

        if ($this->files->exists($path)) {
            $contents = $this->files->get($path);
            $status = self::ADDED;
        } else {
            $contents = $this->stubs->render('provider', [
                'namespace' => substr($provider, 0, strrpos($provider, '\\')),
                'class' => class_basename($provider),
            ]);
            $status = self::CREATED;
        }

        if (str_contains($contents, $contract)) {
            $status = self::SKIPPED;
        } elseif (preg_match('/^([ \t]*)'.preg_quote(self::MARKER, '/').'/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $contents = substr($contents, 0, $offset).$m[1][0].$binding."\n".substr($contents, $offset);
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        } else {
            return ['status' => self::NO_MARKER, 'registered' => false];
        }

        return ['status' => $status, 'registered' => $this->register($provider, $bootstrapProviders)];
    }

    /** Adds the provider to bootstrap/providers.php; true when it was added now. */
    private function register(string $provider, ?string $path): bool
    {
        if ($path === null || ! $this->files->exists($path)) {
            return false;
        }

        $providers = require $path;

        if (! is_array($providers) || in_array($provider, $providers, true)) {
            return false;
        }

        return ServiceProvider::addProviderToBootstrapFile($provider, $path);
    }
}
