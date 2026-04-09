<?php

use Lartisan\Architect\Livewire\ArchitectTrigger;
use Lartisan\Architect\Tests\TestCase;

uses(TestCase::class);

it('reports pro is not installed when the pro service provider class does not exist', function () {
    $trigger = app(ArchitectTrigger::class);

    expect($trigger->isProInstalled())->toBeFalse();
});

it('badge expression evaluates to null when pro is not installed', function () {
    $trigger = app(ArchitectTrigger::class);

    $badge = $trigger->isProInstalled() ? 'BETA' : null;

    expect($badge)->toBeNull();
});
