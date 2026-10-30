<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Support\FacettenWerte;

/** Format-Modul: Formate (Marken-/Themen-Container über den Concepts) auflisten. */
class FormatsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet Formate (Marken-/Themen-Container EINE Ebene über den Konzepten, z. B. „CHEFS.CORNER") '
            . 'mit Editionen-Anzahl, Eventtyp und Servierform. Filter wie der Format-Browser: q (Name, Kundenname, Claim), status '
            . '(draft|active|archiviert), origin (eigen|gruppe|kunde), Facetten servierform/eventtyp/einsatzmoment/saison (ids – '
            . 'facetten_werte=true liefert die erlaubten Werte mit); page/per_page (Default 100, max. 200, last_page).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suche in Name, Kundenname, Claim.'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archiviert', '']],
                'origin' => ['type' => 'string', 'enum' => ['eigen', 'gruppe', 'kunde', '']],
                'servierform' => ['type' => 'integer', 'description' => 'Servierform-id.'],
                'eventtyp' => ['type' => 'integer', 'description' => 'Eventtyp-id.'],
                'einsatzmoment' => ['type' => 'integer', 'description' => 'Einsatzmoment-id.'],
                'saison' => ['type' => 'integer', 'description' => 'Saison-id.'],
                'facetten_werte' => ['type' => 'boolean', 'description' => 'true = erlaubte ids/Namen der vier Facetten mitliefern.'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1).'],
                'per_page' => ['type' => 'integer', 'description' => 'Zeilen je Seite (Default 100, max. 200).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        if ($fehler = $this->enumFehler($arguments)) {
            return $fehler;
        }
        $seite = max(1, (int) ($arguments['page'] ?? 1));
        Paginator::currentPageResolver(fn () => $seite);
        $page = app(FormatService::class)->paginateBrowser([
            'search' => trim((string) ($arguments['q'] ?? '')),
            'status' => $arguments['status'] ?? '',
            'origin' => $arguments['origin'] ?? '',
            'servierform' => $arguments['servierform'] ?? null,
            'eventtyp' => $arguments['eventtyp'] ?? null,
            'einsatzmoment' => $arguments['einsatzmoment'] ?? null,
            'season' => $arguments['saison'] ?? null,
        ], $team, min(200, max(1, (int) ($arguments['per_page'] ?? 100))));

        $daten = [
            'formats' => collect($page->items())->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'consumer_name' => $f->consumer_name,
                'status' => $f->status, 'origin' => $f->origin, 'editions_count' => (int) $f->editions_count,
                'eventtyp' => $f->eventType !== null ? ['id' => (int) $f->eventType->id, 'name' => $f->eventType->name] : null,
                'servierform' => $f->servingForm !== null ? ['id' => (int) $f->servingForm->id, 'name' => $f->servingForm->label] : null,
            ])->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
        ];
        if (! empty($arguments['facetten_werte'])) {
            $daten['facetten_werte'] = FacettenWerte::alsParameter($team);
        }

        return $this->erfolgMitWarnungen($daten, $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'liste'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET', 'foodalchemist.formats.SEARCH'],
            'examples' => ['Zeig mir alle Formate', 'Welche aktiven Formate gibt es?'],
        ];
    }
}
