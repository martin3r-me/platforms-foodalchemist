<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * M6-05 / D-6 §3.3: Speisen-Klassen-Klassifikation + Rollen-Verteilung —
 * beides nach GL-07 (Vorschlag → editierbar → Accept schreibt Fachwert +
 * Lineage; Override-First: manuell gepflegt blockt den Accept). `null` ist
 * ein EHRLICHER Nicht-Treffer (kein Erzwingen, kein Schreibversuch).
 */
class SpeisenKlassenService
{

    public function __construct(private AiGatewayService $ki)
    {
    }

    /**
     * ai_classify_speisen_klasse: Kontext = Name + Komponenten + Diät-Flags +
     * Taxonomie; Ergebnis validiert gegen dish_classes.
     *
     * @return array{klasse_id: ?int, klasse_name: ?string, confidence: float, reasoning: ?string}
     */
    public function classify(Team $team, int $recipeId): array
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->with(['ingredients' => fn ($q) => $q->whereNull('deleted_at'), 'ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name'])
            ->findOrFail($recipeId);

        $taxonomie = self::taxonomie($team);

        $vorschlag = $this->ki->propose('vk.speisen_klasse', [
            'name' => $r->name,
            'dish_class_id' => $r->dish_class_id,            // Kontext (FakeProvider echo't)
            'komponenten' => $r->ingredients->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all(),
            'diaet' => ['vegan' => $r->spec_vegan ?? null, 'vegetarisch' => $r->spec_vegetarisch ?? null],
            'taxonomie' => $taxonomie,
        ]);

        $klasseId = $vorschlag->werte['dish_class_id'] ?? null;
        $klasse = $klasseId !== null ? FoodAlchemistDishClass::find((int) $klasseId) : null;

