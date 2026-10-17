<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\PriceCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * M2-04/05 / GL-11: DIE eine Stelle für Preislogik — aktiver Preis (§3.3),
 * Kategorisierung (§3.1, via PriceCategory), Vergleichspreis-Normalisierung (§3.2).
 * Konsumenten: GpService::lasForGp, SupplierItemService (EK-Spalte), ItemModal,
 * später GL-02-Recompute + GL-03-Lead-Wahl.
 */
class PriceService
{
    /** Aktiv-Bedingung (I3) index-fähig ausformuliert: price >= 0 AND NOT NULL AND status IN ('0','2'). */
    public function scopeAktiv(Builder $query): Builder
    {
        return $query
            ->where('is_blocked', false)
            ->whereNotNull('price')
            ->where('price', '>=', 0)
            ->whereIn('status', ['0', '2']);
    }

    /**
     * Spec 82 §3 Stufe 3a: welche Preiszeilen ein Team sieht und in welcher Rangfolge.
     *
     * - MIT Team: eigenes Team → Vorfahren (Kettenreihenfolge) → global (team_id NULL).
     *   Die Team-Stufe schlägt das Alter: ein älterer Preis des eigenen Teams gewinnt gegen
     *   einen neueren Listenpreis. Erst innerhalb einer Stufe gilt die Aktiv-Ordnung.
     * - OHNE Team: nur der Listenpreis = team_id NULL oder team_id = Besitzer-Team des Artikels.
     *   So rutscht kein Mandantenpreis in globale Werte (globales Rezept, Lead-Wahl, Reports).
     *   Bis Spec 82 galt immer price.team_id = item.team_id (gemessen 13.10.: 221.592/221.592),
     *   deshalb ist das ohne Mandantenpreise bitgleich zum alten Verhalten.
     *
     * Hängt Bedingung + Rang-Ordnung an; die Aktiv-Ordnung (orderAktiv) kommt danach.
     */
    public function preisSichtFuer(Builder $query, ?Team $team): Builder
    {
        $col = 'foodalchemist_prices.team_id';

        if ($team === null) {
            return $this->nurListenpreis($query)                 // inkl. stammFilter (ohne Team: kein Opt-in)
                ->orderByRaw("{$col} IS NULL ASC"); // Besitzer-Listenpreis vor globalem
        }

        $this->stammFilter($query, 'foodalchemist_prices', $team);   // Spec 82 §12 A3: Klasse folgt dem Artikel

        $kette = self::preisKette($team);                          // Leitplanke b: endet an der Kundengrenze
        $sichtbar = FoodAlchemistPrice::teamAncestryIds($team);   // Artikel-Sichtbarkeit (Scope-Kette)
        $besitzer = self::BESITZER_SQL;

        // Erlaubt sind:
        //  1. global (team_id NULL) — rankt zuletzt;
        //  2. Teampreise der Kette bis zur Kundengrenze, aber nur DATUMS-AKTIV (Leitplanke a):
        //     ein abgelaufener Teampreis schlägt keinen Listenpreis;
        //  3. der Listenpreis des Besitzer-Teams — nur wenn der Betrachter den Artikel sehen darf
        //     (Besitzer in der Scope-Kette, Leitplanke c), sonst sickerte ein fremder Mandant durch.
        //     Für den Listenpreis gilt die alte Aktiv-Regel unverändert (auch gestempelte Zeilen).
        $inKette = implode(',', array_fill(0, count($kette), '?'));
        $inSicht = implode(',', array_fill(0, count($sichtbar), '?'));
        $query->where(function (Builder $w) use ($col, $kette, $sichtbar, $besitzer, $inKette, $inSicht) {
            $w->whereNull($col)
                ->orWhereRaw("({$col} IN ({$inKette}) AND ({$col} = {$besitzer} OR foodalchemist_prices.valid_to IS NULL OR foodalchemist_prices.valid_to >= ?))",
                    [...$kette, now()->toDateTimeString()])
                ->orWhereRaw("({$col} IN ({$inSicht}) AND {$col} = {$besitzer})", $sichtbar);
        });

        // Rang: Kette in Reihenfolge (eigenes Team zuerst), dann Besitzer-Listenpreis jenseits
        // der Kette, dann global.
        $faelle = implode(' ', array_fill(0, count($kette), 'WHEN ? THEN ?'));
        $bindings = [];
        foreach ($kette as $rang => $teamId) {
            array_push($bindings, $teamId, $rang);
        }

        return $query->orderByRaw(
            "CASE WHEN {$col} IS NULL THEN ? ELSE (CASE {$col} {$faelle} ELSE ? END) END",
            [count($kette) + 1, ...$bindings, count($kette)],
        );
    }

