<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 93 A · Quellen des Trendradars lesen — fällige zuerst; für den MCP-Weg mit Filter abruf_weg=mcp. */
class TrendQuellenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_quellen.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Quellen des Trendradars (Fachpresse, Branchenquellen, Feeds) — fällige zuerst. abruf_weg=mcp liefert die Quellen, '
            .'die per MCP gesichtet werden (die Plattform ruft sie nie selbst ab): besuchen, sichten, Funde mit '
            .'foodalchemist.trend_eingang.POST und quelle_id einreichen (das setzt letzter_abruf_at). Felder je Quelle: faellig, faellig_seit, '
            .'rhythmus, stichworte_wirksam (Filter vor dem Eingang), letzter_fehler. Mit id: eine Quelle. vokabular=true liefert die erlaubten Werte.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'abruf_weg' => ['type' => 'string', 'enum' => array_keys(V::ABRUF_WEGE)],
            'nur_faellige' => ['type' => 'boolean'],
            'suche' => ['type' => 'string'],
            'vokabular' => ['type' => 'boolean'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendQuellenService::class);
        if (! empty($arguments['id'])) {
            try {
                return ToolResult::success($svc->alsArray($svc->detail($team, (int) $arguments['id']), $team));
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                return ToolResult::error('Quelle nicht gefunden.', 'NOT_FOUND');
            }
        }
        $liste = $svc->liste($team, $arguments);
        $out = ['anzahl' => $liste->count(), 'faellig' => $liste->filter(fn ($q) => $svc->istFaellig($q))->count(),
            'quellen' => $liste->map(fn ($q) => $svc->alsArray($q, $team))->values()->all()];
        if (! empty($arguments['vokabular'])) {
            $out['vokabular'] = ['art' => V::QUELLEN, 'feed_typ' => V::FEED_TYPEN, 'abruf_weg' => V::ABRUF_WEGE, 'rhythmus' => V::RHYTHMEN,
                'kategorie' => V::KATEGORIEN, 'sparten' => V::SPARTEN];
        }

        return ToolResult::success($out);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'trendradar', 'quellen', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.trend_eingang.POST', 'foodalchemist.trend_quellen.POST', 'foodalchemist.trend_quellen.PUT'],
            'examples' => ['Welche MCP-Quellen sind fällig?', 'Zeig mir alle Quellen des Trendradars mit Fehlern.'],
        ];
    }
}
