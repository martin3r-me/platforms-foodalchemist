<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Preisverlauf als Stufenlinie (Lieferantenartikel und Grundprodukt).
 *
 * Quellen: `foodalchemist_prices` (inkl. abgelöster Zeilen = Historie) UND `foodalchemist_price_history` (unser Stand vor jedem Necta-Update).
 * Aus beiden entsteht je Artikel eine Stufenlinie: Ein Preis gilt ab seinem Stand (status_valid_from, sonst change_date, creation_date,
 * created_at) bis zum nächsten Stand, der letzte bis zu seinem valid_to bzw. bis zum Ende des Zeitraums.
 *
 * Preis je Basiseinheit rechnet {@see PriceService::vergleichspreis} (€/kg, €/l, €/Stk), hier nicht neu erfunden. Der Rohpreis je Gebinde
 * bleibt je Stufe erhalten (Tooltip). Sichtbarkeit wie überall: Artikel über den Team-Scope, Preise über die Stamm-Freischaltung
 * ({@see PriceService::stammFilter}) und die Preis-Kette des Teams; je Artikel gilt die beste vorhandene Stufe (eigenes Team vor Vorfahren
 * vor Listenpreis), damit Mandantenpreise nie mit dem Listenpreis zu einer Linie gemischt werden.
 *
 * Aktion (status 2) läuft in Necta parallel zum Normalpreis und geht NICHT in die Linie ein (nur wenn ein Artikel keinen Normalpreis-Stand hat).
 *
 * Trend: lineare Regression (kleinste Quadrate) auf der STUFENLINIE, gewichtet nach der Zeit, die jeder Preis galt (exakt, als Integral
 * über die Stufen, gleichwertig zu täglichen Stützpunkten) — nicht über die Änderungspunkte. Viele kurze Aktionspreise verzerren so den Trend nicht.
 * Unter zwei Ständen im Zeitraum gibt es keine Trendlinie. Mehrere Linien in einer Basiseinheit mischt der Aufrufer nie: Artikel mit
 * fremder Basiseinheit werden weggelassen und gezählt.
 */
class PreisVerlaufService
{
    public const ZEITRAEUME = ['3m' => 3, '6m' => 6, '12m' => 12];

    public const TAGE_PRO_MONAT = 30.4375;

    public const TAGE_PRO_JAHR = 365.25;

    /** Einheiten-Rangfolge bei Gleichstand der Basiseinheit (kg vor l vor Stk). */
    private const EINHEIT_RANG = ['€/kg' => 0, '€/l' => 1, '€/Stk' => 2];

    private static ?bool $hatHistorie = null;

    public function __construct(private readonly PriceService $preise) {}

    /**
     * Zeitraum auflösen. `$zeitraum`: 3m | 6m | 12m | alles | frei (von/bis). Rückgabe `[von|null, bis]`; von null = ab dem ersten Stand.
     *
     * @return array{0:?Carbon,1:Carbon}
     */
    public function fenster(string $zeitraum = '12m', ?string $von = null, ?string $bis = null, ?Carbon $heute = null): array
    {
        $heute ??= now();
        if ($zeitraum === 'frei') {
            $b = $bis !== null && $bis !== '' ? Carbon::parse($bis)->endOfDay() : $heute->copy();
            $v = $von !== null && $von !== '' ? Carbon::parse($von)->startOfDay() : null;
            if ($v !== null && $v->gt($b)) {
                [$v, $b] = [$b->copy()->startOfDay(), $v->copy()->endOfDay()];
            }

            return [$v, $b];
        }
        if ($zeitraum === 'alles') {
            return [null, $heute->copy()];
        }
        $monate = self::ZEITRAEUME[$zeitraum] ?? 12;

        return [$heute->copy()->subMonths($monate)->startOfDay(), $heute->copy()];
    }

