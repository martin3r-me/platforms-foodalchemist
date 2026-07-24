<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 — Produktionsauftrag-Editor (voller Modal, Karteien Stammdaten/Ziele/
 * Vorschau). Ziele leben lokal im Livewire-State — kein DB-Schreiben während der
 * Eingabe; die Vorschau ruft PlanungsblattService::produktionsblattFuerZiele()
 * direkt (derselbe unveränderte Rechenkern wie die bisherigen Planungsblätter).
 * Speichern persistiert Auftrag+Ziele+Zeilen in EINEM Rutsch.
 */
class Editor extends Component
{
    public ?int $orderId = null;

    public string $productionDate = '';

    public ?string $name = null;

    public ?string $reference = null;

    public ?string $note = null;

    /** @var list<array{source_ref:string, concept_id?:int, recipe_id?:int, persons?:float, portions?:float, label?:string}> */
    public array $targets = [];

    /** concept | recipe (VK-Gericht) | basisrezept (P1) | kapitel (Foodbook-Kapitel, P2). */
    public string $zielTyp = 'concept';

    public ?int $auswahlConceptId = null;

    public ?int $auswahlRecipeId = null;

    public float $auswahlMenge = 100;

    /** Nur für zielTyp='basisrezept': Menge in Ansätzen oder Kilogramm (P1). */
    public string $basisEinheit = 'ansaetze';

    /** Nur für zielTyp='kapitel' (P2): Foodbook + Kapitel + Personenzahl + Varianten-Wahl. */
    public ?int $auswahlFoodbookId = null;

    public ?int $auswahlChapterId = null;

    public ?int $auswahlPersonen = null;

    /** variant_group_id ⇒ gewählte block_id (Kapitel-Ziel). */
    public array $variantChoices = [];

    public string $suche = '';

    public ?array $vorschau = null;

    public ?string $fehler = null;

    #[On('produktion-editor.oeffnen')]
    public function oeffnenNeu(): void
    {
        $this->reset(['orderId', 'name', 'reference', 'note', 'targets', 'auswahlConceptId', 'auswahlRecipeId', 'suche', 'vorschau', 'fehler', 'basisEinheit', 'auswahlFoodbookId', 'auswahlChapterId', 'auswahlPersonen', 'variantChoices']);
        $this->productionDate = now()->toDateString();
        $this->auswahlMenge = 100;
        $this->dispatch('modal.open', name: 'produktion-editor');
    }

