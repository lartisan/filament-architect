@php
    /** @var \Lartisan\Architect\ValueObjects\RegenerationPlan|null $plan */

    $artifactGroups = $plan?->groupedArtifacts() ?? ['safe' => [], 'risky' => [], 'deferred' => []];
    $schemaGroups = $plan?->groupedSchemaOperations() ?? ['safe' => [], 'risky' => [], 'deferred' => []];

    $artifactCount = array_sum(array_map('count', $artifactGroups));
    $schemaCount = array_sum(array_map('count', $schemaGroups));
    $blockingCount = count($plan?->getBlockingSchemaChanges() ?? []);
    $riskySchemaCount = count($plan?->getRiskySchemaChanges() ?? []);

    $artifactSections = [
        'safe' => [
            'heading' => __('Will write / update'),
            'description' => __('Files that Architect can generate, merge, or sync now.'),
            'badge' => 'success',
        ],
        'risky' => [
            'heading' => __('Will replace'),
            'description' => __('Artifacts that will be rewritten in replace mode.'),
            'badge' => 'danger',
        ],
        'deferred' => [
            'heading' => __('Will keep as-is'),
            'description' => __('Existing files that Architect will preserve or skip.'),
            'badge' => 'gray',
        ],
    ];

    $schemaSections = [
        'safe' => [
            'heading' => __('Ready to apply'),
            'description' => __('Schema updates that can be generated safely from the current blueprint.'),
            'badge' => 'success',
        ],
        'risky' => [
            'heading' => __('Requires care'),
            'description' => __('Risky schema changes that are allowed, but deserve extra attention.'),
            'badge' => 'warning',
        ],
        'deferred' => [
            'heading' => __('Awaiting confirmation'),
            'description' => __('Changes that stay deferred until you explicitly allow them or adjust the blueprint.'),
            'badge' => 'gray',
        ],
    ];

    $badgeClasses = [
        'success' => 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-950/40 dark:text-success-300',
        'warning' => 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950/40 dark:text-warning-300',
        'danger' => 'border-danger-200 bg-danger-50 text-danger-700 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300',
        'gray' => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-300',
    ];

    $hasArtifacts = $artifactCount > 0;
    $hasSchema = $schemaCount > 0;
@endphp

<div class="space-y-4">
    @if (! $plan?->hasAnyItems())
        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ __('Enter table details to preview the regeneration summary...') }}
        </div>
    @else
        @include('architect::components.blocking-schema-warning', ['plan' => $plan])

        <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-950/60">
            <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-800 sm:px-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Regeneration summary') }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ __('Review what Architect will write, preserve, sync, or defer before generating files and migrations.') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 text-xs font-medium">
                        <span class="rounded-full border px-2.5 py-1 {{ $badgeClasses['success'] }}">
                            {{ trans_choice(':count artifact|:count artifacts', $artifactCount, ['count' => $artifactCount]) }}
                        </span>
                        <span class="rounded-full border px-2.5 py-1 {{ $badgeClasses[$riskySchemaCount > 0 ? 'warning' : 'gray'] }}">
                            {{ trans_choice(':count schema change|:count schema changes', $schemaCount, ['count' => $schemaCount]) }}
                        </span>
                        @if ($blockingCount > 0)
                            <span class="rounded-full border px-2.5 py-1 {{ $badgeClasses['danger'] }}">
                                {{ trans_choice(':count blocking item|:count blocking items', $blockingCount, ['count' => $blockingCount]) }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid gap-4 p-4 sm:p-5 xl:grid-cols-2">
                <section class="space-y-3">
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Artifacts') }}</h4>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('Generated files and classes that will be created, merged, replaced, or preserved.') }}
                        </p>
                    </div>

                    @if (! $hasArtifacts)
                        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-5 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            {{ __('No artifact actions detected yet.') }}
                        </div>
                    @else
                        @foreach ($artifactSections as $key => $meta)
                            @continue(($artifactGroups[$key] ?? []) === [])

                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h5 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $meta['heading'] }}</h5>
                                        <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $meta['description'] }}</p>
                                    </div>

                                    <span class="shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $badgeClasses[$meta['badge']] }}">
                                        {{ count($artifactGroups[$key]) }}
                                    </span>
                                </div>

                                <ul class="mt-3 space-y-2">
                                    @foreach ($artifactGroups[$key] as $artifact)
                                        <li class="rounded-lg border border-gray-200 bg-white px-3 py-3 dark:border-gray-700 dark:bg-gray-950/60">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $artifact->label }}</div>
                                                    @if ($artifact->details)
                                                        <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $artifact->details }}</div>
                                                    @endif
                                                </div>

                                                <span class="rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $badgeClasses[$meta['badge']] }}">
                                                    {{ $artifact->action }}
                                                </span>
                                            </div>

                                            @if ($artifact->path !== '')
                                                <div class="mt-2 break-all font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $artifact->path }}</div>
                                            @endif

                                            @if ($artifact->reason)
                                                <p class="mt-2 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $artifact->reason }}</p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @endif
                </section>

                <section class="space-y-3">
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Schema changes') }}</h4>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('Database changes detected from the current table, revision baseline, and blueprint state.') }}
                        </p>
                    </div>

                    @if (! $hasSchema)
                        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-5 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            {{ __('No schema changes detected yet.') }}
                        </div>
                    @else
                        @foreach ($schemaSections as $key => $meta)
                            @continue(($schemaGroups[$key] ?? []) === [])

                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h5 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $meta['heading'] }}</h5>
                                        <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $meta['description'] }}</p>
                                    </div>

                                    <span class="shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $badgeClasses[$meta['badge']] }}">
                                        {{ count($schemaGroups[$key]) }}
                                    </span>
                                </div>

                                <ul class="mt-3 space-y-2">
                                    @foreach ($schemaGroups[$key] as $operation)
                                        <li class="rounded-lg border border-gray-200 bg-white px-3 py-3 dark:border-gray-700 dark:bg-gray-950/60">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $operation->description }}</div>
                                                <span class="rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $badgeClasses[$meta['badge']] }}">
                                                    {{ $operation->action }}
                                                </span>
                                            </div>

                                            @if ($operation->reason)
                                                <p class="mt-2 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $operation->reason }}</p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @endif
                </section>
            </div>
        </section>
    @endif
</div>

