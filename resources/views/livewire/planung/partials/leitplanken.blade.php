{{-- Leitplanken-Fläche (Richtungs-Regler) JE SCOPE — jeder Tab hat einen eigenen Regler-Satz.
     Erwartet $scope (rezept|gericht|concept). VK-Achsen (Anlass/Serviceform/Kompositions-Stil/Ziel-VK)
     nur wenn $scope != rezept. Alle Bindings gehen auf regler.{scope}.* (unabhängig pro Tab). --}}
@php
    $scope = $scope ?? 'rezept';
    $vk = $scope !== 'rezept';
    $r = $regler[$scope] ?? [];
    $pillAktiv = 'border-emerald-500 text-emerald-700 font-medium';
    $pillRuhe = 'border-black/10 text-gray-600 hover:border-violet-400';
    // Concept-Typ (#35): Buffet baut Stationen statt Gänge → Label/Header schalten mit.
    $istBuffet = ($r['menue_typ'] ?? '') === 'buffet';
@endphp
<x-foodalchemist::modal-section title="Richtung (optional)">
    <div class="grid md:grid-cols-2 gap-x-6 gap-y-4" data-planung-regler="{{ $scope }}">
        @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::RICHTUNGEN as $g)
            <div data-richtung="{{ $g['field'] }}">
                <p class="text-xs font-medium text-gray-900 mb-1">{{ $g['label'] }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($g['optionen'] as $wert => $lbl)
                        <button type="button" wire:click="reglerPill('{{ $scope }}', '{{ $g['field'] }}', '{{ $wert }}')"
                                class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ ($r[$g['field']] ?? '') === $wert ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                    @endforeach
                </div>
                <p class="text-[11px] text-gray-500 mt-1">{{ $g['hint'][$r[$g['field']] ?? ''] ?? '' }}</p>
            </div>
        @endforeach

        <div data-richtung="frische">
            <p class="text-xs font-medium text-gray-900 mb-1">Frische (Zustands-Erlaubnis)</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::FRISCHE_OPTIONEN as $wert => $lbl)
                    <button type="button" wire:click="reglerPill('{{ $scope }}', 'frische', '{{ $wert }}')"
                            class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ in_array($wert, (array) ($r['frische'] ?? []), true) ? $pillAktiv : $pillRuhe }}" data-planung-frische="{{ $wert }}">{{ $lbl }}</button>
                @endforeach
            </div>
            <p class="text-[11px] text-gray-500 mt-1">{{ empty($r['frische'] ?? []) ? 'Egal — kein Zustands-Filter (KI wählt frei)' : 'Nur diese Zustände zugelassen (harter Filter; innerhalb: frisch bevorzugt)' }}</p>
        </div>

        <div data-richtung="aroma">
            <p class="text-xs font-medium text-gray-900 mb-1">Aroma-Richtung</p>
            <select wire:model="regler.{{ $scope }}.aroma_kueche" class="{{ $input }} !py-1.5 mb-1.5" data-planung-aroma-kueche>
                @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::AROMA_KUECHEN as $wert => $lbl)
                    <option value="{{ $wert }}">{{ $lbl }}</option>
                @endforeach
            </select>
            <input type="text" wire:model="regler.{{ $scope }}.aroma" placeholder="Feinjustierung — z. B. rauchig-karamellig, umami-lastig …" class="{{ $input }} !py-1.5" />
            <p class="text-[11px] text-gray-500 mt-1">Küche steuert die Würzung (Anker/Technik/Archetyp); Freitext justiert zusätzlich. Beides optional.</p>
        </div>

        <div data-richtung="sektor">
            <p class="text-xs font-medium text-gray-900 mb-1">Sektor (Verpflegungskontext)</p>
            <select wire:model="regler.{{ $scope }}.sektor" class="{{ $input }} !py-1.5">
                <option value="">(egal/universell)</option>
                <option value="betriebsgastronomie">Betriebsgastronomie</option>
                <option value="catering">Catering / Event</option>
                <option value="restaurant">Restaurant / à la carte</option>
                <option value="care">Care / Klinik</option>
                <option value="schule_kita">Schule / Kita</option>
            </select>
            <p class="text-[11px] text-gray-500 mt-1">{{ ($r['sektor'] ?? '') === '' ? 'Kein Sektor-Constraint' : '' }}</p>
        </div>

        <div data-richtung="favoriten">
            <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                <input type="checkbox" wire:model.live="regler.{{ $scope }}.favoriten" class="mt-0.5" data-planung-favoriten />
                <span>⭐ Auf Basis meiner Favoriten bauen</span>
            </label>
            <p class="text-[11px] text-gray-500 mt-1">Bevorzugt die kuratierten Lieblings-GPs (bevorzugt, nicht ausschließlich). Aus = freie Kreativität.</p>
            <label x-show="$wire.get('regler.{{ $scope }}.favoriten')" class="flex items-center gap-1.5 text-[11px] text-gray-600 mt-1.5 ml-6">
                <input type="checkbox" wire:model="regler.{{ $scope }}.favoriten_conv_only" /> nur Convenience-Favoriten
            </label>
        </div>

        <div data-richtung="voll-anreichern">
            <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                <input type="checkbox" wire:model="regler.{{ $scope }}.voll_anreichern" class="mt-0.5" data-planung-voll-anreichern />
                <span>⚡ Voll anreichern</span>
            </label>
            <p class="text-[11px] text-gray-500 mt-1">An = bei der Freigabe auch Schritte, Sensorik, Equipment und weitere Produktionsdaten erzeugen. Aus = leichte Anreicherung der Kernfelder; Vollanreicherung später bewusst starten.</p>
        </div>

        <div data-richtung="ki-bilder">
            <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                <input type="checkbox" wire:model="regler.{{ $scope }}.ki_bilder" class="mt-0.5" data-planung-ki-bilder />
                <span>📷 KI-Fotos bei Anreicherung erstellen</span>
            </label>
            <p class="text-[11px] text-gray-500 mt-1">Bei der Freigabe entstehen Schritt-für-Schritt-Fotos + ein Produktfoto (je Bild ein KI-Call → <b>Kosten</b>). Aus = keine Bilder.</p>
        </div>

        <div class="md:col-span-2" data-richtung="diaet">
            <p class="text-xs font-medium text-gray-900 mb-1">Diät-Constraints (Multi-Select, hart geprüft)</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach(['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb'] as $wert => $lbl)
                    <button type="button" wire:click="reglerPill('{{ $scope }}', 'diaet_hart', '{{ $wert }}')"
                            class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ in_array($wert, (array) ($r['diaet_hart'] ?? []), true) ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                @endforeach
            </div>
            <p class="text-[11px] text-gray-500 mt-1">Nach der Erzeugung geprüft: verletzende Zutaten werden gelöst + gemeldet (keine harte Sperre — du entscheidest).</p>
        </div>

        <div class="md:col-span-2" data-richtung="allergen-nogo">
            <p class="text-xs font-medium text-gray-900 mb-1">Allergen-Ausschluss (EU-14, hart geprüft)</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::ALLERGEN_LABELS as $wert => $lbl)
                    <button type="button" wire:click="reglerPill('{{ $scope }}', 'allergen_nogo', '{{ $wert }}')"
                            class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ in_array($wert, (array) ($r['allergen_nogo'] ?? []), true) ? $pillAktiv : $pillRuhe }}" data-planung-allergen-nogo="{{ $wert }}">{{ $lbl }}</button>
                @endforeach
            </div>
            <p class="text-[11px] text-gray-500 mt-1">{{ empty($r['allergen_nogo'] ?? []) ? 'Kein Allergen-Ausschluss' : 'Zutaten mit diesem Allergen werden nach der Erzeugung gelöst + gemeldet.' }}</p>
        </div>

        <div class="md:col-span-2 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-2" data-richtung="menge-ziel">
            @if($scope === 'rezept')
                {{-- Basisrezept = Halbfabrikat (Charge in einer Einheit), kein Teller für N Gäste:
                     Ziel-Menge + Einheit statt Pax/Portion (2 L Sauce, 5 kg Teig, 30 Stk …). --}}
                <div>
                    <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Einheit</label>
                    <select wire:model="regler.{{ $scope }}.ziel_einheit" class="{{ $input }} !py-1.5" data-planung-ziel-einheit>
                        @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::MENGE_EINHEITEN as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Ziel-Menge</label>
                    <input type="number" min="0" step="any" wire:model="regler.{{ $scope }}.ziel_menge" placeholder="z. B. 2" class="{{ $input }} !py-1.5" data-planung-ziel-menge />
                </div>
            @else
                <div>
                    <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Pax / Gäste</label>
                    <input type="number" min="1" max="100000" step="1" wire:model="regler.{{ $scope }}.pax" placeholder="z. B. 50" class="{{ $input }} !py-1.5" data-planung-pax />
                </div>
                {{-- Ziel-Portion (g) ist per-Portion — für ein Concept (ganzes Menü) scope-fremd, darum nur
                     am Gericht (Leitplanken-Hygiene 2026-08-18). Der Concept-Umfang steuert der Menü-Block unten. --}}
                @if($scope !== 'concept')
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Ziel-Portion (g)</label>
                        <input type="number" min="1" max="5000" step="1" wire:model="regler.{{ $scope }}.ziel_portion_g" placeholder="z. B. 180" class="{{ $input }} !py-1.5" data-planung-portion-g />
                    </div>
                @endif
            @endif
            <div>
                <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Saison</label>
                <select wire:model="regler.{{ $scope }}.saison" class="{{ $input }} !py-1.5" data-planung-saison>
                    @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::SAISON_OPTIONEN as $wert => $lbl)
                        <option value="{{ $wert }}">{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Ziel-Wareneinsatz (%)</label>
                <input type="number" min="1" max="100" step="1" wire:model="regler.{{ $scope }}.ziel_we_pct" placeholder="z. B. 28" class="{{ $input }} !py-1.5" data-planung-we-pct />
            </div>
        </div>

        @if($scope === 'concept')
            {{-- Concept-Typ (#35): Menü (Gänge nacheinander) vs. Buffet (Stationen parallel). Steuert
                 das Positionen-Vokabular (Label + station-Slots + Gänge-Cap). Nur Concept. --}}
            <div class="md:col-span-2 border-t border-black/5 pt-3 mt-1" data-menue-typ>
                <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Concept-Typ</label>
                <select wire:model.live="regler.{{ $scope }}.menue_typ" class="{{ $input }} !py-1.5 md:max-w-xs" data-menue-typ-select>
                    @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::MENUE_TYPEN as $wert => $lbl)
                        <option value="{{ $wert }}">{{ $lbl }}</option>
                    @endforeach
                </select>
                <p class="text-[11px] text-gray-500 mt-1.5">{{ $istBuffet ? 'Buffet = parallele Stationen (eigene Positionen-Logik). Die »Anzahl Stationen« deckelt die Stationen — es sind keine Gänge.' : 'Menü = Gänge in Dramaturgie-Reihenfolge. Für ein Buffet auf »Buffet« wechseln (baut Stationen statt Gänge).' }}</p>
            </div>
            {{-- Menü-Leitplanken (Zusammenstellung) — nur Concept: steuern das GANZE Menü (Anzahl Gänge +
                 Zielpreis-Korridor je Person), nicht die Rezept-Generierung. Etappe 2a. --}}
            <div class="md:col-span-2 pt-1" data-menue-leitplanken>
                <p class="text-xs font-semibold text-gray-900 mb-2">{{ $istBuffet ? '🍽️ Buffet-Leitplanken (Zusammenstellung)' : '🍽️ Menü-Leitplanken (Zusammenstellung)' }}</p>
                <div class="grid md:grid-cols-4 gap-x-6 gap-y-3">
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">{{ $istBuffet ? 'Anzahl Stationen' : 'Anzahl Gänge (nur Menü)' }}</label>
                        <input type="number" min="1" max="20" step="1" wire:model="regler.{{ $scope }}.menue_gaenge" placeholder="{{ $istBuffet ? 'z. B. 6' : 'z. B. 4' }}" class="{{ $input }} !py-1.5" data-menue-gaenge />
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Preis-Untergrenze p. P.</label>
                        <input type="text" wire:model="regler.{{ $scope }}.menue_preis_min" placeholder="z. B. 35,00" class="{{ $input }} !py-1.5" data-menue-preis-min />
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Zielpreis p. P.</label>
                        <input type="text" wire:model="regler.{{ $scope }}.menue_preis_ziel" placeholder="z. B. 45,00" class="{{ $input }} !py-1.5" data-menue-preis-ziel />
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Preis-Obergrenze p. P.</label>
                        <input type="text" wire:model="regler.{{ $scope }}.menue_preis_max" placeholder="z. B. 60,00" class="{{ $input }} !py-1.5" data-menue-preis-max />
                    </div>
                </div>
                <p class="text-[11px] text-gray-500 mt-1.5">Netto je Person für das gesamte Menü. Leer = keine Vorgabe — die KI wählt Umfang und Preislage passend zum Briefing.</p>

                {{-- Diät-Quoten (Portfolio-ANTEIL) — bewusst getrennt von den harten Diät-Constraints oben:
                     hier steuert der Anteil der Positionen (»mind. X % vegan«), nicht ein Ausschluss für
                     das ganze Menü. Etappe 2a, Teil 2. --}}
                <div class="grid md:grid-cols-2 gap-x-6 gap-y-3 mt-3" data-menue-diaet-quoten>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Vegan-Anteil (%)</label>
                        <input type="number" min="0" max="100" step="1" wire:model="regler.{{ $scope }}.menue_quote_vegan" placeholder="z. B. 30" class="{{ $input }} !py-1.5" data-menue-quote-vegan />
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Vegetarisch-Anteil (%)</label>
                        <input type="number" min="0" max="100" step="1" wire:model="regler.{{ $scope }}.menue_quote_vegetarisch" placeholder="z. B. 50" class="{{ $input }} !py-1.5" data-menue-quote-vegetarisch />
                    </div>
                </div>
                <p class="text-[11px] text-gray-500 mt-1.5">Portfolio-Anteil (weiche Zusammenstellungs-Vorgabe), nicht der harte Ausschluss oben. Leer = keine Quote.</p>

                {{-- Portfolio-Balance (Menü-Vielfalt) — weiche Zusammenstellungs-Vorgabe: wie breit das Menü
                     über Proteine/Warengruppen/Garmethoden streut. Enum, kein Filter. Etappe 2a, Rest Teil 2. --}}
                <div class="mt-3" data-menue-balance>
                    <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Portfolio-Balance (Vielfalt)</label>
                    <select wire:model="regler.{{ $scope }}.menue_balance" class="{{ $input }} !py-1.5 md:max-w-xs" data-menue-balance-select>
                        <option value="">— keine Vorgabe</option>
                        @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::MENUE_BALANCE as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-gray-500 mt-1.5">Wie breit streut das Menü über Proteine, Warengruppen und Garmethoden? „Ausgewogen" = bewusste Vielfalt, Hauptzutaten nicht wiederholen; „Fokussiert" = ein Thema durchziehen. Leer = die KI entscheidet passend zum Briefing.</p>
                </div>
            </div>
        @endif

        @if($vk)
            {{-- VK-eigene Achsen — nur Gericht/Concept --}}
            <div class="md:col-span-2 border-t border-black/5 pt-3 mt-1" data-richtung="vk-achsen">
                <div class="grid md:grid-cols-3 gap-x-6 gap-y-3">
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Anlass</label>
                        <select wire:model="regler.{{ $scope }}.occasion" class="{{ $input }} !py-1.5">
                            <option value="">—</option>
                            @foreach(['fruehstueck' => 'Frühstück', 'lunch' => 'Lunch', 'konferenz' => 'Konferenz', 'empfang' => 'Empfang', 'dinner' => 'Dinner', 'late_night' => 'Late Night'] as $wert => $lbl)
                                <option value="{{ $wert }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Serviceform</label>
                        <select wire:model="regler.{{ $scope }}.serviceform" class="{{ $input }} !py-1.5">
                            <option value="">—</option>
                            @foreach(['tellerservice' => 'Tellerservice', 'buffet' => 'Buffet', 'flying' => 'Flying Service', 'stehempfang' => 'Stehempfang', 'boxed' => 'Boxed'] as $wert => $lbl)
                                <option value="{{ $wert }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Kompositions-Stil</label>
                        <select wire:model="regler.{{ $scope }}.kompositions_stil" class="{{ $input }} !py-1.5">
                            <option value="">—</option>
                            <option value="klassisch">klassisch</option>
                            <option value="kreativ">kreativ</option>
                            <option value="gewagt">gewagt (nur belegte Paarungen)</option>
                        </select>
                    </div>
                    {{-- Ziel-VK ist der Portions-Preis (Gericht). Für ein Concept ist der Menü-Preis-Korridor
                         p. P. oben die EINZIGE Preisquelle (Entscheid 2026-08-18) — hier kein zweiter Preis. --}}
                    @if($scope === 'gericht')
                        <div class="md:col-span-3">
                            <p class="text-xs font-medium text-gray-900 mb-1">Ziel-VK (optional)</p>
                            <input type="text" wire:model="regler.{{ $scope }}.ziel_vk" placeholder="z. B. 8,50" class="{{ $input }} !py-1.5 md:max-w-xs" data-planung-ziel-vk />
                            <p class="text-[11px] text-gray-500 mt-1">Netto je Portion. Geht als Vorgabe in den Vorschlag; der Preis wird nicht auf das Ziel gedrückt.</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-foodalchemist::modal-section>