    /**
     * Verlauf für Artikel (gebündelt: Artikel, Preise, Historie je EINE Abfrage — kein N+1).
     *
     * @param  list<int>  $laIds
     * @return array{
     *   einheit:?string, von:?string, bis:string, weggelassen:int, ohne_verlauf:int,
     *   linien:list<array{la_id:int, lieferant:?string, artikel:?string, lead:bool, segmente:list<array<string,mixed>>, trend:?array<string,mixed>, staende:int}>
     * }
     */
    public function verlauf(?Team $team, array $laIds, ?Carbon $von, Carbon $bis, ?int $leadLaId = null): array
    {
        $laIds = array_values(array_unique(array_map('intval', $laIds)));
        $leer = ['einheit' => null, 'von' => $von?->toDateString(), 'bis' => $bis->toDateString(), 'weggelassen' => 0, 'ohne_verlauf' => 0, 'linien' => []];
        if ($laIds === []) {
            return $leer;
        }

        $itemQuery = FoodAlchemistSupplierItem::query()->with('supplier:id,name')->whereIn('id', $laIds);
        if ($team !== null) {
            $itemQuery->visibleToTeam($team);
        }
        $items = $itemQuery->get(['id', 'team_id', 'supplier_id', 'designation', 'marketing_name', 'qty', 'unit_code'])->keyBy('id');
        if ($items->isEmpty()) {
            return $leer;
        }

        $staende = $this->staendeJeArtikel($team, $items->all());

        // Linien je Artikel in Basiseinheit
        $linien = [];
        $ohneBasis = 0;
        $ohneVerlauf = 0;
        foreach ($items as $id => $item) {
            $liste = $staende[$id] ?? [];
            if ($liste === []) {
                $ohneVerlauf++;

                continue;
            }
            $punkte = [];
            $einheit = null;
            foreach ($liste as $s) {
                $v = $this->preise->vergleichspreis($item, $s['preis']);
                if ($v === null) {
                    $punkte = [];

                    break;
                }
                $einheit = $v['unit'];
                $punkte[] = $s + ['wert' => (float) $v['value']];
            }
            if ($punkte === []) {
                $ohneBasis++;

                continue;
            }
            $linien[$id] = ['item' => $item, 'einheit' => $einheit, 'punkte' => $punkte];
        }
        if ($linien === []) {
            return ['weggelassen' => $ohneBasis, 'ohne_verlauf' => $ohneVerlauf] + $leer;
        }

        // Eine Basiseinheit: die des Lead-LA, sonst die häufigste (Gleichstand: kg vor l vor Stk). Fremde Einheiten weglassen und zählen.
        $einheit = $this->gemeinsameEinheit($linien, $leadLaId);
        $weggelassen = $ohneBasis;
        $ausgabe = [];
        $erster = null;
        foreach ($linien as $id => $l) {
            if ($l['einheit'] !== $einheit) {
                $weggelassen++;

                continue;
            }
            $segmente = $this->segmente($l['punkte'], $bis);
            $erster = $segmente !== [] ? min($erster ?? $segmente[0]['von_ts'], $segmente[0]['von_ts']) : $erster;
            $ausgabe[$id] = ['item' => $l['item'], 'segmente' => $segmente];
        }
        $vonTs = $von?->getTimestamp() ?? $erster ?? $bis->getTimestamp();
        $bisTs = $bis->getTimestamp();

        $result = [];
        foreach ($ausgabe as $id => $a) {
            $zugeschnitten = $this->zuschneiden($a['segmente'], $vonTs, $bisTs);
            if ($zugeschnitten === []) {
                $ohneVerlauf++;

                continue;
            }
            $item = $a['item'];
            $result[] = [
                'la_id' => (int) $id,
                'lieferant' => $item->supplier?->name,
                'artikel' => $item->marketing_name ?: $item->designation,
                'lead' => $leadLaId !== null && (int) $id === $leadLaId,
                'segmente' => array_map(fn (array $s) => [
                    'von' => Carbon::createFromTimestamp($s['von_ts'])->toDateString(), 'bis' => Carbon::createFromTimestamp($s['bis_ts'])->toDateString(),
                    'von_ts' => $s['von_ts'], 'bis_ts' => $s['bis_ts'],
                    'wert' => round($s['wert'], 4), 'preis_gebinde' => $s['preis'], 'quelle' => $s['quelle'],
                ], $zugeschnitten),
                'staende' => count($zugeschnitten),
                'trend' => $this->trend($zugeschnitten),
            ];
        }
        usort($result, fn ($a, $b) => [! $a['lead'], mb_strtolower((string) $a['lieferant'])] <=> [! $b['lead'], mb_strtolower((string) $b['lieferant'])]);

        return [
            'einheit' => $einheit,
            'von' => Carbon::createFromTimestamp($vonTs)->toDateString(),
            'bis' => $bis->toDateString(),
            'weggelassen' => $weggelassen,
            'ohne_verlauf' => $ohneVerlauf,
            'linien' => $result,
        ];
    }

