<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
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
