<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1-05 + M1-07: Team-Einstellungen — genau EINE Zeile je Team (kein Hierarchie-Scope:
 * Einkaufs- und Kalkulations-Entscheide trifft jedes Team für sich, D1-Overlay-Gedanke).
 *
 * - lead_la_strategie / lead_la_prioritaeten / ausweich_kette_anzeigen → M1-05 (V-27, speist M3-06)
 * - cooking_loss_defaults (je GP-Klasse/Warengruppe, %) · mwst_defaults · rundungsregeln → M1-07 (GL-02)
 *
 * Fehlende Zeile ⇒ Code-Defaults aus TeamSettingsService (kein Pflicht-Seeding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_team_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->unique()->constrained('teams')->cascadeOnDelete();

            // ── M1-05: Lead-LA-Wahl (V-27)
            $table->string('lead_la_strategie', 32)->default('stamm_lieferant'); // V-27: Default = Ist-Verhalten (GL-03 §6)
            $table->json('lead_la_prioritaeten')->nullable()->comment('geordnete supplier_ids für Strategie prioritaets_kette');
            $table->boolean('ausweich_kette_anzeigen')->default(false);

            // ── M1-07: Kalkulations-Defaults (GL-02)
            $table->json('cooking_loss_defaults')->nullable()->comment('{commodity_group_code|*: prozent}');
            $table->json('mwst_defaults')->nullable()->comment('{regulaer, ermaessigt, default_satz}');
            $table->json('rundungsregeln')->nullable()->comment('{nachkommastellen, modus: kaufmaennisch|auf|ab}');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_settings');
    }
};
