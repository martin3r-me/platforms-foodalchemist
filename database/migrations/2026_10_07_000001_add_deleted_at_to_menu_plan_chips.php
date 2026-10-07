<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 59 · Nachtrag: der Prüf-Chip trägt SoftDeletes (Modell-Vertrag aller FA-Models, PolicyTest).
 * Eigene Migration statt Änderung an 2026_10_06_000001: die lief in der fa-pass-Variante unter
 * demselben Namen schon — eine Änderung dort käme auf diesen DBs nie an. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_menu_plan_chips') && ! Schema::hasColumn('foodalchemist_menu_plan_chips', 'deleted_at')) {
            Schema::table('foodalchemist_menu_plan_chips', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_menu_plan_chips', 'deleted_at')) {
            Schema::table('foodalchemist_menu_plan_chips', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
