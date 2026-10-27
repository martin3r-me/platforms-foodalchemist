<?php

namespace Platform\FoodAlchemist\Services\Marken;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;

/**
 * „Lieferanten → Marken“ (Dominique 27.10.2026): zentrale Übersicht aller Einträge in supplier_marken — keine eigene Datenhaltung, Schreiben über
 * {@see MarkenPflegeService}. Je Eintrag: wie viele GPs die Marke in der Klammer tragen (schnell, eine gruppierte Abfrage je Seite) und — auf Knopfdruck —
 * wie viele Artikel das Kürzel auflöst (exakt über {@see MarkeAusArtikel}, lokal gemessen 27.10.: 62 Einträge × 264.514 Artikel = 3,8 s, darum nicht bei
 * jedem Seitenaufbau).
 */
class MarkenUebersichtService
{
    public const PRO_SEITE = 50;

    public function __construct(private readonly MarkeAusArtikel $marken, private readonly MarkenPflegeService $pflege) {}

    /**
     * @param  array{lieferant?: ?int, typ?: ?string, nur_global?: bool, suche?: ?string}  $filter
     * @return array{zeilen: list<array{id: int, kuerzel: string, marke: string, typ: string, position: string, vorrang: int, supplier_id: ?int, lieferant: ?string, gps: ?int}>, gesamt: int, seite: int, pro_seite: int}
     */
    public function liste(array $filter = [], int $seite = 1, int $proSeite = self::PRO_SEITE): array
    {
        $q = DB::table('foodalchemist_supplier_marken as m')   // Alias: Query-Builder statt Modell (SoftDeletes-Scope qualifiziert ohne Alias)
            ->leftJoin('foodalchemist_suppliers as s', 's.id', '=', 'm.supplier_id')
            ->whereNull('m.deleted_at')
            ->when(($filter['lieferant'] ?? null) !== null, fn ($w) => $w->where('m.supplier_id', (int) $filter['lieferant']))
            ->when(($filter['nur_global'] ?? false) === true, fn ($w) => $w->whereNull('m.supplier_id'))
            ->when(($filter['typ'] ?? '') !== '' && ($filter['typ'] ?? null) !== null, fn ($w) => $w->where('m.typ', (string) $filter['typ']))
            ->when(trim((string) ($filter['suche'] ?? '')) !== '', function ($w) use ($filter) {
                $s = '%' . mb_strtolower(trim((string) $filter['suche'])) . '%';
                $w->where(fn ($x) => $x->whereRaw('LOWER(m.marke) LIKE ?', [$s])->orWhereRaw('LOWER(m.kuerzel) LIKE ?', [$s]));
            });
        $gesamt = (clone $q)->count();
        $seite = max(1, $seite);
        $zeilen = $q->orderBy('m.marke')->orderBy('m.kuerzel')->offset(($seite - 1) * $proSeite)->limit($proSeite)
            ->get(['m.id', 'm.kuerzel', 'm.marke', 'm.typ', 'm.position', 'm.vorrang', 'm.supplier_id', 's.name as lieferant'])
            ->map(fn ($z) => ['id' => (int) $z->id, 'kuerzel' => (string) $z->kuerzel, 'marke' => (string) $z->marke, 'typ' => (string) $z->typ,
                'position' => (string) $z->position, 'vorrang' => (int) $z->vorrang, 'supplier_id' => $z->supplier_id !== null ? (int) $z->supplier_id : null,
                'lieferant' => $z->lieferant, 'gps' => null])->all();

        // GPs mit dieser Marke in der Klammer (zusatz), eine Abfrage für die ganze Seite; Sperren haben keine Marke
        $marken = array_values(array_unique(array_map(fn ($z) => mb_strtolower($z['marke']), array_filter($zeilen, fn ($z) => $z['typ'] !== FoodAlchemistSupplierMarke::TYP_SPERRE))));
        $gpZahl = $marken === [] ? collect() : DB::table('foodalchemist_gps')->whereNull('deleted_at')->whereNotNull('zusatz')
            ->whereIn(DB::raw('LOWER(zusatz)'), $marken)->selectRaw('LOWER(zusatz) AS m, COUNT(*) AS n')->groupBy(DB::raw('LOWER(zusatz)'))->pluck('n', 'm');
        foreach ($zeilen as &$z) {
            $z['gps'] = $z['typ'] === FoodAlchemistSupplierMarke::TYP_SPERRE ? null : (int) ($gpZahl[mb_strtolower($z['marke'])] ?? 0);
        }
        unset($z);

        return ['zeilen' => $zeilen, 'gesamt' => $gesamt, 'seite' => $seite, 'pro_seite' => $proSeite];
    }

