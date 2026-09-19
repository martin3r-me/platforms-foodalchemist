{{-- Leitplanken-Fläche (Richtungs-Regler) JE SCOPE — jeder Tab hat einen eigenen Regler-Satz.
     Erwartet $scope (rezept|gericht|concept). VK-Achsen (Anlass/Serviceform/Kompositions-Stil/Ziel-VK)
     nur wenn $scope != rezept. Alle Bindings gehen auf regler.{scope}.* (unabhängig pro Tab).

     Cockpit-Optik (Paket K, Rollout 2026-09-19): EINE Kartenstruktur für ALLE drei Scopes. Der Pilot
     aus PR #149 trug noch zwei Datei-Varianten (@if($scope==='rezept') Karten / @else eine Fläche mit
     ~20 Reglern) — bewusst als Übergangszustand. Hier verschmolzen: Küche, Anspruch, Anreicherung und
     Constraints sind scope-identisch; scope-abhängig sind nur
       · die Ziel-Karte  — rezept: Einheit + Ziel-Menge (Halbfabrikat-Charge)
                           gericht: Pax + Ziel-Portion + VK-Achsen + Ziel-VK
                           concept: Pax + VK-Achsen, OHNE Ziel-Portion und OHNE Ziel-VK
                           (Entscheid 2026-08-18: für ein Concept ist der Menü-Preis-Korridor p. P.
                            die EINZIGE Preisquelle, Ziel-Portion ist scope-fremd)
       · die Struktur-Karte — nur concept (Concept-Typ + Menü-/Buffet-Leitplanken + Diät-Quoten +
                           Portfolio-Balance; vorher ein flacher Block in der Reglerfläche).
     Reine Optik: Felder, Reihenfolge innerhalb der Gruppen, Bindings, Hinweistexte, alle data-*-Anker
     und alle Agent-Badges unverändert übernommen — nur neu gruppiert und mit Kartenkopf-Zusammenfassung. --}}
