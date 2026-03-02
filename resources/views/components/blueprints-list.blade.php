<div class="w-full overflow-x-auto border border-gray-200 rounded-lg dark:border-gray-700">
    <table class="w-full text-left text-sm text-gray-500 dark:text-gray-400">
        <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-800 dark:text-gray-400">
            <tr>
                <th scope="col" class="px-4 py-3 font-medium">Model</th>
                <th scope="col" class="px-4 py-3 font-medium">Table</th>
                <th scope="col" class="px-4 py-3 font-medium">Columns</th>
                <th scope="col" class="px-4 py-3 font-medium">Updated</th>
                <th scope="col" class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
            @forelse($blueprints as $blueprint)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                        {{ $blueprint->model_name }}
                    </td>
                    <td class="px-4 py-3">
                        {{ $blueprint->table_name }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                            {{ count($blueprint->columns ?? []) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs">
                        {{ $blueprint->updated_at->diffForHumans() }}
                    </td>
                    <td class="px-4 py-3 text-right space-x-2">
                        <div class="flex justify-end gap-2">
                            <x-filament::button
                                size="xs"
                                color="primary"
                                wire:click="loadBlueprint({{ $blueprint->id }})"
                            >
                                {{ __('Edit') }}
                            </x-filament::button>

                            <x-filament::button
                                size="xs"
                                color="danger"
                                outlined
                                wire:click="deleteBlueprint({{ $blueprint->id }})"
                                wire:confirm="Are you sure you want to delete this blueprint?"
                            >
                                {{ __('Delete') }}
                            </x-filament::button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                        No blueprints found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>


