<?php

namespace Lartisan\Architect\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ColumnPreviewTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    /**
     * @var list<array{name: string, type: string, default: mixed, is_nullable: bool, is_unique: bool, is_index: bool, relationship_table: string|null}>
     */
    public array $columns = [];

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect($this->columns)->keyBy('name')->all())
            ->columns([
                TextColumn::make('name')
                    ->label(__('Column'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->color('primary'),
                IconColumn::make('is_nullable')
                    ->label(__('Nullable'))
                    ->boolean(),
                TextColumn::make('default')
                    ->label(__('Default'))
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('extras')
                    ->label(__('Extra'))
                    ->state(fn (array $record): array => array_values(array_filter([
                        ($record['is_unique'] ?? false) ? 'unique' : null,
                        ($record['is_index'] ?? false) ? 'index' : null,
                    ])))
                    ->badge()
                    ->color('warning'),
                TextColumn::make('relationship_table')
                    ->label(__('Foreign Key'))
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono),
            ])
            ->paginated(false);
    }

    public function render(): View
    {
        return view('architect::livewire.column-preview-table');
    }
}
