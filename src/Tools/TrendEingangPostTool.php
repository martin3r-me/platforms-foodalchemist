<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendEingangService;

/** Spec 93 A · Artikel per MCP in den Eingang einreichen — derselbe Eingang und dieselben Filter wie der Plattform-Abruf. */
class TrendEingangPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_eingang.POST';
    }

    public function getDescription(): string
    {
        return 'Reicht Artikel in den Eingang des Trendradars ein (Kuratieren). Je Artikel url, titel, optional veroeffentlicht_am (YYYY-MM-DD), '
            .'teaser (eigene kurze Beschreibung, KEIN Volltext) und quelle_id (setzt letzter_abruf_at der Quelle). Einzeln oder als artikel-Liste '
            .'(max. 50). Ergebnis je Artikel: neu | dublette (gleiche URL in der Teamfamilie, Tracking-Parameter egal) | gefiltert (Stichwort-Filter '
            .'der Quelle bzw. des Teams; grund nennt das Stichwort). Landet nie auf der Pinnwand. '
            .'Studie oder Trendliste mit mehreren Trends: je Artikel signale = [{titel, teaser}] (max. 12) mitgeben — dann wird jedes '
            .'Signal ein eigener Eintrag mit eigenem Thema (gleiche URL, Dublette = URL + Signal-Titel); ohne signale landet die ganze '
            .'Studie in einem Thema, und Sammelthemen wie „Trendprognose 2027“ lehnt das Erschließen ab. Höchstens 50 Einträge je Aufruf (Signale zählen einzeln).';
    }

    public function getSchema(): array
    {
        $artikel = ['type' => 'object', 'properties' => [
            'url' => ['type' => 'string'], 'titel' => ['type' => 'string'],
            'veroeffentlicht_am' => ['type' => 'string'], 'teaser' => ['type' => 'string'],
            'herausgeber' => ['type' => 'string'], 'herausgeber_url' => ['type' => 'string'],
            'signale' => ['type' => 'array', 'description' => 'Einzelsignale des Beitrags (Studie/Trendliste), je {titel, teaser}; max. 12.',
                'items' => ['type' => 'object', 'properties' => ['titel' => ['type' => 'string'], 'teaser' => ['type' => 'string']]]],
        ]];

        return ['type' => 'object', 'properties' => $artikel['properties'] + [
            'quelle_id' => ['type' => 'integer', 'description' => 'Quelle aus foodalchemist.trend_quellen.GET (eigenes Team).'],
            'artikel' => ['type' => 'array', 'items' => $artikel, 'description' => 'Mehrere Artikel derselben Quelle auf einmal.'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $liste = ! empty($arguments['artikel']) && is_array($arguments['artikel']) ? array_values($arguments['artikel']) : [$arguments];
        $eintraege = array_sum(array_map(fn ($a) => ! empty(((array) $a)['signale']) && is_array(((array) $a)['signale']) ? count(((array) $a)['signale']) : 1, $liste));
        if (count($liste) > 50 || $eintraege > 50) {
            return ToolResult::error('Höchstens 50 Einträge je Aufruf (Signale zählen einzeln).', 'VALIDATION_ERROR');
        }
        $svc = app(TrendEingangService::class);
        $ergebnisse = [];
        foreach ($liste as $i => $a) {
            $a = (array) $a;
            if (! empty($arguments['quelle_id'])) {
                $a['quelle_id'] = (int) $arguments['quelle_id'];
            }
            if (! empty($a['signale']) && is_array($a['signale'])) {
                try {
                    foreach ($svc->signaleEinreichen($team, $a, array_values($a['signale']), 'mcp', $context->user?->id) as $r) {
                        $ergebnisse[] = ['nr' => $i, 'url' => $a['url'] ?? null, 'signal' => $r['signal'], 'ergebnis' => $r['ergebnis'],
                            'grund' => $r['grund'], 'id' => $r['eintrag'] !== null ? (int) $r['eintrag']->id : null];
                    }
                } catch (ModelNotFoundException) {
                    return ToolResult::error('Quelle nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
                } catch (\RuntimeException $e) {
                    $ergebnisse[] = ['nr' => $i, 'url' => $a['url'] ?? null, 'ergebnis' => 'fehler', 'grund' => $e->getMessage(), 'id' => null];
                }

                continue;
            }
            try {
                $r = $svc->einreichen($team, $a, 'mcp', $context->user?->id);
                $ergebnisse[] = ['nr' => $i, 'url' => $a['url'] ?? null, 'ergebnis' => $r['ergebnis'], 'grund' => $r['grund'],
                    'id' => $r['eintrag'] !== null ? (int) $r['eintrag']->id : null];
            } catch (ModelNotFoundException) {
                return ToolResult::error('Quelle nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
            } catch (\RuntimeException $e) {
                $ergebnisse[] = ['nr' => $i, 'url' => $a['url'] ?? null, 'ergebnis' => 'fehler', 'grund' => $e->getMessage(), 'id' => null];
            }
        }
        if (count($liste) === 1 && $ergebnisse[0]['ergebnis'] === 'fehler') {
            return ToolResult::error((string) $ergebnisse[0]['grund'], 'VALIDATION_ERROR');
        }
        $zaehler = array_count_values(array_column($ergebnisse, 'ergebnis'));

        return ToolResult::success(['zaehler' => $zaehler, 'ergebnisse' => $ergebnisse]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'eingang', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.trend_quellen.GET', 'foodalchemist.trend_eingang.GET'],
            'examples' => ['Reich diese drei Artikel von Quelle 4 in den Trend-Eingang ein.'],
        ];
    }
}
