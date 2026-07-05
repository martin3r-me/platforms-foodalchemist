<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B1 (Concepter-Editor-Umbau): macht aus concept_slots eine polymorphe Positions-/Block-Liste
 * — der Aufbau-Tab wird ein zeilenbasierter Editor (Gericht/Basisrezept-Zeilen + Struktur-Blöcke
 * Text/Leerzeile/Header±Preis), aus dem man Pakete bildet.
 *
 * `type` ∈ gericht | basisrezept | paket | text | spacer | header | header_preis.
 * Backfill: package_id → 'paket', sales_recipe_id → 'gericht' (Alt-Slots hielten nur VK-Gerichte).
 * Struktur-Felder analog Foodbook-Block (text_inhalt/price_value/preis_basis/hoehe/ebene).
 * Neue Tabelle concept_slot_staffel = Spiegel von foodbook_block_staffel (header_preis-Staffel).
 *
 * Additive Spalten + Backfill via Query-Builder → cross-DB-sicher (vgl. CLAUDE.md Migrations-Fallen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'type')) {
                $table->string('type', 24)->default('gericht')->after('concept_id');
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'level')) {
                $table->integer('level')->default(0)->after('position');
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'text_content')) {
                $table->text('text_content')->nullable()->after('title');
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'price_value')) {
                $table->decimal('price_value', 10, 2)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'price_basis')) {
                $table->string('price_basis', 12)->nullable()->after('price_value'); // person|pauschal|staffel
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'height')) {
                $table->string('height', 12)->nullable()->after('price_basis'); // spacer: klein|mittel|gross
            }
        });

        // Backfill type aus der bisherigen Befüllung (genau eines war gesetzt; sonst Default 'gericht').
        DB::table('foodalchemist_concept_slots')->whereNotNull('package_id')->update(['type' => 'paket']);
        DB::table('foodalchemist_concept_slots')->whereNull('package_id')->whereNotNull('sales_recipe_id')->update(['type' => 'gericht']);

        if (! Schema::hasTable('foodalchemist_concept_slot_staffel')) {
            Schema::create('foodalchemist_concept_slot_staffel', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('slot_id')->constrained('foodalchemist_concept_slots')->cascadeOnDelete();
                $table->integer('position')->default(0);
                $table->unsignedInteger('min_persons')->default(1);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_concept_slot_staffel');

        // SQLite kann mehrere Spalten nur in EINEM dropColumn-Aufruf droppen (sonst FAIL).
        $cols = array_values(array_filter(
            ['type', 'level', 'text_content', 'price_value', 'price_basis', 'height'],
            fn ($c) => Schema::hasColumn('foodalchemist_concept_slots', $c)
        ));
        if ($cols !== []) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) use ($cols) {
                $table->dropColumn($cols);
            });
        }
    }
};