    /** Korrelierte Besitzer-Spalte des Artikels einer Preiszeile (für Listenpreis-Regeln). */
    private const BESITZER_SQL = '(SELECT si_lp.team_id FROM foodalchemist_supplier_items si_lp WHERE si_lp.id = foodalchemist_prices.supplier_item_id)';

    /**
     * Spec 82 3a, Leitplanke b: Preis-Kette = Team-Kette aufwärts, endet am Kunden-Hauptteam
     * (dieselbe Grenze wie {@see FaRechte::kundenHauptTeam}: das Team direkt unter dem
     * Master-Team; das Master-Team selbst sieht nur sich). Nie das Master-Team oder darüber.
     *
     * @return list<int>
     */
    public static function preisKette(Team $team): array
    {
        $kette = FoodAlchemistPrice::teamAncestryIds($team);
        $master = MasterTeam::id();   // Spec 82 §13 B4: Einstellung vor ENV, dieselbe Quelle wie FaRechte
        if ($master === null) {
            return $kette;
        }
        $pos = array_search($master, $kette, true);

        return match (true) {
            $pos === false => $kette,
            $pos === 0 => [$kette[0]],
            default => array_slice($kette, 0, $pos),
        };
    }

    /**
     * Spec 82 3a: nur Listenpreis-Zeilen (team_id NULL oder = Besitzer-Team des Artikels) —
     * die Regel „ohne Team" für Stellen, die Preise roh oder als EXISTS lesen und in globale
     * Zahlen/Reports einfließen (Lead-Ampel, Favoriten, Money-Truth …). $alias = Tabellenname
     * bzw. Alias der Preis-Tabelle in der Query. Geht für Eloquent- und Query-Builder.
     *
     * @template T of Builder|\Illuminate\Database\Query\Builder
     * @param  T  $query
     * @return T
     */
    public function nurListenpreis(Builder|\Illuminate\Database\Query\Builder $query, string $alias = 'foodalchemist_prices', ?Team $team = null): Builder|\Illuminate\Database\Query\Builder
    {
        $besitzer = "(SELECT si_lp.team_id FROM foodalchemist_supplier_items si_lp WHERE si_lp.id = {$alias}.supplier_item_id)";
        $this->stammFilter($query, $alias, $team);

        return $query->where(fn ($w) => $w->whereNull("{$alias}.team_id")->orWhereRaw("{$alias}.team_id = {$besitzer}"));
    }

    /**
     * Spec 82 §12 A3 (Folge-PR zu 3a): Preise hängen am Artikel. Ist der Artikel für das Team
     * unsichtbar (globaler stamm_paket-Artikel ohne Opt-in), ist es sein Preis auch, ebenso ein
     * Teampreis an diesem Artikel. Die Regel selbst kommt aus {@see StammPaket::begrenzen} (eine
     * Quelle). Ohne Team gibt es kein Opt-in, also keine stamm_paket-Preise. Der Stamm trägt laut
     * Entscheidung 13.10. ohnehin keine Preise, das hier ist die Sicherung.
     *
     * Korreliertes EXISTS (PK-Zugriff je Preiszeile) statt whereIn über alle Artikel.
     * Mit Opt-in bzw. als Master: kein Filter.
     */
    public function stammFilter(Builder|\Illuminate\Database\Query\Builder $query, string $alias, ?Team $team): Builder|\Illuminate\Database\Query\Builder
    {
        if (StammPaket::sieheAlleLieferanten($team)) {
            return $query;
        }

        // Mit gebuchtem Paket: eigene Preise eines Teams (team_id gesetzt) sind nie Stamm und verschwinden nicht, auch wenn der Lieferant nicht freigeschaltet ist.
        // Ohne Paket gilt die alte Regel (Preis folgt dem Artikel).
        $eigene = StammPaket::istFreigeschaltet($team);

        return $query->where(fn ($w) => ($eigene ? $w->whereNotNull("{$alias}.team_id") : $w)->orWhereExists(function ($sub) use ($alias, $team) {
            $sub->selectRaw('1')->from('foodalchemist_supplier_items')
                ->whereColumn('foodalchemist_supplier_items.id', "{$alias}.supplier_item_id");
            StammPaket::begrenzen($sub, 'foodalchemist_supplier_items', $team);
        }));
    }

