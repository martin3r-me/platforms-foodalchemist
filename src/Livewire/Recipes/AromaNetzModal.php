<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * M5-07 / D-7: Aroma-Netz-Modal — Quell-Rezept zentral (orange), Ring aus
 * Pairing-Ankern (rosa, Kern-Anker ★), äußerer Ring verwandte Rezepte
 * (grün = Basis, blau = VK) mit Kanten zu gemeinsamen Ankern. Brücken-Typen
 * klassisch/modern/kontrast (GL-10); Hover über Anker = dessen Brücken,
 * Klick auf Rezept = öffnen; Toggle »Alle Aroma-Brücken« + Vorschlags-Modus
 * je Anker. Layout serverseitig (deterministisch), Interaktion Alpine-only —
 * nur der Vorschlags-Select macht einen Roundtrip.
 */
class AromaNetzModal extends Component
{
    private const W = 900;

    private const H = 640;

    private const R_ANKER = 200;

    private const R_VERWANDT = 292;

    private const R_VORSCHLAG = 248;

    public ?int $recipeId = null;

    /** Pairing-Vorschläge pro Anker: 0 = aus (Referenz-Default) */
    public int $vorschlaege = 0;

    #[On('aroma-netz.oeffnen')]
    public function oeffnen(int $recipeId): void
    {
        $this->recipeId = $recipeId;
        $this->vorschlaege = 0;
        $this->dispatch('modal.open', name: 'aroma-netz');
    }

    public function zeigeRezept(int $id): void
    {
        $this->dispatch('recipe-selected', id: $id);
        $this->dispatch('modal.close', name: 'aroma-netz');
    }

    public function render(PairingService $pairings)
    {
        $team = Auth::user()?->currentTeamRelation;
        $netz = $team !== null && $this->recipeId !== null
            ? $pairings->aromaNetz($team, $this->recipeId, $this->vorschlaege)
            : ['zentrum' => null, 'anker' => [], 'kanten' => [], 'verwandte' => [], 'vorschlaege' => []];

        return view('foodalchemist::livewire.recipes.aroma-netz-modal', $this->layout($netz));
    }

    /** Radial-Layout: Anker gleichmäßig, Verwandte am zirkulären Mittel ihrer Andock-Anker. */
    private function layout(array $netz): array
    {
        $cx = self::W / 2;
        $cy = self::H / 2;

        $winkel = [];
        $n = max(1, count($netz['anker']));
        foreach ($netz['anker'] as $i => &$a) {
            $w = 2 * M_PI * $i / $n - M_PI / 2;
            $winkel[$a['id']] = $w;
            $a['x'] = round($cx + self::R_ANKER * cos($w), 1);
            $a['y'] = round($cy + self::R_ANKER * sin($w), 1);
        }
        unset($a);

        $m = max(1, count($netz['verwandte']));
        $wunsch = [];
        foreach ($netz['verwandte'] as $j => $v) {
            $vx = $vy = 0.0;
            foreach ($v['shared_anker_ids'] as $aid) {
                if (isset($winkel[$aid])) {
                    $vx += cos($winkel[$aid]);
                    $vy += sin($winkel[$aid]);
                }
            }
            $wunsch[$j] = (abs($vx) < 1e-9 && abs($vy) < 1e-9)
                ? 2 * M_PI * $j / $m - M_PI / 2
                : atan2($vy, $vx);
        }
        // UI-Review: Kollisionsauflösung — nach Wunsch-Winkel sortieren und einen
        // MINDEST-Winkelabstand erzwingen (vorher klumpten die Verwandten an einem Punkt)
        asort($wunsch);
        $minAbstand = min(0.5, 2 * M_PI / max(1, $m));
        $vergeben = [];
        foreach ($wunsch as $j => $w) {
            while ($vergeben !== [] && $w - end($vergeben) < $minAbstand) {
                $w = end($vergeben) + $minAbstand;
            }
            $vergeben[] = $w;
            $netz['verwandte'][$j]['x'] = round($cx + self::R_VERWANDT * cos($w), 1);
            $netz['verwandte'][$j]['y'] = round($cy + self::R_VERWANDT * sin($w), 1);
        }

        // Vorschläge: radial außen am jeweiligen Anker, je Anker leicht gefächert
        $jeAnker = [];
        foreach ($netz['vorschlaege'] as &$s) {
            $i = $jeAnker[$s['anker_id']] = ($jeAnker[$s['anker_id']] ?? -1) + 1;
            $w = ($winkel[$s['anker_id']] ?? 0) + ($i - max(0, $this->vorschlaege - 1) / 2) * 0.18;
            $s['x'] = round($cx + self::R_VORSCHLAG * cos($w), 1);
            $s['y'] = round($cy + self::R_VORSCHLAG * sin($w), 1);
        }
        unset($s);

        return [
            'zentrum' => $netz['zentrum'],
            'anker' => $netz['anker'],
            'kanten' => $netz['kanten'],
            'verwandte' => $netz['verwandte'],
            // nicht 'vorschlaege' — das würde die int-Property im Blade verschatten
            'vorschlaege_liste' => $netz['vorschlaege'],
            'pos' => collect($netz['anker'])->keyBy('id'),
            'cx' => $cx,
            'cy' => $cy,
        ];
    }
}
