{{-- M6-06: ✨ VK-Generator v1 — D-5-Achsen + Anlass/Serviceform/Kompositions-Stil, Bestand-Hybrid --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="vk-generator-modal" title="✨ Gericht generieren" size="max-w-2xl">
    @if($fehler !== null)
        <p class="text-xs text-rose-600 mb-3" data-vk-generator-fehler>{{ $fehler }}</p>
    @endif

    @if($ergebnis === null)
        <x-foodalchemist::modal-section title="Beschreibung">
            <textarea wire:model="description" rows="3" class="{{ $input }}" data-vk-generator-description
                      placeholder="z. B. Herbstlicher Hauptgang mit geschmortem Rind, Wurzelgemüse und Kartoffelkomponente für Bankett …"></textarea>
            <p class="text-[10px] text-gray-500 mt-1">Aus Foto/PDF: blockiert auf die Vision-Frage bei Martin — bis dahin Text.</p>
        </x-foodalchemist::modal-section>

        {{-- VK-eigene Achsen (Selects) + Richtungs-Pills (Parität zum Basisrezept-Generator) --}}
        <x-foodalchemist::modal-section title="Richtungs-Parameter">
            @php($pillAktiv = 'border-emerald-500 text-emerald-700 font-medium')
            @php($pillRuhe = 'border-black/10 text-gray-600 hover:border-violet-400')

            {{-- VK-eigene Kontext-Achsen — bleiben Selects (kein Basis-Pendant) --}}
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="block {{ $label }} mb-1">Anlass</label>
                    <select wire:model="parameter.occasion" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['fruehstueck' => 'Frühstück', 'lunch' => 'Lunch', 'konferenz' => 'Konferenz', 'empfang' => 'Empfang', 'dinner' => 'Dinner', 'late_night' => 'Late Night'] as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Serviceform</label>
                    <select wire:model="parameter.serviceform" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['tellerservice' => 'Tellerservice', 'buffet' => 'Buffet', 'flying' => 'Flying Service', 'stehempfang' => 'Stehempfang', 'boxed' => 'Boxed'] as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Kompositions-Stil (Achse 10)</label>
                    <select wire:model="parameter.kompositions_stil" class="{{ $input }}" data-vk-stil>
                        <option value="">—</option>
                        <option value="klassisch">klassisch</option>
                        <option value="kreativ">kreativ</option>
                        <option value="gewagt">gewagt (nur belegte Paarungen)</option>
                    </select>
                </div>
            </div>

            {{-- Richtungs-Pills — identisches Muster wie GeneratorModal::RICHTUNGEN --}}
            <div class="grid md:grid-cols-2 gap-x-6 gap-y-4 mt-4" data-vk-generator-parameter>
                @foreach(\Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal::RICHTUNGEN as $g)
                    <div data-richtung="{{ $g['field'] }}">
                        <p class="text-xs font-medium text-gray-900 mb-1">{{ $g['label'] }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($g['optionen'] as $wert => $lbl)
                                <button type="button" wire:click="togglePill('{{ $g['field'] }}', '{{ $wert }}')"
                                        class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ $parameter[$g['field']] === $wert ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-gray-500 mt-1">{{ $g['hint'][$parameter[$g['field']]] ?? '' }}</p>
                    </div>
                @endforeach

                <div data-richtung="sektor">
                    <p class="text-xs font-medium text-gray-900 mb-1">Sektor (Verpflegungskontext)</p>
                    <select wire:model="parameter.sektor" class="{{ $input }} !py-1.5">
                        <option value="">(egal/universell)</option>
                        <option value="betriebsgastronomie">Betriebsgastronomie</option>
                        <option value="catering">Catering / Event</option>
                        <option value="restaurant">Restaurant / à la carte</option>
                        <option value="care">Care / Klinik</option>
                        <option value="schule_kita">Schule / Kita</option>
                    </select>
                    <p class="text-[11px] text-gray-500 mt-1">{{ $parameter['sektor'] === '' ? 'Kein Sektor-Constraint' : '' }}</p>
                </div>

                <div data-richtung="aroma">
                    <p class="text-xs font-medium text-gray-900 mb-1">Aroma-Richtung</p>
                    <input type="text" wire:model="parameter.aroma" placeholder="frei — z. B. rauchig-karamellig, mediterran …" class="{{ $input }} !py-1.5" />
                    <p class="text-[11px] text-gray-500 mt-1">{{ $parameter['aroma'] === '' ? 'Keine Aroma-Vorgabe — KI wählt passend zur Beschreibung' : '' }}</p>
                </div>

                <div class="md:col-span-2" data-richtung="diaet">
                    <p class="text-xs font-medium text-gray-900 mb-1">Diät-Constraints (Multi-Select, hart erzwungen)</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb'] as $wert => $lbl)
                            <button type="button" wire:click="togglePill('diaet_hart', '{{ $wert }}')"
                                    class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ in_array($wert, $parameter['diaet_hart'], true) ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- 06·H4: opt-in Convenience-Highlight-Modus (Default aus → keine Versteifung) --}}
                <div class="md:col-span-2" data-richtung="convenience-highlights">
                    <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                        <input type="checkbox" wire:model="useConvenienceList" class="mt-0.5" data-vk-convenience />
                        <span>⭐ Auf Basis meiner Convenience-Liste bauen</span>
                    </label>
                    <p class="text-[11px] text-gray-500 mt-1">Bevorzugt die kuratierten Haus-Convenience-Bausteine (bevorzugt, nicht ausschließlich). Aus = freie Kreativität.</p>
                </div>
            </div>
        </x-foodalchemist::modal-section>
    @else
        <x-foodalchemist::modal-section title="Ergebnis">
            <p class="text-xs text-gray-900 font-medium" data-vk-generator-ergebnis>{{ $ergebnis['name'] }}</p>
            <div class="flex flex-wrap gap-1.5 mt-2">
                <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $ergebnis['statistik']['bestand_sub'] }} Basisrezept-Komponenten</span>
                <span class="{{ $pill }} {{ $variantPill['success'] }}">{{ $ergebnis['statistik']['bestand_gp'] }} GP aus Bestand</span>
                <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ $ergebnis['statistik']['stub_neu'] }} Stubs neu</span>
                <span class="{{ $pill }} {{ $ergebnis['statistik']['offen'] > 0 ? $variantPill['danger'] : $variantPill['secondary'] }}">{{ $ergebnis['statistik']['offen'] }} offen</span>
            </div>
            @if(count($ergebnis['offene']) > 0)
                <div class="mt-3 space-y-1" data-vk-generator-offene>
                    <p class="{{ $label }}">Hard-Stops (Bestand-Lücken ohne Halbfabrikat-Marker):</p>
                    @foreach($ergebnis['offene'] as $offen)
                        <p class="text-[11px] text-gray-600">
                            🔴 {{ $offen['text'] }} —
                            <span class="text-violet-600">{{ $offen['primaer'] === 'basisrezept_anlegen' ? 'Basisrezept anlegen' : 'GP anlegen' }}</span>
                            @if(count($offen['shortlist']) > 0)<span class="text-gray-500">· {{ count($offen['shortlist']) }} Shortlist-Kandidaten</span>@endif
                        </p>
                    @endforeach
                </div>
            @endif
            <p class="text-[10px] text-gray-500 mt-2">VK-Daten (Klasse/Aufschlagsklasse) aus dem Vorschlag übernommen, soweit valide — Rest im VK-Editor pflegen.</p>
        </x-foodalchemist::modal-section>
    @endif

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'vk-generator-modal' })" class="{{ $btnGhost }}">{{ $ergebnis === null ? 'Abbrechen' : 'Schließen' }}</button>
        @if($ergebnis === null)
            <button type="button" wire:click="generieren" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-vk-generator-start>✨ Generieren</button>
        @endif
    </x-slot:footer>
</x-foodalchemist::modal>
