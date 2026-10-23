{{-- Spec 30 E3 — Posten (Küchen-Arbeitsplätze) mit optionaler Tageskapazität.
     E-3c (Dominique 23.10.): Liste statt Formularzeilen. Je Posten eine Zeile mit Name, Bereich, Minuten je Tag und Besetzung in Kurzform;
     Details (Samstag/Sonntag, Besetzung, Schicht, Topf-Deckel, Sammelposten) erst beim Aufklappen. „+ Posten“ öffnet das Anlegen bei
     Bedarf. Die Vorgaben (Standard-Topf-Deckel, Posten für Gerichte) stehen darunter, Hilfetexte als (i). Alle Felder bleiben im DOM. --}}
@php
    $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $zeileGrid = 'grid grid-cols-[20px_minmax(0,1fr)_minmax(0,10rem)_7rem_minmax(0,14rem)] items-center gap-3';
    $klein = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp
<div class="flex flex-col gap-4" data-settings-posten>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei (im Reiter: die Leiste des Reiters) --}}
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler)<x-fa::notice tone="crit" data-posten-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-posten-meldung>{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Posten" x-data="{ neuOffen: false }">
        <x-slot:actions>
            @include('foodalchemist::livewire.settings.partials.info', ['text' => 'Ein Posten ist ein Arbeitsplatz, kein Mensch. Die Kapazität ist netto: produktiv verplanbare Minuten, Rüsten und Reinigen schon abgezogen. Sie ist freiwillig, ohne Zahl warnt der Posten nie. Zwei Kombidämpfer trägst du als einen Posten mit doppelter Kapazität ein.'])
            <x-fa::button size="sm" icon="heroicon-m-plus" x-on:click="neuOffen = ! neuOffen" data-posten-neu-oeffnen>Posten</x-fa::button>
        </x-slot:actions>

        <div class="flex flex-wrap items-end gap-2 p-3 rounded-[var(--fa-radius-control)] bg-[var(--fa-accent-soft)]" x-show="neuOffen" x-cloak data-posten-neu>
            <x-fa::field label="Neuer Posten" for="posten-neu-name" class="w-60 max-w-full">
                <x-fa::input id="posten-neu-name" wire:model="neu.name" wire:keydown.enter="create" placeholder="z. B. Warme Küche" data-neu-name />
            </x-fa::field>
            <x-fa::field label="Bereich" for="posten-neu-gruppe" optional class="w-44">
                <x-fa::input id="posten-neu-gruppe" wire:model="neu.group_name" placeholder="Küche" data-neu-gruppe />
            </x-fa::field>
            <x-fa::field label="Minuten je Tag" for="posten-neu-kapazitaet" optional class="w-36">
                <x-fa::input id="posten-neu-kapazitaet" numeric inputmode="numeric" wire:model="neu.kapazitaet" placeholder="480" data-neu-kapazitaet />
            </x-fa::field>
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create" data-posten-anlegen>Anlegen</x-fa::button>
        </div>

        <div class="overflow-x-auto">
            <div class="min-w-[640px] flex flex-col" role="list" data-posten-liste>
                <div class="{{ $zeileGrid }} px-1 pb-1.5 border-b border-[var(--fa-line)] {{ $klein }}" aria-hidden="true">
                    <span></span><span>Posten</span><span>Bereich</span><span class="text-right">Minuten je Tag</span><span>Besetzung</span>
                </div>
                @forelse($posten as $p)
                    @php
                        $eigen = (int) $p->team_id === (int) $eigenesTeamId;
                        $abg = $p->abgeleiteteKapazitaet();
                        $kopfzahl = collect($rollen)->map(fn ($r) => [(int) (($p->besetzung ?? [])[(string) $r->id] ?? 0), $r->name])->filter(fn ($x) => $x[0] > 0);
                        $besetzungKurz = $p->ist_sammelposten ? 'Sammelposten (Pass)'
                            : ($kopfzahl->isEmpty() ? 'nicht besetzt' : $kopfzahl->map(fn ($x) => $x[0] . ' ' . $x[1])->implode(' · '));
                        $minuten = $abg ?? $p->kapazitaet_min_pro_tag;
                    @endphp
                    <div class="border-b border-[var(--fa-line)] {{ $p->is_inactive ? 'opacity-60' : '' }}" role="listitem" x-data="{ offen: false }"
                         wire:key="posten-{{ $p->id }}" data-posten="{{ $p->id }}">
                        <button type="button" x-on:click="offen = ! offen" x-bind:aria-expanded="offen"
                                class="{{ $zeileGrid }} w-full px-1 py-2.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] rounded-[var(--fa-radius-control)]"
                                data-posten-zeile>
                            <span class="text-[var(--fa-ink-3)] transition-transform duration-150" x-bind:class="offen ? 'rotate-90' : ''">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                            <span class="min-w-0 flex flex-wrap items-center gap-1.5 font-medium">{{ $p->name }}
                                @unless($eigen)<x-fa::badge title="Vorlage aus dem Eltern-Team. Die Auslastung rechnet trotzdem je Kalkulationsprofil.">geerbt</x-fa::badge>@endunless
                                @if($p->is_inactive)<x-fa::badge>stillgelegt</x-fa::badge>@endif
                            </span>
                            <span class="min-w-0 truncate text-[var(--fa-ink-2)]">{{ $p->group_name ?: '—' }}</span>
                            <span class="text-right tabular-nums" @if($abg !== null) title="aus der Besetzung berechnet" @endif>{{ $minuten !== null ? number_format((int) $minuten, 0, ',', '.') . ' min' : 'frei' }}</span>
                            <span class="min-w-0 truncate text-[var(--fa-ink-2)]" data-posten-besetzung-kurz>{{ $besetzungKurz }}</span>
                        </button>

                        <div class="ml-8 mb-3 p-3 rounded-[var(--fa-radius-control)] bg-[var(--fa-bg,var(--fa-neutral-soft))] flex flex-col gap-3" x-show="offen" x-cloak data-posten-details>
                            @if($eigen)
                                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_repeat(3,7rem)]">
                                    <x-fa::field label="Name" for="posten-{{ $p->id }}-name">
                                        <x-fa::input id="posten-{{ $p->id }}-name" size="sm" value="{{ $p->name }}" wire:change="feldSetzen({{ $p->id }}, 'name', $event.target.value)" data-posten-name />
                                    </x-fa::field>
                                    <x-fa::field label="Bereich" for="posten-{{ $p->id }}-gruppe">
                                        <x-fa::input id="posten-{{ $p->id }}-gruppe" size="sm" value="{{ $p->group_name }}" wire:change="feldSetzen({{ $p->id }}, 'group_name', $event.target.value)" placeholder="z. B. Warme Küche" data-posten-gruppe />
                                    </x-fa::field>
                                    <x-fa::field label="Minuten je Tag" for="posten-{{ $p->id }}-kap">
                                        <x-fa::input id="posten-{{ $p->id }}-kap" size="sm" numeric inputmode="numeric" value="{{ $p->kapazitaet_min_pro_tag }}" wire:change="feldSetzen({{ $p->id }}, 'kapazitaet', $event.target.value)" placeholder="frei" title="Leer: plant ohne Kapazität und warnt nie" data-posten-kapazitaet />
                                    </x-fa::field>
                                    @foreach([6 => ['sa', 'Samstag'], 7 => ['so', 'Sonntag']] as $iso => [$marker, $tag])
                                        <x-fa::field :label="$tag" for="posten-{{ $p->id }}-{{ $marker }}">
                                            <input id="posten-{{ $p->id }}-{{ $marker }}" type="text" inputmode="numeric" value="{{ ($p->kapazitaet_wochentag ?? [])[(string) $iso] ?? '' }}"
                                                   wire:change="wochentagSetzen({{ $p->id }}, {{ $iso }}, $event.target.value)" placeholder="wie sonst"
                                                   title="{{ $iso === 6 ? 'Leer: wie sonst' : 'Leer: wie sonst, 0: geschlossen' }}"
                                                   class="fa-control h-8 w-full text-[length:var(--fa-text-sm)] text-right tabular-nums" data-posten-{{ $marker }} />
                                        </x-fa::field>
                                    @endforeach
                                </div>
                                {{-- Stufe 3 — Rollen-Besetzung → Kapazität (Köpfe × Schicht) + Kosten; Topf-Deckel. --}}
                                <div class="flex flex-wrap items-end gap-3" data-posten-besetzung="{{ $p->id }}">
                                    @if($rollen->isEmpty())
                                        <x-fa::signal tone="warn">
                                            Erst <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'rollen']) }}" class="underline">Küchenrollen und Stundensätze</a> anlegen, dann hier besetzen.
                                        </x-fa::signal>
                                    @else
                                        @foreach($rollen as $rolle)
                                            <x-fa::field :label="$rolle->name" for="posten-{{ $p->id }}-rolle-{{ $rolle->id }}" class="w-24">
                                                <x-fa::input id="posten-{{ $p->id }}-rolle-{{ $rolle->id }}" size="sm" numeric inputmode="numeric" value="{{ ($p->besetzung ?? [])[(string) $rolle->id] ?? '' }}"
                                                    wire:change="besetzungSetzen({{ $p->id }}, {{ $rolle->id }}, $event.target.value)" placeholder="0" data-posten-besetzung-rolle="{{ $rolle->id }}" />
                                            </x-fa::field>
                                        @endforeach
                                        <x-fa::field label="Schicht (min)" for="posten-{{ $p->id }}-schicht" class="w-28">
                                            <x-fa::input id="posten-{{ $p->id }}-schicht" size="sm" numeric inputmode="numeric" value="{{ $p->schicht_minuten }}" wire:change="feldSetzen({{ $p->id }}, 'schicht', $event.target.value)" placeholder="480" data-posten-schicht />
                                        </x-fa::field>
                                        <x-fa::field label="Topf-Deckel (kg)" for="posten-{{ $p->id }}-topf" class="w-32">
                                            <x-fa::input id="posten-{{ $p->id }}-topf" size="sm" numeric inputmode="decimal" value="{{ $p->batch_max_kg }}" wire:change="feldSetzen({{ $p->id }}, 'batch_max_kg', $event.target.value)" placeholder="Standard" data-posten-topf />
                                        </x-fa::field>
                                        @if($abg !== null)
                                            <x-fa::badge tone="accent" icon="heroicon-m-calculator" title="Wird als Kapazität genutzt und ersetzt die Minuten je Tag">{{ $abg }} Minuten je Tag aus der Besetzung</x-fa::badge>
                                        @endif
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-[var(--fa-line)]">
                                    {{-- Spec 83 B3g: der Pass ist ein Sammelposten ohne eigenes Personal --}}
                                    <label class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" title="Ein Sammelposten hat kein eigenes Personal: Kapazität und Last hängen an den beteiligten Posten, es gibt keine Überlast-Warnung für ihn. Gedacht für den Pass.">
                                        <input type="checkbox" @checked($p->ist_sammelposten) wire:change="feldSetzen({{ $p->id }}, 'ist_sammelposten', $event.target.checked ? '1' : '0')" data-posten-sammelposten />
                                        Sammelposten (Pass)
                                        @if($p->ist_sammelposten)<span class="text-[var(--fa-ink-3)]" data-posten-sammelposten-hinweis>· ohne eigenes Personal</span>@endif
                                    </label>
                                    <x-fa::button size="sm" variant="ghost" wire:click="aktivToggle({{ $p->id }})"
                                        :icon="$p->is_inactive ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-pause-circle'"
                                        title="Posten werden stillgelegt, nicht gelöscht. Sonst liefen bestehende Zuteilungen ins Leere."
                                        data-posten-toggle>{{ $p->is_inactive ? 'Reaktivieren' : 'Stilllegen' }}</x-fa::button>
                                </div>
                            @else
                                <p class="{{ $klein }}">Vorlage aus dem Eltern-Team, hier nur lesbar. Samstag {{ ($p->kapazitaet_wochentag ?? [])['6'] ?? 'wie sonst' }}, Sonntag {{ ($p->kapazitaet_wochentag ?? [])['7'] ?? 'wie sonst' }}.</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-fa::empty compact icon="heroicon-o-fire" title="Noch keine Posten">Mit „+ Posten“ den ersten anlegen, zum Beispiel Warme Küche, Kalte Küche oder Patisserie.</x-fa::empty>
                @endforelse
            </div>
        </div>
    </x-fa::section>

    {{-- Vorgaben: Standard-Topf-Deckel (Fallback der Produktionszeit) und Posten für Gerichte (Spec 83 B2) --}}
    <x-fa::section title="Vorgaben">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2" data-posten-standarddeckel>
                <div class="flex items-center gap-2 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Standard-Topf-Deckel
                    @include('foodalchemist::livewire.settings.partials.info', ['text' => 'Größte Charge je Koch-Vorgang, solange ein Rezept oder Posten keinen eigenen Deckel hat. So zählt ein Kessel Sauce als ein Vorgang und nicht als zehn kleine Töpfe. Leer gilt der Standard von 20 kg bzw. 200 Stück. Rezept- und Posten-Deckel gehen immer vor, der kleinere gilt.'])</div>
                <div class="flex flex-wrap items-end gap-2">
                    <x-fa::field label="kg je Vorgang" for="posten-standard-kg" class="w-32">
                        <x-fa::input id="posten-standard-kg" numeric inputmode="decimal" wire:model="standardTopfKg" placeholder="20" data-posten-standard-kg />
                    </x-fa::field>
                    <x-fa::field label="Stück je Vorgang" for="posten-standard-stueck" class="w-32">
                        <x-fa::input id="posten-standard-stueck" numeric inputmode="decimal" wire:model="standardTopfStueck" placeholder="200" data-posten-standard-stueck />
                    </x-fa::field>
                    <x-fa::button variant="primary" wire:click="standardDeckelSpeichern" data-posten-standard-speichern>Speichern</x-fa::button>
                </div>
            </div>
            <div class="flex flex-col gap-2" data-posten-gerichte>
                <div class="flex items-center gap-2 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Posten für Gerichte
                    @include('foodalchemist::livewire.settings.partials.info', ['text' => 'Gerichte gehen an den Pass. Hier legst du fest, an welchen Posten die Zeile eines Gerichts im Auftrag geht, wenn das Rezept keinen eigenen Standard-Posten trägt. Ein Posten am Rezept geht immer vor, eine Zuteilung von Hand ebenso. Unter-Teams erben die Vorgabe.'])
                    @if($gerichteVorgabeGeerbt)<x-fa::badge title="Die Vorgabe kommt vom Oberteam. Speicherst du hier, gilt für dieses Team eine eigene.">geerbt</x-fa::badge>@endif</div>
                <div class="flex flex-wrap items-end gap-2">
                    <x-fa::field label="Posten" for="posten-gerichte" class="w-64 max-w-full">
                        <x-fa::select id="posten-gerichte" wire:model="gerichteStationId" placeholder="Keine Vorgabe" data-posten-gerichte-select>
                            @foreach($posten->where('is_inactive', false) as $p)<option value="{{ $p->id }}">{{ $p->name }}{{ $p->ist_sammelposten ? ' (Sammelposten)' : '' }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::button variant="primary" wire:click="gerichtePostenSpeichern" data-posten-gerichte-speichern>Speichern</x-fa::button>
                </div>
            </div>
        </div>
    </x-fa::section>
    </fieldset>
</div>
