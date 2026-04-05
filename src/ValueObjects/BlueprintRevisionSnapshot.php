<?php

namespace Lartisan\Architect\ValueObjects;

use Lartisan\Architect\Models\BlueprintRevision;

readonly class BlueprintRevisionSnapshot
{
    public const int CURRENT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $snapshot,
        public array $meta = [],
        public int $version = self::CURRENT_VERSION,
    ) {}

    public static function fromRevision(BlueprintRevision $revision): self
    {
        return new self(
            snapshot: $revision->snapshot ?? [],
            meta: $revision->meta ?? [],
            version: (int) ($revision->snapshot_version ?: self::CURRENT_VERSION),
        );
    }

    public function toBlueprintData(): BlueprintData
    {
        return BlueprintData::fromArray($this->snapshot, shouldValidate: false);
    }
}
