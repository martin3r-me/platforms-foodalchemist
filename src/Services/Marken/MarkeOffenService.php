<?php

namespace Platform\FoodAlchemist\Services\Marken;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\SupplierRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Marken-Nachlauf (Plan Neuaufbau GP-Stamm 3g, Dominique 26.10.2026): GPs mit `marke_offen` (Rezepturware ohne belegte Marke), gruppiert nach den
 * unbekannten Kürzel-Kandidaten aus den Bezeichnungen ihrer Artikel, und „Kürzel zuordnen“ — Eintrag in supplier_marken (über MarkenPflegeService)
 * plus Neu-Rendern der passenden GPs. Die Wortzerlegung kommt aus {@see MarkeAusArtikel::kuerzelWoerter()}, die Auflösung aus
 * {@see MarkeAusArtikel::fuer()} — hier wird nichts davon nachgebaut.
 *
 * Neu-Rendern nur, wenn ALLE Artikel des GP danach dieselbe Marke ergeben (Kurator 26.10., streng): zwei Marken = „teilen“, ein Artikel ohne Marke =
 * „Fall B“ (Dominiques Entscheid: ein Hersteller + Artikel ohne Marke → keine Klammer, bleibt marke_offen). Der gp_key bleibt wie bei jedem Edit.
 */
class MarkeOffenService
{
    /** Form-, Gebinde- und Füllwörter, die ein Kürzel-Kandidat nie ist (klein). */
    public const STOPPWOERTER = ['tk', 'kg', 'gr', 'g', 'ml', 'l', 'ltr', 'st', 'stk', 'pck', 'pk', 'ca', 'nr', 'bio', 'mit', 'und', 'in', 'im', 'an', 'am', 'auf',
        'aus', 'von', 'vom', 'zum', 'zur', 'für', 'fuer', 'der', 'die', 'das', 'den', 'dem', 'ein', 'eine', 'fr', 'frisch', 'gek', 'gef', 'dose', 'glas', 'eimer',
        'beutel', 'btl', 'sack', 'art', 'geb', 'veg', 'hot', 'fl', 'fla', 'krt', 'ktn', 'pack', 'stück', 'stck', 'tray', 'vak', 'ger', 'geg', 'fein', 'grob', 'xl', 'xxl', 'mini', 'gross', 'groß'];

    /** Präpositionen und Füllwörter, auch fremdsprachige aus Produktnamen („Cremoso de Guacamole“) — nie ein Kürzel (Dominique 27.10.). */
    public const FUELLWOERTER = ['de', 'del', 'della', 'di', 'da', 'la', 'le', 'les', 'el', 'il', 'lo', 'al', 'alla', 'en', 'et', 'au', 'aux', 'du', 'des',
        'con', 'y', 'e', 'a', 'of', 'the', 'and', 'with', 'met', 'van', 'het', 'zonder', 'voor', 'op', 'nach', 'art', 'ohne', 'oder', 'zu', 'bei', 'ins', 'vom', 'zum'];

    /** Ergebnis-Fälle je GP beim Zuordnen. */
    public const FALL_GERENDERT = 'gerendert';

    public const FALL_TEILEN = 'teilen';

    public const FALL_B = 'fall_b';

    public const FALL_OHNE_ARTIKEL = 'ohne_artikel';

    /** @var array<string, array<string, array<string, true>>> bekannte Kürzel je Lieferant (je liste()-Aufruf neu geladen) */
    private array $bekannt = [];

    /** @var array<string, true>|null Wörter (≥ 3 Buchstaben) aus den Hauptzutaten aller GPs — je liste()-Aufruf neu geladen */
    private ?array $hauptzutatWoerter = null;

    public function __construct(
        private readonly MarkeAusArtikel $marken,
        private readonly MarkenPflegeService $pflege,
        private readonly GpNamingService $naming,
    ) {}

