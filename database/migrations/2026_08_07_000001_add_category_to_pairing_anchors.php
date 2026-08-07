<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strukturierte Kategorie auf den Pairing-Ankern (Composer-Picker, 2026-08-07).
 *
 * Bisher landete die Foodpairing-Inspire-Kategorie nur als Freitext in `note`
 * (Format „Category / Subcategory", s. InspireImportService). Der Composer braucht
 * aber eine QUERYBARE Kategorie für den Dropdown-Filter (wie `commodity_group_code`
 * beim GP-Picker). Diese Migration ergänzt `category`/`subcategory` + backfillt sie
 * idempotent aus `note` für die Inspire-Anker.
 *
 * Portabel (SQLite + MySQL): der Split läuft in PHP, nicht via SUBSTRING_INDEX.
 * Idempotent: nur Zeilen mit `category IS NULL` werden befüllt (re-run-sicher).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_pairing_anchors')) {
            return;
        }

        Schema::table('foodalchemist_vocab_pairing_anchors', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_vocab_pairing_anchors', 'category')) {
                $table->string('category', 48)->nullable()->after('note')->index()
                    ->comment('Kategorie (Composer-Filter) — Backfill aus note / Inspire-Import');
            }
            if (! Schema::hasColumn('foodalchemist_vocab_pairing_anchors', 'subcategory')) {
                $table->string('subcategory', 64)->nullable()->after('category');
            }
        });

        // Backfill aus dem Freitext-`note` („Category / Subcategory") — nur Inspire-Anker,
        // nur wo category noch leer ist.
        $anchors = DB::table('foodalchemist_vocab_pairing_anchors')
            ->where('source_path', 'foodpairing_inspire')
            ->whereNull('category')
            ->whereNotNull('note')
            ->get(['id', 'note']);

        foreach ($anchors as $a) {
            $note = trim((string) $a->note);
            if ($note === '') {
                continue;
            }
            if (str_contains($note, ' / ')) {
                [$cat, $sub] = array_map('trim', explode(' / ', $note, 2));
            } else {
                $cat = $note;
                $sub = '';
            }
            if ($cat === '') {
                continue;
            }
            DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $a->id)->update([
                'category' => $cat,
                'subcategory' => $sub !== '' ? $sub : null,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_pairing_anchors')) {
            return;
        }
        Schema::table('foodalchemist_vocab_pairing_anchors', function (Blueprint $table) {
            foreach (['subcategory', 'category'] as $col) {
                if (Schema::hasColumn('foodalchemist_vocab_pairing_anchors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
