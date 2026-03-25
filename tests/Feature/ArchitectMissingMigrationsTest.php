<?php

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Livewire\ArchitectWizard;
use Lartisan\Architect\Tests\TestCase;

uses(TestCase::class);

it('shows a warning instead of throwing when architect migrations are missing', function () {
    Schema::drop('architect_blueprint_revisions');
    Schema::drop('architect_blueprints');

    $component = app(ArchitectWizard::class);
    $component->openArchitect();

    Notification::assertNotified('Architect migrations are missing');

    expect(data_get($component->mountedActions, '0.name'))->toBeNull();
});
