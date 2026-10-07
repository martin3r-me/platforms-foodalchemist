{{-- Stufe 3 P3.1 — Küchen-Rollen mit Kostensatz (Küchenchef / Koch / Hilfskoch …).
     fa-pass 2026-10-05: Bausteine/Tokens. Häufigste Aufgabe = Stundensatz je Rolle pflegen →
     Tabelle mit Direkteingabe zuerst, Betriebs-Wahl darüber, Anlegen darunter (Anordnung wie bisher).
     Stilllegen bleibt am Zeilenende, abgesetzt als leiser Knopf (kein Löschen: Besetzungen hängen daran). --}}
<div class="flex flex-col gap-4" data-settings-rollen>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($fehler)<x-fa::notice tone="crit" data-rollen-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-rollen-meldung>{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Rollen und Stundensätze"
        description="Eine Rolle ist ein Kostenträger, kein Mensch: Küchenchef, Koch, Hilfskoch. Der Stundensatz rechnet die Produktionskosten. Die Besetzung der Posten leitet daraus Kapazität und Kosten ab. Ohne Satz gilt der allgemeine Stundensatz des Teams.">

        {{-- Ebene 2: Sätze je Betrieb überschreiben (Bestand/Namen bleiben Team-Sache). --}}
        @if(count($betriebe) > 0)
            <div class="flex flex-wrap items-end gap-3 p-3 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
                <x-fa::field label="Stundensätze gelten für" for="rollen-betrieb" class="w-72 max-w-full">
                    <x-fa::select id="rollen-betrieb" wire:model.live="outletId">
                        <option value="">Ganzes Team (Standard für alle Betriebe)</option>
                        @foreach($betriebe as $b)
                            <option value="{{ $b['id'] }}">Betrieb: {{ $b['name'] }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
                <p class="flex-1 min-w-[16rem] pb-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                    @if($scopeOutletName)
                        Du legst eigene Sätze für <strong class="text-[var(--fa-ink)]">{{ $scopeOutletName }}</strong> fest. Leer übernimmt den Satz des Teams. Rollen anlegen und stilllegen gilt weiter für das ganze Team.
                    @else
                        Sätze des Teams. Sie gelten für jeden Betrieb ohne eigenen Satz.
                    @endif
                </p>
            </div>
        @endif

        <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead>
                    <tr>
                        <th class="w-full">Rolle</th>
                        <th class="num">Stundensatz</th>
                        <th><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rollen as $r)
                        @php($eigen = (int) $r->team_id === (int) $eigenesTeamId)
                        <tr class="{{ $r->is_inactive ? 'opacity-60' : '' }}" wire:key="rolle-{{ $r->id }}" data-rolle="{{ $r->id }}">
                            <td>
                                @if($eigen && ! $scopeOutletName)
                                    <x-fa::input size="sm" value="{{ $r->name }}" wire:change="feldSetzen({{ $r->id }}, 'name', $event.target.value)"
                                        aria-label="Name der Rolle" class="w-full min-w-48" data-rolle-name />
                                @else
                                    <span class="inline-flex flex-wrap items-center gap-1.5">
                                        {{ $r->name }}
                                        @unless($eigen)<x-fa::badge title="Vorlage aus dem Eltern-Team">geerbt</x-fa::badge>@endunless
                                        @if($r->is_inactive)<x-fa::badge>stillgelegt</x-fa::badge>@endif
                                    </span>
                                @endif
                            </td>
                            <td class="num">
                                @if($scopeOutletName)
                                    {{-- Betrieb-Scope: Override-Satz für JEDE sichtbare Rolle; leer = erbt Team-Satz. --}}
                                    @php($ov = $outletRates[$r->id] ?? null)
                                    <span class="inline-flex items-center justify-end gap-1.5">
                                        <x-fa::input size="sm" numeric inputmode="decimal"
                                            value="{{ $ov !== null ? rtrim(rtrim(number_format((float) $ov, 2, '.', ''), '0'), '.') : '' }}"
                                            wire:change="feldSetzen({{ $r->id }}, 'satz', $event.target.value)"
                                            class="w-24 {{ $ov !== null ? 'font-semibold text-[var(--fa-accent)]' : '' }}"
                                            placeholder="{{ $r->stundensatz_eur ?? 'Team' }}" aria-label="Stundensatz {{ $r->name }} in Euro für diesen Betrieb"
                                            title="Leer übernimmt den Satz des Teams ({{ $r->stundensatz_eur !== null ? $r->stundensatz_eur . ' €' : 'allgemeiner Stundensatz' }})" data-rolle-satz-outlet />
                                        <span class="text-[var(--fa-ink-3)]">€/Std</span>
                                    </span>
                                @elseif($eigen)
                                    <span class="inline-flex items-center justify-end gap-1.5">
                                        <x-fa::input size="sm" numeric inputmode="decimal" value="{{ $r->stundensatz_eur }}"
                                            wire:change="feldSetzen({{ $r->id }}, 'satz', $event.target.value)"
                                            class="w-24" placeholder="leer" aria-label="Stundensatz {{ $r->name }} in Euro"
                                            title="Leer: der allgemeine Stundensatz des Teams gilt" data-rolle-satz />
                                        <span class="text-[var(--fa-ink-3)]">€/Std</span>
                                    </span>
                                @else
                                    <x-fa::money :value="$r->stundensatz_eur" per="Std" missing="Kein Satz" />
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if($eigen && ! $scopeOutletName)
                                    <x-fa::button size="sm" variant="ghost" wire:click="aktivToggle({{ $r->id }})"
                                        :icon="$r->is_inactive ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-pause-circle'"
                                        title="Rollen werden stillgelegt, nicht gelöscht. Sonst liefen bestehende Besetzungen ins Leere."
                                        data-rolle-toggle>{{ $r->is_inactive ? 'Reaktivieren' : 'Stilllegen' }}</x-fa::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <x-fa::empty compact icon="heroicon-o-user-group" title="Noch keine Rollen">Unten die erste Rolle anlegen, zum Beispiel Küchenchef, Koch oder Hilfskoch.</x-fa::empty>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @unless($scopeOutletName)
            <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-[var(--fa-line)]" data-rollen-neu>
                <x-fa::field label="Neue Rolle" for="rolle-neu-name" class="w-60 max-w-full">
                    <x-fa::input id="rolle-neu-name" wire:model="neu.name" wire:keydown.enter="create" placeholder="z. B. Koch" data-neu-name />
                </x-fa::field>
                <x-fa::field label="Stundensatz in €" for="rolle-neu-satz" optional class="w-36">
                    <x-fa::input id="rolle-neu-satz" numeric inputmode="decimal" wire:model="neu.satz" placeholder="35" data-neu-satz />
                </x-fa::field>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create" data-rollen-anlegen>Rolle anlegen</x-fa::button>
            </div>
        @endunless
        </fieldset>
    </x-fa::section>
</div>
