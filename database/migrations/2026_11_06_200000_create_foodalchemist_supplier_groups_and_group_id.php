<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieferanten-Gruppen (Dominique 2026-10-16): gepflegte Taxonomie der Lieferanten (Lebensmittel-Großhandel, Frische & Fachhandel, Getränke,
 * Non-Food & Verbrauch, Verleih & Eventausstattung, Sonstiges). Eine Hauptgruppe je Lieferant. `suppliers.branch` bleibt der Necta-Rohwert
 * (Freitext), die Gruppe ist die gepflegte Einordnung; `group_source` hält fest, ob sie vom Vorschlags-Kommando (regel) oder von Hand kommt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_supplier_groups')) {
            Schema::create('foodalchemist_supplier_groups', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index()->comment('Basis-Katalog: NULL oder Master; Teams duerfen ergaenzen');
                $table->string('code', 24);
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'code'], 'fa_supplier_groups_team_code_unique');
            });
        }

        if (! Schema::hasColumn('foodalchemist_suppliers', 'group_id')) {
            Schema::table('foodalchemist_suppliers', function (Blueprint $table) {
                $table->foreignId('group_id')->nullable()->after('branch')->constrained('foodalchemist_supplier_groups')->nullOnDelete();
                $table->string('group_source', 16)->nullable()->after('group_id')->comment('regel | manuell — manuell wird vom Vorschlag nie ueberschrieben');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_suppliers', 'group_id')) {
            Schema::table('foodalchemist_suppliers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('group_id');
                $table->dropColumn('group_source');
            });
        }
        Schema::dropIfExists('foodalchemist_supplier_groups');
    }
};
