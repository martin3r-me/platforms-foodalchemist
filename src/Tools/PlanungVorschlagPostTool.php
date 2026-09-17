<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BriefingLeitplankenService;

/**
 * Spec 53 / Paket D — GL-07 für den Sprachbefehl: „Erstelle ein Gericht mit …" darf NICHT direkt
 * eine Planungs-Session anlegen (das wäre ein Schreiber, `foodalchemist.planung_session.POST` ist
 * dafür zu Recht gesperrt — `read_only=false`, für den Sprach-Agenten strukturell unerreichbar).
 * Dieses Tool ist der Vorschlag davor: `read_only => true`, schreibt NICHTS (ruft
 * {@see BriefingLeitplankenService::ausBriefing()} mit `sessionId = null`, der einzige Modus, in
 * dem der Service selbst nicht schreibt). Das Modal zeigt das Ergebnis als Karte mit einem
 * „Planung starten"-Knopf — der Klick (VoiceModal::planungStarten(), nicht dieses Tool) legt die
 * Session tatsächlich an. Sprechen → Vorschlag → Bestätigen, wie bei jedem anderen Proposal-Tool.
 */
class PlanungVorschlagPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const SCOPES = ['rezept', 'gericht', 'concept'];

    public function getName(): string
    {
        return 'foodalchemist.planung_vorschlag.POST';
    }

    public function getDescription(): string
    {
        return 'Schlägt eine neue Planung vor (Basisrezept/Gericht/Concept) — SCHREIBT NICHTS, nur ein '
            . 'Vorschlag zum Bestätigen durch den Menschen. scope: rezept|gericht|concept, brief: was gebaut '
            . 'werden soll, titel optional. Leitet bei Bedarf Leitplanken aus dem Brief ab (unverbindlich, '
            . 'nicht gespeichert). Nutzen, wenn der User sagt „erstelle ein …", „baue mir ein …", '
            . '„mach ein Rezept/Gericht/Menü für …".';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => self::SCOPES],
                'brief' => ['type' => 'string', 'description' => 'Was entstehen soll — möglichst der Originalwortlaut des Users.'],
                'titel' => ['type' => 'string', 'description' => 'Optionaler Arbeitstitel.'],
                'leitplanken' => ['type' => 'boolean', 'description' => 'Leitplanken aus dem Brief ableiten (Default true).', 'default' => true],
            ],
            'required' => ['scope', 'brief'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $scope = (string) ($arguments['scope'] ?? '');
        if (! in_array($scope, self::SCOPES, true)) {
            return ToolResult::error('scope muss rezept, gericht oder concept sein.', 'VALIDATION_ERROR');
        }
        $brief = trim((string) ($arguments['brief'] ?? ''));
        if ($brief === '') {
            return ToolResult::error('brief ist Pflicht.', 'VALIDATION_ERROR');
        }
        $titel = trim((string) ($arguments['titel'] ?? ''));
        $mitLeitplanken = ($arguments['leitplanken'] ?? true) !== false;

        $leitplanken = null;
        if ($mitLeitplanken) {
            try {
                // sessionId=null: der einzige Modus von ausBriefing(), der NICHTS schreibt.
                $leitplanken = app(BriefingLeitplankenService::class)->ausBriefing($team, $brief, null);
            } catch (\InvalidArgumentException $e) {
                return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
            }
        }

        return ToolResult::success(['vorschlag' => [
            'scope' => $scope,
            'titel' => $titel !== '' ? $titel : null,
            'brief' => $brief,
            'leitplanken' => $leitplanken['leitplanken'] ?? null,
            'verworfen' => $leitplanken['verworfen'] ?? [],
            'unklar' => $leitplanken['unklar'] ?? [],
            'begruendung' => $leitplanken['begruendung'] ?? null,
            'confidence' => $leitplanken['confidence'] ?? null,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'utility',
            'tags' => ['foodalchemist', 'planung', 'voice', 'proposal', 'gl-07'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm_call',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.planung_session.POST', 'foodalchemist.planung_kaskade.LETZTE'],
            'examples' => ['Erstelle ein Basisrezept für Tomatensuppe', 'Baue mir ein Gericht mit Rinderfilet, Kartoffelpüree und Jus'],
        ];
    }
}
