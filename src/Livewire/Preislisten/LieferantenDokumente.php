<?php

namespace Platform\FoodAlchemist\Livewire\Preislisten;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\LieferantenDokumentService;

/**
 * Datenblätter und Produktpässe, die Lieferanten über ihren Link eingereicht haben (Stufe 2c): übernehmen (Produktpass-Weg, die KI liest im
 * Hintergrund) oder verwerfen. Eigene Komponente, in die Preislisten-Seite eingebettet. Desktop-Bereich, kein Lese-Modus.
 */
class LieferantenDokumente extends Component
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
            $b = app(LieferantenDokumentService::class)->entscheide($team, Auth::user(), [$zeileId], $entscheidung);
            $this->meldung = $b['uebernommen'] > 0 ? 'Dokument übernommen, die KI liest es im Hintergrund.' : ($b['verworfen'] > 0 ? 'Dokument verworfen.' : null);
            $this->fehler = $b['fehler'] !== [] ? implode(' ', $b['fehler']) : null;
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.preislisten.lieferanten-dokumente', [
            'zeilen' => $team !== null ? app(LieferantenDokumentService::class)->offene($team, 50) : collect(),
        ]);
    }
}