    /**
     * „Bei mehreren Lieferanten gleich angelegt“ (Dominique 27.10.): Herstellermarken mit gleicher Marke (ohne Groß/Klein), gleichem Kürzel und gleicher Position,
     * je Lieferant angelegt bei ≥ 2 Lieferanten. Eigenmarken und Sperren nie (sie gehören zum Händler bzw. sind bewusst je Lieferant).
     *
     * @return list<array{schluessel: string, marke: string, kuerzel: string, position: string, lieferanten: list<string>, ids: list<int>, vorrang: int,
     *     vorrang_abweichend: bool, global_id: ?int, global_konflikt: ?string}>
     */
    public function dubletten(): array
    {
        $zeilen = DB::table('foodalchemist_supplier_marken as m')->leftJoin('foodalchemist_suppliers as s', 's.id', '=', 'm.supplier_id')
            ->whereNull('m.deleted_at')->whereNotNull('m.supplier_id')->where('m.typ', FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE)
            ->orderBy('m.id')->get(['m.id', 'm.marke', 'm.kuerzel', 'm.position', 'm.vorrang', 's.name as lieferant']);
        $global = DB::table('foodalchemist_supplier_marken')->whereNull('deleted_at')->whereNull('supplier_id')->get(['id', 'kuerzel', 'position', 'marke', 'typ'])
            ->keyBy(fn ($g) => mb_strtolower($g->kuerzel) . '|' . $g->position);
        $out = [];
        foreach ($zeilen->groupBy(fn ($z) => mb_strtolower($z->marke) . '|' . mb_strtolower($z->kuerzel) . '|' . $z->position) as $schluessel => $gruppe) {
            if ($gruppe->count() < 2) {
                continue;
            }
            $erste = $gruppe->first();
            $g = $global->get(mb_strtolower($erste->kuerzel) . '|' . $erste->position);
            $konflikt = $g !== null && ($g->typ !== FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE || mb_strtolower($g->marke) !== mb_strtolower($erste->marke))
                ? 'Globales Kürzel „' . $g->kuerzel . '“ steht schon für ' . ($g->typ === FoodAlchemistSupplierMarke::TYP_SPERRE ? 'eine Sperre' : '„' . $g->marke . '“') : null;
            $vorrang = $gruppe->pluck('vorrang')->map(fn ($v) => (int) $v);
            $out[] = ['schluessel' => $schluessel, 'marke' => $g !== null && $konflikt === null ? (string) $g->marke : (string) $erste->marke,
                'kuerzel' => (string) $erste->kuerzel, 'position' => (string) $erste->position, 'lieferanten' => $gruppe->pluck('lieferant')->map(fn ($l) => (string) $l)->all(),
                'ids' => $gruppe->pluck('id')->map(fn ($i) => (int) $i)->all(), 'vorrang' => (int) $vorrang->max(), 'vorrang_abweichend' => $vorrang->unique()->count() > 1,
                'global_id' => $g !== null ? (int) $g->id : null, 'global_konflikt' => $konflikt];
        }

        return $out;
    }

