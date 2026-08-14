{{-- Geteilte Leitplanken-Fläche (Richtungs-Regler) — von Basisrezept- + Gericht-Tab genutzt.
     $vk (default false) blendet die VK-eigenen Achsen ein (Anlass/Serviceform/Kompositions-Stil/Ziel-VK).
     Nutzt den geteilten $regler-Zustand (bewusst tab-übergreifend); Include teilt den Parent-Scope. --}}
@php $vk = $vk ?? false; @endphp
<x-foodalchemist::modal-section title="Richtung (optional)">
    @php
        $pillAktiv = 'border-emerald-500 text-emerald-700 font-medium';
        $pillRuhe = 'border-black/10 text-gray-600 hover:border-violet-400';
    @endphp
    <div class="grid md:grid-cols-2 gap-x-6 gap-y-4" data-planung-regler>
        @foreach(\Platform\FoodAlchemist\Livewire\Planung\Index::RICHTUNGEN as $g)
            <div data-richtung="{{ $g['field'] }}">
                <p class="text-xs font-medium text-gray-900 mb-1">{{ $g['label'] }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($g['optionen'] as $wert => $lbl)
                        <button type="button" wire:click="reglerPill('{{ $g['field'] }}', '{{ $wert }}')"
                                class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ $regler[$g['field']] === $wert ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                    @endforeach
                </div>
                <p class="text-[11px] text-gray-500 mt-1">{{ $g['hint'][$regler[$g['field']]] ?? '' }}</p>
            </div>
        @endforeach

        <div data-richtung="aroma">
            <p class="text-xs font-medium text-gray-900 mb-1">Aroma-Richtung</p>
            <input type="text" wire:model="regler.aroma" placeholder="frei — z. B. rauchig-karamellig, mediterran …" class="{{ $input }} !py-1.5" />
            <p class="text-[11px] text-gray-500 mt-1">{{ $regler['aroma'] === '' ? 'Keine Aroma-Vorgabe — KI wählt passend zur Beschreibung' : '' }}</p>
        </div>

        <div data-richtung="sektor">
            <p class="text-xs font-medium text-gray-900 mb-1">Sektor (Verpflegungskontext)</p>
            <select wire:model="regler.sektor" class="{{ $input }} !py-1.5">
                <option value="">(egal/universell)</option>
                <option value="betriebsgastronomie">Betriebsgastronomie</option>
                <option value="catering">Catering / Event</option>
                <option value="restaurant">Restaurant / à la carte</option>
                <option value="care">Care / Klinik</option>
                <option value="schule_kita">Schule / Kita</option>
            </select>
            <p class="text-[11px] text-gray-500 mt-1">{{ $regler['sektor'] === '' ? 'Kein Sektor-Constraint' : '' }}</p>
        </div>

        <div data-richtung="favoriten">
            <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                <input type="checkbox" wire:model.live="reglerFavoriten" class="mt-0.5" data-planung-favoriten />
                <span>⭐ Auf Basis meiner Favoriten bauen</span>
            </label>
            <p class="text-[11px] text-gray-500 mt-1">Bevorzugt die kuratierten Lieblings-GPs (bevorzugt, nicht ausschließlich). Aus = freie Kreativität.</p>
            <label x-show="$wire.reglerFavoriten" class="flex items-center gap-1.5 text-[11px] text-gray-600 mt-1.5 ml-6">
                <input type="checkbox" wire:model="reglerFavoritenConvenienceOnly" /> nur Convenience-Favoriten
            </label>
        </div>

        <x-foodalchemist::oneshot-toggle marker="planung" schritte="Beschreibung, Kategorie, Geschmacksrichtung" />

        <div data-richtung="ki-bilder">
            <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
                <input type="checkbox" wire:model="reglerKiBilder" class="mt-0.5" data-planung-ki-bilder />
                <span>📷 KI-Fotos bei Anreicherung erstellen</span>
            </label>
            <p class="text-[11px] text-gray-500 mt-1">Bei der Freigabe entstehen Schritt-für-Schritt-Fotos + ein Produktfoto (je Bild ein KI-Call → <b>Kosten</b>). Aus = keine Bilder.</p>
        </div>

        <div class="md:col-span-2" data-richtung="diaet">
            <p class="text-xs font-medium text-gray-900 mb-1">Diät-Constraints (Multi-Select, hart erzwungen)</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach(['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal', 'low_carb' => 'Low Carb'] as $wert => $lbl)
                    <button type="button" wire:click="reglerPill('diaet_hart', '{{ $wert }}')"
                            class="px-2.5 py-1 rounded-full border text-[11px] transition-colors {{ in_array($wert, $regler['diaet_hart'], true) ? $pillAktiv : $pillRuhe }}">{{ $lbl }}</button>
                @endforeach
            </div>
        </div>

        @if($vk)
            {{-- VK-eigene Achsen — nur im Gericht-Tab --}}
            <div class="md:col-span-2 border-t border-black/5 pt-3 mt-1" data-richtung="vk-achsen">
                <div class="grid md:grid-cols-3 gap-x-6 gap-y-3">
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Anlass</label>
                        <select wire:model="regler.occasion" class="{{ $input }} !py-1.5">
                            <option value="">—</option>
                            @foreach(['fruehstueck' => 'Frühstück', 'lunch' => 'Lunch', 'konferenz' => 'Konferenz', 'empfang' => 'Empfang', 'dinner' => 'Dinner', 'late_night' => 'Late Night'] as $wert => $lbl)
                                <option value="{{ $wert }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Serviceform</label>
                        <select wire:model="regler.serviceform" class="{{ $input }} !py-1.5">
                            <option value="">—</option>
                            @foreach(['tellerservice' => 'Tellerservice', 'buffet' => 'Buffet', 'flying' => 'Flying Service', 'stehempfang' => 'Stehempfang', 'boxed' => 'Boxed'] as $wert => $lbl)
                                <option value="{{ $wert }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block {{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Kompositions-Stil</label>
                        <select wire:model="regler.kompositions_stil" class="{{ $input }} !py-1.5">
                            <option value="">—</option>
                            <option value="klassisch">klassisch</option>
                            <option value="kreativ">kreativ</option>
                            <option value="gewagt">gewagt (nur belegte Paarungen)</option>
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <p class="text-xs font-medium text-gray-900 mb-1">Ziel-VK (optional)</p>
                        <input type="text" wire:model="reglerZielVk" placeholder="z. B. 8,50" class="{{ $input }} !py-1.5 md:max-w-xs" data-planung-ziel-vk />
                        <p class="text-[11px] text-gray-500 mt-1">Netto je Portion. Geht als Vorgabe in den Vorschlag; der Preis wird nicht auf das Ziel gedrückt.</p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-foodalchemist::modal-section>
