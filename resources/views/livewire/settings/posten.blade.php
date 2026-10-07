{{-- Spec 30 E3 — Posten (Küchen-Arbeitsplätze) mit optionaler Tageskapazität.
     fa-pass 2026-10-05: Bausteine/Tokens, Anordnung wie bisher (Standard-Topf-Deckel oben, Posten-Tabelle,
     Anlegen unten). Häufigste Aufgabe = Kapazität und Besetzung je Posten pflegen → Direkteingabe in der
     Tabelle, die Besetzung steht als eigene Zeile unter dem Posten. --}}
<div class="flex flex-col gap-4" data-settings-posten>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler)<x-fa::notice tone="crit" data-posten-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-posten-meldung>{{ $meldung }}</x-fa::notice>@endif

    {{-- Standard-Topf-Deckel: Fallback für die Produktionszeit, wenn Rezept/Posten keinen eigenen Deckel haben. --}}
    <x-fa::section title="Standard-Topf-Deckel" data-posten-standarddeckel
        description="Größte Charge je Koch-Vorgang, solange ein Rezept oder Posten keinen eigenen Deckel hat. So zählt ein Kessel Sauce als ein Vorgang und nicht als zehn kleine Töpfe. Leer gilt der Standard von 20 kg bzw. 200 Stück. Rezept- und Posten-Deckel gehen immer vor, der kleinere gilt.">
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="kg je Koch-Vorgang" for="posten-standard-kg" class="w-40">
                <x-fa::input id="posten-standard-kg" numeric inputmode="decimal" wire:model="standardTopfKg" placeholder="20" data-posten-standard-kg />
            </x-fa::field>
            <x-fa::field label="Stück je Koch-Vorgang" for="posten-standard-stueck" class="w-40">
                <x-fa::input id="posten-standard-stueck" numeric inputmode="decimal" wire:model="standardTopfStueck" placeholder="200" data-posten-standard-stueck />
            </x-fa::field>
            <x-fa::button variant="primary" wire:click="standardDeckelSpeichern" class="ml-auto" data-posten-standard-speichern>Deckel speichern</x-fa::button>
        </div>
    </x-fa::section>

    <x-fa::section title="Posten"
        description="Ein Posten ist ein Arbeitsplatz, kein Mensch. Die Kapazität ist netto: produktiv verplanbare Minuten, Rüsten und Reinigen schon abgezogen. Sie ist freiwillig, ohne Zahl warnt der Posten nie. Zwei Kombidämpfer trägst du als einen Posten mit doppelter Kapazität ein.">
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead>
                    <tr>
                        <th class="w-full">Posten</th>
                        <th>Bereich</th>
                        <th class="num">Minuten je Tag</th>
                        <th class="num" title="Abweichende Kapazität am Samstag. Leer: wie sonst">Samstag</th>
                        <th class="num" title="Abweichende Kapazität am Sonntag. Leer: wie sonst, 0: geschlossen">Sonntag</th>
                        <th><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($posten as $p)
                        @php($eigen = (int) $p->team_id === (int) $eigenesTeamId)
                        <tr class="{{ $p->is_inactive ? 'opacity-60' : '' }}" wire:key="posten-{{ $p->id }}" data-posten="{{ $p->id }}">
                            <td>
                                @if($eigen)
                                    <x-fa::input size="sm" value="{{ $p->name }}" wire:change="feldSetzen({{ $p->id }}, 'name', $event.target.value)"
                                        aria-label="Name des Postens" class="w-full min-w-44" data-posten-name />
                                @else
                                    <span class="inline-flex flex-wrap items-center gap-1.5">
                                        {{ $p->name }}
                                        <x-fa::badge title="Vorlage aus dem Eltern-Team. Die Auslastung rechnet trotzdem je Betrieb.">geerbt</x-fa::badge>
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($eigen)
                                    <x-fa::input size="sm" value="{{ $p->group_name }}" wire:change="feldSetzen({{ $p->id }}, 'group_name', $event.target.value)"
                                        aria-label="Bereich" class="w-40" placeholder="z. B. Warme Küche" data-posten-gruppe />
                                @else
                                    <span class="text-[var(--fa-ink-2)]">{{ $p->group_name }}</span>
                                @endif
                            </td>
                            <td class="num">
                                @if($eigen)
                                    <x-fa::input size="sm" numeric inputmode="numeric" value="{{ $p->kapazitaet_min_pro_tag }}"
                                        wire:change="feldSetzen({{ $p->id }}, 'kapazitaet', $event.target.value)"
                                        aria-label="Minuten je Tag" class="w-20" placeholder="frei"
                                        title="Leer: plant ohne Kapazität und warnt nie" data-posten-kapazitaet />
                                @else
                                    {{ $p->kapazitaet_min_pro_tag ?? 'frei' }}
                                @endif
                            </td>
                            @foreach([6 => 'sa', 7 => 'so'] as $iso => $marker)
                                <td class="num">
                                    @if($eigen)
                                        <input type="text" inputmode="numeric" value="{{ ($p->kapazitaet_wochentag ?? [])[(string) $iso] ?? '' }}"
                                               wire:change="wochentagSetzen({{ $p->id }}, {{ $iso }}, $event.target.value)"
                                               aria-label="{{ $iso === 6 ? 'Minuten am Samstag' : 'Minuten am Sonntag' }}" placeholder="wie sonst"
                                               class="fa-control h-7 w-20 text-[length:var(--fa-text-sm)] text-right tabular-nums"
                                               data-posten-{{ $marker }} />
                                    @else
                                        <span class="text-[var(--fa-ink-2)]">{{ ($p->kapazitaet_wochentag ?? [])[(string) $iso] ?? 'wie sonst' }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap text-right">
                                @if($eigen)
                                    <x-fa::button size="sm" variant="ghost" wire:click="aktivToggle({{ $p->id }})"
                                        :icon="$p->is_inactive ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-pause-circle'"
                                        title="Posten werden stillgelegt, nicht gelöscht. Sonst liefen bestehende Zuteilungen ins Leere."
                                        data-posten-toggle>{{ $p->is_inactive ? 'Reaktivieren' : 'Stilllegen' }}</x-fa::button>
                                @endif
                            </td>
                        </tr>
                        @if($eigen)
                            {{-- Stufe 3 — Rollen-Besetzung → Kapazität (Köpfe × Schicht) + Kosten; Topf-Deckel. --}}
                            <tr wire:key="pbes-{{ $p->id }}" data-posten-besetzung="{{ $p->id }}">
                                <td colspan="6" class="pt-1 pb-3">
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pl-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                        @if($rollen->isEmpty())
                                            <x-fa::signal tone="warn">
                                                Erst <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'rollen']) }}" class="underline">Rollen und Stundensätze</a> anlegen, dann hier besetzen.
                                            </x-fa::signal>
                                        @else
                                            <span class="font-medium text-[var(--fa-ink-3)]">Besetzung</span>
                                            @foreach($rollen as $rolle)
                                                <label class="inline-flex items-center gap-1.5">{{ $rolle->name }}
                                                    <x-fa::input size="sm" numeric inputmode="numeric" value="{{ ($p->besetzung ?? [])[(string) $rolle->id] ?? '' }}"
                                                        wire:change="besetzungSetzen({{ $p->id }}, {{ $rolle->id }}, $event.target.value)"
                                                        class="w-12" placeholder="0"
                                                        data-posten-besetzung-rolle="{{ $rolle->id }}" />
                                                </label>
                                            @endforeach
                                            <label class="inline-flex items-center gap-1.5">Schicht in Minuten
                                                <x-fa::input size="sm" numeric inputmode="numeric" value="{{ $p->schicht_minuten }}"
                                                    wire:change="feldSetzen({{ $p->id }}, 'schicht', $event.target.value)"
                                                    class="w-16" placeholder="480" data-posten-schicht />
                                            </label>
                                            <label class="inline-flex items-center gap-1.5">Topf-Deckel in kg
                                                <x-fa::input size="sm" numeric inputmode="decimal" value="{{ $p->batch_max_kg }}"
                                                    wire:change="feldSetzen({{ $p->id }}, 'batch_max_kg', $event.target.value)"
                                                    class="w-16" placeholder="Standard" data-posten-topf />
                                            </label>
                                            @php($abg = $p->abgeleiteteKapazitaet())
                                            @if($abg !== null)
                                                <x-fa::badge tone="accent" icon="heroicon-m-calculator" title="Wird als Kapazität genutzt und ersetzt die Minuten je Tag">{{ $abg }} Minuten je Tag aus der Besetzung</x-fa::badge>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-fa::empty compact icon="heroicon-o-fire" title="Noch keine Posten">Unten den ersten Posten anlegen, zum Beispiel Warme Küche, Kalte Küche oder Patisserie.</x-fa::empty>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-[var(--fa-line)]" data-posten-neu>
            <x-fa::field label="Neuer Posten" for="posten-neu-name" class="w-60 max-w-full">
                <x-fa::input id="posten-neu-name" wire:model="neu.name" wire:keydown.enter="create" placeholder="z. B. Warme Küche" data-neu-name />
            </x-fa::field>
            <x-fa::field label="Bereich" for="posten-neu-gruppe" optional class="w-44">
                <x-fa::input id="posten-neu-gruppe" wire:model="neu.group_name" placeholder="Küche" data-neu-gruppe />
            </x-fa::field>
            <x-fa::field label="Minuten je Tag" for="posten-neu-kapazitaet" optional class="w-40">
                <x-fa::input id="posten-neu-kapazitaet" numeric inputmode="numeric" wire:model="neu.kapazitaet" placeholder="480" data-neu-kapazitaet />
            </x-fa::field>
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create" data-posten-anlegen>Posten anlegen</x-fa::button>
        </div>
    </x-fa::section>
    </fieldset>
</div>
