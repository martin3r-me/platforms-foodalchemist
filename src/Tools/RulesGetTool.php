<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Models\FoodAlchemistRuleVersion;

/**
 * Spec 81 — Regeln als Daten lesen (MCP im Lockstep mit Einstellungen › Regeln). Ohne `schluessel` die Liste
 * (Filter regelwerk/art/aktiv), mit `schluessel` die ganze Regel samt Parametern, Beispielen und Versionen.
 */
class RulesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.rules.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die mechanischen Regelwerk-Regeln, die der Code beim Anlegen, Prüfen und Matching durchsetzt '
            . '(Vokabular, Ersetzung, Pflichtangabe, Verbot, Zuordnung, Schwelle). Ohne schluessel: Liste mit Filtern. '
            . 'Mit schluessel: Regel mit params, beispiele, aktiv und den letzten Versionen. Global, alle Teams lesen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'schluessel' => ['type' => 'string', 'description' => 'z. B. basisrezept.5.default_gp (leer = Liste)'],
                'regelwerk' => ['type' => 'string', 'description' => 'gp|basisrezept|la|vk|ernaehrung|matching'],
                'art' => ['type' => 'string', 'enum' => FoodAlchemistRule::ARTEN],
                'nur_aktive' => ['type' => 'boolean'],
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
        $schluessel = trim((string) ($arguments['schluessel'] ?? ''));
        if ($schluessel !== '') {
            $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', $schluessel)->first();
            if ($r === null) {
                return ToolResult::error("Regel «{$schluessel}» nicht gefunden.", 'NOT_FOUND');
            }

            return ToolResult::success($this->voll($r) + [
                'versionen' => FoodAlchemistRuleVersion::where('rule_id', $r->id)->orderByDesc('version')->limit(10)
                    ->get(['version', 'aktiv', 'wirkung', 'created_at'])->toArray(),
            ]);
        }

        $q = FoodAlchemistRule::query()->whereNull('team_id')->orderBy('regelwerk')->orderBy('paragraph');
        foreach (['regelwerk', 'art'] as $f) {
            if (! empty($arguments[$f])) {
                $q->where($f, (string) $arguments[$f]);
            }
        }
        if (($arguments['nur_aktive'] ?? false) === true) {
            $q->where('aktiv', true);
        }

        return ToolResult::success(['regeln' => $q->get()->map(fn ($r) => [
            'schluessel' => $r->schluessel, 'regelwerk' => $r->regelwerk, 'paragraph' => $r->paragraph, 'titel' => $r->titel,
            'art' => $r->art, 'ziel' => $r->ziel, 'wirkung' => $r->wirkung, 'aktiv' => $r->aktiv, 'version' => $r->version,
        ])->values()->all()]);
    }

    /** @return array<string, mixed> */
    private function voll(FoodAlchemistRule $r): array
    {
        return $r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'params', 'beispiele', 'aktiv',
            'version', 'notiz', 'dossier_slug']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config', 'tags' => ['foodalchemist', 'regeln', 'regelwerk', 'konfiguration'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.rules.PREVIEW', 'foodalchemist.rules.PUT'],
            'examples' => ['Welche Regeln sind aktiv?', 'Zeig die Default-Grundprodukte (basisrezept.5.default_gp)'],
        ];
    }
}
