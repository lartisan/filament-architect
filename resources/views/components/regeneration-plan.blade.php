@php
    /** @var \Lartisan\Architect\ValueObjects\RegenerationPlan|null $plan */
    $preview = $plan?->toPreviewString();
@endphp

<div class="space-y-4">
    @if (! $plan?->hasAnyItems())
        <div class="rounded-lg border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ __('Enter table details to preview the regeneration plan...') }}
        </div>
    @else
        @include('architect::components.blocking-schema-warning', ['plan' => $plan])

        <x-architect::code-preview :code="$preview" lang="markdown" />
    @endif
</div>

