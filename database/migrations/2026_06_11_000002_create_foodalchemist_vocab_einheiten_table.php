<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einheiten-Vokabular (g/kg/Stk/EL/…, mit g-/ml-Defaults für Konvertierung GL-02/GL-11).
 *
 * Globale Stammdaten: team_id NULL = global (D1). Quelle: vocab_einheit.
 * `is_inactive` statt Löschen (Alt-App-Konvention der Vokabulare, V-20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_vocab_einheiten', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->string('slug', 64);
            $table->string('display_de');
            $table->string('dimension', 32)->nullable()->comment('mass|volume|count|length|…');
            $table->decimal('default_in_g', 10, 3)->nullable();
            $table->decimal('default_in_ml', 10, 3)->nullable();
            $table->boolean('is_approximate')->default(false);
            $table->unsignedInteger('sort_order')->default(0)->comment('1-19 häufig, 50+ weitere, 100+ exotisch (Smart-Sort der Alt-App)');
            $table->text('note')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_vocab_einheiten');
    }
};
