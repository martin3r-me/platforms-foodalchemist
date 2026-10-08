<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;

/** Spec 77d · Sammlungen pflegen (anlegen, befüllen, leeren, löschen). Ab Kuratieren. */
class SammlungenPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.sammlungen.PUT';
    }

    public function getDescription(): string
    {
        return 'Sammlungen = benannte Listen aus Rezepten (Gerichte und Basisrezepte), Konzepten und Formaten zum Freigeben an Standorte, '
            .'ohne Layout. Eine Aktion je Aufruf: anlegen (name, beschreibung?), hinzufuegen (sammlung_id, typ recipe|concept|format, '
            .'ids[] — mehrere auf einmal), entfernen (sammlung_id, typ, id), loeschen (sammlung_id). Ab Kuratieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'required' => ['aktion'], 'properties' => [
            'aktion' => ['type' => 'string', 'enum' => ['anlegen', 'hinzufuegen', 'entfernen', 'loeschen']],
            'name' => ['type' => 'string'], 'beschreibung' => ['type' => 'string'], 'sammlung_id' => ['type' => 'integer'],
            'typ' => ['type' => 'string', 'enum' => ['recipe', 'concept', 'format']], 'id' => ['type' => 'integer'],
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments, $context) {
            $svc = app(InhaltsFreigabeService::class);
            $sid = (int) ($arguments['sammlung_id'] ?? 0);
            $extra = [];
            switch ($arguments['aktion'] ?? '') {
                case 'anlegen':
                    $extra['sammlung_id'] = $svc->sammlungAnlegen($team, (string) ($arguments['name'] ?? ''), $arguments['beschreibung'] ?? null, $context->user);
                    break;
                case 'hinzufuegen':
                    $extra['hinzugefuegt'] = $svc->sammlungHinzu($team, $sid, (string) ($arguments['typ'] ?? ''), array_map('intval', (array) ($arguments['ids'] ?? [])), $context->user);
                    break;
                case 'entfernen':
                    $svc->sammlungEntfernen($team, $sid, (string) ($arguments['typ'] ?? ''), (int) ($arguments['id'] ?? 0), $context->user);
                    break;
                case 'loeschen':
                    $svc->sammlungLoeschen($team, $sid, $context->user);
                    break;
                default:
                    throw new \RuntimeException('aktion: anlegen, hinzufuegen, entfernen oder loeschen.');
            }

            return $extra + ['sammlungen' => $svc->sammlungen($team)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['sammlungen', 'freigaben'], ['Leg eine Sammlung „Grundsortiment Mittag" mit diesen fünf Gerichten an.'], ['foodalchemist.standort_inhalte.PUT']);
    }
}
