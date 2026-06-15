<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeTemplateService;
use Platform\FoodAlchemist\Support\TemplateSlotHeuristics;

/**
 * D-5: 📐 Aus Vorlage instanziieren — Render-First-Modal. Template wählen ist
 * vorgelagert (Browser-Picker dispatcht mit templateId); hier: Variante eingeben →
 * Slot-Vorschläge holen (deterministischer Seed; KI-Hook im Service) → pro Slot
 * reviewen/überschreiben → instanziieren. Ungebundene Slots bleiben Platzhalter.
 */
class TemplateInstantiateModal extends Component
{
    public ?int $templateId = null;

    public string $templateName = '';

    public string $basisName = '';

    public string $variant = '';

    public string $name = '';

    /** Hat das Template einen eigenen Body-Slot? Steuert Seed + Pool-Präferenz pro Slot. */
    public bool $hatBody = false;

    /**
     * Platzhalter-Slots des Templates. NICHT `$slots` nennen — der Name kollidiert
     * mit Livewires interner Slot-Mechanik (SupportSlots ruft getName() darauf auf).
     *
     * @var array<int, array{placeholder_name:string, menge:float, einheit:string, raw_text:string}>
     */
    public array $slotFelder = [];

    /** @var array<int, array{query:string, target:string, id:?int, name:?string, score:float}> */
    public array $bindings = [];

    public bool $creating = false;

    public ?string $fehler = null;

    #[On('template-instanziieren.oeffnen')]
    public function oeffnen(int $templateId, ?string $variant = null): void
    {
        $this->reset('fehler', 'variant', 'name', 'bindings', 'slotFelder', 'hatBody', 'templateId', 'templateName', 'basisName');

        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $svc = app(RecipeTemplateService::class);
        $template = FoodAlchemistRecipe::visibleToTeam($team)->basis()->where('is_template', true)->find($templateId);
        if ($template === null) {
            return;
        }

        $this->templateId = $templateId;
        $this->templateName = $template->name;
        $this->basisName = $svc->baseName($template);
        $this->variant = trim((string) $variant);

        $slots = $svc->slotsFor($team, $templateId);
        $this->hatBody = TemplateSlotHeuristics::hatDedizErtenBody(array_map(fn ($s) => $s['placeholder_name'], $slots));
        foreach ($slots as $s) {
            $this->slotFelder[$s['ingredient_id']] = [
                'placeholder_name' => $s['placeholder_name'],
                'menge' => $s['menge'],
                'einheit' => $s['einheit'],
                'raw_text' => $s['raw_text'],
            ];
            $this->bindings[$s['ingredient_id']] = ['query' => '', 'target' => 'none', 'id' => null, 'name' => null, 'score' => 0.0];
        }

        $this->dispatch('modal.open', name: 'template-instanziieren');
    }

    /** Deterministische Seed-Vorschläge für alle Slots + Namens-Vorschlag. */
    public function vorschlaege(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->templateId === null) {
            return;
        }
        if (trim($this->variant) === '') {
            $this->fehler = 'Bitte zuerst eine Variante eingeben, z. B. Brombeere.';

            return;
        }

        $this->name = $this->basisName . ': ' . trim($this->variant);
        foreach (app(RecipeTemplateService::class)->seedFill($team, $this->templateId, trim($this->variant)) as $f) {
            $this->bindings[$f['ingredient_id']] = [
                'query' => $f['query'],
                'target' => $f['target'],
                'id' => $f['item_id'],
                'name' => $f['item_name'],
                'score' => $f['score'],
            ];
        }
    }

    /** Einen Slot aus seinem (manuell editierten) Suchtext neu matchen. */
    public function matchSlot(int $ingredientId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || ! isset($this->slotFelder[$ingredientId])) {
            return;
        }
        $query = trim($this->bindings[$ingredientId]['query'] ?? '');
        if ($query === '') {
            $this->bindings[$ingredientId] = ['query' => '', 'target' => 'none', 'id' => null, 'name' => null, 'score' => 0.0];

            return;
        }
        $treffer = app(RecipeTemplateService::class)->matchOne($team, $this->slotFelder[$ingredientId]['placeholder_name'], $query, $this->hatBody);
        $this->bindings[$ingredientId] = [
            'query' => $query,
            'target' => $treffer['target'],
            'id' => $treffer['item_id'],
            'name' => $treffer['item_name'],
            'score' => $treffer['score'],
        ];
    }

    public function instanziieren(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->templateId === null) {
            return;
        }
        if (trim($this->name) === '') {
            $this->fehler = 'Instanz braucht einen Namen.';

            return;
        }

        $payload = [];
        foreach ($this->bindings as $rid => $b) {
            if (($b['id'] ?? null) === null) {
                continue;
            }
            $payload[(int) $rid] = ($b['target'] ?? null) === 'sub_recipe'
                ? ['referenced_recipe_id' => (int) $b['id']]
                : ['gp_id' => (int) $b['id']];
        }

        $this->creating = true;
        try {
            $res = app(RecipeTemplateService::class)->instantiate($team, $this->templateId, trim($this->name), $payload);
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('modal.close', name: 'template-instanziieren');
            // Frische Instanz auswählen + im Voll-Editor zur Review/Nachpflege öffnen.
            $this->dispatch('recipe-selected', id: $res['id']);
            $this->dispatch('recipe-modal.oeffnen', id: $res['id']);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        } finally {
            $this->creating = false;
        }
    }

    #[On('modal.closed')]
    public function beiSchliessen(?string $name = null): void
    {
        if ($name === null || $name === 'template-instanziieren') {
            $this->reset('templateId', 'templateName', 'basisName', 'variant', 'name', 'slotFelder', 'bindings', 'fehler', 'hatBody', 'creating');
        }
    }

    public function render()
    {
        $gebunden = collect($this->bindings)->filter(fn ($b) => ($b['id'] ?? null) !== null)->count();

        return view('foodalchemist::livewire.recipes.template-instantiate-modal', [
            // NICHT 'slots' nennen — Livewire SupportSlots überschreibt diese View-Variable
            // mit den (leeren) Component-Slots, der @foreach liefe dann 0×.
            'slotListe' => $this->slotFelder,
            'gebundenAnzahl' => $gebunden,
            'slotAnzahl' => count($this->slotFelder),
        ]);
    }
}
