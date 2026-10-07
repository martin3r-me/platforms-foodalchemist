{{-- Leitplanken-Fläche (Richtungs-Regler) JE SCOPE — jeder Tab hat einen eigenen Regler-Satz.
     Erwartet $scope (rezept|gericht|concept). VK-Achsen (Anlass/Serviceform/Kompositions-Stil/Ziel-VK)
     nur wenn $scope != rezept. Alle Bindings gehen auf regler.{scope}.* (unabhängig pro Tab).

     Cockpit-Optik (Paket K, Rollout 2026-09-19): EINE Kartenstruktur für ALLE drei Scopes. Küche,
     Anspruch, Anreicherung und Ausschlüsse sind scope-identisch; scope-abhängig sind nur
       · die Ziel-Karte  — rezept: Einheit + Ziel-Menge (Halbfabrikat-Charge)
                           gericht: Pax + Ziel-Portion + VK-Achsen + Ziel-VK
                           concept: Pax + VK-Achsen, OHNE Ziel-Portion und OHNE Ziel-VK
                           (Entscheid 2026-08-18: für ein Concept ist der Menü-Preis-Korridor p. P.
                            die EINZIGE Preisquelle, Ziel-Portion ist scope-fremd)
       · die Struktur-Karte — nur concept (Concept-Typ + Menü-/Buffet-Leitplanken + Diät-Quoten +
                           Portfolio-Balance).

     fa-pass (2026-10-05, Werkbank-tauglich + Laptop-Breite):
       · Nur --fa-*-Tokens und x-fa-Bausteine, damit hell UND dunkel (data-fa-theme="dark") stimmen.
       · Spalten richten sich nach der TATSÄCHLICH verfügbaren Breite (Container-Abfragen @container),
         nicht nach der Fensterbreite. Vorher: ab xl zwei Karten nebeneinander UND darin noch einmal
         zwei Spalten ab sm — auf dem Laptop (Seitenleiste + Modal) wurden daraus vier gequetschte
         Spalten, auf dem großen Monitor passte es. Jetzt: zwei Karten nebeneinander erst ab 56rem
         Flächenbreite, zwei Spalten in der Karte erst ab 36rem Kartenbreite. Anordnung unverändert.
       · Einzelauswahl über reglerPill bleibt Knopf-Chip (wire:click unverändert, aria-pressed);
         Auswahl, die vorher ein <select wire:model> war, ist bei 2–6 Optionen x-fa::choice mit
         DERSELBEN wire:model-Bindung, ab 7 Optionen x-fa::select.
       · Leer-Optionen („(egal)", „—") heißen in der Anzeige „Keine Vorgabe"; die Werte und die
         Konstanten in Index.php bleiben unverändert (Agent + reglerParams lesen sie).
     Felder, Bindings, alle data-*-Anker und alle Agent-Badges (je Feld genau einmal) übernommen. --}}
