<?php

namespace Platform\FoodAlchemist\Services\GpAbgleich;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * S1 des Abgleich-Dienstes: die GP-Naming-Felder eines Lieferantenartikels (hauptzutat, flavor, zusatz, condition, processing, form, pflichtangabe,
 * commodity_group_code, sub_category).
 *
 * Reihenfolge: vorhandene Klassifikation (`supplier_item_structures`, `classified_at` gesetzt) wird WIEDERVERWENDET — sie ist das Ergebnis desselben
 * `gp.suggest`-Aufrufs (ClassifyLaJob) und kostet nichts. Nur wenn sie fehlt UND KI erlaubt ist, läuft `gp.suggest` (ein Call). Der Klammer-Zusatz
 * (Fantasiename) wird nie aus der KI blind übernommen, sondern nur, wenn er exakt in Bezeichnung/Vermarktungsname steht ({@see GpNamingService::zusatzAusArtikel()}).
 */
class LaFelderService
{
    public function __construct(
        private readonly AiGatewayService $ki,
        private readonly GpNamingService $naming,
        private readonly VocabularyService $vocab,
    ) {}

    /**
     * @return array{hauptzutat: string, flavor: string, zusatz: string, condition: string, processing: string, form: string, pflichtangabe: string,
     *               commodity_group_code: string, sub_category: string, quelle: string, ki: ?AiProposal}
     */
    public function fuer(Team $team, FoodAlchemistSupplierItem $la, bool $kiErlaubt = true): array
    {
        $la->loadMissing('structure');
        $s = $la->structure;
        if ($s !== null && $s->classified_at !== null && trim((string) $s->main_ingredient_display) !== '') {
            return [
                'hauptzutat' => (string) $s->main_ingredient_display,
                'flavor' => (string) ($s->flavor ?? ''),
                'zusatz' => (string) ($s->zusatz ?? ''),
                'condition' => (string) ($s->condition ?? ''),
                'processing' => (string) ($s->processing ?? ''),
                'form' => (string) ($s->form ?? ''),
                'pflichtangabe' => '',
                'commodity_group_code' => (string) ($s->commodity_group_suggestion ?? ''),
                'sub_category' => '',
                'quelle' => 'struktur',
                'ki' => null,
            ];
        }
        if (! $kiErlaubt) {
            return $this->leer('keine');
        }

        $vorschlag = $this->ki->propose('gp.suggest', [
            'label' => trim((string) $la->designation),
            'taxonomie' => $this->taxonomie($team),
            'regel' => 'commodity_group_code und sub_category ausschließlich aus taxonomie wählen',
            'quell_lieferantenartikel' => $this->artikelKontext($la),
        ]);
        $w = $vorschlag->werte;
        $feld = fn (string $k): string => $this->naming->bereinigeFeld($w[$k] ?? null);

        return [
            'hauptzutat' => $feld('hauptzutat'),
            'flavor' => $feld('flavor'),
            'zusatz' => $this->naming->zusatzAusArtikel($w['zusatz'] ?? null, [$la->designation, $la->marketing_name]),
            'condition' => $feld('condition'),
            'processing' => $feld('processing'),
            'form' => $feld('form'),
            'pflichtangabe' => $feld('pflichtangabe'),
            'commodity_group_code' => $feld('commodity_group_code'),
            'sub_category' => $feld('sub_category'),
            'quelle' => 'ki',
            'ki' => $vorschlag,
        ];
    }

    /** @return array<string,mixed> */
    private function leer(string $quelle): array
    {
        return ['hauptzutat' => '', 'flavor' => '', 'zusatz' => '', 'condition' => '', 'processing' => '', 'form' => '', 'pflichtangabe' => '',
            'commodity_group_code' => '', 'sub_category' => '', 'quelle' => $quelle, 'ki' => null];
    }

    /** @return list<array{code: string, name: string, sub_categories: list<string>}> */
    private function taxonomie(Team $team): array
    {
        return $this->vocab->listWarengruppen($team)->map(fn ($wg) => [
            'code' => (string) $wg->code,
            'name' => (string) $wg->name,
            'sub_categories' => $this->vocab->listSubCategories($team, (string) $wg->code)->pluck('sub_category')->values()->all(),
        ])->values()->all();
    }

    /**
     * Artikel-Kontext für gp.suggest (wie im GP-Modal): Texte, Marke/Hersteller (nur zur Einordnung, nie im Namen), Haltbarkeit je Lagerart,
     * Gebinde (nie im Namen), Allergene. Öffentlich, damit der Entscheider denselben Auszug bekommt.
     *
     * @return array<string,mixed>
     */
    public function artikelKontext(FoodAlchemistSupplierItem $la): array
    {
        $la->loadMissing(['supplier:id,name', 'allergens']);
        $allergene = [];
        foreach (FoodAlchemistItemAllergen::ALLERGENE as $key => $label) {
            $wert = $la->allergens?->{'allergen_' . $key} ?? null;
            if (in_array($wert, ['enthalten', 'spuren'], true)) {
                $allergene[] = $label . ' (' . $wert . ')';
            }
        }

        return [
            'id' => (int) $la->id,
            'bezeichnung' => (string) $la->designation,
            'regulierter_name' => $la->regulated_name,
            'marketing_name' => $la->marketing_name,
            'zutatenangabe' => $la->ingredients_supplier,
            'marke' => $la->brand,
            'hersteller' => $la->manufacturer,
            'herkunft' => $la->origin ?: $la->origin_country,
            'lieferant' => $la->supplier?->name,
            'bio' => $la->is_organic,
            'vegan' => $la->is_vegan,
            'vegetarisch' => $la->is_vegetarian,
            'alkohol' => $la->is_alcohol,
            'haltbarkeit' => array_filter(['gekuehlt_tage' => $la->shelf_life_chilled_days, 'tiefgekuehlt_tage' => $la->shelf_life_frozen_days, 'trocken_tage' => $la->shelf_life_dry_days]),
            'gebinde_hinweis' => array_filter(['menge' => $la->qty !== null ? (float) $la->qty : null, 'einheit' => $la->unit_code, 'verpackung' => $la->packaging_unit]),
            'allergene' => $allergene,
        ];
    }
}
