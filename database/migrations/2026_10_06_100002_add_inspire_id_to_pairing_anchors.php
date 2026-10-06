<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 60 · P1: Anker-Identität = Inspire-ID.
 *
 * Bisher erkannte der Import einen Anker nur über den Slug, der aus dem englischen Namen gebaut
 * wird; der Originalname stand allein in `anchor_ingredient_map.label_en` (wird in P3 gedroppt).
 * Jetzt trägt jeder Inspire-Anker seine Inspire-UUID (`inspire_id`) und den Index der Quelle
 * (`inspire_ix`). Re-Import wird damit idempotent.
 *
 * Backfill aus `database/data/inspire_anchor_ids.csv` (slug → inspire_id), erzeugt 2026-10-06 aus
 * demo + foodpairing_kompakt.db: 2.622 eindeutig über den Namen, 6 Dubletten-Namen über das
 * Slug-Suffix (apricot_puree/_84, chinese_cabbage/_670, gochujang_1103/_1104).
 *
 * Zugleich fällt `knowledge_document_id` weg: ein Rest des alten Vault-Pairing-Systems
 * (Anker → pairings/<slug>.md), nur noch vom alten knowledge-import verdrahtet. Die Richtung
 * Dossier → Anker liegt jetzt an `knowledge_documents.anchor_id`.
 */
return new class extends Migration
{
    private const T = 'foodalchemist_vocab_pairing_anchors';

    public function up(): void
    {
        Schema::table(self::T, function (Blueprint $table) {
            $table->string('inspire_id', 36)->nullable()->after('slug');
            $table->unsignedInteger('inspire_ix')->nullable()->after('inspire_id');
            $table->unique('inspire_id', 'fa_vpa_inspire_id_unique');
        });

        $datei = __DIR__.'/../data/inspire_anchor_ids.csv';
        if (is_file($datei)) {
            $fh = fopen($datei, 'r');
            $kopf = fgetcsv($fh);
            while (($zeile = fgetcsv($fh)) !== false) {
                $r = array_combine($kopf, $zeile);
                DB::table(self::T)->where('slug', $r['slug'])->whereNull('inspire_id')
                    ->update(['inspire_id' => $r['inspire_id'], 'inspire_ix' => (int) $r['inspire_ix']]);
            }
            fclose($fh);
        }

        if (Schema::hasColumn(self::T, 'knowledge_document_id')) {
            // MySQL kennt den FK unter seinem Namen; SQLite (Tests) nur über die Spalte.
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            Schema::table(self::T, function (Blueprint $table) use ($sqlite) {
                $table->dropForeign($sqlite ? ['knowledge_document_id'] : 'fa_vpa_kdoc_fk');
            });
            Schema::table(self::T, function (Blueprint $table) {
                $table->dropColumn('knowledge_document_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table(self::T, function (Blueprint $table) {
            $table->foreignId('knowledge_document_id')->nullable()->after('display_de')
                ->constrained('foodalchemist_knowledge_documents', 'id', 'fa_vpa_kdoc_fk')->nullOnDelete();
        });
        Schema::table(self::T, function (Blueprint $table) {
            $table->dropUnique('fa_vpa_inspire_id_unique');
            $table->dropColumn(['inspire_id', 'inspire_ix']);
        });
    }
};
