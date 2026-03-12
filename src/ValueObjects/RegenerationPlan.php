<?php

namespace Lartisan\Architect\ValueObjects;

readonly class RegenerationPlan
{
    /**
     * @param  array<PlannedArtifact>  $artifacts
     * @param  array<PlannedSchemaOperation>  $schemaOperations
     */
    public function __construct(
        public array $artifacts,
        public array $schemaOperations,
    ) {}

    public function hasRiskySchemaChanges(): bool
    {
        return collect($this->schemaOperations)->contains(fn (PlannedSchemaOperation $operation) => $operation->risky);
    }

    public function hasDeferredRiskySchemaChanges(): bool
    {
        return collect($this->schemaOperations)->contains(fn (PlannedSchemaOperation $operation) => $operation->risky && $operation->deferred);
    }

    public function getRiskySchemaChanges(): array
    {
        return array_values(array_filter(
            $this->schemaOperations,
            fn (PlannedSchemaOperation $operation) => $operation->risky,
        ));
    }

    public function getDeferredRiskySchemaChanges(): array
    {
        return array_values(array_filter(
            $this->schemaOperations,
            fn (PlannedSchemaOperation $operation) => $operation->risky && $operation->deferred,
        ));
    }

    public function hasBlockingSchemaChanges(): bool
    {
        return collect($this->schemaOperations)->contains(fn (PlannedSchemaOperation $operation) => $this->isBlockingOperation($operation));
    }

    public function getBlockingSchemaChanges(): array
    {
        return array_values(array_filter(
            $this->schemaOperations,
            fn (PlannedSchemaOperation $operation) => $this->isBlockingOperation($operation),
        ));
    }

    private function isBlockingOperation(PlannedSchemaOperation $operation): bool
    {
        return $operation->action === 'add' && $operation->risky && $operation->deferred;
    }

    public function toPreviewString(): string
    {
        $artifactLines = collect($this->artifacts)
            ->map(fn (PlannedArtifact $artifact) => $artifact->toPreviewLine())
            ->implode("\n\n");

        $schemaLines = collect($this->schemaOperations)
            ->map(fn (PlannedSchemaOperation $operation) => $operation->toPreviewLine())
            ->implode("\n");

        return trim(implode("\n\n", array_filter([
            "Artifacts\n---------\n{$artifactLines}",
            $schemaLines !== '' ? "Schema\n------\n{$schemaLines}" : null,
        ])));
    }

    public function groupedArtifacts(): array
    {
        return [
            'safe' => array_values(array_filter($this->artifacts, fn (PlannedArtifact $artifact) => in_array($artifact->action, ['create', 'merge', 'sync'], true))),
            'risky' => array_values(array_filter($this->artifacts, fn (PlannedArtifact $artifact) => $artifact->action === 'replace')),
            'deferred' => array_values(array_filter($this->artifacts, fn (PlannedArtifact $artifact) => in_array($artifact->action, ['preserve', 'skip'], true))),
        ];
    }

    public function groupedSchemaOperations(): array
    {
        return [
            'safe' => array_values(array_filter($this->schemaOperations, fn (PlannedSchemaOperation $operation) => ! $operation->risky && ! $operation->deferred)),
            'risky' => array_values(array_filter($this->schemaOperations, fn (PlannedSchemaOperation $operation) => $operation->risky && ! $operation->deferred)),
            'deferred' => array_values(array_filter($this->schemaOperations, fn (PlannedSchemaOperation $operation) => $operation->deferred)),
        ];
    }

    public function hasAnyItems(): bool
    {
        return $this->artifacts !== [] || $this->schemaOperations !== [];
    }
}
