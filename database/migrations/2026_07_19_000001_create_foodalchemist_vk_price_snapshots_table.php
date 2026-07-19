<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2.5 — Veröffentlichter-VK-Snapshot-Layer. Trennt die interne, LIVE gerechnete
 * Marge (foodalchemist_recipe_presentations.sales_net, bei jedem Edit neu) vom
 * FREIGEGEBENEN Kundenpreis: ein Snapshot friert den zum Freigabe-Zeitpunkt
 * gültigen VK ein. Kopiervorlage: foodbook_kapitel.snapshot_at (Versand-Freeze).
 * Live-recomputePreise bleibt unberührt — kein stiller Kunden-Preissprung.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_vk_price_snapshots');
    }
};
