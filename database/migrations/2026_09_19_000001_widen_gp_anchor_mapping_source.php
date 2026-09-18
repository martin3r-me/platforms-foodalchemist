<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `source` auf `foodalchemist_gp_anchor_mappings` von 16 auf 32 verbreitern (2026-09-19).
 *
 * Befund beim Vault-Brücke-Import (PR #143 Nachtrag): das Label `bridge_alt_neu+exact` (20
 * Zeichen) warf 506× SQLSTATE 22001 (Data too long) — die ursprünglichen 16 Zeichen deckten nur
 * die bisherigen Quellen `manual`/`ai_inferred`/`mcp` ab. Künftige Quellen wie `exact_name_v2`
 * werden erwartet, darum 32 statt exakt der aktuell längsten Zeichenkette.
 *
 * MySQL-Raw (guarded): SQLite erzwingt keine VARCHAR-Länge → dort kein Handlungsbedarf (Muster
 * wie `2026_08_10_000002_widen_pairing_anchor_subcategory.php`). Die eigentliche Absicherung
 * gegen SQL-Fehler ist die PHP-Validierung in `PairingService::setGpAnker()` (wirft sauber,
 * bevor die Query überhaupt losgeht) — diese Migration hebt nur die Kapazität an, sie ersetzt
 * die Validierung nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_gp_anchor_mappings', 'source')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE foodalchemist_gp_anchor_mappings MODIFY source VARCHAR(32) NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_gp_anchor_mappings', 'source')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE foodalchemist_gp_anchor_mappings MODIFY source VARCHAR(16) NULL');
        }
    }
};
