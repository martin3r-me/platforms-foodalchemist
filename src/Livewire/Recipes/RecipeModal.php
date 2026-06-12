<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M4-06 / P-2: Rezept-Stammdaten-Modal — Name (§1-Syntax-Hint, „Name putzen"-KI),
 * Herkunft, Hauptgruppe→Kategorie, Geschmack/Fertigung, yield_kg_manual (A-3),
 * VK-Flag. Edit triggert Recompute bei kalkulations-relevanten Feldern.
 */
class RecipeModal extends Component
{
    private const LEER = [
        'name' => '', 'herkunft' => '', 'kategorie_id' => null, 'hauptgruppe_id' => null,
        'geschmacksrichtung' => '', 'fertigungstiefe' => '', 'arbeitszeit_min' => null,
        'temperatur' => '', 'funktion' => '', 'status' => 'draft',
        'yield_kg_manual' => null, 'beschreibung' => '', 'zubereitung' => '',
        'notizen_manual' => '', 'equipment_ids' => [], 'ist_verkaufsrezept' => false,
    ];

    public ?int $recipeId = null;

    public array $form = self::LEER;

    public ?string $fehler = null;

    #[On('recipe-modal.oeffnen')]
    public function oeffnen(?int $id = null): void
    {
        $this->reset('fehler');
        $this->recipeId = $id;
        $this->form = self::LEER;

        if ($id !== null) {
            $team = Auth::user()?->currentTeamRelation;
            $r = FoodAlchemistRecipe::visibleToTeam($team)->with(['kategorie:id,main_group_id', 'equipment:id'])->find($id);
            if ($r !== null) {
                $this->form = [
                    'name' => $r->name,
                    'herkunft' => $r->herkunft ?? '',
                    'kategorie_id' => $r->kategorie_id,
                    'hauptgruppe_id' => $r->kategorie?->main_group_id,
                    'geschmacksrichtung' => $r->geschmacksrichtung ?? '',
                    'fertigungstiefe' => $r->fertigungstiefe ?? '',
                    'arbeitszeit_min' => $r->arbeitszeit_min,
                    'temperatur' => $r->temperatur ?? '',
                    'funktion' => $r->funktion ?? '',
                    'status' => $r->status->value,
                    'yield_kg_manual' => $r->yield_kg_manual,
                    'beschreibung' => $r->beschreibung ?? '',
                    'zubereitung' => $r->zubereitung ?? '',
                    'notizen_manual' => $r->notizen_manual ?? '',
                    'equipment_ids' => $r->equipment()->pluck('foodalchemist_vocab_kochequipment.id')->map(fn ($i) => (string) $i)->all(),
                    'ist_verkaufsrezept' => (bool) $r->ist_verkaufsrezept,
                ];
            }
        }

        $this->dispatch('modal.open', name: 'recipe-modal');
    }

    public function speichern(RecipeService $recipes): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        try {
            $in = [...$this->form,
                'arbeitszeit_min' => $this->form['arbeitszeit_min'] !== null && $this->form['arbeitszeit_min'] !== '' ? (int) $this->form['arbeitszeit_min'] : null,
                'yield_kg_manual' => $this->form['yield_kg_manual'] !== null && $this->form['yield_kg_manual'] !== '' ? (float) str_replace(',', '.', (string) $this->form['yield_kg_manual']) : null,
            ];
            $recipe = $this->recipeId === null
                ? $recipes->create($team, $in)
                : $recipes->update($team, $this->recipeId, $in);

            $this->dispatch('modal.close', name: 'recipe-modal');
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $recipe->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** @var array<string, array{werte: array, confidence: float, begruendung: ?string}> transiente GL-07-Vorschläge */
    public array $kiVorschlag = [];

    // ── M4-11: GL-07-Lebenszyklus beschreibung ──────────────────────────

    public function ai_beschreibung(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.beschreibung', [
            'name' => $r?->name ?? $this->form['name'],
            'beschreibung' => $this->form['beschreibung'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(20)->all() ?? [],
        ]);
        $this->kiVorschlag['beschreibung'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_beschreibung(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['beschreibung'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->beschreibung_quelle === 'manual') {                          // GL-07 Override-First
            $this->fehler = 'Beschreibung ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $wert = $vorschlag['werte']['beschreibung'] ?? null;
        if (! is_string($wert) || trim($wert) === '') {
            $this->fehler = 'KI-Vorschlag enthält keine Beschreibung.';

            return;
        }
        $r->update(['beschreibung' => $wert, 'beschreibung_quelle' => 'ki', 'beschreibung_ai_confidence' => $vorschlag['confidence']]);
        $this->form['beschreibung'] = $wert;
        unset($this->kiVorschlag['beschreibung']);
    }

    public function clear_beschreibung(): void
    {
        $this->rezept()?->update(['beschreibung' => null, 'beschreibung_quelle' => null, 'beschreibung_ai_confidence' => null]);
        $this->form['beschreibung'] = '';
        unset($this->kiVorschlag['beschreibung']);
    }

    public function manual_beschreibung(): void
    {
        if (trim($this->form['beschreibung']) !== '') {
            $this->rezept()?->update(['beschreibung' => $this->form['beschreibung'], 'beschreibung_quelle' => 'manual', 'beschreibung_ai_confidence' => null]);
        }
    }

    // ── UI-Audit: GL-07-Lebenszyklus zubereitung (D-5 §4.2.5, V-02-Klasse) ──

    public function ai_zubereitung(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.zubereitung', [
            'name' => $r?->name ?? $this->form['name'],
            'zubereitung' => $this->form['zubereitung'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
        ]);
        $this->kiVorschlag['zubereitung'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_zubereitung(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['zubereitung'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->zubereitung_quelle === 'manual') {                            // GL-07 Override-First
            $this->fehler = 'Zubereitung ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $wert = $vorschlag['werte']['zubereitung'] ?? null;
        if (! is_string($wert) || trim($wert) === '') {
            $this->fehler = 'KI-Vorschlag enthält keine Zubereitung.';

            return;
        }
        $r->update(['zubereitung' => $wert, 'zubereitung_quelle' => 'ki', 'zubereitung_ai_confidence' => $vorschlag['confidence']]);
        $this->form['zubereitung'] = $wert;
        unset($this->kiVorschlag['zubereitung']);
    }

    public function clear_zubereitung(): void
    {
        $this->rezept()?->update(['zubereitung' => null, 'zubereitung_quelle' => null, 'zubereitung_ai_confidence' => null]);
        $this->form['zubereitung'] = '';
        unset($this->kiVorschlag['zubereitung']);
    }

    public function manual_zubereitung(): void
    {
        if (trim($this->form['zubereitung']) !== '') {
            $this->rezept()?->update(['zubereitung' => $this->form['zubereitung'], 'zubereitung_quelle' => 'manual', 'zubereitung_ai_confidence' => null]);
        }
    }

    // ── M4-11: GL-07-Lebenszyklus kategorie ─────────────────────────────

    public function ai_kategorie(AiGatewayService $ki, RecipeService $recipes): void
    {
        $r = $this->rezept();
        $team = Auth::user()?->currentTeamRelation;
        $vorschlag = $ki->propose('recipe.kategorie', [
            'name' => $r?->name ?? $this->form['name'],
            'kategorie_id' => $this->form['kategorie_id'],
            'kategorien' => $team !== null
                ? FoodAlchemistRecipeCategory::orderBy('id')->limit(200)->pluck('bezeichnung', 'id')->all()
                : [],
        ]);
        $this->kiVorschlag['kategorie'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_kategorie(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['kategorie'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->kategorie_quelle === 'manual') {
            $this->fehler = 'Kategorie ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $katId = $vorschlag['werte']['kategorie_id'] ?? null;
        $kategorie = $katId !== null ? FoodAlchemistRecipeCategory::find((int) $katId) : null;
        if ($kategorie === null) {
            $this->fehler = 'KI-Vorschlag enthält keine gültige Kategorie.';

            return;
        }
        $r->update([
            'kategorie_id' => $kategorie->id, 'kategorie_quelle' => 'ki',
            'kategorie_ai_confidence' => $vorschlag['confidence'],
            'kategorie_ai_begruendung' => $vorschlag['begruendung'],
        ]);
        $this->form['kategorie_id'] = $kategorie->id;
        $this->form['hauptgruppe_id'] = $kategorie->main_group_id;
        unset($this->kiVorschlag['kategorie']);
    }

    public function clear_kategorie(): void
    {
        $this->rezept()?->update(['kategorie_id' => null, 'kategorie_quelle' => null, 'kategorie_ai_confidence' => null, 'kategorie_ai_begruendung' => null]);
        $this->form['kategorie_id'] = null;
        unset($this->kiVorschlag['kategorie']);
    }

    public function manual_kategorie(): void
    {
        if ($this->form['kategorie_id'] !== null) {
            $this->rezept()?->update(['kategorie_id' => $this->form['kategorie_id'], 'kategorie_quelle' => 'manual', 'kategorie_ai_confidence' => null, 'kategorie_ai_begruendung' => null]);
        }
    }

    private function rezept(): ?FoodAlchemistRecipe
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($this->recipeId === null || $team === null) {
            return null;
        }

        return FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients:id,recipe_id,raw_text')->find($this->recipeId);
    }

    /** „Name putzen": §1-Syntax via KI-Gateway (GL-07: Vorschlag direkt ins Feld, nichts persistiert). */
    public function namePutzen(AiGatewayService $ki): void
    {
        if (trim($this->form['name']) === '') {
            return;
        }
        $vorschlag = $ki->propose('recipe.name_putzen', ['name' => trim($this->form['name'])]);
        if (! empty($vorschlag->werte['name']) && is_string($vorschlag->werte['name'])) {
            $this->form['name'] = $vorschlag->werte['name'];
        }
    }

    public function updatedFormHauptgruppeId(): void
    {
        $this->form['kategorie_id'] = null;                        // Kategorie hängt an der HG
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation;

        // UI-Audit: ehrliche Feld-Zustände für die KI-Felder-Sektion (vorher
        // zeigte »unbefüllt« trotz Inhalt — Quelle NULL bei Import-Beständen)
        $r = $this->rezept();
        $feldZustand = function (?string $inhalt, ?string $quelle): string {
            if ($inhalt === null || trim($inhalt) === '') {
                return 'unbefüllt';
            }

            return $quelle ?? 'import';
        };

        return view('foodalchemist::livewire.recipes.recipe-modal', [
            'neu' => $this->recipeId === null,
            'zustaende' => [
                'beschreibung' => $feldZustand($r?->beschreibung, $r?->beschreibung_quelle),
                'zubereitung' => $feldZustand($r?->zubereitung, $r?->zubereitung_quelle),
                'kategorie' => $r?->kategorie_id !== null ? ($r?->kategorie_quelle ?? 'import') : 'unbefüllt',
            ],
            'equipmentListe' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::orderBy('name')->get(['id', 'name']),
            'hauptgruppen' => $team !== null ? $recipes->mainGroups($team) : collect(),
            'kategorien' => $this->form['hauptgruppe_id'] !== null
                ? FoodAlchemistRecipeCategory::where('main_group_id', $this->form['hauptgruppe_id'])->orderBy('sort_order')->get()
                : collect(),
            'keyVorschau' => trim($this->form['name']) !== '' ? $recipes->rezeptKey($this->form['name']) : '',
        ]);
    }
}
