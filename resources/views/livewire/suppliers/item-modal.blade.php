{{-- Lieferantenartikel-Editor (M2-06/07/08, P-2/P-6). Lesend für die ganze Team-Kette, Pflege nur
     durch das Besitzer-Team (Curate/D1). Schließen ohne Speichern setzt den Zustand zurück (modal.closed).

     fa-pass (2026-10-05): Werkbank-Umbau. Nur --fa-*-Tokens und x-fa-Bausteine, damit hell UND dunkel
     stimmen. Logik-Durchgang: Am häufigsten wird ein Artikel geöffnet, um ihn einem Grundprodukt
     zuzuordnen und den Preis zu prüfen. Deshalb stehen «Grundprodukt» und «Preise» vorn, ein
     ungemappter Artikel öffnet direkt auf der Zuordnung. Stammdaten kommen meist aus dem Import und
     stehen hinten. Speichern ist die EINE Hauptaktion; Allergene, Zusatzstoffe und Nährwerte haben
     weiterhin eigene Speicher-Knöpfe in ihrem Abschnitt (getrennte Server-Methoden, unverändert).
     EAN-Felder werden von speichern() nicht geschrieben und sind deshalb nur noch lesbar. --}}
@php
    $aktivPreis = $aktiverPreis?->price !== null ? (float) $aktiverPreis->price : null;
    $bestellEinheit = $item?->ordering_unit ?: ($item?->unit_code ?: 'Einheit');
    $gpName = $item?->structure?->gp?->name;
    $allergenGesamt = count($allergenLabels);
    $allergenGepflegt = collect($allergenLabels)->keys()
        ->filter(fn ($k) => ($allergene[$k] ?? 'unbekannt') !== 'unbekannt')->count();
    $einheitKurz = fn (?string $u) => $u !== null ? ltrim(str_replace('€/', '', $u)) : null;

    // Herkunft der Deklaration (GL-07): NULL = Lieferanten-Import, manual = hier gepflegt, datei = Datei-Import.
    $quelleText = fn (?string $q) => match ($q) {
        'manual' => 'von Hand gepflegt',
        'datei' => 'aus Datei-Import',
        null, '' => 'aus dem Lieferanten-Import',
        default => 'aus einem Import',
    };

    // Rohe Match-Methoden des Vorschlagsdienstes lesbar machen.
    $grundText = fn (string $g) => match (true) {
        $g === 'exact_ean' => 'gleiche EAN',
        $g === 'exact_artno' => 'gleiche Artikelnummer',
        $g === 'fuzzy_name', $g === 'hybrid_lexical' => 'ähnlicher Name',
        str_starts_with($g, 'hybrid_') => 'ähnliche Bedeutung',
        default => 'ähnlicher Treffer',
    };

    $datum = fn ($wert) => $wert ? \Illuminate\Support\Carbon::parse($wert)->format('d.m.Y') : null;
    $proEinheitOk = $item !== null && (float) $item->qty > 0 && in_array($item->unit_code, ['kg', 'l', 'Stk'], true);

    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zeilenLabel = 'text-[length:var(--fa-text-md)] text-[var(--fa-ink)] min-w-0';
    $segment = 'h-7 px-2.5 text-[length:var(--fa-text-sm)] font-medium transition-colors duration-150 disabled:cursor-not-allowed';
    $segmentAus = 'text-[var(--fa-ink-3)]' . ($darfEdit ? ' hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]' : '');
    $allergenKnoepfe = [
        'nicht_enthalten' => ['nein', 'nicht enthalten', 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink)]'],
        'spuren' => ['Spuren', 'Spuren', 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]'],
        'enthalten' => ['enthalten', 'enthalten', 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]'],
    ];
    $stoffKnoepfe = [
        'nein' => ['nein', 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink)]'],
        'ja' => ['ja', 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]'],
    ];
