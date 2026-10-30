<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PhaseService;
use Platform\FoodAlchemist\Support\FacettenWerte;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Gerichte-Konzepte (Concepter-Katalog)
 * des Teams — ergänzt concepts.SEARCH (Cap 50). page/per_page-Paging fürs komplette
 * Enumerieren. Detail via concepts.GET.
 */
class ConceptsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Gerichte-Konzepte (Concepter-Katalog) des Teams vollständig und seitenweise auf (ohne 50er-Cap), '
            . 'gefiltert wie der Concepter: q (Name/Anlass), klasse, status, vorlagen, kategorie (id | none), phase, die Facetten '
            . 'servierform/eventtyp/einsatzmoment/saison (ids – facetten_werte=true liefert die erlaubten Werte mit) und '
            . 'kind=paket für den Pakete-Reiter (Pakete kennen kategorie, phase, vorlagen nicht). page/per_page-Paging (last_page). '
            . 'Je Konzept id, name, kind, status, class, occasion, level, slots, eventtyp, servierform, preis_pro_person, '
            . 'ek_pro_person, wareneinsatz_pct. Detail via foodalchemist.concepts.GET, semantische Suche via foodalchemist.concepts.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suche in Name und Anlass.'],
                'kind' => ['type' => 'string', 'enum' => ['konzept', 'paket', ''], 'description' => 'konzept (Default) oder paket (Concepter-Reiter Pakete).'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archiviert', ''], 'description' => 'Optionaler Status-Filter. Leer = alle.'],
                'vorlagen' => ['type' => 'boolean', 'default' => false, 'description' => 'true = nur Template-Konzepte.'],
                'klasse' => ['type' => 'string', 'description' => 'Konzept-Klasse (class), exakt.'],
                'kategorie' => ['type' => 'string', 'description' => 'Kategorie-id (inkl. Unterkategorien) oder none = ohne Kategorie.'],
                'phase' => ['type' => 'string', 'enum' => [...array_keys(PhaseService::LABELS), ''], 'description' => 'Planungsphase.'],
                'servierform' => ['type' => 'integer', 'description' => 'Servierform-id.'],
                'eventtyp' => ['type' => 'integer', 'description' => 'Eventtyp-id.'],
                'einsatzmoment' => ['type' => 'integer', 'description' => 'Einsatzmoment-id.'],
                'saison' => ['type' => 'integer', 'description' => 'Saison-id.'],
                'facetten_werte' => ['type' => 'boolean', 'description' => 'true = erlaubte ids/Namen der vier Facetten mitliefern.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Seitennummer (last_page aus der Vorantwort).'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße (max. 200).'],
            ],
            'required' => [],
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
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? 100)));
        Paginator::currentPageResolver(fn () => $page);
        $treffer = self::katalog($team, $arguments, $perPage);

        $daten = [
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'concepts' => collect($treffer->items())->map(fn ($c) => self::zeile($c))->all(),
        ];
        if (! empty($arguments['facetten_werte'])) {
            $daten['facetten_werte'] = FacettenWerte::alsParameter($team);
        }

        return $this->erfolgMitWarnungen($daten, $arguments);
    }

    /**
     * Filterweg des Concepter-Browsers für LIST und SEARCH: kind=paket → Pakete-Reiter (paginatePakete), sonst Konzepte.
     * Hier statt in ConceptService, der am Größen-Bestand steht (Spec 85).
     */
    public static function katalog(Team $team, array $a, int $perPage): LengthAwarePaginator
    {
        $svc = app(ConceptService::class);
        $f = [
            'search' => trim((string) ($a['q'] ?? '')),
            'class' => (string) ($a['klasse'] ?? ''),
            'status' => (string) ($a['status'] ?? ''),
            'servierform' => $a['servierform'] ?? null,
            'eventtyp' => $a['eventtyp'] ?? null,
            'einsatzmoment' => $a['einsatzmoment'] ?? null,
            'season' => $a['saison'] ?? null,
        ];
        if (($a['kind'] ?? '') === 'paket') {
            return $svc->paginatePakete($f, $team, $perPage);
        }

        return $svc->paginateBrowser($f + [
            'vorlagen' => filter_var($a['vorlagen'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'category' => ($a['kategorie'] ?? '') !== '' ? (string) $a['kategorie'] : null,
            'phase' => (string) ($a['phase'] ?? ''),
        ], $team, $perPage);
    }

    /** Spalten des Concepter-Browsers; Preise aus dem Team-Preis (ConceptPreisService, über paginateBrowser angewandt). */
    public static function zeile($c): array
    {
        $preis = $c->price_per_person_cache !== null ? (float) $c->price_per_person_cache : null;
        $ek = $c->ek_per_person_cache !== null ? (float) $c->ek_per_person_cache : null;
        // paginateBrowser lädt eventtyp/servierform, paginatePakete eventType/servingForm (gleiche Spalten) — das geladene nehmen, kein N+1
        $eventtyp = $c->relationLoaded('eventtyp') ? $c->eventtyp : $c->eventType;
        $servierform = $c->relationLoaded('servierform') ? $c->servierform : $c->servingForm;

        return [
            'id' => $c->id, 'name' => $c->name, 'kind' => $c->kind === 'paket' ? 'paket' : 'konzept', 'status' => $c->status, 'class' => $c->class,
            'occasion' => $c->occasion, 'level' => $c->level, 'slots' => $c->slots_count,
            'eventtyp' => $eventtyp !== null ? ['id' => (int) $eventtyp->id, 'name' => $eventtyp->name] : null,
            'servierform' => $servierform !== null ? ['id' => (int) $servierform->id, 'name' => $servierform->label] : null,
            'preis_pro_person' => $preis, 'ek_pro_person' => $ek,
            'wareneinsatz_pct' => $preis !== null && $preis > 0 && $ek !== null ? round($ek / $preis * 100, 1) : null,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.concepts.SEARCH', 'foodalchemist.concepts.GET'],
            'examples' => ['Liste alle Konzepte auf', 'Zeig mir alle Template-Konzepte seitenweise'],
        ];
    }
}
