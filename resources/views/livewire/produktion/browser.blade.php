{{-- Spec 18/30 — Produktion: Browser-Liste der Produktionsaufträge.
     E4: serverseitig gefiltert + paginiert (schließt MVP-033), Filterbaum mit Zählern,
     Zeitraum-Presets, Spalten-Ansichten, KPI-Zeile.
     E5: Zeilen-Klick wählt fürs Detail, der NAME öffnet den Editor (Muster: Gerichte-Browser).

     fa-pass (2026-10-05): auf Bausteine <x-fa::…> und --fa-*-Tokens umgestellt. Häufigste Aufgabe:
     sehen, was heute und in den nächsten Tagen produziert wird, und den Auftrag öffnen. Deshalb
     „Neuer Produktionsauftrag" als einzige Hauptaktion im Seitenkopf, Lage als eine schmale
     Kennzahl-Leiste (klickbar → Ausschnitt), die Tabelle darunter. Laptop-tauglich: die Detail-Spalte
     erscheint erst nach Auswahl (vorher nahm der leere Hinweis der Tabelle Breite weg), Namen
     brechen um statt die Tabelle zu verbreitern, die Tabelle scrollt waagerecht statt abzuschneiden.
     Alle wire:-Bindungen, wire:keys, Event-Namen und data-Marker unverändert. --}}
