<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use RuntimeException;

/**
 * Gericht-Vorgabe je Concept-Position (Dominique 13.11.2026): „das sind meine Gerichte, baue genau diese".
 *
 * Vorher zählte der Fan-out eines Concepts nur die leeren Positionen und ließ die KI frei erfinden
 * ({@see IdeenService::kiDivergenzConcept}); ein eigener Gerichtstitel an der Position ging nie in den Prompt.
 * Die Kette DAHINTER passt aber schon: Skizze (Titel + Beschreibung) → geplanter Gericht-Schritt → Freigabe →
 * Gericht in der Position. Hier entsteht nur der Einstieg für die EIGENE Skizze:
 *
 *   - {@see setze}: die Vorgabe an einer leeren Position (`dish_brief`, `dish_brief_note`) — Concepter, Leitstelle, MCP.
 *   - {@see planeVorgaben}: im Fan-out wird aus jeder Vorgabe 1:1 eine Skizze mit geplantem Schritt, ohne KI-Aufruf.
 *     Erfunden wird nur noch für Positionen OHNE Vorgabe.
 *   - {@see setzeGericht}: das gebaute Gericht kommt in die Position; der Wortlaut der Vorgabe bleibt als
 *     Kundenwording stehen (Entscheid Dominique: interner Name darf nach Namensregel normalisiert werden).
 *   - {@see uebernimmBestand}: Bestand zuerst (Entscheid Dominique): gibt es ein freigegebenes Gericht mit
 *     demselben Namen, schlägt der Schritt dieses vor; die Freigabe des Schritts übernimmt es, statt neu zu bauen.
 *
 * Eigener Service, weil PlanningCascadeService und ConceptService auf ihrer Größen-Basis stehen (Spec 85).
 */
final class ConceptVorgabenService
{
    public const QUELLE = 'vorgabe';

    private const TITEL_MAX = 255;

    private const NOTIZ_MAX = 2000;

    /** Vorgabe setzen, ändern oder (leerer Titel) löschen. Nur an einer leeren Gericht-Position des eigenen Teams. */
    public function setze(Team $team, int $slotId, ?string $titel, ?string $notiz = null): FoodAlchemistConceptSlot
    {
        $slot = $this->eigenePosition($team, $slotId);
        $titel = $this->sauber($titel, self::TITEL_MAX);
        if ($titel !== null && ! self::istLeer($slot)) {
            throw new RuntimeException('Eine Gericht-Vorgabe geht nur an einer leeren Position. Diese Position ist schon belegt.');
        }
        $slot->update([
            'dish_brief' => $titel,
            'dish_brief_note' => $titel === null ? null : $this->sauber($notiz, self::NOTIZ_MAX),
        ]);

        return $slot->refresh();
    }

    /** Leere Gericht-Position: kein Gericht, kein Paket, kein Struktur-Block. */
    public static function istLeer(FoodAlchemistConceptSlot $slot): bool
    {
        return $slot->sales_recipe_id === null && $slot->package_id === null && $slot->embedded_concept_id === null
            && ! in_array((string) $slot->type, ConceptService::STRUKTUR_TYPEN, true);
    }

    /**
     * Fan-out: aus jeder Vorgabe eine Skizze mit geplantem Gericht-Schritt (Vorschlag-Gate wie bei erfundenen
     * Gerichten: gebaut wird erst nach der Freigabe). Gibt die Positionen OHNE Vorgabe zurück — nur für die
     * wird noch erfunden.
     *
     * @param  Collection<int, FoodAlchemistConceptSlot>  $leere  leere Gericht-Positionen in Reihenfolge
     * @return Collection<int, FoodAlchemistConceptSlot>
     */
    public function planeVorgaben(Team $team, FoodAlchemistCascadeRunStep $conceptStep, Collection $leere): Collection
    {
        [$mit, $ohne] = $leere->partition(fn (FoodAlchemistConceptSlot $s) => trim((string) $s->dish_brief) !== '');

        foreach ($mit->values() as $idx => $slot) {
            $titel = trim((string) $slot->dish_brief);
            $notiz = $this->sauber($slot->dish_brief_note, self::NOTIZ_MAX);
            $bestand = $this->bestandsGericht($team, $titel);
            $komponenten = $this->komponentenAusTitel($titel);

            $idee = FoodAlchemistDishIdea::create([
                'team_id' => $team->id,
                'chapter_id' => null,
                'concept_id' => (int) $slot->concept_id,
                'position' => (int) FoodAlchemistDishIdea::where('team_id', $team->id)->where('concept_id', (int) $slot->concept_id)->max('position') + 1,
                'title' => $titel,
                'description' => $notiz,
                'sales_recipe_id' => $bestand?->id,
                'target_form' => 'einzel',
                'group_id' => null,
                'status' => 'entwurf',
                'created_via' => 'ui',
                'source_meta' => [
                    'quelle' => self::QUELLE,
                    'target_concept_slot_id' => (int) $slot->id,
                    'komponenten' => $komponenten,
                ],
            ]);

            FoodAlchemistCascadeRunStep::create([
                'team_id' => $team->id,
                'cascade_run_id' => (int) $conceptStep->cascade_run_id,
                'parent_step_id' => (int) $conceptStep->id,
                'kind' => 'gericht',
                'label' => Str::limit($bestand !== null ? 'Aus Bestand: ' . $bestand->name : $titel, 120),
                'status' => 'geplant',
                'sort' => $idx + 1,
                'context_snapshot' => [
                    'dish_idea_id' => (int) $idee->id,
                    'beschreibung' => $bestand !== null
                        ? 'Deine Vorgabe „' . $titel . '“ gibt es schon als freigegebenes Gericht. Die Freigabe übernimmt es in die Position, gebaut wird nichts Neues.'
                        : (string) ($notiz ?? ''),
                    'komponenten' => $komponenten,
                    'vorgabe' => true,
                    'bestand_recipe_id' => $bestand?->id,
                ],
            ]);
        }

        return $ohne->values();
    }

