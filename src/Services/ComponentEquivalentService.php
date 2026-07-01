<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent as Equiv;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;

/**
 * Ersatz-Logik (make-or-buy + Artikel-Ersatz): Schreib-/Lese-Wege für den polymorphen
 * Äquivalenz-Katalog (GP↔Rezept, GP↔GP, Rezept↔Rezept) + der eigentliche Zutaten-Tausch.
 *
 * Der Katalog wird EINMAL team-weit gepflegt; der Tausch in einem Rezept hängt nur die
 * Zutat-FK um (gp_id XOR referenced_recipe_id) + Menge × umrechnungsfaktor und stößt
 * RecipeRecomputeService an (EK/Allergene/Yield). GL-07-Geist: nichts wird still bulk-
 * geswapt — swap_locked schützt eine bewusst gewählte Realisierung.
 */
class ComponentEquivalentService
{
    public function __construct(private RecipeRecomputeService $recompute)
    {
    }

    /** Äquivalenz anlegen/aktualisieren (dedupe je team+Seiten-Paar). Beide Seiten müssen existieren. */
    public function verknuepfe(
        Team $team,
        string $sourceKind,
        int $sourceId,
        string $altKind,
        int $altId,
        float $umrechnungsfaktor = 1.0,
        string $standardSeite = Equiv::SEITE_SOURCE,
        ?string $notes = null,
    ): Equiv {
        foreach ([$sourceKind, $altKind] as $k) {
            if (! in_array($k, [Equiv::KIND_GP, Equiv::KIND_RECIPE], true)) {
                throw new \RuntimeException("Ungültige Baustein-Art [{$k}].");
            }
        }
        if ($sourceKind === $altKind && $sourceId === $altId) {
            throw new \RuntimeException('Eine Realisierung kann nicht zu sich selbst äquivalent sein.');
        }
        if (Equiv::resolve($sourceKind, $sourceId) === null || Equiv::resolve($altKind, $altId) === null) {
            throw new \RuntimeException('Quelle oder Alternative existiert nicht.');
        }

        return Equiv::updateOrCreate(
            ['team_id' => $team->id, 'source_kind' => $sourceKind, 'source_id' => $sourceId, 'alt_kind' => $altKind, 'alt_id' => $altId],
            ['umrechnungsfaktor' => max(0.0001, $umrechnungsfaktor), 'standard_seite' => $standardSeite, 'notes' => $notes],
        );
    }

    public function loese(Team $team, int $id): void
    {
        Equiv::where('team_id', $team->id)->whereKey($id)->get()->each->delete();
    }

    /**
     * Alle Äquivalenzen, die (kind,id) berühren — je mit aufgelöster Gegenseite.
     *
     * @return Collection<int, object>
     */
    public function fuer(Team $team, string $kind, int $id): Collection
    {
        return Equiv::where('team_id', $team->id)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('source_kind', $kind)->where('source_id', $id))
                ->orWhere(fn ($w) => $w->where('alt_kind', $kind)->where('alt_id', $id)))
            ->get()
            ->map(function (Equiv $e) use ($kind, $id) {
                $gegen = $e->counterpartOf($kind, $id);
                $ziel = $gegen !== null ? Equiv::resolve($gegen['kind'], $gegen['id']) : null;

                return (object) [
                    'id' => (int) $e->id,
                    'gegen_kind' => $gegen['kind'] ?? null,
                    'gegen_id' => $gegen['id'] ?? null,
                    'gegen_name' => $ziel?->name ?? '—',
                    'umrechnungsfaktor' => (float) $e->umrechnungsfaktor,
                ];
            });
    }

    /** Such-Kandidaten für die Gegenseite (GPs + Rezepte, team-sichtbar), ohne den Ausgangs-Baustein selbst. */
    public function sucheZiele(Team $team, string $suche, string $exceptKind, int $exceptId, int $limit = 6): Collection
    {
        $such = mb_strtolower(trim($suche));
        if ($such === '') {
            return collect();
        }
        $gps = FoodAlchemistGp::visibleToTeam($team)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $such . '%'])
            ->when($exceptKind === Equiv::KIND_GP, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn ($g) => (object) ['kind' => Equiv::KIND_GP, 'id' => (int) $g->id, 'name' => $g->name]);

        $rez = FoodAlchemistRecipe::visibleToTeam($team)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $such . '%'])
            ->when($exceptKind === Equiv::KIND_RECIPE, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn ($r) => (object) ['kind' => Equiv::KIND_RECIPE, 'id' => (int) $r->id, 'name' => $r->name]);

        return $gps->concat($rez)->take($limit);
    }

    /**
     * Tausch einer Rezept-Zutat auf ihre Ersatz-Gegenseite (Fertig↔Selbst / Artikel↔Artikel)
     * + Recompute. Menge wird über umrechnungsfaktor richtungsabhängig umgerechnet.
     */
    public function tauscheZutat(Team $team, int $recipeIngredientId): FoodAlchemistRecipeIngredient
    {
        $zutat = FoodAlchemistRecipeIngredient::findOrFail($recipeIngredientId);
        if (! FoodAlchemistRecipe::visibleToTeam($team)->whereKey($zutat->recipe_id)->exists()) {
            throw new \RuntimeException('Rezept nicht im Zugriff (D1).');
        }

        [$kind, $id] = $zutat->gp_id !== null
            ? [Equiv::KIND_GP, (int) $zutat->gp_id]
            : [Equiv::KIND_RECIPE, (int) $zutat->referenced_recipe_id];

        $treffer = $this->fuer($team, $kind, $id)->first();
        if ($treffer === null) {
            throw new \RuntimeException('Kein Ersatz für diese Zutat hinterlegt.');
        }

        $e = Equiv::where('team_id', $team->id)->findOrFail($treffer->id);
        $gegen = $e->counterpartOf($kind, $id);
        $neueMenge = $e->convertMenge($zutat->menge !== null ? (float) $zutat->menge : 0.0, $gegen['von']);

        $zutat->update([
            'gp_id' => $gegen['kind'] === Equiv::KIND_GP ? $gegen['id'] : null,
            'referenced_recipe_id' => $gegen['kind'] === Equiv::KIND_RECIPE ? $gegen['id'] : null,
            'menge' => $neueMenge,
        ]);

        $this->recompute->recomputeAndPropagate((int) $zutat->recipe_id);

        return $zutat->refresh();
    }

    /** swap_locked einer Zutat setzen — schützt die Realisierung gegen eine Bulk-Umschaltung. */
    public function setSwapLocked(Team $team, int $recipeIngredientId, bool $locked): void
    {
        $zutat = FoodAlchemistRecipeIngredient::findOrFail($recipeIngredientId);
        if (FoodAlchemistRecipe::visibleToTeam($team)->whereKey($zutat->recipe_id)->exists()) {
            $zutat->update(['swap_locked' => $locked]);
        }
    }
}
