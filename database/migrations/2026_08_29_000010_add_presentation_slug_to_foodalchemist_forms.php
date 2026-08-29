<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 — Eigener Link-Name (Slug) für die öffentliche Präsentation. Optional, pro
 * Ausgabeform eindeutig (die Auflösung kennt den Typ aus der URL /p/{type}/{ref}); der
 * Zufalls-Token bleibt als Fallback. Additiv/nullable — Bestandslinks unberührt.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'foodalchemist_foodbooks',
        'foodalchemist_menu_cards',
        'foodalchemist_menu_plans',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'presentation_slug')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->string('presentation_slug')->nullable()->after('presentation_token');
                $t->unique('presentation_slug', $table . '_pres_slug_unique');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'presentation_slug')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table . '_pres_slug_unique');
                $t->dropColumn('presentation_slug');
            });
        }
    }
};
