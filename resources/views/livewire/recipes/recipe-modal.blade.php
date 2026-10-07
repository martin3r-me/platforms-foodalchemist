{{-- Rezept-Editor (Basisrezept) — EIN Voll-Editor im Werkbank-Modus (darkCanvas → data-fa-theme="dark").
     fa-pass: nur --fa-*-Tokens und x-fa-Bausteine, damit hell UND dunkel stimmen.

     Anatomie: Kopf = Titel + Name + Status · rechts KI-Assistent (Menü) · Weitere Aktionen (Menü,
     Löschen ganz unten) · Speichern (die eine Hauptaktion). Kennzahlen fix im Kopf, EK je kg als
     Hauptzahl. Häufigste Arbeit = Zutaten und Mengen → Reiter «Aufbau» zuerst. --}}
@php
    $kg = fn ($wert) => $wert === null ? null : rtrim(rtrim(number_format((float) $wert, 3, ',', '.'), '0'), ',');
    $euro = fn ($wert) => $wert === null ? null : number_format((float) $wert, 2, ',', '.') . ' €';
    // Herkunft eines KI-fähigen Textfelds (RecipeModal::render → $zustaende) lesbar statt Rohwert.
    $herkunftText = fn (?string $z) => [
        'unbefüllt' => 'noch leer',
        'import' => 'importiert',
        'ki' => 'von der KI geschrieben',
        'manual' => 'von Hand gepflegt, die KI überschreibt ihn nicht',
        'auto' => 'automatisch erzeugt',
    ][$z ?? ''] ?? (string) $z;
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $knopfKi = 'inline-flex items-center gap-1.5 h-7 px-2.5 whitespace-nowrap rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] border border-[var(--fa-accent-line)] hover:bg-[var(--fa-accent-soft-hover)] transition-colors duration-150';
    $hinweis = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $laedt = 'py-12 text-center text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]';
    $statusWahl = ['stub' => 'Platzhalter', 'draft' => 'Entwurf', 'review' => 'Prüfen', 'approved' => 'Freigegeben', 'archived' => 'Archiviert'];
@endphp

