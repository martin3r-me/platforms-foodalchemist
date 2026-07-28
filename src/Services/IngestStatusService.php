<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 13 · S3 — die Lese-Seite des Katalog-Ingests: „ist der Import angekommen,
 * und was fehlt noch?". Read-only, kein Provider-Call, keine Schreibwege.
 *
 * Drei Blöcke, wie in der Etappen-Zeile:
 *  1. **Läufe** — die letzten `ingest`-Läufe aus `foodalchemist_bulk_runs`.
 *  2. **Lücken** — sichtbare Artikel ohne aktiven Preis / ohne GP-Struktur /
 *     ohne Allergen-Aussage / ohne Nährwerte.
 *  3. **Preis-Deltas** — Artikel mit frisch geschriebener Preis-Zeile, Delta
 *     gegen den Vorgänger (dieselbe Zahl, die R2.1 alarmiert).
 *
 * **Der Lauf benennt seinen Gegenstand (V-047, seit 22·H3a):** `bulk_runs.context`
 * trägt Datei, Lieferant und Auslöse-Weg; „welche Datei ist zuletzt für Hanos
 * gelaufen?" ist damit beantwortbar statt nur zählbar. Für Läufe von VOR H3a bleibt
 * der Kontext leer — dort steht `beschreibung: null` statt einer erfundenen Angabe,
 * und die Lücken-/Delta-Blöcke beantworten „was ist angekommen?" wie bisher aus den
 * Zieldaten.
 *
 * **Lücke = keine Aussage, nicht „keine Zeile".** Eine Allergen-/Nährwert-Zeile,
 * in der alle Werte NULL sind, ist keine Angabe — sie zählt als Lücke. Sonst
 * meldete das Tool nach einem Import, der nur den Stamm brachte, fälschlich grün
 * (GL-01: im Zweifel die konservative Seite).
 */
final class IngestStatusService
{
    /**
     * Lauf-Art in der geteilten `bulk_runs`-Tabelle (Schreiber: {@see FileArticleImportService::starteRun}).
     * Der Wert kommt seit 22·H3a aus {@see BulkRunType} — die Konstante bleibt als
     * benannter Einstieg für die Leser stehen, ist aber keine zweite Wahrheit mehr.
     */
    public const LAUF_TYP = BulkRunType::Ingest->value;

    public const MAX_LAEUFE = 20;

    public const MAX_BEISPIELE = 50;

    /**
     * Obergrenze für die Delta-Ermittlung. Wie `MAX_RECOMPUTE` in S1b: lieber ein
     * benannter Schnitt als eine unbegrenzte Query über einen Quartals-Katalog —
     * `abgeschnitten=true` sagt es dem Aufrufer.
     */
    public const MAX_DELTA_ITEMS = 500;

    /** Nährwert-Kernwerte = derselbe Umfang, den `setNutrition` schreibt (eine Wahrheit). */
    public const NAEHRWERT_SPALTEN = SupplierItemService::NAEHRWERT_FELDER;

    public function __construct(private PriceService $preise)
    {
    }

    /**
     * @return array{
     *   lieferant: ?array{id: int, name: string},
     *   artikel_sichtbar: int,
     *   laeufe: list<array<string, mixed>>,
     *   laeufe_hinweis: string,
     *   luecken: array<string, array{label: string, anzahl: int, beispiele: list<array<string, mixed>>}>,
     *   preis_deltas: array{seit: string, tage: int, bewegte_artikel: int, gestiegen: int, gefallen: int, unplausibel: int, abgeschnitten: bool, top: list<array<string, mixed>>}
     * }
     */
    public function status(Team $team, ?int $supplierId = null, int $tage = 30, int $beispiele = 10, int $laeufe = 10): array
    {
        $supplier = $this->lieferant($team, $supplierId);
        $tage = max(1, min(365, $tage));
        $beispiele = max(0, min(self::MAX_BEISPIELE, $beispiele));
        $laeufe = max(1, min(self::MAX_LAEUFE, $laeufe));

        return [
            'lieferant' => $supplier ? ['id' => (int) $supplier->id, 'name' => (string) $supplier->name] : null,
            'artikel_sichtbar' => $this->basis($team, $supplierId)->count(),
            'laeufe' => $this->laeufe($team, $laeufe),
            'laeufe_hinweis' => 'Jeder Lauf nennt seit 22·H3a seinen Gegenstand (`datei`, `lieferant`, '
                . '`ausgeloest_ueber`). Bei älteren Läufen sind diese Felder NULL — der Kontext wurde damals '
                . 'nicht mitgeschrieben und wird nicht nachträglich erraten. Was tatsächlich in den Daten '
                . 'angekommen ist, sagen unabhängig davon die Blöcke `luecken` und `preis_deltas`.',
            'luecken' => $this->luecken($team, $supplierId, $beispiele),
            'preis_deltas' => $this->preisDeltas($team, $supplierId, $tage, $beispiele),
        ];
    }

