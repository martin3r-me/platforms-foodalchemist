{{-- M4-14: ✨ Basisrezept-Generator — Richtungs-Parameter + Bestand-Hybrid --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="generator-modal" title="✨ Basisrezept generieren" size="max-w-2xl">
    @if($fehler !== null)
        <p class="text-sm text-rose-600 dark:text-rose-400 mb-3" data-generator-fehler>{{ $fehler }}</p>
    @endif

    @if($ergebnis === null)
        <x-foodalchemist::modal-section title="Beschreibung">
            <textarea wire:model="beschreibung" rows="3" class="{{ $input }}" data-generator-beschreibung
                      placeholder="z. B. Dunkle Rotwein-Schalotten-Reduktion als Saucenbasis für Schmorgerichte …"></textarea>
            <p class="text-[10px] text-gray-400 mt-1">Aus Foto/PDF: blockiert auf die Vision-Frage bei Martin (Offene Entscheide) — bis dahin Text.</p>
        </x-foodalchemist::modal-section>

        {{-- R5 (Ist-App «Richtung (optional)»): Pill-Gruppen mit Hilfetexten statt Selects --}}
        <x-foodalchemist::modal-section title="Richtung (optional)">
            @php($pillAktiv = 'border-emerald-500 text-emerald-700 dark:text-emerald-300 font-medium')
            @php($pillRuhe = 'border-black/10 dark:border-white/15 text-gray-500 dark:text-gray-400 hover:border-violet-400')
            <div class="grid md:grid-cols-2 gap-x-6 gap-y-4" data-generator-parameter>
                @foreach(\Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal::RICHTUNGEN as $g)
                    <div data-richtung="{{ $g['feld'] }}">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">{{ $g['label'] }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($g['optionen'] as $wert => $lbl)
                                <button type="button" wire:click="togglePill('{{ $g['feld'] }}', '{{ $wert }}')"
                                        class="px-2.5 py-1 rounded-full border text-xs transition-colors {{ $parameter[$g['feld']] === $wert ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">{{ $g['hint'][$parameter[$g['feld']]] ?? '' }}</p>
                    </div>
                @endforeach

                <div data-richtung="aroma">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Aroma-Richtung</p>
                    <input type="text" wire:model="parameter.aroma" placeholder="frei — z. B. rauchig-karamellig, mediterran …" class="{{ $input }} !py-1.5" />
                    <p class="text-[11px] text-gray-400 mt-1">{{ $parameter['aroma'] === '' ? 'Keine Aroma-Vorgabe — KI wählt passend zur Beschreibung' : '' }}</p>
                </div>

                <div data-richtung="sektor">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Sektor (Verpflegungskontext)</p>
                    <select wire:model="parameter.sektor" class="{{ $input }} !py-1.5">
                        <option value="">(egal/universell)</option>
                        <option value="betriebsgastronomie">Betriebsgastronomie</option>
                        <option value="catering">Catering / Event</option>
                        <option value="restaurant">Restaurant / à la carte</option>
                        <option value="care">Care / Klinik</option>
                        <option value="schule_kita">Schule / Kita</option>
                    </select>
                    <p class="text-[11px] text-gray-400 mt-1">{{ $parameter['sektor'] === '' ? 'Kein Sektor-Constraint' : '' }}</p>
                </div>

                <div class="md:col-span-2" data-richtung="diaet">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Diät-Constraints (Multi-Select, hart erzwungen)</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb'] as $wert => $lbl)
                            <button type="button" wire:click="togglePill('diaet_hart', '{{ $wert }}')"
                                    class="px-2.5 py-1 rounded-full border text-xs transition-colors {{ in_array($wert, $parameter['diaet_hart'], true) ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-foodalchemist::modal-section>
    @else
        <x-foodalchemist::modal-section title="Ergebnis">
            <p class="text-sm text-gray-900 dark:text-gray-100 font-medium" data-generator-ergebnis>{{ $ergebnis['name'] }}</p>
            <div class="flex flex-wrap gap-1.5 mt-2">
                <span class="{{ $pill }} {{ $variantPill['success'] }}">{{ $ergebnis['statistik']['bestand_gp'] }} GP aus Bestand</span>
                <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $ergebnis['statistik']['bestand_sub'] }} Sub-Rezepte</span>
                <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ $ergebnis['statistik']['stub_neu'] }} Stubs neu</span>
                <span class="{{ $pill }} {{ $ergebnis['statistik']['offen'] > 0 ? $variantPill['danger'] : $variantPill['secondary'] }}">{{ $ergebnis['statistik']['offen'] }} offen</span>
            </div>
            @if(count($ergebnis['offene']) > 0)
                <div class="mt-3 space-y-1" data-generator-offene>
                    <p class="{{ $label }}">Hard-Stops (Bestand-Lücken ohne Halbfabrikat-Marker):</p>
                    @foreach($ergebnis['offene'] as $offen)
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            🔴 {{ $offen['text'] }} —
                            <span class="text-violet-600 dark:text-violet-400">{{ $offen['primaer'] === 'basisrezept_anlegen' ? 'Basisrezept anlegen' : 'GP anlegen' }}</span>
                            @if(count($offen['shortlist']) > 0)<span class="text-gray-400">· {{ count($offen['shortlist']) }} Shortlist-Kandidaten</span>@endif
                        </p>
                    @endforeach
                </div>
            @endif
        </x-foodalchemist::modal-section>
    @endif

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'generator-modal' })" class="{{ $btnGhost }}">{{ $ergebnis === null ? 'Abbrechen' : 'Schließen' }}</button>
        @if($ergebnis === null)
            <button type="button" wire:click="generieren" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-generator-start>✨ Generieren</button>
        @endif
    </x-slot:footer>
</x-foodalchemist::modal>
