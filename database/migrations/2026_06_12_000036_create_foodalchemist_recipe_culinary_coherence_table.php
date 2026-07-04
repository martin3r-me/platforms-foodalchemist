<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-6 §5.x / GL-10 §2: Judge-Cache `recipe_culinary_coherence` — die ZWEITE
 * Achse (kulinarische Stimmigkeit per LLM-Judge), nie mit dem deterministischen
 * Aroma-Score verrechnet (GL-10 §1). Eine Zeile je Rezept; `components_hash`
 * ist der Invalidierungs-Anker (Zutaten-Änderung ⇒ stale ⇒ Re-Judge).
 *
 * Der Teller-Heber (vk.teller_heber, Ist: plate_suggester) liegt mit in der
 * Zeile: gleiche Entität, gleicher Invalidierungs-Anker — Anzeige bleibt
 * strikt getrennt (zwei Panel-Sektionen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_recipe_culinary_coherence', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('recipe_id')->unique()->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->string('components_hash', 40);

            // Achse 2: Kohärenz-Judge (vk.kohaerenz — Ist: culinary_coherence_judge)
            $table->unsignedTinyInteger('score')->nullable();        // 0–100
            $table->string('label')->nullable();                     // z. B. «Klassischer Teller»
            $table->text('reasoning')->nullable();
            $table->string('schwachstelle')->nullable();             // GL-10 §2 (T5: «Gruyere»)
            $table->string('judge_model')->nullable();
            $table->timestamp('judged_at')->nullable();

            // Teller-Heber (vk.teller_heber): {einschaetzung, vorschlaege: [{typ, zutat, kategorie, reasoning, confidence}]}
            $table->json('heber_json')->nullable();
            $table->string('heber_model')->nullable();
            $table->timestamp('heber_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_culinary_coherence');
    }
};
