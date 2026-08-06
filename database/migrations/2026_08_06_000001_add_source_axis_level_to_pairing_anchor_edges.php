<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspire-Umbau 2a — Provenienz-Achse für den Anker-Graphen (Dominique 2026-08-06).
 *
 * Ranking wechselt von Typ-Dekret (GEWICHTE['erprobt']=1.0) auf Provenienz-Konfidenz.
 * Dafür drei Spalten:
 *   - source  : Herkunft — human_curated | ai_dossier | inspire | computed | book | curated_kontrast
 *   - axis    : harmony | contrast (NUR Display/Buckets + ●-Labeling; Kohäsion rechnet weiter
 *               mit ALLEN Kanten inkl. Kontrast — NICHT auf axis filtern)
 *   - level   : 1|2|3 Foodpairing-Stufe (○/◕/●), nur bei axis='harmony'; ● exklusiv Inspire L3
 *
 * Idempotenter Backfill der Bestands-Kanten (source IS NULL-Guard → re-run-sicher):
 *   source_slug='computed' → computed (harmony level aus weight≥0.48; kontrast level=NULL)
 *   type='aroma'   (book)  → book, harmony, level 1
 *   type='kontrast'        → curated_kontrast, contrast, level NULL
 *   type='erprobt' + '*'   → ai_dossier   (Gemini-Dossier-Signatur)
 *   type='erprobt' ohne '*'→ human_curated
 * (erprobt wird in Schritt 4 gewipt; Backfill nur fürs Audit/den gezielten Wipe.)
 *
 * Portabel (SQLite + MySQL). LIKE '%*%' = literaler Stern (nur % und _ sind Wildcards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_pairing_anchor_edges')) {
            return;
        }

        Schema::table('foodalchemist_pairing_anchor_edges', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_pairing_anchor_edges', 'source')) {
                $table->string('source', 24)->nullable()->after('source_slug')
                    ->comment('Provenienz: human_curated|ai_dossier|inspire|computed|book|curated_kontrast');
            }
            if (! Schema::hasColumn('foodalchemist_pairing_anchor_edges', 'axis')) {
                $table->string('axis', 8)->nullable()->after('source')
                    ->comment('harmony|contrast — nur Display/Buckets, NICHT Kohäsions-Filter');
            }
            if (! Schema::hasColumn('foodalchemist_pairing_anchor_edges', 'level')) {
                $table->unsignedTinyInteger('level')->nullable()->after('axis')
                    ->comment('Foodpairing-Stufe 1○/2◕/3●, nur axis=harmony; ● exklusiv Inspire L3');
            }
        });

        $edges = 'foodalchemist_pairing_anchor_edges';

        // 1) computed — harmony (level aus gradiertem weight: 0.6×conf≥0.48 ⇔ conf≥0.8 → ◕)
        DB::table($edges)->whereNull('source')
            ->where('source_slug', 'computed')->where('type', 'aroma')
            ->update([
                'source' => 'computed',
                'axis' => 'harmony',
                'level' => DB::raw('CASE WHEN weight >= 0.48 THEN 2 ELSE 1 END'),
            ]);

        // 2) computed — kontrast (eigene Achse, keine Stufe)
        DB::table($edges)->whereNull('source')
            ->where('source_slug', 'computed')->where('type', 'kontrast')
            ->update(['source' => 'computed', 'axis' => 'contrast', 'level' => null]);

        // 3) book-aroma (source_slug='book', type='aroma') → einzelnes Signal ○
        DB::table($edges)->whereNull('source')
            ->where('type', 'aroma')
            ->update(['source' => 'book', 'axis' => 'harmony', 'level' => 1]);

        // 4) kuratierter Kontrast
        DB::table($edges)->whereNull('source')
            ->where('type', 'kontrast')
            ->update(['source' => 'curated_kontrast', 'axis' => 'contrast', 'level' => null]);

        // 5) erprobt — KI-Dossier (Signatur *Beispielgericht*)
        DB::table($edges)->whereNull('source')
            ->where('type', 'erprobt')->where('evidence', 'like', '%*%')
            ->update(['source' => 'ai_dossier', 'axis' => 'harmony']);

        // 6) erprobt — Rest = aus Markdown-Dossier geparst (Mensch)
        DB::table($edges)->whereNull('source')
            ->where('type', 'erprobt')
            ->update(['source' => 'human_curated', 'axis' => 'harmony']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_pairing_anchor_edges')) {
            return;
        }
        Schema::table('foodalchemist_pairing_anchor_edges', function (Blueprint $table) {
            foreach (['level', 'axis', 'source'] as $col) {
                if (Schema::hasColumn('foodalchemist_pairing_anchor_edges', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
