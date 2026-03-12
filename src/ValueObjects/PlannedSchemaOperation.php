<?php

namespace Lartisan\Architect\ValueObjects;

readonly class PlannedSchemaOperation
{
    public function __construct(
        public string $action,
        public string $description,
        public bool $risky = false,
        public bool $deferred = false,
        public ?string $reason = null,
    ) {}

    public function toPreviewLine(): string
    {
        $tags = [];

        if ($this->risky) {
            $tags[] = 'risky';
        }

        if ($this->deferred) {
            $tags[] = 'deferred';
        }

        $suffix = $tags === [] ? '' : ' ['.implode(', ', $tags).']';
        $reason = $this->reason ? "\n  {$this->reason}" : '';

        return sprintf('- %s: %s%s%s', strtoupper($this->action), $this->description, $suffix, $reason);
    }
}
