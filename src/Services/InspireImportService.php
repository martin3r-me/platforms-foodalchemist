<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Inspire-Voll-Import (Dominique 2026-08-06) — Foodpairing® Inspire als eigener
 * Graph-Teil, KEIN Merge auf Bestands-Anker.
 *
 * Quelle: foodpairing_kompakt.db (kommerziell, NIE ins Repo; per --source übergeben).
 *   - ingredients     2.630 Zutaten → je eine ein eigener Anker (has_pairing_data=1 → 2.628)
 *   - pairings_strong Level 2+3 in beide Richtungen → aroma-Kanten (source='inspire')
 *
 * Ein Pass (import): mintet die Anker + label_en-Brücke UND schreibt die Kanten. Die
 * ix→Anker-Auflösung läuft über die IX (PK, eindeutig) — NICHT über den Namen: Inspire hat
 * 3 Dubletten-Namen (Apricot Puree/Chinese Cabbage/Gochujang), die als „alles einzeln"
 * eigene Anker bleiben müssen. level 3 → weight 1.0 (●) · level 2 → weight 0.9 (◕).
 * Kein Override (Inspire trifft keinen Bestands-Anker). Redo via purgeInspire().
 */
class InspireImportService
{
    private const ANCHORS = 'foodalchemist_vocab_pairing_anchors';

    private const MAP = 'foodalchemist_anchor_ingredient_map';

    private const EDGES = 'foodalchemist_pairing_anchor_edges';

    /** Zählt bereits importierte Inspire-Anker (für den „schon importiert?"-Guard). */
    public function existingInspireAnchors(): int
    {
        return (int) DB::table(self::ANCHORS)->where('source_path', 'foodpairing_inspire')->count();
    }

    /** Rollback: löscht alle Inspire-Kanten, -Brücken und -Anker (für sauberen Redo). */
    public function purgeInspire(): array
    {
        $edges = DB::table(self::EDGES)->where('source', 'inspire')->delete();
        $map = DB::table(self::MAP)->where('match_method', 'inspire')->delete();
        $anchors = DB::table(self::ANCHORS)->where('source_path', 'foodpairing_inspire')->delete();

        return ['edges' => $edges, 'map' => $map, 'anchors' => $anchors];
    }

    /**
     * Mint + Kanten in einem Pass. ix→Anker über die IX (robust gegen Dubletten-Namen).
     *
     * @return array<string,int>
     */
    public function import(\PDO $src, bool $apply, int $teamId): array
    {
        $usedSlugs = array_flip(DB::table(self::ANCHORS)->pluck('slug')->all());
        $ingredients = $src->query(
            'SELECT ix, name, category, subcategory FROM ingredients WHERE has_pairing_data = 1 ORDER BY ix'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $ts = now()->toDateTimeString();
        $ixToAnchor = [];
        $anchorsCreated = 0;
        $slugFixes = 0;

        // --- Phase 1: Anker minten (in einer Transaktion) ---------------------
        $mint = function () use ($ingredients, $apply, $teamId, $ts, &$usedSlugs, &$ixToAnchor, &$anchorsCreated, &$slugFixes): void {
            foreach ($ingredients as $r) {
                $ix = (int) $r['ix'];
                $name = (string) $r['name'];

                $slug = Str::slug($name, '_');
                if ($slug === '') {
                    $slug = 'inspire_'.$ix;
                }
                if (isset($usedSlugs[$slug])) {
                    $slug .= '_'.$ix;
                    $slugFixes++;
                }
                $usedSlugs[$slug] = true;

                if ($apply) {
                    $note = trim(((string) ($r['category'] ?? '')).' / '.((string) ($r['subcategory'] ?? '')), ' /');
                    $anchorId = DB::table(self::ANCHORS)->insertGetId([
                        'uuid' => (string) UuidV7::generate(),
                        'team_id' => $teamId,
                        'slug' => $slug,
                        'display_de' => $name,
                        'source_path' => 'foodpairing_inspire',
                        'note' => $note !== '' ? $note : null,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ]);
                    DB::table(self::MAP)->insert([
                        'anchor_id' => $anchorId,
                        'slug_de' => $slug,
                        'ingredient_id' => null,
                        'label_en' => $name,
                        'has_profile' => 0,
                        'n_key_components' => 0,
                        'match_method' => 'inspire',
                    ]);
                } else {
                    $anchorId = $ix; // Platzhalter für die Dry-Run-Kantenzählung
                }

                $ixToAnchor[$ix] = $anchorId;
                $anchorsCreated++;
            }
        };
        $apply ? DB::transaction($mint) : $mint();

        // --- Phase 2: Kanten schreiben (gechunkt, ix-aufgelöst) ---------------
        $candidates = 0;
        $inserted = 0;
        $skipped = 0;
        $chunk = [];
        $flush = function () use (&$chunk, &$inserted): void {
            if ($chunk) {
                $inserted += DB::table(self::EDGES)->insertOrIgnore($chunk);
                $chunk = [];
            }
        };

        foreach ($src->query('SELECT a, b, level FROM pairings_strong') as $row) {
            $a = $ixToAnchor[(int) $row['a']] ?? null;
            $b = $ixToAnchor[(int) $row['b']] ?? null;
            if ($a === null || $b === null || $a === $b) {
                $skipped++;

                continue;
            }
            $candidates++;
            if (! $apply) {
                continue;
            }
            $lvl = (int) $row['level'];
            $chunk[] = [
                'uuid' => (string) UuidV7::generate(),
                'team_id' => $teamId,
                'anchor_a_id' => $a,
                'anchor_b_id' => $b,
                'type' => 'aroma',
                'weight' => $lvl === 3 ? 1.0 : 0.9,
                'source' => 'inspire',
                'axis' => 'harmony',
                'level' => $lvl,
                'evidence' => $lvl === 3
                    ? 'Foodpairing Inspire (best match)'
                    : 'Foodpairing Inspire (good match)',
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
            if (count($chunk) >= 2000) {
                $flush();
            }
        }
        $flush();

        return [
            'ingredients' => count($ingredients),
            'anchors_created' => $anchorsCreated,
            'slug_collisions_fixed' => $slugFixes,
            'edge_candidates' => $candidates,
            'skipped_self' => $skipped,
            'edges_inserted' => $apply ? $inserted : $candidates,
        ];
    }
}