        return [
            'klasse_id' => $klasse?->id,                              // ungültige ID ⇒ ehrlicher Nicht-Treffer
            'klasse_name' => $klasse?->label,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'reasoning' => $vorschlag->reasoning,
            'call_log_id' => $vorschlag->callLogId,                   // M7-01: Accept stempelt (§5 Pflicht 3)
        ];
    }

    /**
     * Speisen-Klassen als Prompt-Taxonomie `id => Label`, sichtbar fürs Team.
     *
     * Modell A (MVP-049): die Klassen sind vier flache Diätformen OHNE Hauptgruppe
     * (`dish_main_group_id` NULL). Der frühere INNER JOIN auf die Hauptgruppe hat sie alle
     * verworfen — die KI bekam eine leere Taxonomie und antwortete regelkonform mit null
     * (Weg-B-Befund 1, 2026-10-12). Klassen MIT Hauptgruppe (Altbestand) bleiben erlaubt,
     * aber nur, wenn die Hauptgruppe aktiv ist (M5/E7: APE/SNK/ALC/BVK/ALL sind is_inactive).
     * Label DB-agnostisch in PHP zusammenbauen — SQLites `||` ist auf MySQL logisches OR.
     *
     * @return array<int, string>
     */
    public static function taxonomie(Team $team): array
    {
        return FoodAlchemistDishClass::visibleToTeam($team)
            ->leftJoin('foodalchemist_dish_main_groups AS hg', 'hg.id', '=', 'foodalchemist_dish_classes.dish_main_group_id')
            ->where(fn ($q) => $q->whereNull('foodalchemist_dish_classes.dish_main_group_id')->orWhere('hg.is_inactive', false))
            ->orderBy('foodalchemist_dish_classes.id')
            ->get([
                'foodalchemist_dish_classes.id AS id',
                'hg.code AS hg_code',
                'foodalchemist_dish_classes.label AS klasse_label',
                'foodalchemist_dish_classes.diet_form AS diet_form',
            ])
            ->mapWithKeys(fn ($row) => [(int) $row->id => ($row->hg_code !== null ? "{$row->hg_code} / " : '')."{$row->klasse_label} ({$row->diet_form})"])
            ->all();
    }

    /** GL-07-Accept: schreibt Klasse + Lineage-Trio; Override-First; stempelt accepted_at (§5 P3). */
    public function acceptKlasse(Team $team, int $recipeId, int $klasseId, float $confidence, ?string $reasoning, ?int $callLogId = null): void
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        if (! $r->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Gericht — Speisen-Klasse setzt nur das Besitzer-Team (D1).');
        }
        if ($r->dish_class_source === 'manual') {
            throw new \RuntimeException('Speisen-Klasse ist manuell gepflegt — erst Reset, dann KI übernehmen.');
        }
        FoodAlchemistDishClass::findOrFail($klasseId);                // validiert gegen Taxonomie

        $r->update([
            'dish_class_id' => $klasseId,
            'dish_class_source' => 'ki',
            'dish_class_ai_confidence' => $confidence,
            'dish_class_ai_reasoning' => $reasoning,
        ]);
        $this->ki->stempleAccepted($callLogId);
    }

    /**
     * ai_verteile_rollen (Gesamt-Gericht-Sicht, V-21): Vorschlag je Zutat-Zeile,
     * validiert gegen ROLLEN + die Zeilen des Rezepts.
     *
     * @return array{rollen: array<int, string>, verworfen: array<int, string>, confidence: float, reasoning: ?string, call_log_id: ?int}
     */
    public function verteileRollen(Team $team, int $recipeId, bool $neuZuordnen = false): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $recipeId)
            ?? throw new \RuntimeException('Rezept nicht sichtbar.');

        // Neu zuordnen (Kommando --alle, Dominique 11.10.): Rollen aus migration/ki NICHT als Echo zeigen — sonst bestätigt die KI
        // eher den Altwert (Probelauf 2: „Olivenöl [beilage]“) statt neu zu entscheiden. Manuelle bleiben als Kontext sichtbar.
        $echo = fn ($z) => $z->role !== null && ! ($neuZuordnen && in_array($z->role_source, ['migration', 'ki'], true)) ? $z->role : null;
        $zeilen = $r->ingredients->mapWithKeys(fn ($z) => [
            $z->id => ($z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name) . ($echo($z) !== null ? " [{$echo($z)}]" : ''),
        ])->all();

        $vorschlag = $this->ki->propose('vk.rollen', [
            'gericht' => $r->name,
            'zutaten' => $zeilen,
            'rollen' => $r->ingredients->mapWithKeys(fn ($z) => [$z->id => $echo($z)])->filter()->all(),  // Kontext-Echo
            // Wert, Anzeigename und Bedeutung nebeneinander (Probelauf 3: ohne Bedeutung zog die KI alles zu protein).
            'vokabular' => \Platform\FoodAlchemist\Support\TellerRolle::eintraege(),
            // demo Probelauf 11.10.: der Kanon (Regelwerk §12) nannte noch V-21 — die KI lieferte komponente/aroma_treiber.
            // Die Regel gericht.teller_rolle ist maßgeblich; Altwerte aus älteren Texten sind ausdrücklich ungültig.
            'vokabular_hinweis' => 'Nur Werte aus vokabular (= Regel gericht.teller_rolle). Die alten Werte aroma_treiber und komponente '
                . 'sind UNGUELTIG, auch wenn ein Text sie noch nennt.',
        ], ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id]);   // B-1: Call-Log ↔ Rezept

        $gueltig = [];
        $verworfen = [];
        $ids = array_map('intval', array_keys($zeilen));
        foreach ((array) ($vorschlag->werte['rollen'] ?? []) as $zeileId => $role) {
            if (! in_array((int) $zeileId, $ids, true)) {
                continue;
            }
            if (($k = \Platform\FoodAlchemist\Support\TellerRolle::kanonisch($role)) !== null) {
                $gueltig[(int) $zeileId] = $k;
            } else {
                $verworfen[(int) $zeileId] = is_scalar($role) ? (string) $role : '';   // nicht still: der Aufrufer meldet es
            }
        }

        return [
            'rollen' => $gueltig,
            'verworfen' => $verworfen,                                  // Zeile => gelieferter Wert außerhalb des Vokabulars
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'reasoning' => $vorschlag->reasoning,
            'call_log_id' => $vorschlag->callLogId,                     // B-1: Accept-Stempel im Bulk-Pfad
        ];
    }

    /**
     * Accept der Rollen-Verteilung (zeilenbasiert, Transaktion V-07);
     * danach pro Zeile korrigierbar (Zutaten-Editor).
     *
     * @param  array<int, string>  $rollen  zeileId => role
     */
    public function acceptRollen(Team $team, int $recipeId, array $rollen): int
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);

        return DB::transaction(function () use ($r, $rollen) {
            $n = 0;
            foreach ($rollen as $zeileId => $role) {
                if (($role = \Platform\FoodAlchemist\Support\TellerRolle::kanonisch($role)) === null) {
                    continue;
                }
                $n += DB::table('foodalchemist_recipe_ingredients')
                    ->where('id', (int) $zeileId)->where('recipe_id', $r->id)->whereNull('deleted_at')
                    ->update(['role' => $role, 'role_source' => 'ki', 'updated_at' => now()]);
            }

            return $n;
        });
    }
}
