<?php

namespace Lartisan\Architect\ValueObjects;

readonly class PlannedArtifact
{
    public function __construct(
        public string $label,
        public string $path,
        public string $action,
        public ?string $details = null,
        public ?string $reason = null,
    ) {}

    public function toPreviewLine(): string
    {
        $details = $this->details ? " ({$this->details})" : '';
        $reason = $this->reason ? "\n  {$this->reason}" : '';

        return sprintf('[%s] %s%s%s%s', strtoupper($this->action), $this->label, $details, $this->path !== '' ? "\n  {$this->path}" : '', $reason);
    }
}
