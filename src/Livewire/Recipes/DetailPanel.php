<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M4-05 / P-1: Rezept-DetailPanel (rechte Page-Sidebar) — KPI-Karte
 * (EK/kg·EK·Yield·Konfidenz), Beschreibung, Zutaten read-only mit GP-Links +
 * EK je Zeile + Lineage kursiv (Nachtrag 13_REFERENZ), Diät-&-Spezifikations-
 * Sektion (spec_*-Flags), Eignungs- + Equipment-Chips.
 * Verwandte-Rezepte/Kohäsion folgen mit M5 (GL-10-Daten).
 */
class DetailPanel extends Component
{
    public ?int $recipeId = null;

    /** Eingebettet als Editor-Kartei: blendet die im Editor redundante KPI/Beschreibung/Zutaten aus. */
    public bool $embedded = false;

    /** GP-Modal-Muster: section = genau EINE Kartei rendern (z.B. 'ersatz' als eigener Tab). */
    public ?string $section = null;

    public function mount(?int $recipeId = null, bool $embedded = false, ?string $section = null): void
    {
        $this->recipeId = $recipeId;
        $this->embedded = $embedded;
        $this->section = $section;
    }

    /** @var array<string, bool> M5-04/05: lazy Pairing-Sektionen (Kontext-Erhalt beim Wechsel) */
    public array $offen = [];

    public string $ankerSuche = '';

    public string $pairingSuche = '';

    public string $pairingTyp = 'klassisch';

    /** Ersatz-Logik: Suchtext für die Gegenseite (GP/Rezept) im Verknüpfen-Feld. */
    public string $ersatzSuche = '';

    #[On('recipe-selected')]
    public function zeige(int $id): void
    {
        if ($this->embedded) {
            return; // eingebettet als Editor-Kartei: bleibt auf dem Editor-Rezept, ignoriert Browser-Auswahl
        }
        $this->recipeId = $id;
        $this->ankerSuche = '';
        $this->pairingSuche = '';
        $this->ersatzSuche = '';
    }

    // ── Ersatz-Logik (make-or-buy): dieses Rezept ↔ Fertig-GP / Alternativ-Rezept ──

