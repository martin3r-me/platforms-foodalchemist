    <div x-show="tab === 'zeilen'" x-cloak class="pt-4 space-y-4" data-produktion-zeilen>
        @if($ops === null)
            <p class="text-[12px] text-gray-500">Auftrag zuerst speichern — dann erscheinen die Zeilen.</p>
        @else
            @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif

            {{-- Warnungen des GESPEICHERTEN Auftrags — die standen bisher nirgends im Editor. --}}
            @if(! empty($ops['warnungen']))
                <x-foodalchemist::alert tone="warning" data-produktion-warnungen>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach(array_unique($ops['warnungen']) as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </x-foodalchemist::alert>
            @endif

            @php($zeilen = collect($ops['zeilen']))
            @php($darfEditieren = $ops['editierbar'] ?? false)
            @php($darfDisponieren = ($ops['is_owned'] ?? false) && in_array($ops['status'], ['planned', 'in_progress'], true))

            {{-- Überlast meldet sich passiv und nur, wenn am Posten wirklich eine Kapazität
                 hinterlegt ist. Kein Modal, kein Blockieren. --}}
            @if(! empty($kapazitaetsWarnungen))
                <x-foodalchemist::alert tone="warning" data-kapazitaet-warnung>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($kapazitaetsWarnungen as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </x-foodalchemist::alert>
            @endif

            @if(count($postenSummen) > 0)
                <x-foodalchemist::modal-section title="Nach Posten">
                    <div class="flex flex-wrap gap-2" data-posten-summen>
                        @foreach($postenSummen as $ps)
                            <span class="{{ $pill }} {{ $ps['station_id'] === null ? $variantPill['secondary'] : $variantPill['info'] }}" wire:key="ps-{{ $ps['station_id'] ?? 0 }}">
                                {{ $ps['station'] }} · {{ $ps['zeilen'] }} Zeilen · {{ $ps['arbeitszeit_min'] }} min
                                @if($ps['ohne_zeit'] > 0)<span class="text-amber-600" title="Ohne hinterlegte Arbeitszeit — die Summe ist unvollständig."> ({{ $ps['ohne_zeit'] }} ohne Zeit)</span>@endif
                            </span>
                        @endforeach
                        @if($darfDisponieren && $postenListe->isNotEmpty() && collect($postenSummen)->firstWhere('station_id', null))
                            <span class="inline-flex items-center gap-1">
                                <select wire:change="alleUnverplantAufPosten($event.target.value)" class="{{ $input }} !py-0.5 !w-48" data-bulk-posten>
                                    <option value="">Unverplante alle auf …</option>
                                    @foreach($postenListe as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                                </select>
                            </span>
                        @endif
                    </div>
                </x-foodalchemist::modal-section>
            @endif

            @php($darfAbhaken = $ops !== null && $ops['status'] === 'in_progress' && $ops['is_owned'])
            <x-foodalchemist::modal-section title="Zeilen ({{ $zeilen->count() }})">
                <x-slot:actions>
                    <span class="text-[11px] text-gray-500">
                        {{ rtrim(rtrim(number_format((float) $ops['ansaetze_gesamt'], 2, ',', '.'), '0'), ',') }} Ansätze
                        @if($ops['arbeitszeit_gesamt_min'] > 0) · {{ $ops['arbeitszeit_gesamt_min'] }} min @endif
                        @php($ohneZeit = $zeilen->reject(fn ($z) => $z['ist_gestrichen'])->filter(fn ($z) => ($z['arbeitszeit_min'] ?? null) === null)->count())
                        @if($ohneZeit > 0)<span class="text-amber-600" title="Diese Zeilen haben keine Arbeitszeit am Rezept — die Summe ist unvollständig."> · {{ $ohneZeit }} ohne Zeit</span>@endif
                        @if($ops && $ops['status'] !== 'planned' && $ops['fortschritt']['gesamt'] > 0)
                            <span class="text-emerald-600" data-editor-fortschritt> · {{ $ops['fortschritt']['erledigt'] }}/{{ $ops['fortschritt']['gesamt'] }} erledigt</span>
                        @endif
                    </span>
                </x-slot:actions>

                @if(! $darfEditieren)
                    <p class="text-[11px] text-gray-500 mb-2">
                        Nur ein <strong>geplanter</strong> Auftrag im eigenen Team ist editierbar — der Stand ist eingefroren.
                    </p>
                @endif

                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} w-full">Rezept / Position</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">Ansätze</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">Portionen</th>
                            <th class="{{ $th }} whitespace-nowrap" title="Aus der produzierten Menge gerechnet — Alternativen im Rezept">Behälter</th>
                            <th class="{{ $th }} text-right whitespace-nowrap">Zeit</th>
                            <th class="{{ $th }} whitespace-nowrap">Posten</th>
                            <th class="{{ $th }} whitespace-nowrap">Verantwortlich</th>
                            <th class="{{ $th }} text-right whitespace-nowrap" title="Tage vor dem Liefertag — 0 = am Tag selbst">Vorlauf</th>
                            <th class="{{ $th }}">Notiz</th>
                            <th class="{{ $th }} w-px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($zeilen as $z)
                            <tr class="{{ $tr }} {{ $z['ist_gestrichen'] ? 'opacity-50' : '' }}" wire:key="pz-{{ $z['id'] }}" data-produktion-zeile="{{ $z['id'] }}">
                                <td class="{{ $td }}">
                                    <span class="{{ $z['ist_gestrichen'] ? 'line-through' : '' }}">{{ $z['name'] }}</span>
                                    @if($z['ist_freie_position'])
                                        <span class="{{ $pill }} {{ $variantPill['primary'] }} ml-1">manuell</span>
                                    @elseif($z['ist_basisrezept'])
                                        <span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">Basisrezept</span>
                                    @endif
                                    @if($z['line_status'] === 'done')
                                        <span class="{{ $pill }} {{ $variantPill['success'] }} ml-1" data-zeile-status="done">{{ $z['line_status_label'] }}</span>
                                    @elseif($z['line_status'] !== 'open')
                                        <span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1" data-zeile-status="{{ $z['line_status'] }}">{{ $z['line_status_label'] }}</span>
                                    @endif
                                    @if($z['ist_gestrichen'])
                                        <span class="{{ $pill }} {{ $variantPill['danger'] ?? $variantPill['secondary'] }} ml-1">gestrichen</span>
                                        @if($z['struck_reason'])<span class="text-[11px] text-gray-500 ml-1">— {{ $z['struck_reason'] }}</span>@endif
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right whitespace-nowrap">
                                    @if($darfEditieren)
                                        <input type="text" inputmode="decimal"
                                               value="{{ $z['ist_manuelle_ansaetze'] ? rtrim(rtrim(number_format($z['ansaetze'], 3, ',', ''), '0'), ',') : '' }}"
                                               placeholder="{{ rtrim(rtrim(number_format($z['ansaetze_berechnet'], 3, ',', ''), '0'), ',') }}"
                                               wire:change="zeileAnsaetze({{ $z['id'] }}, $event.target.value)"
                                               class="{{ $input }} !py-0.5 !w-20 text-right tabular-nums"
                                               title="leer = berechneter Wert" data-zeile-ansaetze />
                                    @else
                                        <span class="tabular-nums">{{ rtrim(rtrim(number_format($z['ansaetze'], 2, ',', '.'), '0'), ',') }}</span>
                                    @endif
                                    @if($z['override_stale'])
                                        <div class="text-[10px] text-amber-600 mt-0.5" data-override-stale>
                                            berechnet: {{ rtrim(rtrim(number_format($z['ansaetze_berechnet'], 2, ',', '.'), '0'), ',') }}
                                            @if($darfEditieren)
                                                <button type="button" wire:click="zeileAnsaetze({{ $z['id'] }}, '')" class="underline">zurücksetzen</button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $z['portionen'] ?? '—' }}</td>
                                @php($behaelter = \Platform\FoodAlchemist\Services\BehaelterBedarfService::kurz($z['darreichung']['behaelter_bedarf'] ?? null))
                                <td class="{{ $td }} text-[11px] whitespace-nowrap" data-zeile-behaelter>{{ $behaelter ?? '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $z['arbeitszeit_min'] !== null ? $z['arbeitszeit_min'] . ' min' : '—' }}</td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    @if($darfDisponieren)
                                        <select wire:change="zeileZuteilen({{ $z['id'] }}, 'station_id', $event.target.value)"
                                                class="{{ $input }} !py-0.5 !w-36" data-zeile-posten>
                                            <option value="">— kein Posten —</option>
                                            @foreach($postenListe as $p)
                                                <option value="{{ $p->id }}" @selected(($z['station_id'] ?? null) === $p->id)>{{ $p->name }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-[11px] text-gray-500">{{ $z['station'] ?? '—' }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    @if($darfDisponieren)
                                        <input type="text" value="{{ $z['assignee'] }}" wire:change="zeileZuteilen({{ $z['id'] }}, 'assignee', $event.target.value)"
                                               class="{{ $input }} !py-0.5 !w-28" placeholder="Name" data-zeile-assignee />
                                    @else
                                        <span class="text-[11px] text-gray-500">{{ $z['assignee'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right whitespace-nowrap">
                                    @if($darfDisponieren)
                                        <input type="text" inputmode="numeric" value="{{ $z['vorlauf_tage'] }}"
                                               wire:change="zeileZuteilen({{ $z['id'] }}, 'vorlauf_tage', $event.target.value)"
                                               class="{{ $input }} !py-0.5 !w-14 text-right tabular-nums" data-zeile-vorlauf />
                                    @else
                                        <span class="tabular-nums">{{ $z['vorlauf_tage'] }}</span>
                                    @endif
                                    @if(($z['vorlauf_tage'] ?? 0) > 0)
                                        <div class="text-[10px] text-gray-500 mt-0.5">{{ $z['plan_date'] }}</div>
                                    @endif
                                </td>
                                <td class="{{ $td }}">
                                    @if($darfEditieren)
                                        <input type="text" value="{{ $z['note'] }}" wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)"
                                               class="{{ $input }} !py-0.5" placeholder="Küchen-Notiz" data-zeile-notiz />
                                    @else
                                        <span class="text-[11px] text-gray-500">{{ $z['note'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    @if($darfEditieren)
                                        @if($z['ist_freie_position'])
                                            <button type="button" wire:click="freiePositionLoeschen({{ $z['id'] }})" wire:confirm="Freie Position entfernen?"
                                                    class="{{ $btnGhostXs }} text-rose-500" data-zeile-loeschen>✕</button>
                                        @elseif($z['ist_gestrichen'])
                                            <button type="button" wire:click="zeileStreichen({{ $z['id'] }}, false)" class="{{ $btnGhostXs }}" data-zeile-zurueck>wiederherstellen</button>
                                        @else
                                            <button type="button" wire:click="zeileStreichen({{ $z['id'] }}, true)" class="{{ $btnGhostXs }} text-rose-500"
                                                    title="Zählt nicht mehr mit und kommt nicht auf den Zettel — bleibt aber sichtbar" data-zeile-streichen>streichen</button>
                                        @endif
                                    @elseif($darfAbhaken && ! $z['ist_gestrichen'])
                                        {{-- Spec 30 E6: abhaken geht nur im laufenden Auftrag — im „geplant" könnte
                                             ein Recompute die Zeile unter der Hand ersetzen. --}}
                                        <button type="button" wire:click="zeileAbhaken({{ $z['id'] }})"
                                                class="{{ $btnGhostXs }} {{ $z['line_status'] === 'done' ? 'text-emerald-600' : '' }}"
                                                data-zeile-abhaken>{{ $z['line_status'] === 'done' ? '✓ erledigt' : 'abhaken' }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="{{ $td }} text-[12px] text-gray-500">Noch keine Zeilen — erst Ziele setzen.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if($darfEditieren)
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-2 border-t border-black/5" data-freie-position>
                        <span class="{{ $dt }}">＋ Freie Position</span>
                        <input type="text" wire:model="freiTitel" placeholder="z. B. Brot beim Bäcker abholen" class="{{ $input }} !py-1 flex-1 min-w-48" data-frei-titel />
                        <input type="text" inputmode="numeric" wire:model="freiZeit" placeholder="min" class="{{ $input }} !py-1 !w-20" data-frei-zeit />
                        <button type="button" wire:click="freiePositionAnlegen" class="{{ $btnGhostXs }}" data-frei-anlegen>Hinzufügen</button>
                        <span class="text-[10px] text-gray-500">Etwas, das kein Rezept ist. Erscheint nicht im Einkauf.</span>
                    </div>
                @endif
            </x-foodalchemist::modal-section>
        @endif
    </div>{{-- /Tab ZEILEN --}}
