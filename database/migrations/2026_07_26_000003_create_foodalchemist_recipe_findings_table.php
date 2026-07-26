<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 21 · S5a (Tranche B) — die Ablage für KI-Befunde am Rezept.
 *
 * Bis hierher war der L6-Copilot-Befund der einzige KI-Vorschlag ohne Zeile: er
 * lebte in der Livewire-Property, ein Reload warf ihn weg (V-031). Tranche B kann
 * darauf nicht aufsetzen — ein Signal muss zwischen zwei Detektor-Läufen wissen,
 * ob dieser Befund neu, wiedergekehrt oder bewusst liegengelassen ist.
 *
 * Eine Zeile = EIN Befund an EINEM Rezept, dedupliziert über `fingerprint`
 * (art + Zielzeile + Wert). Zwei Läufe über dasselbe unveränderte Rezept erzeugen
 * damit keine zweite Zeile, sondern erhöhen `seen_count` — „kommt nach jedem Fix
 * zurück" ist ein anderer Sachverhalt als „neu gefunden" (V-009 auf dieser Ebene).
 *
 * `status` ist die menschliche Hälfte und der Ruhigsteller:
 *  - `offen`        → Kandidat für ein Signal (S5b, oberhalb der Konfidenz-Schwelle)
 *  - `uebernommen`  → über `RecipeReviewService::uebernehmen` angewendet
 *  - `verworfen`    → bewusst liegengelassen; ein Folgelauf darf ihn NICHT wieder
 *                     öffnen, sonst wird derselbe abgelehnte Befund jedes Mal Signal
 *  - `verschwunden` → im letzten Pass nicht mehr gemeldet (Rezept wurde geändert)
 *
 * Team-Scoping strikt `team_id` (kein visibleToTeam-Erben, wie bei den Snapshots):
 * der Befund gehört dem messenden Team. Ein Eltern-Team, das ein Kind-Rezept prüft,
 * bekommt seine eigene Zeile — sonst schriebe die KI eines Teams in die Inbox eines
 * anderen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_findings')) {
            return;
        }
        Schema::create('foodalchemist_recipe_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();

            $table->string('kind', 16);                              // RecipeReviewService::ARTEN
            $table->unsignedBigInteger('ingredient_id')->nullable();  // Zielzeile, wenn der Befund eine trifft
            $table->string('ingredient_text', 255)->nullable();       // Rohtext des Modells (auch ohne Zielzeile)
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit_slug', 32)->nullable();
            $table->text('reason')->nullable();
            $table->decimal('confidence', 4, 3)->default(0);
            $table->boolean('auto_applicable')->default(false);
            $table->string('applicability', 24)->default('anwendbar'); // Warum nicht: kein_ziel | kein_treffer | …

            $table->string('status', 16)->default('offen');
            $table->string('fingerprint', 64);                        // Dedup-Schlüssel (sha1)
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('decided_at')->nullable();              // uebernommen/verworfen gestempelt
            // Absichtlich OHNE FK auf foodalchemist_bulk_runs: der Lauf ist Bookkeeping,
            // der Befund ist die Wahrheit — ein aufgeräumter Lauf darf ihn nicht mitnehmen.
            $table->unsignedBigInteger('run_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Ein Befund je Rezept+Fingerprint (Dedup-Garantie auf DB-Ebene).
            $table->unique(['team_id', 'recipe_id', 'fingerprint'], 'fa_recipe_finding_unique');
            // Leserichtung S5b: „offene Befunde über Schwelle" je Team.
            $table->index(['team_id', 'status', 'confidence'], 'fa_recipe_finding_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_findings');
    }
};
