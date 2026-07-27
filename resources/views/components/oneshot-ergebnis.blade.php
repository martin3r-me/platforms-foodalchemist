{{--
    Spec 03 L7b: die Ergebnis-Zeile des One-Shot-Passes — eine Fläche für BEIDE
    Generator-Modals. Zeigt, was die Kaskade wirklich getan hat, statt „fertig".

    Drei Fälle, alle drei sichtbar:
      · übernommen  — Felder, die der Pass gefüllt hat
      · übersprungen — Schritte, deren Ziel-Feld schon belegt war (kein Call bezahlt,
                       nichts überschrieben; das ist die GL-07-Grenze, keine Panne)
      · offen/fehler — der Pass ist an einem Schritt gescheitert. Das Rezept steht
                       trotzdem vollständig da; der Rest ist eine Lücke, die in der
                       Review-Queue liegt — darum als Hinweis, nicht als Fehler.

    L7b-2: dazu das Kohärenz-Glied (nur VK, nur ab zwei Komponenten). Es steht
    NEBEN dem Aroma-Score aus der Statistik-Pille, nie verrechnet mit ihm —
    GL-10 §1: zwei Achsen, zwei Anzeigen. Fehlt das Urteil, wird das ehrlich
    gesagt statt weggelassen (sonst liest sich ein stiller Provider-Ausfall wie
    „nicht vorgesehen").

    L8b: und zuletzt das Wirtschaftlichkeits-Glied (L8a). Es ist die einzige
    Zeile hier, in der eine LÜCKE genauso wichtig ist wie ein Ergebnis: ohne
    Portionsgröße oder Aufschlagsklasse gibt es keinen VK, und das muss am
    Erzeugnis stehen statt als stiller Null-Preis im Editor aufzuschlagen. Der
    Wareneinsatz trägt dieselbe Ampel wie das Signal-Cockpit (eine Schwelle,
    eine Leiter — L8a Entscheidung 4), und „vorläufig" sagt, dass unbepreiste
    Park-GPs im EK stecken (#511-F2).
--}}
@props(['anreicherung'])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if($anreicherung !== null)
    <div class="mt-3 pt-3 border-t border-black/5" data-oneshot-ergebnis>
        <p class="{{ $label }}">⚡ Anreicherung</p>
        <div class="flex flex-wrap gap-1.5 mt-1.5">
            <span class="{{ $pill }} {{ ($anreicherung['uebernommen'] ?? 0) > 0 ? $variantPill['success'] : $variantPill['secondary'] }}">{{ $anreicherung['uebernommen'] ?? 0 }} Felder gefüllt</span>
            @if(count($anreicherung['uebersprungen'] ?? []) > 0)
                <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ count($anreicherung['uebersprungen']) }} schon belegt</span>
            @endif
            @if(($anreicherung['offen'] ?? 0) > 0)
                <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ $anreicherung['offen'] }} offen in der Review-Liste</span>
            @endif
        </div>
        @if(($anreicherung['fehler'] ?? null) !== null)
            <p class="text-[11px] text-amber-700 mt-1.5" data-oneshot-fehler>
                ⚠️ Anreicherung unvollständig: {{ $anreicherung['fehler'] }} — das Rezept selbst ist fertig und geerdet, die restlichen Felder bleiben offen.
            </p>
        @elseif(($anreicherung['uebernommen'] ?? 0) === 0 && count($anreicherung['schritte'] ?? []) === 0)
            <p class="text-[11px] text-gray-500 mt-1.5">Alle Ziel-Felder waren schon belegt — kein zusätzlicher KI-Call nötig.</p>
        @endif

        @if(($anreicherung['kohaerenz_urteil'] ?? null) !== null)
            @php($koh = $anreicherung['kohaerenz_urteil'])
            <div class="mt-2" data-oneshot-kohaerenz>
                @if($koh['score'] !== null)
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $pill }} {{ $koh['score'] >= 70 ? $variantPill['success'] : ($koh['score'] >= 50 ? $variantPill['warning'] : $variantPill['danger']) }}">🍽️ Kohärenz {{ $koh['score'] }} / 100</span>
                        @if($koh['label'] !== null)<span class="text-[11px] text-gray-600">{{ $koh['label'] }}</span>@endif
                    </div>
                    @if($koh['schwachstelle'] !== null)
                        <p class="text-[11px] text-gray-600 mt-0.5">Schwachstelle: {{ $koh['schwachstelle'] }}</p>
                    @endif
                @else
                    <p class="text-[11px] text-amber-700" data-oneshot-kohaerenz-fehler>
                        ⚠️ Kohärenz-Urteil offen: {{ $koh['fehler'] }} — im VK-Detail über «Kohärenz prüfen» nachholen.
                    </p>
                @endif
            </div>
        @endif

        @if(($anreicherung['wirtschaftlichkeit'] ?? null) !== null)
            @php($w = $anreicherung['wirtschaftlichkeit'])
            @php($lueckenText = ['portion' => 'Portionsgröße', 'aufschlagsklasse' => 'Aufschlagsklasse', 'darreichung' => 'Standard-Darreichung'])
            <div class="mt-2" data-oneshot-wirtschaftlichkeit>
                @if(($w['fehler'] ?? null) !== null)
                    <p class="text-[11px] text-amber-700" data-oneshot-wirtschaft-fehler>
                        ⚠️ Kalkulation offen: {{ $w['fehler'] }} — das Gericht steht, der Preis wird im VK-Editor nachgezogen.
                    </p>
                @else
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if(($w['sales_net'] ?? null) !== null)
                            <span class="{{ $pill }} {{ $variantPill['success'] }}" data-oneshot-vk>💰 VK {{ number_format((float) $w['sales_net'], 2, ',', '.') }} €</span>
                        @endif
                        @if(($w['wareneinsatz_pct'] ?? null) !== null)
                            {{-- Dieselbe Leiter wie das Signal (L8a Entscheidung 4): über Ziel = gelb, über 1,5 × Ziel = rot --}}
                            <span class="{{ $pill }} {{ ['gruen' => $variantPill['success'], 'gelb' => $variantPill['warning'], 'rot' => $variantPill['danger']][$w['ampel'] ?? ''] ?? $variantPill['secondary'] }}" data-oneshot-we>
                                W {{ number_format((float) $w['wareneinsatz_pct'], 1, ',', '.') }} % / Ziel {{ number_format((float) ($w['ziel_pct'] ?? 0), 0, ',', '.') }} %
                            </span>
                        @endif
                        @if(($w['portion_g'] ?? null) !== null)
                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ number_format((float) $w['portion_g'], 0, ',', '.') }} g / Portion</span>
                        @endif
                        @if($w['vorlaeufig'] ?? false)
                            <span class="{{ $pill }} {{ $variantPill['warning'] }}" data-oneshot-vorlaeufig>vorläufig</span>
                        @endif
                    </div>
                    @if($w['vorlaeufig'] ?? false)
                        <p class="text-[11px] text-amber-700 mt-0.5">Unbepreiste Zutaten im EK — der VK ist vorläufig, bis die Lead-LAs stehen.</p>
                    @endif
                    @if(count($w['luecken'] ?? []) > 0)
                        <p class="text-[11px] text-amber-700 mt-0.5" data-oneshot-wirtschaft-luecken>
                            ⚠️ Kein Auto-VK: {{ implode(' + ', array_map(fn ($l) => $lueckenText[$l] ?? $l, $w['luecken'])) }} fehlt — im VK-Editor setzen, der Preis rechnet sich dann selbst.
                        </p>
                    @endif
                    @if($w['signal'] ?? false)
                        <p class="text-[11px] text-amber-700 mt-0.5" data-oneshot-wirtschaft-signal>
                            Wareneinsatz über Ziel — als Signal im Cockpit vermerkt.
                        </p>
                    @endif
                @endif
            </div>
        @endif
    </div>
@endif
