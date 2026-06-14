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
 * Backfill: paket_id → 'paket', vk_recipe_id → 'gericht' (Alt-Slots hielten nur VK-Gerichte).
 * Struktur-Felder analog Foodbook-Block (text_inhalt/preis_wert/preis_basis/hoehe/ebene).
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
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'ebene')) {
                $table->integer('ebene')->default(0)->after('position');
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'text_inhalt')) {
                $table->text('text_inhalt')->nullable()->after('titel');
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'preis_wert')) {
                $table->decimal('preis_wert', 10, 2)->nullable()->after('menge');
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'preis_basis')) {
                $table->string('preis_basis', 12)->nullable()->after('preis_wert'); // person|pauschal|staffel
            }
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'hoehe')) {
                $table->string('hoehe', 12)->nullable()->after('preis_basis'); // spacer: klein|mittel|gross
            }
        });

        // Backfill type aus der bisherigen Befüllung (genau eines war gesetzt; sonst Default 'gericht').
        DB::table('foodalchemist_concept_slots')->whereNotNull('paket_id')->update(['type' => 'paket']);
        DB::table('foodalchemist_concept_slots')->whereNull('paket_id')->whereNotNull('vk_recipe_id')->update(['type' => 'gericht']);

        if (! Schema::hasTable('foodalchemist_concept_slot_staffel')) {
            Schema::create('foodalchemist_concept_slot_staffel', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('slot_id')->constrained('foodalchemist_concept_slots')->cascadeOnDelete();
                $table->integer('position')->default(0);
                $table->unsignedInteger('min_personen')->default(1);
                $table->decimal('preis', 10, 2)->default(0);
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
            ['type', 'ebene', 'text_inhalt', 'preis_wert', 'preis_basis', 'hoehe'],
            fn ($c) => Schema::hasColumn('foodalchemist_concept_slots', $c)
        ));
        if ($cols !== []) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) use ($cols) {
                $table->dropColumn($cols);
            });
        }
    }
};
