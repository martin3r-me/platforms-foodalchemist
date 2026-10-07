{{--
    Spec 03 L7b: der One-Shot-Schalter „Voll anreichern" — eine Fläche für BEIDE
    Generator-Modals (Basisrezept + Gericht). Recipe-first (2026-08-06): Standard AUS —
    zuerst die Rezept-Basis, die Anreicherung ist ein bewusster Schritt nach der Prüfung.
    AN = „Beschreibung rein, fertiges Rezept raus" in einem Durchlauf.

    Der Hinweistext nennt die Schrittfolge der jeweiligen Ebene, weil sie sich
    unterscheidet (Basis: Beschreibung/Kategorie/Geschmack · Gericht: Beschreibung/
    Wording/Plating/Speisen-Klasse) — die Mechanik dahinter ist dieselbe.
    fa-pass (2026-10-05): Tokens, Heroicon statt Emoji; Prop `marker` + `vollAnreichern` unverändert.
--}}
@props([
    'schritte' => 'Beschreibung, Kategorie, Geschmacksrichtung',
    'marker' => 'oneshot',
])

<div class="flex flex-col gap-1.5 min-w-0" data-richtung="oneshot">
    <label class="flex items-start gap-2 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] cursor-pointer">
        <input type="checkbox" wire:model="vollAnreichern" class="mt-0.5 accent-[var(--fa-accent)]" data-{{ $marker }}-toggle />
        <span class="inline-flex items-center gap-1.5">@svg('heroicon-o-sparkles', 'w-4 h-4 text-[var(--fa-accent)]') Voll anreichern</span>
    </label>
    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
        Direkt im Anschluss werden {{ $schritte }} ergänzt, nur in leere Felder, nichts wird überschrieben.
        Aus heißt: nur das Grundgerüst, anreichern später per Sammel-Klick.
    </p>
</div>
