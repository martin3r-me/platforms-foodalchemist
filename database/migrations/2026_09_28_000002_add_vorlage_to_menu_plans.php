<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 57 · Paket 7 (E10): Vorlage für Betriebe — verknüpfte Kopie je Betrieb im selben Team.
 *
 * - menu_plans.is_template: dieser Plan ist Vorlage (zentral gepflegt).
 * - menu_plans.source_plan_id: die Kopie eines Betriebs zeigt auf ihre Vorlage (kein FK: die
 *   App hält die Verknüpfung, eine gelöschte Vorlage macht die Kopie nur „frei“).
 * - menu_plans.source_synced_at: letzter Abgleich mit der Vorlage — Änderungen der Vorlage
 *   danach gelten als „aus der Vorlage“, sonst als lokale Abweichung.
 * - menu_plan_lines.source_line_id: Linie der Kopie ↔ Linie der Vorlage (für den Zellen-Abgleich).
 *
 * Additiv und nullable: bestehende Pläne sind weder Vorlage noch Kopie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('opening_days');
            $table->unsignedBigInteger('source_plan_id')->nullable()->after('is_template');
            $table->timestamp('source_synced_at')->nullable()->after('source_plan_id');
            $table->index('source_plan_id', 'fa_menuplan_source_idx');
        });

        Schema::table('foodalchemist_menu_plan_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('source_line_id')->nullable()->after('meal');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            $table->dropIndex('fa_menuplan_source_idx');
            $table->dropColumn(['is_template', 'source_plan_id', 'source_synced_at']);
        });

        Schema::table('foodalchemist_menu_plan_lines', function (Blueprint $table) {
            $table->dropColumn('source_line_id');
        });
    }
};
