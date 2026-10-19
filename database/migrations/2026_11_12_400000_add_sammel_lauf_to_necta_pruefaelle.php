<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Necta-Prüffälle, Sammel-Entscheidung über den aktuellen Filter (Kurator/Dominique 19.10.): jede gesammelt getroffene Entscheidung
 * trägt die ID ihres Laufs (wer/wann stehen schon in erledigt_von/erledigt_at). Einzelentscheidungen bleiben NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_necta_pruefaelle') && ! Schema::hasColumn('foodalchemist_necta_pruefaelle', 'sammel_lauf')) {
            Schema::table('foodalchemist_necta_pruefaelle', function (Blueprint $table) {
                $table->uuid('sammel_lauf')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_necta_pruefaelle', 'sammel_lauf')) {
            Schema::table('foodalchemist_necta_pruefaelle', function (Blueprint $table) {
                $table->dropIndex(['sammel_lauf']);
                $table->dropColumn('sammel_lauf');
            });
        }
    }
};
