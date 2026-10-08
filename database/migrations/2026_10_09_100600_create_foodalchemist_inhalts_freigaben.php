<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 77d · Inhalte für Standorte freigeben. Ein Unter-Team übernimmt entweder alles vom Oberteam
 * (Schnellstart-Haken, keine Zeile = an → Bestand verliert nichts) oder sieht nur, was ihm freigegeben ist:
 * Sammlungen (Basisrezepte, Gerichte, Konzepte, Formate) und fertige Ausgaben (Foodbook, Speiseplan,
 * Speisekarte). Die Hülle der Abhängigkeiten wird gespeichert (`freigabe_objekte`). Eigene Kopie merkt
 * sich ihr Original. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_inhalte')) {
            Schema::create('foodalchemist_team_inhalte', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id')->unique('fa_tin_team');      // das Unter-Team
                $table->boolean('erbt_alles')->default(true);                      // false = nur Freigegebenes
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('foodalchemist_sammlungen')) {
            Schema::create('foodalchemist_sammlungen', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique('fa_sam_uuid');
                $table->unsignedBigInteger('team_id')->index('fa_sam_team');
                $table->string('name', 160);
                $table->text('beschreibung')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasTable('foodalchemist_sammlung_objekte')) {
            Schema::create('foodalchemist_sammlung_objekte', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sammlung_id');
                $table->string('typ', 16);                                          // recipe | concept | format
                $table->unsignedBigInteger('objekt_id');
                $table->timestamps();
                $table->unique(['sammlung_id', 'typ', 'objekt_id'], 'fa_samo_eindeutig');
            });
        }
        if (! Schema::hasTable('foodalchemist_ausgabe_freigaben')) {
            Schema::create('foodalchemist_ausgabe_freigaben', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique('fa_afr_uuid');
                $table->unsignedBigInteger('team_id')->index('fa_afr_team');        // Besitzer (Oberteam)
                $table->string('ausgabe_typ', 16);                                  // sammlung | foodbook | speiseplan | speisekarte
                $table->unsignedBigInteger('ausgabe_id');
                $table->unsignedBigInteger('empfaenger_team_id')->index('fa_afr_empf');
                $table->unsignedBigInteger('freigegeben_von')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['ausgabe_typ', 'ausgabe_id', 'empfaenger_team_id'], 'fa_afr_eindeutig');
            });
        }
        if (! Schema::hasTable('foodalchemist_freigabe_objekte')) {
            Schema::create('foodalchemist_freigabe_objekte', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id');                              // Empfänger
                $table->string('typ', 16);
                $table->unsignedBigInteger('objekt_id');
                $table->unique(['team_id', 'typ', 'objekt_id'], 'fa_fro_eindeutig');
            });
        }
        foreach (['foodalchemist_recipes', 'foodalchemist_concepts', 'foodalchemist_formats'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'kopie_von_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('kopie_von_id')->nullable();         // eigene Kopie: Original (gleiche Tabelle)
                    $table->timestamp('kopie_stand_at')->nullable();                // Stand des Originals beim Kopieren
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_recipes', 'foodalchemist_concepts', 'foodalchemist_formats'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'kopie_von_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['kopie_von_id', 'kopie_stand_at']));
            }
        }
        foreach (['foodalchemist_freigabe_objekte', 'foodalchemist_ausgabe_freigaben', 'foodalchemist_sammlung_objekte', 'foodalchemist_sammlungen', 'foodalchemist_team_inhalte'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
