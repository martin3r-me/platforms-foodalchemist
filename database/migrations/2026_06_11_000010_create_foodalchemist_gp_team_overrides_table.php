<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 (Eltern→Kinder) + V-27: Einkaufs-Overlay pro Kind-Team auf geerbte Katalog-GPs.
 *
 * Ein Kind-Team kann — ohne den Eltern-Katalog zu kopieren oder zu ändern —
 *   (a) einen eigenen Lead-LA wählen (lead_la_override_id) und
 *   (b) einzelne LAs für sich sperren (blocked_supplier_item_ids, JSON-Array von IDs):
 *       „LA A will ich nicht, aber B geht."
 *
 * Auflösung zur Laufzeit (LeadLaService, Etappe 2):
 *   Lead = COALESCE(Override des Teams, GP-Lead) unter Ausschluss gesperrter LAs,
 *   danach reguläre GL-03-Kette als Fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_gp_team_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('gp_id')->constrained('foodalchemist_gps')->cascadeOnDelete();

            $table->foreignId('lead_la_override_id')->nullable()
                ->constrained('foodalchemist_supplier_items')->nullOnDelete();
            $table->json('blocked_supplier_item_ids')->nullable();

            $table->text('notiz')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'gp_id'], 'fa_gp_team_override_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_team_overrides');
    }
};
