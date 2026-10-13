<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PreislistenImportService;

/** Spec 82 §3 / Schritt 4d, Stufe 3 · Treffer eines Preislisten-Laufs als Teampreise übernehmen. */
class PreislisteApplyTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preisliste.APPLY';
    }

    public function getDescription(): string
    {
        return 'Übernimmt die Treffer eines Preislisten-Laufs (lauf_id aus preisliste.UPLOAD) als Teampreise des eigenen Teams: append-only, nie ein Listenpreis, '
            .'unveränderte Preise erzeugen keine neue Zeile, „gültig ab“ in der Zukunft wird erst bei einem späteren Anwenden geschrieben. '
            .'Idempotent: ein zweiter Aufruf schreibt nichts doppelt. Danach werden betroffene Rezepte neu berechnet. Nur das Haupt-Team des Kunden mit Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['lauf_id' => ['type' => 'integer']], 'required' => ['lauf_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(PreislistenImportService::class);
            $run = $svc->lauf($team, (int) ($arguments['lauf_id'] ?? 0)) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException();

            return $svc->anwenden($team, $context->user, $run);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['preisliste'], ['Übernimm die Preise aus der Hanos-Liste.'], ['foodalchemist.preisliste.UPLOAD', 'foodalchemist.preisliste_laeufe.GET']), [
            'tags' => ['foodalchemist', 'preisliste', 'einkauf', 'write', 'preise'],
        ]);
    }
}
