<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DruckerService;

/** Spec 78 · Druckerprofile des Betriebs (Modell, Format, Ränder, Arbeitsplatz) + Kompatibilitätsliste. */
class PrintersGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.printers.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Druckerprofile für Etiketten (Name, Modell, Format/Maß, Ränder-Versatz, Arbeitsplatz Küche/Lager/Büro, Standard) '
            . 'und die Kompatibilitätsliste der Modelle. Eine Etikettenvorlage kann einen Drucker wählen (label_templates.POST drucker_id).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(DruckerService::class);

        return ToolResult::success([
            'drucker' => $svc->liste($team)->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'modell' => $p->modell, 'format' => $svc->format($p),
                'arbeitsplatz' => $p->arbeitsplatz, 'standard' => (bool) $p->is_default, 'betrieb_id' => $p->outlet_id])->values()->all(),
            'modelle' => DruckerService::MODELLE,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'etiketten', 'drucker', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.label_templates.POST'],
            'examples' => ['Welche Etikettendrucker sind eingerichtet?'],
        ];
    }
}
