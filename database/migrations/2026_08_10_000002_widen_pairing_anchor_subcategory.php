<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `subcategory` auf 150 verbreitern (2026-08-10). Die deutschen Unterkategorie-PFADE aus der
 * reviewten CSV sind bis 78 Zeichen lang (z.B. „Getränke/Alkoholische Getränke/Spirituosen/
 * Nicht traubenbasierte Spirituosen") — die ursprünglichen 64 haben den CSV-Import abbrechen
 * lassen. `category` (max 19) bleibt bei 48.
 *
 * MySQL-Raw (guarded): SQLite erzwingt keine VARCHAR-Länge → dort kein Handlungsbedarf.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_vocab_pairing_anchors', 'subcategory')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE foodalchemist_vocab_pairing_anchors MODIFY subcategory VARCHAR(150) NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_vocab_pairing_anchors', 'subcategory')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE foodalchemist_vocab_pairing_anchors MODIFY subcategory VARCHAR(64) NULL');
        }
    }
};
