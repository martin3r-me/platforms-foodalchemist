{{-- Spec 33 P2: Pflege der Betriebe/Standorte (Outlets).
     Die Tabelle gibt es seit Spec 19, diese Oberfläche nicht — deshalb war sie leer und die
     Betriebsbrille des Controllings hätte nichts anzuzeigen gehabt.

     fa-pass 2026-10-05: Bausteine/Tokens. Häufigste Aufgabe = Betrieb anlegen und bearbeiten →
     Anlegen oben rechts als Hauptaktion; Deaktivieren im Menü „Weitere Aktionen", nie neben Speichern.
     Präsentations-Vorlage und -Logo stehen beim Bearbeiten in einer eigenen Zeile unter dem Betrieb. --}}
<div class="flex flex-col gap-4" data-settings-betriebe>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">

    <x-fa::section title="Betriebe" :meta="$betriebe->isNotEmpty() ? $betriebe->count() . ' angelegt' : null"
        description="Standorte, Filialen oder Ausgabestellen. Im Controlling zeigen sie, welcher Betrieb gerade welche Karte, welchen Plan und welches Foodbook fährt. Die Zuordnung an der einzelnen Ausgabe bleibt freiwillig.">

        <div class="flex flex-wrap items-end gap-2">
            <x-fa::field label="Neuer Betrieb" for="betrieb-neu" class="w-72 max-w-full">
                <x-fa::input id="betrieb-neu" wire:model="neuName" wire:keydown.enter="anlegen"
                    placeholder="z. B. Kantine Nord" data-betrieb-neu />
            </x-fa::field>
            <x-fa::button :variant="$editId === null ? 'primary' : 'secondary'" icon="heroicon-m-plus" wire:click="anlegen" :disabled="trim($neuName) === ''">Betrieb anlegen</x-fa::button>
        </div>
        @if($fehler)<x-fa::signal tone="crit">{{ $fehler }}</x-fa::signal>@endif

        @if($betriebe->isEmpty())
            <x-fa::empty icon="heroicon-o-building-storefront" title="Noch kein Betrieb angelegt">
                Solange hier nichts steht, kann keine Ausgabe einem Standort zugeordnet werden. Oben den ersten Betrieb anlegen.
            </x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th class="w-full">Betrieb</th>
                            <th>Farbe</th>
                            <th class="num">Reihenfolge</th>
                            <th>In Verwendung</th>
                            <th><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($betriebe as $b)
                            <tr class="{{ $b->is_inactive ? 'opacity-60' : '' }}" wire:key="outlet-{{ $b->id }}">
                                @if($editId === $b->id)
                                    <td><x-fa::input size="sm" wire:model="form.name" aria-label="Name des Betriebs" class="w-full min-w-48" /></td>
                                    <td><x-fa::input size="sm" wire:model="form.color" placeholder="Farbwert mit #" aria-label="Farbe (sechsstelliger Farbwert mit #)" class="w-32" /></td>
                                    <td class="num"><x-fa::input size="sm" type="number" numeric wire:model="form.sort_order" aria-label="Reihenfolge" class="w-20" /></td>
                                    <td></td>
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <x-fa::button size="sm" variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                                            <x-fa::button size="sm" variant="primary" wire:click="speichern">Speichern</x-fa::button>
                                        </div>
                                    </td>
                                @else
                                    <td class="font-medium">
                                        <span class="inline-flex flex-wrap items-center gap-1.5">
                                            {{ $b->name }}
                                            @if($b->is_inactive)<x-fa::badge>inaktiv</x-fa::badge>@endif
                                        </span>
                                    </td>
                                    <td>
                                        @if($b->color)
                                            <span class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">
                                                <span class="inline-block w-3.5 h-3.5 rounded-full border border-[var(--fa-line)]" style="background-color: {{ $b->color }}"></span>{{ $b->color }}
                                            </span>
                                        @else
                                            <span class="text-[var(--fa-ink-3)]">keine</span>
                                        @endif
                                    </td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ $b->sort_order }}</td>
                                    {{-- Zeigt, was eine Deaktivierung berührt — deshalb wird hier auch nicht gelöscht. --}}
                                    <td class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                        @php($n = $nutzung[$b->id] ?? [])
                                        @if($n === [])
                                            <span class="text-[var(--fa-ink-3)]">noch nicht</span>
                                        @else
                                            {{ collect($n)->map(fn ($z, $k) => $z . ' ' . $k)->implode(' · ') }}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1">
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil-square" wire:click="edit({{ $b->id }})">Bearbeiten</x-fa::button>
                                            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $b->name }}"
                                                    x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                <div class="hidden w-48 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="aktivUmschalten({{ $b->id }})"
                                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $b->is_inactive ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-crit)]' }}">
                                                        @if($b->is_inactive)
                                                            @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4 shrink-0') Betrieb aktivieren
                                                        @else
                                                            @svg('heroicon-o-no-symbol', 'w-4 h-4 shrink-0') Betrieb deaktivieren
                                                        @endif
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                            @if($editId === $b->id)
                                <tr wire:key="outlet-ov-{{ $b->id }}">
                                    <td colspan="5" class="bg-[var(--fa-accent-soft)]">
                                        <div class="flex flex-col gap-4 py-1">
                                            <x-fa::notice tone="info">
                                                Eigene Kosten je Betrieb (Marge, Stundensatz, Zuschläge, Fixkosten) pflegst du unter
                                                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'herstellkosten']) }}" class="font-medium text-[var(--fa-accent)] hover:underline">Herstellkosten &amp; Zuschläge</a>.
                                                Dort oben den Betrieb auswählen.
                                            </x-fa::notice>

                                            <div class="grid gap-4 grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))]">
                                                {{-- Slice F: Präsentations-Vorlage je Betrieb — greift beim Betriebs-Link (Foodbook/Speisekarte). --}}
                                                <x-fa::field label="Präsentations-Vorlage für den Betriebs-Link" for="betrieb-vorlage-{{ $b->id }}"
                                                    hint="Leer: jedes Dokument behält seine eigene Vorlage. Gesetzt: dieser Betrieb bekommt beim Betriebs-Link diese Optik.">
                                                    <x-fa::select id="betrieb-vorlage-{{ $b->id }}" wire:model="form.vorlage">
                                                        <option value="">Vorlage des Dokuments</option>
                                                        @foreach($vorlagenOptionen as $vo)
                                                            <option value="{{ $vo['value'] }}">{{ $vo['label'] }}</option>
                                                        @endforeach
                                                    </x-fa::select>
                                                </x-fa::field>

                                                {{-- Präsentations-Logo je Betrieb — ersetzt beim Betriebs-Link das Dokument-Logo (leer = Dokument-Logo). --}}
                                                <x-fa::field label="Präsentations-Logo für den Betriebs-Link" for="betrieb-logo-{{ $b->id }}" error="logoUpload"
                                                    hint="Leer: jedes Dokument zeigt sein eigenes Logo. Gesetzt: dieses Logo ersetzt es beim Betriebs-Link.">
                                                    <div class="flex flex-wrap items-center gap-3">
                                                        @if(($logoUrls[$b->id] ?? null))
                                                            <img src="{{ $logoUrls[$b->id] }}" alt="Logo {{ $b->name }}" class="h-10 max-w-[140px] object-contain rounded-[var(--fa-radius-control)] bg-[var(--fa-surface)] border border-[var(--fa-line)] px-1" />
                                                        @endif
                                                        <input id="betrieb-logo-{{ $b->id }}" type="file" wire:model="logoUpload" accept="image/*" class="min-w-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                                        <span wire:loading wire:target="logoUpload" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">wird hochgeladen</span>
                                                        @if(($logoUrls[$b->id] ?? null))
                                                            <x-fa::button size="sm" variant="danger" icon="heroicon-o-trash" wire:click="logoLoeschen">Logo entfernen</x-fa::button>
                                                        @endif
                                                    </div>
                                                </x-fa::field>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                Betriebe werden nicht gelöscht, sondern deaktiviert. An ihnen hängen Ausgaben und Kapitel, eine Löschung würde diese Zuordnungen stillschweigend kappen.
            </p>
        @endif
    </x-fa::section>

    {{-- Spec 77c: jeder Standort ist ein Unter-Team; es fährt genau einen Betrieb des Oberteams, fest.
         „Standard" = keine eigene Zuordnung, der Standort erbt alles. --}}
    @if($standorte !== [])
        <x-fa::section title="Standorte" :meta="count($standorte) . ' Unter-Teams'" data-settings-standorte
            description="Jeder Standort ist ein eigenes Unter-Team mit eigenen Einkaufspreisen, Bestellungen und Lager. Hier legst du fest, welchen Betrieb er fährt — im Standort ist die Betriebs-Brille dann fest eingestellt.">
            <table class="fa-table fa-table--compact">
                <thead><tr><th>Standort</th><th>Betrieb</th></tr></thead>
                <tbody>
                    @foreach($standorte as $st)
                        <tr wire:key="st-{{ $st['id'] }}">
                            <td class="font-medium">{{ $st['name'] }}</td>
                            <td>
                                <x-fa::select class="w-64 max-w-full" aria-label="Betrieb von {{ $st['name'] }}" data-standort-betrieb="{{ $st['id'] }}"
                                    wire:change="standortBetriebSetzen({{ $st['id'] }}, $event.target.value)">
                                    <option value="" @selected($st['betrieb_id'] === null)>Standard (erbt alles)</option>
                                    @foreach($betriebe->where('is_inactive', false) as $b)
                                        <option value="{{ $b->id }}" @selected($st['betrieb_id'] === (int) $b->id)>{{ $b->name }}</option>
                                    @endforeach
                                </x-fa::select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-fa::section>
    @endif
    </fieldset>
</div>
