<x-filament::callout
        :icon="\Filament\Support\Icons\Heroicon::Sparkles"
        color="primary"
>
    <x-slot name="heading">
        <h2 class="fi-logo text-left!">
            Stop Rewriting. Start Architecting.
        </h2>
    </x-slot>

    <x-slot name="description">
        <div class="text-gray-600 dark:text-gray-400">
            Join the waitlist for <strong class="text-gray-950 dark:text-white underline decoration-primary-500/50">Architect PRO</strong> and lock in a <strong class="text-gray-950 dark:text-white underline decoration-primary-500/50">30%</strong> Early Bird discount for launch day.
        </div>

        <div class="mt-4">
            <x-filament::button
                    href="https://filamentcomponents.com/components/waitlist/architect-pro?source=oss_plugin_callout"
                    target="_blank"
                    tag="a"
                    size="sm"
                    color="primary"
                    variant="outline"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
            >
                Claim My 30% Launch Discount
            </x-filament::button>
        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs font-medium text-gray-500 dark:text-gray-400">
            <div class="flex items-center gap-1.5" style="display: inline-flex; align-items: center; gap: 0.25rem">
                <x-filament::icon icon="heroicon-m-clock" :size="\Filament\Support\Enums\IconSize::Small" />
                <span>Limited to first 100 architects</span>
            </div>
        </div>
    </x-slot>
</x-filament::callout>

