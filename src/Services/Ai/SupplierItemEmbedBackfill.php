<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\Core\Services\EmbeddingStoreRegistry;
use Platform\Core\Services\QdrantEmbeddingStore;
use Throwable;

/**
 * Lieferantenartikel-Embeddings gezielt nachziehen: nur die FEHLENDEN, nach IDs, in Stapeln mit
 * Pause und Zeitfenster. Ergänzt `PoolEmbeddingService::embedSupplierItems()` (Vollabgleich),
 * das über ALLE Artikel läuft und je Artikel einen Hash-Abruf im Store macht (bei Qdrant ein
 * HTTP-GET je Artikel). Hier wird stattdessen EINMAL je Partition der Bestand an Vektoren
 * gelesen (Qdrant-Scroll bzw. core_embeddings), alles Vorhandene fällt ohne Abruf heraus.
 *
 * Der Embed-Text bleibt derselbe wie im Vollabgleich ({@see PoolEmbeddingService::supplierItemEmbedText}):
 * Bezeichnung, Marketing-Name, Marke — und die Structure-Felder nur, wenn der Artikel schon eine
 * Structure-Zeile hat. Es wird hier NICHTS klassifiziert. Ein später klassifizierter Artikel hat
 * einen anderen Text und damit einen anderen Hash; den holt der reguläre Vollabgleich nach
 * (`--missing` selbst fasst Vorhandenes nie an).
 *
 * Ein Fehler im Provider-/Store-Aufruf verwirft nur den betroffenen Stapel (protokolliert, die
 * IDs landen im Ergebnis) — der Lauf geht weiter. Nach {@see self::MAX_FEHLER_IN_FOLGE} Fehlern
 * hintereinander bricht er ab (Provider ersichtlich down; sonst liefe er den ganzen Bestand durch).
 */
class SupplierItemEmbedBackfill
{
    public const MAX_FEHLER_IN_FOLGE = 10;

    private const SCROLL_SEITE = 5000;

    public function __construct(private readonly PoolEmbeddingService $pools) {}

    /**
     * @param  array{
     *     missing?: bool, ids?: list<int>, limit?: ?int, batch?: int, sleep?: float,
     *     until?: ?\DateTimeInterface, team?: ?int, dry_run?: bool, on_batch?: ?callable
     * }  $opt
     * @return array{
     *     available: bool, scanned: int, missing: int, embedded: int, failed_batches: int,
     *     failed_ids: list<int>, stopped: ?string, partitions: array<int,int>
     * }
     */
    public function run(array $opt = []): array
    {
        $res = ['available' => true, 'scanned' => 0, 'missing' => 0, 'embedded' => 0, 'failed_batches' => 0,
            'failed_ids' => [], 'stopped' => null, 'partitions' => []];

        if (! $this->pools->isProviderAvailable()) {
            $res['available'] = false;

            return $res;
        }

        $nurFehlende = (bool) ($opt['missing'] ?? false);
        $ids = array_values(array_unique(array_map('intval', $opt['ids'] ?? [])));
        $limit = isset($opt['limit']) && $opt['limit'] > 0 ? (int) $opt['limit'] : null;
        $batch = max(1, min((int) ($opt['batch'] ?? 500), 1000));
        $sleep = max(0.0, (float) ($opt['sleep'] ?? 0));
        $until = $opt['until'] ?? null;
        $dry = (bool) ($opt['dry_run'] ?? false);
        $onBatch = $opt['on_batch'] ?? null;

        $service = app(EmbeddingService::class);
        $providerName = $this->pools->providerName();
        $vorhanden = [];   // Partition → [entity_id => true], je Partition einmal geladen
        $folge = 0;

        $query = DB::table('foodalchemist_supplier_items as si')
            ->leftJoin('foodalchemist_supplier_item_structures as st', 'st.supplier_item_id', '=', 'si.id')
            ->whereNull('si.deleted_at')
            ->where(fn ($w) => $w->where('si.is_discontinued', false)->orWhereNull('si.is_discontinued'));
        if (($opt['team'] ?? null) !== null) {
            $query->where('si.team_id', (int) $opt['team']);
        }
        if ($ids !== []) {
            $query->whereIn('si.id', $ids);
        }
        $query->select('si.id', 'si.team_id', 'si.designation', 'si.marketing_name', 'si.brand',
            'st.main_ingredient_display', 'st.gp_name_derived');

        $query->chunkById(2000, function ($rows) use (
            &$res, &$vorhanden, &$folge, $nurFehlende, $limit, $batch, $sleep, $until, $dry, $onBatch,
            $service, $providerName,
        ): bool {
            $byTeam = [];
            foreach ($rows as $row) {
                $res['scanned']++;
                $text = $this->pools->supplierItemEmbedText($row);
                if ($text === '') {
                    continue;
                }
                $team = $this->pools->partitionTeamId($row->team_id);
                if ($nurFehlende) {
                    $vorhanden[$team] ??= $this->vorhandeneIds($team);
                    if (isset($vorhanden[$team][(int) $row->id])) {
                        continue;
                    }
                }
                $res['missing']++;
                $byTeam[$team][] = ['id' => (int) $row->id, 'text' => $text];
            }

            foreach ($byTeam as $team => $entries) {
                foreach (array_chunk($entries, $batch) as $stapel) {
                    foreach ($this->pools->chunkByBudget($stapel) as $sub) {
                        if ($limit !== null && $res['embedded'] >= $limit) {
                            $res['stopped'] = 'limit';

                            return false;
                        }
                        if ($until !== null && now()->greaterThanOrEqualTo($until)) {
                            $res['stopped'] = 'zeitfenster';

                            return false;
                        }
                        if ($limit !== null) {
                            $sub = array_slice($sub, 0, $limit - $res['embedded']);
                        }
                        if ($dry) {
                            $res['embedded'] += count($sub);
                            $res['partitions'][(int) $team] = ($res['partitions'][(int) $team] ?? 0) + count($sub);

                            continue;
                        }
                        try {
                            $service->embedAndStoreBatch(
                                teamId: (int) $team,
                                entityType: PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM,
                                entries: $sub,
                                providerName: $providerName,
                            );
                            $res['embedded'] += count($sub);
                            $res['partitions'][(int) $team] = ($res['partitions'][(int) $team] ?? 0) + count($sub);
                            $folge = 0;
                        } catch (Throwable $e) {
                            $res['failed_batches']++;
                            foreach ($sub as $e2) {
                                $res['failed_ids'][] = (int) $e2['id'];
                            }
                            $folge++;
                            Log::warning('foodalchemist:embed --pool=la: Stapel übersprungen', [
                                'team' => $team, 'anzahl' => count($sub), 'erste_id' => $sub[0]['id'] ?? null,
                                'fehler' => $e->getMessage(),
                            ]);
                            if ($folge >= self::MAX_FEHLER_IN_FOLGE) {
                                $res['stopped'] = 'fehler';

                                return false;
                            }
                        }
                        if ($onBatch !== null) {
                            $onBatch($res);
                        }
                        if ($sleep > 0) {
                            usleep((int) ($sleep * 1_000_000));
                        }
                    }
                }
            }

            return true;
        }, 'si.id', 'id');

        return $res;
    }

