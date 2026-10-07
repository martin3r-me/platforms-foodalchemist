<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\TauschtRezept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M4-05 / P-1: Rezept-DetailPanel (rechte Page-Sidebar) — KPI-Karte
 * (EK/kg·EK·Yield·Konfidenz), Beschreibung, Zutaten read-only mit GP-Links +
 * EK je Zeile + Lineage kursiv (Nachtrag 13_REFERENZ), Diät-&-Spezifikations-
 * Sektion (spec_*-Flags), Eignungs- + Equipment-Chips.
 * Verwandte-Rezepte/Kohäsion folgen mit M5 (GL-10-Daten).
 */
class DetailPanel extends Component
{
    use TauschtRezept;   // Verwaltungs-Block: tauschen + löschen — identisch im Editor (RecipeModal)
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65

    /** Spec 65: gleiche Sperre wie der Basisrezept-Editor (Ziel recipe). */
    protected function sperrZiel(): ?array
    {
        return $this->recipeId !== null ? ['recipe', $this->recipeId] : null;
    }

    /**
     * Ohne Sperre: Anzeige/Navigation, Pairing nachladen, Sektionen aufklappen, Kopie anlegen (ändert das Original nicht),
     * Kosten neu rechnen (abgeleitete Werte, keine Pflege).
     */
    protected function sperrFreiExtra(): array
    {
        return ['pairingLaden', 'zeige', 'nachSpeichern', 'toggleSektion', 'duplizieren', 'neuBerechnen'];
    }

    public ?int $recipeId = null;

    /** Eingebettet als Editor-Kartei: blendet die im Editor redundante KPI/Beschreibung/Zutaten aus. */
    public bool $embedded = false;

    /** GP-Modal-Muster: section = genau EINE Kartei rendern (z.B. 'ersatz' als eigener Tab). */
    public ?string $section = null;

    /**
     * Pairing (Kombinationslogik + Netz) wird NACHGELADEN (Dominique 2026-10-07: Detailspalte lädt sehr langsam —
     * gemessen ~2–4 s nur fürs Pairing, bei jedem Klick). Der Platzhalter im Blade ruft pairingLaden() einmal je
     * Rezept (wire:key je Rezept, x-init). Ergebnis kurz gecacht, Schlüssel inkl. updated_at des Rezepts: Aktionen
     * in der Spalte (Eignung, Status …) rechnen es nicht neu; nach Speichern/Recompute ist der Schlüssel neu.
     * Achtung: Kombinationslogik::daten schreibt bei Basisrezepten das Rezept-Profil (Hash-Wechsel) — deshalb nur
     * einmal je Rezept anstoßen, nie pro Render.
     */
    public ?int $pairingFuer = null;

    public function pairingLaden(): void
    {
        $this->pairingFuer = $this->recipeId;
    }

