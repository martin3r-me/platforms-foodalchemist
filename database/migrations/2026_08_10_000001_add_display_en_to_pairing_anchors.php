<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Original-(Englisch-)Anzeigename der Anker sichern (Composer-Deutsch-Übersetzung, 2026-08-10).
 *
 * Die Inspire-Anker tragen ihren englischen Namen in `display_de` (Import-Altlast). Der Command
 * `foodalchemist:anchors-translate` schreibt Deutsch nach `display_de` und legt das Original hier
 * in `display_en` ab. `display_en` dient gleichzeitig als **Idempotenz-Marker**: nur Zeilen mit
 * `display_en IS NULL` werden (erneut) übersetzt. Additiv, re-run-sicher.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_pairing_anchors')) {
            return;
        }
        Schema::table('foodalchemist_vocab_pairing_anchors', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_vocab_pairing_anchors', 'display_en')) {
                $table->string('display_en', 190)->nullable()->after('display_de')
                    ->comment('Original-Anzeigename (i.d.R. Englisch); gesetzt beim Deutsch-Übersetzen = Idempotenz-Marker');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_pairing_anchors')) {
            return;
        }
        Schema::table('foodalchemist_vocab_pairing_anchors', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_vocab_pairing_anchors', 'display_en')) {
                $table->dropColumn('display_en');
            }
        });
    }
};