    public function ersatzVerknuepfen(string $kind, int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)
                ->verknuepfe($team, 'recipe', $this->recipeId, $kind, $id);
            $this->ersatzSuche = '';
        } catch (\RuntimeException $e) {
            $this->fehlerAnker = $e->getMessage();
        }
    }

    public function ersatzLoesen(int $equivId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null) {
            app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)->loese($team, $equivId);
        }
    }

    public ?string $fehlerEignung = null;

    /** Eignung-Chips klickbar (M9-01k-Service): aktiv → entfernen, inaktiv → als manual setzen. */
    public function eignungToggle(string $typ, string $slug): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehlerEignung = null;
        $svc = app(\Platform\FoodAlchemist\Services\RecipeService::class);
        try {
            $spalte = $typ === 'niveau' ? 'level_slug' : 'sektor_slug';
            $relation = $typ === 'niveau' ? 'niveauEignungen' : 'sektorEignungen';
            $aktiv = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($this->recipeId)
                ->{$relation}->pluck($spalte)->contains($slug);
            $aktiv
                ? $svc->entferneEignung($team, $this->recipeId, $typ, $slug)
                : $svc->setzeEignung($team, $this->recipeId, $typ, $slug);
        } catch (\RuntimeException $e) {
            $this->fehlerEignung = $e->getMessage();
        }
    }

    // ── Manuelle Pairings (recipe_pairings, created_via='manual') ──
    public function pairingVerknuepfen(int $ankerId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        app(\Platform\FoodAlchemist\Services\PairingService::class)
            ->setRecipePairing($team, $this->recipeId, $ankerId, $this->pairingTyp);
        $this->pairingSuche = '';
    }

    public function pairingLoesen(int $ankerId, ?string $typ = null): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(\Platform\FoodAlchemist\Services\PairingService::class)
                ->removeRecipePairing($team, $this->recipeId, $ankerId, $typ);
        }
    }

    public function toggleSektion(string $sektion): void
    {
        if (in_array($sektion, ['anker', 'pairing', 'nachbarn'], true)) {
            $this->offen[$sektion] = ! ($this->offen[$sektion] ?? false);
        }
    }

    // ── M5-04: Kern-Anker-Aktionen (Cap 5, manual gewinnt — GL-10 Inv. 1/3) ──

    public function ankerVerknuepfen(int $ankerId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\PairingService::class)->setRecipeAnker($team, $this->recipeId, $ankerId);
            $this->ankerSuche = '';
        } catch (\RuntimeException $e) {
            $this->fehlerAnker = $e->getMessage();
        }
    }

    public ?string $fehlerAnker = null;

    public function ankerLoesen(int $ankerId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->recipeId !== null) {
            app(\Platform\FoodAlchemist\Services\PairingService::class)->removeRecipeAnker($team, $this->recipeId, $ankerId);
        }
    }

    public function neuBerechnen(): void
    {
        if ($this->recipeId !== null) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate($this->recipeId);
            $this->dispatch('recipe-gespeichert');
        }
    }

    // ── M4-12: Workflow-Aktionen ─────────────────────────────────────────

    public function statusSetzen(string $status): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        app(RecipeService::class)->setStatus($team, $this->recipeId, $status);
        $this->dispatch('recipe-gespeichert');
    }

    public function duplizieren(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $original = app(RecipeService::class)->detail($team, $this->recipeId);
        if ($original === null) {
            return;
        }
        $kopie = app(RecipeService::class)->duplicate($team, $this->recipeId, $original->name . ' (Kopie)');
        $this->recipeId = $kopie->id;
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('recipe-selected', id: $kopie->id);
    }

    public function templateToggle(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        app(RecipeService::class)->setTemplate($team, $this->recipeId);
        $this->dispatch('recipe-gespeichert');
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null
            ? $recipes->detail($team, $this->recipeId)
            : null;

        $equivSvc = app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class);

        return view('foodalchemist::livewire.recipes.detail-panel', [
            'rezept' => $rezept,
            // Ersatz-Logik: Äquivalenzen dieses Rezepts + Such-Kandidaten fürs Verknüpfen
            'ersatz' => $rezept !== null && $team !== null ? $equivSvc->fuer($team, 'recipe', $rezept->id) : collect(),
            'ersatzKandidaten' => $rezept !== null && $team !== null && $this->ersatzSuche !== ''
                ? $equivSvc->sucheZiele($team, $this->ersatzSuche, 'recipe', $rezept->id) : collect(),
            // R6: Step-by-Step-Fotos (gruppiert nach Schritt)
            'schrittFotos' => $rezept !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $rezept->id)
                    ->orderBy('schritt_nr')->orderBy('sort_order')->orderBy('id')->get()->groupBy('schritt_nr')
                : collect(),
            // Nachtrag 13_REFERENZ: EK je Zeile — dieselbe T3-Kaskade wie der Recompute (eine Regel-Stelle)
            'zeilenEk' => $rezept !== null ? app(RecipeRecomputeService::class)->zeilenKosten($rezept) : [],
            // M4-10: ↑-Navigation („Verwendet in")
            'eltern' => $rezept !== null ? $recipes->getParents($team, $rezept->id) : collect(),
            // M5-04/05: lazy — nur offene Sektionen rechnen (P-1-Performance-Gebot)
            'kernAnker' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\PairingService::class)->recipeAnkers($rezept->id) : collect(),
            'kohaesion' => $rezept !== null && ($this->offen['anker'] ?? false)
                ? app(\Platform\FoodAlchemist\Services\PairingService::class)->recipeCohesion($rezept) : null,
            'pairings' => $rezept !== null && ($this->offen['pairing'] ?? false)
                ? app(\Platform\FoodAlchemist\Services\PairingService::class)->recipePairings($rezept->id) : null,
            'verwandte' => $rezept !== null && ($this->offen['pairing'] ?? false)
                ? app(\Platform\FoodAlchemist\Services\PairingService::class)->recipesSharingPairings($team, $rezept->id) : collect(),
            'nachbarn' => $rezept !== null && ($this->offen['nachbarn'] ?? false)
                ? app(\Platform\FoodAlchemist\Services\PairingService::class)->componentSuggestions($rezept) : null,
            'ankerKandidaten' => $this->ankerSuche !== ''
                ? \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_pairing_anchors')
                    ->whereRaw('LOWER(slug) LIKE ?', ['%' . mb_strtolower($this->ankerSuche) . '%'])
                    ->whereNull('deleted_at')->orderBy('slug')->limit(6)->get(['id', 'slug', 'display_de'])
                : collect(),
            'pairingKandidaten' => $this->pairingSuche !== '' && ($this->offen['pairing'] ?? false)
                ? \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_pairing_anchors')
                    ->whereRaw('LOWER(slug) LIKE ?', ['%' . mb_strtolower($this->pairingSuche) . '%'])
                    ->whereNull('deleted_at')->orderBy('slug')->limit(6)->get(['id', 'slug', 'display_de'])
                : collect(),
        ]);
    }
}
