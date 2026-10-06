<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;

/**
 * Spec 60 · Schritt 1: Dossier ↔ Aroma-Anker als echte Verknüpfung.
 *
 * Zutaten-Dossiers tragen ihren Anker im Kopf (`anker_id: 1011`, `anker_slug: acai_berry`).
 * Bisher war das nur Text: das Import-Tool verwarf `anker_slug` ausdrücklich, und das Signal
 * „Kombination fehlt" löste den Anker per Namensraten aus dem Titel auf.
 *
 * Verknüpft wird nur, wenn ID UND Slug zum Anker-Vokabular passen. Ein Kopf mit ID ohne
 * passenden Slug (umbenannter Anker, Tippfehler) bleibt unverknüpft statt falsch verknüpft.
 * Alle Schreibwege (POST, PUT, IMPORT, knowledge-import) rufen {@see ausInhalt} auf.
 */
final class DossierAnker
{
    /** Anker-ID aus dem Frontmatter, sofern ID und Slug zum Vokabular passen; sonst null. */
    public static function ausInhalt(?string $inhalt): ?int
    {
        [$id, $slug] = self::kopf((string) $inhalt);
        if ($id === null || $slug === null) {
            return null;
        }
        $treffer = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)->value('slug');

        return $treffer !== null && $treffer === $slug ? $id : null;
    }

    /**
     * Liest `anker_id` und `anker_slug` aus dem führenden Frontmatter-Block (--- … ---).
     * Ein `anker_id:` im Fließtext zählt nicht.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function kopf(string $inhalt): array
    {
        if (! preg_match('/\A\s*---\R(.*?)\R---/s', $inhalt, $m)) {
            return [null, null];
        }
        $id = preg_match('/^anker_id:\s*(\d+)\s*$/m', $m[1], $i) ? (int) $i[1] : null;
        $slug = preg_match('/^anker_slug:\s*["\']?([A-Za-z0-9_\-]+)["\']?\s*$/m', $m[1], $s) ? $s[1] : null;

        return [$id, $slug];
    }
}
