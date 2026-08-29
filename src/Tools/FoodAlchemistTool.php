<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M8-01: Basis für Modul-Tools (ToolContract) — Naming `<modul>.resource.VERB`
 * (REST-Verben; Punkte werden vom MCP-Server zu __). Tools rufen SERVICES,
 * nie Models (LLM-First-Prinzip); Team kommt aus dem ToolContext.
 */
abstract class FoodAlchemistTool
{
    /** Phase A: Nur in diesen Status darf der MCP-Pfad Rezepte mutieren (Draft-Quarantäne). */
    protected const KI_EDITIERBARE_STATUS = ['stub', 'draft'];

    protected function team(ToolContext $context): ?Team
    {
        $team = $context->team;
        if ($team instanceof Team) {
            return $team;
        }
        // Kontext liefert je nach Aufrufpfad das Core-Team-Objekt oder nichts —
        // dann auf die User-Relation zurückfallen (gleiches Verhalten wie UI)
        $user = $context->user;

        return method_exists($user, 'currentTeamRelation') ? $user->currentTeamRelation : null;
    }

    /**
     * Phase A: MCP-Zutat-Zeilen → syncIngredients-Format. Löst `unit`
     * (Slug/Name) in unit_vocab_id auf; wirft RuntimeException mit
     * verfügbaren Einheiten, wenn nichts passt.
     *
     * @return array<int, array>
     */
    protected function normalisiereZutatZeilen(Team $team, array $zeilen): array
    {
        $vocab = app(\Platform\FoodAlchemist\Services\VocabularyService::class);
        $out = [];
        foreach (array_values($zeilen) as $i => $z) {
            $unit = $vocab->findEinheit($team, (string) ($z['unit'] ?? ''));
            if ($unit === null) {
                $verfuegbar = $vocab->listEinheiten($team)->pluck('slug')->implode(', ');
                throw new \RuntimeException('Unbekannte Einheit "' . ($z['unit'] ?? '') . '" (Zeile ' . ($i + 1) . "). Verfügbar: {$verfuegbar}");
            }
            $out[] = [
                'gp_id' => $z['gp_id'] ?? null,
                'referenced_recipe_id' => $z['referenced_recipe_id'] ?? null,
                'raw_text' => (string) ($z['name'] ?? ''),
                'display_name' => (string) ($z['name'] ?? ''),
                'quantity' => $z['quantity'] ?? 0,
                'quantity_max' => $z['quantity_max'] ?? null,
                'unit_vocab_id' => $unit->id,
                'trimming_loss_pct' => $z['trimming_loss_pct'] ?? null,
                'cooking_loss_pct' => $z['cooking_loss_pct'] ?? null,
                'cooking_loss_source' => isset($z['cooking_loss_pct']) ? 'ki' : null,   // GL-07-Lineage
                'is_optional' => (bool) ($z['is_optional'] ?? false),
                'note' => $z['note'] ?? null,
                'role' => $z['role'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * M1: Darreichungen (Servierformen) eines Gerichts kompakt — Form, EK/VK je Form,
     * Standard-Marker, W% (Wareneinsatz). Für verkaufsrezepte.SEARCH/GET, damit externe
     * LLM-Clients das Darreichungs-Modell sehen (nicht nur den vk_netto-Spiegel).
     *
     * @return list<array>
     */
    protected function darreichungenSummary(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe): array
    {
        return $recipe->presentations()->with('servingForm')->orderByDesc('is_standard')->orderBy('id')->get()
            ->map(function ($d) {
                $ek = $d->ek_portion !== null ? (float) $d->ek_portion : null;
                $vk = $d->sales_net !== null ? (float) $d->sales_net : null;

                return [
                    'presentation_id' => $d->id,
                    'form' => $d->servingForm?->code,
                    'form_label' => $d->servingForm?->label,
                    'is_standard' => (bool) $d->is_standard,
                    'ek_portion' => $ek,
                    'sales_net' => $vk,
                    'sales_gross' => $d->sales_gross !== null ? (float) $d->sales_gross : null,
                    'price_mode' => $d->price_mode,
                    'wareneinsatz_pct' => ($ek !== null && $vk !== null && $vk > 0) ? round($ek / $vk * 100, 1) : null,
                ];
            })->all();
    }

    /**
     * M3: Servierform-Slug/Code/Label → id (team-scoped ∪ global, aktiv). null bei leer,
     * RuntimeException mit Verfügbar-Liste bei unbekanntem Wert.
     */
    protected function resolveServierformId(Team $team, string $wert): ?int
    {
        $wert = trim($wert);
        if ($wert === '') {
            return null;
        }
        $id = DB::table('foodalchemist_serving_forms')->whereNull('deleted_at')->where('is_inactive', false)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', TeamScope::ancestryIds($team)))
            ->where(fn ($q) => $q->where('code', $wert)->orWhereRaw('LOWER(label) = ?', [mb_strtolower($wert)]))
            ->value('id');
        if ($id === null) {
            $verf = DB::table('foodalchemist_serving_forms')->whereNull('deleted_at')->where('is_inactive', false)
                ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', TeamScope::ancestryIds($team)))
                ->orderBy('code')->pluck('code')->implode(', ');
            throw new \RuntimeException("Unbekannte Servierform \"{$wert}\". Verfügbar: {$verf}");
        }

        return (int) $id;
    }

    /** M3: Facetten-Vokabel (name) → id in service_moments/seasons/event_types. */
    protected function resolveFacetId(Team $team, string $tabelle, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $id = DB::table($tabelle)->whereNull('deleted_at')->where('is_inactive', false)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', TeamScope::ancestryIds($team)))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');
        if ($id === null) {
            $verf = DB::table($tabelle)->whereNull('deleted_at')->where('is_inactive', false)
                ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', TeamScope::ancestryIds($team)))
                ->orderBy('name')->pluck('name')->implode(', ');
            throw new \RuntimeException("Unbekannt: \"{$name}\". Verfügbar: {$verf}");
        }

        return (int) $id;
    }

    /** @param array<int,string> $namen @return list<int> */
    protected function resolveFacetIds(Team $team, string $tabelle, array $namen): array
    {
        $ids = [];
        foreach ($namen as $n) {
            $id = $this->resolveFacetId($team, $tabelle, (string) $n);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** Status-Wert enum-sicher als String (recipes.status ist RecipeStatus-Enum-Cast). */
    protected function statusWert(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe): string
    {
        return $recipe->status instanceof \BackedEnum ? $recipe->status->value : (string) $recipe->status;
    }

    /**
     * E4 (#507): semantische Kandidaten-IDs für einen Pool-entity_type, die NOCH
     * NICHT im lexikalischen Ergebnis stehen (entity_id => cosine, Score-sortiert).
     * Leer wenn Semantik aus / kein Provider (graceful) — der lexikalische Pfad
     * bleibt dann unverändert. Ergänzt Recall, ersetzt NIE das lexikalische Ranking.
     *
     * @param  list<int>  $existingIds  bereits lexikalisch gefundene IDs
     * @return array<int, float>
     */
    protected function semanticPoolIds(Team $team, string $query, string $entityType, array $existingIds, int $limit): array
    {
        $query = trim($query);
        if ($query === '' || $limit <= 0) {
            return [];
        }
        $sem = app(\Platform\FoodAlchemist\Services\Ai\SemanticRetrievalService::class);
        if (! $sem->enabled()) {
            return [];
        }
        $existing = array_flip(array_map('intval', $existingIds));
        $out = [];
        foreach ($sem->candidates($team, $query, [$entityType], $limit) as $hit) {
            $id = (int) $hit['entity_id'];
            if (! isset($existing[$id])) {
                $out[$id] = (float) $hit['score'];
            }
        }

        return $out;
    }

    /**
     * Spec 19 E6.5: Kreativ-Skizze (dish_idea) kompakt für MCP. MCP-Feldnamen spiegeln die
     * Spec-Parameter (ziel_form/paket_gruppe), nicht die Roh-Spalten (target_form/group_id).
     */
    protected function skizzeArr(\Platform\FoodAlchemist\Models\FoodAlchemistDishIdea $i): array
    {
        return [
            'id' => $i->id,
            'chapter_id' => $i->chapter_id !== null ? (int) $i->chapter_id : null,
            'concept_id' => $i->concept_id !== null ? (int) $i->concept_id : null,
            'title' => $i->title,
            'description' => $i->description,
            'ziel_form' => $i->target_form,
            'paket_gruppe' => $i->group_id !== null ? (int) $i->group_id : null,
            'sales_recipe_id' => $i->sales_recipe_id !== null ? (int) $i->sales_recipe_id : null,
            'status' => $i->status,
            'created_via' => $i->created_via,
            'position' => (int) $i->position,
        ];
    }

    /**
     * Spec 19 E6.5: Paket-Gruppe (dish_idea_group) kompakt für MCP. $ideen optional
     * für die gruppierte GET-Sicht (Gruppe → ihre Skizzen).
     *
     * @param  list<array>|null  $ideen
     */
    protected function paketArr(\Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup $g, ?array $ideen = null): array
    {
        $out = [
            'id' => $g->id,
            'chapter_id' => $g->chapter_id !== null ? (int) $g->chapter_id : null,
            'concept_id' => $g->concept_id !== null ? (int) $g->concept_id : null,
            'name' => $g->name,
            'paket_zielpreis_pp' => $g->target_price_pp !== null ? (float) $g->target_price_pp : null,
            'position' => (int) $g->position,
        ];
        if ($ideen !== null) {
            $out['ideen'] = $ideen;
        }

        return $out;
    }

    /** Pairing-Anker (foodalchemist_vocab_pairing_anchors) sichtbar fürs Team (global ∪ Ancestry, aktiv). */
    protected function pairingAnkerSichtbar(Team $team, int $ankerId): bool
    {
        if ($ankerId <= 0) {
            return false;
        }

        return DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $ankerId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', TeamScope::ancestryIds($team)))
            ->exists();
    }

    /**
     * Guard für Konzept-Slot-/Block-by-id-Tools: existiert der Slot und gehört sein Konzept dem Team?
     * Gibt bei Fehler NOT_FOUND/ACCESS_DENIED, sonst null.
     */
    protected function guardConceptSlotOwned(Team $team, int $slotId): ?ToolResult
    {
        $slot = FoodAlchemistConceptSlot::whereKey($slotId)->first();
        if ($slot === null) {
            return ToolResult::error('Slot nicht vorhanden.', 'NOT_FOUND');
        }
        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereKey($slot->concept_id)->first();
        if ($concept === null) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $concept->isOwnedBy($team)) {
            return ToolResult::error('Nur fürs Besitzer-Team des Konzepts.', 'ACCESS_DENIED');
        }

        return null;
    }

    /**
     * Generischer Owner-Guard für team-hierarchie-fähige Modelle (BelongsToTeamHierarchy): existiert der
     * Datensatz sichtbar und gehört er dem Team? Gibt bei Fehler NOT_FOUND/ACCESS_DENIED, sonst null.
     *
     * @param  class-string  $modelClass
     */
    protected function guardOwned(Team $team, string $modelClass, int $id, string $label): ?ToolResult
    {
        $m = $modelClass::visibleToTeam($team)->whereKey($id)->first();
        if ($m === null) {
            return ToolResult::error("{$label} nicht sichtbar/vorhanden.", 'NOT_FOUND');
        }
        if (! $m->isOwnedBy($team)) {
            return ToolResult::error("{$label}: nur fürs Besitzer-Team.", 'ACCESS_DENIED');
        }

        return null;
    }

    /**
     * Guard für Gericht-by-id-Tools (VK): existiert das Verkaufsrezept (is_sales_recipe) sichtbar und
     * gehört es dem Team? Gibt bei Fehler das passende ToolResult (NOT_FOUND/ACCESS_DENIED), sonst null.
     */
    protected function guardVkRecipe(Team $team, int $recipeId): ?ToolResult
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->whereKey($recipeId)->first();
        if ($r === null) {
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $r->isOwnedBy($team)) {
            return ToolResult::error('Nur fürs Besitzer-Team des Gerichts.', 'ACCESS_DENIED');
        }

        return null;
    }

    /**
     * Guard für Foodbook-Struktur-Edits (D7): Foodbook sichtbar + team-eigen + im Status Entwurf?
     * Kundensichtbare Dokumente sind via MCP nur als Entwurf editierbar (spiegelt Foodbook-Kapitel-/
     * Block-Tools). Gibt bei Fehler NOT_FOUND/ACCESS_DENIED, sonst null.
     */
    protected function guardFoodbookEditable(Team $team, ?\Platform\FoodAlchemist\Models\FoodAlchemistFoodbook $fb): ?ToolResult
    {
        if ($fb === null) {
            return ToolResult::error('Foodbook nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $fb->isOwnedBy($team)) {
            return ToolResult::error('Nur fürs Besitzer-Team des Foodbooks.', 'ACCESS_DENIED');
        }
        if ($fb->statusWert() !== \Platform\FoodAlchemist\Enums\AusgabeStatus::Entwurf) {
            return ToolResult::error("Foodbook-Status \"{$fb->statusWert()->label()}\" — via MCP ist nur ein Entwurf strukturell editierbar.", 'ACCESS_DENIED');
        }

        return null;
    }

    /** Lädt das (sichtbare) Foodbook zu einem Kapitel — für die Struktur-Guards (D7). */
    protected function foodbookVonKapitel(Team $team, int $kapitelId): ?\Platform\FoodAlchemist\Models\FoodAlchemistFoodbook
    {
        $kap = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::whereKey($kapitelId)->first();
        if ($kap === null) {
            return null;
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($kap->foodbook_id)->first();
    }

    /** Lädt das (sichtbare) Foodbook zu einem Block (Block → Kapitel → Foodbook) — Struktur-Guards (D7). */
    protected function foodbookVonBlock(Team $team, int $blockId): ?\Platform\FoodAlchemist\Models\FoodAlchemistFoodbook
    {
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::whereKey($blockId)->first();
        if ($block === null) {
            return null;
        }
        $kap = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::whereKey($block->chapter_id)->first();
        if ($kap === null) {
            return null;
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($kap->foodbook_id)->first();
    }

    /**
     * Guard für Format-Slot-by-id-Tools (D6): existiert der Slot und gehört sein Format dem Team?
     * Gibt bei Fehler NOT_FOUND/ACCESS_DENIED, sonst null.
     */
    protected function guardFormatSlotOwned(Team $team, int $slotId): ?ToolResult
    {
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot::whereKey($slotId)->first();
        if ($slot === null) {
            return ToolResult::error('Format-Slot nicht vorhanden.', 'NOT_FOUND');
        }

        return $this->guardOwned($team, \Platform\FoodAlchemist\Models\FoodAlchemistFormat::class, (int) $slot->format_id, 'Format');
    }

    /**
     * Guard für Format-Bild-by-id-Tools (D6): existiert das Bild und gehört sein Format dem Team?
     * Gibt bei Fehler NOT_FOUND/ACCESS_DENIED, sonst null.
     */
    protected function guardFormatImageOwned(Team $team, int $imageId): ?ToolResult
    {
        $img = \Platform\FoodAlchemist\Models\FoodAlchemistFormatImage::whereKey($imageId)->first();
        if ($img === null) {
            return ToolResult::error('Format-Bild nicht vorhanden.', 'NOT_FOUND');
        }

        return $this->guardOwned($team, \Platform\FoodAlchemist\Models\FoodAlchemistFormat::class, (int) $img->format_id, 'Format');
    }

    /**
     * Kompakte Paket-Serialisierung (D5d) — geteilt von pakete.GET/LIST/SEARCH. Mit $withDishes werden
     * die Gericht-Positionen (Row-Id, Gericht, Menge/Einheit) mitgegeben.
     */
    protected function paketPayload(\Platform\FoodAlchemist\Models\FoodAlchemistPaket $p, bool $withDishes = false): array
    {
        $out = [
            'id' => (int) $p->id,
            'name' => $p->name,
            'consumer_name' => $p->consumer_name,
            'role' => $p->role,
            'class' => $p->class,
            'level' => $p->level,
            'price_mode' => $p->price_mode,
            'price_per_person' => $p->price_per_person !== null ? (float) $p->price_per_person : null,
            'ek_per_person' => $p->ek_per_person !== null ? (float) $p->ek_per_person : null,
            'food_cost_percent' => $p->food_cost_percent !== null ? (float) $p->food_cost_percent : null,
            'is_inactive' => (bool) $p->is_inactive,
        ];
        if ($withDishes) {
            $out['dishes'] = $p->dishes->map(fn ($g) => [
                'row_id' => (int) $g->id,
                'sales_recipe_id' => (int) $g->sales_recipe_id,
                'name' => $g->dish?->name,
                'quantity' => $g->quantity !== null ? (float) $g->quantity : null,
                'unit' => $g->unit?->slug,
            ])->values()->all();
        }

        return $out;
    }

    /**
     * Guard für Darreichungs-by-id-Tools: existiert die Darreichung und gehört sie einem team-eigenen
     * Gericht? Gibt bei Fehler das passende ToolResult (NOT_FOUND/ACCESS_DENIED), sonst null.
     */
    protected function guardDarreichungOwned(Team $team, int $darreichungId): ?ToolResult
    {
        $dar = FoodAlchemistRecipeDarreichung::whereKey($darreichungId)->first();
        if ($dar === null) {
            return ToolResult::error('Darreichung nicht vorhanden.', 'NOT_FOUND');
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey($dar->recipe_id)->first();
        if ($recipe === null) {
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Darreichungen nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }

        return null;
    }

    /** Phase A: Draft-Quarantäne-Guard — approved/review/archived sind für den MCP-Pfad locked. */
    protected function kiEditGesperrt(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe): ?string
    {
        $status = $this->statusWert($recipe);
        if (! in_array($status, self::KI_EDITIERBARE_STATUS, true)) {
            return "Rezept hat Status \"{$status}\" — via MCP sind nur stub/draft editierbar. "
                . 'Änderungen an gepflegten Rezepten laufen über den Editor (GL-07/Override-First).';
        }

        return null;
    }
}