@php
    $scope = $scope ?? 'rezept';
    $vk = $scope !== 'rezept';
    $r = $regler[$scope] ?? [];
    $I = \Platform\FoodAlchemist\Livewire\Planung\Index::class;
    // Concept-Typ (#35): Buffet baut Stationen statt Gänge → Label/Header schalten mit.
    $istBuffet = ($r['menue_typ'] ?? '') === 'buffet';

    $richtungenByField = collect($I::RICHTUNGEN)->keyBy('field');

    // Anzeige-Vokabular: Leer-Option einheitlich „Keine Vorgabe", Werte bleiben die der Konstanten.
    $keineVorgabe = fn (array $optionen) => array_replace($optionen, array_key_exists('', $optionen) ? ['' => 'Keine Vorgabe'] : []);
    $convenienceOptionen = array_replace($keineVorgabe($richtungenByField['convenience']['optionen']), ['from_scratch' => 'Alles selbst']);
    $convenienceHinweis = [
        '' => 'Die KI wählt passend zum Briefing.',
        'from_scratch' => 'Rohware und eigene Basisrezepte, keine Fertigprodukte.',
        'teil_convenience' => 'Halbfabrikate sind erlaubt.',
        'voll_convenience' => 'Fertigprodukte werden bevorzugt.',
    ];
    $bioOptionen = array_replace($richtungenByField['bio_praeferenz']['optionen'], ['egal' => 'Keine Präferenz']);
    $bioHinweis = [
        'konventionell' => 'Voreinstellung, Bio wird nicht erzwungen.',
        'bio' => 'Bio-Ware wird bevorzugt.',
        'egal' => 'Bio und konventionell gleichrangig.',
    ];
    $levelOptionen = $keineVorgabe($richtungenByField['level']['optionen']);
    $aromaKuechen = array_replace($I::AROMA_KUECHEN, ['' => 'Keine Vorgabe, die KI wählt']);
    $sektorOptionen = $keineVorgabe($I::SEKTOR_OPTIONEN);
    $saisonOptionen = array_replace($I::SAISON_OPTIONEN, ['' => 'Ganzjährig']);
    $einheitOptionen = $keineVorgabe($I::MENGE_EINHEITEN);
    $anlassOptionen = $keineVorgabe($I::OCCASION_OPTIONEN);
    $serviceformOptionen = $keineVorgabe($I::SERVICEFORM_OPTIONEN);
    $diaetLabels = [
        'vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei',
        'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb',
    ];
    // Kompositions-Stil hat (noch) keine Konstante, bleibt lokal.
    $kompositionsStilLabels = [
        'klassisch' => 'Klassisch', 'kreativ' => 'Kreativ', 'gewagt' => 'Gewagt (nur belegte Paarungen)',
    ];
    $balanceOptionen = ['' => 'Keine Vorgabe'] + $I::MENUE_BALANCE;

    // Einheitliche Formular-Typografie (wie x-fa::field / x-fa::choice).
    $labelKlasse = 'flex flex-wrap items-center gap-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $hinweisKlasse = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $agentTitel = 'Vom Sprachassistenten vorgeschlagen. Verschwindet, sobald du selbst etwas änderst.';
    // Knopf-Chips (reglerPill): gleiche Gestalt wie x-fa::choice, Zustand über aria-pressed.
    $chip = 'inline-flex items-center gap-1 h-[30px] px-3 rounded-[var(--fa-radius-pill)] border text-[length:var(--fa-text-md)] transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[color:var(--fa-accent)]';
    $chipRuhe = 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink-2)] hover:border-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]';
    $chipAktiv = 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium';

    // Kartenkopf: „Voreinstellung" (nichts geändert) oder die aktiven Werte als Etiketten —
    // damit man auf einen Blick sieht, was in einer Karte eingestellt ist.
    $kopf = fn (array $werte) => collect($werte)->filter(fn ($w) => $w !== null && trim((string) $w) !== '')->values();

    $kuecheKopf = $kopf([
        ($r['convenience'] ?? '') !== '' ? ($convenienceOptionen[$r['convenience']] ?? null) : null,
        (($r['bio_praeferenz'] ?? 'konventionell') !== 'konventionell') ? ($bioOptionen[$r['bio_praeferenz']] ?? null) : null,
        ($r['aroma_kueche'] ?? '') !== '' ? ($I::AROMA_KUECHEN[$r['aroma_kueche']] ?? null) : null,
        trim($r['aroma'] ?? '') !== '' ? '„' . \Illuminate\Support\Str::limit(trim($r['aroma']), 22) . '“' : null,
    ]);
    $anspruchKopf = $kopf([
        ($r['level'] ?? '') !== '' ? ($levelOptionen[$r['level']] ?? null) : null,
        ! empty($r['frische'] ?? []) ? collect((array) $r['frische'])->map(fn ($w) => $I::FRISCHE_OPTIONEN[$w] ?? $w)->implode(' / ') : null,
        ($r['sektor'] ?? '') !== '' ? ($I::SEKTOR_OPTIONEN[$r['sektor']] ?? null) : null,
    ]);
    $anreicherungKopf = $kopf([
        ! ($r['voll_anreichern'] ?? true) ? 'Nur Kernangaben' : null,
        ($r['ki_bilder'] ?? false) ? 'KI-Fotos an' : null,
        ($r['favoriten'] ?? false) ? ('Favoriten' . (($r['favoriten_conv_only'] ?? false) ? ', nur Convenience' : '')) : null,
    ]);
    $ausschlussKopf = $kopf([
        ! empty($r['diaet_hart'] ?? []) ? collect((array) $r['diaet_hart'])->map(fn ($w) => $diaetLabels[$w] ?? $w)->implode(' / ') : null,
        ! empty($r['allergen_nogo'] ?? []) ? ('ohne ' . collect((array) $r['allergen_nogo'])->map(fn ($w) => $I::ALLERGEN_LABELS[$w] ?? $w)->implode(', ')) : null,
    ]);
    // Ziel-Karte: rezept zeigt die Charge (Menge + Einheit), gericht/concept die Gäste-/VK-Achsen.
    $zielKopf = $scope === 'rezept'
        ? $kopf([
            trim((string) ($r['ziel_menge'] ?? '')) !== '' ? trim(trim((string) $r['ziel_menge']) . ' ' . (($r['ziel_einheit'] ?? '') !== '' ? ($I::MENGE_EINHEITEN[$r['ziel_einheit']] ?? '') : '')) : null,
            ($r['saison'] ?? '') !== '' ? ($I::SAISON_OPTIONEN[$r['saison']] ?? null) : null,
            trim((string) ($r['ziel_we_pct'] ?? '')) !== '' ? (trim((string) $r['ziel_we_pct']) . ' % Wareneinsatz') : null,
        ])
        : $kopf([
            trim((string) ($r['pax'] ?? '')) !== '' ? (trim((string) $r['pax']) . ' Personen') : null,
            ($scope === 'gericht' && trim((string) ($r['ziel_portion_g'] ?? '')) !== '') ? (trim((string) $r['ziel_portion_g']) . ' g je Portion') : null,
            ($r['occasion'] ?? '') !== '' ? ($I::OCCASION_OPTIONEN[$r['occasion']] ?? null) : null,
            ($r['serviceform'] ?? '') !== '' ? ($I::SERVICEFORM_OPTIONEN[$r['serviceform']] ?? null) : null,
            ($r['kompositions_stil'] ?? '') !== '' ? ($kompositionsStilLabels[$r['kompositions_stil']] ?? null) : null,
            ($scope === 'gericht' && trim((string) ($r['ziel_vk'] ?? '')) !== '') ? ('VK ' . trim((string) $r['ziel_vk']) . ' €') : null,
            ($r['saison'] ?? '') !== '' ? ($I::SAISON_OPTIONEN[$r['saison']] ?? null) : null,
            trim((string) ($r['ziel_we_pct'] ?? '')) !== '' ? (trim((string) $r['ziel_we_pct']) . ' % Wareneinsatz') : null,
        ]);

    // Struktur-Karte (nur concept): Umfang + Preislage + Vielfalt des GANZEN Menüs/Buffets.
    $strukturKopf = $scope === 'concept'
        ? $kopf([
            $istBuffet ? 'Buffet' : 'Menü',
            trim((string) ($r['menue_gaenge'] ?? '')) !== '' ? (trim((string) $r['menue_gaenge']) . ($istBuffet ? ' Stationen' : ' Gänge')) : null,
            trim((string) ($r['menue_preis_ziel'] ?? '')) !== '' ? ('Ziel ' . trim((string) $r['menue_preis_ziel']) . ' € p. P.') : null,
            ($r['menue_balance'] ?? '') !== '' ? ($I::MENUE_BALANCE[$r['menue_balance']] ?? null) : null,
        ])
        : $kopf([]);
