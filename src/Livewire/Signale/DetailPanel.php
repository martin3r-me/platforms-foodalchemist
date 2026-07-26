<?php

namespace Platform\FoodAlchemist\Livewire\Signale;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Jobs\SignalFixJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalFixService;
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
 * Ergänzt in S3b (Spec §7):
 *  3. Fix-Vorschau (Dry-Run) — „n Objekte, diese Felder, diese Werte" VOR dem Klick,
 *     statt „KI erledigen lassen" blind zu drücken.
 *  7. Teil-Bulk — Checkboxen auf den Objekten: „diese 12 fixen" statt alles-oder-nichts.
 *
 * Lifecycle-Aktionen (Erledigt/Ignorieren) und der Fix über den VOLLEN Satz bleiben in
 * der Signal-Zeile der ReviewQueue — ein zweiter Satz derselben Knöpfe wäre eine zweite
 * Wahrheit. Das Panel bekommt deshalb ausschließlich den Knopf, den die Zeile NICHT hat:
 * den auf die Auswahl geschnittenen Fix (anderer Scope, nicht dieselbe Aktion zweimal).
 */
class DetailPanel extends Component
{
    public ?int $signalId = null;

    /** Objekt-zentrische Sicht: welches Objekt ist aufgeklappt (Punkt 2). */
    public ?string $objektKind = null;

    public ?int $objektId = null;

    /** Sortierung der Objekt-Liste: 'name' | 'name_desc' | 'art'. */
    public string $sort = 'name';

    /** Teil-Bulk (Punkt 7): angehakte Objekt-IDs. Livewire liefert Checkbox-Werte als String. */
    public array $auswahl = [];

    /** Dry-Run-Ergebnis (Punkt 3) — transient, wird bei jeder Änderung verworfen. */
    public ?array $vorschau = null;

    public ?string $meldung = null;

    public ?string $fehler = null;

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
        $this->auswahlZuruecksetzen();
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
        $this->auswahlZuruecksetzen();
    }

    // ── S3b: Dry-Run (Punkt 3) + Teil-Bulk (Punkt 7) ───────────────────────

    /**
     * Eine Auswahl-Änderung verwirft die Vorschau: eine Feld-/Wert-Liste, die zu einer
     * anderen Auswahl gehört als der Knopf darunter, ist genau die Fehlinformation, die
     * der Dry-Run abschaffen soll.
     */
    public function updatedAuswahl(): void
    {
        $this->vorschau = null;
    }

    /** Alle sichtbaren, fixbaren Objekte anhaken (Kind gp/recipe). */
    public function alleWaehlen(SignalObjectService $objekte): void
    {
        $this->auswahl = array_map('strval', array_column($this->fixbareItems($objekte), 'id'));
        $this->vorschau = null;
    }

    public function auswahlLeeren(): void
    {
        $this->auswahlZuruecksetzen();
    }

    /** Dry-Run: „n Objekte, diese Felder, diese Werte" — auf die Auswahl oder den ganzen Satz. */
    public function vorschauZeigen(): void
    {
        $this->meldung = null;
        $this->fehler = null;
        [$team, $sig] = $this->kontext();
        if ($sig === null) {
            return;
        }
        try {
            $this->vorschau = app(SignalFixService::class)->vorschau($team, $sig, $this->idsOderNull());
        } catch (\RuntimeException $e) {
            $this->vorschau = null;
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * Fix auf die Auswahl anstoßen (Teil-Bulk). Ohne Auswahl passiert bewusst nichts —
     * „alles fixen" bleibt der Knopf in der Signal-Zeile, sonst hätte dieselbe Aktion
     * zwei Auslöser mit unterschiedlichem Scope-Default.
     */
    public function teilFixAusfuehren(): void
    {
        $this->meldung = null;
        $this->fehler = null;
        [$team, $sig] = $this->kontext();
        if ($sig === null) {
            return;
        }
        $ids = $this->idsOderNull();
        if ($ids === null) {
            $this->fehler = 'Erst Objekte anhaken — „alles fixen" läuft über den Knopf in der Signal-Zeile.';

            return;
        }
        $plan = SignalCockpit::planFor($sig);
        if ($plan === null || $plan['kind'] !== 'deterministic') {
            $this->fehler = 'Für dieses Signal gibt es keinen automatischen Fix.';

            return;
        }

        SignalFixJob::dispatch((int) $sig->id, (int) $team->id, $ids);
        $this->meldung = count($ids) . ' Objekt(e) werden behoben — die Liste aktualisiert sich, sobald der Lauf durch ist.';
        $this->auswahlZuruecksetzen();
        $this->dispatch('signal-geaendert');
    }

    /** @return array{0:?\Platform\Core\Models\Team,1:?FoodAlchemistSignal} */
    private function kontext(): array
    {
        $team = Auth::user()?->currentTeamRelation;
        $sig = $team !== null && $this->signalId !== null
            ? FoodAlchemistSignal::visibleToTeam($team)->find($this->signalId)
            : null;
        if ($sig === null) {
            $this->fehler = 'Signal nicht gefunden.';
        }

        return [$team, $sig];
    }

    /** @return list<int>|null */
    private function idsOderNull(): ?array
    {
        $ids = array_values(array_filter(array_map('intval', $this->auswahl), fn (int $i) => $i > 0));

        return $ids === [] ? null : $ids;
    }

    private function auswahlZuruecksetzen(): void
    {
        $this->auswahl = [];
        $this->vorschau = null;
        $this->meldung = null;
        $this->fehler = null;
    }

    /** @return list<array<string,mixed>> */
    private function fixbareItems(SignalObjectService $objekte): array
    {
        [$team, $sig] = $this->kontext();
        if ($sig === null) {
            return [];
        }
        $this->fehler = null;

        return array_values(array_filter(
            $objekte->betroffene($team, $sig)['items'],
            fn (array $i) => in_array($i['kind'], ['recipe', 'gp'], true) && (int) $i['id'] > 0
        ));
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
