<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\IdeenService;

/**
 * Spec 19 „Foodbook-Leitstelle" (E6.5): Kreativ-Skizzen eines Owners lesen — GRUPPIERT
 * in Paket-Gruppen (mit ihren Skizzen) + freie Einzel-Skizzen. Owner ist GENAU eines von
 * chapter_id / concept_id (XOR). Read-only, team-scoped.
 *
 * Skizzen sind Entwürfe (Status entwurf|verworfen) und erden NICHTS — erst der Kapitel-Go
 * (E7, human-only) materialisiert sie. Verworfene sind per Default ausgeblendet.
 */
class KapitelIdeenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kapitel_ideen.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Kreativ-Skizzen eines Owners (chapter_id XOR concept_id), gruppiert: '
            . 'gruppen[] (Paket-Gruppen mit ihren Skizzen, €/Gast-Ziel) + einzel[] (freie Einzel-Skizzen). '
            . 'Skizzen sind Entwürfe und erzeugen KEINE Rezepte/Konzepte — das macht erst der Kapitel-Go. '
            . 'include_verworfen=true zeigt auch die Papierkorb-Skizzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer', 'description' => 'Owner-Kapitel (XOR concept_id)'],
                'concept_id' => ['type' => 'integer', 'description' => 'Owner-Konzept (XOR chapter_id)'],
                'include_verworfen' => ['type' => 'boolean', 'default' => false, 'description' => 'true = auch verworfene Skizzen'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $chapterId = isset($arguments['chapter_id']) ? (int) $arguments['chapter_id'] : null;
        $conceptId = isset($arguments['concept_id']) ? (int) $arguments['concept_id'] : null;

        // Cross-Team-Guard: liste() filtert Skizzen team-sichtbar (kein Leak), validiert den
        // Owner selbst aber nicht — deshalb hier explizit prüfen, sonst gäbe ein fremdes Kapitel
        // still eine leere Liste statt NOT_FOUND.
        if ($chapterId !== null && $chapterId > 0
            && ! FoodAlchemistFoodbookKapitel::visibleToTeam($team)->whereKey($chapterId)->exists()) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if ($conceptId !== null && $conceptId > 0
            && ! FoodAlchemistConcept::visibleToTeam($team)->whereKey($conceptId)->exists()) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $liste = app(IdeenService::class)->liste(
                $team,
                $chapterId,
                $conceptId,
                (bool) ($arguments['include_verworfen'] ?? false),
            );
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Owner (Kapitel/Konzept) nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $gruppen = array_map(
            fn ($g) => $this->paketArr($g['gruppe'], $g['ideen']->map(fn ($i) => $this->skizzeArr($i))->all()),
            $liste['gruppen'],
        );
        $einzel = $liste['einzel']->map(fn ($i) => $this->skizzeArr($i))->all();

        return ToolResult::success([
            'gruppen' => $gruppen,
            'einzel' => $einzel,
            'total' => count($einzel) + array_sum(array_map(fn ($g) => count($g['ideen']), $gruppen)),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'ideen', 'skizzen', 'kreativ', 'leitstelle'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.kapitel_ideen.POST', 'foodalchemist.kapitel_ideen.PUT', 'foodalchemist.leitstelle.GET'],
            'examples' => ['Zeig mir die Ideen-Skizzen von Kapitel 8', 'Welche Paket-Ideen hat das Kapitel?'],
        ];
    }
}
