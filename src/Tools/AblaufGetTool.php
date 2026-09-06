<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VorgangsRegisterService;

/**
 * Spec 50 · E-3 — „Wie geht das hier?" als Werkzeug.
 *
 * Bis hierher musste ein Agent den Ablauf raten oder mit dem richtigen Suchbegriff auf ein
 * Workflow-Dossier stossen. Die Kategorie `workflow` hat kein Routing — kein Generator lädt
 * sie, keine Kaskade zieht sie. Dieses Tool ist der Abholpunkt, den die Spec dafür vorsieht.
 *
 * Ohne `vorgang` listet es die verfügbaren Vorgänge — der Einstieg, wenn der Agent den
 * passenden Code noch nicht kennt.
 */
class AblaufGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ablauf.GET';
    }

    public function getDescription(): string
    {
        return 'Das Vorgangs-Register: wie ein Vorgang im Food Alchemist abläuft — Einstiegs-Tool, '
            . 'Werkzeug-Kette, Soll-Aspekte (woran das Ergebnis gemessen wird), geltende Regelwerke '
            . 'und das Workflow-Dossier mit Anti-Patterns. VOR dem Anlegen von Rezept, Gericht, Konzept, '
            . 'Foodbook, Angebot, Speiseplan, Speisekarte, Format oder GP aufrufen. Ohne vorgang: Liste aller Vorgänge.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vorgang' => [
                    'type' => 'string',
                    'enum' => array_keys(VorgangsRegisterService::VORGAENGE),
                    'description' => 'Vorgangs-Code. Weglassen für die Liste aller Vorgänge.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        $svc = app(VorgangsRegisterService::class);
        $code = trim((string) ($arguments['vorgang'] ?? ''));

        if ($code === '') {
            return ToolResult::success([
                'vorgaenge' => $svc->liste(),
                'hinweis' => 'Mit vorgang=<code> den vollen Ablauf holen.',
            ]);
        }

        if (! $svc->kennt($code)) {
            return ToolResult::error(
                'Unbekannter Vorgang «' . $code . '». Bekannt: ' . implode(', ', array_keys(VorgangsRegisterService::VORGAENGE)) . '.',
                'VALIDATION_ERROR'
            );
        }

        $vorgang = $svc->vorgang($code, $team);

        return ToolResult::success($vorgang + [
            'hinweis' => 'soll_aspekte nennt die Lücken-Codes, die reife.GET an einem konkreten Objekt meldet — '
                . 'wie: null heisst, es gibt kein Werkzeug dafür (nicht: es sei egal).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'ablauf', 'workflow', 'vorgang', 'anleitung'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => false, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.regelwerk.GET', 'foodalchemist.reife.GET', 'foodalchemist.knowledge.GET'],
            'examples' => [
                'Wie lege ich ein Gericht an?',
                'Welche Vorgänge kennst du?',
                'Was gehört zu einem vollständigen Konzept?',
            ],
        ];
    }
}
