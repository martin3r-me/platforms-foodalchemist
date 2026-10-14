<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 82 §13 B6 — Menüpunkt „Veröffentlichen …“ (Fassung an die Abonnenten geben): EINE Bedingung für Basisrezepte, Gerichte, Pakete, Konzepte und Formate.
 *
 * Sichtbar nur, wenn das aktive Team das Master-Team ist, das Objekt dem Master gehört und kein Fassungs-Stand ist (veröffentlicht wird die Arbeitszeile,
 * nie eine Fassung). Ein Kunden-Format (origin=kunde) hat nie einen Menüpunkt (D8, Kunden-IP). Die Menüs in Rezept-Modal, Rezept-Detailpanel, Gericht-
 * Modal (VK), Gericht-Detailpanel, Paket-Detail, Konzept-Detailpanel und Format-Detailpanel fragen hier; wer die Bedingung ändert, ändert sie überall.
 * Das Veröffentlichen selbst prüfen {@see \Platform\FoodAlchemist\Services\FassungService} (Rezept), PaketFassungService, ConceptFassungService und
 * FormatFassungService.
 */
final class FassungMenue
{
    public const ARTEN = ['recipe', 'paket', 'concept', 'format'];

    private const MODELL = ['recipe' => FoodAlchemistRecipe::class, 'paket' => FoodAlchemistPaket::class, 'concept' => FoodAlchemistConcept::class, 'format' => FoodAlchemistFormat::class];

    /** @param  'recipe'|'paket'|'concept'|'format'  $art */
    public static function sichtbar(?Team $team, Model|int|null $objekt, string $art = 'recipe'): bool
    {
        if ($team === null || $objekt === null || ! MasterTeam::istMaster($team) || ! isset(self::MODELL[$art])) {
            return false;
        }
        if (! $objekt instanceof Model) {
            $spalten = $art === 'format' ? ['id', 'team_id', 'ist_fassung', 'origin'] : ['id', 'team_id', 'ist_fassung'];
            $objekt = self::MODELL[$art]::query()->whereKey($objekt)->first($spalten);   // Fassungen sind im Standard-Scope ausgeblendet
        }
        if ($objekt === null || (int) $objekt->team_id !== (int) MasterTeam::id() || $objekt->ist_fassung) {
            return false;
        }

        return ! ($art === 'format' && $objekt->origin === 'kunde');
    }
}
