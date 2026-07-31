<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 27 Phase 1 — Step-by-Step-Zubereitung: strukturierte Schritte statt Markdown-Blob.
 *
 * Bis hierher war die Zubereitung EIN Textfeld (`recipes.preparation`, `##`-Phasen +
 * nummerierte Zeilen) und die Schritt-Fotos hingen über eine HANDGETIPPTE Zahl
 * (`recipe_step_photos.schritt_nr`) daran. Das ist eine Konvention, keine Beziehung:
 * Umsortieren im Text verschiebt die Fotos nicht, und ein Foto konnte nur an EINEM
 * Schritt hängen.
 *
 * Ab jetzt: ein Schritt ist eine Zeile (Nummer = `position`, Überschrift = `phase`),
 * Fotos hängen many-to-many am Schritt-DATENSATZ. `recipes.preparation` bleibt als
 * gerenderter Lese-Spiegel bestehen (15 Konsumenten: Produktionsdruck, Prozessanker,
 * Sensorik-source_hash, Embeddings, DataQuality-SQL, MCP) — EINBAHN Schritte → Markdown.
 *
 * BEWUSST NICHT dabei (YAGNI, Spec §3.1): `arbeitszeit_min`, `kerntemp` am Schritt.
 * BEWUSST NICHT gedroppt: `recipe_step_photos.schritt_nr` — bleibt bis der Backfill
 * gegen den echten Bestand verifiziert ist (Drop in einem Folge-Schritt, Spec §3.1).
 *
 * Pivot-Name: `..._recipe_step_photo_links` (NICHT `..._recipe_step_photo` — das
 * unterschiede sich von der bestehenden Tabelle `..._recipe_step_photos` nur durch
 * ein einzelnes `s`). Index-Namen mit `fa_`-Kurzpräfix < 64 Zeichen (MySQL-Grenze).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_steps')) {
            Schema::create('foodalchemist_recipe_steps', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();

                $table->foreignId('recipe_id')
                    ->constrained('foodalchemist_recipes')->cascadeOnDelete();

                // Nummer = Reihenfolge (1-basiert). Reorder = Neunummerierung, nie getippt.
                $table->unsignedSmallInteger('position')->default(0);
                // Abschnitts-Überschrift, erbt das, was heute `## …` im Markdown leistet.
                $table->string('phase', 120)->nullable();
                $table->text('text');                       // Anweisung, markdown-lite (inline)

                $table->timestamps();
                $table->softDeletes();

                $table->index(['recipe_id', 'position'], 'fa_recipe_steps_recipe_pos_idx');
            });
        }

        if (! Schema::hasTable('foodalchemist_recipe_step_photo_links')) {
            // Reiner Pivot: kein uuid, kein softDeletes — Entkoppeln ist eine harte Löschung
            // (das Foto selbst bleibt im Rezept-Pool und ist dann „allgemein").
            Schema::create('foodalchemist_recipe_step_photo_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('step_id')
                    ->constrained('foodalchemist_recipe_steps')->cascadeOnDelete();
                $table->foreignId('photo_id')
                    ->constrained('foodalchemist_recipe_step_photos')->cascadeOnDelete();
                $table->unsignedSmallInteger('position')->default(1);   // Foto-Reihenfolge im Schritt
                $table->timestamps();

                $table->unique(['step_id', 'photo_id'], 'fa_step_photo_link_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_step_photo_links');
        Schema::dropIfExists('foodalchemist_recipe_steps');
    }
};
