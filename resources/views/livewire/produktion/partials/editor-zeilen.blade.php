    <div x-show="tab === 'zeilen'" x-cloak class="pt-4 flex flex-col gap-4" data-produktion-zeilen>
        @if($ops === null)
            <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Positionen">Auftrag zuerst speichern, dann erscheinen hier die Positionen.</x-fa::empty>
        @else
            @if($hinweis)<x-fa::notice tone="ok">{{ $hinweis }}</x-fa::notice>@endif

            {{-- Warnungen des GESPEICHERTEN Auftrags — die standen bisher nirgends im Editor. --}}
            @if(! empty($ops['warnungen']))
                <x-fa::notice tone="warn" title="Beim Berechnen aufgefallen" data-produktion-warnungen>
                    <ul class="list-disc list-inside flex flex-col gap-0.5">
                        @foreach(array_unique($ops['warnungen']) as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </x-fa::notice>
            @endif

            @php
                $zeilen = collect($ops['zeilen']);
                $darfEditieren = $ops['editierbar'] ?? false;
                $darfDisponieren = ($ops['is_owned'] ?? false) && in_array($ops['status'], ['planned', 'in_progress'], true);
                $darfAbhaken = $ops !== null && $ops['status'] === 'in_progress' && $ops['is_owned'];
                $ohneZeit = $zeilen->reject(fn ($z) => $z['ist_gestrichen'])->filter(fn ($z) => ($z['arbeitszeit_min'] ?? null) === null)->count();
            @endphp

            {{-- Überlast meldet sich passiv und nur, wenn am Posten wirklich eine Kapazität
                 hinterlegt ist. Kein Modal, kein Blockieren. --}}
            @if(! empty($kapazitaetsWarnungen))
                <x-fa::notice tone="warn" title="Posten überlastet" data-kapazitaet-warnung>
                    <ul class="list-disc list-inside flex flex-col gap-0.5">
                        @foreach($kapazitaetsWarnungen as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </x-fa::notice>
            @endif

            @if(count($postenSummen) > 0)
                <x-fa::section title="Nach Posten" icon="heroicon-o-users">
                    @if($darfDisponieren && $postenListe->isNotEmpty() && collect($postenSummen)->firstWhere('station_id', null))
                        <x-slot:actions>
                            <x-fa::select size="sm" wire:change="alleUnverplantAufPosten($event.target.value)" class="w-56" aria-label="Alle Positionen ohne Posten zuteilen" data-bulk-posten>
                                <option value="">Alle ohne Posten zuteilen an</option>
                                @foreach($postenListe as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </x-fa::select>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-wrap gap-2" data-posten-summen>
                        @foreach($postenSummen as $ps)
                            <div class="flex flex-col gap-0.5 min-w-[9rem] px-3 py-2 rounded-[var(--fa-radius-control)] border {{ $ps['station_id'] === null ? 'border-dashed border-[var(--fa-line-strong)]' : 'border-[var(--fa-line)] bg-[var(--fa-ground)]' }}" wire:key="ps-{{ $ps['station_id'] ?? 0 }}">
                                <span class="text-[length:var(--fa-text-md)] font-medium {{ $ps['station_id'] === null ? 'text-[var(--fa-ink-2)]' : 'text-[var(--fa-ink)]' }}">{{ $ps['station_id'] === null ? 'Ohne Posten' : $ps['station'] }}</span>
                                <span class="{{ $leise }} tabular-nums">{{ $ps['zeilen'] }} {{ $ps['zeilen'] === 1 ? 'Position' : 'Positionen' }} · {{ number_format((int) $ps['arbeitszeit_min'], 0, ',', '.') }} min</span>
                                @if($ps['ohne_zeit'] > 0)<x-fa::signal tone="warn" title="Ohne hinterlegte Arbeitszeit, die Summe ist unvollständig.">{{ $ps['ohne_zeit'] }} ohne Zeit</x-fa::signal>@endif
                            </div>
                        @endforeach
                    </div>
                </x-fa::section>
            @endif

            <x-fa::section title="Positionen" icon="heroicon-o-queue-list" :meta="$zeilen->count()">
                <x-slot:actions>
                    <span class="flex flex-wrap items-center justify-end gap-x-2 gap-y-1 {{ $leise }} tabular-nums">
                        <span>{{ $menge($ops['ansaetze_gesamt']) }} Ansätze</span>
                        @if($ops['arbeitszeit_gesamt_min'] > 0)<span>· {{ number_format((int) $ops['arbeitszeit_gesamt_min'], 0, ',', '.') }} min</span>@endif
                        @if($ohneZeit > 0)<x-fa::signal tone="warn" title="Diese Positionen haben keine Arbeitszeit am Rezept, die Summe ist unvollständig.">{{ $ohneZeit }} ohne Zeit</x-fa::signal>@endif
                        @if($ops && $ops['status'] !== 'planned' && $ops['fortschritt']['gesamt'] > 0)
                            <x-fa::signal tone="ok" data-editor-fortschritt>{{ $ops['fortschritt']['erledigt'] }} von {{ $ops['fortschritt']['gesamt'] }} erledigt</x-fa::signal>
                        @endif
                    </span>
                </x-slot:actions>

                @if(! $darfEditieren)
                    <x-fa::notice tone="info">Nur ein geplanter Auftrag im eigenen Team lässt sich ändern. Dieser Stand ist eingefroren.</x-fa::notice>
                @endif

                <div class="overflow-x-auto -mx-4 px-4">
                    <table class="fa-table">
                        <thead>
                            <tr>
                                <th class="w-full min-w-[14rem]">Rezept oder Position</th>
                                <th class="num" title="Leer lassen, dann gilt der berechnete Wert">Ansätze</th>
                                <th class="num">Portionen</th>
                                <th title="Aus der produzierten Menge gerechnet, Alternativen stehen im Rezept">Behälter</th>
                                <th class="num">Zeit</th>
                                <th>Posten</th>
                                <th>Verantwortlich</th>
                                <th class="num" title="Tage vor dem Liefertag, 0 bedeutet am Tag selbst">Vorlauf</th>
                                <th class="min-w-[10rem]">Notiz</th>
                                <th class="w-px"><span class="sr-only">Aktion</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($zeilen as $z)
                                @php
                                    $behaelter = \Platform\FoodAlchemist\Services\BehaelterBedarfService::kurz($z['darreichung']['behaelter_bedarf'] ?? null);
                                @endphp
                                <tr class="{{ $z['ist_gestrichen'] ? 'opacity-60' : '' }}" wire:key="pz-{{ $z['id'] }}" data-produktion-zeile="{{ $z['id'] }}">
                                    <td>
                                        <span class="font-medium text-[var(--fa-ink)] {{ $z['ist_gestrichen'] ? 'line-through' : '' }}">{{ $z['name'] }}</span>
                                        <span class="inline-flex flex-wrap items-center gap-1 ml-1 align-middle">
                                            @if($z['ist_freie_position'])
                                                <x-fa::badge tone="accent">Freie Position</x-fa::badge>
                                            @elseif($z['ist_basisrezept'])
                                                <x-fa::badge tone="info">Basisrezept</x-fa::badge>
                                            @endif
                                            @if($z['line_status'] === 'done')
                                                <x-fa::badge tone="ok" icon="heroicon-m-check" data-zeile-status="done">{{ ucfirst($z['line_status_label']) }}</x-fa::badge>
                                            @elseif($z['line_status'] !== 'open')
                                                <x-fa::badge data-zeile-status="{{ $z['line_status'] }}">{{ ucfirst($z['line_status_label']) }}</x-fa::badge>
                                            @endif
                                            @if($z['ist_gestrichen'])
                                                <x-fa::badge tone="crit">gestrichen</x-fa::badge>
                                            @endif
                                        </span>
                                        @if($z['ist_gestrichen'] && $z['struck_reason'])<p class="{{ $leise }}">Grund: {{ $z['struck_reason'] }}</p>@endif
                                    </td>
                                    <td class="num">
                                        @if($darfEditieren)
                                            <x-fa::input size="sm" numeric inputmode="decimal" class="w-20"
                                                   value="{{ $z['ist_manuelle_ansaetze'] ? rtrim(rtrim(number_format($z['ansaetze'], 3, ',', ''), '0'), ',') : '' }}"
                                                   placeholder="{{ rtrim(rtrim(number_format($z['ansaetze_berechnet'], 3, ',', ''), '0'), ',') }}"
                                                   wire:change="zeileAnsaetze({{ $z['id'] }}, $event.target.value)"
                                                   aria-label="Ansätze für {{ $z['name'] }}"
                                                   title="Leer lassen, dann gilt der berechnete Wert" data-zeile-ansaetze />
                                        @else
                                            <span>{{ $menge($z['ansaetze']) }}</span>
                                        @endif
                                        @if($z['override_stale'])
                                            <div class="mt-0.5 flex items-center justify-end gap-1 text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" data-override-stale>
                                                <span>berechnet {{ $menge($z['ansaetze_berechnet']) }}</span>
                                                @if($darfEditieren)
                                                    <button type="button" wire:click="zeileAnsaetze({{ $z['id'] }}, '')" class="underline hover:text-[var(--fa-ink)]">übernehmen</button>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="num">{{ $z['portionen'] ?? '–' }}</td>
                                    <td class="whitespace-nowrap text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-zeile-behaelter>{{ $behaelter ?? '–' }}</td>
                                    <td class="num">
                                        @if($z['arbeitszeit_min'] !== null)
                                            <x-fa::menge :value="$z['arbeitszeit_min']" unit="min" :decimals="0" />
                                        @else
                                            <span class="text-[var(--fa-ink-3)]">–</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if($darfDisponieren)
                                            <x-fa::select size="sm" wire:change="zeileZuteilen({{ $z['id'] }}, 'station_id', $event.target.value)"
                                                    class="w-40" aria-label="Posten für {{ $z['name'] }}" data-zeile-posten>
                                                <option value="">Kein Posten</option>
                                                @foreach($postenListe as $p)
                                                    <option value="{{ $p->id }}" @selected(($z['station_id'] ?? null) === $p->id)>{{ $p->name }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        @else
                                            <span class="text-[length:var(--fa-text-sm)] {{ $z['station'] ? 'text-[var(--fa-ink-2)]' : 'text-[var(--fa-ink-3)]' }}">{{ $z['station'] ?? 'Kein Posten' }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if($darfDisponieren)
                                            <x-fa::input size="sm" value="{{ $z['assignee'] }}" wire:change="zeileZuteilen({{ $z['id'] }}, 'assignee', $event.target.value)"
                                                   class="w-32" placeholder="Name" aria-label="Verantwortlich für {{ $z['name'] }}" data-zeile-assignee />
                                        @else
                                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $z['assignee'] }}</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if($darfDisponieren)
                                            <x-fa::input size="sm" numeric inputmode="numeric" value="{{ $z['vorlauf_tage'] }}"
                                                   wire:change="zeileZuteilen({{ $z['id'] }}, 'vorlauf_tage', $event.target.value)"
                                                   class="w-16" aria-label="Vorlauf in Tagen für {{ $z['name'] }}" data-zeile-vorlauf />
                                        @else
                                            <span>{{ $z['vorlauf_tage'] }}</span>
                                        @endif
                                        @if(($z['vorlauf_tage'] ?? 0) > 0)
                                            <div class="mt-0.5 {{ $leise }}">am {{ $z['plan_date'] }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($darfEditieren)
                                            <x-fa::input size="sm" value="{{ $z['note'] }}" wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)"
                                                   placeholder="Notiz für die Küche" aria-label="Notiz zu {{ $z['name'] }}" data-zeile-notiz />
                                        @else
                                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $z['note'] }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        @if($darfEditieren)
                                            @if($z['ist_freie_position'])
                                                <x-fa::icon-button icon="heroicon-o-trash" label="Freie Position entfernen" size="sm" tone="danger"
                                                    wire:click="freiePositionLoeschen({{ $z['id'] }})" wire:confirm="Freie Position entfernen?" data-zeile-loeschen />
                                            @elseif($z['ist_gestrichen'])
                                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-uturn-left" wire:click="zeileStreichen({{ $z['id'] }}, false)" data-zeile-zurueck>Wiederherstellen</x-fa::button>
                                            @else
                                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-minus-circle" wire:click="zeileStreichen({{ $z['id'] }}, true)"
                                                        title="Zählt nicht mehr mit und kommt nicht auf den Zettel, bleibt aber sichtbar" data-zeile-streichen>Streichen</x-fa::button>
                                            @endif
                                        @elseif($darfAbhaken && ! $z['ist_gestrichen'])
                                            {{-- Spec 30 E6: abhaken geht nur im laufenden Auftrag — im „geplant" könnte
                                                 eine Neuberechnung die Zeile unter der Hand ersetzen. --}}
                                            <x-fa::button size="sm" :variant="$z['line_status'] === 'done' ? 'ghost' : 'secondary'"
                                                    :icon="$z['line_status'] === 'done' ? 'heroicon-m-check-circle' : 'heroicon-m-check'"
                                                    :class="$z['line_status'] === 'done' ? 'text-[var(--fa-ok)]' : ''"
                                                    href="#" x-on:click.prevent="" wire:click="zeileAbhaken({{ $z['id'] }})"
                                                    data-zeile-abhaken>{{-- Spec 65 (Dominique): Abhaken in der laufenden Küche ohne „Bearbeiten“ — als Link, damit das gesperrte fieldset ihn nicht ausschaltet --}}{{ $z['line_status'] === 'done' ? 'Erledigt' : 'Abhaken' }}</x-fa::button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10">
                                    <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Positionen">Erst im Reiter Ziele etwas einfügen und speichern.</x-fa::empty>
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($darfEditieren)
                    <div class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]" data-freie-position>
                        <div class="flex flex-wrap items-end gap-2">
                            <x-fa::field label="Freie Position" for="produktion-frei-titel" class="flex-1 min-w-[16rem]">
                                <x-fa::input id="produktion-frei-titel" wire:model="freiTitel" placeholder="z. B. Brot beim Bäcker abholen" data-frei-titel />
                            </x-fa::field>
                            <x-fa::field label="Minuten" for="produktion-frei-zeit" optional class="w-32">
                                <x-fa::input id="produktion-frei-zeit" numeric inputmode="numeric" wire:model="freiZeit" placeholder="min" data-frei-zeit />
                            </x-fa::field>
                            <x-fa::button variant="secondary" icon="heroicon-m-plus" wire:click="freiePositionAnlegen" data-frei-anlegen>Position hinzufügen</x-fa::button>
                        </div>
                        <p class="{{ $leise }}">Etwas, das kein Rezept ist. Erscheint nicht im Einkauf.</p>
                    </div>
                @endif
            </x-fa::section>
        @endif
    </div>{{-- /Reiter POSITIONEN --}}
