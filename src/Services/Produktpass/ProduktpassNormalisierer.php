<?php

namespace Platform\FoodAlchemist\Services\Produktpass;

use Platform\FoodAlchemist\Support\ProduktpassFelder;

/**
 * Macht aus der Rohantwort einer {@see ProduktdatenQuelle} einen belastbaren Extrakt. Streng: was nicht passt, fliegt raus
 * (unbekannter Schlüssel, falscher Typ, Zahl < 0, Wert ohne Inhalt) — fehlt etwas, bleibt es leer, nie ein Standardwert.
 * Quelle `websuche`: jedes Feld und jedes Allergen braucht eine URL, sonst wird es verworfen.
 */
final class ProduktpassNormalisierer
{
    private const ALLERGEN_WERTE = ['enthalten', 'spuren', 'nicht_enthalten'];

    /** @return array{quelle:string,felder:array<string,array<string,mixed>>,allergene:array<string,array<string,mixed>>,allergene_details:array<string,array<string,mixed>>,verworfen:list<string>} */
    public static function normalisiere(array $roh, string $quelle): array
    {
        $web = $quelle === ProduktpassFelder::QUELLE_WEB;
        $aus = ['quelle' => $quelle, 'felder' => [], 'allergene' => [], 'allergene_details' => [], 'verworfen' => []];

        foreach ((array) ($roh['felder'] ?? []) as $key => $eintrag) {
            $ziel = is_string($key) && is_array($eintrag) ? ProduktpassFelder::ziel($key) : null;
            if ($ziel === null) {
                $aus['verworfen'][] = 'unbekanntes Feld: '.(is_string($key) ? $key : '?');

                continue;
            }
            $wert = self::wert($ziel['typ'], $eintrag['wert'] ?? null);
            $url = self::url($eintrag['url'] ?? null);
            if ($wert === null || ($web && $url === null)) {
                $aus['verworfen'][] = "{$key}: ".($wert === null ? 'kein gültiger Wert' : 'ohne Quellen-URL');

                continue;
            }
            $aus['felder'][$key] = array_filter([
                'wert' => $wert,
                'konfidenz' => self::konfidenz($eintrag['konfidenz'] ?? null),
                'einheit' => self::text($eintrag['einheit'] ?? null, 32),
                'zitat' => self::text($eintrag['zitat'] ?? null, 300),
                'url' => $url,
            ], fn ($v) => $v !== null);
        }

        $erlaubt = ProduktpassFelder::allergene();
        foreach ((array) ($roh['allergene'] ?? []) as $key => $eintrag) {
            $wert = is_array($eintrag) && in_array($eintrag['wert'] ?? null, self::ALLERGEN_WERTE, true) ? $eintrag['wert'] : null;
            $url = is_array($eintrag) ? self::url($eintrag['url'] ?? null) : null;
            if (! in_array($key, $erlaubt, true) || $wert === null || ($web && $url === null)) {
                $aus['verworfen'][] = 'Allergen '.(is_string($key) ? $key : '?').': ungültig';

                continue;
            }
            $aus['allergene'][$key] = array_filter(['wert' => $wert, 'konfidenz' => self::konfidenz($eintrag['konfidenz'] ?? null),
                'zitat' => self::text($eintrag['zitat'] ?? null, 300), 'url' => $url], fn ($v) => $v !== null);
        }
        foreach ((array) ($roh['allergene_details'] ?? []) as $key => $eintrag) {
            $wert = is_array($eintrag) && in_array($eintrag['wert'] ?? null, self::ALLERGEN_WERTE, true) ? $eintrag['wert'] : null;
            $url = is_array($eintrag) ? self::url($eintrag['url'] ?? null) : null;
            if (! is_string($key) || ! preg_match('/^[a-z][a-z_]{1,30}$/', $key) || $wert === null || ($web && $url === null)) {
                $aus['verworfen'][] = 'Unterart '.(is_string($key) ? $key : '?').': ungültig';

                continue;
            }
            $aus['allergene_details'][$key] = array_filter(['wert' => $wert, 'konfidenz' => self::konfidenz($eintrag['konfidenz'] ?? null), 'url' => $url], fn ($v) => $v !== null);
        }

        return $aus;
    }

    private static function wert(string $typ, mixed $roh): mixed
    {
        switch ($typ) {
            case 'zahl':
                $n = is_string($roh) ? str_replace(',', '.', trim($roh)) : $roh;

                return is_numeric($n) && (float) $n >= 0 ? (float) $n : null;
            case 'tage':
                $n = is_string($roh) ? trim($roh) : $roh;

                return is_numeric($n) && (int) $n >= 1 && (int) $n <= 3650 ? (int) $n : null;
            case 'jn':
                return in_array($roh, ['ja', 'nein'], true) ? $roh : null;
            default:
                return self::text($roh, 4000);
        }
    }

    private static function text(mixed $roh, int $max): ?string
    {
        if (! is_string($roh) && ! is_numeric($roh)) {
            return null;
        }
        $s = trim((string) $roh);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private static function url(mixed $roh): ?string
    {
        $s = is_string($roh) ? trim($roh) : '';

        return preg_match('~^https?://[^\s]+$~i', $s) ? mb_substr($s, 0, 500) : null;
    }

    /** Fehlende oder ungültige Konfidenz = 0 (nie „sicher“ unterstellen): bleibt damit Vorschlag. */
    private static function konfidenz(mixed $roh): float
    {
        return is_numeric($roh) ? round(max(0.0, min(1.0, (float) $roh)), 3) : 0.0;
    }
}
