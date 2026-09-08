<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 52 · F4 — `regelwerk:always` gibt es nicht mehr, also darf keine Zeile mehr darauf stehen.
 *
 * Der dedizierte `always`-Zweig für die Kategorie `regelwerk` (`regelwerkBlock()`) ist gelöscht:
 * er wählte per Slug-Muster und nahm `orderBy('slug')->first()` — bei `%basisrezept%` also eines
 * von rund zwanzig §-Dossiers, alphabetisch. Verbindliches Wissen kommt aus dem KANON.
 *
 * ⚠ **Warum das eine Migration braucht und nicht nur gelöschten Code.** Der generische
 * Discovery-Pfad verarbeitet ausschliesslich `mode = 'discovery'`. Eine stehen gebliebene
 * `regelwerk:always`-Zeile lüde ab sofort **gar nichts** — ohne Fehler, ohne Log, genau die
 * stille Sorte Verlust, gegen die diese Spec antritt. Also wird sie umgestellt, nicht ignoriert.
 *
 * Live auf demo betraf das GENAU EINE Zeile: `foodbook.grundgeruest × regelwerk` (ungekappt).
 * Dort trifft `%foodbook%` genau ein Dossier — die Auswahl war also zufällig richtig, nicht
 * grundsätzlich. Ersatz ist eine Kanon-Zeile auf `regelwerk-foodbook-grundgerust`; die legt
 * diese Migration mit an, wo das Dossier existiert.
 *
 * Der Ersatz steht als explizite Liste in `ZIELE` — je Feature der Live-Stand, nicht eine
 * generische Regel. Was dort nicht steht, geht auf `discovery`, nie auf `none`: durch diese
 * Migration darf kein Key ÄRMER werden als vorher.
 */
return new class extends Migration
{
    /**
     * feature → Soll-Zustand, wenn dort heute `regelwerk:always` steht.
     *
     * Explizite Liste statt generischer Regel — dasselbe Muster wie
     * `2026_09_08_000002_fix_widerspruechliche_routings`. Eine generische Regel („mehrere
     * Treffer ⇒ discovery") war mein erster Versuch und widersprach prompt dem Seed: für
     * `ai_generate_recipe` ist `none` richtig, weil dort der KANON trägt, nicht die Suche.
     * Der Drift-Test hat es gefangen.
     *
     * `kanon_wenn_eindeutig`: nur bei `foodbook.grundgeruest`. Dort trifft `%foodbook%` genau
     * EIN Dossier — die Auswahl, die `->first()` ohnehin traf, wird als Kanon-Zeile
     * festgeschrieben und damit deterministisch statt zufällig richtig. Bei `%basisrezept%`
     * (rund zwanzig §-Splits) wäre dasselbe die Lotterie zurück; dort entscheidet ein Mensch.
     *
     * @var array<string, array{mode: string, max_docs: int|null, chars: int|null, slug_like?: string}>
     */
    private const ZIELE = [
        // Der Kanon trägt es (13 Pflicht-Dossiers auf demo). Seed sagt seit Welle 0 `none`,
        // die Migration von 2026-08-14 sagt `always 1×7000` — dieser Widerspruch (Befund G1)
        // ist damit aufgelöst, und zwar zugunsten des Live-Stands.
        'ai_generate_recipe' => ['mode' => 'none', 'max_docs' => null, 'chars' => null],
        // Live auf demo `discovery 3×8000`; der Kanon (3 Zeilen) macht das Verbindliche.
        'concept.brief_geruest' => ['mode' => 'discovery', 'max_docs' => 3, 'chars' => 8000],
        // Das letzte lebende `always` — bekommt seinen Kanon und geht auf `none`.
        'foodbook.grundgeruest' => ['mode' => 'none', 'max_docs' => null, 'chars' => null, 'slug_like' => '%foodbook%'],
    ];

    /**
     * Für alles, was nicht in ZIELE steht: `discovery`, NICHT `none`.
     *
     * Ein unbekanntes Feature auf `none` zu setzen hiesse, es durch diese Migration ärmer zu
     * machen als vorher. `discovery` hält es versorgt, bis jemand kuratiert.
     */
    private const REST = ['mode' => 'discovery', 'max_docs' => 3, 'chars' => 4000];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }

        $betroffen = DB::table('foodalchemist_knowledge_routings')
            ->where('category', 'regelwerk')->where('mode', 'always')
            ->pluck('feature')->all();

        foreach ($betroffen as $feature) {
            $ziel = self::ZIELE[$feature] ?? self::REST;

            DB::table('foodalchemist_knowledge_routings')
                ->where('feature', $feature)->where('category', 'regelwerk')->where('mode', 'always')
                ->update([
                    'mode' => $ziel['mode'],
                    'max_docs' => $ziel['max_docs'],
                    'max_chars_per_doc' => $ziel['chars'],
                    'updated_at' => now(),
                ]);

            $like = $ziel['slug_like'] ?? null;
            if ($like === null || ! Schema::hasTable('foodalchemist_knowledge_canon')) {
                continue;
            }

            $treffer = DB::table('foodalchemist_knowledge_documents')
                ->where('category', 'regelwerk')->where('active', 1)->whereNull('deleted_at')
                ->where('slug', 'like', $like)->get(['id', 'team_id']);
            if ($treffer->count() !== 1) {
                continue;              // nicht eindeutig ⇒ Kuration, keine Migrations-Aufgabe
            }
            $doc = $treffer->first();

            // Idempotent: bestehende Zeile (auch eine soft-gelöschte) nicht doppeln.
            $da = DB::table('foodalchemist_knowledge_canon')
                ->where('scope', 'prompt_key')->where('scope_key', $feature)->where('role', 'root')
                ->where('knowledge_document_id', $doc->id)->exists();
            if ($da) {
                continue;
            }

            DB::table('foodalchemist_knowledge_canon')->insert([
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => $doc->team_id,
                'scope' => 'prompt_key', 'scope_key' => $feature, 'role' => 'root', 'ord' => 10,
                'knowledge_document_id' => $doc->id, 'mode' => 'pflicht', 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Kein Rückweg: `always` würde nach F4 nichts mehr laden, das Wiederherstellen wäre
        // eine Zeile, die lügt. Wer zurück will, nimmt den Code-Stand vor F4.
    }
};