    /**
     * „Für alle zusammenführen“: eine globale Zeile (supplier_id NULL; vorhandene wird übernommen) mit dem höchsten Vorrang, die Lieferanten-Zeilen fallen weg.
     * Danach Nachrendern der Marke-offen-GPs (streng). Trockenlauf: nichts schreiben, Vorschau mit Lieferanten, Vorrang und dem Nachrender-Ergebnis.
     *
     * @return array{schluessel: string, trockenlauf: bool, marke: string, kuerzel: string, position: string, vorrang: int, entfernt: list<string>, nachrendern: array}
     */
    public function zusammenfuehren(?\Platform\Core\Models\User $user, string $schluessel, bool $trockenlauf = true): array
    {
        if (! $this->pflege->darf($user)) {
            throw new \Platform\FoodAlchemist\Exceptions\FaRechtFehltException(\Platform\FoodAlchemist\Enums\FaRolle::Admin, null,
                'Marken für alle zusammenführen (nur Plattform-Admin und Admin im Master-Team)');
        }
        $d = collect($this->dubletten())->firstWhere('schluessel', $schluessel)
            ?? throw new \RuntimeException('Diese Marke ist nicht (mehr) bei mehreren Lieferanten gleich angelegt.');
        if ($d['global_konflikt'] !== null) {
            throw new \RuntimeException($d['global_konflikt'] . ' — erst dort klären.');
        }
        $ergebnis = ['schluessel' => $schluessel, 'trockenlauf' => $trockenlauf, 'marke' => $d['marke'], 'kuerzel' => $d['kuerzel'], 'position' => $d['position'],
            'vorrang' => $d['vorrang'], 'vorrang_abweichend' => $d['vorrang_abweichend'], 'entfernt' => $d['lieferanten']];

        return DB::transaction(function () use ($user, $d, $trockenlauf, $ergebnis) {
            $offen = app(MarkeOffenService::class);
            if ($trockenlauf) {
                return $ergebnis + ['nachrendern' => $offen->nachrendern($user, null, $d['kuerzel'], $d['position'], true)];
            }
            $global = $this->pflege->kuerzelSetzen($user, null, $d['kuerzel'], $d['marke'], FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE, $d['position'], $d['vorrang']);
            foreach (FoodAlchemistSupplierMarke::query()->whereIn('id', $d['ids'])->get() as $zeile) {
                $this->pflege->kuerzelEntfernen($user, (int) $zeile->supplier_id, (string) $zeile->kuerzel);
            }
            app(\Platform\FoodAlchemist\Services\PlattformProtokoll::class)->schreibe('marke_zusammengefuehrt', ['marke' => $d['marke'], 'kuerzel' => $d['kuerzel'],
                'position' => $d['position'], 'vorrang' => $d['vorrang'], 'lieferanten' => $d['lieferanten'], 'entfernte_ids' => $d['ids']], $user, $global);

            return $ergebnis + ['nachrendern' => $offen->nachrendern($user, null, $d['kuerzel'], $d['position'], false)];
        });
    }

    /**
     * Artikel, deren Bezeichnung über genau diesen Eintrag auf eine Marke führt (MarkeAusArtikel::kuerzelAus, inkl. Vorrang und Sperre); bei einer Sperre:
     * Artikel, deren Wort durch sie gesperrt ist. SQL-Vorfilter LIKE auf das Kürzel, bei Lieferanten-Einträgen nur dessen Artikel.
     */
    public function artikelZahl(int $eintragId): int
    {
        $e = FoodAlchemistSupplierMarke::query()->find($eintragId);
        if ($e === null) {
            return 0;
        }
        $kuerzel = mb_strtolower((string) $e->kuerzel);
        $n = 0;
        DB::table('foodalchemist_supplier_items')->whereNull('deleted_at')
            ->where('designation', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $e->kuerzel) . '%')
            ->when($e->supplier_id !== null, fn ($w) => $w->where('supplier_id', $e->supplier_id))
            ->select(['id', 'designation', 'supplier_id'])->orderBy('id')
            ->chunk(2000, function ($zeilen) use (&$n, $e, $kuerzel) {
                foreach ($zeilen as $i) {
                    $sid = $i->supplier_id !== null ? (int) $i->supplier_id : null;
                    if ($e->typ === FoodAlchemistSupplierMarke::TYP_SPERRE) {
                        $traegt = collect(MarkeAusArtikel::kuerzelWoerter((string) $i->designation))
                            ->contains(fn ($w) => $w['kuerzel'] === $kuerzel && $w['position'] === $e->position);
                        $n += $traegt ? 1 : 0;

                        continue;
                    }
                    $t = $this->marken->kuerzelAus((string) $i->designation, $sid);
                    if ($t !== null && mb_strtolower(rtrim($t[1], '.')) === $kuerzel && $t[0] === $e->marke) {
                        $n++;
                    }
                }
            });

        return $n;
    }
}