@php
    $scope = $scope ?? 'rezept';
    $vk = $scope !== 'rezept';
    $r = $regler[$scope] ?? [];
    $pillRuhe = 'border-black/10 text-gray-600 hover:border-violet-400';
    // Aktive Akzentfarbe der Karten-Pills: FA-Violett (löst das frühere Emerald der flachen Fläche ab).
    $pillAktivKarte = 'border-violet-500 bg-violet-500/10 text-violet-700 font-medium';
    // Concept-Typ (#35): Buffet baut Stationen statt Gänge → Label/Header schalten mit.
    $istBuffet = ($r['menue_typ'] ?? '') === 'buffet';

    $richtungenByField = collect(\Platform\FoodAlchemist\Livewire\Planung\Index::RICHTUNGEN)->keyBy('field');
    $diaetLabels = [
        'vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei',
        'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb',
    ];
    // VK-Achsen-Vokabular: Sektor, Anlass und Serviceform kommen aus den Index-Konstanten
    // (Oskar, Spec 55 Nachtrag) — Feld UND Kartenkopf lesen dieselbe Quelle, und der Agent
    // schreibt gegen dieselbe Liste. Kompositions-Stil hat (noch) keine Konstante, bleibt lokal.
    $kompositionsStilLabels = [
        'klassisch' => 'klassisch', 'kreativ' => 'kreativ', 'gewagt' => 'gewagt (nur belegte Paarungen)',
    ];
    // Kartenkopf: „Standard" (nichts vom Default abgewichen) oder die aktiven Werte als Chips —
    // damit man auf einen Blick sieht, was in einer Karte aktiv ist, ohne sie zu öffnen.
    $kopf = fn (array $werte) => collect($werte)->filter(fn ($w) => $w !== null && trim((string) $w) !== '')->values();
    $kartenKopf = function ($werte) use ($pill, $variantPill) {
        if ($werte->isEmpty()) {
            return '<span class="' . $pill . ' ' . $variantPill['secondary'] . '">Standard</span>';
        }
        return $werte->map(fn ($w) => '<span class="' . $pill . ' ' . $variantPill['primary'] . '">' . e($w) . '</span>')->implode(' ');
    };

    $kuecheKopf = $kopf([
        ($r['convenience'] ?? '') !== '' ? ($richtungenByField['convenience']['optionen'][$r['convenience']] ?? null) : null,
        (($r['bio_praeferenz'] ?? 'konventionell') !== 'konventionell') ? ($richtungenByField['bio_praeferenz']['optionen'][$r['bio_praeferenz']] ?? null) : null,
        ($r['aroma_kueche'] ?? '') !== '' ? (\Platform\FoodAlchemist\Livewire\Planung\Index::AROMA_KUECHEN[$r['aroma_kueche']] ?? null) : null,
        trim($r['aroma'] ?? '') !== '' ? '„' . \Illuminate\Support\Str::limit(trim($r['aroma']), 22) . '"' : null,
    ]);
    $anspruchKopf = $kopf([
        ($r['level'] ?? '') !== '' ? ($richtungenByField['level']['optionen'][$r['level']] ?? null) : null,
        !empty($r['frische'] ?? []) ? collect((array) $r['frische'])->map(fn ($w) => \Platform\FoodAlchemist\Livewire\Planung\Index::FRISCHE_OPTIONEN[$w] ?? $w)->implode(' / ') : null,
        ($r['sektor'] ?? '') !== '' ? (\Platform\FoodAlchemist\Livewire\Planung\Index::SEKTOR_OPTIONEN[$r['sektor']] ?? null) : null,
    ]);
    $anreicherungKopf = $kopf([
        !($r['voll_anreichern'] ?? true) ? 'Anreicherung aus' : null,
        ($r['ki_bilder'] ?? false) ? 'KI-Fotos an' : null,
        ($r['favoriten'] ?? false) ? ('Favoriten' . (($r['favoriten_conv_only'] ?? false) ? ' · nur Convenience' : '')) : null,
    ]);
    $constraintsKopf = $kopf([
        !empty($r['diaet_hart'] ?? []) ? collect((array) $r['diaet_hart'])->map(fn ($w) => $diaetLabels[$w] ?? $w)->implode(' / ') : null,
        !empty($r['allergen_nogo'] ?? []) ? collect((array) $r['allergen_nogo'])->map(fn ($w) => \Platform\FoodAlchemist\Livewire\Planung\Index::ALLERGEN_LABELS[$w] ?? $w)->implode(' / ') : null,
    ]);
    // Ziel-Karte: scope-abhängige Kopfzeile. rezept zeigt die Charge (Menge + Einheit), gericht/concept
    // die Gäste-/VK-Achsen; Ziel-Portion und Ziel-VK nur am Gericht (siehe Kopfkommentar).
    $zielKopf = $scope === 'rezept'
        ? $kopf([
            trim((string) ($r['ziel_menge'] ?? '')) !== '' ? (trim((string) $r['ziel_menge']) . ' ' . (\Platform\FoodAlchemist\Livewire\Planung\Index::MENGE_EINHEITEN[$r['ziel_einheit'] ?? ''] ?? '')) : null,
            ($r['saison'] ?? '') !== '' ? (\Platform\FoodAlchemist\Livewire\Planung\Index::SAISON_OPTIONEN[$r['saison']] ?? null) : null,
            trim((string) ($r['ziel_we_pct'] ?? '')) !== '' ? (trim((string) $r['ziel_we_pct']) . ' % WE') : null,
        ])
        : $kopf([
            trim((string) ($r['pax'] ?? '')) !== '' ? (trim((string) $r['pax']) . ' Pax') : null,
            ($scope === 'gericht' && trim((string) ($r['ziel_portion_g'] ?? '')) !== '') ? (trim((string) $r['ziel_portion_g']) . ' g/Portion') : null,
            ($r['occasion'] ?? '') !== '' ? (\Platform\FoodAlchemist\Livewire\Planung\Index::OCCASION_OPTIONEN[$r['occasion']] ?? null) : null,
            ($r['serviceform'] ?? '') !== '' ? (\Platform\FoodAlchemist\Livewire\Planung\Index::SERVICEFORM_OPTIONEN[$r['serviceform']] ?? null) : null,
            ($r['kompositions_stil'] ?? '') !== '' ? ($kompositionsStilLabels[$r['kompositions_stil']] ?? null) : null,
            ($scope === 'gericht' && trim((string) ($r['ziel_vk'] ?? '')) !== '') ? ('VK ' . trim((string) $r['ziel_vk']) . ' €') : null,
            ($r['saison'] ?? '') !== '' ? (\Platform\FoodAlchemist\Livewire\Planung\Index::SAISON_OPTIONEN[$r['saison']] ?? null) : null,
            trim((string) ($r['ziel_we_pct'] ?? '')) !== '' ? (trim((string) $r['ziel_we_pct']) . ' % WE') : null,
        ]);

    // Struktur-Karte (nur concept): Umfang + Preislage + Vielfalt des GANZEN Menüs/Buffets.
    $strukturKopf = $scope === 'concept'
        ? $kopf([
            $istBuffet ? 'Buffet' : 'Menü',
            trim((string) ($r['menue_gaenge'] ?? '')) !== '' ? (trim((string) $r['menue_gaenge']) . ($istBuffet ? ' Stationen' : ' Gänge')) : null,
            trim((string) ($r['menue_preis_ziel'] ?? '')) !== '' ? ('Ziel ' . trim((string) $r['menue_preis_ziel']) . ' € p. P.') : null,
            ($r['menue_balance'] ?? '') !== '' ? (\Platform\FoodAlchemist\Livewire\Planung\Index::MENUE_BALANCE[$r['menue_balance']] ?? null) : null,
        ])
        : $kopf([]);
