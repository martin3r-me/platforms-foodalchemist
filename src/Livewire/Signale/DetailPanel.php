<?php

namespace Platform\FoodAlchemist\Livewire\Signale;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalObjectService;
use Platform\FoodAlchemist\Support\SignalCockpit;

/**
 * Spec 21 · Tranche P (Etappe S3a) — Signal-DetailPanel für die rechte Fläche der
 * Signale-Seite. Sie war die einzige der sieben Cockpit-Seiten ohne Panel; die
 * betroffenen Objekte hingen vorher als 50er-Liste unter der Signal-Zeile.
 *
 * Gebaut in S3a (Spec §7):
 *  1. Betroffene Objekte — volle Liste (bis {@see SignalObjectService::PANEL_LIMIT}),
 *     sortierbar, Klick öffnet Rezept-/VK-Modal bzw. springt in den GP-Katalog.
 *  2. Objekt-zentrische Sicht — „was hat dieses Rezept noch?": alle offenen Signale
 *     am selben Objekt, damit man es EINMAL richtig fixt statt dreimal einzeln.
 *
 * Read-only: das Panel löst auf und verlinkt, es mutiert nichts. Lifecycle-Aktionen
 * (Erledigt/Ignorieren/KI) bleiben in der Signal-Zeile der ReviewQueue — ein zweiter
 * Satz derselben Knöpfe wäre eine zweite Wahrheit.
 */
class DetailPanel extends Component
{
    public ?int $signalId = null;

    /** Objekt-zentrische Sicht: welches Objekt ist aufgeklappt (Punkt 2). */
    public ?string $objektKind = null;

    public ?int $objektId = null;

    /** Sortierung der Objekt-Liste: 'name' | 'name_desc' | 'art'. */
    public string $sort = 'name';

    public function mount(?int $signalId = null): void
    {
        $this->signalId = $signalId;
    }

    #[On('signal-selected')]
    public function zeige(int $id): void
    {
        $this->signalId = $id;
        $this->objektKind = null;
        $this->objektId = null;
    }

    /**
     * Nach einer Lifecycle-Aktion in der Zeile (erledigt/ignoriert/wieder offen) neu
     * rendern: die Liste am Objekt darf nicht ein bereits geschlossenes Signal zeigen.
     * Die Präsenz des Listeners genügt — render() liest ohnehin frisch.
     */
    #[On('signal-geaendert')]
    public function nachAenderung(): void
    {
        // no-op
    }

    /** Objekt auf-/zuklappen — „was hat dieses Rezept noch?" (Punkt 2). */
    public function objektWaehlen(string $kind, int $id): void
    {
        if ($this->objektKind === $kind && $this->objektId === $id) {
            $this->objektKind = null;
            $this->objektId = null;

            return;
        }
        $this->objektKind = $kind;
        $this->objektId = $id;
    }

    public function setSort(string $s): void
    {
        if (in_array($s, ['name', 'name_desc', 'art'], true)) {
            $this->sort = $s;
        }
    }

    /** Aus der Objekt-Sicht auf ein anderes Signal desselben Objekts springen. */
    public function signalOeffnen(int $id): void
    {
        $this->signalId = $id;
    }

    public function render(SignalObjectService $objekte)
    {
        $team = Auth::user()?->currentTeamRelation;
        $sig = $team !== null && $this->signalId !== null
            ? FoodAlchemistSignal::visibleToTeam($team)->find($this->signalId)
            : null;

        $betroffen = $team !== null && $sig !== null ? $objekte->betroffene($team, $sig) : null;
        if ($betroffen !== null) {
            $betroffen['items'] = $this->sortiere($betroffen['items']);
        }

        // Objekt-Sicht nur für das aufgeklappte Objekt auflösen (ein EXISTS je Metrik).
        $objektSignale = $team !== null && $sig !== null && $this->objektKind !== null && $this->objektId !== null
            ? $objekte->signaleAmObjekt($team, $this->objektKind, $this->objektId)
            : [];

        return view('foodalchemist::livewire.signale.detail-panel', [
            'sig' => $sig,
            'plan' => $sig !== null ? SignalCockpit::planFor($sig) : null,
            'betroffen' => $betroffen,
            'objektSignale' => $objektSignale,
            'panelLimit' => SignalObjectService::PANEL_LIMIT,
        ]);
    }

    /**
     * Sortierung in PHP, nicht in SQL: die Liste ist bereits gekappt geladen (die
     * Metrik-Query ordnet alphabetisch), und ein zweiter Query-Pfad je Sortierung
     * wäre eine zweite Wahrheit über dasselbe Prädikat.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function sortiere(array $items): array
    {
        usort($items, function (array $a, array $b) {
            return match ($this->sort) {
                'name_desc' => mb_strtolower((string) $b['name']) <=> mb_strtolower((string) $a['name']),
                // 'art': GP vor Rezept, Verkaufsgericht vor Basisrezept, dann Name.
                'art' => [$a['kind'], ! $a['is_sales_recipe'], mb_strtolower((string) $a['name'])]
                    <=> [$b['kind'], ! $b['is_sales_recipe'], mb_strtolower((string) $b['name'])],
                default => mb_strtolower((string) $a['name']) <=> mb_strtolower((string) $b['name']),
            };
        });

        return $items;
    }
}
