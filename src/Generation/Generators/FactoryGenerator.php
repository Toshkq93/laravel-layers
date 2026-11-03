<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation\Generators;

use Carbon\CarbonInterface;
use Toshkq93\LaravelLayers\Generation\Blueprint;
use Toshkq93\LaravelLayers\Generation\Imports;
use Toshkq93\LaravelLayers\Generation\Layer;
use Toshkq93\LaravelLayers\Schema\Field;

/**
 * Model factory whose definition() fills every writable column with a
 * Faker value that passes the generated validation, so the same data
 * works for Model::factory()->create() and as a request payload (raw()).
 */
class FactoryGenerator extends Generator
{
    /** Column name fragment => Faker call, checked in order. */
    private const STRING_HINTS = [
        'email' => 'fake()->unique()->safeEmail()',
        'password' => "'password'",
        'phone' => 'fake()->phoneNumber()',
        'url' => 'fake()->url()',
        'link' => 'fake()->url()',
        'slug' => 'fake()->unique()->slug()',
        'first_name' => 'fake()->firstName()',
        'last_name' => 'fake()->lastName()',
        'name' => 'fake()->name()',
        'title' => 'fake()->sentence(3)',
        'city' => 'fake()->city()',
        'country' => 'fake()->country()',
        'address' => 'fake()->address()',
        'ip' => 'fake()->ipv4()',
        'color' => 'fake()->hexColor()',
        'body' => 'fake()->paragraph()',
        'content' => 'fake()->paragraph()',
        'description' => 'fake()->paragraph()',
        'text' => 'fake()->paragraph()',
    ];

    public function layer(): Layer
    {
        return Layer::Factory;
    }

    public function generate(Blueprint $blueprint): array
    {
        $naming = $blueprint->naming;
        $namespace = $naming->namespace(Layer::Factory);
        $imports = new Imports($namespace);
        $model = $imports->add($blueprint->model->class);

        $lines = [];

        foreach ($blueprint->model->writableFields() as $field) {
            [$value, $comment] = $this->value($blueprint, $field, $imports);
            $value = $this->unique($field, $value);
            $lines[] = "            '{$field->name}' => {$value},".($comment ? " // {$comment}" : '');
        }

        $extends = $imports->add($naming->extends(Layer::Factory));

        $contents = $this->stubs->render('factory', [
            'namespace' => $namespace,
            'imports' => $imports->render(),
            'extends' => $extends,
            'class' => $naming->class(Layer::Factory),
            'model' => $model,
            'definition' => implode("\n", $lines),
        ]);

        return [$this->file($blueprint, $contents)];
    }

    /**
     * @return array{0: string, 1: ?string} Faker expression and an optional comment
     */
    private function value(Blueprint $blueprint, Field $field, Imports $imports): array
    {
        if ($field->references !== null) {
            $factory = $blueprint->relatedFactory($field->references['table']);

            if ($factory !== null) {
                return [$imports->add($factory).'::new()', null];
            }

            return ['null', $field->nullable ? null : "TODO: no factory for the [{$field->references['table']}] table"];
        }

        if ($field->isEnumClass()) {
            return ['fake()->randomElement('.$imports->add($field->type).'::cases())', null];
        }

        if ($field->allowed !== []) {
            $values = implode(', ', array_map(fn (string $v) => "'".addcslashes($v, "'\\")."'", $field->allowed));

            return ["fake()->randomElement([{$values}])", null];
        }

        $value = match ($field->format) {
            Field::FORMAT_UUID => 'fake()->uuid()',
            Field::FORMAT_DATE => 'fake()->date()',
            Field::FORMAT_DATETIME => "fake()->dateTime()->format('Y-m-d H:i:s')",
            Field::FORMAT_TIME => 'fake()->time()',
            Field::FORMAT_NUMERIC => '(string) fake()->randomFloat(2, 0, 1000)',
            default => null,
        };

        if ($value !== null) {
            return [$value, null];
        }

        return [match ($field->type) {
            'int' => 'fake()->numberBetween(1, 1000)',
            'float' => 'fake()->randomFloat(2, 0, 1000)',
            'bool' => 'fake()->boolean()',
            'array' => '[]',
            'string' => $this->string($field),
            '\\'.CarbonInterface::class => "fake()->dateTime()->format('Y-m-d H:i:s')",
            default => 'null',
        }, $field->type === 'mixed' ? 'TODO: custom cast, set a value' : null];
    }

    /**
     * fake()->x() -> fake()->unique()->x() for columns with a unique index.
     * Not for booleans and enums: too few values, unique() would run out.
     */
    private function unique(Field $field, string $value): string
    {
        $tooFewValues = $field->type === 'bool' || $field->isEnumClass() || $field->allowed !== [];

        if (! $field->unique || $tooFewValues || $field->references !== null
            || str_contains($value, 'unique()') || ! str_contains($value, 'fake()->')) {
            return $value;
        }

        return preg_replace('/fake\(\)->/', 'fake()->unique()->', $value, 1);
    }

    private function string(Field $field): string
    {
        $value = 'fake()->words(3, true)';

        foreach (self::STRING_HINTS as $hint => $faker) {
            if ($field->name === $hint || str_contains($field->name, $hint)) {
                $value = $faker;
                break;
            }
        }

        // Keep within varchar(N) so the payload passes #[Max(N)].
        if ($field->maxLength !== null && $field->maxLength < 255) {
            return $field->maxLength >= 5
                ? "fake()->text({$field->maxLength})"
                : "fake()->lexify('".str_repeat('?', $field->maxLength)."')";
        }

        return $value;
    }
}
