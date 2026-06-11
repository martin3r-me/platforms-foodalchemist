{{-- GP-Browser — Content custom nach DESIGN.md (Linear/Raycast), Shell-Komponenten bleiben x-ui --}}
@php
    // Status → Pill-Klassen (DESIGN.md: soft, frosted, dark-aware)
    $statusPill = [
        'approved'  => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        'tentative' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'rejected'  => 'bg-red-500/10 text-red-600 dark:text-red-400',
        'merged'    => 'bg-black/5 dark:bg-white/10 text-gray-500 dark:text-gray-400',
    ];
    $card = 'rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-white/20 dark:border-white/10 shadow-sm shadow-black/5';
    $input = 'w-full px-3 py-2 text-sm bg-black/[0.03] dark:bg-white/5 rounded-lg border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Grundprodukte" icon="heroicon-o-cube" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">

        {{-- Filter-Card --}}
        <div class="{{ $card }} p-5">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium uppercase tracking-wider text-gray-400 mb-1.5">Suche</label>
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Name oder Hauptzutat-Slug …" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wider text-gray-400 mb-1.5">Warengruppe</label>
                    <select wire:model.live="warengruppe" class="{{ $input }}">
                        <option value="">Alle</option>
                        @foreach($warengruppen as $wg)
                            <option value="{{ $wg->code }}">{{ $wg->code }} {{ $wg->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wider text-gray-400 mb-1.5">Status</label>
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
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Grundprodukte</h3>
                <span class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ number_format($gps->total(), 0, ',', '.') }} Treffer</span>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        @foreach(['Name', 'Warengruppe', 'Zustand', 'Status', 'LAs'] as $head)
                            <th class="px-5 py-2 text-xs font-medium uppercase tracking-wider text-gray-400">{{ $head }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($gps as $gp)
                        <tr wire:key="gp-{{ $gp->id }}"
                            class="border-t border-black/5 dark:border-white/10 hover:bg-gradient-to-r hover:from-violet-500/5 hover:to-indigo-500/5 transition-all duration-150">
                            <td class="px-5 py-2.5">
                                <a href="{{ route('foodalchemist.gps.show', $gp) }}"
                                   class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150">
                                    {{ $gp->name }}
                                </a>
                                @if($gp->is_derivat)
                                    <span class="ml-1.5 inline-flex px-2 py-0.5 rounded-full text-xs bg-sky-500/10 text-sky-600 dark:text-sky-400">Derivat</span>
                                @endif
                                @if($gp->is_platzhalter)
                                    <span class="ml-1.5 inline-flex px-2 py-0.5 rounded-full text-xs bg-black/5 dark:bg-white/10 text-gray-500">Platzhalter</span>
                                @endif
                            </td>
                            <td class="px-5 py-2.5 text-gray-500 dark:text-gray-400">{{ $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-gray-500 dark:text-gray-400">{{ $gp->zustand ?? '—' }}</td>
                            <td class="px-5 py-2.5">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">
                                    {{ $gp->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-2.5">
                                @if($gp->n_las_total > 0)
                                    <span class="text-gray-500 dark:text-gray-400">{{ $gp->n_las_total }}</span>
                                @elseif(!$gp->requires_la)
                                    <span class="text-gray-400" title="bewusst LA-frei (requires_la=0)">n/a</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400"
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
