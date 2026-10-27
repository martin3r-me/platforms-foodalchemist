<?php

namespace Platform\FoodAlchemist\Services\Matching;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\StammLieferantService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\Suche;

/**
 * „Artikel verknüpfen“ (GP-Detail, GP-Modal): freie, lieferbare Artikel der Team-Sicht zu einem Suchtext (Bugfix Dominique 27.10.2026).
 * Vorher alphabetisch mit LIMIT 8: bei „apfelsaft“ nur Handelshof-Artikel „0,2 …“, gleich lautende Treffer ohne Unterscheidung.
 *
 * Gesucht wird in Bezeichnung und Artikelnummer (jedes Wort muss treffen) ODER über die Marke (Nachtrag 27.10.): Necta-`brand` bzw. eine
 * Marke aus supplier_marken, deren Kürzel in der Bezeichnung steht („Hi Taste“ findet Chefs-Artikel mit „HT“). Ein Marken-Treffer zählt wie
 * „jedes Wort als ganzes Wort“ und steht dort hinter den Bezeichnungs-Treffern, aber vor reinen Teilstring-Treffern („hi“ in „Chicken“).
 *
 * Artikel inaktiver Lieferanten bleiben draußen (Kurator 27.10.: „Handelshof Mönchengladbach“ inaktiv neben „… EDI“ — sonst jeder Artikel doppelt).
 *
 * Reihenfolge: Relevanz (Wort am Anfang der Bezeichnung ohne führende Gebindeangabe > ganzes Wort > Teilstring) → mit aktivem Preis der Team-Sicht
 * vor ohne (Necta liefert bei Chefs/Edna/Broscheit/Delta den ganzen Katalog, Preise nur fürs gelistete Sortiment) → Lieferanten-Rang
 * des Teams (Prioritätskette aus den Einkaufs-Einstellungen, dann Stamm-Lieferanten) → Bezeichnung. Die Datenbank liefert bis zu
 * {@see KANDIDATEN} Treffer grob vorsortiert (Wortanfang zuerst), die Feinsortierung läuft hier. Jeder Treffer trägt Gebinde und
 * Preis in der Preis-Sicht des Teams.
 */
class VerknuepfbareArtikelSuche
{
    public const KANDIDATEN = 200;


    /** Gebindeangabe am Anfang der Bezeichnung („0,2 “, „0,75L “, „3L “, „6x1,0 “) — zählt für die Relevanz nicht mit. */
    private const GEBINDE_VORN = '/^\d+(?:[.,]\d+)?\s*(?:x\s*\d+(?:[.,]\d+)?\s*)?(?:kg|gr|ml|cl|ltr|l|g|stk|st)?\.?\s+/iu';

    public function __construct(
        private readonly PriceService $preise,
        private readonly TeamSettingsService $settings,
        private readonly StammLieferantService $stamm,
    ) {}

    /**
     * @return array{treffer: Collection<int, FoodAlchemistSupplierItem>, weitere: int, mehr_als: ?int}  weitere = Treffer über dem Limit
     *         (genau bis {@see KANDIDATEN}); mehr_als gesetzt, wenn es noch mehr gibt als gezählt
     */
    public function suche(Team $team, string $suche, ?string $warengruppe = null, int $limit = 20): array
    {
        $tokens = Suche::tokens($suche);
        if ($tokens === []) {
            return ['treffer' => collect(), 'weitere' => 0, 'mehr_als' => null];
        }

        $marken = $this->marken($suche);
        $kandidaten = $this->kandidaten($team, $suche, $tokens[0], $marken)
            ->each(function ($la) use ($tokens, $marken, $suche) {
                $bezeichnung = $this->relevanzBezeichnung($la, $tokens);
                $la->setAttribute('ueber_marke', $bezeichnung === null);
                $la->setAttribute('relevanz', $bezeichnung ?? ($this->markePasst($la, $marken, $suche) ? count($tokens) : null));
            })
            ->filter(fn ($la) => $la->relevanz !== null)   // LIKE auf ein Kürzel traf ein Wort, das nach der Matchregel keins ist
            ->values();
        $mehrAls = $kandidaten->count() > self::KANDIDATEN ? self::KANDIDATEN : null;
        $kandidaten = $kandidaten->take(self::KANDIDATEN);

        $prio = array_flip(array_map('intval', $this->settings->leadLaPrioritaeten($team)));
        $stamm = array_flip($this->stamm->stammSupplierIdsFor($team, $warengruppe));
        $treffer = $kandidaten
            ->each(function ($la) use ($tokens, $prio, $stamm) {
                $sid = (int) $la->supplier_id;
                $la->setAttribute('lieferanten_rang', isset($prio[$sid]) ? $prio[$sid] : (isset($stamm[$sid]) ? 10_000 : 20_000));
            })
            ->sortBy(fn ($la) => [$la->relevanz, $la->aktiver_preis === null ? 1 : 0, $la->ueber_marke ? 1 : 0, $la->lieferanten_rang, $this->ohneGebinde((string) $la->designation), (int) $la->id])
            ->take($limit)
            ->values();

        $this->mitGebinde($treffer);

        return ['treffer' => $treffer, 'weitere' => max(0, $kandidaten->count() - $treffer->count()), 'mehr_als' => $mehrAls];
    }

