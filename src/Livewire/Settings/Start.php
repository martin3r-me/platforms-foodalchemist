<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Support\EinrichtungsStatus;

/**
 * Einstellungen › Übersicht (Neuordnung 23.10., E-2): je Reiter eine Kachel mit den zählbaren Fakten seiner Bereiche
 * ({@see EinrichtungsStatus}) und Links direkt in den Bereich. Nur lesend; zeigt nur, was der Benutzer auch öffnen darf
 * (dieselbe Quelle wie die Reiter: {@see Index::sichtbareSektionen()}). Standortprofil und Abos bleiben eigene Seiten (Kachel-Link).
 */
class Start extends Component
{
    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $sichtbar = Index::sichtbareSektionen();
        $kacheln = [];
        $offen = 0;
        $erledigt = 0;
        foreach (Index::sichtbareGruppen($sichtbar) as $key => $g) {
            if ($key === 'uebersicht') {
                continue;
            }
            $zeilen = [];
            foreach ($g['sektionen'] as $s) {
                $status = $team !== null ? EinrichtungsStatus::fuer($s, $team) : null;
                $zeilen[] = ['sektion' => $s, 'label' => $sichtbar[$s]['label'], 'status' => $status,
                    'url' => route('foodalchemist.einstellungen', ['tab' => $key, 'bereich' => $s])];
                if (in_array($s, Index::GRUND, true) && $status !== null) {
                    $status['zustand'] === 'offen' ? $offen++ : $erledigt++;
                }
            }
            $kacheln[$key] = ['label' => $g['label'], 'zeilen' => $zeilen];
        }

        return view('foodalchemist::livewire.settings.start', [
            'kacheln' => $kacheln,
            'offen' => $offen,
            'erledigt' => $erledigt,
            'seiten' => array_filter([
                'standortprofil' => \Illuminate\Support\Facades\Route::has('foodalchemist.standortprofil.index') ? ['label' => 'Standortprofil', 'url' => route('foodalchemist.standortprofil.index')] : null,
                'abos' => \Illuminate\Support\Facades\Route::has('foodalchemist.abos.index') ? ['label' => 'Abos', 'url' => route('foodalchemist.abos.index')] : null,
            ]),
        ]);
    }
}
