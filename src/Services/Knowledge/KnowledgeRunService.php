<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Symfony\Component\Uid\UuidV7;

/** Ein Snapshot wird nur angelegt oder gelesen, niemals auf den aktuellen Stand aktualisiert. */
final class KnowledgeRunService
{
    public function start(Team $team): RecipeKnowledgeRun
    {
        $snapshot = DB::transaction(fn () => app(KnowledgeRunContext::class)->within(null,
            fn () => app(KnowledgeCanonService::class)->snapshotFor($team)));
        $json = self::encode($snapshot);
        $id = (string) UuidV7::generate();
        $hash = hash('sha256', $json);
        DB::table('foodalchemist_knowledge_runs')->insert([
            'id' => $id, 'team_id' => $team->id, 'snapshot' => $json, 'snapshot_hash' => $hash, 'created_at' => now(),
        ]);
        return new RecipeKnowledgeRun($id, (int) $team->id, $hash, $snapshot);
    }

    public function load(Team $team, string $id): RecipeKnowledgeRun
    {
        $row = DB::table('foodalchemist_knowledge_runs')->where('team_id', $team->id)->where('id', $id)->first();
        if ($row === null) throw new \RuntimeException('Wissenslauf fehlt oder gehört zu einem anderen Team.');
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 1) {
            throw new \RuntimeException('Wissens-Snapshot ist beschädigt oder hat eine unbekannte Version.');
        }
        // JSON-Spalten dürfen Whitespace ändern; die kanonische Kodierung ist Teil des Formats.
        $json = self::encode($snapshot);
        if (! hash_equals($row->snapshot_hash, hash('sha256', $json))) {
            throw new \RuntimeException('Wissens-Snapshot ist beschädigt.');
        }
        return new RecipeKnowledgeRun($id, (int) $team->id, $row->snapshot_hash, $snapshot);
    }

    private static function encode(array $snapshot): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) ksort($value);
            foreach ($value as &$entry) {
                if (is_array($entry)) $entry = $canonical($entry);
            }
            return $value;
        };
        return json_encode($canonical($snapshot), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function withRecipe(Team $team, FoodAlchemistRecipe $recipe, callable $action): mixed
    {
        if ((int) $recipe->team_id !== (int) $team->id) {
            // Lesefreigaben bleiben Sache des bestehenden Fachpfads; kein fremder Snapshot.
            return app(KnowledgeRunContext::class)->within(null, $action);
        }
        if ($recipe->is_sales_recipe) return app(KnowledgeRunContext::class)->within(null, $action);
        $context = app(KnowledgeRunContext::class);
        // Explizit übergebener Queue-Lauf hat Vorrang vor einem inzwischen erneuerten Rezeptzeiger.
        if ($context->current((int) $team->id) !== null) return $action();
        if ($recipe->knowledge_run_id === null) {
            // Bestandsrezepte ohne Generierungslauf behalten ihren bisherigen Live-Kontext.
            return $action();
        }
        return $context->within($this->load($team, $recipe->knowledge_run_id), $action);
    }
}
