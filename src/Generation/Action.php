<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Generation;

enum Action: string
{
    case Index = 'index';
    case Show = 'show';
    case Store = 'store';
    case Update = 'update';
    case Destroy = 'destroy';

    /** Whether the route contains the model key: posts/{post}. */
    public function needsId(): bool
    {
        return in_array($this, [self::Show, self::Update, self::Destroy], true);
    }

    public function httpMethod(): string
    {
        return match ($this) {
            self::Index, self::Show => 'get',
            self::Store => 'post',
            self::Update => 'patch',
            self::Destroy => 'delete',
        };
    }

    /** Policy method / gate ability. */
    public function ability(): string
    {
        return match ($this) {
            self::Index => 'viewAny',
            self::Show => 'view',
            self::Store => 'create',
            self::Update => 'update',
            self::Destroy => 'delete',
        };
    }

    /**
     * @param  list<string>|string|null  $value  "index,show" or ['index', 'show']
     * @return list<self>
     */
    public static function parse(array|string|null $value): array
    {
        $names = is_array($value) ? $value : explode(',', (string) $value);
        $actions = [];

        foreach ($names as $name) {
            $name = strtolower(trim($name));

            if ($name === '') {
                continue;
            }

            $actions[] = self::tryFrom($name)
                ?? throw new \InvalidArgumentException("Unknown action [{$name}]. Allowed: ".implode(', ', array_column(self::cases(), 'value')).'.');
        }

        // Keep canonical order and drop duplicates.
        return array_values(array_filter(self::cases(), fn (self $a) => in_array($a, $actions, true)));
    }
}
