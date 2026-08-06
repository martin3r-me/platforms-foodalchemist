{{--
    Spec 03 L7b: der One-Shot-Toggle „⚡ Voll anreichern" — eine Fläche für BEIDE
    Generator-Modals (Basisrezept + Gericht). Recipe-first (2026-08-06): Default AUS —
    zuerst die Rezept-Basis, die Anreicherung ist ein bewusster Schritt nach dem Review.
    Toggle AN = „Beschreibung rein, fertiges Rezept raus" in einem Durchlauf (opt-in).

    Der Hinweistext nennt die Schrittfolge der jeweiligen Ebene, weil sie sich
    unterscheidet (Basis: Beschreibung/Kategorie/Geschmack · Gericht: Beschreibung/
    Wording/Plating/Speisen-Klasse) — die Mechanik dahinter ist dieselbe.
--}}
@props([
    'schritte' => 'Beschreibung, Kategorie, Geschmacksrichtung',
    'marker' => 'oneshot',
])

<div data-richtung="oneshot">
    <label class="flex items-start gap-2 text-xs font-medium text-gray-900">
        <input type="checkbox" wire:model="vollAnreichern" class="mt-0.5" data-{{ $marker }}-toggle />
        <span>⚡ Voll anreichern</span>
    </label>
    <p class="text-[11px] text-gray-500 mt-1">
        Nach dem Erden läuft die Anreicherung direkt mit ({{ $schritte }}) — nur in leere Felder,
        nichts wird überschrieben. Aus = nur das geerdete Gerüst, Anreicherung später per Sammel-Klick.
    </p>
</div>
