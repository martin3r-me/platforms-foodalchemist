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
 *
 * Idempotent: `hasTable`/`hasIndex` schützen vor halb-gelaufener Migration (MySQL hat kein
 * transaktionales DDL — ein Fehler beim ALTER nach erfolgreichem CREATE hinterlässt die Tabelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_main_groups')) {
            Schema::create('foodalchemist_recipe_main_groups', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('recipe_hauptgruppen.hauptgruppe_id');
                $table->string('code', 64);
                $table->string('label');
                $table->string('bereich', 64)->nullable()->comment('Quelle description: KUECHE_HERZHAFT|KUECHE_SUESS|…');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'code']);
            });
        }

        if (! Schema::hasTable('foodalchemist_recipe_categories')) {
            Schema::create('foodalchemist_recipe_categories', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('recipe_kategorien.category_id');
                $table->foreignId('main_group_id')->constrained('foodalchemist_recipe_main_groups')->cascadeOnDelete();
                $table->string('code', 64);
                $table->string('label');
                $table->string('technik')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('legacy_excel_kat')->nullable();
                $table->unsignedBigInteger('legacy_category_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Unique-Index separat: deckt sowohl Frisch-Anlage als auch halb-gelaufenen Vorlauf ab
        // (Tabelle vom failed run da, aber ALTER für Unique war es, was scheiterte).
        if (! $this->hasIndex('foodalchemist_recipe_categories', 'fa_recipe_cats_team_grp_bez_unique')) {
            Schema::table('foodalchemist_recipe_categories', function (Blueprint $table) {
                $table->unique(['team_id', 'main_group_id', 'label'], 'fa_recipe_cats_team_grp_bez_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_categories');
        Schema::dropIfExists('foodalchemist_recipe_main_groups');
    }

    private function hasIndex(string $tabelle, string $indexName): bool
    {
        return collect(Schema::getIndexes($tabelle))
            ->pluck('name')
            ->contains($indexName);
    }
};
