<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * D-6 §5.x / GL-10: Kohärenz-Judge (zweite Achse) + Teller-Heber — beides
 * gecacht je Rezept mit `components_hash` als Invalidierungs-Anker (Zutaten-
 * Änderung ⇒ stale ⇒ «Erneut prüfen»). Abgrenzung GL-10 §1: der Judge-Score
 * wird NIE mit dem deterministischen Aroma-Score (PairingService::cohesion)
 * verrechnet — zwei Achsen, zwei Anzeigen.
 *
 * Kein GL-07-Proposal-Flow: das Urteil ist ein Cache (wie in der Ist-App),
 * kein kuratierbares Fachfeld — Kill-Switch/Audit laufen über das Gateway mit.
 */
class CoherenceService
{
    public function __construct(private AiGatewayService $ki)
    {
    }

    /** Invalidierungs-Anker: Hash über die aufgelösten Komponenten + Mengen. */
    public function componentsHash(FoodAlchemistRecipe $r): string
    {
        $teile = $r->ingredients
            ->map(fn ($z) => ($z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name ?? $z->raw_text) . '|' . (float) $z->quantity)
            ->sort()->values();

        return sha1($teile->implode(';'));
    }

    /**
     * Panel-Stand: Cache-Zeile + stale-Flag (Komponenten seit dem Urteil geändert).
     *
     * @return array{cache: ?FoodAlchemistRecipeCulinaryCoherence, stale: bool}
     */
    public function status(Team $team, int $recipeId): array
    {
        $r = $this->lade($team, $recipeId);
        $cache = FoodAlchemistRecipeCulinaryCoherence::where('recipe_id', $r->id)->first();

        return [
            'cache' => $cache,
            'stale' => $cache !== null && $cache->components_hash !== $this->componentsHash($r),
        ];
    }

    /** vk.kohaerenz (Ist: culinary_coherence_judge): Urteil holen + Cache schreiben. */
    public function judge(Team $team, int $recipeId): FoodAlchemistRecipeCulinaryCoherence
    {
        $r = $this->lade($team, $recipeId);
        $vorschlag = $this->ki->propose('vk.kohaerenz', $this->tellerKontext($r));

        $score = $vorschlag->werte['score'] ?? null;
        if (! is_numeric($score)) {
            // Ehrlicher Nicht-Treffer (FakeProvider-Grenze): nichts cachen
            throw new \RuntimeException('Judge lieferte kein verwertbares Urteil (score fehlt) — echter Provider nötig.');
        }

        return FoodAlchemistRecipeCulinaryCoherence::updateOrCreate(
            ['recipe_id' => $r->id],
            [
                'team_id' => $r->team_id,
                'components_hash' => $this->componentsHash($r),
                'score' => max(0, min(100, (int) round((float) $score))),
                'label' => $this->kurz($vorschlag->werte['label'] ?? null),
                'reasoning' => is_string($vorschlag->werte['reasoning'] ?? null) ? $vorschlag->werte['reasoning'] : $vorschlag->reasoning,
                'schwachstelle' => $this->kurz($vorschlag->werte['schwachstelle'] ?? null),
                'judge_model' => $vorschlag->model,
                'judged_at' => now(),
            ],
        );
    }

    /** vk.teller_heber (Ist: plate_suggester): Vorschläge holen + in derselben Cache-Zeile ablegen. */
    public function tellerHeber(Team $team, int $recipeId): FoodAlchemistRecipeCulinaryCoherence
    {
        $r = $this->lade($team, $recipeId);
        $vorschlag = $this->ki->propose('vk.teller_heber', $this->tellerKontext($r));

        $liste = [];
        foreach ((array) ($vorschlag->werte['vorschlaege'] ?? []) as $v) {
            if (! is_array($v) || ! is_string($v['zutat'] ?? null) || $v['zutat'] === '') {
                continue;                                            // ohne Zutat kein Vorschlag
            }
            $liste[] = [
                'type' => in_array($v['type'] ?? null, FoodAlchemistRecipeCulinaryCoherence::HEBER_TYPEN, true) ? $v['type'] : 'ergaenzung',
                'zutat' => $v['zutat'],
                'category' => $this->kurz($v['category'] ?? null),
                'reasoning' => is_string($v['reasoning'] ?? null) ? $v['reasoning'] : null,
                'confidence' => is_numeric($v['confidence'] ?? null) ? max(0.0, min(1.0, (float) $v['confidence'])) : null,
            ];
        }
        if ($liste === []) {
            throw new \RuntimeException('Keine verwertbaren Teller-Vorschläge (vorschlaege leer) — echter Provider nötig.');
        }

        return FoodAlchemistRecipeCulinaryCoherence::updateOrCreate(
            ['recipe_id' => $r->id],
            [
                'team_id' => $r->team_id,
                'components_hash' => $this->componentsHash($r),
                'heber_json' => [
                    'einschaetzung' => is_string($vorschlag->werte['einschaetzung'] ?? null) ? $vorschlag->werte['einschaetzung'] : null,
                    'vorschlaege' => $liste,
                ],
                'heber_model' => $vorschlag->model,
                'heber_at' => now(),
            ],
        );
    }

    /** Geteilter Prompt-Kontext beider Judges: der Teller, wie er auf der Karte steht. */
    private function tellerKontext(FoodAlchemistRecipe $r): array
    {
        return [
            'name' => $r->name,
            'komponenten' => $r->ingredients
                ->map(fn ($z) => ($z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name ?? $z->raw_text))
                ->filter()->values()->all(),
            'geschmack' => $r->taste_direction,
            'speisen_klasse' => $r->dishClass?->label,
        ];
    }

    /** Sicht-neutral (D-5-Inventar nennt den Judge auch für Basisrezepte). */
    private function lade(Team $team, int $recipeId): FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)
            ->with(['ingredients' => fn ($q) => $q->whereNull('deleted_at'), 'ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name', 'speisenKlasse:id,label'])
            ->findOrFail($recipeId);
    }

    private function kurz(mixed $wert): ?string
    {
        return is_string($wert) && $wert !== '' ? mb_substr($wert, 0, 255) : null;
    }
}
