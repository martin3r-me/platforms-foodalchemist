<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Umbau-Spec Darreichungen Phase 3: Servierform-Vokabular (Spiegel von
 * WaWi vocab_servierform). Scharnier-Vokabel: dieselben Formen hängen an
 * recipe_darreichungen UND (Phase 4) an Concepts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_serving_forms')) {
            return;
        }
        Schema::create('foodalchemist_serving_forms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->integer('sort_order')->default(100);
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_serving_forms');
    }
};
