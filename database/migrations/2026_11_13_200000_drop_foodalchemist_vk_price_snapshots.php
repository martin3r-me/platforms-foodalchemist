<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 85 F5 (Dominique 19.10.): die separate VK-Freigabe entfällt. Kundenschutz ist das Einfrieren der Preise beim
 * Veröffentlichen eines Links (`presentation_snapshot_json` / `foodalchemist_presentations.snapshot_json`); die Drift zeigt
 * der AusgabeDriftService. io vor dem Drop lesend geprüft (Kurator 19.10.): 0 Zeilen, kein Backup nötig.
 * Der Rückweg legt die leere Tabelle wieder an (Schema wie 2026_07_19_000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('foodalchemist_vk_price_snapshots');
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_vk_price_snapshots')) {
            return;
        }
        Schema::create('foodalchemist_vk_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('presentation_id')->constrained('foodalchemist_recipe_presentations')->cascadeOnDelete();
            $table->decimal('sales_net', 12, 2)->nullable();
            $table->decimal('sales_gross', 12, 2)->nullable();
            $table->timestamp('released_at');
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['presentation_id', 'released_at']);
        });
    }
};
