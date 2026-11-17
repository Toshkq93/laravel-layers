<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Scramble;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Configuration\ParametersExtractors;
use Dedoc\Scramble\Scramble;

/**
 * Teaches dedoc/scramble the package's conventions. Registered by the
 * service provider when Scramble is installed; the package does not
 * depend on it.
 *
 *  - requests from input DTOs (body / query, 422, 403);
 *  - relations in DataResource::whenLoadedData().
 */
final class ScrambleIntegration
{
    /**
     * The Scramble API the integration relies on (0.13.54+). Older versions
     * are left alone instead of failing at boot.
     */
    public static function supported(): bool
    {
        return method_exists(\Dedoc\Scramble\GeneratorConfig::class, 'withParametersExtractors')
            && class_exists(ParametersExtractors::class)
            && class_exists(\Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult::class)
            && interface_exists(\Dedoc\Scramble\Support\Type\Contracts\LiteralString::class);
    }

    public static function register(): void
    {
        if (! self::supported()) {
            return;
        }

        Scramble::registerExtension(DataResourceExtension::class);

        Scramble::configure()
            ->withParametersExtractors(fn (ParametersExtractors $extractors) => $extractors->append(InputDataParametersExtractor::class))
            // Before ErrorResponsesExtension / ResponseExtension, which turn the exceptions into responses.
            ->withOperationTransformers(fn (OperationTransformers $transformers) => $transformers->prepend(InputDataErrorResponses::class));
    }
}