    /** Aktiv-Ordnung §3.3 innerhalb einer Stufe: unbefristet zuerst, dann valid_to DESC, Tiebreak id DESC. */
    private function orderAktiv(Builder $query): Builder
    {
        return $query
            ->orderByRaw('foodalchemist_prices.valid_to IS NULL DESC') // NULL = unbefristet = AKTUELL gültig, rankt VOR datierten (Append-only: alte Zeile wird gestempelt) — engine-agnostisch (07 §7); Realdaten: 108.310/111.543 aktive Zeilen sind NULL
            ->orderByDesc('foodalchemist_prices.valid_to')
            ->orderByDesc('foodalchemist_prices.id');
    }

    /** §3.3: aktiver Preis = NEUESTE aktive Zeile der höchsten sichtbaren Team-Stufe (Spec 82 3a, siehe preisSichtFuer). */
    public function activeFor(int $supplierItemId, ?Team $team = null): ?FoodAlchemistPrice
    {
        $q = $this->scopeAktiv(FoodAlchemistPrice::query())
            ->where('foodalchemist_prices.supplier_item_id', $supplierItemId);

        return $this->orderAktiv($this->preisSichtFuer($q, $team))->first();
    }

    /** Subquery für Listen-Spalten (EK je Artikel) — gleiche Regel wie activeFor(). $outerColumn = Spalte der äußeren Query (bei Alias z. B. 'i.id'). */
    public function activePriceSubquery(string $outerColumn = 'foodalchemist_supplier_items.id', ?Team $team = null): Builder
    {
        $q = $this->scopeAktiv(FoodAlchemistPrice::query())
            ->select('foodalchemist_prices.price')
            ->whereColumn('foodalchemist_prices.supplier_item_id', $outerColumn);

        return $this->orderAktiv($this->preisSichtFuer($q, $team))->limit(1);
    }

