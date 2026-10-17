<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/**
 * Spec 82 §8 / §12 C (Dominique 13.10.): VK eines Abo-Gerichts beim Mandanten — wird NICHT gepflegt, sondern berechnet:
 *   MEK (EK je Portion) = Team-EK je kg (KostenCacheService) × Grammatur × Anzahl der übergebenen Standard-Darreichung
 *   VK  = MEK × Basissatz DES TEAMS × Faktor SEINER Preisklasse (CatalogPricingService::catalogPrice, wie der Auto-VK)
 * Die Fassung trägt keine Master-Preisklasse, also greift die Team-Standard-Klasse des Mandanten (sonst neutral 100 %).
 *
 * Ohne vollständigen EK gibt es „kein VK" (null), nie 0 € (Auflage 4). Arbeitszeilen laufen unverändert über die
 * Preis-Leiter des DarreichungResolver (Besitzer-Weg).
 *
 * Durchstich-Grenze: Darreichungen mit Deltas (Komponenten weglassen/umgewichten) rechnet der Abo-Weg noch nicht →
 * „kein VK" mit Grund `deltas_im_abo_offen`.
 */
class TeamVkService
{
    /**
     * @param  ?FoodAlchemistRecipeDarreichung  $darreichung  nur für Fassungen: statt der Standard-Form eine bestimmte Darreichung
     *                                                         der Fassung rechnen (Paket-Gericht mit gewählter Form, Spec 82 Abo Pakete PR 3)
     * @return array{vk: ?float, quelle: string, grund: ?string, mek: ?float}
     */
    public function fuer(FoodAlchemistRecipe $recipe, Team $team, ?FoodAlchemistOutlet $outlet = null, ?FoodAlchemistRecipeDarreichung $darreichung = null): array
    {
        if (! array_key_exists('ist_fassung', $recipe->getAttributes())) {
            // Spec 84 B3: dieselbe id, die der Aufrufer schon hat — nur fehlende Spalten nachladen, unabhängig vom Kontext
            $recipe = FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('nachladen')->findOrFail($recipe->id);
        }
        if (! $recipe->ist_fassung) {
            $r = app(DarreichungResolver::class)->vkNettoMitQuelle($recipe, $outlet);

            return ['vk' => $r['vk'], 'quelle' => $r['quelle'], 'grund' => $r['vk'] === null ? 'kein_vk' : null, 'mek' => null];
        }

        $kosten = app(KostenCacheService::class)->fuer($recipe, $team);
        if (! $kosten['vollstaendig'] || $kosten['ek_per_kg'] === null) {
            return ['vk' => null, 'quelle' => 'keine', 'grund' => 'ek_unvollstaendig', 'mek' => null];
        }
        $standard = $darreichung !== null && (int) $darreichung->recipe_id === (int) $recipe->id
            ? $darreichung
            : FoodAlchemistRecipeDarreichung::where('recipe_id', $recipe->id)->orderByDesc('is_standard')->orderBy('id')->first();
        if ($standard === null) {
            return ['vk' => null, 'quelle' => 'keine', 'grund' => 'keine_darreichung', 'mek' => null];
        }
        if ($standard->deltas()->exists()) {
            return ['vk' => null, 'quelle' => 'keine', 'grund' => 'deltas_im_abo_offen', 'mek' => null];
        }
        $gramm = app(DarreichungService::class)->grammJeEinheit($standard, $recipe) ?? 0.0;
        if ($gramm <= 0) {
            return ['vk' => null, 'quelle' => 'keine', 'grund' => 'keine_grammatur', 'mek' => null];
        }
        $mek = round($kosten['ek_per_kg'] / 1000.0 * $gramm * (float) ($standard->unit_count ?: 1), 4);

        // Zubehör & Verpackung der Form: zum Preis des Teams (dessen Lead-LA) auf den MEK — fehlt ein Preis, gibt es keinen halben Preis
        if ($standard->vessel_gp_id !== null || $standard->zubehoer()->exists()) {
            $beigaben = app(DarreichungService::class)->beigabenKosten($standard, $team);
            if (! $beigaben['vollstaendig']) {
                return ['vk' => null, 'quelle' => 'keine', 'grund' => 'zubehoer_ohne_preis', 'mek' => null];
            }
            $mek = round($mek + $beigaben['summe'] * (float) ($standard->unit_count ?: 1), 4);
        }

        // Rechen-Kopie (nie gespeichert): MEK des Teams, keine fremde Preisklasse, Auto-Modus
        $rechnung = $standard->replicate();
        $rechnung->forceFill(['ek_portion' => $mek, 'markup_class_id' => null, 'price_mode' => 'auto', 'sales_net' => null]);
        $rechnung->setRelation('recipe', $recipe);
        $rechnung->setRelation('markupClass', null);
        $preis = app(CatalogPricingService::class)->catalogPrice($team, $rechnung, $outlet);

        return $preis['sales_net'] !== null
            ? ['vk' => (float) $preis['sales_net'], 'quelle' => 'team_kalkulation', 'grund' => null, 'mek' => $mek]
            : ['vk' => null, 'quelle' => 'keine', 'grund' => 'kein_basissatz', 'mek' => $mek];
    }
}
