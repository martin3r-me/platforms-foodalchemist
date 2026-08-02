{{-- Spec 30 E3/E6/E8 — Tagesplan: die Tages-Ausgabe. Was steht an welchem Tag an welchem
     Posten an, über alle Aufträge. Aggregation über ZEILEN (nicht Aufträge): der Auftrag bleibt
     ein Liefertag-Punkt, seine Zeilen dürfen per vorlauf_tage davor liegen.
     3-Panel wie der Produktions-Browser (Sidebar/Center/Detail); Wandmodus blendet die Panels aus. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($istWall = $display === 'wall')

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Tagesplan" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion', 'href' => route('foodalchemist.produktion.index')],
            ['label' => 'Tagesplan'],
        ]">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="vorschlagen" class="{{ $btnAi }}"
                        data-tagesplan-vorschlagen>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Tagesplan vorschlagen</button>
                <a href="{{ route('foodalchemist.produktion.tagesplan.blatt', array_filter(['von' => $von, 'tage' => $tage, 'posten' => $postenFilter])) }}"
                   target="_blank" class="{{ $btnGhostXs }}" data-tagesplan-drucken>Posten-Blatt drucken</a>
                <a href="{{ route('foodalchemist.produktion.tagesplan', ['von' => $von, 'tage' => $tage, 'display' => $istWall ? '' : 'wall']) }}"
                   wire:navigate class="{{ $btnGhostXs }}" data-tagesplan-wall-toggle>{{ $istWall ? 'Normalansicht' : 'Wandmodus' }}</a>
            </div>
        </x-ui-page-actionbar>
    </x-slot>

    @unless($istWall)
        <x-slot name="sidebar">
            <x-ui-page-sidebar title="Zeitraum & Posten" width="w-72">
                <div class="p-3 space-y-4" data-tagesplan-steuerung>
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
                    <div>
                        <label class="{{ $label }}">Fenster</label>
                        <select wire:model.live="tage" class="{{ $input }} mt-1" data-tagesplan-fenster>
                            @foreach([7 => '1 Woche', 14 => '2 Wochen', 28 => '4 Wochen'] as $n => $lbl)
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

    <livewire:foodalchemist.produktion.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        @if($fehler)<x-foodalchemist::alert tone="danger" data-tagesplan-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif

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

        {{-- Wandmodus hat keine Sidebar — die Zeitfenster-Steuerung wandert dann nach oben. --}}
        @if($istWall)
            <div class="flex flex-wrap items-center gap-2" data-tagesplan-steuerung>
                <button type="button" wire:click="verschiebe(-7)" class="{{ $btnGhostXs }}">‹ Woche</button>
                <button type="button" wire:click="heute" class="{{ $btnGhostXs }}" data-tagesplan-heute>heute</button>
                <button type="button" wire:click="verschiebe(7)" class="{{ $btnGhostXs }}">Woche ›</button>
                <span class="text-sm text-gray-500 ml-1">
                    {{ \Illuminate\Support\Carbon::parse($von)->format('d.m.') }} – {{ \Illuminate\Support\Carbon::parse($bis)->format('d.m.Y') }}
                </span>
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
                                            <button type="button" wire:click="waehleAuftrag({{ $z->order_id }})" class="text-left hover:text-violet-700" data-tagesplan-select>
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
            </div>
        @empty
            <div class="{{ $card }} px-4 py-10 text-center text-xs text-gray-500" data-tagesplan-leer>
                In diesem Zeitraum steht nichts an.<br>
                Der Tagesplan zeigt Zeilen aus <strong>geplanten und laufenden</strong> Aufträgen — Erledigtes und Storniertes belegt nichts.
            </div>
        @endforelse

    </x-ui-page-container>
</x-ui-page>