@endphp
<div>
    <x-foodalchemist::modal name="item-modal" :title="$item !== null && $darfEdit ? 'Artikel bearbeiten' : 'Artikel'"
        :title-name="$item?->designation" :fullscreen="$item !== null" :dark-canvas="$item !== null">
        @if($item)
            <x-slot:actions>
                <div class="flex w-full flex-wrap items-center gap-2 min-w-0">
                    @if($darfEdit)
                        <p class="{{ $leise }} min-w-0">Speichern sichert Stammdaten, Verpackung und Eigenschaften.</p>
                        {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung --}}
                        <div class="ml-auto">
                            <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern"
                                    wire:loading.attr="disabled" wire:target="speichern" data-la-speichern>Speichern</x-fa::button>
                            </x-foodalchemist::bearbeiten-leiste>
                        </div>
                    @else
                        <x-fa::badge icon="heroicon-m-lock-closed" class="ml-auto"
                            title="Dieser Artikel kommt aus einem übergeordneten Katalog und wird dort gepflegt.">Nur lesen</x-fa::badge>
                    @endif
                </div>
            </x-slot:actions>

            {{-- Kennzahlen fix im Kopf. Leitwert = Einkaufspreis (accent). Grundprodukt und Allergene sind
                 echte Vollständigkeiten (good/warn). Fehlende Werte werden benannt, nicht als Strich versteckt. --}}
            <x-slot:kpiHeader>
                <x-foodalchemist::kpi-tiles marker="la-editor-kpis" :cols="5" :tiles="[
                    ['kpi' => 'ek', 'label' => 'Einkaufspreis je ' . $bestellEinheit, 'tone' => 'accent',
                     'title' => 'Gültiger Einkaufspreis, netto, je ' . $bestellEinheit,
                     'value' => $aktivPreis !== null ? number_format($aktivPreis, 2, ',', '.') . ' €' : 'Preis fehlt'],
                    ['kpi' => 'vergleichspreis', 'label' => $vergleichspreis !== null ? 'Preis je ' . $einheitKurz($vergleichspreis['unit']) : 'Preis je kg',
                     'title' => 'Auf die Kalkulationseinheit umgerechnet',
                     'tone' => $vergleichspreis === null && $aktivPreis !== null ? 'warn' : null,
                     'value' => $vergleichspreis !== null
                        ? number_format($vergleichspreis['value'], 2, ',', '.') . ' ' . $vergleichspreis['unit']
                        : ($aktivPreis !== null ? 'Menge fehlt' : 'Preis fehlt')],
                    ['kpi' => 'gp', 'label' => 'Grundprodukt', 'tone' => $gpName !== null ? 'good' : 'warn',
                     'title' => $gpName ?? 'Ohne Grundprodukt fließt dieser Artikel in keine Rezeptkalkulation.',
                     'value' => $gpName ?? 'nicht zugeordnet'],
                    ['kpi' => 'allergene', 'label' => 'Allergene bewertet',
                     'tone' => $allergenGepflegt >= $allergenGesamt ? 'good' : 'warn',
                     'title' => 'Bewertet von 14 Pflichtangaben. Ohne Angabe zählt als unbekannt.',
                     'value' => $allergenGepflegt . ' von ' . $allergenGesamt],
                    ['kpi' => 'lieferant', 'label' => 'Lieferant',
                     'title' => $item->supplier?->name ?? 'Lieferant unbekannt',
                     'value' => $item->supplier?->name ?? 'unbekannt'],
                ]" />
            </x-slot:kpiHeader>

            @if($fehler)
                <x-fa::notice tone="crit" class="mb-4" data-la-fehler>{{ $fehler }}</x-fa::notice>
            @endif

            {{-- Alpine-Modus: alle Reiter bleiben im DOM, damit die entangle-Bindings (Allergene,
                 Zusatzstoffe) und ungespeicherte Eingaben beim Umschalten erhalten bleiben. --}}
            <x-foodalchemist::editor-tabs marker="la" wire-key="la-tabs-{{ $item->id }}" :init="$gpName === null ? 'gp' : 'preise'" :gesperrt="$darfEdit && in_array($sperr['modus'], ['lesen', 'fremd'], true)"
                :tabs="[
                    'gp' => 'Grundprodukt',
                    'preise' => 'Preise',
                    'deklaration' => 'Allergene und Nährwerte',
                    'stammdaten' => 'Stammdaten',
                ]">

            {{-- ── Reiter GRUNDPRODUKT ─────────────────────────────────────────── --}}
            <div x-show="tab === 'gp'" x-cloak class="pt-4 flex flex-col gap-4">
                <x-fa::section title="Grundprodukt" icon="heroicon-o-cube"
                    description="Über das Grundprodukt fließen Preis und Allergene dieses Artikels in die Rezepte.">
                    <x-slot:actions>
                        {{-- MatchService: exakte Dubletten (EAN, Artikelnummer), Namensähnlichkeit und semantischer Recall (hybrid_*). Kein Chat-Modell, deshalb Lupe statt Sternchen. --}}
                        <x-foodalchemist::ki-action action="kiGpVorschlag" variant="ghostXs" icon="heroicon-o-magnifying-glass"
                            label="Grundprodukte vorschlagen" busy="Wird gesucht …" flash="Vorschläge geladen"
                            title="Sucht Grundprodukte mit gleicher EAN, gleicher Artikelnummer oder ähnlichem Namen" data-ki-gp-vorschlag />
                        @if(! $item->structure?->gp)
                            <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="gpNeuAnlegen" data-gp-neu-aus-la
                                title="Neues Grundprodukt anlegen, mit diesem Artikel als Quelle">Neues Grundprodukt anlegen</x-fa::button>
                        @endif
                    </x-slot:actions>

                    @if($item->structure?->gp)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2.5" data-gp-mapping-aktuell>
                            <p class="flex items-center gap-2 min-w-0 text-[length:var(--fa-text-base)] font-medium text-[var(--fa-ink)]">
                                @svg('heroicon-o-cube', 'w-4 h-4 shrink-0 text-[var(--fa-accent)]')
                                <span class="min-w-0 break-words">{{ $item->structure->gp->name }}</span>
                            </p>
                            <x-fa::button size="sm" variant="danger" icon="heroicon-m-link-slash" wire:click="gpLoesen"
                                wire:confirm="Zuordnung zum Grundprodukt lösen? War dieser Artikel der Leitartikel, wird sofort ein neuer gewählt."
                                data-gp-loesen>Zuordnung lösen</x-fa::button>
                        </div>
                    @else
                        <x-fa::empty compact icon="heroicon-o-link" title="Noch keinem Grundprodukt zugeordnet" data-gp-mapping-leer>
                            Ohne Grundprodukt fließt dieser Artikel in keine Rezeptkalkulation. Grundprodukte vorschlagen lassen, unten nach dem Namen suchen oder ein neues Grundprodukt anlegen.
                        </x-fa::empty>
                    @endif

                    @if($gpVorschlaege !== [])
                        <div class="flex flex-col gap-1" data-gp-vorschlaege>
                            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Vorschläge, Klick ordnet zu</p>
                            <div class="flex flex-col divide-y divide-[var(--fa-line)] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                @foreach($gpVorschlaege as $v)
                                    <button type="button" wire:key="gpv-{{ $v['gp_id'] }}" wire:click="gpZuweisen({{ $v['gp_id'] }})"
                                            class="flex items-center gap-3 w-full px-3 py-2 text-left hover:bg-[var(--fa-hover)] transition-colors duration-150" data-gp-vorschlag>
                                        <x-fa::badge :tone="$v['score'] >= 90 ? 'ok' : 'warn'" class="shrink-0 tabular-nums">{{ $v['score'] }} %</x-fa::badge>
                                        <span class="min-w-0 flex-1 break-words text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $v['name'] }}</span>
                                        @if($v['grund'] !== '')<span class="shrink-0 {{ $leise }}">{{ $grundText($v['grund']) }}</span>@endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-col gap-1.5" data-gp-zuweisen>
                        <label for="la-gp-suche" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                            {{ $item->structure?->gp ? 'Anderem Grundprodukt zuordnen' : 'Grundprodukt suchen und zuordnen' }}
                        </label>
                        <div class="relative">
                            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input id="la-gp-suche" type="search" wire:model.live.debounce.300ms="gpSuche"
                                placeholder="Name des Grundprodukts" class="pl-8" />
                        </div>
                        @if($gpKandidaten->isNotEmpty())
                            <div class="flex flex-col divide-y divide-[var(--fa-line)] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                @foreach($gpKandidaten as $kandidat)
                                    <button type="button" wire:key="gpk-{{ $kandidat->id }}" wire:click="gpZuweisen({{ $kandidat->id }})"
                                            class="flex items-center gap-2 w-full px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] transition-colors duration-150">
                                        @svg('heroicon-o-cube', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                        <span class="min-w-0 break-words">{{ $kandidat->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif(trim($gpSuche) !== '')
                            <p class="{{ $leise }}">Kein Grundprodukt mit diesem Namen gefunden.</p>
                        @endif
                    </div>
                </x-fa::section>
            </div>{{-- /Reiter GRUNDPRODUKT --}}

            {{-- ── Reiter PREISE ───────────────────────────────────────────────── --}}
            <div x-show="tab === 'preise'" x-cloak class="pt-4 flex flex-col gap-4" x-data="{ neuOffen: false }">
                <x-fa::section title="Preise" icon="heroicon-o-banknotes" :meta="$historie->count() > 0 ? $historie->count() . ' Einträge' : null">
                    <x-slot:actions>
                        @if($darfEdit)
                            <x-fa::button size="sm" icon="heroicon-m-plus" x-on:click="neuOffen = ! neuOffen"
                                x-bind:aria-expanded="neuOffen" data-preis-neu-toggle>Preis erfassen</x-fa::button>
                        @endif
                    </x-slot:actions>

                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] px-3 py-2.5" data-ek-aktuell>
                        <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Gültiger Einkaufspreis</span>
                        <span class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]"><x-fa::money :value="$aktivPreis" /></span>
                        <span class="{{ $leise }}">je {{ $bestellEinheit }}</span>
                        @if($vergleichspreis !== null)
                            <span class="{{ $leise }}">entspricht <x-fa::money :value="$vergleichspreis['value']" :per="$einheitKurz($vergleichspreis['unit'])" /></span>
                        @elseif($aktivPreis !== null)
                            <x-fa::signal tone="warn" title="Ohne Inhalt und Kalkulationseinheit kein Preis je kg, l oder Stück">Menge fehlt für den Preis je kg</x-fa::signal>
                        @endif
                    </div>

                    @if($darfEdit)
                        <div x-show="neuOffen" x-cloak class="flex flex-wrap items-end gap-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-3" data-preis-neu>
                            <x-fa::field label="Neuer Preis (€, netto)" for="la-preis-neu" class="w-40">
                                <x-fa::input id="la-preis-neu" wire:model="preisNeu.price" inputmode="decimal" placeholder="z. B. 47,50" numeric />
                            </x-fa::field>
                            <x-fa::choice name="preisNeu.status" id-prefix="la-preis" label="Art" :live="false"
                                :options="['0' => 'Standard-EK', '2' => 'Aktion']" />
                            <div class="flex flex-col gap-1">
                                <x-fa::button wire:click="preisAnlegen" icon="heroicon-m-plus">Preis anlegen</x-fa::button>
                            </div>
                            <p class="basis-full {{ $leise }}">Der bisher gültige Preis endet mit dem neuen Eintrag.</p>
                        </div>
                    @endif

                    <div class="overflow-x-auto -mx-1 px-1">
                        <table class="fa-table" data-preis-historie>
                            <thead>
                                <tr>
                                    <th>Gültig ab</th>
                                    <th>Gültig bis</th>
                                    <th>Art</th>
                                    <th class="num">Preis</th>
                                    <th class="num">Je {{ $proEinheitOk ? $item->unit_code : 'Einheit' }}</th>
                                    <th>Notiz</th>
                                    <th><span class="sr-only">Aktionen</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($historie as $p)
                                    <tr wire:key="preis-{{ $p->id }}">
                                        @if($preisEditId === $p->id)
                                            <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $datum($p->status_valid_from) ?? $datum($p->creation_date) ?? 'unbekannt' }}</td>
                                            <td>
                                                <x-fa::input size="sm" type="date" wire:model="preisEdit.valid_to" aria-label="Gültig bis" class="w-36" />
                                            </td>
                                            <td><x-fa::badge>{{ $p->category->label() }}</x-fa::badge></td>
                                            <td class="num">
                                                <x-fa::input size="sm" wire:model="preisEdit.price" inputmode="decimal" aria-label="Preis in Euro" numeric class="w-24" />
                                            </td>
                                            <td class="num text-[var(--fa-ink-3)]">wird neu berechnet</td>
                                            <td>
                                                <x-fa::input size="sm" wire:model="preisEdit.note" placeholder="Notiz" aria-label="Notiz" class="min-w-32" />
                                            </td>
                                            <td class="whitespace-nowrap text-right">
                                                <div class="inline-flex items-center gap-1.5">
                                                    <x-fa::button size="sm" variant="ghost" wire:click="preisEditAbbrechen">Abbrechen</x-fa::button>
                                                    <x-fa::button size="sm" icon="heroicon-m-check" wire:click="preisUpdate" data-preis-update>Preis sichern</x-fa::button>
                                                </div>
                                            </td>
                                        @else
                                            <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $datum($p->status_valid_from) ?? $datum($p->creation_date) ?? 'unbekannt' }}</td>
                                            <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $datum($p->valid_to) ?? 'offen' }}</td>
                                            <td><x-fa::badge :tone="$p->category->istAktiv() ? 'ok' : 'neutral'">{{ $p->category->label() }}</x-fa::badge></td>
                                            <td class="num font-medium"><x-fa::money :value="$p->price" /></td>
                                            <td class="num text-[var(--fa-ink-2)]">
                                                @if($p->price !== null && $proEinheitOk)
                                                    <x-fa::money :value="(float) $p->price / (float) $item->qty" :per="$item->unit_code" />
                                                @else
                                                    <span class="text-[var(--fa-ink-3)]">nicht berechenbar</span>
                                                @endif
                                            </td>
                                            <td class="text-[var(--fa-ink-2)] min-w-40 break-words">{{ $p->note ?: '' }}</td>
                                            <td class="whitespace-nowrap text-right">
                                                @if($darfEdit)
                                                    <div class="inline-flex items-center gap-0.5">
                                                        <x-fa::icon-button size="sm" icon="heroicon-o-pencil-square" label="Preis bearbeiten"
                                                            wire:click="preisBearbeiten({{ $p->id }})" data-preis-edit />
                                                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Preis löschen"
                                                            wire:click="preisLoeschen({{ $p->id }})" wire:confirm="Diesen Preis löschen?" />
                                                    </div>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">
                                            <x-fa::empty compact icon="heroicon-o-banknotes" title="Noch kein Preis erfasst">
                                                Ohne Preis rechnen Rezepte mit diesem Artikel nicht vollständig.@if($darfEdit) <span>Über «Preis erfassen» den ersten Preis anlegen.</span>@endif
                                            </x-fa::empty>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-fa::section>
            </div>{{-- /Reiter PREISE --}}

            {{-- ── Reiter ALLERGENE UND NÄHRWERTE ──────────────────────────────── --}}
            <div x-show="tab === 'deklaration'" x-cloak class="pt-4 flex flex-col gap-4">
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">

                    {{-- Allergene: 14 EU-Pflichtangaben, 4-Wert-Modell (GL-01). Erneuter Klick auf den aktiven
                         Wert setzt zurück auf «ohne Angabe». Ein Binding aufs Array, kein Roundtrip je Klick. --}}
                    <x-fa::section title="Allergene" icon="heroicon-o-shield-exclamation" :meta="$allergenGepflegt . ' von ' . $allergenGesamt . ' bewertet'">
                        <div class="flex flex-wrap items-center gap-2" data-allergen-kopf>
                            <x-fa::badge :tone="$allergenQuelle === 'manual' ? 'ok' : 'neutral'">{{ $quelleText($allergenQuelle) }}</x-fa::badge>
                            <span class="{{ $leise }}">Ohne Angabe heißt unbekannt, nicht frei von.</span>
                        </div>
                        <div class="flex flex-col divide-y divide-[var(--fa-line)]" x-data="{ werte: $wire.entangle('allergene') }" data-tri-state>
                            @foreach($allergenLabels as $key => $lbl)
                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-1.5" data-tri-row="{{ $key }}">
                                    <span class="{{ $zeilenLabel }}">{{ $lbl }}</span>
                                    <div class="inline-flex shrink-0 overflow-hidden rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] divide-x divide-[var(--fa-line-strong)]" role="group" aria-label="{{ $lbl }}">
                                        @foreach($allergenKnoepfe as $wert => [$text, $titel, $an])
                                            <button type="button" title="{{ $titel }}" @disabled(! $darfEdit)
                                                    @if($darfEdit) x-on:click="werte['{{ $key }}'] = (werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? 'unbekannt' : '{{ $wert }}'" @endif
                                                    x-bind:class="(werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? @js($an) : @js($segmentAus)"
                                                    x-bind:aria-pressed="(werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}'"
                                                    class="{{ $segment }}" data-tri-btn="{{ $wert }}">{{ $text }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-fa::section>

                    {{-- Zusatzstoffe: 18 deklarationspflichtige Stoffe (LMIV, GL-09), ja · nein · ohne Angabe. --}}
                    <x-fa::section title="Zusatzstoffe" icon="heroicon-o-beaker" :meta="count($deklarationLabels) . ' kennzeichnungspflichtige Stoffe'">
                        <div class="flex flex-wrap items-center gap-2" data-deklaration-kopf>
                            <x-fa::badge :tone="$deklarationQuelle === 'manual' ? 'ok' : 'neutral'">{{ $quelleText($deklarationQuelle) }}</x-fa::badge>
                            <span class="{{ $leise }}">Ohne Angabe heißt unbekannt.</span>
                        </div>
                        <div class="flex flex-col divide-y divide-[var(--fa-line)]" x-data="{ dekl: $wire.entangle('deklarationen') }" data-deklarationen>
                            @foreach($deklarationLabels as $stoff => $lbl)
                                <div class="flex items-center justify-between gap-3 py-1.5" data-dekl-row="{{ $stoff }}">
                                    <span class="{{ $zeilenLabel }}">{{ ucfirst($lbl) }}</span>
                                    <div class="inline-flex shrink-0 overflow-hidden rounded-[var(--fa-radius-control)] border border-[var(--fa-line-strong)] divide-x divide-[var(--fa-line-strong)]" role="group" aria-label="{{ ucfirst($lbl) }}">
                                        @foreach($stoffKnoepfe as $wert => [$text, $an])
                                            <button type="button" title="{{ $wert }}" @disabled(! $darfEdit)
                                                    @if($darfEdit) x-on:click="dekl['{{ $stoff }}'] = dekl['{{ $stoff }}'] === '{{ $wert }}' ? 'unbekannt' : '{{ $wert }}'" @endif
                                                    x-bind:class="dekl['{{ $stoff }}'] === '{{ $wert }}' ? @js($an) : @js($segmentAus)"
                                                    x-bind:aria-pressed="dekl['{{ $stoff }}'] === '{{ $wert }}'"
                                                    class="{{ $segment }}" data-dekl-btn="{{ $wert }}">{{ $text }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-fa::section>
                </div>

                <x-fa::section title="Nährwerte je 100 g" icon="heroicon-o-chart-bar"
                    description="Fließen als Mittelwert über alle Artikel in die Nährwerte des Grundprodukts.">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-naehrwerte>
                        @foreach($naehrwertFelder as $feld => $meta)
                            <div class="flex flex-col gap-1.5 min-w-0">
                                <label for="la-naehr-{{ $feld }}" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ $meta[0] }} <span class="font-normal text-[var(--fa-ink-3)]">in {{ $meta[1] }}</span></label>
                                <input id="la-naehr-{{ $feld }}" type="text" inputmode="decimal" wire:model="naehrwerte.{{ $feld }}" placeholder="keine Angabe"
                                       @disabled(! $darfEdit) class="fa-control h-9 text-[length:var(--fa-text-md)] text-right tabular-nums" data-naehr-{{ $feld }} />
                            </div>
                        @endforeach
                    </div>
                </x-fa::section>
            </div>{{-- /Reiter ALLERGENE UND NÄHRWERTE --}}

            {{-- ── Reiter STAMMDATEN (Artikel · Verpackung · Eigenschaften) ─────── --}}
            <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 flex flex-col gap-4">
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
                    <x-fa::section title="Artikel" icon="heroicon-o-tag">
                        <x-fa::field label="Bezeichnung" for="la-designation" error="stammdaten.designation" required>
                            <x-fa::input id="la-designation" wire:model="stammdaten.designation" :disabled="! $darfEdit" />
                        </x-fa::field>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach([['article_number', 'Artikelnummer'], ['marketing_name', 'Verkaufsname des Lieferanten'], ['brand', 'Marke'], ['manufacturer', 'Hersteller'], ['origin', 'Herkunft']] as [$feld, $lbl])
                                <x-fa::field :label="$lbl" for="la-{{ $feld }}">
                                    <x-fa::input id="la-{{ $feld }}" wire:model="stammdaten.{{ $feld }}" :disabled="! $darfEdit" />
                                </x-fa::field>
                            @endforeach
                        </div>
                        <x-fa::field label="Zusatztext" for="la-additional-text">
                            <x-fa::textarea id="la-additional-text" rows="2" wire:model="stammdaten.additional_text" :disabled="! $darfEdit" />
                        </x-fa::field>
                    </x-fa::section>

                    <x-fa::section title="Verpackung und Menge" icon="heroicon-o-archive-box">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <x-fa::field label="Inhalt" for="la-qty" hint="In der Kalkulationseinheit, z. B. 0,8 bei 800 g">
                                <x-fa::input id="la-qty" wire:model="verpackung.qty" inputmode="decimal" numeric :disabled="! $darfEdit" />
                            </x-fa::field>
                            <x-fa::field label="Kalkulationseinheit" for="la-unit-code" hint="Grundlage für den Preis je kg, l oder Stück">
                                <x-fa::select id="la-unit-code" wire:model="verpackung.unit_code" placeholder="keine Angabe"
                                    :options="['kg' => 'kg', 'l' => 'l', 'Stk' => 'Stück']" :disabled="! $darfEdit" />
                            </x-fa::field>
                            <x-fa::field label="Verpackungseinheit" for="la-packaging-unit">
                                <x-fa::input id="la-packaging-unit" wire:model="verpackung.packaging_unit" :disabled="! $darfEdit" />
                            </x-fa::field>
                            <x-fa::field label="Bestelleinheit" for="la-ordering-unit">
                                <x-fa::input id="la-ordering-unit" wire:model="verpackung.ordering_unit" :disabled="! $darfEdit" />
                            </x-fa::field>
                            <x-fa::field label="Verpackungseinheiten je Bestelleinheit" for="la-qty-ordering">
                                <x-fa::input id="la-qty-ordering" wire:model="verpackung.qty_ordering_per_packaging" inputmode="decimal" numeric :disabled="! $darfEdit" />
                            </x-fa::field>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-3 border-t border-[var(--fa-line)]">
                            <x-fa::field label="EAN Verpackungseinheit" for="la-ean-packaging" hint="8 bis 14 Ziffern" error="verpackung.ean_packaging">
                                <x-fa::input id="la-ean-packaging" wire:model="verpackung.ean_packaging" inputmode="numeric" class="tabular-nums" :disabled="! $darfEdit" />
                            </x-fa::field>
                            <x-fa::field label="EAN Bestelleinheit" for="la-ean-ordering" hint="8 bis 14 Ziffern" error="verpackung.ean_ordering">
                                <x-fa::input id="la-ean-ordering" wire:model="verpackung.ean_ordering" inputmode="numeric" class="tabular-nums" :disabled="! $darfEdit" />
                            </x-fa::field>
                        </div>
                    </x-fa::section>
                </div>

                <x-fa::section title="Eigenschaften" icon="heroicon-o-check-badge">
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                        @foreach([['is_organic', 'Bio'], ['is_vegan', 'Vegan'], ['is_vegetarian', 'Vegetarisch'], ['is_alcohol', 'Enthält Alkohol'], ['is_halal', 'Halal'], ['is_gmo_free', 'Gentechnikfrei']] as [$feld, $lbl])
                            <x-fa::field :label="$lbl" for="la-{{ $feld }}">
                                <x-fa::select id="la-{{ $feld }}" wire:model="eigenschaften.{{ $feld }}" placeholder="unbekannt"
                                    :options="['1' => 'ja', '0' => 'nein']" :disabled="! $darfEdit" />
                            </x-fa::field>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                        <x-fa::field label="Mehrwertsteuer in %" for="la-vat">
                            <x-fa::input id="la-vat" wire:model="eigenschaften.vat" inputmode="decimal" placeholder="7 oder 19" numeric :disabled="! $darfEdit" />
                        </x-fa::field>
                        <x-fa::field label="Ursprungsland" for="la-origin-country">
                            <x-fa::input id="la-origin-country" wire:model="eigenschaften.origin_country" :disabled="! $darfEdit" />
                        </x-fa::field>
                        <x-fa::field label="Bio-Kontrollnummer" for="la-organic-control">
                            <x-fa::input id="la-organic-control" wire:model="eigenschaften.organic_control_number" :disabled="! $darfEdit" />
                        </x-fa::field>
                        <div class="flex flex-col gap-1.5 min-w-0" data-vorbestellung>
                            <label for="la-is-preorder" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Vorbestellung</label>
                            <div class="flex gap-2">
                                <x-fa::select id="la-is-preorder" wire:model="eigenschaften.is_preorder" placeholder="keine Angabe"
                                    :options="['1' => 'nötig', '0' => 'nicht nötig']" :disabled="! $darfEdit" class="flex-1 min-w-0" />
                                <x-fa::input type="number" wire:model="eigenschaften.preorder_days" placeholder="Tage" aria-label="Vorlauf in Tagen"
                                    numeric :disabled="! $darfEdit" class="w-24 shrink-0" />
                            </div>
                        </div>
                    </div>
                    <x-fa::field label="Zutatenliste des Lieferanten" for="la-ingredients">
                        <x-fa::textarea id="la-ingredients" rows="3" wire:model="eigenschaften.ingredients_supplier" :disabled="! $darfEdit" />
                    </x-fa::field>
                </x-fa::section>
            </div>{{-- /Reiter STAMMDATEN --}}

            </x-foodalchemist::editor-tabs>
        @endif
    </x-foodalchemist::modal>
</div>