    /** @return array{netz: array, kombination: ?array} */
    private function pairingDaten($team, FoodAlchemistRecipe $rezept): array
    {
        $schluessel = 'fa.detail.pairing.'.$team->id.'.'.$rezept->id.'.'.optional($rezept->updated_at)->timestamp;

        return \Illuminate\Support\Facades\Cache::remember($schluessel, 300, fn () => [
            'netz' => app(\Platform\FoodAlchemist\Services\PairingService::class)->pairingNetz($team, $rezept->id),
            'kombination' => app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class)->daten($rezept),
        ]);
    }

    public function mount(?int $recipeId = null, bool $embedded = false, ?string $section = null): void
    {
        $this->recipeId = $recipeId;
        $this->embedded = $embedded;
        $this->section = $section;
    }

    /** @var array<string, bool> M5-04/05: lazy Pairing-Sektionen (Kontext-Erhalt beim Wechsel) */
    public array $offen = [];

    /** Ersatz-Logik: Suchtext für die Gegenseite (GP/Rezept) im Verknüpfen-Feld. */
    public string $ersatzSuche = '';

    #[On('recipe-selected')]
    public function zeige(int $id): void
    {
        if ($this->embedded) {
            return; // eingebettet als Editor-Kartei: bleibt auf dem Editor-Rezept, ignoriert Browser-Auswahl
        }
        $this->recipeId = $id;
        $this->ersatzSuche = '';
        $this->tauschSuche = '';
        $this->fehlerTausch = null;
        $this->hinweisTausch = null;
    }

    /**
     * #511: nach jedem Save den Rezept-Kopf (EK/kg · EK · Yield · Allergene) neu
     * rendern — greift AUCH im embedded Editor-Kontext, wo zeige() (recipe-selected)
     * bewusst früh aussteigt und den Kopf sonst nur am generischen Re-Render hinge.
     * Re-render liest die frischen Aggregate aus der DB (render() → detail()).
     */
    #[On('recipe-gespeichert')]
    public function nachSpeichern(): void
    {
        // no-op: die Präsenz des Listeners löst das Re-Rendering des Panels aus.
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
            $spalte = $typ === 'level' ? 'level_slug' : 'sector_slug';
            $relation = $typ === 'level' ? 'niveauEignungen' : 'sektorEignungen';
            $aktiv = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($this->recipeId)
                ->{$relation}->pluck($spalte)->contains($slug);
            $aktiv
                ? $svc->entferneEignung($team, $this->recipeId, $typ, $slug)
                : $svc->setzeEignung($team, $this->recipeId, $typ, $slug);
        } catch (\RuntimeException $e) {
            $this->fehlerEignung = $e->getMessage();
        }
    }

    public function toggleSektion(string $sektion): void
    {
        if (in_array($sektion, ['anker', 'pairing', 'nachbarn'], true)) {
            $this->offen[$sektion] = ! ($this->offen[$sektion] ?? false);
        }
    }

    /** Fehler beim Ersatz-Verknüpfen (Name historisch). */
    public ?string $fehlerAnker = null;

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
            'sperr' => $this->sperrZustand(),   // Spec 65
            'rezept' => $rezept,
            // KI-Kontext der Erstellung (2026-09-06): Call-Log-Zeile des Generators, ans Rezept gehängt.
            'kiKontext' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService::class)->fuerRezept($rezept) : null,
            'kiHistorie' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService::class)->historieFuerRezept($rezept) : [],
            // Spec 43: Gericht-Foto als Mini-Bild im Detail-Panel (Basisrezepte + Gerichte).
            'rezeptBildUrl' => ($rezept !== null && ($rezept->image_context_file_id || $rezept->image_path))
                ? app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($rezept->image_context_file_id, $rezept->image_path)
                : null,
            // Ersatz-Logik: Äquivalenzen dieses Rezepts + Such-Kandidaten fürs Verknüpfen
            'ersatz' => $rezept !== null && $team !== null ? $equivSvc->fuer($team, 'recipe', $rezept->id) : collect(),
            'ersatzKandidaten' => $rezept !== null && $team !== null && $this->ersatzSuche !== ''
                ? $equivSvc->sucheZiele($team, $this->ersatzSuche, 'recipe', $rezept->id) : collect(),
            // Spec 27: die Anleitung als Schritte (Nummer + Text + verknüpfte Fotos)
            'schritte' => $rezept !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::where('recipe_id', $rezept->id)
                    ->with('photos')->orderBy('position')->orderBy('id')->get()
                : collect(),
            // Endprodukt-Bild („so soll es fertig aussehen") — steht ganz oben im Panel
            'endprodukt' => $rezept !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $rezept->id)
                    ->where('is_result', true)->first()
                : null,
            // Fotos ohne Schritt-Verknüpfung und ohne Endprodukt-Markierung = sonstige Rezept-Fotos
            'allgemeineFotos' => $rezept !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $rezept->id)
                    ->where('is_result', false)->whereDoesntHave('steps')
                    ->orderBy('sort_order')->orderBy('id')->get()
                : collect(),
            // Nachtrag 13_REFERENZ: EK je Zeile — dieselbe T3-Kaskade wie der Recompute (eine Regel-Stelle)
            'zeilenEk' => $rezept !== null ? app(RecipeRecomputeService::class)->zeilenKosten($rezept, $team) : [],
            // M4-10: ↑-Navigation („Verwendet in")
            'eltern' => $rezept !== null ? $recipes->getParents($team, $rezept->id) : collect(),
            // Verwaltungs-Block (tauschen + löschen) — nur im Standalone-Panel, wie beim GP
            'tauschBilanz' => $rezept !== null && $this->section === null ? $this->tauschBilanz() : null,
            'tauschKandidaten' => $rezept !== null && $this->section === null ? $this->tauschKandidaten() : collect(),
            'tauschReferenzen' => $rezept !== null && $this->section === null ? $this->tauschReferenzen() : null,
            // v3-Redesign: Standalone-Sidebar nicht mehr ausklappbar → Netz/Kohäsion/Pairings
            // direkt laden, aber NUR standalone (im Editor-Embed/nur-Sektion bleiben sie ungenutzt → gespart).
            // Layer: Pairing-Netz-Daten hier laden statt in der anonymen x-Komponente (gleiche Guard wie kohaesion/pairings = nur Standalone-Panel).
            // Spec 60 · P7: Kombinationslogik + Netz — nur Standalone-Panel, NACHGELADEN (pairingLaden).
            ...$this->pairingFuerView($team, $rezept, $rezept !== null && ! $this->embedded && $this->section === null),
        ]);
    }

    /** View-Variablen fürs Pairing: leer, bis pairingLaden() für dieses Rezept gelaufen ist. */
    private function pairingFuerView($team, ?FoodAlchemistRecipe $rezept, bool $erlaubt): array
    {
        $bereit = $erlaubt && $rezept !== null && $team !== null && $this->pairingFuer === $rezept->id;
        $daten = $bereit ? $this->pairingDaten($team, $rezept) : ['netz' => ['nodes' => [], 'edges' => [], 'meta' => []], 'kombination' => null];

        return ['pairingBereit' => $bereit, 'pairingErlaubt' => $erlaubt] + $daten;
    }
}
