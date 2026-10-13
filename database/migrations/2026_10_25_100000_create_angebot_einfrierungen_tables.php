<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 B3 / Durchstich Schritt 4: eingefrorenes Angebot. Beim Wechsel auf „angebot“ hält ein Snapshot je Gericht mit Abo-Fassung im Baum
 * fest, WAS es war: Fassungs-IDs, EK mit Status „vollständig/unvollständig“ (nie 0 € als Preis), VK, Allergenbild. `sales_recipe_id` am Block bleibt
 * unberührt (zeigt auf ein eigenes Gericht, nullOnDelete würde den Stand still löschen). Die Zeilen tragen ihre eigenen Werte, es gibt keine FK auf Rezepte.
 * Eine AKTIVE Einfrierung je Angebot (verworfen_at NULL); eine verworfene bleibt als Historie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_angebot_einfrierungen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('angebot_id')->index();
            $table->string('status_beim_einfrieren', 16);
            $table->timestamp('eingefroren_at');
            $table->unsignedBigInteger('eingefroren_von')->nullable();
            $table->timestamp('verworfen_at')->nullable();
            $table->unsignedBigInteger('verworfen_von')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_angebot_einfrier_zeilen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('einfrierung_id')->index();
            $table->unsignedBigInteger('recipe_id')->comment('Gericht der Position (ohne FK, der Stand bleibt auch ohne das Rezept)');
            $table->string('name', 255);
            $table->json('fassungen')->comment('[{fassung_id, fassung_von_id, fassung_nr, allergene}] — Fassungs-ID, nicht die Familie; Allergenbild je Fassung für den Diff');
            $table->decimal('ek_summe', 12, 4)->nullable()->comment('bei unvollständig = Teilsumme');
            $table->decimal('ek_per_kg', 12, 4)->nullable();
            $table->unsignedInteger('n_priced')->nullable();
            $table->unsignedInteger('n_total')->nullable();
            $table->boolean('vollstaendig')->default(false);
            $table->string('grund', 40)->nullable();
            $table->json('fehlende')->nullable();
            $table->decimal('vk', 12, 4)->nullable()->comment('NULL wenn der EK unvollständig ist, nie 0');
            $table->json('allergene')->comment('allergen_* und additive_* des Gerichts zum Zeitpunkt des Einfrierens');
            $table->timestamp('kosten_stand_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_angebot_einfrier_zeilen');
        Schema::dropIfExists('foodalchemist_angebot_einfrierungen');
    }
};
