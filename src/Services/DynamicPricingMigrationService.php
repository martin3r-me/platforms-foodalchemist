<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/** Fortsetzbare Datenumstellung vom harten Rohaufschlag auf Pricing v2. */
class DynamicPricingMigrationService
{
    public function __construct(
        private CatalogPricingService $catalog,
        private DarreichungService $presentations,
        private PaketService $packages,
        private ConceptService $concepts,
        private AngebotService $offers,
    ) {
    }

    /** @return array<string,int> */
    public function migrate(?int $teamId = null, int $chunk = 200): array
    {
        $stats = ['classes' => 0, 'presentations' => 0, 'packages' => 0, 'concepts' => 0, 'offers' => 0];
        $teams = Team::query()->when($teamId !== null, fn ($q) => $q->whereKey($teamId))->get();

        // Globale Klassen können keinen team-spezifischen Faktor tragen. Sie werden einmalig
        // gegen den neutralen 30-%-Fallback überführt; Teams können sie anschließend forken.
        FoodAlchemistMarkupClass::whereNull('team_id')->whereNull('pricing_v2_migrated_at')
            ->chunkById($chunk, function ($classes) use (&$stats) {
                foreach ($classes as $class) {
                    $class->update($this->classMigrationValues($class, 100 / 30));
                    $stats['classes']++;
                }
            });

        foreach ($teams as $team) {
            $base = (float) ($this->catalog->enterpriseBaseRate($team)['factor'] ?? (100 / 30));
            FoodAlchemistMarkupClass::where('team_id', $team->id)->whereNull('pricing_v2_migrated_at')
                ->chunkById($chunk, function ($classes) use (&$stats, $base) {
                    foreach ($classes as $class) {
                        $class->update($this->classMigrationValues($class, $base));
                        $stats['classes']++;
                    }
                });

            // Altbestand ohne Darreichungszeile hing ausschließlich am Rezept-Cache.
            // Vor der Kaskade eine echte Preis-Wahrheit anlegen; bei vorhandenen
            // Varianten ohne Standard wird weiterhin nichts geraten.
            FoodAlchemistRecipe::where('team_id', $team->id)->where('is_sales_recipe', true)
                ->whereDoesntHave('presentations')
                ->chunkById($chunk, function ($recipes) use (&$stats, $team) {
                    foreach ($recipes as $recipe) {
                        if ($this->presentations->ensureStandard($team, $recipe->id, 'pricing_v2_migration') !== null) {
                            $stats['presentations']++;
                        }
                    }
                });

            FoodAlchemistRecipeDarreichung::where('team_id', $team->id)
                ->where(fn ($q) => $q->where('price_calculation_version', '!=', CatalogPricingService::VERSION)
                    ->orWhereNull('price_calculation_version'))
                ->chunkById($chunk, function ($rows) use (&$stats) {
                    foreach ($rows as $row) {
                        $row->loadMissing('recipe', 'servingForm');
                        $legacyAutoStandard = $row->is_standard
                            && $row->created_via !== null
                            && $row->servingForm?->code === 'unbestimmt'
                            && (float) ($row->unit_count ?? 0) > 1
                            && (float) ($row->unit_count ?? 0) === (float) ($row->recipe?->sales_unit_count ?? 0);
                        $row->update([
                            'price_mode' => 'auto',
                            ...($legacyAutoStandard ? ['unit_count' => 1] : []),
                            'price_override_reason' => null,
                            'price_override_user_id' => null,
                            'price_override_at' => null,
                            'price_override_expires_at' => null,
                        ]);
                        $this->presentations->recomputePreise($row->fresh());
                        $stats['presentations']++;
                    }
                });

            FoodAlchemistPaket::where('team_id', $team->id)->chunkById($chunk, function ($rows) use (&$stats) {
                foreach ($rows as $row) {
                    $row->update(['price_mode' => 'auto', 'price_override_reason' => null,
                        'price_override_user_id' => null, 'price_override_at' => null, 'price_override_expires_at' => null]);
                    $this->packages->recomputePrice($row->fresh());
                    $stats['packages']++;
                }
            });

            // Zwei Durchläufe stabilisieren eingebettete Concepts, deren Cache voneinander abhängt.
            for ($pass = 0; $pass < 2; $pass++) {
                FoodAlchemistConcept::where('team_id', $team->id)
                    ->chunkById($chunk, function ($rows) use (&$stats, $pass) {
                        foreach ($rows as $row) {
                            $row->update([
                                'price_mode' => 'auto',
                                'price_per_person_manual' => null,
                                'price_override_reason' => null,
                                'price_override_user_id' => null,
                                'price_override_at' => null,
                                'price_override_expires_at' => null,
                                'price_per_person_cache' => $this->concepts->preisCockpit($row)['price_per_person'],
                            ]);
                            if ($pass === 0) {
                                $stats['concepts']++;
                            }
                        }
                    }, 'id', 'id');
            }

            FoodAlchemistAngebot::where('team_id', $team->id)
                ->whereIn('status', ['anfrage', 'in_arbeit', 'angebot'])
                ->chunkById($chunk, function ($rows) use (&$stats, $team) {
                    foreach ($rows as $row) {
                        $row->update(['price_mode' => 'auto', 'price_override_reason' => null,
                            'price_override_user_id' => null, 'price_override_at' => null, 'price_override_expires_at' => null]);
                        $this->offers->aktualisiereAutoPreis($team, $row->fresh());
                        $stats['offers']++;
                    }
                });
        }

        return $stats;
    }

    /** @return array<string,mixed> */
    private function classMigrationValues(FoodAlchemistMarkupClass $class, float $base): array
    {
        $oldMultiplier = 1 + max(0.0, (float) ($class->raw_markup_pct ?? 0)) / 100;
        $vatKey = (float) ($class->vat_rate ?? 19) <= 10 ? 'ermaessigt' : 'regulaer';

        return [
            'class_factor_pct' => round($oldMultiplier / max(0.0001, $base) * 100, 3),
            'vat_profile_key' => $class->vat_profile_key ?: $vatKey,
            'pricing_v2_migrated_at' => now(),
        ];
    }
}