    /**
     * Alle Stände je Artikel aus Preiszeilen UND Historie, auf die beste sichtbare Stufe begrenzt, aufsteigend nach Stand.
     *
     * @param  array<int, FoodAlchemistSupplierItem>  $items
     * @return array<int, list<array{start:int, ende:?int, preis:float, quelle:string}>>
     */
    private function staendeJeArtikel(?Team $team, array $items): array
    {
        $ids = array_keys($items);
        $spalten = ['id', 'supplier_item_id', 'team_id', 'status', 'price', 'is_blocked', 'status_valid_from', 'valid_to', 'change_date', 'created_at'];

        $q = DB::table('foodalchemist_prices')->whereIn('supplier_item_id', $ids)->whereNull('deleted_at');
        $this->preise->stammFilter($q, 'foodalchemist_prices', $team);
        $zeilen = $q->get(array_merge($spalten, ['creation_date']))->map(fn ($r) => (object) ((array) $r + ['quelle' => 'preis']));

        if (self::$hatHistorie ??= Schema::hasTable('foodalchemist_price_history')) {
            $h = DB::table('foodalchemist_price_history')->whereIn('supplier_item_id', $ids)
                ->get(['id', 'supplier_item_id', 'team_id', 'status', 'price', 'is_blocked', 'status_valid_from', 'valid_to', 'change_date', 'created_at'])
                ->map(fn ($r) => (object) ((array) $r + ['creation_date' => null, 'quelle' => 'historie']));
            $zeilen = $zeilen->concat($h);
        }

        $kette = $team !== null ? PriceService::preisKette($team) : [];
        $tier = function (object $r, FoodAlchemistSupplierItem $item) use ($kette): ?int {
            $tid = $r->team_id === null ? null : (int) $r->team_id;
            if ($tid === null) {
                return count($kette) + 1;                          // global
            }
            $pos = array_search($tid, $kette, true);
            if ($pos !== false) {
                return (int) $pos;                                 // eigene Team-Kette, eigenes Team zuerst
            }

            return $tid === (int) $item->team_id ? count($kette) : null;   // Listenpreis des Besitzers; sonst nicht sichtbar
        };

        $nachArtikel = [];
        foreach ($zeilen as $r) {
            $item = $items[(int) $r->supplier_item_id] ?? null;
            if ($item === null || $r->price === null || (float) $r->price < 0 || $r->is_blocked || ! in_array((string) $r->status, ['0', '2', ''], true)) {
                continue;
            }
            $t = $tier($r, $item);
            if ($t === null) {
                continue;
            }
            $start = $r->status_valid_from ?? $r->change_date ?? $r->creation_date ?? $r->created_at;
            if ($start === null) {
                continue;
            }
            $nachArtikel[(int) $r->supplier_item_id][] = [
                'status' => (string) $r->status, 'tier' => $t, 'start' => Carbon::parse($start)->getTimestamp(), 'ende' => $r->valid_to !== null ? Carbon::parse($r->valid_to)->getTimestamp() : null,
                'preis' => (float) $r->price, 'quelle' => (string) $r->quelle, 'id' => (int) $r->id,
            ];
        }

        $aus = [];
        foreach ($nachArtikel as $laId => $liste) {
            $beste = min(array_column($liste, 'tier'));
            $liste = array_values(array_filter($liste, fn ($s) => $s['tier'] === $beste));
            // Aktion (status '2') gilt PARALLEL zum Normalpreis (gleicher Tag, zwei offene Stände): in einer Stufenlinie gäbe das Sprünge.
            // Die Linie (und der Trend) nimmt den Normalpreis; nur ohne jeden Normalpreis-Stand dienen die Aktionsstände als Linie.
            $normal = array_values(array_filter($liste, fn ($s) => $s['status'] !== '2'));
            $liste = $normal !== [] ? $normal : $liste;
            // gleicher Stand: die Historie (alter Stand) vor der aktuellen Zeile, damit der neuere Wert gewinnt
            usort($liste, fn ($a, $b) => [$a['start'], $a['quelle'] === 'historie' ? 0 : 1, $a['id']] <=> [$b['start'], $b['quelle'] === 'historie' ? 0 : 1, $b['id']]);
            $ohneDoppel = [];
            foreach ($liste as $s) {
                $letzte = end($ohneDoppel);
                if ($letzte !== false && $letzte['start'] === $s['start'] && abs($letzte['preis'] - $s['preis']) < 0.00005) {
                    continue;   // derselbe Stand aus Zeile und Historie
                }
                $ohneDoppel[] = $s;
            }
            $aus[$laId] = $ohneDoppel;
        }

        return $aus;
    }

