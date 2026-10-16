<?php

namespace Platform\FoodAlchemist\Support;

/**
 * KI-Klassen STARK / MITTEL / LEICHT (16.10.2026). Jeder Prompt-Key der Registry (`foodalchemist.prompts`) trägt genau EIN Feld `klasse`;
 * diese Klasse bestimmt über `foodalchemist.ai.klassen.<klasse>` Modell und Reasoning-Stufe. Es gibt keinen zweiten Pfad (die Stufen A–D und der
 * Sonderschalter für Non-Food sind abgelöst). Leer konfiguriert = Plattform-Default-Modell, also das bisherige Verhalten.
 */
final class KiKlassen
{
    public const STARK = 'stark';

    public const MITTEL = 'mittel';

    public const LEICHT = 'leicht';

    /** @var list<string> */
    public const ALLE = [self::STARK, self::MITTEL, self::LEICHT];

    /** Code in `ai_call_log.tier` (String(2)): S | M | L. Ältere Zeilen tragen noch A–D. */
    private const LOG_CODE = [self::STARK => 'S', self::MITTEL => 'M', self::LEICHT => 'L'];

    private const LABEL = [self::STARK => 'Stark', self::MITTEL => 'Mittel', self::LEICHT => 'Leicht'];

    private const BESCHREIBUNG = [
        self::STARK => 'Sicherheit und Fakten (Allergene, Nährwerte, Produktpass, Rezept einlesen, Regelwerk) und alles Erzeugende oder Planende (Gerichte, Planung, Foodbook, Konzept, Texte, Prüfen, Chat).',
        self::MITTEL => 'Fachliche Schätzungen, die in Mengen und Kosten einfließen (Verluste, Garverlust, Stückgewicht, Verpackungsmaße, Regenerieren, Posten, Zeiten).',
        self::LEICHT => 'Einzelwerte aus kurzen Listen (Kategorie, Zustand, Sektor, Niveau, Speisenklasse, Anker, Signale, Titel- und Namensvorschläge, Schlagworte, Non-Food).',
    ];

    public static function gueltig(mixed $klasse): bool
    {
        return is_string($klasse) && in_array($klasse, self::ALLE, true);
    }

    /** Klasse eines Prompt-Keys; fehlt/ungültig → STARK (der sichere Weg: nie still ein schwaches Modell). */
    public static function von(?array $prompt): string
    {
        $k = $prompt['klasse'] ?? null;

        return self::gueltig($k) ? $k : self::STARK;
    }

    /** Modell der Klasse oder null (= Plattform-Default). */
    public static function modell(string $klasse): ?string
    {
        $m = trim((string) (config('foodalchemist.ai.klassen', [])[$klasse]['model'] ?? ''));

        return $m !== '' ? $m : null;
    }

    /** Reasoning-Stufe der Klasse oder null (= Modell-Standard). */
    public static function reasoning(string $klasse): ?string
    {
        $r = trim((string) (config('foodalchemist.ai.klassen', [])[$klasse]['reasoning'] ?? ''));

        return $r !== '' ? $r : null;
    }

    public static function logCode(string $klasse): string
    {
        return self::LOG_CODE[$klasse] ?? 'S';
    }

    public static function label(string $klasse): string
    {
        return self::LABEL[$klasse] ?? $klasse;
    }

    public static function beschreibung(string $klasse): string
    {
        return self::BESCHREIBUNG[$klasse] ?? '';
    }

    /** Anzeige-Name zu einem Log-Code: S/M/L, D = Sprachbefehl, I = Bild, A–C = Zeilen aus der Zeit der Stufen: für die Nutzungstabelle. */
    public static function logLabel(?string $code): ?string
    {
        $klasse = array_search($code, self::LOG_CODE, true);

        return match (true) {
            $klasse !== false => self::LABEL[$klasse],
            $code === 'D' => 'Sprachbefehl',
            $code === 'I' => 'Bild',
            $code !== null && $code !== '' => 'Alt '.$code,
            default => null,
        };
    }

    /** @return list<string> die abgelösten Stufen-Variablen A–D, die auf diesem Server noch gesetzt sind (wirken nicht mehr). */
    public static function abgeloesteStufenGesetzt(): array
    {
        $gesetzt = [];
        foreach ((array) config('foodalchemist.ai.tiers_abgeloest', []) as $stufe => $modell) {
            if (is_string($modell) && trim($modell) !== '') {
                $gesetzt[] = "FOODALCHEMIST_AI_TIER_{$stufe}";
            }
        }

        return $gesetzt;
    }
}
