<?php

namespace Lartisan\Architect\Enums;

enum GenerationMode: string
{
    case Create = 'create';
    case Merge = 'merge';
    case Replace = 'replace';

    public static function default(): self
    {
        return self::tryFrom((string) config('architect.default_generation_mode', self::Merge->value)) ?? self::Merge;
    }

    public static function options(): array
    {
        return [
            self::Create->value => __('Create missing only'),
            self::Merge->value => __('Merge into existing files'),
            self::Replace->value => __('Replace generated files'),
        ];
    }

    public function shouldReplaceExistingArtifacts(): bool
    {
        return $this === self::Replace;
    }

    public function shouldMergeExistingArtifacts(): bool
    {
        return $this === self::Merge;
    }
}
