<?php

namespace Lartisan\Architect\Enums;

use Illuminate\Container\Container;

enum GenerationMode: string
{
    case Create = 'create';
    case Merge = 'merge';
    case Replace = 'replace';

    public static function default(): self
    {
        $container = Container::getInstance();

        if (! $container instanceof Container || ! $container->bound('config')) {
            return self::Merge;
        }

        $default = $container->make('config')->get('architect.default_generation_mode', self::Merge->value);

        return self::tryFrom((string) $default) ?? self::Merge;
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
