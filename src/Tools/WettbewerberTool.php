<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Validation\ValidationException;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WettbewerbService;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class WettbewerberTool extends FoodAlchemistTool
{
    protected function felder(): array
    {
        $strings = ['name', 'website', 'region', 'gebiet', 'notiz', 'rhythmus', 'presse_feed'];
        $felder = array_fill_keys($strings, ['type' => 'string']);
        foreach (['aliases', 'suchbegriffe', 'ausschliessen', 'branchen'] as $f) {
            $felder[$f] = ['type' => 'array', 'items' => ['type' => 'string']];
        }
        $felder['quellen_ids'] = ['type' => 'array', 'items' => ['type' => 'integer']];
        foreach (['aktiv', 'rss_aktiv'] as $f) {
            $felder[$f] = ['type' => 'boolean'];
        }

        return $felder;
    }

    protected function ausfuehren(ToolContext $context, callable $aktion): ToolResult
    {
        $team = $this->team($context);
        if (! $team) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            return ToolResult::success($aktion(app(WettbewerbService::class), $team));
        } catch (ValidationException $e) {
            return ToolResult::error(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'VALIDATION_ERROR');
        } catch (HttpException $e) {
            return ToolResult::error('Datensatz nicht verfügbar oder nicht bearbeitbar.', $e->getStatusCode() === 404 ? 'NOT_FOUND' : 'ACCESS_DENIED');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        $lesen = str_ends_with($this->getName(), '.GET');

        return ['category' => $lesen ? 'query' : 'action', 'tags' => ['foodalchemist', 'wettbewerber', 'research', $lesen ? 'read' : 'write'],
            'read_only' => $lesen, 'idempotent' => $lesen, 'risk_level' => $lesen ? 'safe' : 'write', 'requires_auth' => true,
            'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => $lesen ? [] : ['updates'],
            'related_tools' => ['foodalchemist.wettbewerber.GET', 'foodalchemist.wettbewerber.POST', 'foodalchemist.wettbewerber.PUT', 'foodalchemist.wettbewerber.ZUORDNEN']];
    }
}