    #[On('produktion-editor.bearbeiten')]
    public function oeffnenBearbeiten(int $id, ProductionOrderService $svc): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $detail = $svc->detail($team, $id);
        $this->orderId = $id;
        $this->productionDate = (string) $detail['production_date'];
        $this->name = $detail['name'];
        $this->reference = $detail['reference'];
        $this->note = $detail['note'];
        $this->targets = $detail['targets'];
        $this->fehler = null;
        $this->berechneVorschau();
        $this->dispatch('modal.open', name: 'produktion-editor');
    }

    public function updatedZielTyp(): void
    {
        $this->auswahlConceptId = null;
        $this->auswahlRecipeId = null;
        $this->suche = '';
        $this->auswahlFoodbookId = null;
        $this->auswahlChapterId = null;
        $this->auswahlPersonen = null;
        $this->variantChoices = [];
        $this->fehler = null;
    }

    public function updatedAuswahlFoodbookId(): void
    {
        // Foodbook gewählt → Kapitel-/Varianten-Wahl zurücksetzen, Pax aus dem Foodbook vorbelegen.
        $this->auswahlChapterId = null;
        $this->variantChoices = [];
        $team = Auth::user()?->currentTeamRelation;
        $fb = ($team && $this->auswahlFoodbookId)
            ? FoodAlchemistFoodbook::visibleToTeam($team)->find((int) $this->auswahlFoodbookId)
            : null;
        $this->auswahlPersonen = $fb?->personen ?: ($this->auswahlPersonen ?: 10);
    }

    public function updatedAuswahlChapterId(): void
    {
        // Neues Kapitel → alte Varianten-Wahl verwerfen (gilt nur pro Kapitel-Scope).
        $this->variantChoices = [];
    }

    public function zielHinzufuegen(): void
    {
        if ($this->zielTyp === 'kapitel') {
            $this->kapitelZielHinzufuegen();

            return;
        }

        if ($this->zielTyp === 'concept') {
            $ziel = ['concept_id' => $this->auswahlConceptId, 'persons' => $this->auswahlMenge];
        } elseif ($this->zielTyp === 'basisrezept' && $this->basisEinheit === 'kg') {
            // P1: Basisrezept nach Kilogramm (Service rechnet kg ÷ Basis-Yield → ganze Ansätze).
            $ziel = ['recipe_id' => $this->auswahlRecipeId, 'amount_kg' => $this->auswahlMenge];
        } elseif ($this->zielTyp === 'basisrezept') {
            // Basisrezept nach Ansätzen (portions trägt beim Basisrezept die Ansatz-Zahl).
            $ziel = ['recipe_id' => $this->auswahlRecipeId, 'portions' => $this->auswahlMenge];
        } else {
            $ziel = ['recipe_id' => $this->auswahlRecipeId, 'portions' => $this->auswahlMenge];
        }

        if (empty($ziel['concept_id'] ?? null) && empty($ziel['recipe_id'] ?? null)) {
            return;
        }

        $sourceRef = ($this->zielTyp === 'concept' ? 'concept:' . $this->auswahlConceptId : 'recipe:' . $this->auswahlRecipeId) . '@' . uniqid();
        $this->targets[] = array_merge($ziel, ['source_ref' => $sourceRef, 'label' => $this->labelFuer($ziel)]);
        $this->auswahlConceptId = null;
        $this->auswahlRecipeId = null;
        $this->suche = '';
        $this->berechneVorschau();
    }

    public function zielEntfernen(string $sourceRef): void
    {
        $this->targets = collect($this->targets)->reject(fn ($t) => ($t['source_ref'] ?? null) === $sourceRef)->values()->all();
        $this->berechneVorschau();
    }

    /**
     * P2 Kapitel-Ziel: Kapitel über kapitelZiele() in eingefrorene Einzel-Ziele expandieren
     * (V2 „kein Live-Bezug") — spiegelt production_orders.ADD_TARGET (source_ref-Suffix „:c<idx>").
     */
    private function kapitelZielHinzufuegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || empty($this->auswahlChapterId)) {
            return;
        }
        $personen = max(1, (int) ($this->auswahlPersonen ?? 0));
        $res = app(PlanungsblattService::class)->kapitelZiele($team, (int) $this->auswahlChapterId, $personen, $this->variantChoices);
        if (empty($res['ziele'])) {
            $this->fehler = 'Kapitel liefert keine auflösbaren Ziele (nur sichtbare Gericht-/Konzept-Blocks).';

            return;
        }
        $chapter = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find((int) $this->auswahlChapterId);
        $kapLabel = $chapter?->title ?? ('Kapitel #' . $this->auswahlChapterId);
        $base = 'chapter:' . $this->auswahlChapterId . '@' . uniqid();
        foreach ($res['ziele'] as $i => $ziel) {
            $this->targets[] = array_merge($ziel, [
                'source_ref' => $base . ':c' . $i,
                'label' => $kapLabel . ' › ' . $this->labelFuer($ziel),
            ]);
        }
        $this->auswahlChapterId = null;
        $this->variantChoices = [];
        $this->fehler = null;
        $this->berechneVorschau();
    }

    /**
     * P2 „Edit": ein Einzel-Ziel zurück in den Picker laden und aus der Liste nehmen (Re-Add
     * ersetzt es). Kapitel-expandierte Teil-Ziele (source_ref „…:c<idx>") sind eingefroren —
     * für sie nur Entfernen, kein Edit.
     */
    public function zielBearbeiten(string $sourceRef): void
    {
        if (str_contains($sourceRef, ':c')) {
            return;
        }
        $t = collect($this->targets)->firstWhere('source_ref', $sourceRef);
        if ($t === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        if (! empty($t['concept_id'])) {
            $this->zielTyp = 'concept';
            $this->auswahlConceptId = (int) $t['concept_id'];
            $this->auswahlMenge = (float) ($t['persons'] ?? 100);
        } else {
            $recipe = $team ? FoodAlchemistRecipe::visibleToTeam($team)->find((int) $t['recipe_id']) : null;
            $istVerkauf = $recipe !== null && (bool) $recipe->is_sales_recipe;
            $this->zielTyp = $istVerkauf ? 'recipe' : 'basisrezept';
            $this->auswahlRecipeId = (int) $t['recipe_id'];
            $this->suche = $recipe?->name ?? '';
            if (isset($t['amount_kg'])) {
                $this->basisEinheit = 'kg';
                $this->auswahlMenge = (float) $t['amount_kg'];
            } else {
                $this->basisEinheit = 'ansaetze';
                $this->auswahlMenge = (float) ($t['portions'] ?? 100);
            }
        }
        $this->zielEntfernen($sourceRef);
    }

    private function labelFuer(array $ziel): string
    {
        $team = Auth::user()?->currentTeamRelation;
        if (! empty($ziel['concept_id'])) {
            $name = $team ? FoodAlchemistConcept::visibleToTeam($team)->find($ziel['concept_id'])?->name : null;

            return ($name ?? '#' . $ziel['concept_id']) . ' (' . $this->zahl($ziel['persons']) . ' P.)';
        }
        $name = $team ? FoodAlchemistRecipe::visibleToTeam($team)->find($ziel['recipe_id'])?->name : null;
        $anzeige = $name ?? '#' . $ziel['recipe_id'];
        // P1: kg-Ziel (Basisrezept) bzw. Ansätze (Basisrezept) vs. Portionen (VK-Gericht).
        if (isset($ziel['amount_kg'])) {
            return $anzeige . ' (' . $this->zahl((float) $ziel['amount_kg']) . ' kg)';
        }
        $einheit = $this->zielTyp === 'basisrezept' ? 'Ansätze' : 'Port.';

        return $anzeige . ' (' . $this->zahl((float) $ziel['portions']) . ' ' . $einheit . ')';
    }

    private function zahl(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
    }

    private function berechneVorschau(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->targets === []) {
            $this->vorschau = null;

            return;
        }
        $ziele = collect($this->targets)->map(fn ($t) => Arr::except($t, ['source_ref', 'label']))->values()->all();
        $this->vorschau = app(PlanungsblattService::class)->produktionsblattFuerZiele($team, $ziele);
    }

    public function speichern(ProductionOrderService $svc): void
    {
        $this->fehler = null;
        if ($this->productionDate === '' || trim((string) $this->name) === '' || $this->targets === []) {
            $this->fehler = 'Name, Datum und mindestens ein Ziel angeben.';

            return;
        }
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            if ($this->orderId === null) {
                $order = $svc->saveNew($team, $this->productionDate, trim((string) $this->name), $this->targets, $this->reference, $this->note, Auth::id());
            } else {
                $order = $svc->replaceTargets($team, $this->orderId, $this->targets);
                $order = $svc->updateHeader($team, $this->orderId, [
                    'name' => trim((string) $this->name),
                    'reference' => $this->reference,
                    'note' => $this->note,
                    'production_date' => $this->productionDate,
                ]);
            }
            $this->dispatch('modal.close', name: 'produktion-editor');
            $this->dispatch('produktion-gespeichert', id: (int) $order->id);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $konzepte = $team ? FoodAlchemistConcept::visibleToTeam($team)->orderBy('name')->get(['id', 'name']) : collect();
        $treffer = collect();
        if ($team && in_array($this->zielTyp, ['recipe', 'basisrezept'], true) && trim($this->suche) !== '') {
            // P1: VK-Gericht ⇒ ->verkauf(), Basisrezept ⇒ ->basis() (Suche ohne Verkauf-Scope).
            $query = FoodAlchemistRecipe::visibleToTeam($team);
            $query = $this->zielTyp === 'basisrezept' ? $query->basis() : $query->verkauf();
            $treffer = $query->where('name', 'like', '%' . trim($this->suche) . '%')
                ->orderBy('name')->limit(20)->get(['id', 'name']);
        }

        // P2 Kapitel-Picker: Foodbooks, Kapitel-Baum (flach + Tiefe) und Wahl-Gruppen.
        $foodbooks = collect();
        $kapitelBaum = collect();
        $variantGroups = [];
        if ($team && $this->zielTyp === 'kapitel') {
            $foodbooks = FoodAlchemistFoodbook::visibleToTeam($team)->orderBy('label')->get(['id', 'label', 'personen']);
            if ($this->auswahlFoodbookId) {
                $kapitelBaum = $this->kapitelBaumFuer($team, (int) $this->auswahlFoodbookId);
            }
            if ($this->auswahlChapterId) {
                $variantGroups = app(PlanungsblattService::class)->kapitelVarianten($team, (int) $this->auswahlChapterId)['groups'];
            }
        }

        return view('foodalchemist::livewire.produktion.editor', [
            'konzepte' => $konzepte,
            'treffer' => $treffer,
            'foodbooks' => $foodbooks,
            'kapitelBaum' => $kapitelBaum,
            'variantGroups' => $variantGroups,
        ]);
    }

    /**
     * Kapitel eines Foodbooks in Dokument-Reihenfolge mit Einrück-Tiefe (n-tiefer Baum via
     * parent_id) für das Picker-Select.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int, title:string, depth:int}>
     */
    private function kapitelBaumFuer($team, int $foodbookId)
    {
        $alle = FoodAlchemistFoodbookKapitel::visibleToTeam($team)
            ->where('foodbook_id', $foodbookId)
            ->orderBy('position')
            ->get(['id', 'parent_id', 'title', 'position']);

        $byParent = $alle->groupBy(fn ($k) => (int) ($k->parent_id ?? 0));
        $out = collect();
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, $out) {
            foreach ($byParent->get($parentId, collect()) as $k) {
                $out->push(['id' => (int) $k->id, 'title' => (string) $k->title, 'depth' => $depth]);
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }
}
