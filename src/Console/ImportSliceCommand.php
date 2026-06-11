<?php

namespace Platform\FoodAlchemist\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Uid\UuidV7;

/**
 * Vertical-Slice-Import: Warengruppen + Einheiten + GPs aus der Alt-SQLite (wawi_1494.sqlite).
 *
 * Architektur nach 07_MIGRATION_SEED: read-only Quelle (PDO sqlite), Chunk-Inserts ohne
 * Model-Events, ID-Map für Idempotenz + FK-Umverdrahtung, team_id = --team (Katalog-Besitzer, D1 Eltern→Kinder),
 * Lineage-Mapping manual|ki|auto (GL-07), Timestamps Europe/Berlin → UTC (07 §3).
 *
 * Der Voll-Import (P0–P8) folgt als foodalchemist:import-legacy; dieses Kommando ist der
 * bewusst schmale Slice-Beweis (P1-Teilmenge + P3-Kern).
 */
class ImportSliceCommand extends Command
{
    protected $signature = 'foodalchemist:import-slice
        {--source= : Pfad zur Quell-SQLite (wawi_1494.sqlite)}
        {--team= : Ziel-Team-ID (Katalog-Besitzer, i.d.R. das Eltern-/Root-Team — D1)}
        {--fresh : Slice-Tabellen vorher leeren (NUR Dev!)}
        {--dry-run : Nur zählen und berichten, nichts schreiben}';

    protected $description = 'Importiert Warengruppen, Einheiten und Grundprodukte aus der Cooking-Jarvis-SQLite (Vertical Slice)';

    private const CHUNK = 500;

    /** D1 (Eltern→Kinder): Importdaten gehören dem angegebenen Team. */
    private int $teamId;

