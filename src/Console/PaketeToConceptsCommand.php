<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;

/**
 * Conceptor-Kaskade Phase 1 (2026-08-24): migriert Pakete → kind=paket-Concepts +
 * package_dishes → concept_slots. Idempotent (Mapping via uuid-Reuse Paket↔Concept).
 *
 * SHADOW: die packages/package_dishes-Tabellen bleiben unangetastet, bis der Paket-Pfad
 * (Browser/Editor/Service/MCP) auf kind=paket umgestellt + verifiziert ist. Caches
 * (Nährwerte/Arbeitszeit) werden bewusst NICHT kopiert — sie werden nach dem Umstieg
 * über den Paket-Recompute neu gerechnet. Preis/EK werden gespiegelt, damit die Anzeige
 * schon vor dem Recompute stimmt.
 */
class PaketeToConceptsCommand extends Command
{
    protected $signature = 'foodalchemist:pakete-to-concepts {--apply : schreiben (ohne Flag nur Dry-Run)}';

    protected $description = 'Migriert Pakete → kind=paket-Concepts + package_dishes → concept_slots (idempotent, Shadow).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' — Pakete → kind=paket-Concepts');

        $pakete = FoodAlchemistPaket::with(['dishes.dish:id,is_sales_recipe'])->orderBy('id')->get();
        $neu = 0;
        $aktualisiert = 0;
        $slots = 0;
        $embeds = 0;

        foreach ($pakete as $pkg) {
            $concept = FoodAlchemistConcept::withoutGlobalScope('kind_concept')
                ->where('uuid', $pkg->uuid)->first();

            $attr = [
                'kind' => 'paket',
                'team_id' => $pkg->team_id,
                'name' => $pkg->name,
                'consumer_name' => $pkg->consumer_name,
                'class' => $pkg->class,
                'level' => $pkg->level,
                'status' => $pkg->is_inactive ? 'archiviert' : 'active',
                'price_mode' => in_array($pkg->price_mode, ['auto', 'manuell'], true) ? $pkg->price_mode : 'manuell',
                'price_per_person_manual' => $pkg->price_per_person,
                'price_per_person_cache' => $pkg->price_per_person,   // Anzeige vor Recompute
                'ek_per_person_cache' => $pkg->ek_per_person,
                'offer_id' => $pkg->offer_id,
                'description' => $pkg->description,
                'note' => $pkg->note,
                'created_via' => 'paket_merge',
            ];

            if (! $apply) {
                $this->line(($concept ? '  ~ update ' : '  + neu    ').$pkg->name.' ('.$pkg->dishes->count().' Posten)');

                continue;
            }

            if ($concept) {
                $concept->update($attr);
                $aktualisiert++;
            } else {
                $attr['uuid'] = $pkg->uuid;   // bootHasUuidV7 respektiert vorgegebene uuid → stabiles Mapping
                $concept = FoodAlchemistConcept::create($attr);
                $neu++;
            }

            // Slots idempotent neu aus package_dishes aufbauen.
            FoodAlchemistConceptSlot::where('concept_id', $concept->id)->forceDelete();
            foreach ($pkg->dishes->sortBy('position')->values() as $i => $pd) {
                $istBasis = ! ($pd->dish?->is_sales_recipe ?? true);
                FoodAlchemistConceptSlot::create([
                    'team_id' => $pkg->team_id,
                    'concept_id' => $concept->id,
                    'type' => $istBasis ? 'basisrezept' : 'gericht',
                    'sales_recipe_id' => $pd->sales_recipe_id,
                    'quantity' => $pd->quantity,
                    'unit_vocab_id' => $pd->unit_vocab_id,
                    'position' => $pd->position ?? $i,
                    'presentation_id' => $pd->presentation_id,
                    'tableware_item_id' => $pd->tableware_item_id,
                    'tableware_alt_item_id' => $pd->tableware_alt_item_id,
                    'is_pflicht' => true,
                ]);
                $slots++;
            }

            // Embed-Rewire: Concept-Slots, die dieses Paket einbetten (package_id) → embedded_concept_id.
            $embeds += FoodAlchemistConceptSlot::where('package_id', $pkg->id)
                ->update(['embedded_concept_id' => $concept->id]);
        }

        if ($apply) {
            $this->info("Fertig: {$neu} neu, {$aktualisiert} aktualisiert, {$slots} Slots, {$embeds} Einbettungen umgehängt.");
        } else {
            $this->info('Dry-Run: '.$pakete->count().' Pakete würden migriert. Mit --apply ausführen.');
        }

        return self::SUCCESS;
    }
}
