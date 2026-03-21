<?php

namespace Lartisan\Architect\ValueObjects;

readonly class ColumnDefinition
{
    /**
     * @param  array<string, mixed>  $relationshipMeta
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $nullable = false,
        public bool $unique = false,
        public bool $index = false,
        public mixed $default = null,
        public ?string $relationshipTable = null,
        public ?string $relationshipTitleColumn = null,
        public array $relationshipMeta = [],
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
            relationshipTable: filled($data['relationship_table'] ?? null) ? (string) $data['relationship_table'] : null,
            relationshipTitleColumn: filled($data['relationship_title_column'] ?? null) ? (string) $data['relationship_title_column'] : null,
            relationshipMeta: is_array($data['relationship_meta'] ?? null) ? $data['relationship_meta'] : [],
        );
    }

    public function toMigrationLine(): string
    {
        return $this->buildMigrationLine(appendChangeCall: false);
    }

    public function toChangeMigrationLine(bool $forceDefault = false): string
    {
        return $this->buildMigrationLine(appendChangeCall: true, forceDefault: $forceDefault);
    }

    private function buildMigrationLine(bool $appendChangeCall, bool $forceDefault = false): string
    {
        $line = "\$table->{$this->type}('{$this->name}')";

        if ($this->nullable) {
            $line .= '->nullable()';
        }

        if (! $appendChangeCall && $this->unique) {
            $line .= '->unique()';
        }

        if ($forceDefault || ($this->default !== null && $this->default !== '')) {
            $line .= '->default('.$this->formatDefaultValue().')';
        }

        if (! $appendChangeCall && $this->index && ! $this->unique) {
            $line .= '->index()';
        }

        if ($appendChangeCall) {
            $line .= '->change()';
        }

        return $line.';';
    }

    private function formatDefaultValue(): string
    {
        if ($this->default === null) {
            return 'null';
        }

        if (is_bool($this->default)) {
            return $this->default ? 'true' : 'false';
        }

        if (is_numeric($this->default)) {
            return (string) $this->default;
        }

        return "'{$this->default}'";
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
            'relationship_table' => $this->relationshipTable,
            'relationship_title_column' => $this->relationshipTitleColumn,
            'relationship_meta' => $this->relationshipMeta,
        ];
    }
}