    /**
     * Gruppen unbekannter Kürzel-Kandidaten über alle marke_offen-GPs: je (Lieferant, Kürzel, Position) Anzahl GPs/Artikel und zwei Beispielartikel,
     * sortiert nach Anzahl GPs. Betrachtet werden nur Artikel, deren Marke heute offen ist.
     *
     * @return array{gruppen: list<array{schluessel: string, supplier_id: ?int, lieferant: string, rolle: ?string, kuerzel: string, roh: string, position: string,
     *     gps: int, artikel: int, beispiele: list<string>, gp_ids: list<int>}>, gps_offen: int, artikel_offen: int}
     */
    public function liste(?int $supplierId = null): array
    {
        $this->bekannt = [];
        $this->hauptzutatWoerter = null;
        $artikel = $this->offeneArtikel($supplierId);
        $endeLieferanten = FoodAlchemistSupplierMarke::query()->where('position', FoodAlchemistSupplierMarke::POSITION_ENDE)
            ->whereNotNull('supplier_id')->distinct()->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();
        $gruppen = [];
        $gpsOffen = [];
        foreach ($artikel as $a) {
            $gpsOffen[$a->gp_id] = true;
            foreach ($this->kandidaten((string) $a->designation, $a->supplier_id !== null ? (int) $a->supplier_id : null, $endeLieferanten,
                trim($a->gp_haupt . ' ' . $a->gp_name)) as $k) {
                $schluessel = ($a->supplier_id ?? 'alle') . '|' . $k['position'] . '|' . $k['kuerzel'];
                $g = $gruppen[$schluessel] ?? ['schluessel' => $schluessel, 'supplier_id' => $a->supplier_id !== null ? (int) $a->supplier_id : null,
                    'lieferant' => (string) ($a->lieferant ?? '—'), 'rolle' => $a->rolle, 'kuerzel' => $k['kuerzel'], 'roh' => $k['roh'], 'position' => $k['position'],
                    'gps' => 0, 'artikel' => 0, 'beispiele' => [], 'gp_ids' => []];
                $g['artikel']++;
                if (! in_array((int) $a->gp_id, $g['gp_ids'], true)) {
                    $g['gp_ids'][] = (int) $a->gp_id;
                    $g['gps']++;
                }
                if (count($g['beispiele']) < 2 && ! in_array((string) $a->designation, $g['beispiele'], true)) {
                    $g['beispiele'][] = (string) $a->designation;
                }
                $gruppen[$schluessel] = $g;
            }
        }
        usort($gruppen, fn ($x, $y) => [$y['gps'], $y['artikel'], $x['kuerzel']] <=> [$x['gps'], $x['artikel'], $y['kuerzel']]);

        return ['gruppen' => array_values($gruppen), 'gps_offen' => count($gpsOffen), 'artikel_offen' => count($artikel)];
    }

