<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 · Schritt 6 (§7): DNA feldweise.
 *
 *  - `canvas_entries.is_binding`: „verbindlich“ je Feld (Skalar = die eine Zeile) bzw. je Eintrag
 *    (Repeatable). Gesetzt wird es nur im Oberteam; ein Unter-Team überschreibt verbindliche Felder nicht.
 *  - `canvas_templates`: pflegbare ZUSATZ-Felder je canvas_type nach dem Muster der Brief-Vorlagen
 *    (Variante A, Spec 64 §1b: die Kern-Felder bleiben in CanvasService::TEMPLATES, die Tabelle trägt nur
 *    Ergänzungen). Oberteam-eigen (team_id = Oberteam), Unter-Teams sehen sie über die Team-Kette.
 *    Start leer — additiv, keine Datenänderung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_canvas_entries', 'is_binding')) {
            Schema::table('foodalchemist_canvas_entries', function (Blueprint $table) {
                $table->boolean('is_binding')->default(false)->after('meta');
            });
        }

        if (! Schema::hasTable('foodalchemist_canvas_templates')) {
            Schema::create('foodalchemist_canvas_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('canvas_type', 24);                    // food_dna | kunde_dna | foodbook | concept | angebot
                $table->string('field_key', 48);
                $table->string('label');
                $table->string('group_name', 64)->nullable();         // Feldgruppe = Reiter im Board
                $table->string('type', 24)->default('longtext');     // text | longtext | repeatable
                $table->json('sub')->nullable();                      // repeatable: {claim: 'Claim', …}
                $table->unsignedInteger('position')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'canvas_type', 'active'], 'fa_canvas_tpl_lookup');
                $table->unique(['team_id', 'canvas_type', 'field_key'], 'fa_canvas_tpl_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_canvas_templates');
        if (Schema::hasColumn('foodalchemist_canvas_entries', 'is_binding')) {
            Schema::table('foodalchemist_canvas_entries', function (Blueprint $table) {
                $table->dropColumn('is_binding');
            });
        }
    }
};
