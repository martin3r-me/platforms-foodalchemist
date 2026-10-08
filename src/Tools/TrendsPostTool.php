<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 79 · Trend erfassen — optional gleich eingeordnet und mit erstem Beleg (Link, Notiz, Screenshot). */
class TrendsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use Concerns\DateiAusArgumenten;

    public function getName(): string
    {
        return 'foodalchemist.trends.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Trend im Trendradar an (Status „gesichtet", braucht Kuratieren). Einzelne Beobachtungen ohne '
            .'Einordnung gehören als Fundstück in die Pinnwand (foodalchemist.fundstuecke.POST). Einordnung nach Sarah Spork optional: typ (trend = Tiefe, '
            .'Bedürfnis, Dauer | hype = Oberfläche, medial, kurzlebig), ebene (mode < konsum < mega < meta), kategorie '
            .'(food|getraenke|deko|event). beleg = erste Quelle (quelle, titel, url, notiz, fundort, beobachtet_am, anteil), '
            .'datei = Screenshot/Foto/PDF als base64 oder url. Gibt es den Namen schon, kommt ein Fehler mit der ID — dann '
            .'foodalchemist.trend_belege.POST nutzen. Vokabular: foodalchemist.trends.GET mit vokabular=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'definition' => ['type' => 'string', 'description' => 'Ein bis zwei Sätze: was ist es, warum relevant.'],
                'typ' => ['type' => 'string', 'enum' => array_keys(V::TYPEN)],
                'ebene' => ['type' => 'string', 'enum' => array_keys(V::EBENEN)],
                'kategorie' => ['type' => 'string', 'enum' => array_keys(V::KATEGORIEN)],
                'food_cluster' => ['type' => 'string', 'enum' => array_keys(V::FOOD_CLUSTER)],
                'sicht' => ['type' => 'string', 'enum' => array_keys(V::SICHTEN)],
                'status' => ['type' => 'string', 'enum' => array_keys(V::STATUS), 'description' => 'Standard gesichtet; auf_radar nur mit Einordnung und bestätigender Quelle.'],
                'suchbegriffe' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Google-Trends-Begriffe (max. 3 werden gemessen).'],
                'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'historische_einordnung' => ['type' => 'string'],
                'gartner_phase' => ['type' => 'string', 'enum' => array_keys(V::GARTNER_PHASEN)],
                'einordnung_quelle' => ['type' => 'string', 'enum' => ['manuell', 'ki'], 'description' => 'ki, wenn ein Modell eingeordnet hat.'],
                'einordnung_begruendung' => ['type' => 'string'],
                'beleg' => ['type' => 'object', 'properties' => [
                    'quelle' => ['type' => 'string', 'enum' => array_keys(V::QUELLEN)],
                    'titel' => ['type' => 'string'], 'url' => ['type' => 'string'], 'notiz' => ['type' => 'string'],
                    'fundort' => ['type' => 'string'], 'beobachtet_am' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'anteil' => ['type' => 'number', 'description' => 'Befragung: Prozent der Nennungen'],
                ]],
                'datei' => self::dateiSchema(),
                'fundstueck_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Fundstücke aus der Pinnwand, die als Belege an den neuen Trend gehen.'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendService::class);
        try {
            $datei = $this->dateiAusArgumenten($arguments['datei'] ?? null);
            if ($datei !== null && empty($arguments['beleg'])) {
                $arguments['beleg'] = ['quelle' => 'beobachtung'];
            }
            $trend = $svc->anlegen($team, $arguments, $context->user?->id, $datei);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }

        return ToolResult::success($svc->alsArray($svc->detail($team, $trend->id), true));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'trends', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.trends.GET', 'foodalchemist.trends.PUT', 'foodalchemist.trend_belege.POST'],
            'examples' => ['Leg den Trend „Dubai-Schokolade" an, Hype, Mode, Food, mit dem Instagram-Link als Beleg.',
                'Mach aus den Fundstücken 4 und 7 den Trend „Loaded Mash Potatoes“.'],
        ];
    }
}