    /**
     * Alle Lieferantenartikel-IDs, die in der Partition schon einen Vektor haben (Provider + Modell
     * des aktuellen Embedders). Qdrant: Scroll nur über die Payload-Spalte entity_id, Seiten à
     * {@see self::SCROLL_SEITE}. MySQL-Store: ein pluck auf core_embeddings.
     *
     * @return array<int,true>
     */
    public function vorhandeneIds(int $partition): array
    {
        $registry = app(EmbeddingProviderRegistry::class);
        $name = $this->pools->providerName();
        $provider = $name !== null ? $registry->get($name) : $registry->getDefaultProvider();
        if ($provider === null) {
            return [];
        }
        $store = app(EmbeddingStoreRegistry::class)->resolve(null, PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM);

        if ($store instanceof QdrantEmbeddingStore) {
            return $this->qdrantScroll($partition, $provider->getName(), $provider->getModel());
        }

        $out = [];
        foreach (DB::table('core_embeddings')
            ->where('team_id', $partition)
            ->where('entity_type', PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM)
            ->where('provider', $provider->getName())
            ->where('model', $provider->getModel())
            ->pluck('entity_id') as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /** @return array<int,true> */
    private function qdrantScroll(int $partition, string $provider, string $model): array
    {
        $cfg = (array) config('embeddings.qdrant', []);
        $slug = trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', "{$provider}_{$model}"), '_');
        $collection = ($cfg['collection_prefix'] ?? 'emb').'_'.$slug;

        $http = Http::baseUrl(rtrim((string) ($cfg['url'] ?? 'http://127.0.0.1:6333'), '/'))
            ->timeout((int) ($cfg['timeout'] ?? 30))
            ->acceptJson();
        if (! empty($cfg['api_key'])) {
            $http = $http->withHeaders(['api-key' => $cfg['api_key']]);
        }

        $out = [];
        $offset = null;
        do {
            $body = [
                'filter' => ['must' => [
                    ['key' => 'team_id', 'match' => ['value' => $partition]],
                    ['key' => 'entity_type', 'match' => ['value' => PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM]],
                ]],
                'limit' => self::SCROLL_SEITE,
                'with_payload' => ['include' => ['entity_id']],
                'with_vector' => false,
            ];
            if ($offset !== null) {
                $body['offset'] = $offset;
            }
            $resp = $http->post("/collections/{$collection}/points/scroll", $body);
            if ($resp->status() === 404) {
                return $out;   // Collection fehlt noch → nichts vorhanden
            }
            if (! $resp->successful()) {
                // Nie raten: ohne belastbare Liste würde „alles fehlt" gelten und der Lauf alles neu embedden.
                throw new \RuntimeException("Qdrant-Scroll {$collection} fehlgeschlagen: HTTP {$resp->status()}");
            }
            foreach ((array) $resp->json('result.points') as $p) {
                $id = $p['payload']['entity_id'] ?? null;
                if ($id !== null) {
                    $out[(int) $id] = true;
                }
            }
            $offset = $resp->json('result.next_page_offset');
        } while ($offset !== null);

        return $out;
    }
}
