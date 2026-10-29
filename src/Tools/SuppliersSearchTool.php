<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\SupplierGroupService;
use Platform\FoodAlchemist\Services\SupplierService;

/**
 * Spec 15 §5a: Lieferanten (Entität, NICHT Artikel) durchsuchen. Hybrid: lexikalisch
 * (Name) plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über
 * Name/Branche/Stadt (behebt „Lieferant nicht gefunden"). Für Artikel/Sortiment →
 * foodalchemist.artikel.LIST (supplier_id).
 *
 * Spec 91 PR 1: Filter der Lieferantenliste (Gruppe, „einstufen“), Artikel-Zähler, und bei leerer Query echtes Paging mit
 * Gesamtzahl — vorher endete die Auflistung still bei 50.
 */
class SuppliersSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Lieferanten (Entität) des aktuellen Teams. Hybrid: lexikalisch (Name) '
            . 'plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über Name/Branche/'
            . 'Stadt (via: lexical|semantic je Treffer). Ohne Provider rein lexikalisch. LEERE oder fehlende Query '
            . 'listet ALLE sichtbaren Lieferanten seitenweise (page/per_page ≤ 200, total = Gesamtzahl, last_page). Filter wie die '
            . 'Lieferantenliste: gruppe (Code oder Id aus foodalchemist.supplier_groups.LIST, "ohne" = ohne Gruppe), einstufen '
            . '(nur Rolle offen mit Artikeln an Rezepturware/Marke offen), include_inactive. Liefert id, name, branch, group, city, '
            . 'is_inactive, item_count, mapped_count — Details via foodalchemist.suppliers.GET. Sortiment eines Lieferanten → '
            . 'foodalchemist.artikel.LIST mit supplier_id. Unbekannte Parameter stehen in warnungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Name/Branche/Stadt)'],
                'include_inactive' => ['type' => 'boolean', 'default' => false],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15, 'description' => 'Trefferzahl bei Suchbegriff.'],
                'gruppe' => ['type' => 'string', 'description' => 'Code oder Id aus supplier_groups.LIST, "ohne" = Lieferanten ohne Gruppe.'],
                'einstufen' => ['type' => 'boolean', 'default' => false, 'description' => 'Nur Lieferanten zum Einstufen (Rolle offen, Artikel an Rezepturware/Marke offen).'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Seite bei leerer Query.'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50, 'description' => 'Seitengröße bei leerer Query (max. 200).'],
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
        $q = trim((string) ($arguments['q'] ?? ''));
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 15)));
        $includeInactive = (bool) ($arguments['include_inactive'] ?? false);
        $gruppeId = null;
        if (($arguments['gruppe'] ?? '') !== '') {
            $roh = mb_strtolower(trim((string) $arguments['gruppe']));
            $gruppeId = $roh === 'ohne' ? 0 : app(SupplierGroupService::class)->liste($team)
                ->first(fn ($g) => (string) $g->id === $roh || mb_strtolower((string) $g->code) === $roh)?->id;
            if ($gruppeId === null) {
                return ToolResult::error("Gruppe '{$arguments['gruppe']}' unbekannt – Codes via foodalchemist.supplier_groups.LIST, \"ohne\" = ohne Gruppe.", 'VALIDATION_ERROR');
            }
        }
        $einstufen = filter_var($arguments['einstufen'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $service = app(SupplierService::class);
        $offen = $einstufen ? array_keys($service->zumEinstufen()) : null;
        [$itemCounts, $mappedCounts] = $service->artikelZaehler($team);
        $zeile = fn ($s, string $via) => [
            'id' => $s->id, 'name' => $s->name, 'branch' => $s->branch, 'group' => $s->group?->code,
            'city' => $s->city, 'is_inactive' => (bool) $s->is_inactive,
            'item_count' => (int) ($itemCounts[$s->id] ?? 0), 'mapped_count' => (int) ($mappedCounts[$s->id] ?? 0), 'via' => $via,
        ];

        $base = fn () => FoodAlchemistSupplier::visibleToTeam($team)
            ->when(! $includeInactive, fn ($x) => $x->where('is_inactive', false))
            ->when($gruppeId !== null, fn ($x) => $gruppeId === 0 ? $x->whereNull('group_id') : $x->where('group_id', $gruppeId))
            ->when($offen !== null, fn ($x) => $x->whereIn('id', $offen))
            ->with('group:id,code');
        $spalten = ['id', 'name', 'branch', 'group_id', 'city', 'is_inactive'];

        // Leere Query = „alle Lieferanten listen“ — seitenweise mit Gesamtzahl (es gibt kein suppliers.LIST-Tool).
        if ($q === '') {
            $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? $arguments['limit'] ?? 50)));
            $treffer = $base()->orderBy('name')->paginate($perPage, $spalten, 'page', max(1, (int) ($arguments['page'] ?? 1)));

            return $this->erfolgMitWarnungen([
                'total' => $treffer->total(), 'page' => $treffer->currentPage(), 'last_page' => $treffer->lastPage(), 'per_page' => $treffer->perPage(),
                'suppliers' => collect($treffer->items())->map(fn ($s) => $zeile($s, 'list'))->all(),
            ], $arguments);
        }

        $suppliers = $base()->where('name', 'like', '%' . $q . '%')->orderBy('name')->limit($limit)
            ->get($spalten)->map(fn ($s) => $zeile($s, 'lexical'))->all();

        // Spec 15 §5a: semantische Ergänzung — nur was die Lexik NICHT schon fand.
        $semScores = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_SUPPLIER, array_column($suppliers, 'id'), $limit);
        if ($semScores !== []) {
            $rows = $base()->whereIn('id', array_keys($semScores))->get($spalten)->keyBy('id');
            arsort($semScores);
            foreach ($semScores as $id => $score) {
                $s = $rows->get($id);
                if ($s === null || count($suppliers) >= $limit) {
                    continue;
                }
                $suppliers[] = $zeile($s, 'semantic') + ['semantic_score' => round($score, 3)];
            }
        }

        return $this->erfolgMitWarnungen(['total' => count($suppliers), 'suppliers' => $suppliers], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'supplier', 'lieferant', 'search'],
            'related_tools' => ['foodalchemist.suppliers.GET', 'foodalchemist.artikel.LIST', 'foodalchemist.supplier_groups.LIST'],
            'examples' => ['Suche Lieferant Hanos', 'Welche Großhändler gibt es in Venlo?'],
        ];
    }
}
