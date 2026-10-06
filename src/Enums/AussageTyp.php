<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 60 · P6: die festen Aussagen der Kombinationslogik. Jede Aussage nennt ihre Grundlage
 * ({@see Grundlage}); fehlt eine, lautet die Aussage „unbekannt" — nie geraten.
 */
enum AussageTyp: string
{
    case Harmoniert = 'harmoniert';      // gemessene Harmonie (Inspire, Stufe 3) trägt
    case Passt = 'passt';                // nur schwache Harmonie (Stufe 2) — Hinweis, zählt nicht
    case Neutral = 'neutral';            // kein nennenswerter aromatischer Bezug (gemessen)
    case Spannung = 'spannung';          // ein Bedarf der einen Seite wird von der anderen gedeckt
    case BedarfOffen = 'bedarf_offen';   // ein Bedarf, den im Gericht niemand deckt
    case Konflikt = 'konflikt';          // „Zerstört" zwischen Kern-Ankern
    case Kombination = 'kombination';    // Klassiker aus dem Dossier („Verträgt")
    case Unbekannt = 'unbekannt';        // Bestandteil ohne Aromenprofil

    public function label(): string
    {
        return match ($this) {
            self::Harmoniert => 'harmonieren',
            self::Passt => 'passen',
            self::Neutral => 'neutral',
            self::Spannung => 'Spannung',
            self::BedarfOffen => 'fehlt',
            self::Konflikt => 'Konflikt',
            self::Kombination => 'Klassiker',
            self::Unbekannt => 'unbekannt',
        };
    }
}
