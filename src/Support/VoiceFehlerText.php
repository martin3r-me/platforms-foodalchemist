<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Http\Client\ConnectionException;
use Platform\FoodAlchemist\Exceptions\KiDeaktiviertException;

/**
 * Spec 53 / Paket D: eine STT-/Sprachbefehl-Ausnahme in einen Satz übersetzen, den ein Nutzer
 * versteht — statt der rohen Exception-Message (z. B. das API-Antwort-Fragment
 * „model_not_found"). Das technische Original bleibt in `detail` erhalten, damit es in einem
 * `<details>`-Aufklapper nicht verloren geht (Support/Diagnose), aber nicht die erste Zeile ist.
 *
 * Reihenfolge der Prüfung ist absichtlich: spezifischer HTTP-Status vor genereller Text-Suche,
 * sonst würde z. B. eine 400-Antwort mit dem Wort „timeout" im Fließtext falsch einsortiert.
 */
class VoiceFehlerText
{
    /** @return array{text: string, detail: ?string} */
    public static function aus(\Throwable $e): array
    {
        $meldung = $e->getMessage();
        $code = (int) $e->getCode();

        if ($e instanceof KiDeaktiviertException) {
            return ['text' => $meldung, 'detail' => null];
        }

        if ($e instanceof ConnectionException || self::enthaelt($meldung, ['timeout', 'timed out'])) {
            return [
                'text' => 'Spracherkennung hat nicht rechtzeitig geantwortet — kürzer sprechen und erneut versuchen.',
                'detail' => $meldung,
            ];
        }

        if (in_array($code, [401, 403], true)) {
            return ['text' => 'Zugang ungültig — API-Schlüssel prüfen.', 'detail' => $meldung];
        }

        if ($code === 429) {
            return ['text' => 'Zu viele Anfragen — kurz warten und erneut versuchen.', 'detail' => $meldung];
        }

        if ($code === 400 && self::enthaelt($meldung, ['format'])) {
            return ['text' => 'Audioformat nicht erkannt (Browser prüfen) — bitte erneut versuchen.', 'detail' => $meldung];
        }

        if (self::enthaelt($meldung, ['nicht konfiguriert'])) {
            return ['text' => $meldung, 'detail' => null];               // schon der richtige Satz, nichts zu übersetzen
        }

        if (self::enthaelt($meldung, ['leer'])) {
            return ['text' => 'Aufnahme war leer.', 'detail' => null];
        }

        return ['text' => 'Unerwarteter Fehler bei der Spracherkennung.', 'detail' => $meldung];
    }

    /** @param  list<string>  $begriffe */
    private static function enthaelt(string $text, array $begriffe): bool
    {
        $norm = mb_strtolower($text);
        foreach ($begriffe as $b) {
            if (str_contains($norm, $b)) {
                return true;
            }
        }

        return false;
    }
}
