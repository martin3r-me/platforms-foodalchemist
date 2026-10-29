<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PreislistenImportService;

/** Spec 82 §3 / Schritt 4d, Stufe 2 · Preislisten-Läufe des eigenen Teams und ihre Prüfliste lesen. */
class PreislisteLaeufeGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preisliste_laeufe.GET';
    }

    public function getDescription(): string
    {
        return 'Ohne run_id: die letzten Preislisten-Läufe des Teams mit Zählern (Zeilen, treffer, ohne_treffer, mehrdeutig, lieferant_unbekannt, fehler, doppelt). '
            .'Stammdaten-Läufe (art=stammdaten) tragen je Zeile aktion (neu | aendert | unveraendert | konflikt | fehler), diff (Soll/Ist) und hinweise. '
            .'Mit run_id: der Lauf und seine Prüfliste (alles außer Treffer, mit Grund je Zeile; limit Standard 200). Nur eigene Läufe.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'run_id' => ['type' => 'integer'], 'aktion' => ['type' => 'string', 'enum' => ['neu', 'aendert', 'unveraendert', 'konflikt', 'fehler'], 'description' => 'Nur Stammdaten-Läufe: Prüfliste nach Aktion filtern'], 'limit' => ['type' => 'integer', 'description' => 'Zeilen der Prüfliste (Standard 200, max. 1000)'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            $svc = app(PreislistenImportService::class);
            if (empty($arguments['run_id'])) {
                return ['laeufe' => $svc->laeufe($team)->map(fn ($l) => ['id' => $l->id, 'art' => $l->art, 'status' => $l->status, 'datei' => $l->filename, 'angelegt' => $l->created_at?->toIso8601String(), 'zaehler' => $l->counts])->all()];
            }
            $run = $svc->lauf($team, (int) $arguments['run_id']) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $limit = max(1, min(1000, (int) ($arguments['limit'] ?? 200)));
            if ($run->status === 'zuordnung_noetig') {
                return ['lauf' => ['id' => $run->id, 'art' => $run->art, 'status' => $run->status, 'datei' => $run->filename, 'zaehler' => $run->counts],
                    'zuordnung_noetig' => ['grund' => $run->mapping['grund'] ?? null, 'kopf' => $run->mapping['kopf'] ?? [], 'vorschau' => $run->mapping['vorschau'] ?? []], 'pruefliste' => []];
            }
            if ($run->art === 'stammdaten') {
                return [
                    'lauf' => ['id' => $run->id, 'art' => $run->art, 'datei' => $run->filename, 'herkunft' => $run->herkunft, 'zaehler' => $run->counts],
                    'pruefliste' => app(\Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService::class)->pruefliste($run, $arguments['aktion'] ?? null)->take($limit)->map(fn ($z) => [
                        'zeile' => $z->row_nr, 'aktion' => $z->aktion, 'lieferant' => $z->supplier_name, 'artikelnummer' => $z->article_number, 'ean' => $z->ean,
                        'bezeichnung' => $z->designation, 'artikel_id' => $z->supplier_item_id, 'treffer_ueber' => $z->match_method, 'grund' => $z->reason,
                        'diff' => $z->diff, 'hinweise' => $z->hinweise, 'entscheidung' => $z->entscheidung,
                    ])->values()->all(),
                ];
            }

            return [
                'lauf' => ['id' => $run->id, 'datei' => $run->filename, 'zaehler' => $run->counts],
                'pruefliste' => $svc->pruefliste($run)->take($limit)->map(fn ($z) => [
                    'zeile' => $z->row_nr, 'status' => $z->status, 'artikelnummer' => $z->article_number, 'ean' => $z->ean,
                    'bezeichnung' => $z->designation, 'preis' => $z->price !== null ? (float) $z->price : null, 'grund' => $z->reason,
                ])->values()->all(),
            ];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['preisliste'], ['Welche Zeilen der letzten Preisliste haben keinen Treffer?'], ['foodalchemist.preisliste.UPLOAD']), [
            'tags' => ['foodalchemist', 'preisliste', 'einkauf'],
        ]);
    }
}