    /**
     * Marken aus supplier_marken, deren Name den Suchtext enthält (ohne „kein Kürzel“-Sperren).
     *
     * @return Collection<int, FoodAlchemistSupplierMarke>
     */
    private function marken(string $suche): Collection
    {
        $text = mb_strtolower(trim($suche));
        if (mb_strlen($text) < 2) {
            return collect();
        }

        return FoodAlchemistSupplierMarke::query()->where('typ', '!=', FoodAlchemistSupplierMarke::TYP_SPERRE)
            ->where(function ($w) use ($text) {
                foreach (Suche::varianten($text) as $v) {
                    $w->orWhereRaw('LOWER(marke) LIKE ?', ['%' . $v . '%']);
                }
            })
            ->limit(50)->get(['id', 'supplier_id', 'kuerzel', 'marke', 'position']);
    }

    private function kandidaten(Team $team, string $suche, string $erstesToken, Collection $marken): Collection
    {
        $query = FoodAlchemistSupplierItem::query()
            ->visibleToTeam($team)                                     // D1: globaler Seed + eigenes Team/Master-Kette
            ->leftJoin('foodalchemist_supplier_item_structures AS s', function ($join) {
                $join->on('s.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
                    ->whereNull('s.deleted_at');
            })
            ->leftJoin('foodalchemist_suppliers AS sup', 'sup.id', '=', 'foodalchemist_supplier_items.supplier_id')
            ->whereNull('s.gp_id')
            ->where(fn ($w) => $w->whereNull('sup.is_inactive')->orWhere('sup.is_inactive', false))   // inaktive Lieferanten nicht anbieten
            ->lieferbar();   // eingestellte Artikel (Necta, nicht bestellbar) nicht zum Verknüpfen anbieten
        $text = mb_strtolower(trim($suche));
        $query->where(function ($w) use ($suche, $text, $marken) {
            $w->where(fn ($t) => Suche::likeAny($t, ['foodalchemist_supplier_items.designation', "COALESCE(foodalchemist_supplier_items.article_number, '')"], $suche))
                ->orWhereRaw("LOWER(COALESCE(foodalchemist_supplier_items.brand, '')) LIKE ?", ['%' . $text . '%']);
            foreach ($marken as $m) {   // Kürzel als Wort in der Bezeichnung (grob per LIKE, genau prüft markePasst)
                $k = mb_strtolower((string) $m->kuerzel);
                $muster = $m->position === FoodAlchemistSupplierMarke::POSITION_ENDE ? ['% ' . $k, '% ' . $k . '.'] : [$k . ' %', $k . '.%', '% ' . $k . ' %', '% ' . $k . '.%'];
                $w->orWhere(function ($x) use ($m, $muster) {
                    $x->when($m->supplier_id !== null, fn ($q) => $q->where('foodalchemist_supplier_items.supplier_id', $m->supplier_id))
                        ->where(function ($y) use ($muster) {
                            foreach ($muster as $mu) {
                                $y->orWhereRaw('LOWER(foodalchemist_supplier_items.designation) LIKE ?', [$mu]);
                            }
                        });
                });
            }
        });

        // Grobe Vorsortierung in der DB, damit bei vielen Treffern die guten im Kandidatenfenster liegen: Wortanfang vor Rest
        $anfang = [];
        $bindungen = [];
        foreach (Suche::varianten($erstesToken) as $v) {
            $anfang[] = 'LOWER(foodalchemist_supplier_items.designation) LIKE ? OR LOWER(foodalchemist_supplier_items.designation) LIKE ?';
            array_push($bindungen, $v . '%', '% ' . $v . '%');
        }

        return $query
            ->select('foodalchemist_supplier_items.*', 'sup.name AS supplier_name')
            ->selectSub($this->preise->activePriceSubquery('foodalchemist_supplier_items.id', $team)->toBase(), 'aktiver_preis')   // Preis der Team-Sicht
            ->orderByRaw('CASE WHEN ' . implode(' OR ', $anfang) . ' THEN 0 ELSE 1 END', $bindungen)
            ->orderByRaw('CASE WHEN aktiver_preis IS NULL THEN 1 ELSE 0 END')
            ->orderBy('foodalchemist_supplier_items.id')
            ->limit(self::KANDIDATEN + 1)
            ->get();
    }

    /** Relevanz über Bezeichnung/Artikelnummer — null, wenn nicht jedes Wort dort steht (dann bleibt nur die Marke). */
    private function relevanzBezeichnung(FoodAlchemistSupplierItem $la, array $tokens): ?int
    {
        $text = mb_strtolower((string) $la->designation . ' ' . (string) $la->article_number);
        $alleWoerter = collect($tokens)->every(fn ($t) => collect(Suche::varianten($t))->contains(fn ($v) => str_contains($text, $v)));

        return $alleWoerter ? $this->relevanz((string) $la->designation, (string) $la->article_number, $tokens) : null;
    }

    /** Necta-Marke enthält den Suchtext, oder ein Kürzel einer passenden Marke steht nach der Matchregel in der Bezeichnung. */
    private function markePasst(FoodAlchemistSupplierItem $la, Collection $marken, string $suche): bool
    {
        if (str_contains(mb_strtolower((string) $la->brand), mb_strtolower(trim($suche)))) {
            return true;
        }
        $woerter = collect(MarkeAusArtikel::kuerzelWoerter((string) $la->designation));

        return $marken->contains(fn ($m) => ($m->supplier_id === null || (int) $m->supplier_id === (int) $la->supplier_id)
            && $woerter->contains(fn ($w) => $w['kuerzel'] === mb_strtolower((string) $m->kuerzel) && $w['position'] === $m->position));
    }

    private function ohneGebinde(string $bezeichnung): string
    {
        $text = mb_strtolower(trim($bezeichnung));

        return preg_replace(self::GEBINDE_VORN, '', $text) ?? $text;
    }

    /** 0 = Wort am Anfang (ohne Gebinde vorn) oder Artikelnummer, 1 = ganzes Wort, 2 = Teilstring — je Token, summiert. */
    public function relevanz(string $bezeichnung, string $artikelNr, array $tokens): int
    {
        $text = mb_strtolower(trim($bezeichnung));
        $ohneGebinde = $this->ohneGebinde($bezeichnung);
        $summe = 0;
        foreach ($tokens as $token) {
            $stufe = mb_strtolower(trim($artikelNr)) === $token ? 0 : 2;
            foreach (Suche::varianten($token) as $v) {
                $wort = '(?<![\p{L}\p{N}])' . preg_quote($v, '/') . '(?![\p{L}\p{N}])';
                if (preg_match('/^' . $wort . '/u', $ohneGebinde)) {
                    $stufe = 0;
                } elseif ($stufe > 1 && preg_match('/' . $wort . '/u', $text)) {
                    $stufe = 1;
                }
            }
            $summe += $stufe;
        }

        return $summe;
    }

    /** Gebinde („1 l“, „0,2 l“) für die Trefferzeile — der Preis kommt schon aus der Kandidaten-Abfrage. */
    private function mitGebinde(Collection $treffer): void
    {
        foreach ($treffer as $la) {
            $menge = FoodAlchemistSupplierItem::mengeVon($la);
            $la->setAttribute('gebinde_text', $menge !== null && $menge > 0 && $la->unit_code
                ? rtrim(rtrim(number_format($menge, 3, ',', '.'), '0'), ',') . ' ' . $la->unit_code : null);
        }
    }
}
