<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1-04 / D-1: Produktions-Taxonomie — Hauptgruppen + Kategorien (Quelle: Skript-204-Stand,
 * recipe_hauptgruppen 30 / recipe_kategorien 186 [Roadmap nannte 139 — Quellstand gewachsen]).
 *
 * Die M4-Browser-Bäume (Basisrezepte) lesen hieraus. `recipe_count` ist ABGELEITET
 * (Delete-Guard AT-D1-02) — gezählt zur Laufzeit, sobald foodalchemist_recipes existiert (M4-01).
 * Offene Weiche 02_DATENMODELL E.1 (v2-Taxonomie) unverändert — das hier ist die gepflegte Alt-Welt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_recipe_main_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('recipe_hauptgruppen.hauptgruppe_id');
            $table->string('code', 64);
            $table->string('bezeichnung');
            $table->string('bereich', 64)->nullable()->comment('Quelle beschreibung: KUECHE_HERZHAFT|KUECHE_SUESS|…');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'code']);
        });

        Schema::create('foodalchemist_recipe_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('recipe_kategorien.kategorie_id');
            $table->foreignId('main_group_id')->constrained('foodalchemist_recipe_main_groups')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('bezeichnung');
            $table->string('technik')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('legacy_excel_kat')->nullable();
            $table->unsignedBigInteger('legacy_category_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'main_group_id', 'bezeichnung']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_categories');
        Schema::dropIfExists('foodalchemist_recipe_main_groups');
    }
};
