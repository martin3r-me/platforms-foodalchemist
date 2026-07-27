<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;

/**
 * Spec 03 L7b-2b — die Auflösung EINER Hard-Stop-Zeile aus dem Generator-Lauf.
 *
 * Bis hierher endete der One-Shot an einer Sackgasse: „🔴 Kalbsjus — GP anlegen"
 * war reiner Text. Wer den Satz las, musste die Fläche verlassen, das Ziel
 * woanders anlegen und die Zeile später von Hand nachbinden. Diese Klasse ist
 * der fehlende Weg zurück — bewusst als schmaler Ein-Zeilen-Pfad, NICHT als
 * zweiter Zutaten-Schreibweg:
 *
 *  - `syncIngredients` ist Voll-Ersatz (und nullt dabei Felder, s. V-027). Für
 *    „binde genau diese eine Zeile" wäre das die falsche Waffe — der ganze
 *    Rest des frisch generierten Rezepts müsste dafür durchgereicht werden.
 *  - Darum: gezielter Row-Update + GENAU EIN `recomputeAndPropagate`, mit
 *    denselben Guards, die auch der Voll-Sync fährt (XOR, Besitzer-Team,
 *    Zyklus-Prüfung, Platzhalter-Ausschluss).
 *
 * Die drei Wege sind NICHT symmetrisch, und das ist Absicht:
 *  - `stubAnlegen`   → legt wirklich an (Basisrezept-Stub, Halbfabrikat-Fall)
 *  - `verknuepfe`    → bindet einen BESTANDS-Treffer aus der Shortlist
 *                      („Meintest du?" — der Mensch übersteuert das no_match)
 *  - `beschaffungAnstossen` → legt KEINEN GP an. Unter der LA-First-Doktrin
 *    (kein GP ohne LA) hat der Mint (`LaFirstGpService::mintFromLa`) im selben
 *    Lauf schon erfolglos gesucht; ein Knopf, der hier trotzdem einen GP
 *    schriebe, wäre genau der Guardrail-Bruch. Was der Mensch stattdessen
 *    auslösen kann, ist der Beschaffungs-Wunsch (`GpProposalService`) — und die
 *    Zeile bleibt ehrlich offen.
 */
class HardstopResolveService
{
    public function __construct(
        private readonly RecipeService $recipes,
        private readonly RecipeRecomputeService $recompute,
        private readonly GpProposalService $proposals,
    ) {}

    /**
     * Bestands-Treffer aus der Shortlist binden („Meintest du?").
     *
     * @param  string  $kind  gp|sub
     * @return array{ok: bool, meldung: string, kind?: string, name?: string}
     */
    public function verknuepfe(Team $team, int $recipeId, int $position, string $kind, int $zielId): array
    {
        $recipe = $this->besitzRezept($team, $recipeId);
        $zeile = $this->offeneZeile($recipe, $position);
        if ($zeile === null) {
            return ['ok' => false, 'meldung' => 'Zeile ist nicht mehr offen — vermutlich schon verknüpft.'];
        }

        if ($kind === 'sub') {
            if ($zielId === $recipe->id) {
                return ['ok' => false, 'meldung' => 'Selbstreferenz — ein Rezept kann sich nicht selbst enthalten.'];
            }
            $ziel = FoodAlchemistRecipe::visibleToTeam($team)->find($zielId);
            if ($ziel === null) {
                return ['ok' => false, 'meldung' => 'Basisrezept nicht gefunden.'];
            }
            $pruefung = $this->recompute->pruefeVerknuepfung($recipe->id, $zielId);
            if (! $pruefung['erlaubt']) {
                return ['ok' => false, 'meldung' => "Verknüpfung abgelehnt: {$pruefung['grund']}."];
            }
            $this->binde($zeile, null, (int) $ziel->id, MatchMethod::OverrideSubrecipe);

            return ['ok' => true, 'kind' => 'sub', 'name' => $ziel->name,
                'meldung' => '«' . $ziel->name . '» als Komponente verknüpft.'];
        }

        $gp = FoodAlchemistGp::visibleToTeam($team)->where('is_platzhalter', false)->find($zielId);
        if ($gp === null) {
            return ['ok' => false, 'meldung' => 'GP nicht gefunden (oder Platzhalter).'];
        }
        $this->binde($zeile, (int) $gp->id, null, MatchMethod::OverrideGp);

        return ['ok' => true, 'kind' => 'gp', 'name' => $gp->name,
            'meldung' => '«' . $gp->name . '» als GP verknüpft.'];
    }

