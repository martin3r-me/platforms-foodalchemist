<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Services\Regeln\Arten\Vokabular;
use Platform\FoodAlchemist\Services\Regeln\Arten\Zuordnung;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;

/**
 * Teller-Rolle einer Gerichtszeile (Dominique 11.10.): Vokabular aus der Regel `gericht.teller_rolle` — die Reihenfolge
 * der Werte ist die Anrichte-Reihenfolge. Pairing-Gewichte aus `pairing.rollen_gewicht`. EINE Quelle (Spec 81 E): fehlt
 * die Regel oder ist sie aus, gibt es keine Rollen (leere Liste, RegelBuch loggt) — keine zweite, fest codierte Liste.
 */
final class TellerRolle
{
    public const SCHLUESSEL = 'gericht.teller_rolle';

    public const GEWICHT_SCHLUESSEL = 'pairing.rollen_gewicht';

    /**
     * @return array<string, string> Rolle => Anzeigename in Anrichte-Reihenfolge — aus der Regel (`werte[].label`, pflegbar),
     *         sonst der Wert mit großem Anfangsbuchstaben. Keine zweite Liste im Code (Spec 81 E).
     */
    public static function labels(): array
    {
        $regel = RegelBuch::falls(self::SCHLUESSEL);
        $out = [];
        foreach ((array) ($regel?->params['werte'] ?? []) as $w) {
            $wert = is_array($w) ? trim((string) ($w['wert'] ?? '')) : '';
            if ($wert !== '') {
                $label = trim((string) ($w['label'] ?? ''));
                $out[$wert] = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $wert));
            }
        }

        return $out;
    }

    /** @return list<string> erlaubte Rollen in Anrichte-Reihenfolge */
    public static function werte(): array
    {
        $regel = RegelBuch::falls(self::SCHLUESSEL);
        $werte = $regel !== null ? array_values(array_filter(array_map(static fn ($w) => is_array($w) ? (string) ($w['wert'] ?? '') : '',
            (array) ($regel->params['werte'] ?? [])), 'strlen')) : [];

        return $werte;
    }

    /** Kanonische Rolle (auch über Alias „säure“ → saeure) oder null. */
    public static function kanonisch(mixed $wert): ?string
    {
        if (! is_string($wert) || trim($wert) === '') {
            return null;
        }
        $regel = RegelBuch::falls(self::SCHLUESSEL);

        return $regel !== null ? app(Vokabular::class)->kanonisch($regel, $wert) : null;
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
