<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 79 · Beleg an einen Trend hängen: Quelle mit Link, Notiz, Fundort, Datum, Anteil (Befragung) und/oder Datei. */
class TrendBelegePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use Concerns\DateiAusArgumenten;

    public function getName(): string
    {
        return 'foodalchemist.trend_belege.POST';
    }

    public function getDescription(): string
    {
        return 'Hängt einen Beleg an einen Trend: quelle (marktforschung, kaufverhalten, befragung, literatur, branchenquelle, '
            .'presse, beobachtung, instagram, social_media, google_trends), titel, url, notiz, fundort, beobachtet_am, anteil '
            .'(Befragung, % der Nennungen) und optional datei (Screenshot/Foto/PDF als base64 oder url). Die Konfidenz des '
            .'Trends wird danach neu berechnet. Für Ergebnisse der Mitarbeiterbefragung (z. B. aus Office/Hatch) quelle=befragung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'trend_id' => ['type' => 'integer'],
                'quelle' => ['type' => 'string', 'enum' => array_keys(V::QUELLEN)],
                'titel' => ['type' => 'string'], 'url' => ['type' => 'string'], 'notiz' => ['type' => 'string'],
                'fundort' => ['type' => 'string', 'description' => 'Wo gesehen: Account, Lokal, Messe …'],
                'beobachtet_am' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Standard heute'],
                'anteil' => ['type' => 'number'],
                'datei' => self::dateiSchema(),
            ],
            'required' => ['trend_id', 'quelle'],
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
            $beleg = $svc->belegAnhaengen($team, (int) $arguments['trend_id'], $arguments, $datei, $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Trend nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }
        $trend = $svc->detail($team, (int) $beleg->trend_id);

        return ToolResult::success(['beleg_id' => $beleg->id, 'datei' => $beleg->datei_name,
            'konfidenz' => $trend->wirksameKonfidenz(), 'konfidenz_begruendung' => $svc->bewertung($trend)['begruendung'],
            'radar_hindernis' => $svc->radarHindernis($trend)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'beleg', 'upload', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.trends.GET', 'foodalchemist.trend_belege.DELETE'],
            'examples' => ['Häng diesen Instagram-Screenshot an Trend 5.', '42 % der Befragten nannten Trend 9 — als Befragungs-Beleg eintragen.'],
        ];
    }
}
