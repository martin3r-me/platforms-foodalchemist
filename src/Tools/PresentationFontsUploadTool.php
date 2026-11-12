<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationFontService;
use Platform\FoodAlchemist\Tools\Concerns\DateiAusArgumenten;

/** Eigene Schrift (Hausschrift/Kunden-CI) fürs Team hochladen — nutzbar in Präsentations-Designs als typography "team:<Familie>". */
class PresentationFontsUploadTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use DateiAusArgumenten;

    public function getName(): string
    {
        return 'foodalchemist.presentation_fonts.UPLOAD';
    }

    public function getDescription(): string
    {
        return 'Lädt eine Schriftdatei (woff2/woff/ttf/otf, max. 5 MB) als eigene Team-Schrift hoch: datei.base64 ODER datei.url, family (Name), '
            . 'optional weight (100–900, Default 400) und style (normal|italic). Eine Datei = ein Schnitt; gleicher Schnitt ersetzt den alten. '
            . 'Danach im Design nutzbar: presentation_designs.PUT tokens_json.typography.heading/body = "team:<family>". '
            . 'Ohne Argumente außer list=true: listet die sichtbaren Team-Schriften. Nur Schriften mit Web-Lizenz hochladen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'family' => ['type' => 'string', 'description' => 'Schriftname, z. B. "Green Tasty Sans".'],
            'weight' => ['type' => 'integer', 'description' => 'Stärke 100–900 (Default 400).'],
            'style' => ['type' => 'string', 'enum' => ['normal', 'italic']],
            'datei' => ['type' => 'object', 'description' => 'Schriftdatei: base64 (auch data-URL) ODER url, dazu name mit Endung (.woff2/.woff/.ttf/.otf).',
                'properties' => ['base64' => ['type' => 'string'], 'url' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'list' => ['type' => 'boolean', 'description' => 'true = nur auflisten, nichts hochladen.'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(PresentationFontService::class);
        $liste = fn () => $svc->list($team)->map(fn ($f) => ['id' => (int) $f->id, 'family' => $f->family, 'token' => 'team:' . $f->family,
            'weight' => (int) $f->weight, 'style' => $f->style, 'format' => $f->format])->values()->all();
        if (($arguments['list'] ?? false) === true) {
            return ToolResult::success(['schriften' => $liste()]);
        }
        try {
            $datei = $this->dateiAusArgumenten(is_array($arguments['datei'] ?? null) ? $arguments['datei'] : null);
            if ($datei === null) {
                return ToolResult::error('datei.base64 oder datei.url angeben (oder list=true).', 'VALIDATION_ERROR');
            }
            $font = $svc->upload($team, $datei, (string) ($arguments['family'] ?? ''), (int) ($arguments['weight'] ?? 400), (string) ($arguments['style'] ?? 'normal'));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }

        return ToolResult::success(['schrift' => ['id' => (int) $font->id, 'family' => $font->family, 'token' => 'team:' . $font->family,
            'weight' => (int) $font->weight, 'style' => $font->style, 'format' => $font->format], 'schriften' => $liste()]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'praesentation', 'design', 'schrift', 'font', 'upload', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_io',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.presentation_designs.PUT'],
            'examples' => ['Lade die Hausschrift von https://…/brand.woff2 als „Green Tasty Sans" hoch.'],
        ];
    }
}
