{{-- Leitplanken-Fläche (Richtungs-Regler) JE SCOPE — jeder Tab hat einen eigenen Regler-Satz.
     Erwartet $scope (rezept|gericht|concept). VK-Achsen (Anlass/Serviceform/Kompositions-Stil/Ziel-VK)
     nur wenn $scope != rezept. Alle Bindings gehen auf regler.{scope}.* (unabhängig pro Tab). --}}
@php
    $scope = $scope ?? 'rezept';
    $vk = $scope !== 'rezept';
    $r = $regler[$scope] ?? [];
    $pillAktiv = 'border-emerald-500 text-emerald-700 font-medium';
    $pillRuhe = 'border-black/10 text-gray-600 hover:border-violet-400';
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

        <div data-richtung="aroma">
            <p class="text-xs font-medium text-gray-900 mb-1">Aroma-Richtung</p>
            <input type="text" wire:model="regler.{{ $scope }}.aroma" placeholder="frei — z. B. rauchig-karamellig, mediterran …" class="{{ $input }} !py-1.5" />
            <p class="text-[11px] text-gray-500 mt-1">{{ ($r['aroma'] ?? '') === '' ? 'Keine Aroma-Vorgabe — KI wählt passend zur Beschreibung' : '' }}</p>
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
            <p class="text-[11px] text-gray-500 mt-1">Nach dem Erden läuft die Anreicherung direkt mit — nur in leere Felder, nichts wird überschrieben. Aus = nur das geerdete Gerüst, Anreicherung später bei der Freigabe.</p>
        </div>

        <div data-richtung="ki-bilder">
            <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                <input type="checkbox" wire:model="regler.{{ $scope }}.ki_bilder" class="mt-0.5" data-planung-ki-bilder />
                <span>📷 KI-Fotos bei Anreicherung erstellen</span>
            </label>
            <p class="text-[11px] text-gray-500 mt-1">Bei der Freigabe entstehen Schritt-für-Schritt-Fotos + ein Produktfoto (je Bild ein KI-Call → <b>Kosten</b>). Aus = keine Bilder.</p>
        </div>

        <div class="md:col-span-2" data-richtung="diaet">
            <p class="text-xs font-medium text-gray-900 mb-1">Diät-Constraints (Multi-Select, hart erzwungen)</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach(['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb'] as $wert => $lbl)
                    <button type="button" wire:click="reglerPill('{{ $scope }}', 'diaet_hart', '{{ $wert }}')"
                            class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ in_array($wert, (array) ($r['diaet_hart'] ?? []), true) ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                @endforeach
            </div>
        </div>

        @if($scope === 'concept')
            {{-- Menü-Leitplanken (Zusammenstellung) — nur Concept: steuern das GANZE Menü (Anzahl Gänge +
                 Zielpreis-Korridor je Person), nicht die Rezept-Generierung. Etappe 2a. --}}
            <div class="md:col-span-2 border-t border-black/5 pt-3 mt-1" data-menue-leitplanken>
                <p class="text-xs font-semibold text-gray-900 mb-2">🍽️ Menü-Leitplanken (Zusammenstellung)</p>
                <div class="grid md:grid-cols-4 gap-x-6 gap-y-3">
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Anzahl Gänge / Positionen</label>
                        <input type="number" min="1" max="20" step="1" wire:model="regler.{{ $scope }}.menue_gaenge" placeholder="z. B. 4" class="{{ $input }} !py-1.5" data-menue-gaenge />
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
                    <div class="md:col-span-3">
                        <p class="text-xs font-medium text-gray-900 mb-1">Ziel-VK (optional)</p>
                        <input type="text" wire:model="regler.{{ $scope }}.ziel_vk" placeholder="z. B. 8,50" class="{{ $input }} !py-1.5 md:max-w-xs" data-planung-ziel-vk />
                        <p class="text-[11px] text-gray-500 mt-1">Netto je Portion. Geht als Vorgabe in den Vorschlag; der Preis wird nicht auf das Ziel gedrückt.</p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-foodalchemist::modal-section>
