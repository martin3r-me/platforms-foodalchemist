<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
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

    public string $zielTyp = 'concept';

    public ?int $auswahlConceptId = null;

    public ?int $auswahlRecipeId = null;

    public float $auswahlMenge = 100;

    public string $suche = '';

    public ?array $vorschau = null;

    public ?string $fehler = null;

    #[On('produktion-editor.oeffnen')]
    public function oeffnenNeu(): void
    {
        $this->reset(['orderId', 'name', 'reference', 'note', 'targets', 'auswahlConceptId', 'auswahlRecipeId', 'suche', 'vorschau', 'fehler']);
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
    }

    public function zielHinzufuegen(): void
    {
        $ziel = $this->zielTyp === 'concept'
            ? ['concept_id' => $this->auswahlConceptId, 'persons' => $this->auswahlMenge]
            : ['recipe_id' => $this->auswahlRecipeId, 'portions' => $this->auswahlMenge];

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

    private function labelFuer(array $ziel): string
    {
        $team = Auth::user()?->currentTeamRelation;
        if (! empty($ziel['concept_id'])) {
            $name = $team ? FoodAlchemistConcept::visibleToTeam($team)->find($ziel['concept_id'])?->name : null;

            return ($name ?? '#' . $ziel['concept_id']) . ' (' . $this->zahl($ziel['persons']) . ' P.)';
        }
        $name = $team ? FoodAlchemistRecipe::visibleToTeam($team)->find($ziel['recipe_id'])?->name : null;

        return ($name ?? '#' . $ziel['recipe_id']) . ' (' . $this->zahl($ziel['portions']) . ' Port.)';
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
        if ($team && $this->zielTyp === 'recipe' && trim($this->suche) !== '') {
            $treffer = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
                ->where('name', 'like', '%' . trim($this->suche) . '%')
                ->orderBy('name')->limit(20)->get(['id', 'name']);
        }

        return view('foodalchemist::livewire.produktion.editor', [
            'konzepte' => $konzepte,
            'treffer' => $treffer,
        ]);
    }
}