@php
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'success' => 'ok', 'danger' => 'crit', 'warning' => 'warn', 'primary' => 'accent'];
    $bedarfAnzeige = [
        'entwurf' => ['Nicht freigegeben', 'neutral'],
        'freigegeben' => ['Freigegeben', 'ok'],
        'geaendert' => ['Geändert', 'warn'],
    ];
    $filterAktiv = $suche !== '' || $statusFilter !== '' || $zeitraum !== '' || filled($von) || filled($bis);
    $chip = 'inline-flex items-center h-7 px-2.5 rounded-full border text-[length:var(--fa-text-md)] transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]';
    $segment = 'h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] font-medium transition-colors duration-150';
    $segmentAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $kleinLabel = 'px-2.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]';
    $menge = fn ($wert) => rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',') ?: '0';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Produktion" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-72">
            <div class="p-3 flex flex-col gap-3">
                <div class="relative">
                    <label for="produktion-suche" class="sr-only">Aufträge durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="produktion-suche" type="search" wire:model.live.debounce.300ms="suche" placeholder="Name oder Anlass" class="pl-8" data-produktion-suche />
                </div>

                {{-- Zähler kennen die übrigen aktiven Filter — sonst zeigen sie Treffer an,
                     die die Liste gar nicht liefert (dieselbe Falle wie MVP-048 im VK-Browser). --}}
                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]" data-produktion-statusfilter>
                    <p class="{{ $kleinLabel }} pb-1">Status</p>
                    <x-foodalchemist::filter-row wire:click="waehleStatus('')" :active="$statusFilter === ''" :count="$gesamtCount">Alle Status</x-foodalchemist::filter-row>
                    <x-foodalchemist::filter-ast>
                        @foreach($statusFaelle as $fall)
                            <x-foodalchemist::filter-row level="child" wire:key="pstat-{{ $fall->value }}"
                                wire:click="waehleStatus('{{ $fall->value }}')"
                                :active="$statusFilter === $fall->value"
                                :count="$statusCounts[$fall->value] ?? 0">{{ ucfirst($fall->label()) }}</x-foodalchemist::filter-row>
                        @endforeach
                    </x-foodalchemist::filter-ast>
                </div>

                <div class="flex flex-col gap-2 pt-2 border-t border-[var(--fa-line)]" data-produktion-zeitraum>
                    <p class="{{ $kleinLabel }}">Liefertag</p>
                    <div class="flex flex-wrap gap-1.5 px-1" role="group" aria-label="Zeitraum">
                        @foreach($zeitraeume as $key => $lbl)
                            <button type="button" wire:click="waehleZeitraum('{{ $key }}')" wire:key="pz-{{ $key }}"
                                aria-pressed="{{ $zeitraum === $key ? 'true' : 'false' }}"
                                class="{{ $chip }} {{ $zeitraum === $key ? $chipAn : $chipAus }}">{{ ucfirst($lbl) }}</button>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-2 px-1">
                        <x-fa::field label="Von" for="produktion-von">
                            <x-fa::input id="produktion-von" type="date" wire:model.live="von" size="sm" />
                        </x-fa::field>
                        <x-fa::field label="Bis" for="produktion-bis">
                            <x-fa::input id="produktion-bis" type="date" wire:model.live="bis" size="sm" />
                        </x-fa::field>
                    </div>
                </div>

                @if($filterAktiv)
                    <div class="pt-2 border-t border-[var(--fa-line)]">
                        <x-fa::button variant="ghost" size="sm" icon="heroicon-m-x-mark" class="w-full"
                            x-on:click="$wire.set('suche', ''); $wire.waehleStatus(''); $wire.waehleZeitraum('')">Filter zurücksetzen</x-fa::button>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Detail-Spalte erst, wenn ein Auftrag gewählt ist: auf dem Laptop fehlte der Tabelle sonst
         ein Drittel der Breite für einen leeren Hinweis. Eigener Store-Scope, sonst teilen sich alle
         Detail-Panels des Moduls EIN Toggle-Feld. --}}
    @if($orderId !== null)
        <x-slot name="activity">
            <x-foodalchemist::detail-sidebar title="Auftrag" width="w-80" :maxWidth="760"
                                             scope="activity_produktion" side="right">
                <livewire:foodalchemist.produktion.detail-panel :order-id="$orderId" key="produktion-browser--produktion.detail-panel" />
            </x-foodalchemist::detail-sidebar>
        </x-slot>
    @endif

    <livewire:foodalchemist.produktion.editor key="produktion-browser--produktion.editor" />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Produktion"
            :subtitle="number_format($gesamtCount, 0, ',', '.') . ($gesamtCount === 1 ? ' Auftrag' : ' Aufträge') . ($filterAktiv ? ' im Ausschnitt' : '')">
            <x-slot:actions>
                <x-fa::button variant="secondary" icon="heroicon-o-calendar-days" :href="route('foodalchemist.produktion.tagesplan')">Tagesplan öffnen</x-fa::button>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neuerAuftrag" data-produktion-anlegen>Neuer Produktionsauftrag</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        {{-- Kennzahl-Leiste: beantwortet „wie ist die Lage", NICHT „was habe ich gefiltert" —
             deshalb bewusst unabhängig von den Filtern. Klick springt in den Ausschnitt.
             Gleiche Fläche wie x-fa::kpis (Klassen fa-kpis/fa-kpi), aber mit Knöpfen, weil zwei
             Kennzahlen in den passenden Ausschnitt springen. Hauptzahl: heute fällig. --}}
        @php
            $lage = [
                ['label' => 'Heute fällig', 'wert' => $kpiHeuteAuftraege, 'einheit' => $kpiHeuteAuftraege === 1 ? 'Auftrag' : 'Aufträge',
                 'aktion' => "waehleZeitraum('heute')", 'titel' => 'Aufträge mit Liefertag heute zeigen', 'haupt' => true],
                ['label' => 'Offene Aufträge', 'wert' => $kpiOffen, 'einheit' => 'geplant',
                 'aktion' => "waehleStatus('planned')", 'titel' => 'Geplante Aufträge zeigen', 'haupt' => false],
                ['label' => 'Arbeitszeit heute', 'wert' => $kpiHeuteMinuten, 'einheit' => 'min',
                 'aktion' => null, 'titel' => 'Geplante Arbeitszeit aller Posten heute', 'haupt' => false],
            ];
        @endphp
        <div class="fa-kpis" data-fa-kpis data-produktion-kpi>
            @foreach($lage as $k)
                @if($k['aktion'])
                    <button type="button" wire:click="{{ $k['aktion'] }}" wire:key="pkpi-{{ $loop->index }}" title="{{ $k['titel'] }}"
                            class="fa-kpi text-left transition-colors duration-150 hover:bg-[var(--fa-hover)]">
                @else
                    <div class="fa-kpi" wire:key="pkpi-{{ $loop->index }}" title="{{ $k['titel'] }}">
                @endif
                        <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">{{ $k['label'] }}</span>
                        <span class="tabular-nums truncate {{ $k['haupt'] ? 'text-[length:var(--fa-text-2xl)] font-semibold tracking-tight leading-tight text-[var(--fa-accent)]' : 'text-[length:var(--fa-text-lg)] font-semibold leading-snug text-[var(--fa-ink)]' }}">
                            {{ number_format($k['wert'], 0, ',', '.') }}<span class="ml-1 text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)]">{{ $k['einheit'] }}</span>
                        </span>
                @if($k['aktion'])
                    </button>
                @else
                    </div>
                @endif
            @endforeach
        </div>

        <div class="fa-surface overflow-hidden" data-produktion-tabelle>
            <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-3 border-b border-[var(--fa-line)]">
                <h2 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Produktionsaufträge</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)]" role="group" aria-label="Ansicht" data-produktion-ansichten>
                        @foreach($ansichten as $key => $def)
                            <button type="button" wire:click="waehleAnsicht('{{ $key }}')" wire:key="pa-{{ $key }}"
                                aria-pressed="{{ $ansicht === $key ? 'true' : 'false' }}"
                                class="{{ $segment }} {{ $ansicht === $key ? $segmentAn : $segmentAus }}">{{ $def[0] }}</button>
                        @endforeach
                    </div>
                    <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                        Je Seite
                        <x-fa::select wire:model.live="perPage" size="sm" class="w-20" data-produktion-perpage>
                            @foreach([25, 50, 100, 250] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                        </x-fa::select>
                    </label>
                </div>
            </div>

            <div class="max-h-[70vh] overflow-auto">
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-full min-w-[12rem]">Name</th>
                            {{-- Kopf folgt dem KATALOG, nicht der Ansicht — sonst versetzt sich die Tabelle. --}}
                            @foreach($spaltenKatalog as $sk => $def)
                                @if(in_array($sk, $spalten, true))
                                    <th class="{{ str_contains($def[1], 'text-right') ? 'num' : '' }} w-px">{{ $def[0] }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($auftraege as $a)
                            @php
                                $aktiv = $a->lines->reject(fn ($l) => (bool) $l->is_struck);
                                $ziele = collect($a->targets ?? [])->pluck('label')->filter()->values();
                            @endphp
                            <x-foodalchemist::table-row :active="$orderId === $a->id" wire:key="po-{{ $a->id }}"
                                wire:click="waehle({{ $a->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_produktion', 'open', true)"
                                data-produktion-zeile="{{ $a->id }}">
                                <td>
                                    <button type="button" wire:click.stop="$dispatch('produktion-editor.bearbeiten', { id: {{ $a->id }} })"
                                            title="{{ $a->name ?: $a->reference ?: 'Auftrag' }} bearbeiten"
                                            class="text-left font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline" data-produktion-bearbeiten>{{ $a->name ?: $a->reference ?: 'Ohne Namen' }}</button>
                                    @if($a->reference && $a->name && $a->reference !== $a->name)<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $a->reference }}</p>@endif
                                </td>

                                @foreach($spalten as $sp)
                                    @if($sp === 'ziele')
                                        <td class="min-w-[10rem] text-[var(--fa-ink-2)]">
                                            @if($ziele->isEmpty())
                                                <x-fa::signal tone="warn">Keine Ziele</x-fa::signal>
                                            @else
                                                <span class="text-[length:var(--fa-text-sm)]">{{ $ziele->take(2)->implode(' · ') }}</span>@if($ziele->count() > 2)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]"> und {{ $ziele->count() - 2 }} weitere</span>@endif
                                            @endif
                                        </td>
                                    @elseif($sp === 'ansaetze')
                                        <td class="num text-[var(--fa-ink-2)]">{{ $menge($aktiv->sum('ansaetze_effektiv')) }}</td>
                                    @elseif($sp === 'portionen')
                                        <td class="num text-[var(--fa-ink-2)]">{{ (int) $aktiv->sum('portionen') ?: '–' }}</td>
                                    @elseif($sp === 'zeit')
                                        @php
                                            $minuten = (int) $aktiv->sum('arbeitszeit_min');
                                        @endphp
                                        <td class="num text-[var(--fa-ink-2)]">@if($minuten > 0)<x-fa::menge :value="$minuten" unit="min" :decimals="0" />@else<span class="text-[var(--fa-ink-3)]">–</span>@endif</td>
                                    @elseif($sp === 'posten')
                                        @php
                                            $postenNamen = $aktiv->pluck('station.name')->filter()->unique();
                                            $ohnePosten = $aktiv->whereNull('station_id')->count();
                                        @endphp
                                        <td class="min-w-[8rem] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                            <span>{{ $postenNamen->take(2)->implode(' · ') ?: 'Kein Posten' }}</span>
                                            @if($ohnePosten > 0)<div class="mt-0.5"><x-fa::signal tone="warn" title="Positionen ohne Posten">{{ $ohnePosten }} ohne Posten</x-fa::signal></div>@endif
                                        </td>
                                    @elseif($sp === 'datum')
                                        <td class="whitespace-nowrap tabular-nums">
                                            {{ $a->production_date?->format('d.m.Y') }}
                                            @if($a->production_date?->isToday())<x-fa::badge tone="info" class="ml-1">Heute</x-fa::badge>@endif
                                        </td>
                                    @elseif($sp === 'status')
                                        <td class="whitespace-nowrap"><x-fa::badge :tone="$statusTon[$a->status->badgeVariant()] ?? 'neutral'" data-produktion-status-chip="{{ $a->status->value }}">{{ ucfirst($a->status->label()) }}</x-fa::badge></td>
                                    @elseif($sp === 'bedarf')
                                        @php
                                            $indKey = $indikatoren[$a->id] ?? 'entwurf';
                                            $ind = $bedarfAnzeige[$indKey] ?? $bedarfAnzeige['entwurf'];
                                        @endphp
                                        <td class="whitespace-nowrap"><x-fa::badge :tone="$ind[1]" data-materialbedarf-indikator="{{ $indKey }}">{{ $ind[0] }}</x-fa::badge></td>
                                    @endif
                                @endforeach
                            </x-foodalchemist::table-row>
                        @empty
                            <tr><td colspan="{{ count($spalten) + 1 }}" data-produktion-leer>
                                @if($filterAktiv)
                                    <x-fa::empty icon="heroicon-o-clipboard-document-list" title="Kein Auftrag im gewählten Ausschnitt">
                                        Filter zurücksetzen oder die Suche ändern.
                                    </x-fa::empty>
                                @else
                                    <x-fa::empty icon="heroicon-o-clipboard-document-list" title="Noch keine Produktionsaufträge">
                                        Ein Auftrag sammelt, was an einem Liefertag produziert wird. Oben rechts „Neuer Produktionsauftrag“ wählen.
                                    </x-fa::empty>
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($auftraege->hasPages())
                <div class="px-4 py-3 border-t border-[var(--fa-line)]" data-produktion-pagination>{{ $auftraege->links('foodalchemist::components.fa.pagination') }}</div>
            @endif
        </div>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
