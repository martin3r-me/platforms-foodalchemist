<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FeedbackQuelle;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFeedback;

/**
 * R2.6 — Praxis-Feedback je Gericht/Basisrezept (Küche · Kunde · Event).
 * Zwei Zwecke: (1) Popularitäts-Achse fürs Menu-Engineering (R2.3) OHNE
 * Verkaufsdaten-Import, (2) Küchen-Feedback als Entwicklungs-Motor.
 *
 * Read-only-Aggregat (Ø/Count) wird on-read gerechnet — keine Recompute-Spalten.
 * Bewusst getrennt von ConcepterBewertungService (Menü-Prüfung) und der
 * KI-Sensorik-Bewertung (maschinell). Hier: menschliche Rückmeldung.
 *
 * D1: Feedback ist team-eigen. Sichtbarkeit VERTIKAL (Ancestry ∪ Descendants):
 * Kind sieht eigenes + geerbtes (Eltern-Katalog), Eltern sieht eigenes + Kinder
 * aggregiert; Geschwister-Teams sehen einander NICHT.
 */
class FeedbackService
{
    public function __construct(private RecipeService $recipes)
    {
    }

    /**
     * Feedback anlegen. Recipe muss im Team-Scope sichtbar sein (auch geerbte
     * Katalog-Rezepte dürfen bewertet werden — D1: Kind bewertet eigenständig).
     * team_id = das handelnde Team (nicht das Besitzer-Team des Rezepts).
     *
     * @param  array{quelle:string,score?:int|null,machbarkeit?:int|null,aufwand?:int|null,geschmack?:int|null,gaeste_reaktion?:int|null,comment?:string|null,kontext_kind?:string|null,kontext_id?:int|null,kontext_datum?:string|null,kontext_label?:string|null,author_user_id?:int|null,created_via?:string|null}  $in
     */
    public function erstelle(Team $team, int $recipeId, array $in): FoodAlchemistRecipeFeedback
    {
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);

        $quelle = FeedbackQuelle::tryFrom((string) ($in['quelle'] ?? ''))
            ?? throw new \InvalidArgumentException('quelle muss kueche|kunde|event sein.');

        $clamp = fn ($v) => $v === null || $v === '' ? null : max(1, min(5, (int) $v));
        $achsen = $quelle->hatAchsen()
            ? [
                'machbarkeit' => $clamp($in['machbarkeit'] ?? null),
                'aufwand' => $clamp($in['aufwand'] ?? null),
                'geschmack' => $clamp($in['geschmack'] ?? null),
                'gaeste_reaktion' => $clamp($in['gaeste_reaktion'] ?? null),
            ]
            : ['machbarkeit' => null, 'aufwand' => null, 'geschmack' => null, 'gaeste_reaktion' => null];

        $score = $clamp($in['score'] ?? null);
        // Küchen-Score fehlt? → Mittel der gesetzten Achsen (außer „Aufwand", das ist invers).
        if ($score === null && $quelle->hatAchsen()) {
            $werte = array_filter([$achsen['machbarkeit'], $achsen['geschmack'], $achsen['gaeste_reaktion']], fn ($v) => $v !== null);
            $score = $werte === [] ? null : (int) round(array_sum($werte) / count($werte));
        }

        $comment = isset($in['comment']) ? trim((string) $in['comment']) : null;
        if ($score === null && ($comment === null || $comment === '')) {
            throw new \InvalidArgumentException('Feedback braucht mindestens einen Score oder einen Kommentar.');
        }

