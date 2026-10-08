<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 70 · Etiketten: Etikett-Vorlagen je Team (Format, Typ intern|verkauf, Felder + Datums-Modus,
 * Gestaltung über Betrieb/Design) und Haltbarkeits-Vorschläge am Rezept. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_label_templates')) {
            Schema::create('foodalchemist_label_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name', 120);
                $table->string('format', 16)->default('a4_24');            // a4_24 | a4_40 | rolle_62 | dymo_54
                $table->string('typ', 16)->default('intern');               // intern | verkauf
                $table->unsignedBigInteger('outlet_id')->nullable();        // Logo + Design des Betriebs
                $table->string('presentation_design', 64)->nullable();      // Slug | design:{id}; leer = Design des Betriebs
                $table->json('felder')->nullable();                         // [{key, an, modus}] in Reihenfolge
                $table->string('allergen_darstellung', 16)->default('beides'); // kuerzel | klartext | beides
                $table->string('schriftgroesse', 4)->default('m');          // s | m | l
                $table->boolean('datum_gross')->default(true);              // „verbrauchen bis" hervorheben
                $table->boolean('zeige_logo')->default(true);
                $table->string('fusstext', 200)->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'shelf_life_chilled_days')) {
                $table->unsignedSmallInteger('shelf_life_chilled_days')->nullable();
                $table->unsignedSmallInteger('shelf_life_frozen_days')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['shelf_life_chilled_days', 'shelf_life_frozen_days']);
        });
        Schema::dropIfExists('foodalchemist_label_templates');
    }
};
