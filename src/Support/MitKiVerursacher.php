<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Job-Trait: der Job läuft im Team seines Auftrags (`$this->teamId`). Ursprung #452 (KI-Abrechnung je Mandant: ohne ihn stünde
 * ein Massenlauf, den ein Mandant ausgelöst hat, als „plattform“ im Log). Seit Spec 84 A-3 läuft er über FaTeam
 * ({@see \Platform\FoodAlchemist\Jobs\Middleware\ImTeamKontext}) — dieselbe Quelle für Kill-Switch, Budget, Call-Log und Abrechnung,
 * statt eines eigenen Kontexts für die Abrechnung. Name bleibt, damit die Jobs aus #452 unverändert bleiben.
 */
trait MitKiVerursacher
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [new \Platform\FoodAlchemist\Jobs\Middleware\ImTeamKontext(isset($this->teamId) ? (int) $this->teamId : null)];
    }
}
