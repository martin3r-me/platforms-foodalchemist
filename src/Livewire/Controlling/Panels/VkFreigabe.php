<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VkSnapshotService;

/**
 * Spec 32 — VK-Batch-Freigabe: der Knopf, der zu R2.5 gehört und nie gebaut wurde.
 *
 * Die Trennung ist seit R2.5 im Datenmodell da: die interne Marge rechnet auf dem LIVE-VK
 * (`recipe_darreichungen.sales_net`, bei jeder Preisänderung neu), der KUNDE sieht nur den
 * freigegebenen Snapshot (`foodalchemist_vk_price_snapshots`). {@see VkSnapshotService} kann
 * das seither — es gab bloß keine Oberfläche dafür, also war der Mechanismus faktisch tot.
 *
 * Zwei Listen, weil es zwei verschiedene Fragen sind:
 *  - **Weggelaufen** ({@see VkSnapshotService::pending}): freigegeben, aber der Live-Preis hat
 *    sich über die Leitplanke hinaus entfernt. Das ist der Fall, den auch der Signal-Detektor
 *    als `vk_anpassung_empfohlen` meldet.
 *  - **Nie freigegeben** ({@see VkSnapshotService::nieFreigegeben}): der Erstfall. Ohne ihn wäre
 *    die Fläche in einem Betrieb, der noch nie freigegeben hat, dauerhaft leer.
 *
 * Freigabe ist und bleibt menschlich und ausdrücklich — es gibt hier kein Auto-Publish.
 */
class VkFreigabe extends Component
{
    /** @var list<int> markierte Darreichungen */
    public array $auswahl = [];

    public ?string $hinweis = null;

    public ?string $fehler = null;

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    public function auswahlLeeren(): void
    {
        $this->auswahl = [];
    }

    /** Alle abgedrifteten Preise markieren — der häufigste Fall („nachziehen, was weggelaufen ist"). */
    public function alleAbgedriftet(VkSnapshotService $snap): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }

        $this->auswahl = array_map(fn ($z) => (int) $z['presentation_id'], $snap->pending($team));
    }

    public function freigeben(VkSnapshotService $snap): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $team = $this->team();
        if ($team === null || $this->auswahl === []) {
            $this->fehler = $team === null ? 'Kein Team zugeordnet.' : 'Nichts markiert.';

            return;
        }

        // release() filtert selbst auf team-eigene Darreichungen und meldet, wie viele
        // wirklich geschrieben wurden — die Differenz wird ausgewiesen statt verschwiegen.
        $gewuenscht = count(array_unique(array_map('intval', $this->auswahl)));
        $n = $snap->release($team, $this->auswahl, Auth::id());

        $this->auswahl = [];
        $this->hinweis = $n . ' Preis(e) freigegeben — ab jetzt sieht der Kunde diesen Stand.'
            . ($n < $gewuenscht ? ' ' . ($gewuenscht - $n) . ' übersprungen (nicht team-eigen).' : '');
    }

    public function render(VkSnapshotService $snap, TeamSettingsService $settings)
    {
        $team = $this->team();

        return view('foodalchemist::livewire.controlling.panels.vk-freigabe', [
            'abgedriftet' => $team !== null ? $snap->pending($team) : [],
            'neu' => $team !== null ? $snap->nieFreigegeben($team) : [],
            'schwelle' => $team !== null ? $settings->maxVkDeltaPct($team) : null,
        ]);
    }
}
