<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Services\Regeln\RegelProbelauf;

/**
 * Spec 81 — Probelauf per MCP: eine geänderte Regel (neue params/wirkung) gegen den Bestand, Vergleich mit der
 * gespeicherten Fassung. Rein lesend — damit Agenten Regeln VORSCHLAGEN können, ohne etwas zu ändern.
 */
class RulesPreviewTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.rules.PREVIEW';
    }

    public function getDescription(): string
    {
        return 'Probelauf einer Regel gegen den Bestand: wie viele Grundprodukte/Rezepte/Zeilen sie trifft, vorher gegen '
            . 'nachher (+neu / −nicht mehr), erste Beispiele, und ob Schema und Beispiele der geänderten Fassung gültig sind. '
            . 'Ohne params/wirkung läuft die gespeicherte Fassung. Ändert nichts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['schluessel'],
            'properties' => [
                'schluessel' => ['type' => 'string'],
                'params' => ['type' => 'object', 'description' => 'Geänderte Parameter (ganz, nicht als Teilmenge)'],
                'wirkung' => ['type' => 'string', 'enum' => FoodAlchemistRule::WIRKUNGEN],
                'beispiele' => ['type' => 'object', 'description' => '{richtig: [], falsch: []}'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($this->team($context) === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! RegelService::darf($context->user)) {
            return ToolResult::error('Regeln pflegt und liest nur der Plattform-Administrator.', 'FORBIDDEN');
        }
        $alt = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', (string) ($arguments['schluessel'] ?? ''))->first();
        if ($alt === null) {
            return ToolResult::error('Regel nicht gefunden.', 'NOT_FOUND');
        }
        $neu = $alt->replicate();
        $neu->id = $alt->id;
        foreach (['params', 'wirkung', 'beispiele'] as $f) {
            if (array_key_exists($f, $arguments)) {
                $neu->{$f} = $arguments[$f];
            }
        }

        return ToolResult::success(['schluessel' => $alt->schluessel] + app(RegelProbelauf::class)->vergleiche($alt, $neu));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config', 'tags' => ['foodalchemist', 'regeln', 'probelauf', 'vorschau'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.rules.GET', 'foodalchemist.rules.PUT'],
            'examples' => ['Was würde sich ändern, wenn „gefriergetrocknet" verboten wäre?'],
        ];
    }
}