    /**
     * Die Vorgaben eines Concepts als Kontext für die Erfindung der übrigen Positionen: „steht schon im Menü,
     * passend dazu, nicht wiederholen".
     *
     * @return list<string>
     */
    public function vorgabenTitel(int $conceptId): array
    {
        return FoodAlchemistConceptSlot::where('concept_id', $conceptId)->whereNotNull('dish_brief')
            ->orderBy('position')->orderBy('id')->pluck('dish_brief')
            ->map(fn ($t) => trim((string) $t))->filter()->values()->all();
    }

    /**
     * Das gebaute Gericht in die Position setzen. Bei einer Vorgabe bleibt deren Wortlaut als Kundenwording der
     * Position stehen (ein schon gepflegtes Wording gewinnt), die Vorgabe selbst ist damit erledigt.
     */
    public function setzeGericht(Team $team, int $slotId, int $recipeId, ?FoodAlchemistDishIdea $idee = null): void
    {
        if ($slotId <= 0) {
            return;
        }
        $concepts = app(ConceptService::class);
        $slot = $concepts->fillSlot($team, $slotId, ['sales_recipe_id' => $recipeId, 'type' => 'gericht']);
        if ((($idee?->source_meta ?? [])['quelle'] ?? null) !== self::QUELLE) {
            return;
        }
        if (trim((string) $slot->wording) === '' && trim((string) $slot->dish_brief) !== '') {
            $concepts->setSlotWording($team, $slotId, (string) $slot->dish_brief);
        }
    }

    /**
     * Bestand zuerst: trägt die Skizze einer Vorgabe ein Bestandsgericht, übernimmt die Freigabe des Schritts es
     * in die Position — ohne Generator. true = erledigt (der Aufrufer baut nichts mehr).
     */
    public function uebernimmBestand(Team $team, int $ideaId, int $stepId): bool
    {
        $idee = FoodAlchemistDishIdea::where('team_id', $team->id)->find($ideaId);
        if ($idee === null || (($idee->source_meta ?? [])['quelle'] ?? null) !== self::QUELLE || $idee->sales_recipe_id === null) {
            return false;
        }
        $gericht = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->find((int) $idee->sales_recipe_id);
        if ($gericht === null) {
            return false;   // inzwischen gelöscht oder nicht mehr sichtbar → normal bauen
        }
        $slotId = (int) (($idee->source_meta ?? [])['target_concept_slot_id'] ?? 0);
        $this->setzeGericht($team, $slotId, (int) $gericht->id, $idee);
        $idee->update([
            'generation_status' => 'erstellt',
            'status' => 'freigegeben',
            'materialized_at' => now(),
            'materialized_ref' => ['concept_slot_id' => $slotId, 'recipe_id' => (int) $gericht->id],
            'source_meta' => array_merge($idee->source_meta ?? [], ['erdung' => 'bestand', 'original_titel' => (string) $idee->title]),
        ]);
        app(PlanningCascadeService::class)->markStepDone($stepId, 'recipe', (int) $gericht->id);

        return true;
    }

    /**
     * Freigegebenes Gericht mit demselben Namen (gleiches Token-Set, das Kürzel in eckigen Klammern zählt nicht).
     * Bewusst streng: ein ähnliches Gericht ist nicht dasselbe, und Entwürfe sind kein Bestand (Spec 80 B4).
     */
    public function bestandsGericht(Team $team, string $titel): ?FoodAlchemistRecipe
    {
        $ziel = $this->tokens($titel);
        if ($ziel === []) {
            return null;
        }
        $anker = collect($ziel)->sortByDesc(fn (string $t) => mb_strlen($t))->first();

        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)
            ->where('status', RecipeStatus::Approved)
            ->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $anker) . '%')
            ->orderBy('id')->limit(200)->get(['id', 'name', 'team_id'])
            ->first(fn (FoodAlchemistRecipe $r) => $this->tokens((string) $r->name) === $ziel);
    }

    /** @return list<string> sortiertes Token-Set ohne Klassen-Kürzel („[VOR] …") */
    private function tokens(string $name): array
    {
        $tokens = app(TokenEngine::class)->tokenize(trim((string) preg_replace('/^\s*\[[^\]]{1,12}\]\s*/u', '', $name)));
        sort($tokens);

        return array_values($tokens);
    }

    /**
     * „Graved Karotte | Dillcreme | Brot-Crumble" → drei Komponenten für den Bauplan. Ohne Trenner keine Vorgabe
     * für die Zerlegung: dann plant der Generator selbst.
     *
     * @return list<array{name: string}>
     */
    private function komponentenAusTitel(string $titel): array
    {
        $teile = array_values(array_filter(array_map('trim', explode('|', $titel)), fn (string $t) => $t !== ''));

        return count($teile) < 2 ? [] : array_map(fn (string $t) => ['name' => $t], $teile);
    }

    private function eigenePosition(Team $team, int $slotId): FoodAlchemistConceptSlot
    {
        $slot = FoodAlchemistConceptSlot::with('concept')->findOrFail($slotId);
        if ($slot->concept === null || ! in_array((int) $slot->concept->team_id, FoodAlchemistConcept::teamAncestryIds($team), true)) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Position nicht sichtbar.');
        }
        if (! $slot->concept->isOwnedBy($team)) {
            throw new RuntimeException('Geerbtes Konzept: Ändern kann es nur das Besitzer-Team.');
        }

        return $slot;
    }

    private function sauber(?string $text, int $max): ?string
    {
        $text = $text !== null ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