    public function handle(): int
    {
        // Importer-Pattern: die legacy_id→id-Maps der großen Tabellen (264k Artikel) brauchen Headroom.
        ini_set('memory_limit', '1024M');

        $source = (string) $this->option('source');
        if ($source === '' || ! is_readable($source)) {
            $this->error('Quell-SQLite nicht lesbar. --source=/pfad/zu/wawi_1494.sqlite angeben.');

            return self::FAILURE;
        }

        $teamOption = $this->option('team');
        if ($teamOption === null || ! ctype_digit((string) $teamOption)) {
            $this->error('Ziel-Team fehlt. --team=<id> angeben (Katalog-Besitzer, D1 Eltern→Kinder).');

            return self::FAILURE;
        }
        $this->teamId = (int) $teamOption;
        if (! DB::table('teams')->where('id', $this->teamId)->exists()) {
            $this->error("Team {$this->teamId} existiert nicht.");

            return self::FAILURE;
        }

        $pdo = new PDO('sqlite:'.$source, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON;'); // Quelle strikt read-only (07 §0)

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('fresh') && ! $dryRun) {
            $this->freshen();
        }

        $stats = [];
        $stats['warengruppen'] = $this->importWarengruppen($pdo, $dryRun);
        $stats['einheiten'] = $this->importEinheiten($pdo, $dryRun);
        $stats['gps'] = $this->importGps($pdo, $dryRun);
        if (! $dryRun) {
            $stats['gps_self_refs'] = $this->wireGpSelfRefs();
        }
        // P2: Lieferanten-Welt (bulk via legacy_id, set-basierte ID-Map)
        $stats['suppliers'] = $this->importBulk($pdo, $dryRun, 'suppliers', 'foodalchemist_suppliers', 'id',
            fn (array $r) => [
                'legacy_id' => $r['id'],
                'name' => $r['name'] ?? '—',
                'branch' => self::nullIfBlank($r['branch'] ?? null),
                'gln' => self::nullIfBlank($r['gln'] ?? null),
                'postal_code' => self::nullIfBlank($r['postal_code'] ?? null),
                'city' => self::nullIfBlank($r['city'] ?? null),
                'address' => self::nullIfBlank($r['address'] ?? null),
                'homepage' => self::nullIfBlank($r['homepage'] ?? null),
                'email_order' => self::nullIfBlank($r['email_order'] ?? null),
                'is_inactive' => (bool) ($r['is_inactive'] ?? 0),
            ]);
        $supplierMap = $this->targetMap('foodalchemist_suppliers');
        $stats['supplier_items'] = $this->importBulk($pdo, $dryRun, 'supplier_items', 'foodalchemist_supplier_items', 'id',
            fn (array $r) => [
                'legacy_id' => $r['id'],
                'supplier_id' => $supplierMap[(int) $r['supplier_id']] ?? null,
                'article_number' => self::nullIfBlank($r['article_number'] ?? null),
                'designation' => $r['designation'] ?? '—',
                'marketing_name' => self::nullIfBlank($r['marketing_name'] ?? null),
                'regulated_name' => self::nullIfBlank($r['regulated_name'] ?? null),
                'brand' => self::nullIfBlank($r['brand'] ?? null),
                'manufacturer' => self::nullIfBlank($r['manufacturer'] ?? null),
                'origin' => self::nullIfBlank($r['origin'] ?? null),
                'packaging_unit' => self::nullIfBlank($r['packaging_unit'] ?? null),
                'ordering_unit' => self::nullIfBlank($r['ordering_unit'] ?? null),
                'qty_ordering_per_packaging' => $r['qty_ordering_per_packaging'],
                'qty' => $r['qty'],
                'ean_packaging' => self::nullIfBlank($r['ean_packaging'] ?? null),
                'ean_ordering' => self::nullIfBlank($r['ean_ordering'] ?? null),
                'is_organic' => self::triState($r['is_organic'] ?? null),
                'is_vegan' => self::triState($r['is_vegan'] ?? null),
                'is_vegetarian' => self::triState($r['is_vegetarian'] ?? null),
                'is_alcohol' => self::triState($r['is_alcohol'] ?? null),
                'is_discontinued' => (bool) ($r['is_discontinued'] ?? 0),
            ], skipRow: fn (array $r) => $r['supplier_id'] === null || ! isset($supplierMap[(int) $r['supplier_id']]));
        $itemMap = $this->targetMap('foodalchemist_supplier_items');
        $stats['prices'] = $this->importBulk($pdo, $dryRun, 'prices', 'foodalchemist_prices', 'id',
            fn (array $r) => [
                'legacy_id' => $r['id'],
                'supplier_item_id' => $itemMap[(int) $r['supplier_item_id']] ?? null,
                'status' => self::nullIfBlank($r['status'] ?? null),
                'price' => $r['price'],
                'price_partial' => $r['price_partial'],
                'valid_to' => self::utcOrNull($r['valid_to'] ?? null),
                'status_valid_from' => self::utcOrNull($r['status_valid_from'] ?? null),
                'is_blocked' => (bool) ($r['is_blocked'] ?? 0),
                'change_date' => self::utcOrNull($r['change_date'] ?? null),
                'creation_date' => self::utcOrNull($r['creation_date'] ?? null),
            ], skipRow: fn (array $r) => ! isset($itemMap[(int) $r['supplier_item_id']]));
        $gpMap = $this->targetMap('foodalchemist_gps', 'wawi_gp_v2');
        $stats['la_structured'] = $this->importBulk($pdo, $dryRun, 'wawi_la_structured', 'foodalchemist_supplier_item_structures', 'supplier_item_id',
            fn (array $r) => [
                'legacy_id' => $r['supplier_item_id'],
                'supplier_item_id' => $itemMap[(int) $r['supplier_item_id']] ?? null,
                'gp_id' => $r['gp_v2_id'] !== null ? ($gpMap[(int) $r['gp_v2_id']] ?? null) : null,
                'ist_lebensmittel' => self::triState($r['ist_lebensmittel']),
                'ausschluss_grund' => self::nullIfBlank($r['ausschluss_grund']),
                'hauptzutat_slug' => self::nullIfBlank($r['hauptzutat_slug']),
                'hauptzutat_display' => self::nullIfBlank($r['hauptzutat_display']),
                'hauptzutat_konfidenz' => $r['hauptzutat_konfidenz'],
                'ist_aroma_haupttraeger' => self::triState($r['ist_aroma_haupttraeger']),
                'aroma_zutaten_slugs' => self::nullIfBlank($r['aroma_zutaten_slugs']),
                'aroma_zutaten_konfidenz' => $r['aroma_zutaten_konfidenz'],
                'verarbeitung' => self::nullIfBlank($r['verarbeitung']),
                'verarbeitung_konfidenz' => $r['verarbeitung_konfidenz'],
                'form' => self::nullIfBlank($r['form']),
                'groesse' => self::nullIfBlank($r['groesse']),
                'convenience_host' => self::nullIfBlank($r['convenience_host']),
                'ist_bio' => self::triState($r['ist_bio']),
                'ist_halal' => self::triState($r['ist_halal']),
                'ist_vegan' => self::triState($r['ist_vegan']),
                'warengruppe_vorschlag' => self::nullIfBlank($r['warengruppe_vorschlag']),
                'warengruppe_konfidenz' => $r['warengruppe_konfidenz'],
                'gp_key' => self::nullIfBlank($r['gp_key']),
                'gp_name_derived' => self::nullIfBlank($r['gp_name_derived']),
                'zustand' => self::nullIfBlank($r['zustand']),
                'klassifikator' => self::nullIfBlank($r['klassifikator']),
                'klassifikator_version' => self::nullIfBlank($r['klassifikator_version']),
                'klassifiziert_am' => self::utcOrNull($r['klassifiziert_am']),
                'needs_review' => (bool) ($r['needs_review'] ?? 0),
                'review_grund' => self::nullIfBlank($r['review_grund']),
                'ai_begruendung' => self::nullIfBlank($r['ai_begruendung']),
            ], skipRow: fn (array $r) => ! isset($itemMap[(int) $r['supplier_item_id']]));
        if (! $dryRun) {
            $stats['lead_la_wire'] = $this->wireLeadLas();
        }

        // M1-04: Produktions-Taxonomie (Skript-204-Stand: 30 HG / 186 Kategorien)
        $stats['recipe_main_groups'] = $this->importBulk($pdo, $dryRun, 'recipe_hauptgruppen', 'foodalchemist_recipe_main_groups', 'hauptgruppe_id',
            fn (array $r) => [
                'legacy_id' => $r['hauptgruppe_id'],
                'code' => $r['code'],
                'bezeichnung' => $r['bezeichnung'],
                'bereich' => self::nullIfBlank($r['beschreibung'] ?? null),
                'sort_order' => (int) ($r['sort_nr'] ?? 0),
            ]);
        $hgMap = $this->targetMap('foodalchemist_recipe_main_groups');
        $stats['recipe_categories'] = $this->importBulk($pdo, $dryRun, 'recipe_kategorien', 'foodalchemist_recipe_categories', 'kategorie_id',
            fn (array $r) => [
                'legacy_id' => $r['kategorie_id'],
                'main_group_id' => $hgMap[(int) $r['hauptgruppe_id']] ?? null,
                'code' => $r['code'],
                'bezeichnung' => $r['bezeichnung'],
                'technik' => self::nullIfBlank($r['technik'] ?? null),
                'sort_order' => (int) ($r['sort_nr'] ?? 0),
                'legacy_excel_kat' => self::nullIfBlank($r['legacy_excel_kat'] ?? null),
                'legacy_category_id' => $r['legacy_category_id'],
            ], skipRow: fn (array $r) => ! isset($hgMap[(int) $r['hauptgruppe_id']]));

        $this->table(
            ['Phase', 'Quelle', 'importiert', 'übersprungen (id_map)'],
            collect($stats)->map(fn ($s, $k) => [$k, $s['source'] ?? '—', $s['imported'] ?? '—', $s['skipped'] ?? '—'])->all()
        );

        // Row-Count-Verifikation (07 §5): Quelle == id_map je Quell-Tabelle
        if (! $dryRun) {
            foreach (['lookup_warengruppe', 'vocab_einheit', 'wawi_gp_v2', 'suppliers', 'supplier_items', 'prices', 'wawi_la_structured', 'recipe_hauptgruppen', 'recipe_kategorien'] as $sourceTable) {
                $sourceCount = (int) $pdo->query("SELECT COUNT(*) FROM {$sourceTable}")->fetchColumn();
                $mapCount = DB::table('foodalchemist_legacy_id_map')->where('source_table', $sourceTable)->count();
                $flag = $sourceCount === $mapCount ? '✅' : '❌ BLOCKER';
                $this->line("{$flag} {$sourceTable}: Quelle {$sourceCount} ↔ id_map {$mapCount}");
            }
        }

        return self::SUCCESS;
    }

