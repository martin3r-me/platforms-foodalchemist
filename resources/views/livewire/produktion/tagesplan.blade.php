{{-- Spec 30 E3 — Tagesplan: was steht wann an welchem Posten an, über alle Aufträge. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Tagesplan" icon="heroicon-o-calendar-days" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion', 'href' => route('foodalchemist.produktion.index')],
            ['label' => 'Tagesplan'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        @if($fehler)<x-foodalchemist::alert tone="danger" data-tagesplan-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif

        {{-- Zeitfenster + Posten-Filter --}}
        <div class="flex flex-wrap items-center gap-2" data-tagesplan-steuerung>
            <button type="button" wire:click="verschiebe(-7)" class="{{ $btnGhostXs }}">‹ Woche</button>
            <button type="button" wire:click="heute" class="{{ $btnGhostXs }}" data-tagesplan-heute>heute</button>
            <button type="button" wire:click="verschiebe(7)" class="{{ $btnGhostXs }}">Woche ›</button>
            <span class="text-[11px] text-gray-500 ml-1">
                {{ \Illuminate\Support\Carbon::parse($von)->format('d.m.') }} – {{ \Illuminate\Support\Carbon::parse($bis)->format('d.m.Y') }}
            </span>
            <select wire:model.live="tage" class="{{ $input }} !py-1 !w-32 ml-auto" data-tagesplan-fenster>
                @foreach([7 => '1 Woche', 14 => '2 Wochen', 28 => '4 Wochen'] as $n => $lbl)
                    <option value="{{ $n }}">{{ $lbl }}</option>
                @endforeach
            </select>
        </div>

        @if($postenListe->isNotEmpty())
            <div class="flex flex-wrap gap-1" data-tagesplan-postenfilter>
                @foreach($postenListe as $p)
                    <button type="button" wire:click="postenWaehlen({{ $p->id }})" wire:key="tpf-{{ $p->id }}"
                            class="{{ $pill }} {{ $postenFilter === $p->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $p->name }}</button>
                @endforeach
                @if($postenFilter !== null)
                    <button type="button" wire:click="postenWaehlen(null)" class="{{ $btnGhostXs }}">Filter zurücksetzen</button>
                @endif
            </div>
        @else
            <x-foodalchemist::alert tone="info" data-tagesplan-keine-posten>
                Noch keine Posten angelegt. Der Tagesplan zeigt dann nur unverplante Arbeit —
                Posten pflegst du unter <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'posten']) }}" class="underline">Einstellungen → Posten &amp; Kapazität</a>.
            </x-foodalchemist::alert>
        @endif

        @forelse($zeilenNachTag as $tag => $zeilen)
            @php($tagC = \Illuminate\Support\Carbon::parse($tag))
            <div class="{{ $card }} px-4 py-3" wire:key="tp-{{ $tag }}" data-tagesplan-tag="{{ $tag }}">
                <div class="flex items-baseline gap-2 mb-2">
                    <h3 class="text-sm font-semibold text-gray-900">{{ $tagC->locale('de')->isoFormat('dd DD.MM.') }}</h3>
                    @if($tagC->isToday())<span class="{{ $pill }} {{ $variantPill['primary'] }}">heute</span>@endif
                    <span class="text-[11px] text-gray-500">{{ $zeilen->count() }} Positionen</span>
                </div>

                {{-- Auslastung je Posten: Balken nur, wo eine Kapazität hinterlegt ist. --}}
                @foreach($auslastung[$tag] ?? [] as $b)
                    @php($tone = ['ueberlast' => 'danger', 'eng' => 'warning', 'ok' => 'success'][$b['stufe']] ?? 'neutral')
                    <div class="flex items-center gap-2 mb-1" wire:key="tpa-{{ $tag }}-{{ $b['station_id'] ?? 0 }}" data-tagesplan-auslastung>
                        <span class="text-[11px] {{ $b['station_id'] === null ? 'text-gray-500 italic' : 'text-gray-700' }} w-40 shrink-0 truncate">{{ $b['station'] }}</span>
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
                @endforeach

                <table class="{{ $table }} mt-2">
                    <tbody>
                        @foreach($zeilen as $z)
                            <tr class="{{ $tr }}" wire:key="tpz-{{ $z->id }}" data-tagesplan-zeile="{{ $z->id }}">
                                <td class="{{ $td }}">
                                    {{ $z->name }}
                                    <span class="text-[11px] text-gray-500 ml-1">
                                        {{ $z->station ?? 'nicht zugeteilt' }}@if($z->assignee) · {{ $z->assignee }}@endif
                                    </span>
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
        @empty
            <div class="{{ $card }} px-4 py-10 text-center text-xs text-gray-500" data-tagesplan-leer>
                In diesem Zeitraum steht nichts an.<br>
                Der Tagesplan zeigt Zeilen aus <strong>geplanten und laufenden</strong> Aufträgen — Erledigtes und Storniertes belegt nichts.
            </div>
        @endforelse

        <livewire:foodalchemist.produktion.editor />
    </x-ui-page-container>
</x-ui-page>
