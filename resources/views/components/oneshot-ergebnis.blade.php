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
    </div>
@endif
