{{-- Spec 18/30 — Produktion: Browser-Liste der Produktionsaufträge.
     E4: serverseitig gefiltert + paginiert (schließt MVP-033), Filterbaum mit Zählern,
     Zeitraum-Presets, Spalten-Ansichten, KPI-Zeile.
     E5: das Detail-Panel ist wieder verdrahtet — Zeilen-Klick wählt, der NAME öffnet den
     Editor (Muster: Gerichte-Browser). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Produktion" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-72">
            <div class="p-3 space-y-3">
                <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Name/Anlass suchen …" class="{{ $input }}" data-produktion-suche />

                {{-- Zähler kennen die übrigen aktiven Filter — sonst zeigen sie Treffer an,
                     die die Liste gar nicht liefert (dieselbe Falle wie MVP-048 im VK-Browser). --}}
                <div data-produktion-statusfilter>
                    <x-foodalchemist::filter-row wire:click="waehleStatus('')" :active="$statusFilter === ''" :count="$gesamtCount">
                        <span class="font-medium">Alle Status</span>
                    </x-foodalchemist::filter-row>
                    <x-foodalchemist::filter-ast>
                        @foreach($statusFaelle as $fall)
                            <x-foodalchemist::filter-row level="child" wire:key="pstat-{{ $fall->value }}"
                                wire:click="waehleStatus('{{ $fall->value }}')"
                                :active="$statusFilter === $fall->value"
                                :count="$statusCounts[$fall->value] ?? 0">{{ $fall->label() }}</x-foodalchemist::filter-row>
                        @endforeach
                    </x-foodalchemist::filter-ast>
                </div>

                <div data-produktion-zeitraum>
                    <span class="{{ $label }}">Zeitraum</span>
                    <div class="flex flex-wrap gap-1 mt-1">
                        @foreach($zeitraeume as $key => $lbl)
                            <button type="button" wire:click="waehleZeitraum('{{ $key }}')" wire:key="pz-{{ $key }}"
                                class="{{ $pill }} {{ $zeitraum === $key ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <div>
                            <label class="{{ $label }}">von</label>
                            <input type="date" wire:model.live="von" class="{{ $input }}" />
                        </div>
                        <div>
                            <label class="{{ $label }}">bis</label>
                            <input type="date" wire:model.live="bis" class="{{ $input }}" />
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        {{-- Eigener Store-Scope: sonst teilen sich alle Detail-Panels des Moduls EIN Toggle-Feld. --}}
        <x-foodalchemist::detail-sidebar title="Auftrag" width="w-96" :maxWidth="760"
                                         scope="activity_produktion" side="right">
            <livewire:foodalchemist.produktion.detail-panel :order-id="$orderId" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <livewire:foodalchemist.produktion.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        {{-- KPI-Zeile: beantwortet „wie ist die Lage", NICHT „was habe ich gefiltert" —
             deshalb bewusst unabhängig von den Filtern. Klick springt in den Ausschnitt. --}}
        @php($mut = 'bg-black/5 text-gray-400')
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-produktion-kpi>
            @foreach([
                ['icon' => 'heroicon-o-clipboard-document-list', 'label' => 'Offene Aufträge', 'wert' => $kpiOffen,
                 'hint' => 'Status geplant', 'aktion' => "waehleStatus('planned')",
                 'tint' => $kpiOffen > 0 ? 'bg-violet-500/10 text-violet-600' : $mut],
                ['icon' => 'heroicon-o-calendar-days', 'label' => 'Heute fällig', 'wert' => $kpiHeuteAuftraege,
                 'hint' => 'Liefertag ist heute', 'aktion' => "waehleZeitraum('heute')",
                 'tint' => $kpiHeuteAuftraege > 0 ? 'bg-amber-500/10 text-amber-600' : $mut],
                ['icon' => 'heroicon-o-clock', 'label' => 'Minuten heute', 'wert' => $kpiHeuteMinuten,
                 'hint' => 'geplante Arbeitszeit aller Posten', 'aktion' => null,
                 'tint' => $kpiHeuteMinuten > 0 ? 'bg-sky-500/10 text-sky-600' : $mut],
                ['icon' => 'heroicon-o-funnel', 'label' => 'Treffer', 'wert' => $gesamtCount,
                 'hint' => 'in der aktuellen Filterung', 'aktion' => null, 'tint' => $mut],
            ] as $k)
                <button type="button" @if($k['aktion']) wire:click="{{ $k['aktion'] }}" @endif
                        class="group relative overflow-hidden {{ $card }} px-4 py-3 text-left {{ $k['aktion'] ? 'hover:-translate-y-0.5 hover:shadow-md transition-all duration-150' : 'cursor-default' }}"
                        wire:key="pkpi-{{ $loop->index }}">
                    <div class="{{ $cardAccent }}"></div>
                    <span class="grid place-items-center w-8 h-8 rounded-lg {{ $k['tint'] }}">@svg($k['icon'], 'w-4 h-4')</span>
                    <p class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-gray-900">{{ number_format($k['wert'], 0, ',', '.') }}</p>
                    <p class="text-[11px] font-medium text-gray-700">{{ $k['label'] }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ $k['hint'] }}</p>
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="neuerAuftrag" class="{{ $btnPrimary }}" data-produktion-anlegen>+ Neuer Produktionsauftrag</button>
            <a href="{{ route('foodalchemist.produktion.tagesplan') }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-calendar-days', 'w-3.5 h-3.5') Tagesplan</a>

            <span class="ml-auto flex items-center gap-1" data-produktion-ansichten>
                @foreach($ansichten as $key => $def)
                    <button type="button" wire:click="waehleAnsicht('{{ $key }}')" wire:key="pa-{{ $key }}"
                        class="{{ $pill }} {{ $ansicht === $key ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $def[0] }}</button>
                @endforeach
            </span>
            <select wire:model.live="perPage" class="{{ $input }} !py-1 !w-24" data-produktion-perpage>
                @foreach([25, 50, 100, 250] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
            </select>
        </div>

        <div class="relative overflow-hidden {{ $card }}" data-produktion-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900">Produktionsaufträge</h3>
                <span class="{{ $label }}">{{ number_format($auftraege->total(), 0, ',', '.') }} Treffer</span>
            </div>
            <div class="max-h-[70vh] overflow-auto">
                <table class="{{ $table }}">
                    <thead>
                        <tr class="text-left">
                            <th class="{{ $th }} w-full sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Name</th>
                            {{-- Kopf folgt dem KATALOG, nicht der Ansicht — sonst versetzt sich die Tabelle. --}}
                            @foreach($spaltenKatalog as $sk => $def)
                                @if(in_array($sk, $spalten, true))
                                    <th class="{{ $th }} {{ $def[1] }} whitespace-nowrap w-px sticky top-0 z-20 bg-white/95 backdrop-blur-xl">{{ $def[0] }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php($bedarfPill = ['entwurf' => ['Entwurf', $variantPill['secondary'] ?? ''], 'freigegeben' => ['freigegeben', $variantPill['success'] ?? ''], 'geaendert' => ['geändert', $variantPill['warning'] ?? '']])
                        @forelse($auftraege as $a)
                            @php($aktiv = $a->lines->reject(fn ($l) => (bool) $l->is_struck))
                            @php($ziele = collect($a->targets ?? [])->pluck('label')->filter()->values())
                            <x-foodalchemist::table-row :active="$orderId === $a->id" wire:key="po-{{ $a->id }}"
                                wire:click="waehle({{ $a->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_produktion', 'open', true)"
                                data-produktion-zeile="{{ $a->id }}">
                                <td class="{{ $td }} font-medium text-gray-900 whitespace-nowrap">
                                    <button type="button" wire:click.stop="$dispatch('produktion-editor.bearbeiten', { id: {{ $a->id }} })"
                                            class="text-left hover:text-violet-600" data-produktion-bearbeiten>{{ $a->name ?: $a->reference ?: '—' }}</button>
                                    @if($a->reference && $a->name && $a->reference !== $a->name)<span class="block text-[11px] font-normal text-gray-500">{{ $a->reference }}</span>@endif
                                </td>

                                @foreach($spalten as $sp)
                                    @if($sp === 'ziele')
                                        <td class="{{ $td }} text-gray-600 whitespace-nowrap">
                                            @if($ziele->isEmpty())<span class="text-gray-400">—</span>
                                            @else<span class="text-[12px]">{{ $ziele->take(2)->implode(' · ') }}</span>@if($ziele->count() > 2)<span class="text-[11px] text-gray-400"> +{{ $ziele->count() - 2 }}</span>@endif
                                            @endif
                                        </td>
                                    @elseif($sp === 'ansaetze')
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap text-gray-600">{{ rtrim(rtrim(number_format((float) $aktiv->sum('ansaetze_effektiv'), 2, ',', '.'), '0'), ',') ?: '0' }}</td>
                                    @elseif($sp === 'portionen')
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap text-gray-600">{{ (int) $aktiv->sum('portionen') ?: '—' }}</td>
                                    @elseif($sp === 'zeit')
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap text-gray-600">{{ (int) $aktiv->sum('arbeitszeit_min') ?: '—' }} min</td>
                                    @elseif($sp === 'posten')
                                        @php($postenNamen = $aktiv->pluck('station.name')->filter()->unique())
                                        @php($ohnePosten = $aktiv->whereNull('station_id')->count())
                                        <td class="{{ $td }} text-[11px] text-gray-500 whitespace-nowrap">
                                            {{ $postenNamen->take(2)->implode(' · ') ?: '—' }}@if($ohnePosten > 0)<span class="text-amber-600" title="Zeilen ohne Posten"> · {{ $ohnePosten }} offen</span>@endif
                                        </td>
                                    @elseif($sp === 'datum')
                                        <td class="{{ $td }} whitespace-nowrap tabular-nums">{{ $a->production_date?->format('d.m.Y') }}</td>
                                    @elseif($sp === 'status')
                                        <td class="{{ $td }} whitespace-nowrap"><span class="{{ $pill }} font-medium {{ $variantPill[$a->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $a->status->label() }}</span></td>
                                    @elseif($sp === 'bedarf')
                                        @php($ind = $bedarfPill[$indikatoren[$a->id] ?? 'entwurf'] ?? $bedarfPill['entwurf'])
                                        <td class="{{ $td }} whitespace-nowrap"><span class="{{ $pill }} font-medium {{ $ind[1] }}" data-materialbedarf-indikator="{{ $indikatoren[$a->id] ?? 'entwurf' }}">{{ $ind[0] }}</span></td>
                                    @endif
                                @endforeach
                            </x-foodalchemist::table-row>
                        @empty
                            <tr><td colspan="{{ count($spalten) + 1 }}" class="px-5 py-10 text-center text-gray-500" data-produktion-leer>
                                Kein Auftrag im gewählten Ausschnitt — Filter zurücksetzen oder oben einen neuen anlegen.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($auftraege->hasPages())
                <div class="px-5 py-3 border-t border-black/5" data-produktion-pagination>{{ $auftraege->links() }}</div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
