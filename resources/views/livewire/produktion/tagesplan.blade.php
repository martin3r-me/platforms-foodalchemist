{{-- Spec 30 E3/E6/E8 — Tagesplan: die Tages-Ausgabe. Was steht an welchem Tag an welchem
     Posten an, über alle Aufträge. Aggregation über ZEILEN (nicht Aufträge): der Auftrag bleibt
     ein Liefertag-Punkt, seine Zeilen dürfen per vorlauf_tage davor liegen.
     3-Panel wie der Produktions-Browser (Sidebar/Center/Detail); Wandmodus blendet die Panels aus. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($istWall = $display === 'wall')

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar :title="$istWall ? 'Küchenmonitor' : 'Tagesordnung Editor'" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion', 'href' => route('foodalchemist.produktion.index')],
            ['label' => $istWall ? 'Küchenmonitor' : 'Tagesordnung Editor'],
        ]">
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-md border border-black/10 p-0.5" data-tagesplan-ansicht>
                    <a href="{{ route('foodalchemist.produktion.tagesplan', array_filter(['von' => $von, 'bis' => $bis, 'tage' => $tage, 'posten' => $postenFilter, 'display' => $display, 'ansicht' => 'posten'])) }}"
                       wire:navigate class="px-2 py-1 text-[11px] rounded {{ $ansicht === 'posten' ? 'bg-violet-600 text-white' : 'text-gray-600' }}">Posten</a>
                    <a href="{{ route('foodalchemist.produktion.tagesplan', array_filter(['von' => $von, 'bis' => $bis, 'tage' => $tage, 'posten' => $postenFilter, 'display' => $display, 'ansicht' => 'gericht'])) }}"
                       wire:navigate class="px-2 py-1 text-[11px] rounded {{ $ansicht === 'gericht' ? 'bg-violet-600 text-white' : 'text-gray-600' }}">Gericht</a>
                </div>
                @unless($istWall)
                    <button type="button" wire:click="vorschlagen" class="{{ $btnAi }}"
                            data-tagesplan-vorschlagen>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Tagesplan vorschlagen</button>
                @endunless
                <a href="{{ route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht])) }}"
                   target="_blank" class="{{ $btnGhostXs }}" data-tagesplan-drucken>Posten-Blatt drucken</a>
                <a href="{{ $istWall ? route('foodalchemist.produktion.tagesplan', ['von' => $von, 'tage' => 14, 'ansicht' => $ansicht]) : route('foodalchemist.produktion.wandmonitor', ['von' => $von, 'ansicht' => $ansicht]) }}"
                   wire:navigate class="{{ $btnGhostXs }}" data-tagesplan-wall-toggle>{{ $istWall ? 'Normalansicht' : 'Wandmodus' }}</a>
            </div>
        </x-ui-page-actionbar>
    </x-slot>

    @unless($istWall)
        <x-slot name="sidebar">
            <x-ui-page-sidebar title="Editor-Werkzeuge" width="w-72">
                <div class="p-3 space-y-4" data-tagesplan-steuerung data-tagesordnung-editor-tools>
                    <div>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="verschiebe(-7)" class="{{ $btnGhostXs }}">‹ Woche</button>
                            <button type="button" wire:click="heute" class="{{ $btnGhostXs }}" data-tagesplan-heute>heute</button>
                            <button type="button" wire:click="verschiebe(7)" class="{{ $btnGhostXs }}">Woche ›</button>
                        </div>
                        <div class="text-[11px] text-gray-500 mt-1">
                            {{ \Illuminate\Support\Carbon::parse($von)->format('d.m.') }} – {{ \Illuminate\Support\Carbon::parse($bis)->format('d.m.Y') }}
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2" data-tagesordnung-editor-zeitraum>
                        <label class="block">
                            <span class="{{ $label }}">von</span>
                            <input type="date" wire:model.live="von" class="{{ $input }} mt-1" />
                        </label>
                        <label class="block">
                            <span class="{{ $label }}">bis</span>
                            <input type="date" wire:model.live="bis" class="{{ $input }} mt-1" />
                        </label>
                    </div>
                    <div>
                        <label class="{{ $label }}">Schnellfenster</label>
                        <select wire:model.live="tage" class="{{ $input }} mt-1" data-tagesplan-fenster>
                            @foreach([1 => '1 Tag', 3 => '3 Tage', 7 => '1 Woche', 14 => '2 Wochen', 28 => '4 Wochen', 30 => 'Monat'] as $n => $lbl)
                                <option value="{{ $n }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($postenListe->isNotEmpty())
                        <div data-tagesplan-postenfilter>
                            <span class="{{ $label }}">Posten</span>
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach($postenListe as $p)
                                    <button type="button" wire:click="postenWaehlen({{ $p->id }})" wire:key="tpf-{{ $p->id }}"
                                            class="{{ $pill }} {{ $postenFilter === $p->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $p->name }}</button>
                                @endforeach
                            </div>
                            @if($postenFilter !== null)
                                <button type="button" wire:click="postenWaehlen(null)" class="{{ $btnGhostXs }} mt-1">Filter zurücksetzen</button>
                            @endif
                        </div>
                    @else
                        <x-foodalchemist::alert tone="info" data-tagesplan-keine-posten>
                            Noch keine Posten angelegt. Der Tagesplan zeigt dann nur unverplante Arbeit —
                            Posten pflegst du unter <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'posten']) }}" class="underline">Einstellungen → Posten &amp; Kapazität</a>.
                        </x-foodalchemist::alert>
                    @endif
                </div>
            </x-ui-page-sidebar>
        </x-slot>

        <x-slot name="activity">
            {{-- Eigener Store-Scope, sonst teilen sich alle Detail-Panels des Moduls EIN Toggle-Feld. --}}
            <x-foodalchemist::detail-sidebar title="Auftrag" width="w-96" :maxWidth="760"
                                             scope="activity_tagesplan" side="right">
                <livewire:foodalchemist.produktion.detail-panel :order-id="$orderId" />
            </x-foodalchemist::detail-sidebar>
        </x-slot>
    @endunless

    @unless($istWall)
        <livewire:foodalchemist.produktion.editor />
    @endunless

    <x-ui-page-container :padding="$istWall ? 'px-3 lg:px-5 pb-5' : 'px-6 pb-6'" spacing="space-y-4">

        @if($fehler)<x-foodalchemist::alert tone="danger" data-tagesplan-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif

        @if($istWall)
            <section class="rounded-lg border border-slate-200 bg-slate-950 text-white px-4 py-4 lg:px-6" data-tagesplan-wall-root>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-slate-300">Küchenmonitor</div>
                        <h1 class="text-2xl lg:text-3xl font-semibold">{{ \Illuminate\Support\Carbon::parse($von)->format('d.m.') }} – {{ \Illuminate\Support\Carbon::parse($bis)->format('d.m.Y') }}</h1>
                    </div>
                    <div class="flex flex-wrap items-center gap-2" data-tagesplan-steuerung>
                        <button type="button" wire:click="verschiebe(-1)" class="min-h-11 rounded-md border border-white/15 px-4 text-sm text-white/90">‹ Tag</button>
                        <button type="button" wire:click="heute" class="min-h-11 rounded-md bg-white px-4 text-sm font-medium text-slate-950" data-tagesplan-heute>Heute</button>
                        <button type="button" wire:click="verschiebe(1)" class="min-h-11 rounded-md border border-white/15 px-4 text-sm text-white/90">Tag ›</button>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-3 md:grid-cols-6 gap-2" data-tagesplan-kennzahlen>
                    @foreach(['offen' => 'Offen', 'laeuft' => 'Läuft', 'erledigt' => 'Fertig', 'blockiert' => 'Blocker', 'minuten_offen' => 'Min offen', 'ueberlast_tage' => 'Überlast'] as $key => $label)
                        <div class="rounded-md bg-white/10 px-3 py-2">
                            <div class="text-[10px] uppercase tracking-wide text-slate-300">{{ $label }}</div>
                            <div class="text-2xl font-semibold tabular-nums">{{ $kennzahlen[$key] }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            @if($klaerfaelle !== [])
                <section class="rounded-lg border {{ $readinessSplit['blockers'] !== [] ? 'border-red-300 bg-red-50' : 'border-amber-300 bg-amber-50' }} px-4 py-3" data-tagesplan-klaerfaelle>
                    <div class="flex flex-wrap items-center gap-2">
                        <strong class="text-sm {{ $readinessSplit['blockers'] !== [] ? 'text-red-900' : 'text-amber-900' }}">Klärfälle</strong>
                        @foreach($klaerfaelle as $fall)
                            <span class="{{ $pill }} {{ $fall['level'] === 'blocker' ? $variantPill['danger'] : ($variantPill['warning'] ?? $variantPill['secondary']) }}">{{ $fall['count'] }} {{ $fall['label'] }}</span>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($alsNaechstes->isNotEmpty())
                <section class="grid xl:grid-cols-3 gap-3" data-tagesplan-wall-lanes>
                    @foreach($alsNaechstes->groupBy(fn ($z) => $z->station ?: 'Nicht zugeteilt') as $stationName => $lane)
                        <div class="rounded-lg border border-black/10 bg-white overflow-hidden" data-tagesplan-wall-lane>
                            <div class="flex items-center justify-between gap-2 border-b border-black/5 bg-slate-50 px-4 py-3">
                                <h2 class="text-base font-semibold text-slate-900 truncate">{{ $stationName }}</h2>
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $lane->count() }} offen</span>
                            </div>
                            <div class="divide-y divide-black/5">
                                @foreach($lane->take(6) as $z)
                                    @php($laeuft = $z->auftrag_status === 'in_progress')
                                    <article class="p-4 {{ $z->blocked_reason ? 'bg-amber-50' : '' }}" data-tagesplan-wall-card="{{ $z->id }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <button type="button" wire:click="oeffneAnleitung({{ $z->id }})" class="min-w-0 text-left">
                                                <strong class="block text-lg leading-snug text-slate-950">{{ $z->name }}</strong>
                                                <span class="block mt-1 text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($z->plan_date)->format('d.m.') }} · {{ $z->auftrag }} · für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</span>
                                            </button>
                                            <span class="shrink-0 rounded-md bg-slate-100 px-2 py-1 text-sm tabular-nums text-slate-700">{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }}</span>
                                        </div>
                                        @if($z->blocked_reason)
                                            <div class="mt-2 rounded-md border border-amber-200 bg-amber-100 px-2 py-1 text-sm text-amber-900">Blockiert: {{ $z->blocked_reason }}{{ $z->blocked_note ? ' · ' . $z->blocked_note : '' }}</div>
                                        @endif
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if($laeuft && $z->line_status === 'open')
                                                <button type="button" wire:click="zeileStarten({{ $z->id }})" class="min-h-11 rounded-md bg-sky-600 px-4 text-sm font-medium text-white">Start</button>
                                            @endif
                                            @if($laeuft)
                                                <button type="button" wire:click="abhaken({{ $z->id }})" class="min-h-11 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white" data-tagesplan-wall-done>Erledigt</button>
                                                @if($z->blocked_reason)
                                                    <button type="button" wire:click="entblocken({{ $z->id }})" class="min-h-11 rounded-md border border-emerald-200 px-4 text-sm font-medium text-emerald-700" data-tagesplan-entblocken>Entblocken</button>
                                                @else
                                                    <button type="button" wire:click="grundDialog({{ $z->id }}, 'block')" class="min-h-11 rounded-md border border-amber-200 px-4 text-sm font-medium text-amber-700">Blocker</button>
                                                @endif
                                            @else
                                                <button type="button" wire:click="produktionStarten({{ $z->order_id }})" class="min-h-11 rounded-md bg-slate-900 px-4 text-sm font-medium text-white" data-produktion-starten>Auftrag starten</button>
                                            @endif
                                            <button type="button" wire:click="oeffneAnleitung({{ $z->id }})" class="min-h-11 rounded-md border border-black/10 px-4 text-sm font-medium text-slate-700">Anleitung</button>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>
            @else
                <div class="rounded-lg border border-black/5 bg-white px-4 py-12 text-center text-sm text-gray-500" data-tagesplan-leer>
                    Keine offenen Positionen im Monitor-Fenster.
                </div>
            @endif

            @if($letzteAenderungen->isNotEmpty())
                <section class="rounded-lg border border-black/5 bg-white px-4 py-3" data-tagesplan-aenderungen>
                    <h3 class="text-sm font-semibold mb-2">Letzte Änderungen</h3>
                    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-x-4">
                        @foreach($letzteAenderungen as $event)
                            <div class="flex justify-between gap-3 py-1 text-xs border-b border-black/5">
                                <span class="truncate">{{ $event->rezept ?: $event->titel ?: $event->auftrag }} · {{ str_replace('_', ' ', $event->event_type) }}</span>
                                <span class="text-gray-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('H:i') }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @else
        <section class="rounded-2xl border border-white/10 bg-slate-950 p-4 shadow-xl shadow-black/10 text-white" data-tagesordnung-editor>
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <p class="text-[10px] font-medium uppercase tracking-wider text-slate-400">Tagesordnung Editor</p>
                    <h1 class="text-xl font-semibold tracking-tight">Produktions-Tagesplanung</h1>
                    <p class="text-sm text-slate-400 mt-1">
                        {{ \Illuminate\Support\Carbon::parse($von)->format('d.m.Y') }}
                        – {{ \Illuminate\Support\Carbon::parse($bis)->format('d.m.Y') }}
                        · {{ $ansicht === 'gericht' ? 'Gerichtssicht' : 'Postensicht' }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="vorschlagen" class="{{ $btnAi }}"
                            data-tagesordnung-editor-vorschlagen>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Vorschlag rechnen</button>
                    <a href="{{ route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'bis' => $bis, 'tage' => $tage, 'posten' => $postenFilter, 'ansicht' => $ansicht])) }}"
                       target="_blank" class="{{ $btnGhostXs }} !text-slate-200 !border-white/10" data-tagesordnung-editor-drucken>Blatt drucken</a>
                </div>
            </div>

        <div class="grid grid-cols-2 md:grid-cols-5 xl:grid-cols-7 gap-2" data-tagesplan-kennzahlen data-tagesordnung-editor-kpis>
            @foreach(['offen' => 'Offen', 'laeuft' => 'Läuft', 'erledigt' => 'Erledigt', 'uebersprungen' => 'Übersprungen', 'blockiert' => 'Blockiert'] as $key => $label)
                <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2"><div class="text-[10px] uppercase tracking-wide text-slate-400">{{ $label }}</div><div class="text-xl font-semibold tabular-nums text-white">{{ $kennzahlen[$key] }}</div></div>
            @endforeach
            <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2"><div class="text-[10px] uppercase tracking-wide text-slate-400">Min offen</div><div class="text-xl font-semibold tabular-nums text-white">{{ $kennzahlen['minuten_offen'] }}</div></div>
            <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2"><div class="text-[10px] uppercase tracking-wide text-slate-400">Manntage</div><div class="text-xl font-semibold tabular-nums text-white">{{ number_format($kennzahlen['manntage'], 1, ',', '.') }}</div></div>
        </div>

        @if($klaerfaelle !== [])
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3" data-tagesplan-klaerfaelle>
                <div class="text-xs font-semibold text-amber-900 mb-2">Vor Produktionsstart klären</div>
                <div class="flex flex-wrap gap-2">
                    @foreach($klaerfaelle as $fall)
                        <span class="{{ $pill }} {{ $fall['level'] === 'blocker' ? $variantPill['danger'] : ($variantPill['warning'] ?? $variantPill['secondary']) }}">{{ $fall['count'] }} {{ $fall['label'] }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Stufe 3 P3.4 — Planungs-Vorschlag zum Review (nicht-destruktiv, erst „Übernehmen" schreibt). --}}
        @if($vorschlag !== null)
            <div class="rounded-lg border border-violet-300 bg-violet-50/60 px-4 py-3" data-tagesplan-vorschlag>
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <div class="text-sm font-semibold text-violet-900 inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-4 h-4') Planungs-Vorschlag</div>
                    <div class="flex items-center gap-2">
                        <span class="text-[12px] text-gray-600">
                            {{ $vorschlag['aenderungen'] }} Änderungen · Arbeitskosten ~{{ number_format($vorschlag['kosten']['gesamt_eur'], 2, ',', '.') }} €
                            @if(count($vorschlag['nicht_zugeteilt'])) · <span class="text-amber-600">{{ count($vorschlag['nicht_zugeteilt']) }} ohne Default-Posten</span>@endif
                        </span>
                        <button type="button" wire:click="vorschlagUebernehmen" class="{{ $btnPrimary }} !py-1" data-vorschlag-uebernehmen>Übernehmen</button>
                        <button type="button" wire:click="vorschlagVerwerfen" class="{{ $btnGhostXs }}" data-vorschlag-verwerfen>Verwerfen</button>
                    </div>
                </div>
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} w-full">Position</th>
                            <th class="{{ $th }}">Posten</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">Vorlauf</th>
                            <th class="{{ $th }} whitespace-nowrap">Tag</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">min</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($vorschlag['vorschlag'] as $v)
                            <tr class="{{ $tr }}" wire:key="vs-{{ $v['line_id'] }}" data-vorschlag-zeile="{{ $v['line_id'] }}">
                                <td class="{{ $td }}">{{ $v['rezept'] }} <span class="text-[10px] text-gray-500">· {{ $v['auftrag'] }}</span></td>
                                <td class="{{ $td }}">{{ $v['station'] }}</td>
                                <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">{{ $v['vorlauf_tage'] > 0 ? '−' . $v['vorlauf_tage'] . ' T' : '—' }}</td>
                                <td class="{{ $td }} whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($v['plan_date'])->format('d.m.') }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $v['arbeitszeit_min'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                        @foreach($vorschlag['nicht_zugeteilt'] as $v)
                            <tr class="{{ $tr }} opacity-70" wire:key="vsn-{{ $v['line_id'] }}">
                                <td class="{{ $td }}">{{ $v['rezept'] }} <span class="text-[10px] text-gray-500">· {{ $v['auftrag'] }}</span></td>
                                <td class="{{ $td }} text-amber-600 text-[11px]" title="Rezept hat keinen Default-Posten — bitte manuell zuteilen">kein Default-Posten</td>
                                <td class="{{ $td }}"></td>
                                <td class="{{ $td }} whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($v['plan_date'])->format('d.m.') }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $v['arbeitszeit_min'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @forelse($zeilenNachTag as $tag => $zeilen)
            @php($tagC = \Illuminate\Support\Carbon::parse($tag))
            @php($nachPosten = $zeilen->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id))
            @php($fertig = $zeilen->filter(fn ($z) => $z->line_status === 'done')->count())
            <div class="{{ $card }} px-4 py-3 {{ $istWall ? 'text-[15px]' : '' }}" wire:key="tp-{{ $tag }}" data-tagesplan-tag="{{ $tag }}">
                <div class="flex items-baseline gap-2 mb-3">
                    <h3 class="{{ $istWall ? 'text-lg' : 'text-sm' }} font-semibold text-gray-900">{{ $tagC->locale('de')->isoFormat('dd DD.MM.') }}</h3>
                    @if($tagC->isToday())<span class="{{ $pill }} {{ $variantPill['primary'] }}">heute</span>@endif
                    <span class="text-[11px] text-gray-500">{{ $zeilen->count() }} Positionen</span>
                    @if($fertig > 0)
                        <span class="{{ $pill }} {{ $fertig === $zeilen->count() ? $variantPill['success'] : $variantPill['info'] }}" data-tagesplan-fortschritt>
                            {{ $fertig }}/{{ $zeilen->count() }} erledigt
                        </span>
                    @endif
                </div>

                @if($ansicht === 'gericht')
                    @foreach($zeilen->groupBy('order_id') as $auftragZeilen)
                        <section class="mb-4 last:mb-0" data-tagesplan-auftrag-gruppe="{{ $auftragZeilen->first()->order_id }}">
                            <div class="flex items-center justify-between border-b border-black/5 pb-1 mb-1">
                                <div><strong class="text-xs">{{ $auftragZeilen->first()->auftrag }}</strong>
                                    <span class="text-[10px] text-gray-500">· für {{ \Illuminate\Support\Carbon::parse($auftragZeilen->first()->liefertag)->format('d.m.') }}</span></div>
                                @if($auftragZeilen->first()->auftrag_status === 'planned')
                                    <button type="button" wire:click="produktionStarten({{ $auftragZeilen->first()->order_id }})" class="{{ $btnGhostXs }} text-emerald-700" data-produktion-starten>Produktion starten</button>
                                @elseif($auftragZeilen->first()->auftrag_status === 'in_progress')
                                    <button type="button" wire:click="fertigDialog({{ $auftragZeilen->first()->order_id }})" class="{{ $btnGhostXs }} text-emerald-700" data-produktion-fertig>Fertig melden</button>
                                @endif
                            </div>
                            @foreach($auftragZeilen->sortBy(['tiefe', 'position']) as $z)
                                <button type="button" wire:click="oeffneAnleitung({{ $z->id }})"
                                        class="flex w-full items-center gap-3 py-1.5 text-left hover:bg-violet-50 rounded px-2"
                                        style="padding-left: {{ 8 + min(3, (int) $z->tiefe) * 18 }}px" data-tagesplan-anleitung="{{ $z->id }}">
                                    <span class="flex-1 text-xs {{ $z->line_status === 'done' ? 'line-through opacity-60' : '' }}">{{ $z->name }}</span>
                                    <span class="text-[10px] text-gray-500">{{ $z->station ?: 'Nicht zugeteilt' }}</span>
                                    <span class="text-[10px] tabular-nums">{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'ohne Zeit' }}</span>
                                </button>
                            @endforeach
                        </section>
                    @endforeach
                @else
                {{-- Ein Block je Posten: Auslastungs-Kopf + die Zeilen dieses Postens.
                     auslastung[$tag] enthält jeden Posten inkl. „Nicht zugeteilt" (station_id null). --}}
                @foreach($auslastung[$tag] ?? [] as $b)
                    @php($schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'])
                    @php($blockZeilen = $nachPosten[$schluessel] ?? collect())
                    @continue($blockZeilen->isEmpty())
                    @php($tone = ['ueberlast' => 'danger', 'eng' => 'warning', 'ok' => 'success'][$b['stufe']] ?? 'neutral')
                    <div class="mb-4 last:mb-0" wire:key="tpb-{{ $tag }}-{{ $schluessel }}">
                        <div class="flex items-center gap-2 mb-1 pb-1 border-b border-black/5" data-tagesplan-auslastung>
                            <span class="text-xs font-semibold {{ $b['station_id'] === null ? 'text-gray-500 italic' : 'text-gray-800' }} w-40 shrink-0 truncate">{{ $b['station'] }}</span>
                            <span class="text-[11px] tabular-nums text-gray-600 w-28 shrink-0">
                                {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) / {{ $b['kapazitaet_min'] }}@endif min
                            </span>
                            <span class="flex-1 min-w-0">
                                @if($b['kapazitaet_min'] !== null)
                                    <x-foodalchemist::meter :value="min(100, $b['prozent'])" :max="100" :tone="$tone" :ticks="[85]" />
                                @endif
                            </span>
                            @if($b['stufe'] === 'ueberlast')
                                <span class="{{ $pill }} {{ $variantPill['danger'] }} shrink-0">{{ $b['prozent'] }} % Überlast</span>
                            @elseif($b['stufe'] === 'eng')
                                <span class="{{ $pill }} {{ $variantPill['warning'] ?? $variantPill['secondary'] }} shrink-0">{{ $b['prozent'] }} %</span>
                            @endif
                            @if($b['ohne_zeit'] > 0)
                                <span class="text-[10px] text-amber-600 shrink-0" title="Diesen Zeilen fehlt die Arbeitszeit am Rezept — die Summe ist unvollständig.">{{ $b['ohne_zeit'] }} ohne Zeit</span>
                            @endif
                        </div>

                        <table class="{{ $table }}">
                            <tbody>
                                @foreach($blockZeilen as $z)
                                    @php($erledigt = $z->line_status === 'done')
                                    @php($laeuft = $z->auftrag_status === 'in_progress')
                                    <tr class="{{ $tr }} {{ $erledigt ? 'opacity-60' : '' }} {{ $orderId === $z->order_id ? 'bg-violet-50' : '' }}"
                                        wire:key="tpz-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}">
                                        <td class="{{ $td }} w-px">
                                            {{-- Abhaken geht erst, wenn die Produktion läuft: vorher ist nichts
                                                 produziert, und ein Recompute könnte die Zeile ersetzen. --}}
                                            @if($laeuft)
                                                <button type="button" wire:click="abhaken({{ $z->id }})"
                                                        class="w-5 h-5 rounded border {{ $erledigt ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-black/20 hover:border-violet-400' }} text-[11px] leading-none"
                                                        title="{{ $erledigt ? 'Haken zurücknehmen' : 'als erledigt abhaken' }}"
                                                        data-tagesplan-abhaken>{{ $erledigt ? '✓' : '' }}</button>
                                            @else
                                                <span class="inline-block w-5 h-5 rounded border border-dashed border-black/10" title="Auftrag läuft noch nicht — abgehakt wird erst ab «in Arbeit»."></span>
                                            @endif
                                        </td>
                                        <td class="{{ $td }} {{ $erledigt ? 'line-through' : '' }}">
                                            {{-- Klick wählt den Auftrag ins Detail-Panel (3-Panel-Muster). --}}
                                            <button type="button" wire:click="oeffneAnleitung({{ $z->id }})" class="text-left hover:text-violet-700" data-tagesplan-select>
                                                {{ $z->name }}
                                            </button>
                                            @if($z->assignee)<span class="text-[11px] text-gray-500 ml-1">· {{ $z->assignee }}</span>@endif
                                        </td>
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">
                                            {{ rtrim(rtrim(number_format($z->ansaetze_effektiv, 2, ',', '.'), '0'), ',') }} Ans.
                                        </td>
                                        <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">
                                            {{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }}
                                        </td>
                                        <td class="{{ $td }} whitespace-nowrap">
                                            {{-- Auftrag + Liefertag als Kontext: sonst weiß am Posten niemand, wofür das ist. --}}
                                            <button type="button" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $z->order_id }} })"
                                                    class="text-[11px] text-sky-600 hover:underline" data-tagesplan-auftrag>
                                                {{ $z->auftrag }}
                                            </button>
                                            <span class="text-[10px] text-gray-500 ml-1">für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</span>
                                        </td>
                                        <td class="{{ $td }} text-right whitespace-nowrap w-px">
                                            @if($laeuft && $z->line_status === 'open')
                                                <button type="button" wire:click="zeileStarten({{ $z->id }})" class="{{ $btnGhostXs }} text-sky-700" title="Tätigkeit starten">Start</button>
                                            @endif
                                            @if($laeuft && $z->blocked_reason)
                                                <button type="button" wire:click="entblocken({{ $z->id }})" class="{{ $btnGhostXs }} text-emerald-700" title="Blocker lösen" data-tagesplan-entblocken>Entblocken</button>
                                            @endif
                                            @if($laeuft && !in_array($z->line_status, ['done', 'skipped'], true))
                                                <button type="button" wire:click="grundDialog({{ $z->id }}, 'block')" class="{{ $btnGhostXs }} text-amber-700" title="Blocker erfassen">Blocker</button>
                                                <button type="button" wire:click="grundDialog({{ $z->id }}, 'skip')" class="{{ $btnGhostXs }} text-gray-500" title="Mit Grund überspringen">Überspringen</button>
                                            @endif
                                            <input type="text" inputmode="numeric" value="{{ $z->vorlauf_tage }}"
                                                   wire:change="vorlaufSetzen({{ $z->id }}, $event.target.value)"
                                                   class="{{ $input }} !py-0.5 !w-14 text-right tabular-nums"
                                                   title="Tage Vorlauf vor dem Liefertag" data-tagesplan-vorlauf />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
                @endif
            </div>
        @empty
            <div class="{{ $card }} px-4 py-10 text-center text-xs text-gray-500" data-tagesplan-leer>
                In diesem Zeitraum steht nichts an.<br>
                Der Tagesplan zeigt Zeilen aus <strong>geplanten und laufenden</strong> Aufträgen — Erledigtes und Storniertes belegt nichts.
            </div>
        @endforelse

        </section>

        @endif

    </x-ui-page-container>

    <x-foodalchemist::modal name="tagesplan-anleitung" title="Zubereitung" size="max-w-3xl">
        @if($anleitungZeile)
            <div data-tagesplan-anleitung-modal>
                <h3 class="text-base font-semibold mb-1">{{ $anleitungZeile->name }}</h3>
                <p class="text-xs text-gray-500 mb-4">{{ $anleitungZeile->auftrag }} · {{ $anleitungZeile->station ?: 'Nicht zugeteilt' }}</p>
                @if(!empty($anleitungZeile->zutaten))
                    <div class="text-xs mb-4"><strong>Zutaten:</strong>
                        {{ collect($anleitungZeile->zutaten)->map(fn ($z) => trim(($z['menge'] ?? '') . ' ' . ($z['einheit'] ?? '') . ' ' . ($z['name'] ?? '')))->implode(' · ') }}
                    </div>
                @endif
                @if(!empty($anleitungZeile->equipment) && collect($anleitungZeile->equipment)->isNotEmpty())
                    <div class="text-xs mb-4" data-tagesplan-equipment><strong>Equipment:</strong>
                        {{ collect($anleitungZeile->equipment)->map(fn ($g) => trim(($g->name ?? '') . (($g->note ?? null) ? ' (' . $g->note . ')' : '')))->filter()->implode(' · ') }}
                    </div>
                @endif
                @if(!empty($anleitungZeile->schritte))
                    <ol class="space-y-2">
                        @foreach($anleitungZeile->schritte as $s)
                            <li class="flex gap-3 text-sm"><span class="grid place-items-center shrink-0 w-6 h-6 rounded-full bg-violet-100 text-violet-700">{{ $s['nr'] ?? $loop->iteration }}</span><span>{{ $s['text'] ?? '' }}</span></li>
                        @endforeach
                    </ol>
                @elseif(!empty($anleitungZeile->zubereitung))
                    <p class="text-sm whitespace-pre-line">{{ $anleitungZeile->zubereitung }}</p>
                @else
                    <x-foodalchemist::alert tone="warning">Keine Anleitung hinterlegt.</x-foodalchemist::alert>
                @endif
            </div>
        @endif
    </x-foodalchemist::modal>

    <x-foodalchemist::modal name="tagesplan-grund" title="{{ $grundModus === 'skip' ? 'Tätigkeit überspringen' : 'Blocker erfassen' }}" size="max-w-lg">
        <div class="space-y-3" data-tagesplan-grund-dialog>
            <label class="block"><span class="{{ $label }}">Grund</span>
                <select wire:model="grundCode" class="{{ $input }} mt-1">
                    <option value="">Bitte wählen</option>
                    <option value="material_fehlt">Material fehlt</option>
                    <option value="equipment">Gerät/Posten nicht verfügbar</option>
                    <option value="prioritaet">Zeit/Priorität</option>
                    <option value="nicht_noetig">Nicht mehr benötigt</option>
                    <option value="sonstiges">Sonstiges</option>
                </select>
            </label>
            <label class="block"><span class="{{ $label }}">Notiz (optional)</span><textarea wire:model="grundNotiz" class="{{ $input }} mt-1" rows="3"></textarea></label>
            <div class="flex justify-end"><button type="button" wire:click="grundSpeichern" class="{{ $btnPrimary }}">Speichern</button></div>
        </div>
    </x-foodalchemist::modal>

    <x-foodalchemist::modal name="tagesplan-start" title="Produktion mit Warnungen starten" size="max-w-lg">
        <div class="space-y-3" data-tagesplan-start-dialog>
            <x-foodalchemist::alert tone="warning">Es gibt noch Warnungen. Der Start ist möglich, braucht aber einen nachvollziehbaren Grund.</x-foodalchemist::alert>
            <div class="flex flex-wrap gap-2">
                @foreach($startWarnings as $fall)
                    <span class="{{ $pill }} {{ $variantPill['warning'] ?? $variantPill['secondary'] }}">{{ $fall['count'] }} {{ $fall['label'] }}</span>
                @endforeach
            </div>
            <label class="block"><span class="{{ $label }}">Override-Grund</span><textarea wire:model="startOverrideReason" class="{{ $input }} mt-1" rows="3"></textarea></label>
            <div class="flex justify-end"><button type="button" wire:click="produktionStartenBestaetigen" class="{{ $btnPrimary }}">Trotzdem starten</button></div>
        </div>
    </x-foodalchemist::modal>

    <x-foodalchemist::modal name="tagesplan-fertig" title="Auftrag fertig melden" size="max-w-lg">
        <div class="space-y-3" data-tagesplan-fertig-dialog>
            @if(($finishSummary['offen'] ?? 0) > 0 || ($finishSummary['blockiert'] ?? 0) > 0)
                <x-foodalchemist::alert tone="warning">
                    {{ $finishSummary['offen'] ?? 0 }} offene und {{ $finishSummary['blockiert'] ?? 0 }} blockierte Zeilen. Abschlussnotiz erforderlich.
                </x-foodalchemist::alert>
            @endif
            <label class="block"><span class="{{ $label }}">Abschlussnotiz</span><textarea wire:model="finishNote" class="{{ $input }} mt-1" rows="3"></textarea></label>
            <div class="flex justify-end"><button type="button" wire:click="fertigSpeichern" class="{{ $btnPrimary }}">Fertig melden</button></div>
        </div>
    </x-foodalchemist::modal>
</x-ui-page>