    /** Lieferant auf die Team-Kette prüfen (D1) — unbekannt ist ein Fehler, kein leeres Ergebnis. */
    private function lieferant(Team $team, ?int $supplierId): ?FoodAlchemistSupplier
    {
        if ($supplierId === null) {
            return null;
        }
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->first();
        if (! $supplier) {
            throw new \RuntimeException("Lieferant #{$supplierId} ist in der Team-Kette nicht sichtbar.");
        }

        return $supplier;
    }

    /** Frische Basis-Query je Aufruf — `visibleToTeam` liefert einen Builder, der nicht geteilt werden darf. */
    private function basis(Team $team, ?int $supplierId): Builder
    {
        $q = FoodAlchemistSupplierItem::visibleToTeam($team);

        return $supplierId !== null ? $q->where('supplier_id', $supplierId) : $q;
    }

    /**
     * Die letzten Ingest-Läufe. Team-**strikt** (nicht die Kette): ein Lauf ist ein
     * Vorgang des Teams, das ihn gestartet hat, kein vererbbarer Katalog-Datensatz —
     * dieselbe Lesart wie {@see BulkEnrichService::runStatus}.
     *
     * @return list<array<string, mixed>>
     */
    private function laeufe(Team $team, int $limit): array
    {
        return FoodAlchemistBulkRun::query()
            ->where('team_id', $team->id)
            ->where('type', self::LAUF_TYP)
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (FoodAlchemistBulkRun $r) => [
                'run_id' => (int) $r->id,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'zeilen' => (int) $r->total,
                'verarbeitet' => (int) $r->done,
                'fehler' => (int) $r->failed,
                'gestartet' => (string) $r->created_at,
                'beendet' => $r->status === BulkRunStatus::Running ? null : (string) $r->updated_at,
                // V-047: der Gegenstand. NULL bei Läufen von vor 22·H3a — „unbekannt"
                // ist die ehrliche Antwort, ein aus dem Datum geratener Dateiname wäre
                // eine erfundene.
                'datei' => $r->context['datei'] ?? null,
                'lieferant' => $r->context['lieferant'] ?? null,
                'lieferant_id' => isset($r->context['supplier_id']) ? (int) $r->context['supplier_id'] : null,
                'ausgeloest_ueber' => $r->context['quelle'] ?? null,
            ])->all();
    }

    /**
     * Die vier Lücken-Arten. Jede ist ein `whereDoesntHave` auf der Beziehung —
     * nicht ein nachgebautes LEFT JOIN, damit die Soft-Delete-Regel der Kind-Tabelle
     * mitläuft.
     *
     * @return array<string, array{label: string, anzahl: int, beispiele: list<array<string, mixed>>}>
     */
    private function luecken(Team $team, ?int $supplierId, int $beispiele): array
    {
        $arten = [
            'ohne_preis' => [
                'Kein aktiver EK — der Artikel steht im Katalog, kostet aber nichts',
                fn (Builder $q) => $q->whereDoesntHave('prices', fn ($p) => $this->preise->scopeAktiv($p)),
            ],
            'ohne_gp' => [
                'Keine GP-Struktur — der Preis erreicht kein Rezept (die E4-Kette endet hier)',
                fn (Builder $q) => $q->whereDoesntHave('structure', fn ($s) => $s->whereNotNull('gp_id')),
            ],
            'ohne_allergene' => [
                'Keine Allergen-Aussage (Zeile fehlt oder alle 14 Werte leer)',
                fn (Builder $q) => $q->whereDoesntHave('allergens', fn ($a) => $this->irgendeinWert(
                    $a, array_map(fn ($k) => "allergen_{$k}", array_keys(FoodAlchemistItemAllergen::ALLERGENE))
                )),
            ],
            'ohne_naehrwerte' => [
                'Keine Nährwerte (Zeile fehlt oder alle Kernwerte leer)',
                fn (Builder $q) => $q->whereDoesntHave('nutritionals', fn ($n) => $this->irgendeinWert(
                    $n, array_keys(self::NAEHRWERT_SPALTEN)
                )),
            ],
        ];

        $out = [];
        foreach ($arten as $key => [$label, $filter]) {
            $out[$key] = [
                'label' => $label,
                'anzahl' => $filter($this->basis($team, $supplierId))->count(),
                'beispiele' => $beispiele === 0 ? [] : $filter($this->basis($team, $supplierId))
                    ->with('supplier:id,name')->orderBy('id')->limit($beispiele)->get()
                    ->map(fn ($i) => [
                        'item_id' => (int) $i->id,
                        'article_number' => $i->article_number,
                        'designation' => $i->designation,
                        'supplier' => $i->supplier?->name,
                    ])->all(),
            ];
        }

        return $out;
    }

    /** „mindestens eine dieser Spalten ist gefüllt" — geklammert, damit das OR nicht ausbricht. */
    private function irgendeinWert(Builder $q, array $spalten): Builder
    {
        return $q->where(function (Builder $w) use ($spalten) {
            foreach ($spalten as $spalte) {
                $w->orWhereNotNull($spalte);
            }
        });
    }

    /**
     * Artikel mit frisch geschriebener Preis-Zeile im Zeitfenster. Das Delta ist
     * bewusst *nicht* „Bewegung innerhalb des Fensters", sondern aktuell gegen den
     * Vorgänger — dieselbe Zahl, die `preisSprungMargeImpact` bewertet (eine
     * Preis-Wahrheit, kein zweiter Trend-Begriff).
     *
     * Der Schreiber ist nicht unterscheidbar: eine Zeile aus dem Datei-Import sieht
     * aus wie eine aus dem Artikel-Modal. Das ist eine Aussage über den Katalog, nicht
     * über den Importer.
     *
     * @return array{seit: string, tage: int, bewegte_artikel: int, gestiegen: int, gefallen: int, unplausibel: int, abgeschnitten: bool, top: list<array<string, mixed>>}
     */
    private function preisDeltas(Team $team, ?int $supplierId, int $tage, int $limit): array
    {
        $seit = now()->subDays($tage);

        $q = DB::table('foodalchemist_prices as p')
            ->join('foodalchemist_supplier_items as i', 'i.id', '=', 'p.supplier_item_id')
            ->whereNull('p.deleted_at')->whereNull('i.deleted_at')
            ->where('p.created_at', '>=', $seit);
        TeamScope::applyVisible($q, 'i.team_id', $team);
        if ($supplierId !== null) {
            $q->where('i.supplier_id', $supplierId);
        }
        $itemIds = $q->distinct()->orderByDesc('p.supplier_item_id')
            ->limit(self::MAX_DELTA_ITEMS + 1)->pluck('p.supplier_item_id')
            ->map(fn ($v) => (int) $v)->all();

        $abgeschnitten = count($itemIds) > self::MAX_DELTA_ITEMS;
        $itemIds = array_slice($itemIds, 0, self::MAX_DELTA_ITEMS);

        $trend = $this->preise->preisTrendBulk($itemIds);
        $out = [
            'seit' => $seit->toDateString(),
            'tage' => $tage,
            'bewegte_artikel' => count($itemIds),
            'gestiegen' => 0, 'gefallen' => 0, 'unplausibel' => 0,
            'abgeschnitten' => $abgeschnitten,
            'top' => [],
        ];
        if ($trend === []) {
            return $out;   // nur Erst-Preise im Fenster: bewegt, aber ohne Vorgänger kein Delta
        }

        foreach ($trend as $t) {
            if (! $t['plausibel']) {
                $out['unplausibel']++;
            }
            if ($t['delta_pct'] > 0) {
                $out['gestiegen']++;
            } elseif ($t['delta_pct'] < 0) {
                $out['gefallen']++;
            }
        }

        uasort($trend, fn ($a, $b) => abs($b['delta_pct']) <=> abs($a['delta_pct']));
        $top = array_slice($trend, 0, $limit, true);
        $namen = FoodAlchemistSupplierItem::whereIn('id', array_keys($top))
            ->with('supplier:id,name')->get()->keyBy('id');
        foreach ($top as $id => $t) {
            $item = $namen->get($id);
            $out['top'][] = [
                'item_id' => (int) $id,
                'article_number' => $item?->article_number,
                'designation' => $item?->designation,
                'supplier' => $item?->supplier?->name,
                'aktuell' => $t['aktuell'],
                'vorher' => $t['vorher'],
                'delta_pct' => $t['delta_pct'],
                'plausibel' => $t['plausibel'],
            ];
        }

        return $out;
    }
}
