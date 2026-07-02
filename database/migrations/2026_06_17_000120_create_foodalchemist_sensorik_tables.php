<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sensorik-Spinnennetz → SaaS-Spiegel (EINBAHN WaWi→App via Skript 246).
 * Spiegelt die im Vault (wawi_1494.sqlite) kuratierten Sensorik-Schichten ins Modul:
 *   - vocab_textur                 = Textur-Vokabular (Layer 3)
 *   - gp_geschmack_vektor          = 7 Grundgeschmäcker je GP (Layer 2)
 *   - gp_textur                    = Textur-Mapping je GP (Layer 3, multi-value)
 *   - vocab_prozess_sensorik_delta = Zubereitungs-Delta je Prozess-Anker (Layer 4)
 *
 * GP-Bezug = foodalchemist_gps.id (Sync löst WaWi gp_v2_id → gp_key → FA gp.id auf).
 * 07 §7: keine CHECK/Enums in DB; nullable+index statt cross-Modul-FK; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_textur')) {
            Schema::create('foodalchemist_vocab_textur', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 48)->unique();
                $table->string('display_de', 96);
                $table->integer('sort_order')->default(0);
                $table->string('note', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('foodalchemist_vocab_prozess_sensorik_delta')) {
            Schema::create('foodalchemist_vocab_prozess_sensorik_delta', function (Blueprint $table) {
                $table->id();
                $table->string('anker_slug', 48)->unique();
                foreach (['suess', 'salzig', 'sauer', 'bitter', 'umami', 'fettig', 'scharf'] as $d) {
                    $table->decimal('d_' . $d, 4, 2)->default(0);
                }
                $table->string('textur_slugs', 255)->nullable();   // comma-sep vocab_textur-slugs
                $table->string('note', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('foodalchemist_gp_geschmack_vektor')) {
            Schema::create('foodalchemist_gp_geschmack_vektor', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gp_id')->unique();
                foreach (['suess', 'salzig', 'sauer', 'bitter', 'umami', 'fettig', 'scharf'] as $d) {
                    $table->decimal($d, 4, 2)->default(0);
                }
                $table->string('quelle', 16)->nullable();           // rule | gemini | manual
                $table->decimal('ai_confidence', 4, 2)->nullable();
                $table->text('ai_begruendung')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('foodalchemist_gp_textur')) {
            Schema::create('foodalchemist_gp_textur', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gp_id')->index();
                $table->unsignedBigInteger('textur_vocab_id')->index();
                $table->decimal('intensitaet', 4, 2)->default(1);
                $table->string('quelle', 16)->nullable();
                $table->timestamps();
                $table->unique(['gp_id', 'textur_vocab_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_textur');
        Schema::dropIfExists('foodalchemist_gp_geschmack_vektor');
        Schema::dropIfExists('foodalchemist_vocab_prozess_sensorik_delta');
        Schema::dropIfExists('foodalchemist_vocab_textur');
    }
};
