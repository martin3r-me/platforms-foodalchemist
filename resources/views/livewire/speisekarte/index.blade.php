{{-- Speisekarte (fa-pass 2026-10-05) — Karten links, Kundensicht in der Mitte, Detail rechts.
     Der Editor läuft als Vollbild-Modal im Werkbank-Modus (dark-canvas → data-fa-theme="dark") und
     nutzt nur --fa-*-Tokens und <x-fa::…>-Bausteine.
     Häufigste Aufgabe: die Karte aufbauen (Rubriken anlegen, Gerichte einsetzen, Reihenfolge, Preise prüfen).
     Laptop-Tauglichkeit: Kennzahlen stehen im Reiter „Aufbau“ statt fest im Kopf (mehr Höhe für die Arbeit),
     Rubrik- und Positionszeilen bündeln ihre Knöpfe in Menüs, der Katalog rechts passt seine Höhe an den
     Bildschirm an und rutscht unter 1024 px unter die Rubriken.
     Alle wire:-Bindungen, wire:keys, Event-Namen und data-Marker unverändert. --}}
@php
    $typLabel = ['alacarte' => 'À la carte', 'tageskarte' => 'Tageskarte', 'saisonkarte' => 'Saisonkarte', 'getraenkekarte' => 'Getränkekarte', 'weinkarte' => 'Weinkarte'];
    // Spec 33 P0: Status aus dem Enum; Zustandsfarbe über die Badge-Töne der Bausteine.
    $statusTon = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit', 'primary' => 'accent', 'info' => 'info'];
    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menueLoeschen = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $checkbox = 'inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]';
    $linkFeld = 'flex-1 min-w-0 rounded-[var(--fa-radius-control)] px-2.5 py-1.5 font-mono text-[length:var(--fa-text-sm)] break-all select-all bg-[var(--fa-ground)] border border-[var(--fa-line)] text-[var(--fa-ink)]';

    // Kennzahlen des Aufbaus: Positionen mit Preis, fehlende Preise, Wareneinsatz der ganzen Karte.
    $kennzahlen = null;
    if ($karte) {
        $verkaufsPositionen = $karte->sections->flatMap->items->whereIn('type', ['gericht_ref', 'menue_ref']);
        $ohnePreis = $verkaufsPositionen->filter(fn ($pos) => (($preise[$pos->id] ?? [])['vk'] ?? null) === null)->count();
        $summeVk = 0.0;
        $summeEk = 0.0;
        foreach ($verkaufsPositionen as $pos) {
            $pp = $preise[$pos->id] ?? [];
            if (($pp['vk'] ?? null) !== null && ($pp['ek'] ?? null) !== null) {
                $summeVk += (float) $pp['vk'];
                $summeEk += (float) $pp['ek'];
            }
        }
        $weGesamt = ($summeVk > 0 && $summeEk > 0) ? round($summeEk / $summeVk * 100) : null;
        $kennzahlen = [
            ['kpi' => 'positionen', 'label' => 'Positionen', 'value' => (string) $verkaufsPositionen->count(), 'primary' => true],
            ['kpi' => 'rubriken', 'label' => 'Rubriken', 'value' => (string) $karte->sections->whereNull('parent_id')->count()],
            ['kpi' => 'ohne-preis', 'label' => 'Ohne Preis', 'value' => (string) $ohnePreis, 'tone' => $ohnePreis > 0 ? 'crit' : 'ok'],
            ['kpi' => 'wareneinsatz', 'label' => 'Wareneinsatz', 'value' => $weGesamt !== null ? $weGesamt . ' %' : 'offen',
                'tone' => $weGesamt === null ? null : ($weGesamt <= 30 ? 'ok' : ($weGesamt <= 38 ? 'warn' : 'crit')),
                'title' => 'Einkauf zu Verkauf über alle Positionen mit beiden Preisen'],
        ];
    }
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Speisekarte" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Speisekarte'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Speisekarten" width="w-64">
            <div class="p-3 flex flex-col gap-2">
                <x-fa::input type="search" wire:model.live.debounce.300ms="search" placeholder="Karte suchen" aria-label="Karte suchen" />
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu" class="w-full" data-sk-neu>Neue Karte</x-fa::button>
                <div class="mt-1 flex flex-col gap-0.5">
                    @forelse($karten as $k)
                        <button type="button" wire:key="sk-{{ $k->id }}" wire:click="waehle({{ $k->id }})" data-sk-zeile="{{ $k->id }}"
                            class="w-full text-left px-2.5 py-2 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $karteId === $k->id ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                            @if($karteId === $k->id) aria-current="true" @endif>
                            <div class="text-[length:var(--fa-text-md)] font-medium break-words leading-snug">{{ $k->name }}</div>
                            <div class="mt-0.5 text-[length:var(--fa-text-sm)] {{ $karteId === $k->id ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink-3)]' }}">{{ $typLabel[$k->karten_typ] ?? $k->karten_typ }} · {{ $k->sections_count }} {{ $k->sections_count === 1 ? 'Rubrik' : 'Rubriken' }}</div>
                        </button>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-clipboard-document-list" title="Keine Karte gefunden">
                            {{ trim((string) $search) !== '' ? 'Suchbegriff ändern oder eine neue Karte anlegen.' : 'Mit „Neue Karte“ die erste Speisekarte anlegen.' }}
                        </x-fa::empty>
                    @endforelse
                </div>
                <div class="pt-1">{{ $karten->links('foodalchemist::components.fa.pagination') }}</div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechtes Detail (nur lesend): Logo · Status, Datum, Nummer · Eckdaten der Auswahl --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-72" scope="activity_speisekarte" side="right" icon="heroicon-o-information-circle" :default-open="true">
            @if($karte)
                @include('foodalchemist::livewire.speisekarte.partials.detail', ['karte' => $karte])
            @else
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    <x-fa::empty icon="heroicon-o-clipboard-document-list" title="Keine Karte gewählt">Links eine Karte anklicken, dann erscheinen hier Wareneinsatz, Rubriken und offene Punkte.</x-fa::empty>
                </div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if(! $karte)
            <div class="fa-surface">
                <x-fa::empty icon="heroicon-o-clipboard-document-list" title="Keine Speisekarte gewählt">
                    Links eine Karte wählen oder mit „Neue Karte“ eine anlegen.
                </x-fa::empty>
            </div>
        @else
            {{-- Seitenkopf: eine Hauptaktion (Karte bearbeiten öffnet den Editor als Vollbild-Modal),
                 Ausgaben und Duplizieren im Menü „Weitere Aktionen“. --}}
            <x-fa::page-header :title="$karte->name">
                <x-slot:actions>
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-64 fa-surface shadow-lg py-1">
                            <a href="{{ route('foodalchemist.speisekarte.dokument', $karte->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}"
                               title="Karte für den Gast, zum Drucken oder als PDF">
                                @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Karte drucken
                            </a>
                            <a href="{{ route('foodalchemist.speisekarte.praesentation', $karte->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}"
                               title="Digitale Karte so ansehen, wie der Gast sie sieht">
                                @svg('heroicon-o-device-phone-mobile', 'w-4 h-4 text-[var(--fa-ink-3)]') Digitale Karte ansehen
                            </a>
                            {{-- Technischer Bericht (Parität Foodbook): Profile und Filter für Preise, Lieferanten, Deklaration, Nährwerte. --}}
                            <a href="{{ route('foodalchemist.speisekarte.report', $karte->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}"
                               title="Ausführlicher Bericht mit Preisen, Lieferanten, Deklaration und Nährwerten, wie bei Konzept, Format und Foodbook">
                                @svg('heroicon-o-document-text', 'w-4 h-4 text-[var(--fa-ink-3)]') Bericht öffnen
                            </a>
                            <div class="my-1 border-t border-[var(--fa-line)]"></div>
                            <button type="button" role="menuitem" wire:click="duplizieren" x-on:click="offen = false" class="{{ $menuePunkt }}">
                                @svg('heroicon-o-document-duplicate', 'w-4 h-4 text-[var(--fa-ink-3)]') Karte duplizieren
                            </button>
                        </div>
                    </div>
                    <x-fa::button variant="primary" icon="heroicon-m-pencil-square" x-on:click="$dispatch('modal.open', { name: 'speisekarte-editor' })" data-sk-bearbeiten>Karte bearbeiten</x-fa::button>
                </x-slot:actions>
            </x-fa::page-header>
            <div class="-mt-2 flex flex-wrap items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                <span>{{ $typLabel[$karte->karten_typ] ?? $karte->karten_typ }}</span>
                <x-fa::badge :tone="$statusTon[$karte->statusWert()->badgeVariant()] ?? 'neutral'">{{ $karte->statusWert()->label() }}</x-fa::badge>
            </div>

            {{-- Live-Ergebnis (Kundensicht, nur lesend): Namen, Preise, Fußnoten. --}}
            @include('foodalchemist::livewire.speisekarte.partials.vorschau', ['vorschau' => $vorschau])

            {{-- ═══════════════ EDITOR = Vollbild-Modal (Foodbook-Muster) ═══════════════ --}}
            <x-foodalchemist::modal name="speisekarte-editor" fullscreen dark-canvas title="Speisekarte bearbeiten" :title-name="$name">
                <x-slot:titleExtra>
                    <x-fa::badge>{{ $typLabel[$karte->karten_typ] ?? $karte->karten_typ }}</x-fa::badge>
                    <x-fa::badge :tone="$statusTon[$karte->statusWert()->badgeVariant()] ?? 'neutral'">{{ $karte->statusWert()->label() }}</x-fa::badge>
                </x-slot:titleExtra>
                <x-slot:actions>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        {{-- Weitere Aktionen: Löschen nie direkt neben Speichern. --}}
                        <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                            <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-60 fa-surface shadow-lg py-1">
                                <a href="{{ route('foodalchemist.speisekarte.dokument', $karte->id) }}" target="_blank" role="menuitem" x-on:click="offen = false" class="{{ $menuePunkt }}">
                                    @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Karte drucken
                                </a>
                                <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                <button type="button" role="menuitem" wire:click="loeschen" wire:confirm="Diese Speisekarte wirklich löschen?" x-on:click="offen = false" class="{{ $menueLoeschen }}" data-sk-loeschen>
                                    @svg('heroicon-o-trash', 'w-4 h-4') Karte löschen
                                </button>
                            </div>
                        </div>
                        <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" wire:loading.attr="disabled" wire:target="speichern" data-sk-speichern>Speichern</x-fa::button>
                    </div>
                </x-slot:actions>

                {{-- Reiter über den Baustein editor-tabs. Werkstrang M Phase A (Spec 40 §6): vom Groben zum
                     Kleinen, Kontext zuerst (Zielgruppe und Niveau als Leitplanken), dann Aufbau. Panels bleiben
                     im DOM (x-show); die Leitstelle wird nicht neu eingehängt. --}}
                <x-foodalchemist::editor-tabs marker="sk" wire-key="sk-tabs-{{ $karte->id }}" :init="'kontext'"
                    :tabs="[
                        'kontext' => 'Kontext',
                        'aufbau' => 'Aufbau',
                        'branding' => 'Gestaltung und digitale Karte',
                        'leitstelle' => 'Leitstelle',
                    ]">

                {{-- ── Reiter: KONTEXT ──────────────────────────────────────────── --}}
                <div x-show="tab === 'kontext'" x-cloak class="pt-4 flex flex-col gap-4">
                    {{-- Leitplanken: gelten als Vorgabe für KI-Namen und Karten-Text dieser Karte. --}}
                    <x-fa::section title="Leitplanken" icon="heroicon-o-adjustments-horizontal" wire:key="sk-kontext-{{ $karte->id }}"
                        description="Gelten als Vorgabe für alle KI-Texte dieser Karte. Mit „Speichern“ oben übernehmen.">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-fa::field label="Gästeart" for="sk-kundentyp">
                                <x-fa::input id="sk-kundentyp" wire:model="kundentyp" placeholder="z. B. Mittagstisch im Büro, Fine-Dining-Gäste" />
                            </x-fa::field>
                            <x-fa::field label="Schreibstil" for="sk-schreibstil">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-fa::select id="sk-schreibstil" wire:model="writingStyleId" class="flex-1 min-w-40">
                                        <option value="">Kein Schreibstil</option>
                                        @foreach($schreibstile as $st)
                                            <option value="{{ $st->id }}">{{ $st->name }}</option>
                                        @endforeach
                                    </x-fa::select>
                                    {{-- Betextet die GANZE Speisekarte im gewählten Stil neu (nur auf Knopfdruck, kostet KI-Leistung). --}}
                                    <x-foodalchemist::ki-action action="speisekarteWordingGenerieren" variant="ai" icon="heroicon-o-sparkles" label="Karte neu betexten"
                                            :disabled="! $writingStyleId"
                                            title="Alle Positionen der Speisekarte im gewählten Schreibstil neu betexten"
                                            busy="Wird betextet …" class="shrink-0" data-sk-wording />
                                </div>
                                @error('speisekarteWording')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert" data-sk-wording-fehler>{{ $message }}</p>@enderror
                            </x-fa::field>
                            <x-fa::field label="Niveau" for="sk-niveau">
                                <x-fa::select id="sk-niveau" wire:model="niveau">
                                    <option value="">Keine Vorgabe</option>
                                    <option value="buergerlich">Bürgerlich</option>
                                    <option value="gehoben">Gehoben</option>
                                    <option value="fine_dining">Fine Dining</option>
                                </x-fa::select>
                            </x-fa::field>
                            <x-fa::field label="Convenience-Anteil" for="sk-convenience">
                                <x-fa::select id="sk-convenience" wire:model="convenience">
                                    <option value="">Keine Vorgabe</option>
                                    <option value="from_scratch">Alles selbst hergestellt</option>
                                    <option value="teil_convenience">Teilweise Convenience</option>
                                    <option value="voll_convenience">Überwiegend Convenience</option>
                                </x-fa::select>
                            </x-fa::field>
                        </div>
                    </x-fa::section>

                    {{-- Karten-Kopf: Name, Typ, Status und Laufzeit, Kunde, Einleitung. --}}
                    <x-fa::section title="Karte" icon="heroicon-o-clipboard-document-list" wire:key="sk-head-{{ $karte->id }}">
                        <x-slot:actions>
                            <x-foodalchemist::ki-action action="kiKartenText" variant="ai" icon="heroicon-o-sparkles" label="Einleitung vorschlagen" busy="Wird geschrieben …" data-sk-ki-einleitung />
                        </x-slot:actions>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                            <x-fa::field label="Name" for="sk-name" class="md:col-span-2">
                                <x-fa::input id="sk-name" wire:model="name" />
                            </x-fa::field>
                            <x-fa::field label="Kartentyp" for="sk-typ">
                                <x-fa::select id="sk-typ" wire:model="kartenTyp">
                                    @foreach($typLabel as $key => $lbl)
                                        <option value="{{ $key }}">{{ $lbl }}</option>
                                    @endforeach
                                </x-fa::select>
                            </x-fa::field>
                        </div>

                        @if($kiKartenVorschau !== null)
                            <x-fa::notice tone="info" title="Vorschlag für die Einleitung">
                                {{ $kiKartenVorschau }}
                                <x-slot:actions>
                                    <x-fa::button size="sm" variant="ghost" wire:click="kiKartenVerwerfen">Verwerfen</x-fa::button>
                                    <x-fa::button size="sm" icon="heroicon-m-check" wire:click="kiKartenUebernehmen">Übernehmen und speichern</x-fa::button>
                                </x-slot:actions>
                            </x-fa::notice>
                        @endif
                        @error('kiKartenVorschau')<x-fa::notice tone="crit">{{ $message }}</x-fa::notice>@enderror

                        {{-- Spec 33 P5: Status, Laufzeit und beide Zuordnungen aus dem geteilten Bauteil,
                             dieselbe Bedienung wie in Foodbook und Speiseplan. --}}
                        <div class="pt-3 border-t border-[var(--fa-line)]">
                            <x-foodalchemist::ausgabe-status
                                status-model="status" von-model="gueltigVon" bis-model="gueltigBis"
                                outlet-model="outletId"
                                :betriebe="$betriebe" :zustand="$karte->laufZustand()" :grund="$karte->laufGrund()"
                                :konflikt="$portfolioKonflikt" toggle="aktivUmschalten" />
                        </div>
                        <x-foodalchemist::crm-kunde-picker
                            :ausgabe="$karte" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
                    </x-fa::section>

                    {{-- #7 (2026-08-27): Preisanzeige wirkt NUR auf die ausgegebene Karte (Druck, digitale Karte),
                         nie auf die gespeicherten Netto-Preise. --}}
                    <x-fa::section title="Preisanzeige" icon="heroicon-o-currency-euro" wire:key="sk-preis-{{ $karte->id }}" data-sk-preisanzeige
                        description="Gilt nur für die ausgegebene Karte. Die gespeicherten Netto-Preise bleiben unberührt.">
                        <x-slot:actions>
                            <x-fa::button size="sm" wire:click="speichern" data-sk-preis-speichern>Preisanzeige speichern</x-fa::button>
                        </x-slot:actions>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
                            <x-fa::field label="Preise anzeigen als" for="sk-brutto">
                                <x-fa::select id="sk-brutto" wire:model.live="preisAnzeigeBrutto" data-sk-brutto>
                                    <option value="1">Brutto (inkl. MwSt.)</option>
                                    <option value="0">Netto</option>
                                </x-fa::select>
                            </x-fa::field>
                            <x-fa::field label="Rundung der Bruttopreise" for="sk-rundung" :hint="$preisAnzeigeBrutto ? null : 'Nur bei Bruttopreisen.'">
                                <x-fa::select id="sk-rundung" wire:model="preisRundung" :disabled="! $preisAnzeigeBrutto" data-sk-rundung>
                                    <option value="keine">Keine (auf den Cent)</option>
                                    <option value="auf_10">Auf 0,10 €</option>
                                    <option value="auf_50">Auf 0,50 €</option>
                                    <option value="auf_90">Aufgerundet auf X,90</option>
                                </x-fa::select>
                            </x-fa::field>
                        </div>
                    </x-fa::section>
                </div>{{-- /Reiter KONTEXT --}}

                {{-- ── Reiter: LEITSTELLE ───────────────────────────────────────── --}}
                <div x-show="tab === 'leitstelle'" x-cloak class="pt-4 flex flex-col gap-4">
                    <livewire:foodalchemist.speisekarte.leitstelle-rail :karte-id="$karte->id" wire:key="sk-ls-rail-{{ $karte->id }}" />
                </div>{{-- /Reiter LEITSTELLE --}}

                {{-- ── Reiter: GESTALTUNG UND DIGITALE KARTE ────────────────────── --}}
                <div x-show="tab === 'branding'" x-cloak class="pt-4 flex flex-col gap-4">
                    {{-- Gestaltung (Stufe C) --}}
                    <x-fa::section title="Gestaltung" icon="heroicon-o-swatch" wire:key="sk-brand-{{ $karte->id }}"
                        description="Farben, Fußzeile, Logo und Titelbild für Druck und digitale Karte.">
                        <x-slot:actions>
                            <x-fa::button size="sm" wire:click="brandingSpeichern">Gestaltung speichern</x-fa::button>
                        </x-slot:actions>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div class="flex flex-col gap-4">
                                <x-fa::field label="Markenfarbe" for="sk-brandfarbe" error="brandColor">
                                    <div class="flex items-center gap-2">
                                        <input type="color" wire:model="brandColor" aria-label="Markenfarbe wählen" class="h-9 w-14 shrink-0 rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)]" />
                                        <x-fa::input id="sk-brandfarbe" wire:model="brandColor" class="w-32 font-mono" />
                                    </div>
                                </x-fa::field>
                                <x-fa::field label="Farbe der Bänder" for="sk-bandfarbe" optional hint="Leer lassen, dann gilt die Markenfarbe.">
                                    <x-fa::input id="sk-bandfarbe" wire:model="bandColor" placeholder="wie Markenfarbe" class="w-40 font-mono" />
                                </x-fa::field>
                                <x-fa::field label="Fußzeile" for="sk-fusszeile">
                                    <x-fa::input id="sk-fusszeile" wire:model="footerText" placeholder="z. B. Restaurant Adler · Musterstraße 1" />
                                </x-fa::field>
                            </div>
                            <div class="flex flex-col gap-4">
                                <x-fa::field label="Logo">
                                    @if($logoPath)
                                        <div class="flex items-center gap-2">
                                            <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($karte?->logo_context_file_id, $logoPath) }}" alt="Logo" class="h-10 rounded-[var(--fa-radius-control)] bg-[var(--fa-surface)] border border-[var(--fa-line)] p-1" />
                                            <x-fa::icon-button icon="heroicon-o-trash" label="Logo entfernen" tone="danger" size="sm" wire:click="brandingLogoEntfernen" />
                                        </div>
                                    @endif
                                    <input type="file" wire:model="logoUpload" accept="image/*" aria-label="Logo hochladen" class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" />
                                    <div wire:loading wire:target="logoUpload" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Wird hochgeladen …</div>
                                </x-fa::field>
                                <x-fa::field label="Titelbild">
                                    @if($coverPath)
                                        <div class="flex items-center gap-2">
                                            <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($karte?->cover_context_file_id, $coverPath) }}" alt="Titelbild" class="h-14 rounded-[var(--fa-radius-control)] object-cover" />
                                            <x-fa::icon-button icon="heroicon-o-trash" label="Titelbild entfernen" tone="danger" size="sm" wire:click="brandingCoverEntfernen" />
                                        </div>
                                    @endif
                                    <input type="file" wire:model="coverUpload" accept="image/*" aria-label="Titelbild hochladen" class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" />
                                    <div wire:loading wire:target="coverUpload" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Wird hochgeladen …</div>
                                </x-fa::field>
                            </div>
                        </div>
                    </x-fa::section>

                    {{-- ═══ Spec 43: digitale Karte (Kundenlink + eingefrorener Stand + Freigabe) ═══ --}}
                    <x-fa::section title="Digitale Karte" icon="heroicon-o-device-phone-mobile" data-sk-praesentation
                        description="Ein Kundenlink zeigt einen eingefrorenen Stand der Karte. Änderungen erscheinen erst nach erneutem Veröffentlichen.">
                        <x-slot:actions>
                            <x-fa::button size="sm" variant="ghost" icon-right="heroicon-m-arrow-top-right-on-square" :href="route('foodalchemist.einstellungen', ['sektion' => 'praesentations-designs'])" target="_blank">Designs bearbeiten</x-fa::button>
                        </x-slot:actions>

                        @if($presentationHinweis)<x-fa::notice tone="ok" data-sk-praes-hinweis>{{ $presentationHinweis }}</x-fa::notice>@endif
                        @if($presentationFehler)<x-fa::notice tone="crit" data-sk-praes-fehler>{{ $presentationFehler }}</x-fa::notice>@endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-fa::field label="Design" for="sk-praes-design">
                                <x-fa::select id="sk-praes-design" wire:model="presentationDesign" data-sk-praes-design>
                                    @foreach($presentationDesignOptionen as $opt)
                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </x-fa::select>
                            </x-fa::field>
                            <x-fa::field label="Gültig bis" for="sk-praes-gueltig" required :hint="$presentationGueltigBis ? null : 'Pflicht zum Veröffentlichen.'">
                                <x-fa::input type="date" id="sk-praes-gueltig" wire:model="presentationGueltigBis" data-sk-praes-gueltig />
                            </x-fa::field>
                        </div>

                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <label class="{{ $checkbox }}"><input type="checkbox" wire:model="presentationPreisAnzeige"> Preise zeigen</label>
                            <label class="{{ $checkbox }}"><input type="checkbox" wire:model="presentationDeklaration"> Allergen-Legende zeigen</label>
                            {{-- Ebene 2 · Schutz der Preise beim erneuten Veröffentlichen --}}
                            <label class="{{ $checkbox }}" title="Aus: beim erneuten Veröffentlichen bleiben die eingefrorenen Preise stehen, neue Speisen kommen mit aktuellem Preis dazu. An: alle aktuellen Verkaufspreise übernehmen. Beim ersten Veröffentlichen gelten immer die aktuellen Preise."><input type="checkbox" wire:model="presentationPreiseAktualisieren"> Preise beim erneuten Veröffentlichen aktualisieren</label>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-fa::field label="Knopf auf der Karte: Text" for="sk-praes-cta-text" optional>
                                <x-fa::input id="sk-praes-cta-text" wire:model="presentationCtaText" placeholder="z. B. Tisch reservieren" />
                            </x-fa::field>
                            <x-fa::field label="Knopf auf der Karte: Link" for="sk-praes-cta-link" optional>
                                <x-fa::input type="url" id="sk-praes-cta-link" wire:model="presentationCtaLink" placeholder="https://…" />
                            </x-fa::field>
                        </div>

                        @if($presentationInfo['design_veraltet'] ?? false)
                            {{-- Bug-Runde 2026-09-17 #2: Der Link zeigt nur den eingefrorenen Stand. Ohne diesen
                                 Hinweis sieht man die Design-Änderung in der Vorschau, im Kundenlink aber nie. --}}
                            <x-fa::notice tone="warn" title="Design wurde nach dem Veröffentlichen geändert" data-fa-design-veraltet>
                                Der Kundenlink zeigt weiter den Stand vom {{ $presentationInfo['published_at'] ?? 'letzten Veröffentlichen' }}. Zum Übernehmen „Neu veröffentlichen“.
                            </x-fa::notice>
                        @endif

                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::button icon="heroicon-m-eye" :href="route('foodalchemist.speisekarte.praesentation', ['id' => $karte->id, 'design' => $presentationDesign])" target="_blank">Vorschau öffnen</x-fa::button>
                            @if($presentationInfo['enabled'] ?? false)
                                <x-fa::button variant="danger" wire:click="zuruckziehen" wire:confirm="Veröffentlichung zurückziehen? Der Link ist dann nicht mehr erreichbar." data-sk-praes-withdraw>Veröffentlichung zurückziehen</x-fa::button>
                            @endif
                            <span class="flex-1"></span>
                            <x-fa::button variant="primary" icon="heroicon-m-globe-alt" wire:click="veroeffentlichen" wire:confirm="Diesen Stand als Karte veröffentlichen? Der Stand wird eingefroren." data-sk-praes-publish :disabled="! $presentationGueltigBis">
                                {{ ($presentationInfo['enabled'] ?? false) ? 'Neu veröffentlichen' : 'Veröffentlichen' }}
                            </x-fa::button>
                        </div>

                        @if($presentationLink)
                            <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-3 flex flex-col gap-2" x-data="{ kopiert: false }">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="{{ $linkFeld }}" data-sk-praes-link>{{ $presentationLink }}</div>
                                    <x-fa::button size="sm" icon="heroicon-m-clipboard-document" x-on:click="navigator.clipboard.writeText('{{ $presentationLink }}'); kopiert = true; setTimeout(() => kopiert = false, 1600)">
                                        <span x-text="kopiert ? 'Kopiert' : 'Link kopieren'">Link kopieren</span>
                                    </x-fa::button>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                    <span>Freigegeben am {{ $presentationInfo['published_at'] ?? 'unbekannt' }} · gültig bis {{ $presentationInfo['expires_at'] ?? 'offen' }}</span>
                                    @if($presentationInfo['live'] ?? false)
                                        <x-fa::badge tone="ok">Erreichbar</x-fa::badge>
                                    @else
                                        <x-fa::badge tone="warn">Nicht erreichbar oder abgelaufen</x-fa::badge>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- ── Slice F: eigener Link je Betrieb ── --}}
                        <div class="pt-4 border-t border-[var(--fa-line)] flex flex-col gap-3">
                            <div>
                                <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Links je Betrieb</h4>
                                <p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[70ch]">Ein zusätzlicher Kundenlink pro Betrieb, eingefroren mit den Preisen und der Vorlage dieses Betriebs und mit eigener Freigabe. Der Standard-Link oben bleibt bestehen.</p>
                            </div>

                            @forelse($betriebsLinks as $bl)
                                <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-3 flex flex-col gap-2" x-data="{ kopiert: false }">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $bl['outlet_name'] }}</span>
                                        <x-fa::badge :tone="$bl['enabled'] ? 'ok' : 'neutral'">{{ $bl['enabled'] ? 'Freigegeben' : 'Zurückgezogen' }}</x-fa::badge>
                                        <span class="ml-auto text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Vorlage: {{ $bl['design'] }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="{{ $linkFeld }}">{{ $bl['url'] }}</div>
                                        <x-fa::button size="sm" icon="heroicon-m-clipboard-document" x-on:click="navigator.clipboard.writeText('{{ $bl['url'] }}'); kopiert = true; setTimeout(() => kopiert = false, 1600)">
                                            <span x-text="kopiert ? 'Kopiert' : 'Link kopieren'">Link kopieren</span>
                                        </x-fa::button>
                                        @if($bl['enabled'])
                                            <x-fa::button size="sm" variant="danger" wire:click="betriebZuruckziehen({{ $bl['outlet_id'] }})" wire:confirm="Diesen Betriebs-Link zurückziehen? Er ist dann nicht mehr erreichbar.">Zurückziehen</x-fa::button>
                                        @else
                                            <x-fa::button size="sm" wire:click="betriebWiederFreigeben({{ $bl['outlet_id'] }})">Wieder freigeben</x-fa::button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch kein Link für einen Betrieb angelegt.</p>
                            @endforelse

                            @if(count($betriebsOptionen) > 0)
                                <div class="rounded-[var(--fa-radius-control)] border border-dashed border-[var(--fa-line-strong)] p-3 flex flex-col gap-3">
                                    <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Link für einen weiteren Betrieb</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 items-end">
                                        <x-fa::field label="Betrieb" for="sk-bl-betrieb">
                                            <x-fa::select id="sk-bl-betrieb" wire:model="outletPublishId">
                                                <option value="">Betrieb wählen</option>
                                                @foreach($betriebsOptionen as $o)
                                                    <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Gültig bis" for="sk-bl-gueltig" optional>
                                            <x-fa::input type="date" id="sk-bl-gueltig" wire:model="outletPublishGueltigBis" />
                                        </x-fa::field>
                                        <x-fa::field label="Vorlage" for="sk-bl-design" optional>
                                            <x-fa::select id="sk-bl-design" wire:model="outletPublishDesign">
                                                <option value="">Vorlage des Betriebs</option>
                                                @foreach($presentationDesignOptionen as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Name im Link" for="sk-bl-slug" optional>
                                            <x-fa::input id="sk-bl-slug" wire:model="outletPublishSlug" placeholder="z. B. broich-nord-2027" />
                                        </x-fa::field>
                                    </div>
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Beliebig viele Betriebe möglich. Ohne eigenes Datum gilt das „Gültig bis“ des Standard-Links.</p>
                                        <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="betriebVeroeffentlichen">Betrieb hinzufügen</x-fa::button>
                                    </div>
                                </div>
                            @else
                                <x-fa::notice tone="warn">Noch keine Betriebe angelegt. Betriebe unter Einstellungen › Betriebe anlegen.</x-fa::notice>
                            @endif
                        </div>
                    </x-fa::section>
                </div>{{-- /Reiter GESTALTUNG --}}

                {{-- ── Reiter: AUFBAU (Rubriken + Positionen) ───────────────────── --}}
                <div x-show="tab === 'aufbau'" x-cloak class="pt-4 flex flex-col gap-4">
                    {{-- Kennzahlen des Aufbaus: hier statt fest im Kopf, damit auf dem Laptop mehr Höhe für die Arbeit bleibt. --}}
                    <x-fa::kpis :items="$kennzahlen" data-sk-editor-kpis />

                    {{-- Gemeinsamer Drag-and-drop-Zustand für ALLE (auch verschachtelten) Rubriken: ein x-data
                         am Container statt pro Rubrik. zu[id] klappt Rubriken einzeln auf und zu. --}}
                    <div class="fa-surface p-4 min-w-0" wire:key="sk-body-{{ $karte->id }}"
                         x-data="{ dragPosId: null, dragRubrikId: null, zu: {}, alleIds: @js($alleRubrikIds) }">

                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <x-fa::input wire:model="neueRubrik" wire:keydown.enter="rubrikNeu" placeholder="Neue Rubrik, z. B. Vorspeisen" aria-label="Name der neuen Rubrik" class="w-full sm:w-64" />
                            <x-fa::button icon="heroicon-m-plus" wire:click="rubrikNeu">Rubrik anlegen</x-fa::button>
                            @if(count($alleRubrikIds) > 0)
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-chevron-down" x-on:click="zu = {}" title="Alle Rubriken aufklappen" data-sk-alle-auf>Alle aufklappen</x-fa::button>
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-chevron-right" x-on:click="zu = Object.fromEntries(alleIds.map(i => [i, true]))" title="Alle Rubriken zuklappen, zum Umsortieren" data-sk-alle-zu>Alle zuklappen</x-fa::button>
                            @endif
                            <span class="flex-1"></span>
                            {{-- Spec 42: Planung (Auftrag, Gerüst, Hintergrund-Erstellung) lebt in der Leitstelle,
                                 die Karte ist reine Ausgabe. Der Knopf springt in die Leitstelle dieser Karte. --}}
                            <x-fa::button icon="heroicon-m-bolt" wire:click="vollKaskadeStarten" wire:loading.attr="disabled" data-sk-leitstelle>
                                <span wire:loading.remove wire:target="vollKaskadeStarten">In der Leitstelle planen</span>
                                <span wire:loading wire:target="vollKaskadeStarten">Wird geöffnet …</span>
                            </x-fa::button>
                        </div>

                        @if($kaskadeMeldung !== null)
                            <x-fa::notice tone="warn" class="mb-3">{{ $kaskadeMeldung }}</x-fa::notice>
                        @endif

                        {{-- Zwei Spalten: Rubriken links, Katalog rechts (Produktions-Muster). Unter 1024 px
                             steht der Katalog unter den Rubriken, damit nichts gequetscht wird. --}}
                        <div class="flex flex-col lg:flex-row gap-4 items-start" data-sk-2col>
                            <div class="w-full lg:flex-1 min-w-0" data-sk-rubriken>
                                @forelse($karte->sections->whereNull('parent_id') as $rubrik)
                                    @include('foodalchemist::livewire.speisekarte.partials.rubrik', ['rubrik' => $rubrik, 'depth' => 0])
                                @empty
                                    <x-fa::empty icon="heroicon-o-queue-list" title="Noch keine Rubrik">
                                        Oben einen Namen eintragen und „Rubrik anlegen“. Danach über „Hinzufügen“ Gerichte, Konzepte oder Pakete einsetzen.
                                    </x-fa::empty>
                                @endforelse
                            </div>

                            {{-- Katalog (geteilter Baustein): Gericht · Konzept · Paket · Format. Gericht, Konzept und
                                 Paket landen in der Ziel-Rubrik (über „Hinzufügen“ an einer Rubrik gewählt); ein Format
                                 wird zur eigenen Rubrik (F5: Editionen bleiben live verknüpft).
                                 Höhe folgt dem Bildschirm, damit die Liste auch auf dem Laptop bis unten erreichbar ist. --}}
                            <x-foodalchemist::katalog-picker marker="sk" switch="katalogModus"
                                class="max-lg:w-full max-lg:static max-lg:max-h-[28rem] lg:w-72 xl:w-80 2xl:w-96 lg:top-14 lg:max-h-[calc(100dvh-14rem)]"
                                :modes="[
                                    ['key' => 'gericht', 'label' => 'Gericht', 'active' => $pickerModus === 'gericht'],
                                    ['key' => 'konzept', 'label' => 'Konzept', 'active' => $pickerModus === 'konzept'],
                                    ['key' => 'paket', 'label' => 'Paket', 'active' => $pickerModus === 'paket'],
                                    ['key' => 'format', 'label' => 'Format', 'active' => $pickerModus === 'format'],
                                ]">
                                @if($pickerModus === 'format')
                                    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="formatSuche" placeholder="Format suchen" aria-label="Format suchen" class="w-full mb-2 shrink-0" data-sk-format-suche />
                                    @error('formatRubrik')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)] px-1 mb-1 shrink-0">{{ $message }}</p>@enderror
                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] px-1 mb-1 shrink-0">Setzt ein Format als eigene Rubrik ein. Seine Editionen bleiben verknüpft.</p>
                                    <div class="flex-1 overflow-y-auto flex flex-col gap-0.5">
                                        @forelse($formatKandidaten as $fk)
                                            <x-foodalchemist::katalog-row wire:key="skfmt-{{ $fk->id }}" wire:click="formatEinfuegen({{ $fk->id }})" data-sk-format-kand :title="$fk->consumer_name ?: $fk->name">{{ $fk->consumer_name ?: $fk->name }}</x-foodalchemist::katalog-row>
                                        @empty
                                            <x-fa::empty compact icon="heroicon-o-rectangle-group" :title="trim($formatSuche) !== '' ? 'Kein Format gefunden' : 'Noch keine Formate'" />
                                        @endforelse
                                    </div>
                                @else
                                    @php
                                        $istMenuePicker = in_array($pickerModus, ['konzept', 'paket'], true);
                                        $suchText = $pickerModus === 'konzept' ? 'Konzept suchen' : ($pickerModus === 'paket' ? 'Paket suchen' : 'Gericht suchen');
                                        $leerText = trim($pickerSuche) !== '' ? 'Nichts gefunden' : ($pickerModus === 'konzept' ? 'Noch keine Konzepte' : ($pickerModus === 'paket' ? 'Noch keine Pakete' : 'Noch keine Gerichte'));
                                    @endphp
                                    @if($pickerRubrikTitel !== null)
                                        <p class="mb-2 shrink-0 inline-flex items-center gap-1.5 px-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)]" data-sk-ziel>@svg('heroicon-m-arrow-down-tray', 'w-4 h-4 shrink-0')<span class="min-w-0 break-words">Ziel-Rubrik: {{ $pickerRubrikTitel }}</span></p>
                                    @else
                                        <p class="mb-2 shrink-0 px-1 text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" data-sk-ziel>Ziel-Rubrik wählen: links an einer Rubrik auf „Hinzufügen“ klicken.</p>
                                    @endif
                                    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="pickerSuche" :placeholder="$suchText" :aria-label="$suchText" class="w-full mb-2 shrink-0" data-sk-picker-suche />
                                    @if($pickerModus === 'gericht')
                                        {{-- Filter als Auswahllisten (Produktions-Muster): Warengruppe → Klasse. --}}
                                        <div class="grid grid-cols-2 gap-1.5 mb-2 shrink-0" data-sk-gericht-facetten>
                                            <x-fa::select size="sm" wire:model.live="pickerHauptgruppe" aria-label="Warengruppe" class="min-w-0" data-sk-facet-hg>
                                                <option value="">Alle Warengruppen</option>
                                                @foreach($pickerHauptgruppen as $hg)<option value="{{ $hg->id }}">{{ $hg->label }}</option>@endforeach
                                            </x-fa::select>
                                            <x-fa::select size="sm" wire:model.live="pickerDishClass" aria-label="Klasse" class="min-w-0" :disabled="$pickerUntergruppen->isEmpty()" data-sk-facet-klasse>
                                                <option value="">Alle Klassen</option>
                                                @foreach($pickerUntergruppen as $ug)<option value="{{ $ug->id }}">{{ $ug->label }}</option>@endforeach
                                            </x-fa::select>
                                        </div>
                                    @endif
                                    <div class="flex-1 overflow-y-auto flex flex-col gap-0.5">
                                        @forelse($pickerErgebnisse as $g)
                                            <x-foodalchemist::katalog-row wire:key="skpk-{{ $g->id }}" :disabled="$pickerRubrikTitel === null" wire:click="{{ $istMenuePicker ? 'positionAusMenue' : 'positionAusGericht' }}({{ (int) ($pickerRubrikId ?? 0) }}, {{ $g->id }})" :title="$g->name" :price="isset($g->sales_net) && $g->sales_net !== null ? number_format((float) $g->sales_net, 2, ',', '.') . ' €' : null">{{ $g->name }}</x-foodalchemist::katalog-row>
                                        @empty
                                            <x-fa::empty compact icon="heroicon-o-magnifying-glass" :title="$leerText" />
                                        @endforelse
                                    </div>
                                @endif
                            </x-foodalchemist::katalog-picker>
                        </div>{{-- /2 Spalten --}}
                    </div>{{-- /sk-body --}}
                </div>{{-- /Reiter AUFBAU --}}
                </x-foodalchemist::editor-tabs>
            </x-foodalchemist::modal>
        @endif

    {{-- Bug-Runde 2026-09-17 #1 (Nachbesserung): aus der Karte heraus öffnet das Gericht bzw. das
         Konzept IM Editor, nicht in einem neuen Tab. Dieselben Editor-Modale wie im Rezept-/
         Concepter-Browser; sie stehen auf Seitenebene NACH dem Speisekarten-Modal, damit sie
         darüber liegen (beide tragen z-[100], der spätere im DOM gewinnt). --}}
    <livewire:foodalchemist.verkauf.vk-modal />
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.recipes.pairing-netz-modal />{{-- „Netz öffnen" aus dem Rezept-/Gericht-Editor --}}
    <livewire:foodalchemist.concepter.editor />
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
