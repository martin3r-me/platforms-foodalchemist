<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 79 · Fundstück in die Inspirations-Pinnwand legen — Screenshot, Link, Notiz; ohne Einordnung. */
class FundstueckePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use Concerns\DateiAusArgumenten;

    public function getName(): string
    {
        return 'foodalchemist.fundstuecke.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Quelle in die Inspirations-Pinnwand des Trendradars: etwas Gesehenes (Instagram-Post, Video, Foto im '
            .'Restaurant, Artikel), noch KEIN Trend. Gibt es das Thema schon als Inspiration, inspiration_id mitgeben — dann landet die '
            .'Quelle auf derselben Karte statt einer neuen. Felder: titel, quelle (Standard instagram), url, notiz, fundort, '
            .'beobachtet_am, schlagworte, datei (Screenshot/Foto/PDF als base64 oder url), optional trend_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'titel' => ['type' => 'string', 'description' => 'Kurz: was ist zu sehen?'],
                'quelle' => ['type' => 'string', 'enum' => array_keys(V::QUELLEN)],
                'url' => ['type' => 'string'], 'notiz' => ['type' => 'string'],
                'fundort' => ['type' => 'string', 'description' => 'Account, Lokal, Messe …'],
                'beobachtet_am' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Standard heute'],
                'schlagworte' => ['type' => 'array', 'items' => ['type' => 'string']],
                'inspiration_id' => ['type' => 'integer', 'description' => 'Bestehende Inspiration (eigenes Team), zu der diese Quelle gehört. Ohne: neue Inspiration. Vorher mit fundstuecke.GET suche/schlagwort prüfen, ob es das Thema schon gibt.'],
                'trend_id' => ['type' => 'integer'],
                'datei' => self::dateiSchema(),
            ],
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
            $b = $svc->fundstueckAblegen($team, $arguments, $datei, $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Trend nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }

        return ToolResult::success(['fundstueck_id' => $b->id, 'inspiration_id' => $b->inspiration_id,
            'inspiration' => $b->inspiration?->titel, 'quellen' => $b->inspiration?->quellen()->count(),
            'titel' => $b->titel, 'datei' => $b->datei_name, 'trend_id' => $b->trend_id, 'haeufungen' => $svc->haeufungen($team)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'inspiration', 'fundstueck', 'upload', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.fundstuecke.GET', 'foodalchemist.fundstuecke.PUT'],
            'examples' => ['Hier ein Screenshot von Instagram — leg das als Inspiration ab.', 'Ich habe im Lokal X herzhafte Lollis gesehen.'],
        ];
    }
}
