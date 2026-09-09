<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Database\Query\Builder;

/**
 * Löst kuratierte Werte auf. ProportionService bleibt der Rechner für Formeln;
 * hier liegen weder Formeln noch fachlich erfundene Default-Zahlen.
 */
class DatenwerkResolver
{
    public function resolve(Builder $visibleDocuments, array $params): array
    {
        $candidates = [];
        $gaps = [];
        $docs = (clone $visibleDocuments)->where('art', Wissensart::DATENWERK)
            ->where('active', true)->whereNull('deleted_at')->orderBy('slug')
            ->get(['slug', 'version', 'geltung', 'datenwerte']);
        foreach ($docs as $doc) {
            $geltung = WissensGeltung::lesen($doc->geltung);
            if (! WissensGeltung::passt($geltung, $params)) continue;
            $rows = WissensGeltung::lesen($doc->datenwerte);
            if ($rows === []) {
                $gaps[] = ['status' => 'luecke', 'quelle' => "{$doc->slug}@v{$doc->version}", 'grund' => 'Keine strukturierten Werte gepflegt.'];
            }
            foreach ($rows as $row) {
                if (($geltung === [] && ($row['geltung'] ?? []) === []) || ! WissensGeltung::passt($row['geltung'] ?? [], $params)) continue;
                $candidates[$row['kennzahl']][] = $row + ['dossier' => "{$doc->slug}@v{$doc->version}"];
            }
        }
        $results = [];
        foreach ($candidates as $key => $rows) {
            $distinct = array_unique(array_map(static fn ($v) => json_encode([$v['min'], $v['max'], $v['einheit'], $v['bezug']]), $rows));
            $results[] = ['kennzahl' => $key, 'status' => count($distinct) === 1 ? 'aufgeloest' : 'widerspruch',
                'werte' => count($distinct) === 1 ? array_intersect_key($rows[0], array_flip(['min', 'max', 'einheit', 'bezug'])) : null,
                'kandidaten' => $rows];
        }
        if ($results === [] && $gaps === []) $gaps[] = ['status' => 'luecke', 'grund' => 'Kein Datenwert für die angegebenen Geltungsbedingungen.'];
        return ['ergebnisse' => $results, 'luecken' => $gaps];
    }
}
