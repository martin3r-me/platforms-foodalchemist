<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Services\PhaseService;
use RuntimeException;

/**
 * R4.3: Phasen-Stepper-State für Livewire-Hosts (Foodbook, Concepter). Host liefert
 * den Owner über phaseOwner(); gerendert über das Partial
 * `foodalchemist::livewire.planning.partials.phase-stepper`.
 */
trait ManagesPhase
{
    public ?string $phaseFehler = null;

    public string $phaseOverrideNote = '';

    public bool $phaseOverrideOffen = false;

    /** @return array{0:string,1:?int} [owner_type, owner_id] — Host implementiert. */
    abstract protected function phaseOwner(): array;

    public function phaseSetzen(string $phase): void
    {
        $this->phaseFehler = null;
        [$type, $id] = $this->phaseOwner();
        if ($id === null) {
            return;
        }
        try {
            $note = trim($this->phaseOverrideNote) !== '' ? trim($this->phaseOverrideNote) : null;
            app(PhaseService::class)->setPhase(Auth::user()->currentTeamRelation, $type, $id, $phase, $note);
            $this->phaseOverrideNote = '';
            $this->phaseOverrideOffen = false;
        } catch (RuntimeException $e) {
            $this->phaseFehler = $e->getMessage();
            // Freigabe-Gate: Override-Feld anbieten statt Sackgasse
            $this->phaseOverrideOffen = str_contains($e->getMessage(), 'Freigabe-Gate');
        }
    }

    public function phasenListe(): array
    {
        return PhaseService::LABELS;
    }
}
