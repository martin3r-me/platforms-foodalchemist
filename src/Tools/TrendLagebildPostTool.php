<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagebildService;

/**
 * Observatorium zentral: wöchentliches Lagebild schreiben (nur Master-Team, Kuratieren). Der Text kommt vom Aufrufer (Claude-Läufer);
 * das Tool ruft keine KI. Aussagen ohne Beleg, mit unbelegten Zahlen oder mit Prognose/Empfehlung werden verworfen.
 */
class TrendLagebildPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_lagebild.POST';
    }

    public function getDescription(): string
    {
        return 'Schreibt das wöchentliche Lagebild des Gastro-Observatoriums für alle Mandanten (nur Master-Team, Rolle Kuratieren). '
            .'Gleiches zeitraum_bis ersetzt das vorhandene Lagebild. Jede Aussage und jeder Impuls braucht belege (IDs aus foodalchemist.trend_eingang.GET) '
            .'oder markt=true (Zahlen aus foodalchemist.marktdaten.GET). Jede Zahl muss wörtlich in den Belegen stehen; Aussagen dürfen nicht prognostizieren '
            .'oder empfehlen (Empfehlungen → impulse). Verworfenes wird mit Grund gemeldet; bleibt keine Aussage übrig, wird nichts gespeichert.';
    }

    public function getSchema(): array
    {
        $teil = ['type' => 'object', 'required' => ['text'], 'properties' => [
            'text' => ['type' => 'string', 'description' => 'Ein Satz, höchstens 400 Zeichen, deutsch, einfach.'],
            'belege' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Eingangs-IDs, aus denen der Satz stammt'],
            'markt' => ['type' => 'boolean', 'description' => 'true, wenn Zahlen aus „Markt in Zahlen“ stammen'],
        ]];

        return ['type' => 'object', 'required' => ['zeitraum_von', 'zeitraum_bis', 'titel', 'aussagen'], 'properties' => [
            'zeitraum_von' => ['type' => 'string', 'description' => 'JJJJ-MM-TT'],
            'zeitraum_bis' => ['type' => 'string', 'description' => 'JJJJ-MM-TT (höchstens 31 Tage nach von)'],
            'titel' => ['type' => 'string', 'description' => 'höchstens 200 Zeichen'],
            'kurzfazit' => ['type' => 'string', 'description' => 'optional, 1–2 Sätze; Zahlen nur aus den Aussagen'],
            'aussagen' => ['type' => 'array', 'items' => $teil, 'description' => '1–8 belegte Aussagen (keine Prognose, keine Empfehlung)'],
            'impulse' => ['type' => 'array', 'items' => $teil, 'description' => '0–5 belegte Impulse für Catering/Gastronomie/GV'],
            'anbieter' => ['type' => 'string', 'description' => 'wer schreibt, z. B. anthropic'],
            'modell' => ['type' => 'string', 'description' => 'Modell-ID des Schreibers'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            return ToolResult::success(app(LagebildService::class)->schreiben($team, $arguments, $context->user?->id));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['foodalchemist', 'observatorium', 'lagebild', 'write'], 'read_only' => false,
            'idempotent' => true, 'risk_level' => 'write', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['Lagebild anlegen oder ersetzen (für alle Mandanten sichtbar)'],
            'related_tools' => ['foodalchemist.trend_lagebild.GET', 'foodalchemist.trend_eingang.GET', 'foodalchemist.marktdaten.GET'],
            'examples' => ['Schreib das Lagebild für KW 45 aus den gebündelten Einträgen der Woche.']];
    }
}