        return FoodAlchemistRecipeFeedback::create([
            'team_id' => $team->id,
            'recipe_id' => $recipeId,
            'quelle' => $quelle,
            'score' => $score,
            ...$achsen,
            'comment' => $comment ?: null,
            'kontext_kind' => in_array($in['kontext_kind'] ?? null, ['concept', 'event'], true) ? $in['kontext_kind'] : null,
            'kontext_id' => isset($in['kontext_id']) && $in['kontext_id'] !== '' ? (int) $in['kontext_id'] : null,
            'kontext_datum' => $in['kontext_datum'] ?? null,
            'kontext_label' => isset($in['kontext_label']) ? (trim((string) $in['kontext_label']) ?: null) : null,
            'author_user_id' => $in['author_user_id'] ?? null,
            'created_via' => $in['created_via'] ?? 'fa_ui',
        ]);
    }

    /** Feedback-Eintrag löschen — nur das Besitzer-Team (D1). */
    public function loeschen(Team $team, int $feedbackId): void
    {
        $f = FoodAlchemistRecipeFeedback::visibleToTeam($team)->findOrFail($feedbackId);
        if (! $f->isOwnedBy($team)) {
            throw new \RuntimeException('Fremdes Feedback — löschen nur durch das Besitzer-Team (D1).');
        }
        $f->delete();
    }

    /** Sichtbare Einträge eines Rezepts (vertikaler Scope), neueste zuerst. */
    public function fuerRezept(Team $team, int $recipeId, int $limit = 50): Collection
    {
        return FoodAlchemistRecipeFeedback::query()
            ->whereIn('team_id', $this->vertikaleTeamIds($team))
            ->where('recipe_id', $recipeId)
            ->latest()->limit($limit)->get();
    }

    /**
     * Aggregat eines Rezepts: Ø-Score, Count, Count je Quelle, jüngste Kommentare.
     *
     * @return array{avg:?float,count:int,per_source:array<string,int>,recent:list<array{quelle:string,score:?int,comment:?string,datum:?string}>}
     */
    public function aggregat(Team $team, int $recipeId): array
    {
        $teamIds = $this->vertikaleTeamIds($team);
        $base = FoodAlchemistRecipeFeedback::query()->whereIn('team_id', $teamIds)->where('recipe_id', $recipeId);

        $count = (clone $base)->count();
        if ($count === 0) {
            return ['avg' => null, 'count' => 0, 'per_source' => [], 'recent' => []];
        }
        $avg = (clone $base)->whereNotNull('score')->avg('score');
        $perSource = (clone $base)->selectRaw('quelle, COUNT(*) n')->groupBy('quelle')->pluck('n', 'quelle')->all();
        $recent = (clone $base)->latest()->limit(5)->get(['quelle', 'score', 'comment', 'created_at'])
            ->map(fn ($f) => [
                'quelle' => $f->quelle instanceof FeedbackQuelle ? $f->quelle->value : (string) $f->quelle,
                'score' => $f->score,
                'comment' => $f->comment,
                'datum' => $f->created_at?->format('Y-m-d'),
            ])->all();

        return [
            'avg' => $avg !== null ? round((float) $avg, 1) : null,
            'count' => $count,
            'per_source' => array_map('intval', $perSource),
            'recent' => $recent,
        ];
    }

    /**
     * Bulk-Aggregat für Browser-Listen: recipe_id → {avg, count}. Ein Query.
     *
     * @param  list<int>  $recipeIds
     * @return array<int, array{avg:?float,count:int}>
     */
    public function aggregatBulk(Team $team, array $recipeIds): array
    {
        $recipeIds = array_values(array_unique(array_map('intval', $recipeIds)));
        if ($recipeIds === []) {
            return [];
        }
        $rows = FoodAlchemistRecipeFeedback::query()
            ->whereIn('team_id', $this->vertikaleTeamIds($team))
            ->whereIn('recipe_id', $recipeIds)
            ->selectRaw('recipe_id, AVG(score) avg_score, COUNT(*) n')
            ->groupBy('recipe_id')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->recipe_id] = [
                'avg' => $r->avg_score !== null ? round((float) $r->avg_score, 1) : null,
                'count' => (int) $r->n,
            ];
        }

        return $out;
    }

    /**
     * „Weiterentwickeln"-Brücke: aus einem Feedback eine Draft-Rezept-Iteration
     * erzeugen (Kopie inkl. Zutaten via RecipeService::duplicate), Status=draft,
     * Lineage aufs Feedback (spawned_recipe_id). Rückgabe = die neue Iteration.
     */
    public function weiterentwickeln(Team $team, int $feedbackId, string $createdVia = 'fa_ui'): FoodAlchemistRecipe
    {
        $f = FoodAlchemistRecipeFeedback::visibleToTeam($team)->findOrFail($feedbackId);
        $quelle = $f->recipe()->visibleToTeam($team)->firstOrFail();

        return DB::transaction(function () use ($team, $f, $quelle, $createdVia) {
            if ($f->spawned_recipe_id !== null) {
                return FoodAlchemistRecipe::findOrFail($f->spawned_recipe_id); // idempotent: schon abgeleitet
            }
            $name = mb_substr($quelle->name . ' (Weiterentwicklung)', 0, 250);
            $iteration = $this->recipes->duplicate($team, $quelle->id, $name);
            $this->recipes->setStatus($team, $iteration->id, 'draft');
            $iteration->update(['created_via' => $createdVia]);
            $f->update(['spawned_recipe_id' => $iteration->id]);

            return $iteration->refresh();
        });
    }

    /**
     * Vertikale Team-Sichtbarkeit: Ancestry (self + Eltern, aus dem Trait) ∪
     * Descendants (self + alle Kind-Teams, BFS abwärts über parent_team_id).
     * Kind sieht geerbtes, Eltern sieht Kinder, Geschwister sehen einander nicht.
     *
     * @return list<int>
     */
    private function vertikaleTeamIds(Team $team): array
    {
        $ids = FoodAlchemistRecipeFeedback::teamAncestryIds($team); // aufwärts: self + Eltern
        $frontier = [(int) $team->id];
        $guard = 0;
        while ($frontier !== [] && $guard < 32) {
            $kinder = Team::query()->whereIn('parent_team_id', $frontier)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $neu = array_values(array_diff($kinder, $ids));
            if ($neu === []) {
                break;
            }
            $ids = array_merge($ids, $neu);
            $frontier = $neu;
            $guard++;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
