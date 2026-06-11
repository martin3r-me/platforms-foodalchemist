{{-- GP-Browser — Content custom nach DESIGN.md (Linear/Raycast), Shell-Komponenten bleiben x-ui --}}
{{-- M0-12: alle Dichte-/Klassen-Maps zentral aus Ui::maps() (keine Insellösungen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Grundprodukte" icon="heroicon-o-cube" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">

        {{-- Filter-Card --}}
        <div class="{{ $card }} p-5">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="md:col-span-2">
                    <label class="block {{ $label }} mb-1.5">Suche</label>
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Name oder Hauptzutat-Slug …" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1.5">Warengruppe</label>
                    <select wire:model.live="warengruppe" class="{{ $input }}">
                        <option value="">Alle</option>
                        @foreach($warengruppen as $wg)
                            <option value="{{ $wg->code }}">{{ $wg->code }} {{ $wg->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1.5">Status</label>
                    <select wire:model.live="status" class="{{ $input }}">
                        <option value="">Alle</option>
                        @foreach(\Platform\FoodAlchemist\Enums\GpStatus::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }} ({{ $statusCounts[$case->value] ?? 0 }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Ergebnis-Card --}}
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Grundprodukte</h3>
                <span class="{{ $label }}">{{ number_format($gps->total(), 0, ',', '.') }} Treffer</span>
            </div>
            <table class="{{ $table }}">
                <thead>
                    <tr class="text-left">
                        @foreach(['Name', 'Warengruppe', 'Zustand', 'Status', 'LAs'] as $head)
                            <th class="{{ $th }}">{{ $head }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($gps as $gp)
                        <tr wire:key="gp-{{ $gp->id }}"
                            class="{{ $tr }}">
                            <td class="{{ $td }}">
                                <a href="{{ route('foodalchemist.gps.show', $gp) }}"
                                   class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150">
                                    {{ $gp->name }}
                                </a>
                                @if($gp->is_derivat)
                                    <span class="ml-1.5 {{ $pill }} {{ $variantPill['info'] }}">Derivat</span>
                                @endif
                                @if($gp->is_platzhalter)
                                    <span class="ml-1.5 {{ $pill }} {{ $variantPill['secondary'] }}">Platzhalter</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-gray-500 dark:text-gray-400">{{ $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500 dark:text-gray-400">{{ $gp->zustand ?? '—' }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} font-medium {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">
                                    {{ $gp->status->label() }}
                                </span>
                            </td>
                            <td class="{{ $td }}">
                                @if($gp->n_las_total > 0)
                                    <span class="text-gray-500 dark:text-gray-400">{{ $gp->n_las_total }}</span>
                                @elseif(!$gp->requires_la)
                                    <span class="text-gray-400" title="bewusst LA-frei (requires_la=0)">n/a</span>
                                @else
                                    <span class="{{ $pill }} font-medium {{ $variantPill['warning'] }}"
                                          title="kein Lieferantenartikel verknüpft — EK-/Allergen-Lücke">0</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-gray-400">
                                Keine Grundprodukte gefunden. Import via <code class="text-xs bg-black/5 dark:bg-white/10 rounded px-1.5 py-0.5">php artisan foodalchemist:import-slice</code>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">
                {{ $gps->links() }}
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
