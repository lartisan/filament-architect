@if (($plan?->getBlockingSchemaChanges() ?? []) !== [])
    @php
        $blockingSchemaOperations = $plan->getBlockingSchemaChanges();
    @endphp

    <x-filament::callout
            :icon="\Filament\Support\Icons\Heroicon::ShieldExclamation"
            color="danger"
    >
        <x-slot name="heading">
            <h2 class="fi-logo text-left!">
                {{ __('Blocking changes') }}
            </h2>
        </x-slot>

        <x-slot name="description">
            <p class="mt-1 text-sm text-warning-900/80 dark:text-warning-100/80">
                {{ __('These schema changes will stop generation until you make them safe. For example, make the new column nullable first, provide a default value, or backfill existing rows before making it required.') }}
            </p>
        </x-slot>

        <x-slot name="footer">
            <ul class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs font-medium text-gray-500 dark:text-gray-400">
            @foreach ($blockingSchemaOperations as $operation)
                <li class="rounded-lg border border-warning-200/80 bg-white/80 px-3 py-2.5 dark:border-warning-800/80 dark:bg-gray-950/40">
                    {{--<div class="fi-logo">{{ $operation->action }}</div>--}}

                    <div class="fi-logo mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $operation->description }}</div>

                    @if ($operation->reason)
                        <div class="mt-1.5 text-xs leading-5 text-gray-700 dark:text-gray-300">{{ $operation->reason }}</div>
                    @endif
                </li>
            @endforeach
            </ul>
        </x-slot>
    </x-filament::callout>
    {{--<section
        role="alert"
        aria-live="polite"
        class="rounded-xl border border-warning-300/80 bg-warning-50/90 p-4 text-sm text-warning-950 shadow-sm dark:border-warning-800 dark:bg-warning-950/30 dark:text-warning-50"
    >
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold tracking-tight">{{ __('Blocking changes') }}</h3>
                        <p class="mt-1 text-sm text-warning-900/80 dark:text-warning-100/80">
                            {{ __('These schema changes will stop generation until you make them safe. For example, make the new column nullable first, provide a default value, or backfill existing rows before making it required.') }}
                        </p>
                    </div>

                    <span class="shrink-0 rounded-full border border-warning-300 bg-white/80 px-2.5 py-1 text-xs font-semibold text-warning-800 dark:border-warning-700 dark:bg-warning-900/50 dark:text-warning-100">
                        {{ trans_choice(':count blocking item|:count blocking items', count($blockingSchemaOperations), ['count' => count($blockingSchemaOperations)]) }}
                    </span>
                </div>

                <ul class="space-y-2">
                    @foreach ($blockingSchemaOperations as $operation)
                        <li class="rounded-lg border border-warning-200/80 bg-white/80 px-3 py-2.5 dark:border-warning-800/80 dark:bg-gray-950/40">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-warning-700 dark:text-warning-300">{{ $operation->action }}</div>
                            <div class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $operation->description }}</div>
                            @if ($operation->reason)
                                <div class="mt-1.5 text-xs leading-5 text-gray-700 dark:text-gray-300">{{ $operation->reason }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>--}}
@endif

