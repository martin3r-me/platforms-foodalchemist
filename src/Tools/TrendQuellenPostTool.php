<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 93 A · Quelle anlegen; ohne feed_typ/abruf_weg prüft das System die URL und wählt den Weg (Feed → Übersicht → MCP). */
class TrendQuellenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_quellen.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Quelle für den Trendradar an (Kuratieren). Ohne feed_typ und abruf_weg prüft das System die URL selbst: Feed gefunden '
            .'(auch über <link rel=alternate> oder /feed/, /rss) → Plattform holt den Feed; sonst Übersichtsseite mit genug erkannten Artikeln → '
            .'Plattform liest die Übersicht; sonst abruf_weg=mcp (die Plattform ruft nie ab, MCP sichtet). Die Antwort nennt erkannt / gewählt / Grund. '
            .'rhythmus (taeglich|woechentlich|monatlich|aus; Standard = Team-Einstellung), stichworte_ein / stichworte_aus = Filter vor dem Eingang '
            .'(Wortanfang, Umlaute egal, Phrasen in Anführungszeichen exakt; leer = Team-Standard).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'url' => ['type' => 'string', 'description' => 'Feed- oder Seiten-URL (http/https).'],
            'name' => ['type' => 'string'],
            'art' => ['type' => 'string', 'enum' => array_keys(V::QUELLEN), 'description' => 'Quellenart, Standard presse.'],
            'feed_typ' => ['type' => 'string', 'enum' => array_keys(V::FEED_TYPEN), 'description' => 'Nur setzen, wenn die automatische Wahl übergangen werden soll.'],
            'abruf_weg' => ['type' => 'string', 'enum' => array_keys(V::ABRUF_WEGE)],
            'rhythmus' => ['type' => 'string', 'enum' => array_keys(V::RHYTHMEN)],
            'schwerpunkt_kategorien' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(V::KATEGORIEN)]],
            'schwerpunkt_sparten' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(V::SPARTEN)]],
            'stichworte_ein' => ['type' => 'array', 'items' => ['type' => 'string']],
            'stichworte_aus' => ['type' => 'array', 'items' => ['type' => 'string']],
            'aktiv' => ['type' => 'boolean'],
        ], 'required' => ['url']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendQuellenService::class);
        try {
            $r = $svc->anlegen($team, $arguments, $context->user?->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['quelle' => $svc->alsArray($r['quelle'], $team), 'pruefung' => $r['pruefung']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'quellen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external', 'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.trend_quellen.GET', 'foodalchemist.trend_quellen.PUT'],
            'examples' => ['Leg gastgewerbe-magazin.de/schlagwort/food-trends als Quelle an, wöchentlich.'],
        ];
    }
}