    /**
     * Preis-Trend je Item: aktuell vs. vorheriger aktiver Preis (gleiche Aktiv-Ordnung
     * wie activeFor, Offset 1 — Append-only stempelt den Vorgänger mit valid_to). Bulk
     * gegen N+1. Nur Items MIT Vorgänger und positivem Vorpreis (Delta% berechenbar).
     *
     * `plausibel=false`, wenn Vorpreis/aktuell um Faktor ≥10 auseinanderliegen — dann
     * ist der Vorpreis fast sicher ein Platzhalter/Dummy (z.B. 999). Der Aufrufer zeigt
     * dann ein ⚠-Flag statt eines irreführenden Delta-% (Hybrid: Datenmüll sichtbar,
     * Anzeige sauber).
     *
     * @param array<int> $itemIds
     * @return array<int, array{aktuell: float, vorher: float, delta_pct: float, plausibel: bool}>
     */
    public function preisTrendBulk(array $itemIds, ?Team $team = null): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }

        $q = $this->scopeAktiv(FoodAlchemistPrice::query())
            ->whereIn('foodalchemist_prices.supplier_item_id', $itemIds)
            ->orderBy('foodalchemist_prices.supplier_item_id');
        $rows = $this->orderAktiv($this->preisSichtFuer($q, $team))
            ->get(['foodalchemist_prices.supplier_item_id', 'foodalchemist_prices.price', 'foodalchemist_prices.team_id']);

        // Spec 82 3a: Trend nur innerhalb der Team-Stufe, die den aktuellen Preis stellt —
        // sonst wäre „Mandantenpreis vs. Listenpreis" ein Scheinsprung.
        $proItem = [];
        $stufe = [];
        foreach ($rows as $r) {
            $id = (int) $r->supplier_item_id;
            $stufe[$id] ??= $r->team_id;
            if ($r->team_id === $stufe[$id]) {
                $proItem[$id][] = (float) $r->price;
            }
        }

        $out = [];
        foreach ($proItem as $id => $preise) {
            if (count($preise) >= 2 && $preise[1] > 0 && $preise[0] > 0) {
                $faktor = max($preise[0], $preise[1]) / min($preise[0], $preise[1]);
                $out[$id] = [
                    'aktuell' => $preise[0],
                    'vorher' => $preise[1],
                    'delta_pct' => round(($preise[0] - $preise[1]) / $preise[1] * 100, 1),
                    'plausibel' => $faktor < 10,       // Faktor ≥10 ⇒ Vorpreis fast sicher Platzhalter
                ];
            }
        }

        return $out;
    }

    /**
     * Historie eines LA (alle für das Team sichtbaren Zeilen, Rang-Stufe zuerst, darin neueste
     * zuerst) inkl. abgeleiteter Kategorie (I2). Ohne Team: nur Listenpreis-Zeilen (Spec 82 3a).
     */
    public function historyFor(int $supplierItemId, ?Team $team = null): Collection
    {
        $q = FoodAlchemistPrice::query()->where('foodalchemist_prices.supplier_item_id', $supplierItemId);

        return $this->orderAktiv($this->preisSichtFuer($q, $team))
            ->get()
            ->each(fn ($p) => $p->setAttribute(
                'category',
                PriceCategory::fuer($p->price !== null ? (float) $p->price : null, $p->status),
            ));
    }

    /**
     * M2-08 / P-6: „+ Neuer Preis schließt Vorgänger" — Append-only (GL-11 §3.3 Historie):
     * der bisher aktive Preis bekommt valid_to = jetzt, die neue Zeile ist unbefristet (NULL)
     * und damit sofort aktiv. Nur Besitzer-Team des LA (D1) — das ist der LISTENPREIS.
     * Mandantenpreise an fremden/globalen Artikeln: {@see createTeamPrice()}.
     */
    public function createFor(Team $team, FoodAlchemistSupplierItem $item, float $preis, string $status = '0'): FoodAlchemistPrice
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel: Preise pflegt nur das Besitzer-Team.');
        }

        return $this->schreibeTeamZeile($team, $item, $preis, $status);
    }

    /**
     * Spec 82 §3 Stufe 3a: Preis eines Teams (Mandant) an einem Artikel, den es sieht, aber
     * nicht besitzen muss (Stamm-/Katalogartikel). Die Zeile trägt IMMER die team_id des
     * schreibenden Teams — nie eine fremde. Der Listenpreis des Besitzers bleibt unberührt;
     * geschlossen wird nur der eigene Vorgänger desselben Teams. Ist das Team selbst
     * Besitzer, ist es der Listenpreis (= createFor). Aufrufer u. a. Preislisten-Import (§3).
     */
    public function createTeamPrice(Team $team, FoodAlchemistSupplierItem $item, float $preis, string $status = '0'): FoodAlchemistPrice
    {
        if ($item->isOwnedBy($team)) {
            return $this->createFor($team, $item, $preis, $status);
        }
        if (! self::darfTeampreisSchreiben($team)) {
            throw new \RuntimeException('Unter-Teams erben die Preise ihres Oberteams — eigene Preise je Unter-Team gibt es nicht (Spec 82 §3).');
        }
        if (! FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($item->id)->exists()) {
            throw new \RuntimeException('Artikel ist für dieses Team nicht sichtbar — kein Preis möglich.');
        }

        return $this->schreibeTeamZeile($team, $item, $preis, $status);
    }

    /**
     * Spec 82 §3 / Leitplanke e: Teampreise schreibt nur das Kunden-Hauptteam (Oberteam bzw.
     * Mandant ohne Eltern). Unter-Teams erben, sie schreiben keine eigenen Preise.
     */
    public static function darfTeampreisSchreiben(Team $team): bool
    {
        return count(self::preisKette($team)) === 1;
    }

    /** Gemeinsamer Schreibweg: Validierung, eigenen Vorgänger schließen, Zeile mit team_id = $team. */
    private function schreibeTeamZeile(Team $team, FoodAlchemistSupplierItem $item, float $preis, string $status): FoodAlchemistPrice
    {
        if ($preis < 0) {
            throw new \RuntimeException('Negative Preise sind Service-Zuschläge und lassen sich nicht von Hand anlegen.');
        }
        if (! in_array($status, ['0', '2'], true)) {
            throw new \RuntimeException('Status muss 0 (Standard-EK) oder 2 (läuft aus) sein.');
        }

        $zeile = DB::transaction(function () use ($team, $item, $preis, $status) {
            $vorgaenger = $this->scopeAktiv(FoodAlchemistPrice::query())
                ->where('foodalchemist_prices.supplier_item_id', $item->id)
                ->where('foodalchemist_prices.team_id', $team->id);       // nur den EIGENEN Vorgänger schließen
            $this->orderAktiv($vorgaenger)->first()?->update(['valid_to' => now()]);

            return FoodAlchemistPrice::create([
                'team_id' => $team->id,
                'supplier_item_id' => $item->id,
                'price' => $preis,
                'status' => $status,
                'valid_to' => null,
                'is_blocked' => false,
                'creation_date' => now(),
            ]);
        });
        $this->kostenVeraltet($item, $team);

        return $zeile;
    }

    /** Spec 82 3b: Auslöser-Matrix — Team-Kosten der betroffenen Rezepte als veraltet markieren (lazy neu gerechnet). */
    private function kostenVeraltet(FoodAlchemistSupplierItem $item, Team $team): void
    {
        app(KostenCacheService::class)->nachPreisAenderung($item, $team);   // per app(): KostenCache → Recompute → PriceService
    }

    /**
     * Darf $team Preiszeilen an $item pflegen? Besitzer immer (Listenpreis), sonst nur, wenn der
     * Artikel sichtbar ist (Mandantenpreis, Spec 82 3a). Welche ZEILE, entscheidet der Aufrufer:
     * immer nur die mit team_id = $team (nie fremde Mandanten- oder Listenpreise).
     */
    private function pruefePreisPflege(Team $team, FoodAlchemistSupplierItem $item): void
    {
        if (! $item->isOwnedBy($team) && ! FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($item->id)->exists()) {
            throw new \RuntimeException('Geerbter Katalog-Artikel: Preise pflegt nur das Besitzer-Team.');
        }
    }

    /** M2-08: Preiszeile löschen — nur eine Zeile des eigenen Teams (Spec 82 3a: nie fremde Mandantenpreise). */
    public function deleteFor(Team $team, FoodAlchemistSupplierItem $item, int $priceId): void
    {
        $this->pruefePreisPflege($team, $item);
        $price = FoodAlchemistPrice::where('supplier_item_id', $item->id)->where('team_id', $team->id)
            ->whereKey($priceId)->first();
        if ($price === null) {
            throw new \RuntimeException('Preis-Eintrag nicht an diesem Artikel gefunden.');
        }
        $price->delete();
        $this->kostenVeraltet($item, $team);
    }

    /**
     * Preiszeile bearbeiten (MVP: vorher inline in ItemModal::preisUpdate). Nur eine Zeile des
     * eigenen Teams (Spec 82 3a); Whitelist price/valid_to/note; setzt change_date.
     * RuntimeException wenn nicht am Artikel bzw. nicht vom eigenen Team.
     */
    public function updatePrice(Team $team, FoodAlchemistSupplierItem $item, int $priceId, array $felder): FoodAlchemistPrice
    {
        $this->pruefePreisPflege($team, $item);
        $price = FoodAlchemistPrice::where('supplier_item_id', $item->id)->where('team_id', $team->id)
            ->whereKey($priceId)->first();
        if ($price === null) {
            throw new \RuntimeException('Preis-Eintrag nicht an diesem Artikel gefunden.');
        }
        $clean = array_intersect_key($felder, array_flip(['price', 'valid_to', 'note']));
        if (array_key_exists('price', $clean)) {
            $p = str_replace(',', '.', trim((string) $clean['price']));
            if (! is_numeric($p) || (float) $p < 0) {
                throw new \RuntimeException('Preis braucht eine Zahl ≥ 0.');
            }
            $clean['price'] = (float) $p;
        }
        $clean['change_date'] = now();
        $price->update($clean);
        $this->kostenVeraltet($item, $team);

        return $price->refresh();
    }

    /**
     * M2-12 / V-Register: Preis-Anomalien — (a) Sprünge > x % zwischen aufeinander-
     * folgenden Preis-Generationen eines LA, (b) Vergleichspreis-Ausreißer je
     * Warengruppe (Faktor ≥ x vom WG-Median, gleiche Einheit). PHP-seitige
     * Median-Rechnung = engine-agnostisch (07 §7).
     *
     * @return array{spruenge: \Illuminate\Support\Collection, ausreisser: \Illuminate\Support\Collection}
     */
    /** Spec 84 E-2: höchstens so viele Sprünge bzw. Ausreißer je Bericht (die größten); die Gesamtzahl kommt mit. */
    public const ANOMALIEN_GRENZE = 200;

    public function detectAnomalies(Team $team, float $sprungPct = 30.0, float $ausreisserFaktor = 4.0, int $grenze = self::ANOMALIEN_GRENZE): array
    {
        // (a) Generationen-Sprünge. Spec 84 E-2: EINE gestreamte Abfrage statt historyFor() je Artikel (N+1, bei
        // 342k Artikeln zehntausende Abfragen in einem Request). Sicht und Reihenfolge je Artikel sind exakt die von
        // historyFor() (preisSichtFuer + orderAktiv), nur nach Artikel vorsortiert; die Paare entstehen beim Lesen.
        $q = FoodAlchemistPrice::query()
            ->select('foodalchemist_prices.supplier_item_id', 'foodalchemist_prices.price')
            // nur Artikel mit mehr als einer Preiszeile (wie bisher die Vorauswahl) — sonst streamte die Abfrage jede Preiszeile
            ->whereIn('foodalchemist_prices.supplier_item_id', FoodAlchemistPrice::query()->select('supplier_item_id')
                ->whereIn('supplier_item_id', FoodAlchemistSupplierItem::visibleToTeam($team)->select('id'))
                ->groupBy('supplier_item_id')->havingRaw('COUNT(*) > 1'))
            ->where('foodalchemist_prices.price', '>', 0)
            ->orderBy('foodalchemist_prices.supplier_item_id');
        $q = $this->orderAktiv($this->preisSichtFuer($q, $team));

        $spruenge = [];
        $item = null;
        $neu = null;
        foreach ($q->toBase()->cursor() as $p) {
            $preis = (float) $p->price;
            if ((int) $p->supplier_item_id === $item && $neu !== null) {
                $pct = abs($neu - $preis) / $preis * 100;
                if ($pct > $sprungPct) {
                    $spruenge[] = (object) ['supplier_item_id' => $item, 'von' => $preis, 'nach' => $neu, 'sprung_pct' => round($pct, 1)];
                }
            }
            $item = (int) $p->supplier_item_id;
            $neu = $preis;
        }
        usort($spruenge, fn ($a, $b) => [$b->sprung_pct, $a->supplier_item_id, $b->nach] <=> [$a->sprung_pct, $b->supplier_item_id, $a->nach]);

        // (b) WG-Ausreißer. Spec 84 E-2: zwei gestreamte Durchgänge statt alle Kandidaten als Objekte im RAM —
        // erst nur die Vergleichswerte je Gruppe (für den Median), dann die Ausreißer.
        $kandidaten = fn () => \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_items AS i')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'i.id')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 's.gp_id')
            ->join('foodalchemist_suppliers AS sup', 'sup.id', '=', 'i.supplier_id')
            ->whereIn('i.team_id', FoodAlchemistSupplierItem::teamAncestryIds($team))
            ->whereNull('i.deleted_at')->whereNull('s.deleted_at')
            ->whereRaw(FoodAlchemistSupplierItem::mengeSql('i').' > 0')->whereNotNull('i.unit_code')
            ->select(['i.id', 'i.designation', 'i.qty', 'i.qty_korrigiert', 'i.unit_code', 'sup.name AS lieferant', 'g.commodity_group_code AS wg'])
            ->selectSub($this->activePriceSubquery('i.id', $team)->toBase(), 'aktiver_preis')
            ->orderBy('i.id')
            ->cursor();
        $wert = function (object $i): ?array {
            if ($i->aktiver_preis === null || (float) $i->aktiver_preis <= 0) {
                return null;
            }
            $v = $this->vergleichspreis($i, (float) $i->aktiver_preis);

            return $v === null ? null : [($i->wg ?? '–') . '|' . $v['unit'], $v];
        };

        $werte = [];
        foreach ($kandidaten() as $i) {
            if (($w = $wert($i)) !== null) {
                $werte[$w[0]][] = $w[1]['value'];
            }
        }
        $median = [];
        foreach ($werte as $gruppe => $liste) {
            if (count($liste) < 4) {
                continue;                                    // zu wenig Daten für einen belastbaren Median
            }
            sort($liste);
            $median[$gruppe] = $liste[(int) floor(count($liste) / 2)];
        }
        unset($werte);

        $ausreisser = [];
        foreach ($kandidaten() as $i) {
            if (($w = $wert($i)) === null || ! isset($median[$w[0]])) {
                continue;
            }
            $m = $median[$w[0]];
            $value = $w[1]['value'];
            $faktor = $m > 0 ? max($value / $m, $m / max($value, 1e-9)) : 0;
            if ($faktor >= $ausreisserFaktor) {
                $ausreisser[] = (object) [
                    'id' => $i->id, 'label' => $i->designation, 'lieferant' => $i->lieferant,
                    'wg' => $i->wg ?? '–', 'unit' => $w[1]['unit'],
                    'value' => round($value, 2), 'median' => round($m, 2), 'faktor' => round($faktor, 1),
                ];
            }
        }
        usort($ausreisser, fn ($a, $b) => [$b->faktor, $a->id] <=> [$a->faktor, $b->id]);

        return [
            'spruenge' => collect(array_slice($spruenge, 0, $grenze)),
            'ausreisser' => collect(array_slice($ausreisser, 0, $grenze)),
            'spruenge_gesamt' => count($spruenge),
            'ausreisser_gesamt' => count($ausreisser),
        ];
    }

    /**
     * §3.2 Vergleichspreis: Gebindepreis → €/kg | €/l | €/Stk (Anzeige-Normalisierung).
     * qty NULL/0 ⇒ NULL (I4, nie Division), price < 0 ⇒ NULL (I5), unbekannte Einheit ⇒ NULL.
     *
     * @return array{wert: float, unit: string}|null
     */
    public function vergleichspreis(object $item, ?float $preis): ?array
    {
        if ($preis === null || $preis < 0) {
            return null;
        }
        $qty = FoodAlchemistSupplierItem::mengeVon($item);   // korrigierte Menge (EDI: Menge / Stückzahl) vor Necta-qty
        if ($qty === null || $qty == 0.0) {
            return null; // I4 — GT-5: 47,50 € mit qty NULL ⇒ kein Vergleichspreis
        }

        return match ($item->unit_code) {
            'kg' => ['value' => $preis / $qty, 'unit' => '€/kg'],
            'l' => ['value' => $preis / $qty, 'unit' => '€/l'],
            'Stk' => ['value' => $preis / $qty, 'unit' => '€/Stk'],
            default => null,
        };
    }

    /** §3.2 GL-02-Sicht: €/g (kg+l über Dichte 1.0; Stk nur via Stückgewichts-Brücke). */
    public function preisProGramm(object $item, ?float $preis): ?float
    {
        $v = $this->vergleichspreis($item, $preis);

        return match ($v['unit'] ?? null) {
            '€/kg', '€/l' => $v['value'] / 1000,
            default => null,
        };
    }
}
