<?php

namespace Platform\FoodAlchemist\Livewire\Preislisten;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\LieferantenStammdatenblattService;

/**
 * Stammdatenblatt-Vorschläge der Lieferanten (Stufe 2d): je Datensatz Vorher/Nachher, übernehmen oder verwerfen. Eigene Komponente, in die
 * Preislisten-Seite eingebettet. Desktop-Bereich, kein Lese-Modus.
 */
class LieferantenStammdatenblatt extends Component
{
    public ?string $meldung = null;

    public ?string $fehler = null;

    public function entscheiden(int $zeileId, string $entscheidung): void
    {
        $this->meldung = $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            $b = app(LieferantenStammdatenblattService::class)->entscheide($team, Auth::user(), [$zeileId], $entscheidung);
            $this->meldung = $b['uebernommen'] > 0 ? 'Angaben übernommen.' : ($b['verworfen'] > 0 ? 'Angaben verworfen.' : null);
            $this->fehler = $b['fehler'] !== [] ? implode(' ', $b['fehler']) : null;
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.preislisten.lieferanten-stammdatenblatt', [
            'zeilen' => $team !== null ? app(LieferantenStammdatenblattService::class)->offene($team, 100) : collect(),
            'feldnamen' => collect(LieferantenStammdatenblattService::STAMM_FELDER)->map(fn ($f) => $f[0])->all(),
        ]);
    }
}
