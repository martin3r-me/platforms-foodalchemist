<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 A · Quellen des Trendradars je Team (Fachpresse, Branchenquellen, Feeds). Abruf-Weg plattform (der
 * Abruf-Command holt Feed/Übersicht, ohne KI) oder mcp (die Plattform ruft nie selbst ab, ein MCP-Client sichtet und
 * reicht ein). Rhythmus je Quelle, Stichwort-Filter je Quelle. Rein additiv: neue Tabelle, down() droppt nur sie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_trend_quellen')) {
            return;
        }
        Schema::create('foodalchemist_trend_quellen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('name', 190);
            $table->string('url', 2048);
            $table->string('feed_url', 2048)->nullable();
            $table->string('art', 32)->default('presse');
            $table->string('feed_typ', 16)->default('manuell');
            $table->string('abruf_weg', 16)->default('plattform');
            $table->string('rhythmus', 16)->default('aus');
            $table->json('schwerpunkt_kategorien')->nullable();
            $table->json('schwerpunkt_sparten')->nullable();
            $table->json('stichworte_ein')->nullable();
            $table->json('stichworte_aus')->nullable();
            $table->boolean('aktiv')->default(true);
            $table->timestamp('letzter_abruf_at')->nullable();
            $table->text('letzter_fehler')->nullable();
            $table->unsignedInteger('fehler_in_folge')->default(0);
            $table->unsignedInteger('gefiltert_letzter_abruf')->default(0);
            $table->unsignedInteger('gefiltert_gesamt')->default(0);
            $table->string('pruef_hinweis', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['abruf_weg', 'rhythmus', 'aktiv'], 'fa_trend_quellen_abruf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_trend_quellen');
    }
};