    /**
     * Stufen mit Ende: jeder Stand gilt bis zum nächsten, der letzte bis zu seinem valid_to bzw. bis `$bis`.
     *
     * @param  list<array<string,mixed>>  $punkte
     * @return list<array<string,mixed>>
     */
    private function segmente(array $punkte, Carbon $bis): array
    {
        $aus = [];
        $n = count($punkte);
        foreach ($punkte as $i => $p) {
            $naechster = $punkte[$i + 1]['start'] ?? null;
            $ende = $naechster ?? ($p['ende'] ?? $bis->getTimestamp());
            if ($p['ende'] !== null && ($naechster === null || $p['ende'] < $naechster)) {
                $ende = $p['ende'];   // eingestellt: die Linie endet dort (Lücke bis zum nächsten Stand)
            }
            if ($ende <= $p['start']) {
                continue;
            }
            $aus[] = ['von_ts' => $p['start'], 'bis_ts' => $ende, 'wert' => $p['wert'], 'preis' => $p['preis'], 'quelle' => $p['quelle']];
        }

        return $aus;
    }

    /**
     * Auf das Fenster zuschneiden. Der Stand, der am Fensteranfang gilt, wird mitgenommen (Linie beginnt nicht im Leeren).
     *
     * @param  list<array<string,mixed>>  $segmente
     * @return list<array<string,mixed>>
     */
    private function zuschneiden(array $segmente, int $vonTs, int $bisTs): array
    {
        $aus = [];
        foreach ($segmente as $s) {
            if ($s['bis_ts'] <= $vonTs || $s['von_ts'] >= $bisTs) {
                continue;
            }
            $s['von_ts'] = max($s['von_ts'], $vonTs);
            $s['bis_ts'] = min($s['bis_ts'], $bisTs);
            if ($s['bis_ts'] > $s['von_ts']) {
                $aus[] = $s;
            }
        }

        return $aus;
    }