    private function freshen(): void
    {
        $this->warn('--fresh: leere Slice-Tabellen …');
        DB::table('foodalchemist_gps')->update([
            'merged_into_id' => null, 'derivat_von_gp_id' => null, 'lead_la_supplier_item_id' => null,
        ]);
        DB::table('foodalchemist_recipe_categories')->delete();
        DB::table('foodalchemist_recipe_main_groups')->delete();
        DB::table('foodalchemist_supplier_item_structures')->delete();
        DB::table('foodalchemist_prices')->delete();
        DB::table('foodalchemist_supplier_items')->delete();
        DB::table('foodalchemist_suppliers')->delete();
        DB::table('foodalchemist_gps')->delete();
        DB::table('foodalchemist_vocab_einheiten')->delete();
        DB::table('foodalchemist_lookup_warengruppen')->delete();
        DB::table('foodalchemist_legacy_id_map')
            ->whereIn('source_table', [
                'lookup_warengruppe', 'vocab_einheit', 'wawi_gp_v2',
                'suppliers', 'supplier_items', 'prices', 'wawi_la_structured',
                'recipe_hauptgruppen', 'recipe_kategorien',
            ])
            ->delete();
    }

    private function importWarengruppen(PDO $pdo, bool $dryRun): array
    {
        $rows = $pdo->query('SELECT rowid AS source_id, code, name FROM lookup_warengruppe ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
        if ($dryRun) {
            return ['source' => count($rows), 'imported' => 0, 'skipped' => 0];
        }

        $imported = $skipped = 0;
        DB::transaction(function () use ($rows, &$imported, &$skipped) {
            foreach ($rows as $i => $row) {
                if ($this->mapped('lookup_warengruppe', (int) $row['source_id'])) {
                    $skipped++;

                    continue;
                }
                $uuid = (string) UuidV7::generate();
                $id = DB::table('foodalchemist_lookup_warengruppen')->insertGetId([
                    'uuid' => $uuid,
                    'team_id' => $this->teamId,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->map('lookup_warengruppe', (int) $row['source_id'], $id, $uuid);
                $imported++;
            }
        });

        return ['source' => count($rows), 'imported' => $imported, 'skipped' => $skipped];
    }

    private function importEinheiten(PDO $pdo, bool $dryRun): array
    {
        $rows = $pdo->query('SELECT vocab_id, slug, display_de, dimension, default_in_g, default_in_ml, is_approximate, sort_order, note FROM vocab_einheit ORDER BY vocab_id')->fetchAll(PDO::FETCH_ASSOC);
        if ($dryRun) {
            return ['source' => count($rows), 'imported' => 0, 'skipped' => 0];
        }

        $imported = $skipped = 0;
        DB::transaction(function () use ($rows, &$imported, &$skipped) {
            foreach ($rows as $row) {
                if ($this->mapped('vocab_einheit', (int) $row['vocab_id'])) {
                    $skipped++;

                    continue;
                }
                $uuid = (string) UuidV7::generate();
                $id = DB::table('foodalchemist_vocab_einheiten')->insertGetId([
                    'uuid' => $uuid,
                    'team_id' => $this->teamId,
                    'slug' => $row['slug'],
                    'display_de' => $row['display_de'],
                    'dimension' => self::nullIfBlank($row['dimension']),
                    'default_in_g' => $row['default_in_g'],
                    'default_in_ml' => $row['default_in_ml'],
                    'is_approximate' => (bool) $row['is_approximate'],
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'note' => self::nullIfBlank($row['note']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->map('vocab_einheit', (int) $row['vocab_id'], $id, $uuid);
                $imported++;
            }
        });

        return ['source' => count($rows), 'imported' => $imported, 'skipped' => $skipped];
    }

    private function importGps(PDO $pdo, bool $dryRun): array
    {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM wawi_gp_v2')->fetchColumn();
        if ($dryRun) {
            return ['source' => $total, 'imported' => 0, 'skipped' => 0];
        }

        $einheitMap = DB::table('foodalchemist_legacy_id_map')
            ->where('source_table', 'vocab_einheit')->pluck('target_id', 'source_id')->all();

        $stmt = $pdo->query('SELECT * FROM wawi_gp_v2 ORDER BY gp_v2_id');
        $imported = $skipped = 0;
        $chunk = [];

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($this->mapped('wawi_gp_v2', (int) $row['gp_v2_id'])) {
                $skipped++;

                continue;
            }
            $chunk[] = $row;
            if (count($chunk) >= self::CHUNK) {
                $imported += $this->flushGpChunk($chunk, $einheitMap);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $imported += $this->flushGpChunk($chunk, $einheitMap);
        }

        return ['source' => $total, 'imported' => $imported, 'skipped' => $skipped];
    }

    private function flushGpChunk(array $rows, array $einheitMap): int
    {
        $count = 0;
        DB::transaction(function () use ($rows, $einheitMap, &$count) {
            foreach ($rows as $row) {
                $uuid = (string) UuidV7::generate();
                $id = DB::table('foodalchemist_gps')->insertGetId([
                    'uuid' => $uuid,
                    'team_id' => $this->teamId, // Katalog-Besitzer (D1 Eltern→Kinder)
                    'gp_key' => $row['gp_key'],
                    'name' => $row['gp_name'],
                    'hauptzutat_slug' => $row['hauptzutat_slug'],
                    'hauptzutat_display' => self::nullIfBlank($row['hauptzutat_display']),
                    'verarbeitung' => self::nullIfBlank($row['verarbeitung']),
                    'form' => self::nullIfBlank($row['form']),
                    'warengruppe_code' => self::warengruppeCode($row['warengruppe']),
                    'sub_kategorie' => self::nullIfBlank($row['sub_kategorie']),
                    'zustand' => self::nullIfBlank($row['zustand']),
                    'bio' => self::nullIfBlank($row['bio']),
                    'status' => $row['status'] ?: 'tentative',
                    'reviewer_note' => self::nullIfBlank($row['reviewer_note']),
                    'first_seen_at' => self::utcOrNull($row['first_seen_at']),
                    'last_review_at' => self::utcOrNull($row['last_review_at']),
                    'ai_confidence' => $row['ai_confidence'],
                    'ai_begruendung' => self::nullIfBlank($row['ai_begruendung']),
                    'is_derivat' => (bool) ($row['is_derivat'] ?? 0),
                    'requires_la' => (bool) ($row['requires_la'] ?? 1),
                    'is_platzhalter' => (bool) ($row['is_platzhalter'] ?? 0),
                    'n_las_total' => (int) ($row['n_las_total'] ?? 0),
                    'first_supplier_legacy_id' => $row['first_supplier_id'],
                    'lead_la_supplier_item_legacy_id' => $row['lead_la_supplier_item_id'],
                    'garverlust_default_pct' => $row['garverlust_default_pct'],
                    'stk_default_g' => $row['stk_default_g'],
                    'stk_default_g_quelle' => self::mapQuelle($row['stk_default_g_quelle']),
                    'stk_default_g_ai_confidence' => $row['stk_default_g_ai_confidence'],
                    'stk_default_g_ai_begruendung' => self::nullIfBlank($row['stk_default_g_ai_begruendung']),
                    'preferred_count_unit_id' => $row['preferred_count_unit_vocab_id'] !== null
                        ? ($einheitMap[(int) $row['preferred_count_unit_vocab_id']] ?? null)
                        : null,
                    ...self::allergenColumns($row),
                    'allergene_quelle' => self::mapQuelle($row['allergene_quelle']),
                    'allergene_ai_confidence' => $row['allergene_ai_confidence'],
                    'allergene_aggregiert_am' => self::utcOrNull($row['allergene_aggregiert_am']),
                    ...self::tagColumns($row),
                    'tag_quelle' => self::mapQuelle($row['tag_quelle']),
                    'tag_ai_confidence' => $row['tag_ai_confidence'],
                    'tag_ai_begruendung' => self::nullIfBlank($row['tag_ai_begruendung']),
                    'tag_aggregiert_am' => self::utcOrNull($row['tag_aggregiert_am']),
                    'primary_food_domain_legacy_id' => $row['primary_food_domain_id'],
                    'food_domain_quelle' => self::mapQuelle($row['food_domain_quelle']),
                    'food_domain_ai_confidence' => $row['food_domain_ai_confidence'],
                    'food_domain_ai_begruendung' => self::nullIfBlank($row['food_domain_ai_begruendung']),
                    'food_domain_aggregiert_am' => self::utcOrNull($row['food_domain_aggregiert_am']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->map('wawi_gp_v2', (int) $row['gp_v2_id'], $id, $uuid);
                $count++;
            }
        });

        return $count;
    }

    /** Zweiter Pass: Self-FKs (merged_into, derivat_von) über die ID-Map umverdrahten. */
    private function wireGpSelfRefs(): array
    {
        $gpMap = DB::table('foodalchemist_legacy_id_map')
            ->where('source_table', 'wawi_gp_v2')->pluck('target_id', 'source_id')->all();

        $pdo = new PDO('sqlite:'.(string) $this->option('source'));
        $rows = $pdo->query('SELECT gp_v2_id, merged_into_id, derivat_von_gp_id FROM wawi_gp_v2 WHERE merged_into_id IS NOT NULL OR derivat_von_gp_id IS NOT NULL')
            ->fetchAll(PDO::FETCH_ASSOC);

        $wired = 0;
        foreach ($rows as $row) {
            $targetId = $gpMap[(int) $row['gp_v2_id']] ?? null;
            if ($targetId === null) {
                continue;
            }
            DB::table('foodalchemist_gps')->where('id', $targetId)->update([
                'merged_into_id' => $row['merged_into_id'] !== null ? ($gpMap[(int) $row['merged_into_id']] ?? null) : null,
                'derivat_von_gp_id' => $row['derivat_von_gp_id'] !== null ? ($gpMap[(int) $row['derivat_von_gp_id']] ?? null) : null,
            ]);
            $wired++;
        }

        return ['source' => count($rows), 'imported' => $wired, 'skipped' => count($rows) - $wired];
    }

    /**
     * Generischer Bulk-Import (P2-Pfad für die großen Tabellen): Chunk-Inserts mit uuid+legacy_id,
     * danach set-basierte ID-Map-Befüllung (kein insertGetId pro Zeile — 264k Zeilen wären sonst lahm).
     * Idempotenz auf Phasen-Ebene: vollständig importierte Tabelle wird übersprungen; Teilstand → Hinweis auf --fresh.
     */
    private function importBulk(PDO $pdo, bool $dryRun, string $sourceTable, string $targetTable, string $sourcePk, \Closure $mapRow, ?\Closure $skipRow = null): array
    {
        $total = (int) $pdo->query("SELECT COUNT(*) FROM {$sourceTable}")->fetchColumn();
        if ($dryRun) {
            return ['source' => $total, 'imported' => 0, 'skipped' => 0];
        }

        $mapped = DB::table('foodalchemist_legacy_id_map')->where('source_table', $sourceTable)->count();
        if ($mapped >= $total && $total > 0) {
            return ['source' => $total, 'imported' => 0, 'skipped' => $total];
        }
        if ($mapped > 0) {
            $this->warn("{$sourceTable}: Teilstand ({$mapped}/{$total}) — für sauberen Re-Import --fresh nutzen. Phase übersprungen.");

            return ['source' => $total, 'imported' => 0, 'skipped' => $mapped];
        }

        $stmt = $pdo->query("SELECT * FROM {$sourceTable} ORDER BY {$sourcePk}");
        $imported = $skippedRows = 0;
        $chunk = [];
        $now = now()->toDateTimeString();

        $flush = function () use (&$chunk, $targetTable, &$imported) {
            if ($chunk === []) {
                return;
            }
            DB::transaction(fn () => DB::table($targetTable)->insert($chunk));
            $imported += count($chunk);
            $chunk = [];
        };

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($skipRow !== null && $skipRow($row)) {
                $skippedRows++;

                continue;
            }
            $chunk[] = [
                'uuid' => (string) UuidV7::generate(),
                'team_id' => $this->teamId, // Katalog-Besitzer (D1 Eltern→Kinder)
                ...$mapRow($row),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();

        // Set-basierte ID-Map-Befüllung aus legacy_id
        DB::statement("
            INSERT INTO foodalchemist_legacy_id_map (source_table, source_id, target_id, target_uuid, created_at, updated_at)
            SELECT ?, t.legacy_id, t.id, t.uuid, ?, ?
            FROM {$targetTable} t
            WHERE t.legacy_id IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM foodalchemist_legacy_id_map m
                WHERE m.source_table = ? AND m.source_id = t.legacy_id
              )
        ", [$sourceTable, $now, $now, $sourceTable]);

        if ($skippedRows > 0) {
            $this->warn("{$sourceTable}: {$skippedRows} Zeilen ohne auflösbare FK übersprungen (Waisen in der Quelle).");
        }

        return ['source' => $total, 'imported' => $imported, 'skipped' => $skippedRows];
    }

    /** legacy_id → target_id einer Ziel-Tabelle (für FK-Auflösung in Folge-Phasen). */
    private function targetMap(string $targetTable, ?string $viaSourceTable = null): array
    {
        if ($viaSourceTable !== null) {
            return DB::table('foodalchemist_legacy_id_map')
                ->where('source_table', $viaSourceTable)->pluck('target_id', 'source_id')->all();
        }

        return DB::table($targetTable)->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
    }

    /** GL-03: Legacy-Lead-Referenz → echter FK, set-basiert über legacy_id-Join. */
    private function wireLeadLas(): array
    {
        $total = (int) DB::table('foodalchemist_gps')->whereNotNull('lead_la_supplier_item_legacy_id')->count();
        DB::statement('
            UPDATE foodalchemist_gps
            SET lead_la_supplier_item_id = (
                SELECT i.id FROM foodalchemist_supplier_items i
                WHERE i.legacy_id = foodalchemist_gps.lead_la_supplier_item_legacy_id
            )
            WHERE lead_la_supplier_item_legacy_id IS NOT NULL
        ');
        $wired = (int) DB::table('foodalchemist_gps')->whereNotNull('lead_la_supplier_item_id')->count();

        return ['source' => $total, 'imported' => $wired, 'skipped' => $total - $wired];
    }

    /** INTEGER nullable → tri-state bool (NULL = unbewertet). */
    private static function triState(mixed $raw): ?bool
    {
        return $raw === null ? null : (bool) $raw;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function mapped(string $sourceTable, int $sourceId): bool
    {
        return DB::table('foodalchemist_legacy_id_map')
            ->where('source_table', $sourceTable)->where('source_id', $sourceId)->exists();
    }

    private function map(string $sourceTable, int $sourceId, int $targetId, string $uuid): void
    {
        DB::table('foodalchemist_legacy_id_map')->insert([
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'target_uuid' => $uuid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Lineage-Vokabular vereinheitlichen: manual|ki|auto (GL-07 §2 / 07 §3). */
    private static function mapQuelle(?string $raw): ?string
    {
        return match (trim((string) $raw)) {
            'manual' => 'manual',
            'ki', 'ai_inferred' => 'ki',
            'auto_slug_match', 'auto_neutral', 'auto' => 'auto',
            default => null,
        };
    }

    /** "01 Gemuese & Blattsalat" → "01" (Quelle hält den kombinierten String). */
    private static function warengruppeCode(?string $raw): ?string
    {
        if ($raw === null || ! preg_match('/^(\d{2})\b/', trim($raw), $m)) {
            return null;
        }

        return $m[1];
    }

    /** Quell-Zeitstempel sind Europe/Berlin-Wanduhr ohne TZ → UTC (07 §3); unparsbar ⇒ NULL. */
    private static function utcOrNull(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw, 'Europe/Berlin')->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function nullIfBlank(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : $raw;
    }

    private static function allergenColumns(array $row): array
    {
        $out = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS as $field) {
            $out["allergen_{$field}"] = self::nullIfBlank($row["allergen_{$field}"] ?? null);
        }

        return $out;
    }

    private static function tagColumns(array $row): array
    {
        $out = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag) {
            $raw = $row["tag_{$tag}"] ?? null;
            $out["tag_{$tag}"] = $raw === null ? null : (bool) $raw;
        }

        return $out;
    }
}
