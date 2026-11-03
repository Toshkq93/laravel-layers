<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests\Unit;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Toshkq93\LaravelLayers\Schema\Field;
use Toshkq93\LaravelLayers\Schema\TypeMapper;
use Toshkq93\LaravelLayers\Tests\Fixtures\PostStatus;

class TypeMapperTest extends TestCase
{
    private function column(string $typeName, ?string $type = null): array
    {
        return ['name' => 'x', 'type_name' => $typeName, 'type' => $type ?? $typeName, 'nullable' => false, 'default' => null, 'auto_increment' => false];
    }

    public static function columns(): array
    {
        return [
            'mysql bigint' => ['bigint', 'bigint unsigned', 'int', null, null, []],
            'pgsql int8' => ['int8', 'bigint', 'int', null, null, []],
            'mysql bool' => ['tinyint', 'tinyint(1)', 'bool', null, null, []],
            'pgsql bool' => ['bool', 'boolean', 'bool', null, null, []],
            'mysql varchar' => ['varchar', 'varchar(120)', 'string', 120, null, []],
            'pgsql varchar' => ['varchar', 'character varying(80)', 'string', 80, null, []],
            'text' => ['text', null, 'string', null, null, []],
            'decimal' => ['decimal', 'decimal(8,2)', 'string', null, Field::FORMAT_NUMERIC, []],
            'double' => ['double', null, 'float', null, null, []],
            'json' => ['json', null, 'array', null, null, []],
            'jsonb' => ['jsonb', null, 'array', null, null, []],
            'uuid' => ['uuid', null, 'string', null, Field::FORMAT_UUID, []],
            'date' => ['date', null, 'string', null, Field::FORMAT_DATE, []],
            'timestamp' => ['timestamp', 'timestamp(0) without time zone', 'string', null, Field::FORMAT_DATETIME, []],
            'mysql enum' => ['enum', "enum('draft','it''s','published')", 'string', null, null, ['draft', "it's", 'published']],
        ];
    }

    #[DataProvider('columns')]
    public function test_column_mapping(string $typeName, ?string $type, string $php, ?int $max, ?string $format, array $allowed): void
    {
        $result = (new TypeMapper)->map($this->column($typeName, $type));

        $this->assertSame($php, $result['type']);
        $this->assertSame($max, $result['maxLength']);
        $this->assertSame($format, $result['format']);
        $this->assertSame($allowed, $result['allowed']);
    }

    public function test_cast_overrides_column(): void
    {
        $mapper = new TypeMapper;

        $this->assertSame('bool', $mapper->map($this->column('integer'), 'boolean')['type']);
        $this->assertSame('array', $mapper->map($this->column('text'), 'array')['type']);
        $this->assertSame('array', $mapper->map($this->column('text'), 'encrypted:array')['type']);
        $this->assertSame('array', $mapper->map($this->column('text'), AsCollection::class)['type']);
        $this->assertSame('string', $mapper->map($this->column('numeric'), 'decimal:2')['type']);
        $this->assertSame('\\'.CarbonInterface::class, $mapper->map($this->column('datetime'), 'datetime:Y-m-d')['type']);
        $this->assertSame('\\'.PostStatus::class, $mapper->map($this->column('varchar'), PostStatus::class)['type']);
    }

    public function test_cast_keeps_column_length(): void
    {
        $result = (new TypeMapper)->map($this->column('varchar', 'varchar(50)'), 'string');

        $this->assertSame('string', $result['type']);
        $this->assertSame(50, $result['maxLength']);
    }
}
