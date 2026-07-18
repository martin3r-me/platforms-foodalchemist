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

        <x-foodalchemist::modal-section title="Richtungs-Parameter">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3" data-vk-generator-parameter>
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
                <div>
                    <label class="block {{ $label }} mb-1">Convenience</label>
                    <select wire:model="parameter.convenience" class="{{ $input }}">
                        <option value="from_scratch">from scratch</option>
                        <option value="teil_convenience">Teil-Convenience</option>
                        <option value="standard">Standard</option>
                        <option value="voll_convenience">Voll-Convenience</option>
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Frische-Hook</label>
                    <select wire:model="parameter.frische" class="{{ $input }}">
                        <option value="frisch">frisch</option><option value="tk">alles aus TK</option><option value="konserve">Konserve/haltbar</option>
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Diät (hart)</label>
                    <select wire:model="parameter.diaet_hart" class="{{ $input }}">
                        <option value="">—</option>
                        <option value="vegan">vegan</option><option value="vegetarisch">vegetarisch</option>
                        <option value="glutenfrei">glutenfrei</option><option value="laktosefrei">laktosefrei</option><option value="halal">halal</option>
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Niveau</label>
                    <input type="text" wire:model="parameter.level" placeholder="z. B. fine_dining" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Sektor</label>
                    <input type="text" wire:model="parameter.sektor" placeholder="z. B. catering" class="{{ $input }}" />
                </div>
                <div class="flex items-end pb-2 gap-3">
                    <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                        <input type="checkbox" wire:model="parameter.bio" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" /> Bio
                    </label>
                </div>
                <div class="col-span-2 md:col-span-3">
                    <label class="block {{ $label }} mb-1">Aroma-Richtung</label>
                    <input type="text" wire:model="parameter.aroma" placeholder="z. B. rauchig-karamellig, mediterran …" class="{{ $input }}" />
                </div>
                {{-- 06·H4: opt-in Convenience-Highlight-Modus (Default aus → keine Versteifung) --}}
                <div class="col-span-2 md:col-span-3">
                    <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                        <input type="checkbox" wire:model="useConvenienceList" class="mt-0.5 rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-vk-convenience />
                        <span>⭐ Auf Basis meiner Convenience-Liste bauen</span>
                    </label>
                    <p class="text-[11px] text-gray-500 mt-1">Bevorzugt die kuratierten Haus-Convenience-Bausteine (bevorzugt, nicht ausschließlich).</p>
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
