<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ersatz-Logik (make-or-buy + Artikel-Ersatz): Katalog-Äquivalenz zwischen zwei
 * Realisierungen DESSELBEN Bausteins. POLYMORPH — jede Seite ist GP oder Rezept:
 *   - GP  ↔ Rezept : Ersatz-Rezept (fertig ↔ selbst, z.B. "Velouté TK" ↔ "Velouté: Grund")
 *   - GP  ↔ GP     : Ersatz-Artikel (alternativer Artikel, z.B. Lieferant aus → Ausweich)
 *   - Rezept ↔ Rezept : alternative Zubereitung
 *
 * EINMAL zentral gepflegt (Katalog), überall nutzbar. Tausch in einem Rezept hängt
 * nur die Zutat-FK um (gp_id ↔ referenced_recipe_id) + Menge × umrechnungsfaktor;
 * RecipeRecomputeService rechnet EK/Allergene automatisch nach.
 *
 * standard_seite = katalogweiter Default ('source'|'alt'). Rezept-Override = die
 * Zutat zeigt auf die gewählte Seite; swap_locked (sep. Migration) fixiert sie.
 *
 * Polymorph → keine DB-FK (kind-Diskriminator wie foodbook_block-type, 07 §7);
 * Integrität im Model/Service. Lookup-Indizes auf beide Seiten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_component_equivalents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();

            // Seite A ("source") und Seite B ("alt") — je GP oder Rezept
            $table->string('source_kind', 8);              // 'gp' | 'recipe'
            $table->unsignedBigInteger('source_id');
            $table->string('alt_kind', 8);                 // 'gp' | 'recipe'
            $table->unsignedBigInteger('alt_id');

            // 1 Einheit source = umrechnungsfaktor Einheiten alt (Ergiebigkeit)
            $table->decimal('umrechnungsfaktor', 10, 4)->default(1);
            $table->string('standard_seite', 8)->default('source'); // 'source' | 'alt'

            $table->decimal('match_confidence', 4, 3)->nullable(); // falls KI-vorgeschlagen
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['team_id', 'source_kind', 'source_id', 'alt_kind', 'alt_id'],
                'fa_comp_equiv_pair_unique'
            );
            $table->index(['source_kind', 'source_id'], 'fa_comp_equiv_source_idx');
            $table->index(['alt_kind', 'alt_id'], 'fa_comp_equiv_alt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_component_equivalents');
    }
};