    /**
     * Lieferanten mit Rolle „offen“, an deren Artikeln Rezepturware hängt (Leitstellen-Signal: erst einstufen, dann greift die Marken-Auflösung).
     *
     * @return list<array{id: int, name: string, gps: int}>
     */
    public function lieferantenOffenMitRezepturware(): array
    {
        return DB::table('foodalchemist_suppliers as s')
            ->join('foodalchemist_supplier_items as i', 'i.supplier_id', '=', 's.id')
            ->join('foodalchemist_supplier_item_structures as st', 'st.supplier_item_id', '=', 'i.id')
            ->join('foodalchemist_gps as g', 'g.id', '=', 'st.gp_id')
            ->where('s.rolle', SupplierRolle::Offen->value)->whereNull('s.deleted_at')->whereNull('i.deleted_at')->whereNull('g.deleted_at')
            ->where('g.rezepturware', true)
            ->groupBy('s.id', 's.name')->orderByDesc(DB::raw('count(distinct g.id)'))
            ->get(['s.id', 's.name', DB::raw('count(distinct g.id) as gps')])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'gps' => (int) $r->gps])->all();
    }

    /**
     * „Kürzel zuordnen“: Eintrag über MarkenPflegeService (Recht: Plattform-Admin/Master-Admin), danach jedes marke_offen-GP mit einem Artikel, der dieses
     * Kürzel an dieser Position trägt, prüfen und — nur bei genau einer Marke über ALLE Artikel — mit der Marke in der Klammer neu rendern.
     * `trockenlauf`: nichts schreiben; die Marke des neuen Kürzels wird für die Vorschau angenommen (Vorrang anderer Kürzel bleibt unberücksichtigt).
     *
     * @return array{kuerzel: string, marke: string, trockenlauf: bool, zaehler: array<string, int>, gps: list<array{id: int, name_alt: string, name_neu: ?string, fall: string, marken: list<string>}>}
     */
    public function zuordnen(?User $user, ?int $supplierId, string $kuerzel, string $marke, string $typ = FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE,
        string $position = FoodAlchemistSupplierMarke::POSITION_ANFANG, int $vorrang = 0, bool $trockenlauf = false): array
    {
        $k = MarkenPflegeService::normKuerzel($kuerzel);
        if (! $this->pflege->darf($user)) {
            throw new \Platform\FoodAlchemist\Exceptions\FaRechtFehltException(\Platform\FoodAlchemist\Enums\FaRolle::Admin, null,
                'Marken-Kürzel zuordnen (nur Plattform-Admin und Admin im Master-Team)');
        }

        return DB::transaction(function () use ($user, $supplierId, $k, $marke, $typ, $position, $vorrang, $trockenlauf) {
            if (! $trockenlauf) {
                $eintrag = $this->pflege->kuerzelSetzen($user, $supplierId, $k, $marke, $typ, $position, $vorrang);
                app(PlattformProtokoll::class)->schreibe('marke_kuerzel_zugeordnet', ['kuerzel' => $k, 'marke' => trim($marke), 'supplier_id' => $supplierId,
                    'position' => $position, 'typ' => $typ], $user, $eintrag);
            }
            $gpIds = $this->gpIdsMitKuerzel($supplierId, $k, $position);
            $zaehler = [self::FALL_GERENDERT => 0, self::FALL_TEILEN => 0, self::FALL_B => 0, self::FALL_OHNE_ARTIKEL => 0];
            $ergebnis = [];
            $team = $this->team($user);
            foreach (FoodAlchemistGp::ohneTeamSicht('marken-nachlauf: globale Stamm-GPs')->whereIn('id', $gpIds)->where('marke_offen', true)->orderBy('id')->get() as $gp) {
                $artikel = FoodAlchemistSupplierItem::ohneTeamSicht('marken-nachlauf: Artikel des GP')->with('supplier')
                    ->whereIn('id', DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gp->id)->pluck('supplier_item_id'))->get();
                $marken = [];
                $ohneMarke = false;
                foreach ($artikel as $la) {
                    $m = $this->marken->fuer($la)->marke;
                    if ($m === null && $trockenlauf && $this->traegtKuerzel($la, $supplierId, $k, $position)) {
                        $m = trim($marke);
                    }
                    $m === null ? $ohneMarke = true : $marken[mb_strtolower($m)] = $m;
                }
                $fall = match (true) {
                    $artikel->isEmpty() => self::FALL_OHNE_ARTIKEL,
                    count($marken) > 1 => self::FALL_TEILEN,
                    $ohneMarke || $marken === [] => self::FALL_B,
                    default => self::FALL_GERENDERT,
                };
                $nameNeu = null;
                if ($fall === self::FALL_GERENDERT) {
                    $neueMarke = (string) reset($marken);
                    $nameNeu = $this->nameMitMarke((string) $gp->name, $neueMarke);
                    if (! $trockenlauf) {
                        $vorher = ['name' => $gp->name, 'zusatz' => $gp->zusatz, 'marke_offen' => (bool) $gp->marke_offen];
                        $this->naming->updateGp($team, $gp, ['name' => $nameNeu, 'zusatz' => $neueMarke, 'hauptzutat' => (string) $gp->main_ingredient_display,
                            'condition' => $gp->condition, 'commodity_group_code' => (string) $gp->commodity_group_code, 'sub_category' => $gp->sub_category]);
                        app(PlattformProtokoll::class)->schreibe('gp_marke_nachgerendert', ['vorher' => $vorher, 'name_neu' => $nameNeu, 'zusatz_neu' => $neueMarke,
                            'kuerzel' => $k], $user, $gp);
                    }
                }
                $zaehler[$fall]++;
                $ergebnis[] = ['id' => (int) $gp->id, 'name_alt' => (string) $gp->name, 'name_neu' => $nameNeu, 'fall' => $fall, 'marken' => array_values($marken)];
            }
            return ['kuerzel' => $k, 'marke' => trim($marke), 'trockenlauf' => $trockenlauf, 'zaehler' => $zaehler, 'gps' => $ergebnis];
        });
    }

    /**
     * Name mit Marke in der Klammer: der Zusatz steht vor den Flags (Renderer §6.6) — „Bohnensalat: konserviert / (Bio)“ → „… / (C&F) / (Bio)“.
     * Bewusst am bestehenden Namen statt aus den Feldern: so ändert das Zuordnen nur die Klammer, nie den Rest des Namens.
     */
    public function nameMitMarke(string $name, string $marke): string
    {
        $pos = mb_strpos($name, ' / (');
        $basis = $pos === false ? $name : mb_substr($name, 0, $pos);
        $flags = $pos === false ? '' : mb_substr($name, $pos);

        return rtrim($basis) . ' / (' . $this->naming->bereinigeZusatz($marke) . ')' . $flags;
    }

    /**
     * Kürzel-Kandidaten einer Bezeichnung: Wortzerlegung aus MarkeAusArtikel, ohne bekannte Kürzel (Lieferant oder global), Sperr- und Stoppwörter;
     * nur kürzelartige Wörter (mit Punkt, höchstens vier Buchstaben, mit „&“ oder GROSS bis sechs Buchstaben in gemischter Schreibweise). Position „ende“
     * nur bei Lieferanten, die schon ein Ende-Kürzel führen.
     *
     * @param  list<int>  $endeLieferanten
     * @return list<array{kuerzel: string, roh: string, position: string}>
     */
    public function kandidaten(string $bezeichnung, ?int $supplierId, array $endeLieferanten = [], string $gpText = ''): array
    {
        $bekannt = $this->bekannteKuerzel($supplierId);
        $gemischt = $bezeichnung !== mb_strtoupper($bezeichnung);
        $out = [];
        foreach (MarkeAusArtikel::kuerzelWoerter($bezeichnung) as $w) {
            if ($w['position'] === FoodAlchemistSupplierMarke::POSITION_ENDE && ! in_array((int) $supplierId, $endeLieferanten, true)) {
                continue;
            }
            $k = $w['kuerzel'];
            $roh = rtrim($w['roh'], '.');
            $kuerzelArtig = str_ends_with($w['roh'], '.') || mb_strlen($k) <= 4 || str_contains($k, '&')
                || ($gemischt && mb_strlen($roh) <= 6 && $roh === mb_strtoupper($roh));
            if (! $kuerzelArtig || mb_strlen($k) < 2 || in_array($k, self::STOPPWOERTER, true) || in_array($k, self::FUELLWOERTER, true)
                || isset($bekannt[$w['position']][$k]) || $this->istHauptzutatWort($k, str_ends_with($w['roh'], '.'), $gpText)) {
                continue;
            }
            $out[$w['position'] . '|' . $k] = $w;
        }

        return array_values($out);
    }

    /**
     * Vorfilter (Dominique 27.10., ohne zu raten): ein Wort, das als Hauptzutat bzw. als Wort in der Hauptzutat eines GP vorkommt (Käse, Jus, Mix), ist
     * kein Markenkürzel. Abgekürzt mit Punkt (≥ 3 Buchstaben, „Dess.“, „Häh.“): Präfix eines Worts aus Hauptzutat oder Name DES EIGENEN GP — gegen alle
     * GPs fielen sonst echte Kürzel weg, die zufällig ein Wortanfang sind (Göt. ↔ Götterspeise, Dal. ↔ Dal, Messung lokal 27.10.).
     */
    private function istHauptzutatWort(string $kuerzel, bool $mitPunkt, string $gpText = ''): bool
    {
        if ($this->hauptzutatWoerter === null) {
            $this->hauptzutatWoerter = [];
            foreach (DB::table('foodalchemist_gps')->whereNull('deleted_at')->whereNotNull('main_ingredient_display')->distinct()->pluck('main_ingredient_display') as $h) {
                foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string) $h), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $wort) {
                    if (mb_strlen($wort) >= 3) {
                        $this->hauptzutatWoerter[$wort] = true;
                    }
                }
            }
        }
        if (isset($this->hauptzutatWoerter[$kuerzel])) {
            return true;
        }
        if ($mitPunkt && mb_strlen($kuerzel) >= 3) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($gpText), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $wort) {
                if (mb_strlen($wort) > mb_strlen($kuerzel) && str_starts_with($wort, $kuerzel)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * „Kein Kürzel“ (Dominique 27.10.): Sperr-Eintrag über MarkenPflegeService — der Kandidat verschwindet dauerhaft aus der Liste, MarkeAusArtikel nutzt das
     * Wort nie als Marke. `trockenlauf`: nichts schreiben, nur die betroffenen GPs/Artikel zählen. Rückgängig über den Lieferanten-Block.
     *
     * @return array{kuerzel: string, supplier_id: ?int, position: string, trockenlauf: bool, gps: int, artikel: int}
     */
    public function sperren(?User $user, ?int $supplierId, string $kuerzel, string $position = FoodAlchemistSupplierMarke::POSITION_ANFANG, bool $trockenlauf = false): array
    {
        $k = MarkenPflegeService::normKuerzel($kuerzel);
        if (! $this->pflege->darf($user)) {
            throw new \Platform\FoodAlchemist\Exceptions\FaRechtFehltException(\Platform\FoodAlchemist\Enums\FaRolle::Admin, null,
                'Marken-Kürzel sperren (nur Plattform-Admin und Admin im Master-Team)');
        }
        $gruppe = collect($this->liste($supplierId)['gruppen'])->first(fn ($g) => $g['kuerzel'] === $k && $g['position'] === $position && $g['supplier_id'] === $supplierId);
        if (! $trockenlauf) {
            $eintrag = $this->pflege->sperren($user, $supplierId, $k, $position);
            app(PlattformProtokoll::class)->schreibe('marke_kuerzel_gesperrt', ['kuerzel' => $k, 'supplier_id' => $supplierId, 'position' => $position,
                'gps' => $gruppe['gps'] ?? 0, 'artikel' => $gruppe['artikel'] ?? 0], $user, $eintrag);
        }

        return ['kuerzel' => $k, 'supplier_id' => $supplierId, 'position' => $position, 'trockenlauf' => $trockenlauf,
            'gps' => (int) ($gruppe['gps'] ?? 0), 'artikel' => (int) ($gruppe['artikel'] ?? 0)];
    }

    /** @return \Illuminate\Support\Collection<int, object{gp_id: int, designation: string, supplier_id: ?int, lieferant: ?string, rolle: ?string, id: int}> */
    private function offeneArtikel(?int $supplierId): \Illuminate\Support\Collection
    {
        $zeilen = DB::table('foodalchemist_gps as g')
            ->join('foodalchemist_supplier_item_structures as st', 'st.gp_id', '=', 'g.id')
            ->join('foodalchemist_supplier_items as i', 'i.id', '=', 'st.supplier_item_id')
            ->leftJoin('foodalchemist_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->where('g.marke_offen', true)->whereNull('g.deleted_at')->whereNull('i.deleted_at')
            ->when($supplierId !== null, fn ($q) => $q->where('i.supplier_id', $supplierId))
            ->orderBy('g.id')->orderBy('i.id')
            ->get(['g.id as gp_id', 'g.main_ingredient_display as gp_haupt', 'g.name as gp_name', 'i.id', 'i.designation', 'i.supplier_id', 's.name as lieferant', 's.rolle']);
        if ($zeilen->isEmpty()) {
            return $zeilen;
        }
        // Nur Artikel, deren Marke heute offen ist — die Auflösung selbst fragt MarkeAusArtikel (einzige Quelle)
        $modelle = FoodAlchemistSupplierItem::ohneTeamSicht('marken-nachlauf: Artikel der offenen GPs')->with('supplier')
            ->whereIn('id', $zeilen->pluck('id')->unique()->all())->get()->keyBy('id');

        return $zeilen->filter(fn ($z) => isset($modelle[$z->id]) && $this->marken->fuer($modelle[$z->id])->marke === null)->values();
    }

    /** @return list<int> marke_offen-GPs mit mindestens einem Artikel, der das Kürzel an der Position trägt (bei Lieferanten-Geltung nur dessen Artikel) */
    private function gpIdsMitKuerzel(?int $supplierId, string $kuerzel, string $position): array
    {
        $ids = [];
        foreach (DB::table('foodalchemist_gps as g')
            ->join('foodalchemist_supplier_item_structures as st', 'st.gp_id', '=', 'g.id')
            ->join('foodalchemist_supplier_items as i', 'i.id', '=', 'st.supplier_item_id')
            ->where('g.marke_offen', true)->whereNull('g.deleted_at')->whereNull('i.deleted_at')
            ->when($supplierId !== null, fn ($q) => $q->where('i.supplier_id', $supplierId))
            ->get(['g.id', 'i.designation']) as $z) {
            foreach (MarkeAusArtikel::kuerzelWoerter((string) $z->designation) as $w) {
                if ($w['kuerzel'] === $kuerzel && $w['position'] === $position) {
                    $ids[(int) $z->id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function traegtKuerzel(FoodAlchemistSupplierItem $la, ?int $supplierId, string $kuerzel, string $position): bool
    {
        if ($supplierId !== null && (int) $la->supplier_id !== $supplierId) {
            return false;
        }
        foreach (MarkeAusArtikel::kuerzelWoerter((string) $la->designation) as $w) {
            if ($w['kuerzel'] === $kuerzel && $w['position'] === $position) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, true>> Position → Kürzel (global + dieses Lieferanten) */
    private function bekannteKuerzel(?int $supplierId): array
    {
        $schluessel = (string) ($supplierId ?? 'alle');
        if (! isset($this->bekannt[$schluessel])) {
            $this->bekannt[$schluessel] = [];
            foreach (FoodAlchemistSupplierMarke::query()->where(fn ($q) => $q->whereNull('supplier_id')->when($supplierId !== null, fn ($w) => $w->orWhere('supplier_id', $supplierId)))
                ->get(['kuerzel', 'position']) as $z) {
                $this->bekannt[$schluessel][(string) $z->position][mb_strtolower((string) $z->kuerzel)] = true;
            }
        }

        return $this->bekannt[$schluessel];
    }

    private function team(?User $user): Team
    {
        return (MasterTeam::id() !== null ? Team::find(MasterTeam::id()) : null) ?? $user?->currentTeamRelation ?? throw new \RuntimeException('Kein Team für das Neu-Rendern.');
    }
}
