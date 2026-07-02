<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zentrales Canvas-Modul (Dominique 2026-06-17): EINE generische Canvas-Mechanik für
 * alle Ebenen — food_dna (Team) · foodbook · concept · angebot — statt 4× pro Modul.
 * „Thematisch alles beisammen", Entitäten verdrahten ihren Canvas.
 *
 * Generischer SPEICHER (canvases + canvas_entries) mit FESTEN Templates je canvas_type
 * (Feld-Definitionen in CanvasService::TEMPLATES — semantische Felder bleiben kuratiert,
 * nicht frei zusammenklickbar). Repeatable-Felder (z. B. Geschmackswelten) = mehrere
 * Entries gleichen field_key mit position + meta-JSON (Sub-Felder claim/beschreibung).
 *
 * Inspiration: Martins planner_project_canvases → _blocks → _entries.
 * 07 §7: nullable+index statt cross-Modul-FK (owner polymorph), idempotent, engine-agnostisch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_canvases')) {
            Schema::create('foodalchemist_canvases', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('canvas_type', 24)->index();          // food_dna | foodbook | concept | angebot
                $table->string('owner_type', 24)->nullable();        // team | foodbook | concept | angebot
                $table->unsignedBigInteger('owner_id')->nullable();  // team_id bzw. Entitäts-ID
                $table->string('status', 16)->default('draft');
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['canvas_type', 'owner_type', 'owner_id'], 'fa_canvas_type_owner_unique');
                $table->index(['owner_type', 'owner_id']);
            });
        }

        if (! Schema::hasTable('foodalchemist_canvas_entries')) {
            Schema::create('foodalchemist_canvas_entries', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('canvas_id')->index();
                $table->string('field_key', 48);
                $table->integer('position')->default(0);
                $table->text('value')->nullable();
                $table->json('meta')->nullable();                    // repeatable: {claim, beschreibung, …}
                $table->timestamps();
                $table->softDeletes();
                $table->index(['canvas_id', 'field_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_canvas_entries');
        Schema::dropIfExists('foodalchemist_canvases');
    }
};
