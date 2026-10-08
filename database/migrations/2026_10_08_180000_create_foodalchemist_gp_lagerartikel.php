<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 74 · Lagerartikel: Grundvorrat (Gewürze, Öle, Salz …) je Betrieb und Grundprodukt — wird nicht über den
 * Rezeptbedarf bestellt, sondern über Mindestbestand → Auffüllen auf Sollbestand. Mengen in Basiseinheit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_gp_lagerartikel')) {
            return;
        }
        Schema::create('foodalchemist_gp_lagerartikel', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('gp_id')->index();
            $table->boolean('ist_lagerartikel')->default(true);
            $table->decimal('mindestbestand', 14, 4)->nullable();
            $table->decimal('sollbestand', 14, 4)->nullable();
            $table->string('base_unit', 8)->default('g');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'gp_id'], 'fa_gp_lagerartikel_team_gp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_lagerartikel');
    }
};