@endphp

    {{-- Breite-Fix (Dominique-Feedback 2026-09-19): Karten 2-spaltig ab xl statt einzeln über die
         volle Breite — die Lesebreiten-Begrenzung übernimmt der Gesamtcontainer im Elternteil
         (erstellen-tab.blade.php: max-w-7xl mx-auto), damit Eingabe/Leitplanken/Go gleich breit sind. --}}
    <div>
        <div class="xl:grid xl:grid-cols-2 xl:gap-4">
            <x-foodalchemist::modal-section class="!mt-0" icon="heroicon-o-fire" title="Küche">
                <x-slot:actions>{!! $kartenKopf($kuecheKopf) !!}</x-slot:actions>
                <div class="grid sm:grid-cols-2 gap-x-4 gap-y-3" data-planung-regler="{{ $scope }}">
                    <div data-richtung="{{ $richtungenByField['convenience']['field'] }}">
                        <p class="{{ $label }} mb-1">{{ $richtungenByField['convenience']['label'] }}
                            @if($reglerVonAgent[$scope]['convenience'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="convenience" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                            @endif
                        </p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($richtungenByField['convenience']['optionen'] as $wert => $lbl)
                                <button type="button" wire:click="reglerPill('{{ $scope }}', 'convenience', '{{ $wert }}')"
                                        class="px-2 py-0.5 rounded-full border text-[11px] transition-colors {{ ($r['convenience'] ?? '') === $wert ? $pillAktivKarte : $pillRuhe }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">{{ $richtungenByField['convenience']['hint'][$r['convenience'] ?? ''] ?? '' }}</p>
                    </div>

                    <div data-richtung="{{ $richtungenByField['bio_praeferenz']['field'] }}">
                        <p class="{{ $label }} mb-1">{{ $richtungenByField['bio_praeferenz']['label'] }}
                            @if($reglerVonAgent[$scope]['bio_praeferenz'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="bio_praeferenz" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                            @endif
                        </p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($richtungenByField['bio_praeferenz']['optionen'] as $wert => $lbl)
                                <button type="button" wire:click="reglerPill('{{ $scope }}', 'bio_praeferenz', '{{ $wert }}')"
                                        class="px-2 py-0.5 rounded-full border text-[11px] transition-colors {{ ($r['bio_praeferenz'] ?? '') === $wert ? $pillAktivKarte : $pillRuhe }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">{{ $richtungenByField['bio_praeferenz']['hint'][$r['bio_praeferenz'] ?? ''] ?? '' }}</p>
                    </div>

                    <div class="sm:col-span-2" data-richtung="aroma">
                        <p class="{{ $label }} mb-1">Aroma-Richtung</p>
                        <select wire:model="regler.{{ $scope }}.aroma_kueche" class="{{ $input }} !py-1.5 mb-1 max-w-sm" data-planung-aroma-kueche>
                            @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::AROMA_KUECHEN as $wert => $lbl)
                                <option value="{{ $wert }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="regler.{{ $scope }}.aroma" placeholder="Feinjustierung — z. B. rauchig-karamellig, umami-lastig …" class="{{ $input }} !py-1.5 max-w-sm" />
                        <p class="text-[10px] text-gray-500 mt-1">Küche steuert die Würzung (Anker/Technik/Archetyp); Freitext justiert zusätzlich. Beides optional.</p>
                    </div>
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section class="!mt-0" icon="heroicon-o-star" title="Anspruch">
                <x-slot:actions>{!! $kartenKopf($anspruchKopf) !!}</x-slot:actions>
                <div class="grid sm:grid-cols-2 gap-x-4 gap-y-3">
                    <div data-richtung="{{ $richtungenByField['level']['field'] }}">
                        <p class="{{ $label }} mb-1">{{ $richtungenByField['level']['label'] }}
                            @if($reglerVonAgent[$scope]['level'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="level" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                            @endif
                        </p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($richtungenByField['level']['optionen'] as $wert => $lbl)
                                <button type="button" wire:click="reglerPill('{{ $scope }}', 'level', '{{ $wert }}')"
                                        class="px-2 py-0.5 rounded-full border text-[11px] transition-colors {{ ($r['level'] ?? '') === $wert ? $pillAktivKarte : $pillRuhe }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">{{ $richtungenByField['level']['hint'][$r['level'] ?? ''] ?? '' }}</p>
                    </div>

                    <div data-richtung="frische">
                        <p class="{{ $label }} mb-1">Frische (Zustands-Erlaubnis)</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::FRISCHE_OPTIONEN as $wert => $lbl)
                                <button type="button" wire:click="reglerPill('{{ $scope }}', 'frische', '{{ $wert }}')"
                                        class="px-2 py-0.5 rounded-full border text-[11px] transition-colors {{ in_array($wert, (array) ($r['frische'] ?? []), true) ? $pillAktivKarte : $pillRuhe }}" data-planung-frische="{{ $wert }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">{{ empty($r['frische'] ?? []) ? 'Egal — kein Zustands-Filter' : 'Nur diese Zustände (frisch bevorzugt)' }}</p>
                    </div>

                    <div class="sm:col-span-2" data-richtung="sektor">
                        <p class="{{ $label }} mb-1">Sektor (Verpflegungskontext)
                            @if($reglerVonAgent[$scope]['sektor'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="sektor" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                            @endif
                        </p>
                        <select wire:model="regler.{{ $scope }}.sektor" class="{{ $input }} !py-1.5 max-w-sm">
                            @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::SEKTOR_OPTIONEN as $wert => $lbl)
                                <option value="{{ $wert }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                        <p class="text-[10px] text-gray-500 mt-1">{{ ($r['sektor'] ?? '') === '' ? 'Kein Sektor-Constraint' : '' }}</p>
                    </div>
                </div>
            </x-foodalchemist::modal-section>
        </div>

        <div class="xl:grid xl:grid-cols-2 xl:gap-4 mt-4">
            <x-foodalchemist::modal-section class="!mt-0" icon="heroicon-o-sparkles" title="Anreicherung">
                <x-slot:actions>{!! $kartenKopf($anreicherungKopf) !!}</x-slot:actions>
                <div class="space-y-3">
                    <div data-richtung="voll-anreichern">
                        <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                            <input type="checkbox" wire:model="regler.{{ $scope }}.voll_anreichern" class="mt-0.5" data-planung-voll-anreichern />
                            <span>⚡ Voll anreichern</span>
                        </label>
                        <p class="text-[10px] text-gray-500 mt-1">An (Standard) = bei der Freigabe auch Schritte, Sensorik, Zeiten, Equipment, Posten und Pairings erzeugen. Aus = nur Kernfelder (<em>leicht angereichert</em>).</p>
                    </div>

                    <div data-richtung="ki-bilder">
                        <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                            <input type="checkbox" wire:model="regler.{{ $scope }}.ki_bilder" class="mt-0.5" data-planung-ki-bilder />
                            <span>📷 KI-Fotos bei Anreicherung erstellen</span>
                        </label>
                        <p class="text-[10px] text-gray-500 mt-1">Schritt-für-Schritt + Produktfoto (je Bild ein KI-Call → <b>Kosten</b>). Aus = keine Bilder.</p>
                    </div>

                    <div class="border-t border-black/5 pt-2.5" data-richtung="favoriten">
                        <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                            <input type="checkbox" wire:model.live="regler.{{ $scope }}.favoriten" class="mt-0.5" data-planung-favoriten />
                            <span>⭐ Auf Basis meiner Favoriten bauen</span>
                        </label>
                        <p class="text-[10px] text-gray-500 mt-1">Bevorzugt kuratierte Lieblings-GPs. Aus = freie Kreativität.</p>
                        <label x-show="$wire.get('regler.{{ $scope }}.favoriten')" class="flex items-center gap-1.5 text-[11px] text-gray-600 mt-1 ml-6">
                            <input type="checkbox" wire:model="regler.{{ $scope }}.favoriten_conv_only" /> nur Convenience-Favoriten
                        </label>
                    </div>
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section class="!mt-0" icon="heroicon-o-shield-check" title="Constraints">
                <x-slot:actions>{!! $kartenKopf($constraintsKopf) !!}</x-slot:actions>
                <div class="space-y-3">
                    <div data-richtung="diaet">
                        <p class="{{ $label }} mb-1">Diät-Constraints (Multi-Select, hart geprüft)</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($diaetLabels as $wert => $lbl)
                                <button type="button" wire:click="reglerPill('{{ $scope }}', 'diaet_hart', '{{ $wert }}')"
                                        class="px-2 py-0.5 rounded-full border text-[11px] transition-colors {{ in_array($wert, (array) ($r['diaet_hart'] ?? []), true) ? $pillAktivKarte : $pillRuhe }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Verletzende Zutaten werden nach der Erzeugung gelöst + gemeldet (keine harte Sperre).</p>
                    </div>

                    <div data-richtung="allergen-nogo">
                        <p class="{{ $label }} mb-1">Allergen-Ausschluss (EU-14, hart geprüft)</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::ALLERGEN_LABELS as $wert => $lbl)
                                <button type="button" wire:click="reglerPill('{{ $scope }}', 'allergen_nogo', '{{ $wert }}')"
                                        class="px-2 py-0.5 rounded-full border text-[11px] transition-colors {{ in_array($wert, (array) ($r['allergen_nogo'] ?? []), true) ? $pillAktivKarte : $pillRuhe }}" data-planung-allergen-nogo="{{ $wert }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">{{ empty($r['allergen_nogo'] ?? []) ? 'Kein Allergen-Ausschluss' : 'Zutaten mit diesem Allergen werden gelöst + gemeldet.' }}</p>
                    </div>
                </div>
            </x-foodalchemist::modal-section>
        </div>

        <x-foodalchemist::modal-section icon="heroicon-o-flag" title="Ziel">
            <x-slot:actions>{!! $kartenKopf($zielKopf) !!}</x-slot:actions>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 max-w-2xl" data-richtung="menge-ziel">
                @if($scope === 'rezept')
                    {{-- Basisrezept = Halbfabrikat (Charge in einer Einheit), kein Teller für N Gäste:
                         Ziel-Menge + Einheit statt Pax/Portion (2 L Sauce, 5 kg Teig, 30 Stk …). --}}
                    <div>
                        <label class="block {{ $label }} mb-1">Einheit
                            @if($reglerVonAgent[$scope]['ziel_einheit'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="ziel_einheit" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                            @endif
                        </label>
                        <select wire:model="regler.{{ $scope }}.ziel_einheit" class="{{ $input }} !py-1.5" data-planung-ziel-einheit>
                            @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::MENGE_EINHEITEN as $wert => $lbl)
                                <option value="{{ $wert }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block {{ $label }} mb-1">Ziel-Menge
                            @if($reglerVonAgent[$scope]['ziel_menge'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="ziel_menge" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                            @endif
                        </label>
                        <input type="number" min="0" step="any" wire:model="regler.{{ $scope }}.ziel_menge" placeholder="z. B. 2" class="{{ $input }} !py-1.5 font-mono" data-planung-ziel-menge />
                    </div>
                @else
                    <div>
                        <label class="block {{ $label }} mb-1">Pax / Gäste
                            @if($reglerVonAgent[$scope]['pax'] ?? false)
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="pax" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                            @endif
                        </label>
                        <input type="number" min="1" max="100000" step="1" wire:model="regler.{{ $scope }}.pax" placeholder="z. B. 50" class="{{ $input }} !py-1.5 font-mono" data-planung-pax />
                    </div>
                    {{-- Ziel-Portion (g) ist per-Portion — für ein Concept (ganzes Menü) scope-fremd, darum nur
                         am Gericht (Leitplanken-Hygiene 2026-08-18). Den Concept-Umfang steuert die Struktur-Karte. --}}
                    @if($scope !== 'concept')
                        <div>
                            <label class="block {{ $label }} mb-1">Ziel-Portion (g)</label>
                            <input type="number" min="1" max="5000" step="1" wire:model="regler.{{ $scope }}.ziel_portion_g" placeholder="z. B. 180" class="{{ $input }} !py-1.5 font-mono" data-planung-portion-g />
                        </div>
                    @endif
                @endif
                <div>
                    <label class="block {{ $label }} mb-1">Saison</label>
                    <select wire:model="regler.{{ $scope }}.saison" class="{{ $input }} !py-1.5" data-planung-saison>
                        @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::SAISON_OPTIONEN as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Ziel-Wareneinsatz (%)</label>
                    <input type="number" min="1" max="100" step="1" wire:model="regler.{{ $scope }}.ziel_we_pct" placeholder="z. B. 28" class="{{ $input }} !py-1.5 font-mono" data-planung-we-pct />
                </div>
            </div>

            @if($vk)
                {{-- VK-eigene Achsen — nur Gericht/Concept. Lagen vorher als eigener Block am Ende der
                     flachen Reglerfläche; sie beschreiben dasselbe wie Pax/Portion (wofür wird gebaut),
                     darum jetzt in derselben Ziel-Karte statt in einem eigenen Abschnitt. --}}
                <div class="border-t border-black/5 pt-3 mt-3" data-richtung="vk-achsen">
                    <div class="grid md:grid-cols-3 gap-x-4 gap-y-3 max-w-2xl">
                        <div>
                            <label class="block {{ $label }} mb-1">Anlass
                                @if($reglerVonAgent[$scope]['occasion'] ?? false)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="occasion" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                                @endif
                            </label>
                            <select wire:model="regler.{{ $scope }}.occasion" class="{{ $input }} !py-1.5">
                                @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::OCCASION_OPTIONEN as $wert => $lbl)
                                    <option value="{{ $wert }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Serviceform
                                @if($reglerVonAgent[$scope]['serviceform'] ?? false)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="serviceform" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                                @endif
                            </label>
                            <select wire:model="regler.{{ $scope }}.serviceform" class="{{ $input }} !py-1.5">
                                @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::SERVICEFORM_OPTIONEN as $wert => $lbl)
                                    <option value="{{ $wert }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Kompositions-Stil</label>
                            <select wire:model="regler.{{ $scope }}.kompositions_stil" class="{{ $input }} !py-1.5">
                                <option value="">—</option>
                                @foreach($kompositionsStilLabels as $wert => $lbl)
                                    <option value="{{ $wert }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    {{-- Ziel-VK ist der Portions-Preis (Gericht). Für ein Concept ist der Menü-Preis-Korridor
                         p. P. in der Struktur-Karte die EINZIGE Preisquelle (Entscheid 2026-08-18) — hier
                         kein zweiter Preis. --}}
                    @if($scope === 'gericht')
                        <div class="mt-3 max-w-2xl">
                            <label class="block {{ $label }} mb-1">Ziel-VK (optional)
                                @if($reglerVonAgent[$scope]['ziel_vk'] ?? false)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-regler-von-agent="ziel_vk" title="Vom Sprachbefehl-Agenten vorgeschlagen — verschwindet bei manueller Änderung">Agent</span>
                                @endif
                            </label>
                            <input type="text" wire:model="regler.{{ $scope }}.ziel_vk" placeholder="z. B. 8,50" class="{{ $input }} !py-1.5 font-mono md:max-w-xs" data-planung-ziel-vk />
                            <p class="text-[10px] text-gray-500 mt-1">Netto je Portion. Geht als Vorgabe in den Vorschlag; der Preis wird nicht auf das Ziel gedrückt.</p>
                        </div>
                    @endif
                </div>
            @endif
        </x-foodalchemist::modal-section>

        @if($scope === 'concept')
            {{-- Struktur — nur Concept: Umfang, Preislage und Vielfalt des GANZEN Menüs/Buffets. Das ist
                 eine andere Frage als die Ziel-Karte darüber (wofür + wie teuer je Portion), darum eine
                 eigene Karte statt eines Unterblocks in der Reglerfläche. --}}
            <x-foodalchemist::modal-section icon="heroicon-o-squares-2x2" :title="$istBuffet ? 'Struktur — Buffet (Zusammenstellung)' : 'Struktur — Menü (Zusammenstellung)'">
                <x-slot:actions>{!! $kartenKopf($strukturKopf) !!}</x-slot:actions>

                {{-- Concept-Typ (#35): Menü (Gänge nacheinander) vs. Buffet (Stationen parallel). Steuert
                     das Positionen-Vokabular (Label + station-Slots + Gänge-Cap). Nur Concept. --}}
                <div class="max-w-2xl" data-menue-typ>
                    <label class="block {{ $label }} mb-1">Concept-Typ</label>
                    <select wire:model.live="regler.{{ $scope }}.menue_typ" class="{{ $input }} !py-1.5 md:max-w-xs" data-menue-typ-select>
                        @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::MENUE_TYPEN as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] text-gray-500 mt-1">{{ $istBuffet ? 'Buffet = parallele Stationen (eigene Positionen-Logik). Die »Anzahl Stationen« deckelt die Stationen — es sind keine Gänge.' : 'Menü = Gänge in Dramaturgie-Reihenfolge. Für ein Buffet auf »Buffet« wechseln (baut Stationen statt Gänge).' }}</p>
                </div>

                {{-- Menü-Leitplanken (Zusammenstellung): steuern das GANZE Menü (Anzahl Gänge + Zielpreis-
                     Korridor je Person), nicht die Rezept-Generierung. Etappe 2a. --}}
                <div class="border-t border-black/5 pt-3 mt-3" data-menue-leitplanken>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 max-w-2xl">
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $istBuffet ? 'Anzahl Stationen' : 'Anzahl Gänge (nur Menü)' }}</label>
                            <input type="number" min="1" max="20" step="1" wire:model="regler.{{ $scope }}.menue_gaenge" placeholder="{{ $istBuffet ? 'z. B. 6' : 'z. B. 4' }}" class="{{ $input }} !py-1.5 font-mono" data-menue-gaenge />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Preis-Untergrenze p. P.</label>
                            <input type="text" wire:model="regler.{{ $scope }}.menue_preis_min" placeholder="z. B. 35,00" class="{{ $input }} !py-1.5 font-mono" data-menue-preis-min />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Zielpreis p. P.</label>
                            <input type="text" wire:model="regler.{{ $scope }}.menue_preis_ziel" placeholder="z. B. 45,00" class="{{ $input }} !py-1.5 font-mono" data-menue-preis-ziel />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Preis-Obergrenze p. P.</label>
                            <input type="text" wire:model="regler.{{ $scope }}.menue_preis_max" placeholder="z. B. 60,00" class="{{ $input }} !py-1.5 font-mono" data-menue-preis-max />
                        </div>
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">Netto je Person für das gesamte Menü. Leer = keine Vorgabe — die KI wählt Umfang und Preislage passend zum Briefing.</p>
                </div>

                {{-- Diät-Quoten (Portfolio-ANTEIL) — bewusst getrennt von den harten Diät-Constraints in der
                     Constraints-Karte: hier steuert der Anteil der Positionen (»mind. X % vegan«), nicht ein
                     Ausschluss für das ganze Menü. Etappe 2a, Teil 2. --}}
                <div class="border-t border-black/5 pt-3 mt-3" data-menue-diaet-quoten>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 max-w-2xl">
                        <div>
                            <label class="block {{ $label }} mb-1">Vegan-Anteil (%)</label>
                            <input type="number" min="0" max="100" step="1" wire:model="regler.{{ $scope }}.menue_quote_vegan" placeholder="z. B. 30" class="{{ $input }} !py-1.5 font-mono" data-menue-quote-vegan />
                        </div>
                        <div>
                            <label class="block {{ $label }} mb-1">Vegetarisch-Anteil (%)</label>
                            <input type="number" min="0" max="100" step="1" wire:model="regler.{{ $scope }}.menue_quote_vegetarisch" placeholder="z. B. 50" class="{{ $input }} !py-1.5 font-mono" data-menue-quote-vegetarisch />
                        </div>
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">Portfolio-Anteil (weiche Zusammenstellungs-Vorgabe), nicht der harte Ausschluss in der Constraints-Karte. Leer = keine Quote.</p>
                </div>

                {{-- Portfolio-Balance (Menü-Vielfalt) — weiche Zusammenstellungs-Vorgabe: wie breit das Menü
                     über Proteine/Warengruppen/Garmethoden streut. Enum, kein Filter. Etappe 2a, Rest Teil 2. --}}
                <div class="border-t border-black/5 pt-3 mt-3" data-menue-balance>
                    <label class="block {{ $label }} mb-1">Portfolio-Balance (Vielfalt)</label>
                    <select wire:model="regler.{{ $scope }}.menue_balance" class="{{ $input }} !py-1.5 md:max-w-xs" data-menue-balance-select>
                        <option value="">— keine Vorgabe</option>
                        @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::MENUE_BALANCE as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] text-gray-500 mt-1 max-w-2xl">Wie breit streut das Menü über Proteine, Warengruppen und Garmethoden? „Ausgewogen" = bewusste Vielfalt, Hauptzutaten nicht wiederholen; „Fokussiert" = ein Thema durchziehen. Leer = die KI entscheidet passend zum Briefing.</p>
                </div>
            </x-foodalchemist::modal-section>
        @endif
    </div>
