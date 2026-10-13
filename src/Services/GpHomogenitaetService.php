<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Support\DeklarationsProfil;

/**
 * Spec 82 §2 — Bericht „GPs mit uneinheitlichen Artikeln“.
 *
 * Nur LESEND: Bestehende Verknüpfungen werden nicht gelöst, umgehängt oder neu berechnet.
 * Vergleichsregel wie beim Verknüpfen ({@see DeklarationsProfil}): nur „enthalten“, Spuren zählen nicht,
 * fehlende Daten sind „unbekannt“ und kein Widerspruch.
 */
class GpHomogenitaetService
{
    /**
     * @return array{
     *   zusammenfassung: array<string,int>,
     *   gps: list<array{gp_id:int,name:string,artikel:int,unbekannt:int,abweichungen:list<string>}>
     * }
     */
    public function bericht(int $chunk = 300): array
    {
        $verknuepft = fn () => DB::table('foodalchemist_supplier_item_structures AS s')
            ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 's.supplier_item_id')
            ->whereNotNull('s.gp_id')->whereNull('s.deleted_at')->whereNull('i.deleted_at');

        $gesamtVerknuepft = $verknuepft()->count();
        $unbekanntGesamt = $this->zaehleUnbekannte($verknuepft());

        $gpIds = $verknuepft()->groupBy('s.gp_id')->havingRaw('COUNT(*) > 1')->pluck('s.gp_id')->map(fn ($i) => (int) $i)->all();

        $gps = [];
        $artikelInUneinheitlichen = 0;
        $unbekanntInUneinheitlichen = 0;

        foreach (array_chunk($gpIds, $chunk) as $ids) {
            $zeilen = $verknuepft()->whereIn('s.gp_id', $ids)->get(['s.gp_id', 's.supplier_item_id']);
            $itemIds = $zeilen->pluck('supplier_item_id')->all();
            $allergene = FoodAlchemistItemAllergen::query()->whereIn('supplier_item_id', $itemIds)->get()->keyBy('supplier_item_id');
            $stoffe = FoodAlchemistItemDeclaration::query()->whereIn('supplier_item_id', $itemIds)->get()->keyBy('supplier_item_id');
            $namen = DB::table('foodalchemist_gps')->whereIn('id', $ids)->pluck('name', 'id');

            foreach ($zeilen->groupBy('gp_id') as $gpId => $artikel) {
                $abw = [];
                $unbekannt = 0;
                foreach (FoodAlchemistItemAllergen::ALLERGENE as $feld => $label) {
                    $mit = $ohne = false;
                    foreach ($artikel as $z) {
                        $w = $allergene->get($z->supplier_item_id)?->{"allergen_{$feld}"};
                        if (DeklarationsProfil::allergenBekannt($w)) {
                            $w === 'enthalten' ? $mit = true : $ohne = true;
                        }
                    }
                    if ($mit && $ohne) {
                        $abw[] = 'Allergen „' . $label . '“';
                    }
                }
                foreach (FoodAlchemistItemDeclaration::STOFFE as $feld => $label) {
                    $werte = $artikel->map(fn ($z) => (int) ($stoffe->get($z->supplier_item_id)?->{$feld}))->all();
                    if (in_array(1, $werte, true) && in_array(3, $werte, true)) {
                        $abw[] = 'Zusatzstoff „' . $label . '“';
                    }
                }
                foreach ($artikel as $z) {
                    if (DeklarationsProfil::istUnbekannt($allergene->get($z->supplier_item_id))) {
                        $unbekannt++;
                    }
                }
                if ($abw !== []) {
                    $gps[] = [
                        'gp_id' => (int) $gpId,
                        'name' => (string) ($namen[$gpId] ?? ''),
                        'artikel' => $artikel->count(),
                        'unbekannt' => $unbekannt,
                        'abweichungen' => $abw,
                    ];
                    $artikelInUneinheitlichen += $artikel->count();
                    $unbekanntInUneinheitlichen += $unbekannt;
                }
            }
        }

        usort($gps, fn ($a, $b) => [count($b['abweichungen']), $b['artikel']] <=> [count($a['abweichungen']), $a['artikel']]);

        return [
            'zusammenfassung' => [
                'artikel_verknuepft' => $gesamtVerknuepft,
                'artikel_unbekannt_gesamt' => $unbekanntGesamt,
                'gps_mit_mehreren_artikeln' => count($gpIds),
                'gps_uneinheitlich' => count($gps),
                'artikel_in_uneinheitlichen_gps' => $artikelInUneinheitlichen,
                'artikel_unbekannt_in_uneinheitlichen_gps' => $unbekanntInUneinheitlichen,
            ],
            'gps' => $gps,
        ];
    }

    /** Verknüpfte Artikel ohne verwertbare Allergenangabe (keine Zeile oder alle 14 Felder NULL/unbekannt). */
    private function zaehleUnbekannte($basis): int
    {
        $basis->leftJoin('foodalchemist_item_allergens AS a', fn ($j) => $j->on('a.supplier_item_id', '=', 'i.id')->whereNull('a.deleted_at'));
        $basis->where(function ($q) {
            $q->whereNull('a.id');
            $q->orWhere(function ($alle) {
                foreach (array_keys(FoodAlchemistItemAllergen::ALLERGENE) as $feld) {
                    $alle->where(fn ($f) => $f->whereNull("a.allergen_{$feld}")->orWhere("a.allergen_{$feld}", 'unbekannt'));
                }
            });
        });

        return $basis->count();
    }
}
