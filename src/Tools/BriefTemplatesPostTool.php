<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BriefTemplateService;
use RuntimeException;

/**
 * Legt eine team-eigene Schnellstart-Vorlage (Brief-Template) an: Brief + Kreativ-Modus +
 * Leitplanken-Snapshot, je Scope. Der `regler`-Snapshot wird as-is gespeichert; beim Anwenden im
 * Editor werden nur Keys gesetzt, die der Ziel-Regler-Satz des Scopes führt. Kuratierte Globals
 * bleiben unberührt (die pflegt das Master-Team).
 */
class BriefTemplatesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.brief_templates.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine NEUE team-eigene Schnellstart-Vorlage (Brief-Template) für die Planung-Erzeugung an. '
            . 'Eine Vorlage bündelt einen Startpunkt: Briefing-Text + Kreativ-Modus + Leitplanken-Snapshot (regler), '
            . 'gebunden an einen Scope (rezept|gericht|concept). Der regler-Snapshot darf reale Leitplanken-Keys tragen '
            . '(z. B. convenience, level, bio_praeferenz, frische[], aroma_kueche, sektor, occasion, serviceform, '
            . 'diaet_hart[], allergen_nogo[], pax, ziel_portion_g, menue_typ …). Anwenden im Editor als ★-Chip.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['rezept', 'gericht', 'concept'], 'description' => 'Creation-Tab, für den die Vorlage gilt.'],
                'label' => ['type' => 'string', 'description' => 'Anzeigename der Vorlage (Pflicht).'],
                'brief' => ['type' => 'string', 'description' => 'Briefing-Text, der in die Erzeugung geht (Pflicht).'],
                'regler' => ['type' => 'object', 'description' => 'Leitplanken-Snapshot als key→value (nur reale Regler-Keys werden beim Anwenden gesetzt). Optional.'],
                'titel' => ['type' => 'string', 'description' => 'Optionaler Vorschlags-Titel (wird beim Anwenden nur gesetzt, wenn das Titelfeld leer ist).'],
                'creative_mode' => ['type' => 'string', 'description' => 'Optionaler Kreativ-Modus (voll_kreativ|hybrid|datenbank).'],
            ],
            'required' => ['scope', 'label', 'brief'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $regler = $arguments['regler'] ?? [];
        if (! is_array($regler)) {
            $regler = [];
        }
        try {
            $tpl = app(BriefTemplateService::class)->speichere(
                $team,
                (string) ($arguments['scope'] ?? ''),
                (string) ($arguments['label'] ?? ''),
                (string) ($arguments['brief'] ?? ''),
                $regler,
                isset($arguments['titel']) ? (string) $arguments['titel'] : null,
                isset($arguments['creative_mode']) ? (string) $arguments['creative_mode'] : null,
                $context->user?->id,
            );
        } catch (RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(BriefTemplatesListTool::arr($tpl), ['created' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'planung', 'vorlage', 'template', 'schnellstart', 'brief', 'create'],
            'related_tools' => ['foodalchemist.brief_templates.LIST', 'foodalchemist.brief_templates.PUT', 'foodalchemist.brief_templates.DELETE'],
            'examples' => ['Lege eine Gericht-Vorlage „Bio-Buffet" an', 'Speichere diesen Brief + Leitplanken als Vorlage'],
        ];
    }
}
