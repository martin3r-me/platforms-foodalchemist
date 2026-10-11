<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Services\Regeln\Arten\Vokabular;
use Platform\FoodAlchemist\Services\Regeln\Arten\Zuordnung;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelText;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;

/**
 * Teller-Rolle einer Gerichtszeile (Dominique 11.10.): Vokabular aus der Regel `gericht.teller_rolle` — die Reihenfolge
 * der Werte ist die Anrichte-Reihenfolge. Pairing-Gewichte aus `pairing.rollen_gewicht`. {@see SpeisenKlassenService::ROLLEN}
 * ist Rückfall, wenn die Regel fehlt oder aus ist.
 */
final class TellerRolle
{
    public const SCHLUESSEL = 'gericht.teller_rolle';

    public const GEWICHT_SCHLUESSEL = 'pairing.rollen_gewicht';

    /** Anzeige-Namen; ein neuer Wert aus der Pflege ohne Eintrag erscheint mit großem Anfangsbuchstaben. */
    private const LABELS = ['basis' => 'Basis', 'protein' => 'Protein', 'beilage' => 'Beilage', 'sauce' => 'Sauce',
        'crunch' => 'Crunch', 'saeure' => 'Säure', 'aroma' => 'Aroma', 'garnitur' => 'Garnitur'];

    /** @return array<string, string> Rolle => Anzeige-Name, in Anrichte-Reihenfolge */
    public static function labels(): array
    {
        $out = [];
        foreach (self::werte() as $w) {
            $out[$w] = self::LABELS[$w] ?? ucfirst(str_replace('_', ' ', $w));
        }

        return $out;
    }

    /** @return list<string> erlaubte Rollen in Anrichte-Reihenfolge */
    public static function werte(): array
    {
        $regel = RegelBuch::falls(self::SCHLUESSEL);
        $werte = $regel !== null ? array_values(array_filter(array_map(static fn ($w) => is_array($w) ? (string) ($w['wert'] ?? '') : '',
            (array) ($regel->params['werte'] ?? [])), 'strlen')) : [];

        return $werte !== [] ? $werte : SpeisenKlassenService::ROLLEN;
    }

    /** Kanonische Rolle (auch über Alias „säure“ → saeure) oder null. */
    public static function kanonisch(mixed $wert): ?string
    {
        if (! is_string($wert) || trim($wert) === '') {
            return null;
        }
        $regel = RegelBuch::falls(self::SCHLUESSEL);
        if ($regel !== null) {
            return app(Vokabular::class)->kanonisch($regel, $wert);
        }
        $n = RegelText::norm($wert);

        return in_array($n, SpeisenKlassenService::ROLLEN, true) ? $n : null;
    }

    /** Anrichte-Rang (1 = zuerst auf den Teller); unbekannt/leer = 99. */
    public static function rang(?string $rolle): int
    {
        $i = $rolle !== null ? array_search($rolle, self::werte(), true) : false;

        return $i === false ? 99 : $i + 1;
    }

    /** Pairing-Gewicht der Rolle; ohne Eintrag 1,0. */
    public static function gewicht(?string $rolle): float
    {
        $regel = RegelBuch::falls(self::GEWICHT_SCHLUESSEL);
        if ($regel === null || $rolle === null || $rolle === '') {
            return 1.0;
        }
        $e = app(Zuordnung::class)->finde($regel, $rolle);

        return $e !== null && is_numeric($e['ziel_name']) ? (float) $e['ziel_name'] : 1.0;
    }
}
