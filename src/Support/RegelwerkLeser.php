<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;

/**
 * Liest Regelwerk-Dossiers aus dem FA-Wissensmodul (SSOT, Spec 41 A3) für deterministische Prüfungen —
 * statt Listen im Code zu pflegen, die mit dem Regelwerk auseinanderlaufen (Spec 80 Teil G, Vorgabe
 * Dominique 2026-10-09: Wissen wird zur Laufzeit gelesen, nicht hart kodiert).
 *
 * Memo im Container mit 10 Minuten Haltbarkeit: je Request/Job (und je Test) frisch, in langlebigen
 * Queue-Workern kommt eine Änderung im Wissensmodul ohne Neustart an. Fehlt ein Dossier, liefert der
 * Leser '' und die aufbauende Prüfung greift nicht (fail-open = Verhalten wie vorher).
 */
final class RegelwerkLeser
{
    private const MEMO = 'foodalchemist.regelwerk_leser';

    /** Inhalt der neuesten aktiven Version eines Dossiers (Slug-LIKE-Muster) oder ''. */
    public static function inhalt(string $slugLike): string
    {
        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : [];
        if (isset($memo[$slugLike]) && $memo[$slugLike]['bis'] > time()) {
            return $memo[$slugLike]['md'];
        }
        try {
            $md = (string) (DB::table('foodalchemist_knowledge_documents')
                ->where('slug', 'like', $slugLike)
                ->where('active', 1)->whereNull('deleted_at')
                ->orderByDesc('version')->value('content_md') ?? '');
        } catch (\Throwable) {
            $md = '';
        }
        $memo[$slugLike] = ['md' => $md, 'bis' => time() + 600];
        app()->instance(self::MEMO, $memo);

        return $md;
    }

    /** Memo leeren (Tests, nach einem Wissens-Import). */
    public static function vergessen(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Die Werte in Backticks aus der ersten Zeile, die `$marker` enthält — z. B. aus
     * „- Verarbeitungs-Suffixe: `brunoise`, `würfel/wuerfel`, …". Schrägstrich trennt Schreibvarianten.
     *
     * @return list<string>
     */
    public static function backtickListe(string $md, string $marker): array
    {
        foreach (preg_split('/\R/u', $md) ?: [] as $zeile) {
            if (! str_contains($zeile, $marker)) {
                continue;
            }
            preg_match_all('/`([^`]+)`/u', $zeile, $m);
            $out = [];
            foreach ($m[1] ?? [] as $wert) {
                foreach (explode('/', $wert) as $teil) {
                    if (($t = trim($teil)) !== '') {
                        $out[] = $t;
                    }
                }
            }

            return array_values(array_unique($out));
        }

        return [];
    }
}