{{-- R4 (Dominique): Voll-Editor nimmt den ganzen Bildschirm — 19-Zutaten-Rezepte brauchen die Fläche --}}
<x-foodalchemist::modal name="recipe-modal"
    :title="! $istOffen ? 'Basisrezept wird geladen' : ($neu ? 'Basisrezept anlegen' : 'Basisrezept')"
    :title-name="$istOffen && ! $neu ? $form['name'] : null"
    size="max-w-3xl" :fullscreen="! $neu" :dark-canvas="true">

    @if($istOffen && ! $neu)
        <x-slot:titleExtra>
            {{-- x-fa::status kennt «archived» (noch) nicht → sonst stünde dort englisch „Archived". --}}
            @if(($form['status'] ?? null) === 'archived')
                <x-fa::badge data-status="archived">Archiviert</x-fa::badge>
            @else
                <x-fa::status :value="$form['status'] ?? 'draft'" />
            @endif
            @if($istTemplate)<x-fa::badge tone="info" icon="heroicon-m-square-2-stack">Vorlage</x-fa::badge>@endif
        </x-slot:titleExtra>
    @endif

    <x-slot:actions>
        @if($istOffen)
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if(! $neu)
                    {{-- KI-Assistent: die drei rezeptweiten KI-Funktionen in EINEM Menü. Die Felder-KI
                         (Name, Kategorie, Beschreibung …) bleibt am jeweiligen Feld. --}}
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::button variant="ai" icon="heroicon-m-sparkles" icon-right="heroicon-m-chevron-down"
                            x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" data-ki-assistent>KI-Assistent</x-fa::button>
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-72 fa-surface shadow-lg py-1">
                            {{-- Alles anreichern bleibt ein ki-action: Fortschritt und Erfolg sieht man im Menü. --}}
                            <div class="px-3 py-2 flex flex-col gap-1">
                                <x-foodalchemist::ki-action action="allesAnreichern" variant="ai" icon="heroicon-o-sparkles" label="Alles anreichern"
                                    title="Text, Eigenschaften, Produktionsplanung, Equipment, Schritte, Aromen, Pairings, Eignung und Sensorik in einem Lauf. KI-Fotos laufen separat, Ersatz bleibt eine bewusste Verknüpfung von Hand."
                                    class="w-full justify-center" data-alles-anreichern busy="Wird angereichert …" flash="Angereichert" />
                                <span class="{{ $hinweis }}">Füllt alle leeren Felder in einem Lauf. Von Hand Gepflegtes bleibt stehen.</span>
                            </div>
                            <div class="my-1 border-t border-[var(--fa-line)]"></div>
                            <button type="button" role="menuitem" wire:click="$toggle('ueberarbeitenOffen')" x-on:click="offen = false" class="{{ $menuePunkt }}"
                                    title="Freie Anweisung: die KI überarbeitet Zutaten, Mengen, Zubereitung und Beschreibung. Erst Vorschau, dann übernehmen." data-ki-ueberarbeiten>
                                @svg('heroicon-o-pencil-square', 'w-4 h-4 text-[var(--fa-ink-3)]') Mit Anweisung überarbeiten
                            </button>
                            <button type="button" role="menuitem" wire:click="$toggle('copilotOffen')" x-on:click="offen = false" class="{{ $menuePunkt }}"
                                    title="Die KI prüft Mengen, Einheiten, überflüssige und fehlende Zutaten. Jeder Befund lässt sich einzeln übernehmen, das Rezept bleibt stehen." data-copilot>
                                @svg('heroicon-o-clipboard-document-check', 'w-4 h-4 text-[var(--fa-ink-3)]') Rezept prüfen lassen
                            </button>
                        </div>
                    </div>

                    {{-- Weitere Aktionen: Drucken · Vorlage · Löschen (ganz unten, rot — nie neben Speichern) --}}
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-60 fa-surface shadow-lg py-1">
                            <a href="{{ route('foodalchemist.rezepte.dokument', ['id' => $recipeId, 'profil' => 'produktion']) }}" target="_blank" role="menuitem"
                               x-on:click="offen = false" class="{{ $menuePunkt }}" title="Druck- und PDF-Bericht mit Profilen und Filtern" data-rezept-druck>
                                @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Rezept drucken
                            </a>
                            {{-- R6: Vorlage-Markierung (Basis für «Aus Vorlage» im Browser) --}}
                            <button type="button" role="menuitem" wire:click="templateToggle" x-on:click="offen = false" class="{{ $menuePunkt }}"
                                    title="Vorlage für neue Rezepte (im Browser: aus Vorlage anlegen)" data-template-toggle>
                                @svg('heroicon-o-square-2-stack', 'w-4 h-4 text-[var(--fa-ink-3)]') {{ $istTemplate ? 'Vorlage aufheben' : 'Als Vorlage markieren' }}
                            </button>
                            <div class="my-1 border-t border-[var(--fa-line)]"></div>
                            <button type="button" role="menuitem" wire:click="loeschen" x-on:click="offen = false"
                                    wire:confirm="Rezept wirklich löschen? Rezepte, die in anderen Rezepten stecken, bleiben geschützt."
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" data-rezept-loeschen>
                                @svg('heroicon-o-trash', 'w-4 h-4') Rezept löschen
                            </button>
                        </div>
                    </div>
                @endif

                {{-- #1b: EIN Speichern-Weg, sequenziert. Erst Stammdaten (`speichern`), dann — nur bei
                     Erfolg und nur im Bestand (Anlage hat noch keine Zutaten) — adressiert das Zutaten-
                     Speichern anstoßen (MVP-046). Der eingebettete Editor meldet `zutaten-persistiert`
                     zurück → beiZutatenPersistiert schließt. Kein paralleler Race, kein Früh-Schließen. --}}
                {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung,
                     der Editor bleibt im Lesemodus offen. Neuanlage braucht keine Sperre (modus neu = nur der Knopf). --}}
                <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check"
                        x-on:click="const warBestand = $wire.recipeId !== null; $wire.speichern().then(() => { if (warBestand && ! $wire.fehler && $wire.recipeId) $dispatch('zutaten-speichern', { recipeId: $wire.recipeId }) })"
                        data-rezept-speichern>{{ $neu ? 'Rezept anlegen' : 'Speichern' }}</x-fa::button>
                </x-foodalchemist::bearbeiten-leiste>
            </div>
        @endif
    </x-slot:actions>

    @if(! $istOffen)
        {{-- Der Browser öffnet diese bereits montierte Hülle optimistisch. Das eigentliche
             Rezept kommt im folgenden Livewire-Roundtrip; bis dahin niemals das leere
             Neuanlageformular vortäuschen. --}}
        <div class="h-full min-h-72 flex items-center justify-center" data-rezept-laedt>
            <div class="text-center flex flex-col items-center gap-2">
                <span class="h-8 w-8 rounded-full border-2 border-[var(--fa-accent-line)] border-t-[var(--fa-accent)] animate-spin"></span>
                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-2)]">Basisrezept wird geladen …</p>
            </div>
        </div>
    @else

    {{-- Kennzahlen fix im Modal-Kopf (scrollen nie weg). EIN Hauptwert: EK je kg. Fehlende Preise
         werden gezeigt, nicht als 0,00 € versteckt. --}}
    @if($voll !== null)
        @php
            $nPreis = (int) ($voll->ek_n_ingredients_priced ?? 0);
            $nZutat = (int) ($voll->ek_n_ingredients_total ?? 0);
            [$konfText, $konfTon] = ['high' => ['hoch', 'ok'], 'medium' => ['mittel', 'warn'], 'low' => ['niedrig', 'crit']][$voll->allergens_confidence ?? ''] ?? ['nicht bewertet', 'warn'];
            $ekKg = $voll->ek_per_kg_eur;
            $kennzahlen = [
                ['kpi' => 'yield', 'label' => 'Ertrag', 'value' => $kg($voll->yield_kg) !== null ? $kg($voll->yield_kg) . ' kg' : 'fehlt', 'tone' => $voll->yield_kg === null ? 'warn' : null],
                ['kpi' => 'ek', 'label' => 'EK gesamt', 'value' => $euro($voll->ek_total_eur) ?? 'Preis fehlt', 'tone' => $voll->ek_total_eur === null ? 'crit' : null],
                ['kpi' => 'ekkg', 'label' => 'EK je kg', 'value' => $ekKg !== null ? $euro($ekKg) : 'Preis fehlt', 'primary' => $ekKg !== null, 'tone' => $ekKg === null ? 'crit' : null],
                ['kpi' => 'priced', 'label' => 'Preise', 'value' => $nZutat === 0 ? 'keine Zutaten' : $nPreis . ' von ' . $nZutat,
                 'tone' => $nZutat > 0 && $nPreis >= $nZutat ? 'ok' : 'warn', 'title' => 'Zutaten mit Preis von allen Zutaten'],
                ['kpi' => 'allergen', 'label' => 'Allergen-Konfidenz', 'value' => $konfText, 'tone' => $konfTon],
            ];
        @endphp
        <x-slot:kpiHeader>
            <x-fa::kpis :items="$kennzahlen" data-editor-kpis />
        </x-slot:kpiHeader>
    @endif

    @if($fehler !== null)
        <x-fa::notice tone="crit" data-modal-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    {{-- Älterer Anreicherungslauf (falls noch offen); der Knopf nutzt inzwischen den Einzel-Lauf darunter. --}}
    @if($bulkRun !== null)
        <div @if($bulkRun->status === 'running') wire:poll.2s @endif data-anreichern-status>
            <x-fa::notice tone="info">
                @if($bulkRun->status === 'running')
                    Anreicherung läuft …
                @else
                    <span>{{ $bulkOffen }} {{ $bulkOffen === 1 ? 'Vorschlag' : 'Vorschläge' }} offen</span>@if($bulkRun->failed > 0)<span>, {{ $bulkRun->failed }} Fehler</span>@endif
                @endif
                @if($bulkRun->status !== 'running')
                    <x-slot:actions>
                        <x-fa::button size="sm" icon="heroicon-m-check" wire:click="bulkAlleUebernehmen" data-anreichern-uebernehmen>Alle übernehmen</x-fa::button>
                    </x-slot:actions>
                @endif
            </x-fa::notice>
        </div>
    @endif

    <x-foodalchemist::oneshot-ergebnis :anreicherung="$anreicherung" />

    {{-- KI-Überarbeiten (aus dem KI-Assistent): rezeptweit, deshalb über den Reitern — sichtbar,
         egal welcher Reiter gerade offen ist. --}}
    @if(! $neu && $ueberarbeitenOffen)
        <x-fa::section title="Mit Anweisung überarbeiten" icon="heroicon-o-pencil-square" data-ueberarbeiten-box>
            <x-slot:actions>
                <x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" wire:click="$toggle('ueberarbeitenOffen')" />
            </x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-fa::input wire:model="anweisung" wire:keydown.enter="kiUeberarbeiten"
                    placeholder="z. B. «mach das Rezept vegan und halbiere den Zucker»" class="flex-1 min-w-[16rem]" data-anweisung />
                <x-foodalchemist::ki-action action="kiUeberarbeiten" variant="ai" icon="heroicon-o-sparkles" label="Vorschlag holen"
                    data-ueberarbeiten-start busy="Denkt nach …" flash="Vorschlag da" />
            </div>
            @if($ueberarbeitung !== null)
                <div class="flex flex-col gap-2 max-h-72 overflow-y-auto rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-3 py-2.5 text-[length:var(--fa-text-md)]" data-ueberarbeiten-vorschau>
                    @if(is_string($ueberarbeitung['werte']['aenderungs_notiz'] ?? null))
                        <p class="font-medium text-[var(--fa-ink)]">{{ $ueberarbeitung['werte']['aenderungs_notiz'] }}</p>
                    @endif
                    @if(!empty($ueberarbeitung['werte']['zutaten']))
                        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Zutaten nach der Überarbeitung</p>
                        <ul class="flex flex-col gap-1">
                            @foreach($ueberarbeitung['werte']['zutaten'] as $z)
                                @if(is_array($z))
                                    @php $mv = $ueberarbeitung['match_vorschau'][$loop->index] ?? null; @endphp
                                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[var(--fa-ink-2)]" wire:key="uz-{{ $loop->index }}">
                                        <span class="text-[var(--fa-ink)]"><span class="tabular-nums">{{ $z['quantity'] ?? '?' }} {{ $z['einheit_slug'] ?? '' }}</span> {{ $z['text'] ?? 'ohne Text' }}</span>
                                        <x-fa::badge>{{ isset($z['id']) ? 'bestehende Zeile' : 'neue Zeile' }}</x-fa::badge>
                                        @if($mv)
                                            @php $zielArt = $mv['kind'] === 'gp' ? 'Grundprodukt' : 'Rezept'; @endphp
                                            @if($mv['status'] === 'matched')
                                                <x-fa::signal tone="ok" title="Bestehende Verknüpfung bleibt">{{ $zielArt }}: {{ $mv['ziel'] ?? 'ohne Namen' }}</x-fa::signal>
                                            @elseif($mv['status'] === 'grounded')
                                                <x-fa::signal tone="ok" icon="heroicon-m-link" title="Wird beim Übernehmen automatisch verknüpft">{{ $zielArt }}: {{ $mv['ziel'] ?? 'ohne Namen' }}</x-fa::signal>
                                            @else
                                                <x-fa::signal tone="warn" title="Kein Treffer im Bestand, nach dem Übernehmen anlegen">{{ $mv['primaer'] === 'basisrezept_anlegen' ? 'Basisrezept anlegen' : 'Grundprodukt anlegen' }}@if(($mv['shortlist'] ?? 0) > 0)<span>, {{ $mv['shortlist'] }} {{ $mv['shortlist'] === 1 ? 'Kandidat' : 'Kandidaten' }}</span>@endif</x-fa::signal>
                                            @endif
                                        @endif
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                        @php $hardstops = collect($ueberarbeitung['match_vorschau'] ?? [])->where('status', 'hardstop')->count(); @endphp
                        @if($hardstops > 0)
                            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" data-ueberarbeiten-hardstops>
                                {{ $hardstops }} {{ $hardstops === 1 ? 'Zutat hat' : 'Zutaten haben' }} keinen Treffer im Bestand. Nach dem Übernehmen als Grundprodukt oder Basisrezept anlegen, alle anderen werden automatisch verknüpft.
                            </p>
                        @endif
                    @endif
                    @if(is_string($ueberarbeitung['werte']['description'] ?? null))
                        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Neue Beschreibung</p>
                        <p class="text-[var(--fa-ink)]">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte']['description'], 280) }}</p>
                    @endif
                    @if(is_string($ueberarbeitung['werte']['preparation'] ?? null))
                        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Neue Zubereitung</p>
                        <p class="text-[var(--fa-ink)] whitespace-pre-line">{{ \Illuminate\Support\Str::limit($ueberarbeitung['werte']['preparation'], 400) }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::button size="sm" icon="heroicon-m-check" wire:click="ueberarbeitungUebernehmen" data-ueberarbeiten-uebernehmen>Vorschlag übernehmen ({{ round($ueberarbeitung['confidence'] * 100) }} %)</x-fa::button>
                    <x-fa::button size="sm" variant="ghost" wire:click="ueberarbeitungVerwerfen" data-ueberarbeiten-verwerfen>Verwerfen</x-fa::button>
                    <span class="{{ $hinweis }}">Übernehmen schreibt Zutaten und Texte. Von Hand Gepflegtes bleibt stehen.</span>
                </div>
            @endif
        </x-fa::section>
    @endif

    @if(! $neu && $copilotOffen)
        <x-foodalchemist::copilot-box :copilot="$copilot" :status="$copilotStatus" zeilen-wort="Zutat" />
    @endif

    {{-- Spec 28 / E0.1: sticky Tab-Leiste + Alpine-Scope liegen im Baustein `editor-tabs`
         (Panels bleiben hier und alle im DOM — der eingebettete Zutaten-Editor darf nicht neu
         gemountet werden). Start-Tab: «Aufbau», bei Neuanlage «Stammdaten» (Aufbau ist ohne
         Zutaten leer). Die drei Morph-Fallen (wire:key · x-effect-Reset · ein Scope für Leiste
         und Panels) stecken im Baustein. --}}
    <x-foodalchemist::editor-tabs marker="rezept" wire-key="rezept-tabs-{{ $recipeId ?? 'neu' }}" visit-action="tabLaden" :gesperrt="in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true)"
        :visited="array_keys($geladeneTabs)"
        :init="$neu ? 'eigenschaften' : 'aufbau'"
        :tabs="[
            'aufbau' => $neu ? null : 'Aufbau',
            'eigenschaften' => 'Stammdaten',
            'preparation' => 'Zubereitung',
            'details' => 'Deklaration',
            'regeneration' => $neu ? null : 'Regeneration und Behälter',
            'sensorik' => $neu ? null : 'Sensorik und Pairing',
            'feedback' => $neu ? null : 'Feedback',
            'notes' => 'Notizen',
            'verwaltung' => $neu ? null : 'Verwaltung',
        ]">

    {{-- ── Reiter: AUFBAU (Zutaten und Ertrag) ──────────────────────────── --}}
    <div x-show="tab === 'aufbau'" x-cloak class="pt-4 flex flex-col gap-4">
    @if(!$neu)
        <x-fa::section title="Zutaten" icon="heroicon-o-list-bullet" :meta="(string) ($voll?->ingredients?->count() ?? 0)">
            <x-slot:actions>
                {{-- Garverluste: feuert ins eingebettete zutaten-kern (Alpine garverluste() via Window-Event) —
                     lebt in einem ANDEREN x-data-Scope als der $wire-Call selbst, darum kein
                     <x-foodalchemist::ki-action> (das ruft $wire.<action> direkt); die Rückmeldung
                     kommt hier stattdessen über garverluste-fertig/-fehler (s. ingredient-editor.blade.php),
                     visuell identisch zur Komponente (Spinner/Haken/Fehler). --}}
                <button type="button" x-data="{ pending: false, ok: false, err: null }"
                        x-on:garverluste-fertig.window="pending = false; ok = true; err = null; setTimeout(() => ok = false, 1600)"
                        x-on:garverluste-fehler.window="pending = false; ok = false; err = $event.detail?.message || 'Fehler, bitte erneut versuchen.'"
                        x-on:click="pending = true; ok = false; err = null; $dispatch('garverluste-vorschlagen')"
                        :class="{ 'opacity-50 cursor-wait': pending }" :disabled="pending"
                        class="{{ $knopfKi }}" :title="err || 'Die KI schätzt den Garverlust je Zutat. Gespeichert wird erst mit Speichern.'" data-garverlust-ki>
                    <template x-if="pending"><span class="inline-flex items-center gap-1">@svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin')<span>Schätzt …</span></span></template>
                    <template x-if="!pending && ok"><span class="inline-flex items-center gap-1 text-[var(--fa-ok)]">@svg('heroicon-o-check', 'w-3.5 h-3.5')<span>Übernommen</span></span></template>
                    <template x-if="!pending && !ok && err"><span class="inline-flex items-center gap-1 text-[var(--fa-crit)]">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5')<span x-text="err"></span></span></template>
                    <template x-if="!pending && !ok && !err"><span class="inline-flex items-center gap-1">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5')<span>Garverluste schätzen</span></span></template>
                </button>
            </x-slot:actions>

            <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="$recipeId" :eingebettet="true" wire:key="zutaten-inline-{{ $recipeId }}-v{{ $zutatenVersion }}" />
        </x-fa::section>

        @if($voll !== null)
            @php
                $es = is_numeric(str_replace(',', '.', (string) ($form['yield_pieces'] ?? ''))) ? (float) str_replace(',', '.', (string) $form['yield_pieces']) : null;
                $stueckHinweis = $es !== null && $es > 0 && $voll->yield_kg !== null
                    ? '1 Stück ≈ ' . number_format((float) $voll->yield_kg / $es * 1000, 0, ',', '.') . ' g' . ($voll->ek_total_eur !== null ? ' · EK je Stück ≈ ' . number_format((float) $voll->ek_total_eur / $es, 2, ',', '.') . ' €' : '')
                    : 'Für Stückrezepte, z. B. 50 Törtchen. Rechnet kg und Stück ineinander um.';
            @endphp
            <x-fa::section title="Ertrag" icon="heroicon-o-scale">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-fa::field label="Ertrag von Hand (kg)" for="rezept-yield-manuell" optional
                        hint="{{ 'Leer lassen = Summe der Zutaten' . ($kg($voll->yield_kg) !== null ? ' (' . $kg($voll->yield_kg) . ' kg)' : '') . '. Ein Wert hier hat Vorrang.' }}">
                        <x-fa::input id="rezept-yield-manuell" wire:model="form.yield_kg_manual" numeric inputmode="decimal"
                            placeholder="{{ $kg($voll->yield_kg) !== null ? 'Summe: ' . $kg($voll->yield_kg) : 'Summe fehlt' }}" data-yield-manual />
                    </x-fa::field>
                    <x-fa::field label="Ertrag in Stück" for="rezept-ertrag-stueck" optional :hint="$stueckHinweis">
                        <x-fa::input id="rezept-ertrag-stueck" wire:model.live.debounce.500ms="form.yield_pieces" numeric inputmode="decimal" placeholder="z. B. 50" data-ertrag-stueck />
                    </x-fa::field>
                </div>
            </x-fa::section>
        @endif
    @endif
    </div>{{-- /Reiter AUFBAU --}}

    {{-- ── Reiter: ZUBEREITUNG (Equipment + Schritte) ───────────────────── --}}
    <div x-show="tab === 'preparation'" x-cloak class="pt-4 flex flex-col gap-4">
    @if($geladeneTabs['preparation'] ?? false)
    {{-- ZUBEREITUNG zuerst (wird häufiger gepflegt als das Equipment). Spec 27: strukturierte
         Schritte sind der Master, `recipes.preparation` ist nur ihr gerenderter Lese-Spiegel. --}}
    <x-fa::section title="Zubereitung" icon="heroicon-o-queue-list">
        @if(!$neu)
            <x-slot:actions>
                {{-- Spec 53: Produktfoto (Hero) erzeugen/ersetzen — läuft async (EnrichRecipeJob,
                     nurProduktfoto), gepollt über pruefeProduktfotoErgebnis. --}}
                @php $bildkosten = config('foodalchemist.ai.bildkosten_usd.models')['gpt-image-1.5'] ?? null; @endphp
                <x-foodalchemist::ki-action action="kiProduktfoto" variant="ai" icon="heroicon-o-photo" label="Produktfoto erzeugen"
                        busy="Malt …" flash="Foto erzeugt"
                        title="{{ 'Erzeugt oder ersetzt das Foto des fertigen Gerichts' . ($bildkosten !== null ? ', ca. ' . number_format($bildkosten, 3, ',', '.') . ' $ je Bild' : '') . '.' }}"
                        data-ki-produktfoto />
                <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen zur Zubereitung" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 fa-surface shadow-lg py-1">
                        <button type="button" role="menuitem" wire:click="manual_zubereitung" x-on:click="offen = false" class="{{ $menuePunkt }}" title="Die KI überschreibt die Zubereitung dann nicht mehr">
                            @svg('heroicon-o-lock-closed', 'w-4 h-4 text-[var(--fa-ink-3)]') Vor KI schützen
                        </button>
                        <button type="button" role="menuitem" wire:click="clear_zubereitung" x-on:click="offen = false" class="{{ $menuePunkt }}" title="Hebt die Herkunfts-Markierung auf, der Text bleibt stehen">
                            @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4 text-[var(--fa-ink-3)]') Herkunft zurücksetzen
                        </button>
                    </div>
                </div>
            </x-slot:actions>
        @endif
        @if($produktfotoLaeuft)
            <div wire:poll.2s="pruefeProduktfotoErgebnis" class="flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-info)]" data-produktfoto-laeuft>
                @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') KI-Produktfoto wird erzeugt …
            </div>
        @endif
        @if($produktfotoFehler)
            <x-fa::notice tone="crit" data-produktfoto-fehler>{{ $produktfotoFehler }}</x-fa::notice>
        @endif
        @if($neu)
            {{-- Anlage-Modus: es gibt noch keine Schritt-IDs (und damit keine Foto-Verknüpfung).
                 Freitext ist hier weiter erlaubt und wird beim Speichern in Schritte geparst. --}}
            <x-fa::field for="rezept-preparation-neu" hint="Zeilen mit ## werden Abschnitte, Zeilen mit 1. oder - werden Schritte. Nach dem Anlegen gibt es den Schritt-Editor mit Fotos.">
                {{-- Bewusst roh: der Platzhalter braucht echte Zeilenumbrüche (&#10;), ein Baustein-Attribut würde sie escapen. --}}
                <textarea id="rezept-preparation-neu" wire:model="form.preparation" rows="6" data-rezept-preparation
                          class="fa-control py-2 font-mono text-[length:var(--fa-text-md)] leading-relaxed"
                          placeholder="Optional schon eintippen, wird beim Speichern in Schritte umgewandelt.&#10;## Mise en Place&#10;1. …"></textarea>
            </x-fa::field>
        @else
            <livewire:foodalchemist.recipes.step-editor :recipe-id="$recipeId" wire:key="schritt-editor-{{ $recipeId }}-v{{ $fotoVersion }}" />
            <p class="{{ $hinweis }}">
                Text {{ $herkunftText($zustaende['preparation']) }}. Druck, Suche und Produktionsplanung lesen die Anleitung aus diesen Schritten.
            </p>
        @endif
    </x-fa::section>

    {{-- EQUIPMENT (§4.2.6) — gruppiert nach Vokabular-Gruppe. Chips = x-fa-Chip-Optik (echte Checkboxen). --}}
    @php $eqGewaehlt = $equipmentListe->filter(fn ($g) => in_array((string) $g->id, $form['equipment_ids'], true)); @endphp
    <x-fa::section title="Equipment" icon="heroicon-o-wrench-screwdriver" :meta="$eqGewaehlt->count() . ' gewählt'">
        @if(!$neu)
            <x-slot:actions>
                <x-foodalchemist::ki-action action="kiEquipment" variant="ai" icon="heroicon-o-sparkles" label="Equipment vorschlagen"
                    title="Vorschlag aus den Zutaten, landet in der Auswahl. Gespeichert wird erst mit Speichern."
                    busy="Wird ermittelt …" flash="Equipment ermittelt" />
            </x-slot:actions>
        @endif
        <div class="flex flex-col gap-3" data-rezept-equipment>
            <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3 pb-3 border-b border-[var(--fa-line)]" data-equipment-gewaehlt>
                <span class="sm:w-32 shrink-0 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Gewählt</span>
                @if($eqGewaehlt->isNotEmpty())
                    <span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-accent)] leading-snug">{{ $eqGewaehlt->pluck('name')->join(' · ') }}</span>
                @else
                    <span class="{{ $hinweis }}">Noch nichts gewählt.</span>
                @endif
            </div>
            @foreach($equipmentListe->groupBy(fn ($g) => $g->group_name ?? 'sonstig') as $gruppe => $geraete)
                <div class="flex flex-col sm:flex-row sm:items-start gap-1.5 sm:gap-3">
                    <span class="sm:w-32 shrink-0 sm:pt-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ ucfirst($gruppe) }}</span>
                    <div class="flex flex-wrap gap-1.5 min-w-0">
                        @foreach($geraete as $geraet)
                            @php $eqOn = in_array((string) $geraet->id, $form['equipment_ids'], true); @endphp
                            <label class="fa-chip" wire:key="eq-{{ $geraet->id }}">
                                <input type="checkbox" wire:model.live="form.equipment_ids" value="{{ $geraet->id }}" class="sr-only" />
                                <span class="gap-1">@if($eqOn)@svg('heroicon-m-check', 'w-3.5 h-3.5 shrink-0')@endif{{ $geraet->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-fa::section>
    @else
        <p class="{{ $laedt }}" data-rezept-tab-laedt="preparation">Zubereitung wird geladen …</p>
    @endif
    </div>{{-- /Reiter ZUBEREITUNG --}}

    {{-- ── Reiter: STAMMDATEN (Name, Einordnung, Eigenschaften, Text) ───── --}}
    <div x-show="tab === 'eigenschaften'" x-cloak class="pt-4 flex flex-col gap-4">
    @if($geladeneTabs['eigenschaften'] ?? false)
    <x-fa::section title="Stammdaten" icon="heroicon-o-identification">
        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Name: volle Breite, KI-Hilfe direkt am Feld --}}
            <div class="sm:col-span-2 flex flex-col gap-1.5">
                <div class="flex items-center justify-between gap-2">
                    <label for="rezept-name" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Name<span class="text-[var(--fa-crit)]" aria-hidden="true"> *</span></label>
                    <x-foodalchemist::ki-action action="namePutzen" variant="ai" icon="heroicon-o-sparkles" label="Namen glätten"
                        title="Schreibweise nach dem Rezept-Regelwerk vereinheitlichen" busy="Wird geglättet …" flash="Name geglättet" />
                </div>
                <x-fa::input id="rezept-name" wire:model.live.debounce.300ms="form.name" placeholder="Schaumsauce: Beurre Blanc" data-rezept-name />
                <p class="{{ $hinweis }}">Schema «Typ: Bezeichnung (Variante)», jedes Wort groß.@if($keyVorschau !== '')<span> Kennung <span class="font-mono text-[var(--fa-ink-2)]" data-key-vorschau>{{ $keyVorschau }}</span>{{ $neu ? '' : ', bleibt stabil' }}.</span>@endif</p>
            </div>

            <x-fa::field label="Hauptgruppe" for="rezept-hauptgruppe" required hint="{{ $hauptgruppen->count() }} Hauptgruppen zur Auswahl">
                <x-fa::select id="rezept-hauptgruppe" wire:model.live="form.hauptgruppe_id" placeholder="Bitte wählen">
                    @foreach($hauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>

            <div class="flex flex-col gap-1.5 min-w-0">
                <div class="flex items-center justify-between gap-2">
                    <label for="rezept-kategorie" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Kategorie<span class="text-[var(--fa-crit)]" aria-hidden="true"> *</span></label>
                    @if(!$neu)
                        <x-foodalchemist::ki-action action="ai_kategorie" variant="ai" icon="heroicon-o-sparkles" label="Kategorie vorschlagen"
                            title="Vorschlag erscheint unter dem Feld, übernommen wird erst per Klick" busy="Wird ermittelt …" flash="Kategorie ermittelt" />
                    @endif
                </div>
                <x-fa::select id="rezept-kategorie" wire:model.live="form.category_id" placeholder="{{ $kategorien->isEmpty() ? 'Erst Hauptgruppe wählen' : 'Bitte wählen' }}" :disabled="$kategorien->isEmpty()">
                    @foreach($kategorien as $kat)<option value="{{ $kat->id }}">{{ $kat->label }}</option>@endforeach
                </x-fa::select>
                @if($kategorien->isNotEmpty())<p class="{{ $hinweis }}">{{ $kategorien->count() }} {{ $kategorien->count() === 1 ? 'Kategorie' : 'Kategorien' }} in dieser Hauptgruppe</p>@endif
            </div>

            @if(isset($kiVorschlag['category']))
                <div class="sm:col-span-2" data-kategorie-vorschlag>
                    <x-fa::notice tone="info">
                        KI-Vorschlag: <strong>{{ $kiVorschlag['category']['werte']['kategorie_name'] ?? $kiVorschlag['category']['werte']['category_id'] ?? 'ohne Namen' }}</strong> ({{ round($kiVorschlag['category']['confidence'] * 100) }} % sicher)
                        <x-slot:actions>
                            <x-fa::button size="sm" icon="heroicon-m-check" wire:click="accept_kategorie">Übernehmen</x-fa::button>
                        </x-slot:actions>
                    </x-fa::notice>
                </div>
            @endif

            @if($neu)
                <x-fa::field label="Status" hint="Neue Rezepte starten als Entwurf.">
                    <span data-rezept-status><x-fa::status value="draft" /></span>
                </x-fa::field>
            @else
                <x-fa::choice name="form.status" :live="false" label="Status" :options="$statusWahl" class="sm:col-span-2" data-rezept-status />
            @endif

            <x-fa::field label="Herkunft oder Quelle" for="rezept-herkunft" optional hint="Gehört nicht in den Namen.">
                <x-fa::input id="rezept-herkunft" wire:model="form.origin_source" placeholder="z. B. Broich, nach Paul, nach Omas Art" />
            </x-fa::field>

            {{-- Der Rezept-Typ ist nach der Anlage fest: ein Basisrezept wird nie selbst zum Gericht
                 (Sub-Rezept-Verweise, Picker und Speiseplan hängen am Typ). Verkauft wird es über ein
                 eigenes Gericht, das das Basisrezept als Komponente trägt. --}}
            @if(!$neu && !($form['is_sales_recipe'] ?? false))
                <div class="sm:col-span-2 flex flex-wrap items-center gap-3" data-rezept-als-gericht>
                    <span class="{{ $hinweis }}">Soll das verkauft werden? Dafür ein Gericht anlegen, das dieses Basisrezept als Komponente enthält.</span>
                    <x-fa::button size="sm" variant="secondary" icon="heroicon-m-plus"
                        x-on:click="Livewire.dispatch('vk-modal.oeffnen', { ausBasis: {{ (int) $recipeId }} })">Gericht anlegen</x-fa::button>
                </div>
            @endif

            @if(!$neu)
                {{-- Spec 43 (Bild-Epic): Gericht-Foto — optional in der Präsentation (Builder-Toggle „Gericht-Fotos") --}}
                <x-fa::field label="Gericht-Foto" optional class="sm:col-span-2" hint="Erscheint nur, wenn im Präsentations-Design die Gericht-Fotos eingeschaltet sind." data-rezept-bild>
                    <div class="flex flex-wrap items-center gap-3">
                        @if($dishImageUrl)
                            <img src="{{ $dishImageUrl }}" alt="" class="h-12 w-20 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                            <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="dishImageEntfernen">Foto entfernen</x-fa::button>
                        @endif
                        <input type="file" wire:model="dishImageUpload" accept="image/*" data-rezept-bild-upload
                               class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] file:mr-2 file:h-7 file:px-2.5 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:cursor-pointer">
                        <span wire:loading wire:target="dishImageUpload" class="{{ $hinweis }}">Lädt …</span>
                    </div>
                    @error('dishImageUpload')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]">{{ $message }}</p>@enderror
                </x-fa::field>
            @endif
        </div>
    </x-fa::section>

    {{-- BESCHREIBUNG (§8) --}}
    <x-fa::section title="Beschreibung" icon="heroicon-o-document-text" description="Drei bis fünf sachliche Sätze.">
        @if(!$neu)
            <x-slot:actions>
                <x-foodalchemist::ki-action action="ai_beschreibung" variant="ai" icon="heroicon-o-sparkles" label="Beschreibung schreiben"
                    data-ai-description busy="Wird geschrieben …" flash="Beschreibung erstellt" />
                <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen zur Beschreibung" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                    <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 fa-surface shadow-lg py-1">
                        <button type="button" role="menuitem" wire:click="manual_beschreibung" x-on:click="offen = false" class="{{ $menuePunkt }}" title="Aktuellen Text als von Hand gepflegt markieren, die KI überschreibt ihn dann nicht">
                            @svg('heroicon-o-lock-closed', 'w-4 h-4 text-[var(--fa-ink-3)]') Vor KI schützen
                        </button>
                        <button type="button" role="menuitem" wire:click="clear_beschreibung" x-on:click="offen = false" class="{{ $menuePunkt }}" title="Text und Herkunft leeren">
                            @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4 text-[var(--fa-ink-3)]') Beschreibung leeren
                        </button>
                    </div>
                </div>
            </x-slot:actions>
        @endif
        <x-fa::textarea wire:model="form.description" rows="3" aria-label="Beschreibung" />
        @if(isset($kiVorschlag['description']))
            <div data-description-vorschlag>
                <x-fa::notice tone="info" title="KI-Vorschlag ({{ round($kiVorschlag['description']['confidence'] * 100) }} % sicher)">
                    {{ $kiVorschlag['description']['werte']['description'] ?? 'ohne Text' }}
                    <x-slot:actions>
                        <x-fa::button size="sm" icon="heroicon-m-check" wire:click="accept_beschreibung">Übernehmen</x-fa::button>
                    </x-slot:actions>
                </x-fa::notice>
            </div>
        @endif
        @if(!$neu)<p class="{{ $hinweis }}">Text {{ $herkunftText($zustaende['description']) }}.</p>@endif
    </x-fa::section>

    {{-- EIGENSCHAFTEN (§4.2.4) — Zeiten, Mengen je Kochvorgang, Charakter --}}
    <x-fa::section title="Eigenschaften" icon="heroicon-o-adjustments-horizontal">
        <x-slot:actions>
            <x-foodalchemist::ki-action action="kiEigenschaften" variant="ai" icon="heroicon-o-sparkles" label="Eigenschaften schätzen"
                title="Arbeitszeit, Temperatur, Funktion und Geschmack schätzen. Landet in den Feldern, gespeichert wird erst mit Speichern."
                busy="Wird geschätzt …" flash="Eigenschaften geschätzt" />
        </x-slot:actions>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-fa::field label="Arbeitszeit (min)" for="rezept-arbeitszeit" hint="Aktiv, je Kochvorgang">
                <x-fa::input id="rezept-arbeitszeit" type="number" min="0" wire:model="form.work_time_min" numeric />
            </x-fa::field>
            <x-fa::field label="Gar- und Standzeit (min)" for="rezept-standzeit" hint="Passiv, z. B. Köcheln oder Ziehen (Durchlaufzeit)">
                <x-fa::input id="rezept-standzeit" type="number" min="0" wire:model="form.standzeit_min" numeric placeholder="0" data-recipe-standzeit />
            </x-fa::field>
            <x-fa::field label="Zusätzliche Personenminuten" for="rezept-var-zeit" hint="Wächst mit der Menge">
                <x-fa::input id="rezept-var-zeit" inputmode="decimal" wire:model="form.variable_work_time_min" numeric placeholder="0" />
            </x-fa::field>
            <x-fa::choice name="form.variable_work_time_basis" :live="false" label="Personenminuten je" :options="['kg' => 'kg', 'piece' => 'Stück', 'portion' => 'Portion']" />
            <x-fa::field label="Höchstmenge je Kochvorgang (kg)" for="rezept-topf-kg" hint="Leer = Standardkessel (20 kg)">
                <x-fa::input id="rezept-topf-kg" inputmode="decimal" wire:model="form.batch_max_kg" numeric placeholder="20" data-recipe-topf />
            </x-fa::field>
            <x-fa::field label="Höchstmenge je Kochvorgang (Stück)" for="rezept-topf-stueck" hint="Für Stückrezepte, leer = 200">
                <x-fa::input id="rezept-topf-stueck" inputmode="decimal" wire:model="form.batch_max_pieces" numeric placeholder="200" data-recipe-topf-stueck />
            </x-fa::field>
            <x-fa::field label="Temperatur" for="rezept-temperatur">
                <x-fa::input id="rezept-temperatur" wire:model="form.temperature" placeholder="z. B. raumtemperatur, warm, kalt" />
            </x-fa::field>
            <x-fa::field label="Funktion" for="rezept-funktion" hint="Vorschläge oder freier Text">
                {{-- Dropdown-Vorschläge via datalist — freie Eingabe bleibt möglich (bestehende Freitext-Werte gehen nicht verloren). --}}
                <x-fa::input id="rezept-funktion" wire:model="form.function" list="fa-function-optionen" placeholder="z. B. Komponente, Sauce, Bindung …" />
                <datalist id="fa-function-optionen">
                    @foreach(['Komponente', 'Hauptkomponente', 'Sauce', 'Bindung', 'Topping', 'Beilage', 'Garnitur', 'Fond / Basis', 'Marinade', 'Dekor', 'Füllung', 'Teig'] as $opt)
                        <option value="{{ $opt }}"></option>
                    @endforeach
                </datalist>
            </x-fa::field>
            <x-fa::choice name="form.taste_direction" :live="false" label="Geschmacksrichtung" :options="['' => 'Keine Angabe', 'suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral']" />
            <div class="flex flex-col gap-1.5 min-w-0">
                <x-fa::choice name="form.production_depth" :live="false" label="Fertigungstiefe" :options="['' => 'Keine Angabe', 'from_scratch' => 'selbst gemacht', 'teilfertig' => 'teilfertig', 'convenience' => 'Convenience']" />
                <div>
                    <x-foodalchemist::ki-action action="kiFertigung" variant="ai" icon="heroicon-o-sparkles" label="Aus Zutaten ermitteln"
                        title="Fertigungstiefe aus den Zutaten ableiten" busy="Wird ermittelt …" flash="Fertigung ermittelt" />
                </div>
            </div>
        </div>
    </x-fa::section>

    {{-- Produktion / Auto-Planer (2026-08-03): eigene Sektion — Parität mit dem Gericht-Editor.
         Der Auto-Planer (ProductionPlanService) routet über recipe.default_station_id. --}}
    <x-fa::section title="Produktionsplanung" icon="heroicon-o-calendar-days" description="Danach plant die Produktion dieses Rezept automatisch ein.">
        <div class="grid gap-4 sm:grid-cols-3" data-recipe-produktion>
            <x-fa::field label="Posten" for="rezept-posten">
                <x-fa::select id="rezept-posten" wire:model="form.default_station_id" placeholder="Kein Posten" data-recipe-default-station>
                    @foreach($posten as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Rüstzeit (min)" for="rezept-ruestzeit" hint="Einmal je Lauf">
                <x-fa::input id="rezept-ruestzeit" type="number" min="0" wire:model="form.setup_time_min" numeric placeholder="0" data-recipe-setup />
            </x-fa::field>
            <x-fa::field label="Vorproduzierbar (Tage)" for="rezept-vorlauf" hint="0 = nur am Einsatztag">
                <x-fa::input id="rezept-vorlauf" type="number" min="0" max="14" wire:model="form.max_vorlauf_tage" numeric placeholder="offen" data-recipe-vorlauf />
            </x-fa::field>
        </div>
        @if($posten->isEmpty())
            <x-fa::signal tone="warn">Noch keine Posten angelegt. Unter Einstellungen, Posten und Kapazität anlegen, dann hier zuweisen.</x-fa::signal>
        @endif
    </x-fa::section>

    {{-- EIGNUNG (M9-01k) — Detail-Panel-Kartei via section-Prop --}}
    <x-fa::section title="Eignung" icon="heroicon-o-check-badge" description="Für welches Niveau und welchen Sektor das Rezept passt.">
        @if($recipeId !== null)
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" :embedded="true" section="eignung" wire:key="reignung-{{ $recipeId }}" />
        @else
            <p class="{{ $hinweis }}">Die Eignung lässt sich nach dem ersten Speichern pflegen.</p>
        @endif
    </x-fa::section>

    {{-- ERSATZ (make-or-buy / Artikel-Ersatz) — Detail-Panel-Kartei via section-Prop (eine Quelle) --}}
    <x-fa::section title="Ersatz" icon="heroicon-o-arrows-right-left" description="Fertigprodukt statt Eigenherstellung oder umgekehrt.">
        @if($recipeId !== null)
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" :embedded="true" section="ersatz" wire:key="rersatz-{{ $recipeId }}" />
        @else
            <p class="{{ $hinweis }}">Ersatz lässt sich nach dem ersten Speichern verknüpfen.</p>
        @endif
    </x-fa::section>
    @else
        <p class="{{ $laedt }}" data-rezept-tab-laedt="eigenschaften">Stammdaten werden geladen …</p>
    @endif
    </div>{{-- /Reiter STAMMDATEN --}}

    {{-- ── Reiter: DEKLARATION — Allergene · Zusatzstoffe (Detail-Panel-Embed) + Nährwerte ── --}}
    <div x-show="tab === 'details'" x-cloak class="pt-4 flex flex-col gap-4">
        @if($geladeneTabs['details'] ?? false)
        @if($recipeId !== null)
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" :embedded="true" wire:key="rdetail-{{ $recipeId }}" />

            {{-- NÄHRWERTE (GL-08-Aggregat, nur lesen — Quelle: Zutaten-Neuberechnung) --}}
            <x-fa::section title="Nährwerte je 100 g" icon="heroicon-o-chart-pie">
                @if($voll?->nutri_kcal_per_100g === null)
                    <x-fa::signal tone="warn" data-naehrwerte-leer>Noch nicht berechnet. Das passiert beim nächsten Speichern der Zutaten.</x-fa::signal>
                @else
                    <dl class="grid grid-cols-2 sm:grid-cols-5 gap-3 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] px-3 py-3" data-naehrwerte>
                        @foreach([
                            ['Brennwert', $voll->nutri_kcal_per_100g, 'kcal', 0, null, null],
                            ['Eiweiß', $voll->nutri_protein_g_per_100g, 'g', 1, null, null],
                            ['Fett', $voll->nutri_fat_g_per_100g, 'g', 1, 'davon gesättigt', $voll->nutri_saturated_fat_g_per_100g],
                            ['Kohlenhydrate', $voll->nutri_carbs_g_per_100g, 'g', 1, 'davon Zucker', $voll->nutri_sugar_g_per_100g],
                            ['Salz', $voll->nutri_salt_g_per_100g, 'g', 2, null, null],
                        ] as [$lbl, $wert, $unit, $dez, $subLbl, $subWert])
                            <div class="flex flex-col gap-0.5 min-w-0" wire:key="rn-{{ $lbl }}">
                                <dt class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $lbl }}</dt>
                                <dd class="text-[length:var(--fa-text-base)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ $wert !== null ? number_format((float) $wert, $dez, ',', '.') . ' ' . $unit : 'fehlt' }}</dd>
                                @if($subLbl !== null)
                                    <dd class="text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-3)]" data-naehrwert-sub="{{ $subLbl }}">{{ $subLbl }} {{ $subWert !== null ? number_format((float) $subWert, 1, ',', '.') . ' g' : 'fehlt' }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                    @php [$nKonf, $nTon] = ['high' => ['hoch', 'ok'], 'medium' => ['mittel', 'warn'], 'low' => ['niedrig', 'crit']][$voll->nutri_confidence ?? ''] ?? ['nicht bewertet', 'warn']; @endphp
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 {{ $hinweis }}">
                        <x-fa::signal :tone="$nTon">Konfidenz {{ $nKonf }}</x-fa::signal>
                        <span>{{ $voll->nutri_n_ingredients_mapped ?? 0 }} von {{ $voll->nutri_n_ingredients_total ?? 0 }} Zutaten mit Nährwerten. Rohwerte aus dem Bundeslebensmittelschlüssel, Gar- und Putzverluste nicht eingerechnet.</span>
                    </p>
                @endif
            </x-fa::section>
        @else
            <x-fa::empty compact icon="heroicon-o-shield-check" title="Deklaration erscheint nach dem ersten Speichern" />
        @endif
        @else
            <p class="{{ $laedt }}" data-rezept-tab-laedt="details">Deklaration wird geladen …</p>
        @endif
    </div>

    {{-- ── Reiter: REGENERATION UND BEHÄLTER (Spec 51) ──────────────────────
         Der Default der Komponente: einmal hier gepflegt, von jedem Gericht geerbt. Gespeichert
         wird mit dem GLOBALEN Speichern-Knopf oben — kein zweiter Knopf im Reiter. --}}
    <div x-show="tab === 'regeneration'" x-cloak class="pt-4 flex flex-col gap-4">
        @if($regenMeldung !== null)<x-fa::notice tone="ok" data-regen-meldung>{{ $regenMeldung }}</x-fa::notice>@endif

        <x-fa::section title="Regeneration" icon="heroicon-o-fire"
            description="So kommt diese Komponente auf Temperatur. Gilt als Vorgabe in jedem Gericht, das sie enthält. Kein Gerät heißt kalt servieren, alles leer heißt keine Angabe und wird als Lücke gemeldet.">
            <div class="grid gap-3 grid-cols-2 sm:grid-cols-4" data-regen-selbst>
                <x-fa::field label="Gerät" for="regen-geraet" class="col-span-2 sm:col-span-1">
                    <x-fa::select id="regen-geraet" wire:model="regenForm.device_vocab_id" placeholder="kalt servieren">
                        @foreach($geraeteListe as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Temperatur (°C)" for="regen-temp">
                    <x-fa::input id="regen-temp" wire:model="regenForm.temp_c" numeric inputmode="decimal" />
                </x-fa::field>
                <x-fa::field label="Dauer (min)" for="regen-dauer">
                    <x-fa::input id="regen-dauer" wire:model="regenForm.duration_min" numeric inputmode="decimal" />
                </x-fa::field>
                <x-fa::field label="Kerntemperatur (°C)" for="regen-kern">
                    <x-fa::input id="regen-kern" wire:model="regenForm.core_temp_c" numeric inputmode="decimal" />
                </x-fa::field>
                <x-fa::field label="Hinweis" for="regen-hinweis" optional class="col-span-2 sm:col-span-4">
                    <x-fa::input id="regen-hinweis" wire:model="regenForm.note" placeholder="z. B. abgedeckt" />
                </x-fa::field>
            </div>
        </x-fa::section>

        @php
            $dichteText = ['fluessig' => 'flüssig', 'dicht' => 'dicht', 'schuettfaehig' => 'schüttfähig', 'locker' => 'locker'];
            $zweckText = ['abfuellen' => 'Abfüllen', 'regenerieren' => 'Regenerieren', 'ausgabe' => 'Ausgabe', 'transport' => 'Transport'];
        @endphp
        <x-fa::section title="Behälter je Zweck" icon="heroicon-o-archive-box"
            description="Abfüllen ist nicht Regenerieren: die Suppe kommt aus dem Kipper in Eimer und geht erst am Einsatztag ins GN. Ist der Behälter beim Regenerieren derselbe wie beim Abfüllen, zählt die Produktion ihn nur einmal.">
            <x-slot:actions>
                <x-foodalchemist::ki-action action="kiDichteklasse" variant="ai" icon="heroicon-o-sparkles" label="Dichteklasse schätzen"
                    title="Schätzt die Dichte des Produkts, nie die Zahl der Behälter"
                    data-ki-dichteklasse busy="Wird geschätzt …" flash="Geschätzt" />
            </x-slot:actions>

            <x-fa::field label="Dichteklasse" for="rezept-dichteklasse" hint="Auffangnetz: greift nur, wo keine Menge je Behälter steht." class="sm:max-w-sm">
                <x-fa::select id="rezept-dichteklasse" wire:model="dichteklasse" placeholder="Nicht gepflegt">
                    @foreach(\Platform\FoodAlchemist\Services\BehaelterRechner::DICHTE as $klasse => $kgProLiter)
                        <option value="{{ $klasse }}">{{ $dichteText[$klasse] ?? $klasse }} ({{ number_format($kgProLiter, 2, ',', '') }} kg/l)</option>
                    @endforeach
                </x-fa::select>
            </x-fa::field>

            <div class="overflow-x-auto">
                <table class="fa-table">
                    <thead>
                        <tr><th>Zweck</th><th>Behälter</th><th class="num">Menge je Behälter</th><th>Skalierung</th><th><span class="sr-only">Aktion</span></th></tr>
                    </thead>
                    <tbody>
                        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::ZWECKE as $zweck)
                            @php $lagen = ($behaelterForm[$zweck]['skalierung'] ?? '') === 'lagenware'; @endphp
                            <tr wire:key="bh-{{ $zweck }}" data-behaelter-zweck="{{ $zweck }}">
                                <td class="whitespace-nowrap font-medium">{{ $zweckText[$zweck] ?? ucfirst($zweck) }}</td>
                                <td class="min-w-[11rem]">
                                    <x-fa::select size="sm" wire:model="behaelterForm.{{ $zweck }}.container_vocab_id" placeholder="Kein Behälter" aria-label="Behälter für {{ $zweckText[$zweck] ?? $zweck }}">
                                        @foreach($behaelterListe as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                                    </x-fa::select>
                                </td>
                                <td class="num min-w-[7rem]">
                                    <x-fa::input size="sm" numeric wire:model="behaelterForm.{{ $zweck }}.{{ $lagen ? 'stueck_je_behaelter' : 'referenz_menge_kg' }}"
                                        placeholder="{{ $lagen ? 'Stück' : 'kg' }}" aria-label="{{ $lagen ? 'Stück je Behälter' : 'kg je Behälter' }}"
                                        title="{{ $lagen ? 'Wie viele Stück auf oder in genau diesen Behälter passen' : 'So viel passt in genau diesen Behälter. Am größten praktikablen angeben.' }}" />
                                </td>
                                <td class="min-w-[10rem]">
                                    <x-fa::select size="sm" wire:model.live="behaelterForm.{{ $zweck }}.skalierung" placeholder="Bitte wählen" aria-label="Skalierung">
                                        <option value="tiefer_fuellbar">tiefer füllbar</option>
                                        <option value="hoehe_gebunden">höhengebunden</option>
                                        <option value="lagenware">Lagenware</option>
                                    </x-fa::select>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if($zweck === 'regenerieren')
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-down-on-square" wire:click="behaelterUebernehmen('abfuellen', 'regenerieren')"
                                            title="Derselbe Behälter wie beim Abfüllen, kein Umfüllen" data-behaelter-durchgaengig>Wie Abfüllen</x-fa::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="{{ $hinweis }}">Tiefer füllbar: Sauce, Suppe · höhengebunden: Gulasch, Reis, Salat · Lagenware: wird gelegt, z. B. Schnitzel, Papadam</p>
        </x-fa::section>
    </div>

    {{-- ── Reiter: SENSORIK UND PAIRING (Geschmacks-Balance, Textur, Aroma-Zusammenhalt der Zutaten) ── --}}
    <div x-show="tab === 'sensorik'" x-cloak class="pt-4 flex flex-col gap-4">
        @if($geladeneTabs['sensorik'] ?? false)
        <x-fa::section title="Sensorik" icon="heroicon-o-beaker" description="Profil im gegarten Zustand. Die KI liest dafür Zutaten und Zubereitung.">
            @unless($neu)
                <x-slot:actions>
                    <x-foodalchemist::ki-action action="sensorikBewerten" variant="ai" icon="heroicon-o-sparkles" label="Sensorik neu bewerten"
                        busy="Bewertet …" flash="Sensorik bewertet" />
                </x-slot:actions>
            @endunless
            @include('foodalchemist::livewire.concepter.partials.sensorik')
        </x-fa::section>
        <x-fa::section title="Pairing" icon="heroicon-o-share">
            @include('foodalchemist::livewire.concepter.partials.pairing')
        </x-fa::section>
        @else
            <p class="{{ $laedt }}" data-rezept-tab-laedt="sensorik">Sensorik und Pairing werden geladen …</p>
        @endif
    </div>

    {{-- ── Reiter: FEEDBACK (R2.6 — Praxis-Feedback Küche/Kunde/Event) ───── --}}
    @if(! $neu && $recipeId !== null)
    @endif

    {{-- ── Reiter: NOTIZEN (§9.1 — manuelle Insel) ───────────────────────── --}}
    <div x-show="tab === 'notes'" x-cloak class="pt-4 flex flex-col gap-4">
    <x-fa::section title="Notizen" icon="heroicon-o-pencil" description="Bleibt bei jeder KI-Anreicherung unverändert.">
        <x-fa::textarea wire:model="form.notes_manual" rows="4" aria-label="Notizen" data-rezept-notes
            placeholder="z. B. Anpassung im Catering, Mengen-Korrektur, …" />
    </x-fa::section>
    </div>{{-- /Reiter NOTIZEN --}}

    {{-- ── Reiter: VERWALTUNG (tauschen + löschen — dasselbe Partial wie im Detail-Panel) ── --}}
    <div x-show="tab === 'verwaltung'" x-cloak class="pt-4 flex flex-col gap-4">
    {{-- KEIN &amp; im Titel: der Abschnitt escapt {{ $title }} selbst → „&AMP;". --}}
    <x-fa::section title="Rezept tauschen & löschen" icon="heroicon-o-arrows-right-left"
        description="Der Tausch hängt dieses Rezept in allen eigenen Gerichten und Basisrezepten, die es als Komponente führen, auf ein anderes um. Menge, Einheit und Verlust-Angaben der Zeilen bleiben stehen, die betroffenen Rezepte werden neu berechnet. Löschen geht erst, wenn nichts mehr darauf zeigt.">
        @include('foodalchemist::livewire.recipes.partials.verwaltung', ['rezeptName' => $form['name'] ?: 'dieses Rezept', 'kompakt' => false])
    </x-fa::section>
    </div>{{-- /Reiter VERWALTUNG --}}
        {{-- Spec 65: KI-Feedback auch ohne „Bearbeiten“ (Dominique 2026-10-07) --}}
        <x-slot:frei>
    <div x-show="tab === 'feedback'" x-cloak class="pt-4">
        @if($geladeneTabs['feedback'] ?? false)
        <livewire:foodalchemist.recipes.feedback-panel :recipe-id="$recipeId" wire:key="feedback-rez-{{ $recipeId }}" />
        @else
            <p class="{{ $laedt }}" data-rezept-tab-laedt="feedback">Feedback wird geladen …</p>
        @endif
    </div>
        </x-slot:frei>
    </x-foodalchemist::editor-tabs>

    @endif

    <x-slot:footer>
        {{-- #1b: es gibt nur den EINEN Speichern-Knopf oben in der Aktionsleiste (data-rezept-speichern).
             Hier bleibt bewusst nur „Abbrechen". --}}
        <x-fa::button variant="ghost" wire:click="$dispatch('modal.close', { name: 'recipe-modal' })">Abbrechen</x-fa::button>
    </x-slot:footer>
</x-foodalchemist::modal>