    /**
     * Lineare Regression (kleinste Quadrate) auf der Stufenlinie, zeitgewichtet (exakt: Integrale über die Stufen).
     * Unter zwei Ständen: null. Steigung je Monat in €/Basiseinheit und je Jahr in % (bezogen auf den zeitgewichteten Mittelwert).
     *
     * @param  list<array<string,mixed>>  $segmente
     * @return array{steigung_pro_monat:float, prozent_pro_jahr:?float, mittelwert:float, start_wert:float, ende_wert:float, von_ts:int, bis_ts:int}|null
     */
    public function trend(array $segmente): ?array
    {
        if (count($segmente) < 2) {
            return null;
        }
        $t0 = $segmente[0]['von_ts'];
        $t1 = end($segmente)['bis_ts'];
        $tage = fn (int $ts) => ($ts - $t0) / 86400;
        $T = $s1 = $s2 = $sy = $sty = 0.0;
        foreach ($segmente as $s) {
            $a = $tage($s['von_ts']);
            $b = $tage($s['bis_ts']);
            $v = $s['wert'];
            $T += $b - $a;
            $s1 += ($b * $b - $a * $a) / 2;
            $s2 += ($b ** 3 - $a ** 3) / 3;
            $sy += $v * ($b - $a);
            $sty += $v * ($b * $b - $a * $a) / 2;
        }
        $nenner = $T * $s2 - $s1 * $s1;
        if ($T <= 0 || abs($nenner) < 1e-9) {
            return null;
        }
        $steigung = ($T * $sty - $s1 * $sy) / $nenner;          // € je Tag
        $achse = ($sy - $steigung * $s1) / $T;
        $mittel = $sy / $T;
        $gesamt = $tage($t1);

        return [
            'steigung_pro_monat' => round($steigung * self::TAGE_PRO_MONAT, 4),
            'prozent_pro_jahr' => $mittel > 0 ? round($steigung * self::TAGE_PRO_JAHR / $mittel * 100, 2) : null,
            'mittelwert' => round($mittel, 4),
            'start_wert' => round($achse, 4),
            'ende_wert' => round($achse + $steigung * $gesamt, 4),
            'von_ts' => $t0,
            'bis_ts' => $t1,
        ];
    }

    /**
     * @param  array<int, array{item:FoodAlchemistSupplierItem, einheit:?string, punkte:list<array>}>  $linien
     */
    private function gemeinsameEinheit(array $linien, ?int $leadLaId): ?string
    {
        if ($leadLaId !== null && isset($linien[$leadLaId])) {
            return $linien[$leadLaId]['einheit'];
        }
        $zaehler = array_count_values(array_map(fn ($l) => (string) $l['einheit'], $linien));
        uksort($zaehler, fn ($a, $b) => [-$zaehler[$a], self::EINHEIT_RANG[$a] ?? 9] <=> [-$zaehler[$b], self::EINHEIT_RANG[$b] ?? 9]);

        return (string) array_key_first($zaehler);
    }

    /**
     * Verlauf eines Grundprodukts: EINE Linie je Lieferantenartikel, Lead-LA hervorgehoben. Die Artikel kommen gebündelt aus der Struktur-Tabelle.
     *
     * @return array<string,mixed>  wie {@see verlauf()}, dazu `la_gesamt` (Zahl der verknüpften Artikel) und `lead_la_id`
     */
    public function verlaufFuerGp(?Team $team, \Platform\FoodAlchemist\Models\FoodAlchemistGp $gp, ?Carbon $von, Carbon $bis): array
    {
        $laIds = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::query()->where('gp_id', $gp->id)
            ->pluck('supplier_item_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        $lead = $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null;

        return $this->verlauf($team, $laIds, $von, $bis, $lead) + ['la_gesamt' => count($laIds), 'lead_la_id' => $lead];
    }

    /** Für Tests: Zwischenspeicher der Tabellen-Prüfung zurücksetzen. */
    public static function flush(): void
    {
        self::$hatHistorie = null;
    }
}
