<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 A1 / Durchstich Schritt 1: Abo = Freigabe einer Sammlung des Master-Teams an einen Mandanten
 * (Kunden-Hauptteam, steht NEBEN dem Master). Eigene Zugriffsregel, keine 77d-Freigabe.
 *
 * - foodalchemist_abos: ein Abo je (Mandant, Sammlung); Kündigung/Zurückziehen setzt status (Schritt 5).
 * - foodalchemist_abo_objekte: berechnete Hülle je Mandant (wie freigabe_objekte), enthält NUR
 *   veröffentlichte Fassungs-Zeilen (Leck-Sperre: nie Arbeitszeilen des Masters samt ek_*).
 * - recipes.ist_fassung / fassung_von_id: Schema für die Fassungen (Schritt 2 füllt sie). Schon hier, damit die
 *   Hülle von Anfang an nur Fassungen enthalten kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_abos')) {
            Schema::create('foodalchemist_abos', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique('fa_abo_uuid');
                $table->unsignedBigInteger('master_team_id')->index('fa_abo_master');
                $table->unsignedBigInteger('mandant_team_id')->index('fa_abo_mandant');   // immer Kunden-Hauptteam (§12 A2)
                $table->unsignedBigInteger('sammlung_id');
                $table->string('status', 16)->default('aktiv');                          // aktiv | gekuendigt | zurueckgezogen
                $table->timestamp('seit')->nullable();
                $table->timestamp('beendet_at')->nullable();
                $table->unsignedBigInteger('angelegt_von')->nullable();
                $table->unsignedBigInteger('beendet_von')->nullable();
                $table->timestamps();
                $table->unique(['mandant_team_id', 'sammlung_id'], 'fa_abo_eindeutig');
            });
        }

        if (! Schema::hasTable('foodalchemist_abo_objekte')) {
            Schema::create('foodalchemist_abo_objekte', function (Blueprint $table) {
                $table->unsignedBigInteger('mandant_team_id');
                $table->string('typ', 16);
                $table->unsignedBigInteger('objekt_id');
                $table->unique(['mandant_team_id', 'typ', 'objekt_id'], 'fa_abo_obj_eindeutig');
                $table->index(['typ', 'objekt_id'], 'fa_abo_obj_objekt');
            });
        }

        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'ist_fassung')) {
                $table->boolean('ist_fassung')->default(false)->index('fa_recipe_ist_fassung');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'fassung_von_id')) {
                $table->unsignedBigInteger('fassung_von_id')->nullable()->index('fa_recipe_fassung_von');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_abo_objekte');
        Schema::dropIfExists('foodalchemist_abos');
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            foreach (['ist_fassung' => 'fa_recipe_ist_fassung', 'fassung_von_id' => 'fa_recipe_fassung_von'] as $spalte => $index) {
                if (Schema::hasColumn('foodalchemist_recipes', $spalte)) {
                    $table->dropIndex($index);
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