@endphp

    {{-- Lesebreite begrenzt der Gesamtcontainer im Elternteil (erstellen-tab: max-w-7xl mx-auto).
         Jede Karte steckt in einem eigenen Wrapper, damit modal-section (mt-4 first:mt-0) ohne
         Überschreib-Regel bündig im Raster steht. --}}
    <div class="@container flex flex-col gap-4 min-w-0">
        <div class="grid gap-4 @4xl:grid-cols-2">
            <div class="min-w-0">
                <x-foodalchemist::modal-section icon="heroicon-o-fire" title="Küche">
                    <x-slot:actions>
                        @forelse($kuecheKopf as $w)<x-fa::badge tone="accent">{{ $w }}</x-fa::badge>@empty<x-fa::badge title="Nichts geändert, es gilt die Voreinstellung.">Voreinstellung</x-fa::badge>@endforelse
                    </x-slot:actions>
                    <div class="@container">
                        <div class="grid gap-x-6 gap-y-4 @xl:grid-cols-2" data-planung-regler="{{ $scope }}">
                            <div class="flex flex-col gap-1.5 min-w-0" data-richtung="{{ $richtungenByField['convenience']['field'] }}">
                                <p class="{{ $labelKlasse }}">Eigenleistung
                                    @if($reglerVonAgent[$scope]['convenience'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="convenience" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </p>
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Eigenleistung">
                                    @foreach($convenienceOptionen as $wert => $lbl)
                                        @php $an = ($r['convenience'] ?? '') === (string) $wert; @endphp
                                        <button type="button" wire:click="reglerPill('{{ $scope }}', 'convenience', '{{ $wert }}')" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                                class="{{ $chip }} {{ $an ? $chipAktiv : $chipRuhe }}">{{ $lbl }}</button>
                                    @endforeach
                                </div>
                                <p class="{{ $hinweisKlasse }}">{{ $convenienceHinweis[$r['convenience'] ?? ''] ?? '' }}</p>
                            </div>

                            <div class="flex flex-col gap-1.5 min-w-0" data-richtung="{{ $richtungenByField['bio_praeferenz']['field'] }}">
                                <p class="{{ $labelKlasse }}">Bio
                                    @if($reglerVonAgent[$scope]['bio_praeferenz'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="bio_praeferenz" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </p>
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Bio">
                                    @foreach($bioOptionen as $wert => $lbl)
                                        @php $an = ($r['bio_praeferenz'] ?? '') === (string) $wert; @endphp
                                        <button type="button" wire:click="reglerPill('{{ $scope }}', 'bio_praeferenz', '{{ $wert }}')" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                                class="{{ $chip }} {{ $an ? $chipAktiv : $chipRuhe }}">{{ $lbl }}</button>
                                    @endforeach
                                </div>
                                <p class="{{ $hinweisKlasse }}">{{ $bioHinweis[$r['bio_praeferenz'] ?? ''] ?? '' }}</p>
                            </div>

                            <div class="@xl:col-span-2 grid gap-x-6 gap-y-4 @xl:grid-cols-2" data-richtung="aroma">
                                <x-fa::field label="Küchenstil" for="planung-aroma-kueche-{{ $scope }}">
                                    <x-fa::select id="planung-aroma-kueche-{{ $scope }}" wire:model="regler.{{ $scope }}.aroma_kueche" :options="$aromaKuechen" data-planung-aroma-kueche />
                                </x-fa::field>
                                <x-fa::field label="Geschmack fein einstellen" for="planung-aroma-{{ $scope }}" optional>
                                    <x-fa::input id="planung-aroma-{{ $scope }}" wire:model="regler.{{ $scope }}.aroma" placeholder="zum Beispiel rauchig, karamellig, umami-betont" />
                                </x-fa::field>
                                <p class="@xl:col-span-2 {{ $hinweisKlasse }}">Der Küchenstil bestimmt die Würzung, Garweise und Grundidee. Der Freitext justiert zusätzlich. Beides ist optional.</p>
                            </div>
                        </div>
                    </div>
                </x-foodalchemist::modal-section>
            </div>

            <div class="min-w-0">
                <x-foodalchemist::modal-section icon="heroicon-o-star" title="Anspruch">
                    <x-slot:actions>
                        @forelse($anspruchKopf as $w)<x-fa::badge tone="accent">{{ $w }}</x-fa::badge>@empty<x-fa::badge title="Nichts geändert, es gilt die Voreinstellung.">Voreinstellung</x-fa::badge>@endforelse
                    </x-slot:actions>
                    <div class="@container">
                        <div class="grid gap-x-6 gap-y-4 @xl:grid-cols-2">
                            <div class="flex flex-col gap-1.5 min-w-0" data-richtung="{{ $richtungenByField['level']['field'] }}">
                                <p class="{{ $labelKlasse }}">Niveau
                                    @if($reglerVonAgent[$scope]['level'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="level" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </p>
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Niveau">
                                    @foreach($levelOptionen as $wert => $lbl)
                                        @php $an = ($r['level'] ?? '') === (string) $wert; @endphp
                                        <button type="button" wire:click="reglerPill('{{ $scope }}', 'level', '{{ $wert }}')" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                                class="{{ $chip }} {{ $an ? $chipAktiv : $chipRuhe }}">{{ $lbl }}</button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex flex-col gap-1.5 min-w-0" data-richtung="frische">
                                <p class="{{ $labelKlasse }}">Erlaubte Zustände <span class="font-normal text-[var(--fa-ink-3)]">(mehrere möglich)</span></p>
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Erlaubte Zustände">
                                    @foreach($I::FRISCHE_OPTIONEN as $wert => $lbl)
                                        @php $an = in_array($wert, (array) ($r['frische'] ?? []), true); @endphp
                                        <button type="button" wire:click="reglerPill('{{ $scope }}', 'frische', '{{ $wert }}')" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                                class="{{ $chip }} {{ $an ? $chipAktiv : $chipRuhe }}" data-planung-frische="{{ $wert }}">@if($an)@svg('heroicon-o-check', 'w-3.5 h-3.5 shrink-0 -ml-0.5')@endif{{ $lbl }}</button>
                                    @endforeach
                                </div>
                                <p class="{{ $hinweisKlasse }}">{{ empty($r['frische'] ?? []) ? 'Keine Vorgabe, alle Zustände erlaubt.' : 'Nur diese Zustände, frische Ware bevorzugt.' }}</p>
                            </div>

                            <div class="@xl:col-span-2 flex flex-col gap-1.5 min-w-0" data-richtung="sektor">
                                <p class="{{ $labelKlasse }}">Verpflegungsbereich
                                    @if($reglerVonAgent[$scope]['sektor'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="sektor" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </p>
                                <x-fa::choice name="regler.{{ $scope }}.sektor" :options="$sektorOptionen" :live="false" id-prefix="leitplanken" aria-label="Verpflegungsbereich" />
                            </div>
                        </div>
                    </div>
                </x-foodalchemist::modal-section>
            </div>
        </div>

        <div class="grid gap-4 @4xl:grid-cols-2">
            <div class="min-w-0">
                <x-foodalchemist::modal-section icon="heroicon-o-sparkles" title="Anreicherung">
                    <x-slot:actions>
                        @forelse($anreicherungKopf as $w)<x-fa::badge tone="accent">{{ $w }}</x-fa::badge>@empty<x-fa::badge title="Nichts geändert, es gilt die Voreinstellung.">Voreinstellung</x-fa::badge>@endforelse
                    </x-slot:actions>
                    <div class="flex flex-col gap-4">
                        <div data-richtung="voll-anreichern">
                            <label class="flex items-start gap-2.5 cursor-pointer">
                                <input type="checkbox" wire:model="regler.{{ $scope }}.voll_anreichern" class="mt-0.5 h-4 w-4 shrink-0 accent-[var(--fa-accent)]" data-planung-voll-anreichern />
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Vollständig anreichern</span>
                                    <span class="{{ $hinweisKlasse }}">An (Voreinstellung): Bei der Freigabe entstehen auch Arbeitsschritte, Sensorik, Zeiten, Equipment, Posten und Pairings. Aus: nur die Kernangaben.</span>
                                </span>
                            </label>
                        </div>

                        <div data-richtung="ki-bilder">
                            <label class="flex items-start gap-2.5 cursor-pointer">
                                <input type="checkbox" wire:model="regler.{{ $scope }}.ki_bilder" class="mt-0.5 h-4 w-4 shrink-0 accent-[var(--fa-accent)]" data-planung-ki-bilder />
                                <span class="flex flex-col gap-0.5">
                                    <span class="flex items-center gap-1.5 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">@svg('heroicon-o-camera', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Fotos per KI erstellen</span>
                                    <span class="{{ $hinweisKlasse }}">Schrittfotos und Produktfoto bei der Anreicherung. Jedes Bild ist ein eigener KI-Aufruf und kostet. Aus: keine Bilder.</span>
                                </span>
                            </label>
                        </div>

                        <div class="border-t border-[var(--fa-line)] pt-4" data-richtung="favoriten">
                            <label class="flex items-start gap-2.5 cursor-pointer">
                                <input type="checkbox" wire:model.live="regler.{{ $scope }}.favoriten" class="mt-0.5 h-4 w-4 shrink-0 accent-[var(--fa-accent)]" data-planung-favoriten />
                                <span class="flex flex-col gap-0.5">
                                    <span class="flex items-center gap-1.5 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">@svg('heroicon-o-star', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Auf meinen Favoriten aufbauen</span>
                                    <span class="{{ $hinweisKlasse }}">Bevorzugt deine gemerkten Grundprodukte. Aus: freie Auswahl.</span>
                                </span>
                            </label>
                            <label x-show="$wire.get('regler.{{ $scope }}.favoriten')" class="flex items-center gap-2 mt-2 ml-6.5 cursor-pointer text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                                <input type="checkbox" wire:model="regler.{{ $scope }}.favoriten_conv_only" class="h-4 w-4 shrink-0 accent-[var(--fa-accent)]" /> Nur Convenience-Favoriten
                            </label>
                        </div>
                    </div>
                </x-foodalchemist::modal-section>
            </div>

            <div class="min-w-0">
                <x-foodalchemist::modal-section icon="heroicon-o-shield-check" title="Ernährung und Allergene">
                    <x-slot:actions>
                        @forelse($ausschlussKopf as $w)<x-fa::badge tone="accent">{{ $w }}</x-fa::badge>@empty<x-fa::badge title="Nichts ausgeschlossen.">Keine Vorgabe</x-fa::badge>@endforelse
                    </x-slot:actions>
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1.5" data-richtung="diaet">
                            <p class="{{ $labelKlasse }}">Ernährungsform <span class="font-normal text-[var(--fa-ink-3)]">(mehrere möglich)</span></p>
                            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Ernährungsform">
                                @foreach($diaetLabels as $wert => $lbl)
                                    @php $an = in_array($wert, (array) ($r['diaet_hart'] ?? []), true); @endphp
                                    <button type="button" wire:click="reglerPill('{{ $scope }}', 'diaet_hart', '{{ $wert }}')" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                            class="{{ $chip }} {{ $an ? $chipAktiv : $chipRuhe }}">@if($an)@svg('heroicon-o-check', 'w-3.5 h-3.5 shrink-0 -ml-0.5')@endif{{ $lbl }}</button>
                                @endforeach
                            </div>
                            <p class="{{ $hinweisKlasse }}">Wird nach der Erstellung geprüft. Verstöße werden behoben und gemeldet, vorab wird nichts gesperrt.</p>
                        </div>

                        <div class="flex flex-col gap-1.5" data-richtung="allergen-nogo">
                            <p class="{{ $labelKlasse }}">Allergene ausschließen <span class="font-normal text-[var(--fa-ink-3)]">(14 EU-Allergene)</span></p>
                            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Allergene ausschließen">
                                @foreach($I::ALLERGEN_LABELS as $wert => $lbl)
                                    @php $an = in_array($wert, (array) ($r['allergen_nogo'] ?? []), true); @endphp
                                    <button type="button" wire:click="reglerPill('{{ $scope }}', 'allergen_nogo', '{{ $wert }}')" aria-pressed="{{ $an ? 'true' : 'false' }}"
                                            class="{{ $chip }} {{ $an ? $chipAktiv : $chipRuhe }}" data-planung-allergen-nogo="{{ $wert }}">@if($an)@svg('heroicon-o-no-symbol', 'w-3.5 h-3.5 shrink-0 -ml-0.5')@endif{{ $lbl }}</button>
                                @endforeach
                            </div>
                            <p class="{{ $hinweisKlasse }}">{{ empty($r['allergen_nogo'] ?? []) ? 'Kein Allergen ausgeschlossen.' : 'Wird nach der Erstellung geprüft. Treffer werden behoben und gemeldet.' }}</p>
                        </div>
                    </div>
                </x-foodalchemist::modal-section>
            </div>
        </div>

        <div class="min-w-0">
            <x-foodalchemist::modal-section icon="heroicon-o-flag" title="Ziel">
                <x-slot:actions>
                    @forelse($zielKopf as $w)<x-fa::badge tone="accent">{{ $w }}</x-fa::badge>@empty<x-fa::badge title="Nichts geändert, es gilt die Voreinstellung.">Voreinstellung</x-fa::badge>@endforelse
                </x-slot:actions>
                <div class="flex flex-wrap items-start gap-x-6 gap-y-4" data-richtung="menge-ziel">
                    @if($scope === 'rezept')
                        {{-- Basisrezept = Halbfabrikat (Charge in einer Einheit), kein Teller für N Gäste:
                             Ziel-Menge + Einheit statt Pax/Portion (2 L Sauce, 5 kg Teig, 30 Stk …). --}}
                        <div class="flex flex-col gap-1.5 w-44">
                            <label for="planung-ziel-einheit-{{ $scope }}" class="{{ $labelKlasse }}">Einheit
                                @if($reglerVonAgent[$scope]['ziel_einheit'] ?? false)
                                    <x-fa::badge tone="info" data-regler-von-agent="ziel_einheit" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                @endif
                            </label>
                            <x-fa::select id="planung-ziel-einheit-{{ $scope }}" wire:model="regler.{{ $scope }}.ziel_einheit" :options="$einheitOptionen" data-planung-ziel-einheit />
                        </div>
                        <div class="flex flex-col gap-1.5 w-36">
                            <label for="planung-ziel-menge-{{ $scope }}" class="{{ $labelKlasse }}">Ziel-Menge
                                @if($reglerVonAgent[$scope]['ziel_menge'] ?? false)
                                    <x-fa::badge tone="info" data-regler-von-agent="ziel_menge" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                @endif
                            </label>
                            <x-fa::input id="planung-ziel-menge-{{ $scope }}" type="number" min="0" step="any" numeric wire:model="regler.{{ $scope }}.ziel_menge" placeholder="zum Beispiel 2" data-planung-ziel-menge />
                        </div>
                    @else
                        <div class="flex flex-col gap-1.5 w-36">
                            <label for="planung-pax-{{ $scope }}" class="{{ $labelKlasse }}">Personen
                                @if($reglerVonAgent[$scope]['pax'] ?? false)
                                    <x-fa::badge tone="info" data-regler-von-agent="pax" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                @endif
                            </label>
                            <x-fa::input id="planung-pax-{{ $scope }}" type="number" min="1" max="100000" step="1" numeric wire:model="regler.{{ $scope }}.pax" placeholder="zum Beispiel 50" data-planung-pax />
                        </div>
                        {{-- Ziel-Portion (g) ist per-Portion — für ein Concept (ganzes Menü) scope-fremd, darum nur
                             am Gericht (Leitplanken-Hygiene 2026-08-18). Den Concept-Umfang steuert die Struktur-Karte.
                             ziel_portion_g steht in AGENT_SCHREIBBARE_REGLER → Badge Pflicht (Entscheid 03). --}}
                        @if($scope !== 'concept')
                            <div class="flex flex-col gap-1.5 w-40">
                                <label for="planung-portion-{{ $scope }}" class="{{ $labelKlasse }}">Portionsgewicht (g)
                                    @if($reglerVonAgent[$scope]['ziel_portion_g'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="ziel_portion_g" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </label>
                                <x-fa::input id="planung-portion-{{ $scope }}" type="number" min="1" max="5000" step="1" numeric wire:model="regler.{{ $scope }}.ziel_portion_g" placeholder="zum Beispiel 180" data-planung-portion-g />
                            </div>
                        @endif
                    @endif
                    <div class="min-w-0">
                        <x-fa::choice name="regler.{{ $scope }}.saison" :options="$saisonOptionen" :live="false" label="Saison" id-prefix="leitplanken" data-planung-saison />
                    </div>
                    <div class="flex flex-col gap-1.5 w-44">
                        <label for="planung-we-{{ $scope }}" class="{{ $labelKlasse }}">Wareneinsatz-Ziel (%)</label>
                        <x-fa::input id="planung-we-{{ $scope }}" type="number" min="1" max="100" step="1" numeric wire:model="regler.{{ $scope }}.ziel_we_pct" placeholder="zum Beispiel 28" data-planung-we-pct />
                    </div>
                </div>

                @if($vk)
                    {{-- VK-eigene Achsen — nur Gericht/Concept: beschreiben dasselbe wie Pax/Portion (wofür
                         wird gebaut), darum in derselben Ziel-Karte. --}}
                    <div class="border-t border-[var(--fa-line)] pt-4 mt-4 flex flex-col gap-4" data-richtung="vk-achsen">
                        <div class="flex flex-wrap items-start gap-x-6 gap-y-4">
                            <div class="flex flex-col gap-1.5 w-56">
                                <label for="planung-anlass-{{ $scope }}" class="{{ $labelKlasse }}">Anlass
                                    @if($reglerVonAgent[$scope]['occasion'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="occasion" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </label>
                                <x-fa::select id="planung-anlass-{{ $scope }}" wire:model="regler.{{ $scope }}.occasion" :options="$anlassOptionen" />
                            </div>
                            <div class="flex flex-col gap-1.5 min-w-0">
                                <p class="{{ $labelKlasse }}">Servierform
                                    @if($reglerVonAgent[$scope]['serviceform'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="serviceform" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </p>
                                <x-fa::choice name="regler.{{ $scope }}.serviceform" :options="$serviceformOptionen" :live="false" id-prefix="leitplanken" aria-label="Servierform" />
                            </div>
                        </div>
                        <x-fa::choice name="regler.{{ $scope }}.kompositions_stil" :options="['' => 'Keine Vorgabe'] + $kompositionsStilLabels" :live="false" label="Kompositionsstil" id-prefix="leitplanken" />

                        {{-- Ziel-VK ist der Portions-Preis (Gericht). Für ein Concept ist der Menü-Preis-Korridor
                             p. P. in der Struktur-Karte die EINZIGE Preisquelle (Entscheid 2026-08-18). --}}
                        @if($scope === 'gericht')
                            <div class="flex flex-col gap-1.5 max-w-md">
                                <label for="planung-ziel-vk-{{ $scope }}" class="{{ $labelKlasse }}">Ziel-Verkaufspreis (€) <span class="font-normal text-[var(--fa-ink-3)]">(optional)</span>
                                    @if($reglerVonAgent[$scope]['ziel_vk'] ?? false)
                                        <x-fa::badge tone="info" data-regler-von-agent="ziel_vk" :title="$agentTitel">vom Assistenten</x-fa::badge>
                                    @endif
                                </label>
                                <x-fa::input id="planung-ziel-vk-{{ $scope }}" wire:model="regler.{{ $scope }}.ziel_vk" placeholder="zum Beispiel 8,50" numeric class="w-36" data-planung-ziel-vk />
                                <p class="{{ $hinweisKlasse }}">Netto je Portion. Geht als Vorgabe in den Vorschlag, der Preis wird nicht auf das Ziel gedrückt.</p>
                            </div>
                        @endif
                    </div>
                @endif
            </x-foodalchemist::modal-section>
        </div>

        @if($scope === 'concept')
            {{-- Struktur — nur Concept: Umfang, Preislage und Vielfalt des GANZEN Menüs/Buffets. Andere Frage
                 als die Ziel-Karte darüber (wofür + wie teuer je Portion), darum eine eigene Karte. --}}
            <div class="min-w-0">
                <x-foodalchemist::modal-section icon="heroicon-o-squares-2x2" :title="$istBuffet ? 'Aufbau des Buffets' : 'Aufbau des Menüs'">
                    <x-slot:actions>
                        @foreach($strukturKopf as $w)<x-fa::badge tone="accent">{{ $w }}</x-fa::badge>@endforeach
                    </x-slot:actions>

                    {{-- Concept-Typ (#35): Menü (Gänge nacheinander) vs. Buffet (Stationen parallel). Steuert
                         das Positionen-Vokabular (Label + Stationen + Gänge-Obergrenze). Nur Concept. --}}
                    <div class="flex flex-col gap-1.5" data-menue-typ>
                        <x-fa::choice name="regler.{{ $scope }}.menue_typ" :options="$I::MENUE_TYPEN" label="Menü oder Buffet" id-prefix="leitplanken" data-menue-typ-select />
                        <p class="{{ $hinweisKlasse }} max-w-2xl">{{ $istBuffet ? 'Buffet: parallele Stationen mit eigener Positionen-Logik. Die Anzahl Stationen begrenzt die Stationen, es sind keine Gänge.' : 'Menü: Gänge in der Reihenfolge des Ablaufs. Für ein Buffet auf Buffet wechseln, dann entstehen Stationen statt Gänge.' }}</p>
                    </div>

                    {{-- Menü-Leitplanken (Zusammenstellung): steuern das GANZE Menü (Anzahl Gänge + Zielpreis-
                         Korridor je Person), nicht die Rezept-Generierung. Etappe 2a. --}}
                    <div class="border-t border-[var(--fa-line)] pt-4 mt-4 flex flex-col gap-2" data-menue-leitplanken>
                        <div class="flex flex-wrap items-start gap-x-6 gap-y-4">
                            <div class="flex flex-col gap-1.5 w-36">
                                <label for="planung-menue-gaenge" class="{{ $labelKlasse }}">{{ $istBuffet ? 'Anzahl Stationen' : 'Anzahl Gänge' }}</label>
                                <x-fa::input id="planung-menue-gaenge" type="number" min="1" max="20" step="1" numeric wire:model="regler.{{ $scope }}.menue_gaenge" placeholder="{{ $istBuffet ? 'zum Beispiel 6' : 'zum Beispiel 4' }}" data-menue-gaenge />
                            </div>
                            <div class="flex flex-col gap-1.5 w-40">
                                <label for="planung-menue-preis-min" class="{{ $labelKlasse }}">Preis ab p. P. (€)</label>
                                <x-fa::input id="planung-menue-preis-min" numeric wire:model="regler.{{ $scope }}.menue_preis_min" placeholder="zum Beispiel 35,00" data-menue-preis-min />
                            </div>
                            <div class="flex flex-col gap-1.5 w-40">
                                <label for="planung-menue-preis-ziel" class="{{ $labelKlasse }}">Zielpreis p. P. (€)</label>
                                <x-fa::input id="planung-menue-preis-ziel" numeric wire:model="regler.{{ $scope }}.menue_preis_ziel" placeholder="zum Beispiel 45,00" data-menue-preis-ziel />
                            </div>
                            <div class="flex flex-col gap-1.5 w-40">
                                <label for="planung-menue-preis-max" class="{{ $labelKlasse }}">Preis bis p. P. (€)</label>
                                <x-fa::input id="planung-menue-preis-max" numeric wire:model="regler.{{ $scope }}.menue_preis_max" placeholder="zum Beispiel 60,00" data-menue-preis-max />
                            </div>
                        </div>
                        <p class="{{ $hinweisKlasse }} max-w-2xl">Netto je Person für das ganze Menü. Leer lassen: Die KI wählt Umfang und Preislage passend zum Briefing.</p>
                    </div>

                    {{-- Diät-Quoten (Portfolio-ANTEIL) — bewusst getrennt vom harten Ausschluss in der Karte
                         „Ernährung und Allergene": hier steuert der Anteil der Positionen (»mind. X % vegan«). --}}
                    <div class="border-t border-[var(--fa-line)] pt-4 mt-4 flex flex-col gap-2" data-menue-diaet-quoten>
                        <div class="flex flex-wrap items-start gap-x-6 gap-y-4">
                            <div class="flex flex-col gap-1.5 w-40">
                                <label for="planung-quote-vegan" class="{{ $labelKlasse }}">Anteil vegan (%)</label>
                                <x-fa::input id="planung-quote-vegan" type="number" min="0" max="100" step="1" numeric wire:model="regler.{{ $scope }}.menue_quote_vegan" placeholder="zum Beispiel 30" data-menue-quote-vegan />
                            </div>
                            <div class="flex flex-col gap-1.5 w-40">
                                <label for="planung-quote-vegetarisch" class="{{ $labelKlasse }}">Anteil vegetarisch (%)</label>
                                <x-fa::input id="planung-quote-vegetarisch" type="number" min="0" max="100" step="1" numeric wire:model="regler.{{ $scope }}.menue_quote_vegetarisch" placeholder="zum Beispiel 50" data-menue-quote-vegetarisch />
                            </div>
                        </div>
                        <p class="{{ $hinweisKlasse }} max-w-2xl">Weicher Anteil an den Positionen, kein Ausschluss wie unter Ernährung und Allergene. Leer lassen: keine Quote.</p>
                    </div>

                    {{-- Portfolio-Balance (Menü-Vielfalt) — weiche Zusammenstellungs-Vorgabe: wie breit das Menü
                         über Proteine/Warengruppen/Garmethoden streut. Enum, kein Filter. --}}
                    <div class="border-t border-[var(--fa-line)] pt-4 mt-4 flex flex-col gap-1.5" data-menue-balance>
                        <x-fa::choice name="regler.{{ $scope }}.menue_balance" :options="$balanceOptionen" :live="false" label="Vielfalt" id-prefix="leitplanken" data-menue-balance-select />
                        <p class="{{ $hinweisKlasse }} max-w-2xl">Wie breit streut das Menü über Proteine, Warengruppen und Garmethoden? Ausgewogen: bewusste Vielfalt, Hauptzutaten wiederholen sich nicht. Fokussiert: ein Thema zieht sich durch. Keine Vorgabe: Die KI entscheidet passend zum Briefing.</p>
                    </div>
                </x-foodalchemist::modal-section>
            </div>
        @endif
    </div>
