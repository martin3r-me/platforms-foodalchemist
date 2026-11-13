<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Präsentation Stufe 1: eigene Schriften je Team (Hausschrift/Kunden-CI) für Präsentations-Designs,
 * plus freie Präsentationsbilder je Angebot (für image-Blöcke mit quelle=angebot_N).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_presentation_fonts')) {
            Schema::create('foodalchemist_presentation_fonts', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->nullable()->unique();
                $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $t->string('family', 60);
                $t->unsignedSmallInteger('weight')->default(400);
                $t->string('style', 10)->default('normal');
                $t->string('format', 10);
                $t->unsignedBigInteger('context_file_id')->nullable();
                $t->string('path')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['team_id', 'family'], 'fa_pres_fonts_team_family');
            });
        }
        if (! Schema::hasColumn('foodalchemist_offers', 'presentation_images_json')) {
            Schema::table('foodalchemist_offers', function (Blueprint $t) {
                $t->json('presentation_images_json')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_offers', 'presentation_images_json')) {
            Schema::table('foodalchemist_offers', function (Blueprint $t) {
                $t->dropColumn('presentation_images_json');
            });
        }
        Schema::dropIfExists('foodalchemist_presentation_fonts');
    }
};
