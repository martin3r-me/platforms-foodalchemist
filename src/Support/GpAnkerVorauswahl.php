<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Anker-Vorauswahl für `gp.anker`: aus welchen Slugs die KI den Kern-Anker eines GPs wählen darf.
 *
 * io-Lauf 01.11.2026 (138 Antworten: 91 neutral, 43 Pate, 4 eigen): Die alte Vorauswahl fragte den semantischen Recall mit dem ganzen
 * GP-Namen. Zustandswörter wie „frisch" zogen `fresh_atlantic_salmon`, `fresh_goat`, `raw_veal` in fast jede Liste, der echte Anker fehlte oft.
 * Dann hieß „neutral" nur „nicht in der Vorauswahl", und die Paten-Regel griff zu früh. Neu:
 *  (a) Anker der Geschwister (gleiche Hauptzutat), ungefiltert: das stärkste Signal;
 *  (b) Wort-Treffer im Produktkopf und in der Hauptzutat, auch in Komposita (Rostbratwurst → Bratwurst);
 *  (c) semantischer Recall nur mit dem Produktkopf (Name vor dem Doppelpunkt, ohne Marke);
 *  (b) und (c) nur aus den Anker-Kategorien, die zur Warengruppe passen (Fisch-Anker nur bei Fisch), Liste in
 *  `foodalchemist.gp_anker_vorauswahl.kategorien_je_wg`; eine Warengruppe ohne Eintrag filtert nicht.
 * Trägt keine Quelle: die häufigsten Kern-Anker der Warengruppe (bisher die alphabetisch ersten 120 des ganzen Vokabulars).
 * Jede Quelle nach Slug sortiert: der Fingerabdruck des externen KI-Auftrags hängt an der Liste im Prompt (#1002).
 * Messen: `foodalchemist:gp-anker-vorauswahl-messen` (alt gegen neu an GPs mit bekanntem Anker).
 */
final class GpAnkerVorauswahl
{
    public const SEMANTISCH = 40;

    public const GESCHWISTER = 10;

    public const MAX = 120;

    /** Wörter im Produktkopf, die nichts über die Zutat sagen (Zustand, Verarbeitung, Füllwörter) */
    private const STOPP = ['frisch', 'trocken', 'ganz', 'gegart', 'konserviert', 'geschnitten', 'vegan', 'veganem', 'vegane', 'veganer', 'classic', 'premium', 'gefuellt', 'gemischt'];

    /** @var list<array{0: string, 1: list<string>, 2: int}>|null Anker-Wortindex je Prozess */
    private static ?array $index = null;

    /** @return list<string> Slugs, `neutral` immer zuletzt */
    public function fuer(FoodAlchemistGp $gp, ?Team $team = null): array
    {
        return $this->liste($this->quellen($gp, $team), (string) $gp->commodity_group_code);
    }

    /**
     * Die drei Quellen einzeln (für die Messung).
     *
     * @return array{geschwister: list<string>, lexikalisch: list<string>, semantisch: list<string>}
     */
    public function quellen(FoodAlchemistGp $gp, ?Team $team = null): array
    {
        $wg = (string) $gp->commodity_group_code;
        $kopf = self::kopf((string) $gp->name);
        $haupt = trim((string) $gp->main_ingredient_display);
        $frage = $haupt !== '' && ! str_contains(mb_strtolower($kopf), mb_strtolower($haupt)) ? $kopf.' '.$haupt : $kopf;
        // nur der Kopf: hinter dem Doppelpunkt stehen Zustandswörter, die im Wortindex auf einen beliebigen Anker zeigen („frisch" → Lachs)
        $ids = app(PairingService::class)->lexicalAnkerKandidaten($frage);
        $slugs = $ids === [] ? [] : DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_unique($ids))
            ->whereNull('deleted_at')->pluck('slug')->all();
        $lexikalisch = $this->passend([...$slugs, ...$this->woerter($frage)], $wg);

        $semantisch = array_slice($this->passend($this->semantisch($frage, self::SEMANTISCH * 2), $wg, false), 0, self::SEMANTISCH);

        return ['geschwister' => $this->geschwister($gp, $team), 'lexikalisch' => $lexikalisch, 'semantisch' => self::sortiert($semantisch)];
    }

    /**
     * Die bisherige Vorauswahl (ganzer Name, ohne Geschwister, ohne Warengruppen-Filter) — nur für die Vorher/Nachher-Messung.
     *
     * @return list<string>
     */
    public function alt(FoodAlchemistGp $gp): array
    {
        $ids = app(PairingService::class)->lexicalAnkerKandidaten((string) $gp->name);
        $slugs = $ids === [] ? [] : DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $ids)->whereNull('deleted_at')
            ->orderBy('slug')->pluck('slug')->all();
        $vereint = array_values(array_unique(array_merge($slugs, $this->semantisch((string) $gp->name, 40))));
        if ($vereint === []) {
            $vereint = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')->where('slug', '!=', 'neutral')->orderBy('slug')->pluck('slug')->all();
        }

        return array_values(array_unique([...array_slice($vereint, 0, self::MAX), 'neutral']));
    }

    /** Produktkopf: Name vor dem Doppelpunkt, ohne „/ (Marke)" und Klammern. */
    public static function kopf(string $name): string
    {
        $kopf = preg_replace('#\s*/\s*\(.*$#u', '', $name) ?? $name;
        $kopf = explode(':', $kopf, 2)[0];
        $kopf = preg_replace('/\([^)]*\)/u', '', $kopf) ?? $kopf;

        return trim(preg_replace('/\s+/u', ' ', $kopf) ?? $kopf);
    }

    /**
     * @param  array{geschwister: list<string>, lexikalisch: list<string>, semantisch: list<string>}  $q
     * @return list<string>
     */
    private function liste(array $q, string $wg): array
    {
        $vereint = array_values(array_unique([...$q['geschwister'], ...$q['lexikalisch'], ...$q['semantisch']]));
        $vereint = array_values(array_diff($vereint, ['neutral']));
        if ($vereint === []) {
            // keine Quelle trägt: die häufigsten Kern-Anker der Warengruppe (bisher: die alphabetisch ersten 120 des ganzen Vokabulars)
            $vereint = self::sortiert($this->haeufigInWg($wg));
        }
        if ($vereint === []) {
            // Warengruppe ohne Mappings (neu oder unbesetzt): das Vokabular wie bisher, aber nur aus den passenden Kategorien
            $vereint = $this->passend(DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
                ->where('slug', '!=', 'neutral')->orderBy('slug')->pluck('slug')->all(), $wg);
        }

        return array_values(array_unique([...array_slice($vereint, 0, self::MAX), 'neutral']));
    }

    /** @return list<string> Kern-Anker der sichtbaren Geschwister (gleiche Hauptzutat), häufigste zuerst, dann nach Slug */
    private function geschwister(FoodAlchemistGp $gp, ?Team $team): array
    {
        $slug = trim((string) $gp->main_ingredient_slug);
        if ($slug === '') {
            return [];
        }
        $q = DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 'm.gp_id')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('g.main_ingredient_slug', $slug)->where('g.id', '!=', $gp->id)
            ->whereNull('g.deleted_at')->whereNull('g.merged_into_id')->whereNull('m.deleted_at')->whereNull('a.deleted_at')
            ->where('m.role', 'kern')->where('a.slug', '!=', 'neutral');
        if ($team !== null) {
            $q->whereIn('g.id', FoodAlchemistGp::visibleToTeam($team)->select('id')->toBase());
        }

        return $q->groupBy('a.slug')->selectRaw('a.slug AS slug, COUNT(*) AS n')
            ->orderByDesc('n')->orderBy('a.slug')->limit(self::GESCHWISTER)->pluck('slug')->all();
    }

    /** @return list<string> die {@see MAX} häufigsten Kern-Anker der GPs dieser Warengruppe */
    private function haeufigInWg(string $wg): array
    {
        return DB::table('foodalchemist_gp_anchor_mappings AS m')->join('foodalchemist_gps AS g', 'g.id', '=', 'm.gp_id')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('g.commodity_group_code', $wg)->whereNull('g.deleted_at')->whereNull('m.deleted_at')->whereNull('a.deleted_at')
            ->where('m.role', 'kern')->where('a.slug', '!=', 'neutral')
            ->groupBy('a.slug')->selectRaw('a.slug AS slug, COUNT(*) AS n')->orderByDesc('n')->orderBy('a.slug')
            ->limit(self::MAX)->pluck('slug')->all();
    }

    /**
     * Wort-Treffer im Anzeigenamen der Anker: jedes Wort des Kopfes gegen jedes Wort des Anker-Namens, gleich oder als Teil eines
     * Kompositums (Rostbratwurst → Bratwurst, Buttermilchdessert → Buttermilch). Anders als der Wortindex des Pairings zeigt ein Wort
     * auf ALLE Anker, die es tragen („Paprika" → alle Paprika-Anker), nicht auf einen. Höchstens {@see SEMANTISCH}: das längere Wort zuerst, dann der Grundanker.
     *
     * @return list<string>
     */
    private function woerter(string $text): array
    {
        $pairing = app(PairingService::class);
        $gpWoerter = array_values(array_filter(explode(' ', trim($pairing->fold($text))), fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, self::STOPP, true)));
        if ($gpWoerter === []) {
            return [];
        }
        self::$index ??= DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')->where('slug', '!=', 'neutral')
            ->orderBy('slug')->get(['slug', 'display_de'])
            ->map(fn ($a) => [(string) $a->slug, array_values(array_filter(explode(' ', trim($pairing->fold((string) $a->display_de))), fn ($w) => mb_strlen($w) >= 4)), mb_strlen((string) $a->display_de)])
            ->all();
        $treffer = [];                                               // slug => [-Länge des Treffers, Art (0 gleich, 1 Kompositum), Namenslänge]
        foreach (self::$index as [$slug, $ankerWoerter, $laenge]) {
            foreach ($gpWoerter as $w) {
                foreach ($ankerWoerter as $d) {
                    $art = $w === $d ? 0 : (mb_strlen($d) >= 5 && (str_starts_with($w, $d) || str_ends_with($w, $d)) ? 1 : null);
                    $rang = [-mb_strlen($d), $art, $laenge];         // das spezifischere Wort zuerst: Tomatensauce → Tomate vor Sauce
                    if ($art !== null && (! isset($treffer[$slug]) || $treffer[$slug] > $rang)) {
                        $treffer[$slug] = $rang;
                    }
                }
            }
        }
        uksort($treffer, fn ($a, $b) => [...$treffer[$a], $a] <=> [...$treffer[$b], $b]);

        return array_slice(array_keys($treffer), 0, self::SEMANTISCH);
    }

    /** @return list<string> Score-Reihenfolge; leer ohne Provider */
    private function semantisch(string $frage, int $limit): array
    {
        try {
            return app(KnowledgeEmbeddingService::class)->searchAnkerSlugs($frage, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Nur Anker aus Kategorien, die zur Warengruppe passen. Ein Eintrag passt auf die Kategorie oder einen Unterkategorie-Pfad
     * („Protein/Fisch"). Warengruppe ohne Eintrag: alles.
     *
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function passend(array $slugs, string $wg, bool $sortieren = true): array
    {
        $erlaubt = (array) (config('foodalchemist.gp_anker_vorauswahl.kategorien_je_wg')[$wg] ?? []);
        if ($erlaubt !== [] && $slugs !== []) {
            $kat = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('slug', $slugs)->whereNull('deleted_at')
                ->get(['slug', 'category', 'subcategory'])->keyBy('slug');
            $slugs = array_values(array_filter($slugs, function ($s) use ($kat, $erlaubt) {
                $a = $kat->get($s);
                foreach ($a === null ? [] : $erlaubt as $e) {
                    if ((string) $a->category === $e || str_starts_with((string) $a->subcategory, $e.'/') || (string) $a->subcategory === $e) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return $sortieren ? self::sortiert($slugs) : array_values(array_unique($slugs));
    }

    /** @param list<string> $slugs @return list<string> */
    private static function sortiert(array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        sort($slugs, SORT_STRING);

        return $slugs;
    }
}
