<?php

namespace Lartisan\Architect\ValueObjects;

readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $nullable = false,
        public bool $unique = false,
        public bool $index = false,
        public mixed $default = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            type: $data['type'] ?? 'string',
            nullable: (bool) ($data['is_nullable'] ?? false),
            unique: (bool) ($data['is_unique'] ?? false),
            index: (bool) ($data['is_index'] ?? false),
            default: $data['default'] ?? null,
        );
    }

    public function toMigrationLine(): string
    {
        $line = "\$table->{$this->type}('{$this->name}')";

        if ($this->nullable) {
            $line .= '->nullable()';
        }

        if ($this->unique) {
            $line .= '->unique()';
        }

        // Handle default values - skip empty strings but allow 0 and false
        if ($this->default !== null && $this->default !== '') {
            if (is_bool($this->default)) {
                $value = $this->default ? 'true' : 'false';
            } elseif (is_numeric($this->default)) {
                $value = $this->default;
            } else {
                $value = "'{$this->default}'";
            }
            $line .= "->default({$value})";
        }

        if ($this->index && ! $this->unique) {
            $line .= '->index()';
        }

        return $line.';';
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'default' => $this->default,
            'is_nullable' => $this->nullable,
            'is_unique' => $this->unique,
            'is_index' => $this->index,
        ];
    }
}
