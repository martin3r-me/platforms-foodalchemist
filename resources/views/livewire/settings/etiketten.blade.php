{{-- Spec 70 · Einstellungen → Etiketten: Vorlagen gestalten. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $haken = 'w-4 h-4 rounded accent-[var(--fa-accent)]';
@endphp
<div class="flex flex-col gap-4" data-settings-etiketten>
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
    @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif

    <div class="flex flex-wrap items-center justify-between gap-2" data-etiketten-aktionen>
        <span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $form['name'] ?? '' }}</span>
        <div class="flex flex-wrap gap-2">
            <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="neu" data-etiketten-neu>Neue Vorlage</x-fa::button>
            <x-fa::button size="sm" variant="ghost" wire:click="duplizieren">Duplizieren</x-fa::button>
            <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="loeschen" wire:confirm="Vorlage löschen?">Löschen</x-fa::button>
            <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="speichern" data-etiketten-speichern>Speichern</x-fa::button>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-2 items-start">
        <div class="flex flex-col gap-4 min-w-0">
            <x-fa::section title="Vorlagen" icon="heroicon-o-tag" :meta="$vorlagen->count()">
                <ul class="flex flex-col divide-y divide-[var(--fa-line)]">
                    @foreach($vorlagen as $v)
                        <li wire:key="lv-{{ $v->id }}">
                            <button type="button" wire:click="waehlen({{ $v->id }})" class="w-full text-left py-2 px-2 rounded-[var(--fa-radius-control)] hover:bg-[var(--fa-hover)] {{ $vorlageId === $v->id ? 'bg-[var(--fa-ground)]' : '' }}">
                                <div class="font-medium text-[var(--fa-ink)]">{{ $v->name }}{{ $v->is_default ? ' · Standard' : '' }}{{ $v->is_kitchen_default ? ' · Wandmonitor' : '' }}</div>
                                <div class="{{ $leise }}">{{ $formate[$v->format] ?? $v->format }} · {{ $v->typ === 'verkauf' ? 'Verkauf' : 'intern' }}</div>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-fa::section>

            {{-- Spec 78: Druckerprofile — Format + Ränder je Gerät, Standard je Arbeitsplatz --}}
            <x-fa::section title="Drucker" icon="heroicon-o-printer" :meta="$druckerListe->count()" description="Gedruckt wird über den Druckdialog des Browsers: Ränder „keine“, Maßstab 100 %." data-etiketten-drucker-liste>
                <x-slot:actions><x-fa::button size="sm" icon="heroicon-m-plus" wire:click="druckerNeu">Drucker</x-fa::button></x-slot:actions>
                @forelse($druckerListe as $p)
                    <button type="button" wire:click="druckerWaehlen({{ $p->id }})" wire:key="dr-{{ $p->id }}" class="w-full text-left px-2 py-1.5 rounded-[var(--fa-radius-control)] hover:bg-[var(--fa-hover)] {{ $druckerId === $p->id ? 'bg-[var(--fa-ground)]' : '' }}">
                        <div class="font-medium text-[var(--fa-ink)]">{{ $p->name }}{{ $p->is_default ? ' · Standard' : '' }}</div>
                        <div class="{{ $leise }}">{{ $modelle[$p->modell] ?? $p->modell }}{{ $p->arbeitsplatz ? ' · ' . ($arbeitsplaetze[$p->arbeitsplatz] ?? $p->arbeitsplatz) : '' }}</div>
                    </button>
                @empty
                    <p class="{{ $leise }}">Noch kein Drucker. Ohne Drucker wählt jede Vorlage ihr Format selbst.</p>
                @endforelse
                @if($druckerId !== null)
                    <div class="flex flex-col gap-2 border-t border-[var(--fa-line)] pt-2" data-etiketten-drucker-form>
                        <x-fa::field label="Name" for="dr-name"><x-fa::input id="dr-name" wire:model="drucker.name" placeholder="z. B. Küche Pass" /></x-fa::field>
                        <x-fa::field label="Modell" for="dr-modell"><x-fa::select id="dr-modell" wire:model.live="drucker.modell" :options="$modelle" /></x-fa::field>
                        @if(($drucker['format'] ?? '') === 'eigen' || ($drucker['modell'] ?? '') === 'eigen')
                            <div class="grid grid-cols-2 gap-2">
                                <x-fa::field label="Breite (mm)" for="dr-b"><x-fa::input id="dr-b" wire:model="drucker.breite_mm" inputmode="decimal" /></x-fa::field>
                                <x-fa::field label="Höhe (mm)" for="dr-h"><x-fa::input id="dr-h" wire:model="drucker.hoehe_mm" inputmode="decimal" /></x-fa::field>
                            </div>
                        @else
                            <x-fa::field label="Format" for="dr-format"><x-fa::select id="dr-format" wire:model="drucker.format" :options="$formate + ['eigen' => 'Eigenes Maß']" /></x-fa::field>
                        @endif
                        <div class="grid grid-cols-2 gap-2">
                            <x-fa::field label="Versatz links/rechts (mm)" for="dr-x"><x-fa::input id="dr-x" wire:model="drucker.versatz_x_mm" inputmode="decimal" /></x-fa::field>
                            <x-fa::field label="Versatz oben/unten (mm)" for="dr-y"><x-fa::input id="dr-y" wire:model="drucker.versatz_y_mm" inputmode="decimal" /></x-fa::field>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <x-fa::field label="Arbeitsplatz" for="dr-ap"><x-fa::select id="dr-ap" wire:model="drucker.arbeitsplatz" :options="$arbeitsplaetze" placeholder="–" /></x-fa::field>
                            <x-fa::field label="Betrieb" for="dr-betrieb"><x-fa::select id="dr-betrieb" wire:model="drucker.outlet_id" :options="$betriebe" placeholder="alle" /></x-fa::field>
                        </div>
                        <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)]"><input type="checkbox" wire:model="drucker.is_default" class="{{ $haken }}" /> Standard für diesen Arbeitsplatz</label>
                        <div class="flex gap-2">
                            <x-fa::button size="sm" variant="primary" wire:click="druckerSpeichern" data-etiketten-drucker-speichern>Speichern</x-fa::button>
                            @if($druckerId)<x-fa::button size="sm" variant="danger" wire:click="druckerLoeschen" wire:confirm="Drucker löschen? Vorlagen mit diesem Drucker nutzen dann wieder ihr eigenes Format.">Löschen</x-fa::button>@endif
                            <x-fa::button size="sm" variant="ghost" wire:click="$set('druckerId', null)">Abbrechen</x-fa::button>
                        </div>
                    </div>
                @endif
            </x-fa::section>

            <x-fa::section title="Gestaltung" icon="heroicon-o-swatch" data-etiketten-form>
                <x-fa::field label="Name" for="ef-name"><x-fa::input id="ef-name" wire:model="form.name" /></x-fa::field>
                <x-fa::field label="Typ" for="ef-typ" hint="Verkauf erzwingt die LMIV-Pflichtangaben (Zutaten, Menge, Hersteller).">
                    <x-fa::select id="ef-typ" wire:model.live="form.typ" :options="['intern' => 'Intern (Lager, Küche)', 'verkauf' => 'Verkauf (vorverpackt, To-Go)']" />
                </x-fa::field>
                <x-fa::field label="Drucker" for="ef-drucker" hint="Mit Drucker kommen Format und Ränder vom Drucker.">
                    <x-fa::select id="ef-drucker" wire:model.live="form.printer_id" :options="$druckerListe->pluck('name', 'id')" placeholder="– ohne Drucker (Format wählen) –" data-etiketten-drucker />
                </x-fa::field>
                @if(($form['printer_id'] ?? '') === '')
                    <x-fa::field label="Format" for="ef-format"><x-fa::select id="ef-format" wire:model="form.format" :options="$formate" /></x-fa::field>
                @endif
                <x-fa::field label="Betrieb (Logo, Hersteller)" for="ef-betrieb" hint="Ohne Betrieb bzw. ohne Betriebslogo: Food-Alchemist-Logo.">
                    <x-fa::select id="ef-betrieb" wire:model="form.outlet_id" :options="$betriebe" placeholder="– kein Betrieb –" />
                </x-fa::field>
                <x-fa::field label="Design (Farbe, Schrift)" for="ef-design">
                    <x-fa::select id="ef-design" wire:model="form.presentation_design" :options="$designs" placeholder="wie Betrieb / Standard" />
                </x-fa::field>
                <div class="grid grid-cols-2 gap-2">
                    <x-fa::field label="Allergene" for="ef-all"><x-fa::select id="ef-all" wire:model="form.allergen_darstellung" :options="['beides' => 'Klartext + Kürzel', 'klartext' => 'Klartext', 'kuerzel' => 'Kürzel']" /></x-fa::field>
                    <x-fa::field label="Schrift" for="ef-schrift"><x-fa::select id="ef-schrift" wire:model="form.schriftgroesse" :options="['s' => 'klein', 'm' => 'mittel', 'l' => 'groß']" /></x-fa::field>
                </div>
                <x-fa::field label="Fußtext" for="ef-fuss"><x-fa::input id="ef-fuss" wire:model="form.fusstext" placeholder="z. B. Hergestellt in unserer Küche" /></x-fa::field>
                <div class="flex flex-col gap-1.5 text-[length:var(--fa-text-md)]">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="form.datum_gross" class="{{ $haken }}" /> „Verbrauchen bis" groß und umrahmt</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="form.zeige_logo" class="{{ $haken }}" /> Logo drucken</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="form.is_default" class="{{ $haken }}" /> Standard-Vorlage</label>
                    <label class="inline-flex items-center gap-2" data-etikett-kueche-standard><input type="checkbox" wire:model="form.is_kitchen_default" class="{{ $haken }}" /> Standard für den Wandmonitor (Küche)</label>
                </div>
            </x-fa::section>
        </div>

        <div class="flex flex-col gap-4 min-w-0">
            <x-fa::section title="Felder" icon="heroicon-o-queue-list" description="Reihenfolge wie auf dem Etikett. Datumsfelder: vorbelegt (beim Druck änderbar) oder leer zum Handschreiben. Pflichtfelder sind gesperrt." data-etiketten-felder>
                <ul class="flex flex-col divide-y divide-[var(--fa-line)]">
                    @foreach($form['felder'] as $i => $f)
                        <li wire:key="ff-{{ $f['key'] }}" class="flex items-center gap-2 py-1.5">
                            <div class="flex flex-col">
                                <x-fa::icon-button size="sm" icon="heroicon-m-chevron-up" label="nach oben" wire:click="feldVerschieben({{ $i }}, -1)" :disabled="$loop->first" />
                                <x-fa::icon-button size="sm" icon="heroicon-m-chevron-down" label="nach unten" wire:click="feldVerschieben({{ $i }}, 1)" :disabled="$loop->last" />
                            </div>
                            <label class="flex-1 inline-flex items-center gap-2 text-[length:var(--fa-text-md)]">
                                <input type="checkbox" wire:model.live="form.felder.{{ $i }}.an" class="{{ $haken }}" @disabled($f['pflicht']) />
                                {{ $feldKatalog[$f['key']]['label'] ?? $f['key'] }}
                                @if($f['pflicht'])<x-fa::badge tone="neutral">Pflicht</x-fa::badge>@endif
                            </label>
                            @if(($feldKatalog[$f['key']]['art'] ?? '') === 'datum')
                                <select wire:model.live="form.felder.{{ $i }}.modus" class="fa-control fa-select h-7 pr-8 text-[length:var(--fa-text-sm)]" aria-label="Datums-Modus">
                                    <option value="vorbelegt">vorbelegt</option>
                                    <option value="leer">leer (von Hand)</option>
                                </select>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-fa::section>

        </div>
    </div>

    <x-fa::section title="Vorschau" icon="heroicon-o-eye" description="Gespeicherter Stand an einem echten Rezept — nach „Speichern“ aktualisiert.">
        @if($vorschauUrl)
            <iframe src="{{ $vorschauUrl }}" wire:key="ev-{{ md5($vorschauUrl) }}" class="w-full h-[640px] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" title="Etikett-Vorschau" data-etiketten-vorschau></iframe>
        @else
            <p class="{{ $leise }}">Für die Vorschau braucht es ein Rezept mit Zutaten.</p>
        @endif
    </x-fa::section>
</div>