    /**
     * Halbfabrikat-Lücke: Basisrezept-Stub anlegen und die Zeile darauf binden.
     * Idempotent über `createSubRecipeStub` (Dedupe by name).
     *
     * @return array{ok: bool, meldung: string, kind?: string, name?: string, neu?: bool, recipe_id?: int}
     */
    public function stubAnlegen(Team $team, int $recipeId, int $position, string $name): array
    {
        $recipe = $this->besitzRezept($team, $recipeId);
        $zeile = $this->offeneZeile($recipe, $position);
        if ($zeile === null) {
            return ['ok' => false, 'meldung' => 'Zeile ist nicht mehr offen — vermutlich schon verknüpft.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'meldung' => 'Kein Name für den Stub.'];
        }

        $stub = $this->recipes->createSubRecipeStub($team, $name, $recipe->id);
        if ((int) $stub['recipe']->id === $recipe->id) {
            return ['ok' => false, 'meldung' => 'Selbstreferenz — Stub-Name kollidiert mit dem Rezept selbst.'];
        }
        // Provenienz wie im Generator (`recipe_ref`): derselbe Mechanismus, nur
        // menschlich ausgelöst. Ein Override wäre es erst, wenn ein BESTAND
        // übersteuert würde — hier existierte vorher nichts.
        $this->binde($zeile, null, (int) $stub['recipe']->id, MatchMethod::RecipeRef);

        return ['ok' => true, 'kind' => 'sub', 'name' => $stub['recipe']->name,
            'neu' => (bool) $stub['neu'], 'recipe_id' => (int) $stub['recipe']->id,
            'meldung' => $stub['neu']
                ? 'Basisrezept-Stub «' . $stub['recipe']->name . '» angelegt und verknüpft — ausrezeptieren offen.'
                : 'Bestehendes Basisrezept «' . $stub['recipe']->name . '» verknüpft.'];
    }

    /**
     * GP-Lücke ohne passende LA → Beschaffungs-Wunsch (KEIN GP-Write, s.
     * Klassen-Doc). Die Zeile bleibt unmatched; das ist die ehrliche Ausgabe.
     *
     * @return array{ok: bool, meldung: string, created?: bool, proposal_id?: int}
     */
    public function beschaffungAnstossen(Team $team, int $recipeId, string $text, ?string $slug = null, ?int $userId = null): array
    {
        $recipe = $this->besitzRezept($team, $recipeId);
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'meldung' => 'Kein Zutatentext.'];
        }

        $ergebnis = $this->proposals->propose($team, [
            'name' => $text,
            'main_ingredient_slug' => $slug,
            'kontext' => "Hard-Stop im Generator-Lauf zu Rezept #{$recipe->id} ({$recipe->name})",
            'source_kind' => 'recipe',
            'source_id' => $recipe->id,
            'reasoning' => 'Weder Bestands-GP noch LA-First-Mint möglich — Artikel fehlt im Sortiment.',
        ], $userId);

        return ['ok' => true, 'created' => $ergebnis['created'], 'proposal_id' => (int) $ergebnis['proposal']->id,
            'meldung' => $ergebnis['created']
                ? '«' . $text . '» steht als Beschaffungs-Wunsch im Sourcing-Backlog. Die Zeile bleibt offen, bis es eine LA gibt.'
                : '«' . $text . '» stand bereits als Beschaffungs-Wunsch im Backlog (nicht doppelt angelegt).'];
    }

    /** Besitzer-Regel wie im Voll-Sync: geerbte Rezepte werden nicht geschrieben (D1). */
    private function besitzRezept(Team $team, int $recipeId): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Zutaten-Pflege nur durchs Besitzer-Team (D1).');
        }

        return $recipe;
    }

    /**
     * Die Zeile an dieser Position — aber NUR, solange sie wirklich offen ist.
     * Zwei Läufe auf denselben Hard-Stop (Doppelklick, zweiter Tab) dürfen den
     * ersten nicht überschreiben.
     */
    private function offeneZeile(FoodAlchemistRecipe $recipe, int $position): ?FoodAlchemistRecipeIngredient
    {
        return $recipe->ingredients()
            ->where('position', $position)
            ->whereNull('gp_id')
            ->whereNull('referenced_recipe_id')
            ->first();
    }

    private function binde(FoodAlchemistRecipeIngredient $zeile, ?int $gpId, ?int $subId, MatchMethod $methode): void
    {
        DB::transaction(function () use ($zeile, $gpId, $subId, $methode) {
            $zeile->update([
                'gp_id' => $gpId,
                'referenced_recipe_id' => $subId,
                'match_method' => $methode,
                'match_confidence' => null,   // menschliche Bindung hat keinen Score
            ]);
        });

        // Genau EIN Recompute je Auflösung (Yield/Allergene/EK + Eltern).
        $this->recompute->recomputeAndPropagate((int) $zeile->recipe_id);
    }
}
