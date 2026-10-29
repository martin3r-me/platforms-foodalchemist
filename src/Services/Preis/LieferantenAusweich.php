<?php

namespace Platform\FoodAlchemist\Services\Preis;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\LeadLaService;

/**
 * Inaktiver oder gesperrter Lieferant (Kurator 29.10.2026) — aus RecipeRecomputeService ausgelagert (Dateigrößen-Wächter, Spec 85 §9).
 * Gemeinsame Stelle „Lead übergehen“ fürs Rezept-EK: so ein Artikel ist nie Lead und nie Preisquelle, solange ein bestellbarer Lieferant den GP
 * beliefert; gibt es sonst nichts, bleibt er Ausweich. Das Kennzeichen `lieferant_inaktiv` setzen die Artikel-Abfragen ({@see \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::nichtBestellbarSql}).
 */
class LieferantenAusweich
{
    public function uebergehen(?FoodAlchemistSupplierItem $la): bool
    {
        return $la !== null && (bool) ($la->lieferant_inaktiv ?? false);
    }

    /** Ø-Pfad: Artikel nicht bestellbarer Lieferanten zählen nicht mit — außer es bliebe nichts. */
    public function nurBestellbare(Collection $las): Collection
    {
        $ok = $las->reject(fn ($la) => $this->uebergehen($la));

        return $ok->isNotEmpty() ? $ok->values() : $las;
    }

    /**
     * Ersatz-Lead, wenn der gesetzte Lead übergangen wird: erster Kandidat der Rangliste (Strategie, Stamm-Lieferant, Prioritäts-Kette bleiben wirksam —
     * kein „günstigster“), der frei (nicht gesperrt, bepreist, nicht eingestellt = $frei) und bei einem bestellbaren Lieferanten ist. Null, wenn es keinen gibt.
     */
    public function ersatz(FoodAlchemistGp $gp, ?Team $team, Collection $frei): ?FoodAlchemistSupplierItem
    {
        $team ??= $gp->team_id !== null ? Team::find($gp->team_id) : null;
        if ($team === null) {
            return $frei->first(fn ($la) => ! $this->uebergehen($la));
        }
        foreach (app(LeadLaService::class)->rangliste($gp, $team, null, true) as $kandidat) {
            $la = $frei->firstWhere('id', $kandidat->id);
            if ($la !== null && ! $this->uebergehen($la)) {
                return $la;
            }
        }

        return null;
    }
}
