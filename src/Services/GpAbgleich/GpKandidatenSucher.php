<?php

namespace Platform\FoodAlchemist\Services\GpAbgleich;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\MatchService;

/**
 * S2–S5 des Abgleich-Dienstes: GP-Kandidaten für einen Lieferantenartikel (Entscheid Dominique 25.10.2026, Plan §2).
 *
 *  S2 Recall (ohne KI): Vereinigung aus exakter Brücke (EAN/Artikelnummer), Namens-Fuzzy ({@see MatchService}), Hybrid ({@see IngredientMatchService}:
 *     Terminologie/Alias + Qdrant) und struktureller Treffer (gleiche Hauptzutat). Die Hauptzutat ist ein WEICHES Kriterium (Oberbegriffe/Doppelnamen
 *     wie „Rapsöl / Pflanzenöl“ würden sonst den richtigen Treffer ausfiltern).
 *  S3 Harte Filter: Status approved/tentative, kein Derivat/Platzhalter, Warengruppe (nur wenn beide bekannt), Zustand (frisch/TK/trocken/konserviert, nur
 *     wenn beide bekannt), kein Non-Food-GP.
 *  S4 Merkmals-Diff ({@see MerkmalsDiff}).
 *  S5 Guard-Vorschau ({@see LeadLaService::guardAbweichungen()}): Kandidaten, bei denen der Artikel NICHT verknüpfbar wäre, sind markiert.
 *
 * Profil `ersatz` (PR B, Ersatz-Pool): ohne die harten Filter WG/Zustand — Ersatz darf eine andere Ware sein, der Diff bleibt als Hinweis.
 */
class GpKandidatenSucher
{
    public function __construct(
        private readonly MatchService $match,
        private readonly IngredientMatchService $ingredients,
        private readonly LeadLaService $lead,
        private readonly GpNamingService $naming,
        private readonly MerkmalsDiff $diff,
    ) {}

    /**
     * @param  array<string,mixed>  $felder  Felder aus {@see LaFelderService}
     * @return list<array{gp: FoodAlchemistGp, score: float, methoden: list<string>, rang: float, diff: array, guard: list<string>, exakt: bool}>
     */
    public function kandidaten(Team $team, FoodAlchemistSupplierItem $la, array $felder, string $profil = 'gleich', int $limit = 5): array
    {
        $pool = [];   // gp_id => ['gp','score','methoden','exakt']
        $merke = function (FoodAlchemistGp $gp, float $score, string $methode, bool $exakt = false) use (&$pool) {
            $k = (int) $gp->id;
            $pool[$k] ??= ['gp' => $gp, 'score' => 0.0, 'methoden' => [], 'exakt' => false];
            $pool[$k]['score'] = max($pool[$k]['score'], $score);
            $pool[$k]['methoden'][] = $methode;
            $pool[$k]['exakt'] = $pool[$k]['exakt'] || $exakt;
        };

        // exakte Brücken
        foreach ($this->match->exakteBruecken($la, $team) as $t) {
            $merke($t['gp'], 1.0, (string) $t['methode'], true);
        }
        // Namens-Fuzzy
        foreach ($this->match->nameKandidaten($la, $team, 12) as $t) {
            $merke($t['gp'], (float) $t['score'], (string) $t['methode']);
        }
        // Hybrid (Terminologie + Qdrant): Bezeichnung UND die abgeleitete Hauptzutat mit Geschmack als zweite Anfrage
        $anfragen = [trim((string) $la->designation)];
        $kern = trim(($felder['hauptzutat'] ?? '') . ' ' . ($felder['flavor'] ?? ''));
        if ($kern !== '') {
            $anfragen[] = $kern;
        }
        foreach (array_unique(array_filter($anfragen)) as $q) {
            foreach ($this->ingredients->candidatesFor($team, $q, null, 12) as $k) {
                if (($k['kind'] ?? null) !== 'gp' || (float) ($k['score'] ?? 0) < 0.50) {
                    continue;
                }
                $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) $k['id']);
                if ($gp !== null) {
                    $merke($gp, (float) $k['score'], 'hybrid_' . ($k['origin'] ?? 'lexical'));
                }
            }
        }
        // struktureller Treffer: gleiche Hauptzutat (weich)
        $slug = $this->naming->slugify((string) ($felder['hauptzutat'] ?? ''));
        if ($slug !== '') {
            foreach (FoodAlchemistGp::visibleToTeam($team)->where('main_ingredient_slug', $slug)->whereIn('status', ['approved', 'tentative'])->limit(15)->get() as $gp) {
                $merke($gp, 0.60, 'hauptzutat');
            }
        }

        $artikelTexte = [$la->designation, $la->regulated_name, $la->marketing_name, $la->ingredients_supplier];
        $artikelBio = (bool) $la->is_organic;
        $out = [];
        foreach ($pool as $c) {
            /** @var FoodAlchemistGp $gp */
            $gp = $c['gp'];
            if (! $this->zulaessig($gp, $felder, $profil)) {
                continue;
            }
            $score = $c['score'];
            if ($slug !== '' && (string) $gp->main_ingredient_slug === $slug) {
                $score = min(1.0, $score + 0.05);   // Hauptzutat gleich: weicher Bonus
            }
            $out[] = ['gp' => $gp, 'score' => round($score, 4), 'methoden' => array_values(array_unique($c['methoden'])), 'exakt' => $c['exakt']];
        }
        usort($out, fn ($a, $b) => [$b['exakt'], $b['score'], $a['gp']->id] <=> [$a['exakt'], $a['score'], $b['gp']->id]);
        $out = array_slice($out, 0, max($limit, 1) * 2);

        // S4 + S5 nur für die Spitze
        foreach ($out as &$c) {
            $c['diff'] = $this->diff->vergleiche($felder, $artikelTexte, $artikelBio, $c['gp']);
            try {
                $c['guard'] = $this->lead->guardAbweichungen($team, $c['gp'], (int) $la->id);
            } catch (\Throwable) {
                $c['guard'] = [];   // Artikel nicht sichtbar o. Ä.: keine Aussage → kein Veto
            }
            // deterministischer Rang: Score, abzüglich je abweichendem Merkmal und bei Guard-Widerspruch
            $c['rang'] = round($c['score'] - 0.15 * count($c['diff']['abweichungen']) - ($c['guard'] !== [] ? 0.5 : 0.0), 4);
        }
        unset($c);
        usort($out, fn ($a, $b) => [$b['exakt'], $b['rang'], $a['gp']->id] <=> [$a['exakt'], $a['rang'], $b['gp']->id]);

        return array_slice($out, 0, $limit);
    }

    /** S3 — harte Filter. @param array<string,mixed> $felder */
    private function zulaessig(FoodAlchemistGp $gp, array $felder, string $profil): bool
    {
        $status = $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status;
        if (! in_array($status, ['approved', 'tentative'], true) || $gp->is_derivat || $gp->is_platzhalter) {
            return false;
        }
        if ((string) $gp->commodity_group_code === '16') {
            return false;   // Non-Food-GP: ein Food-Artikel gehört nie dorthin
        }
        if ($profil === 'ersatz') {
            return true;
        }
        $awg = (string) ($felder['commodity_group_code'] ?? '');
        if ($awg !== '' && (string) $gp->commodity_group_code !== '' && $awg !== (string) $gp->commodity_group_code) {
            return false;
        }
        $basis = ['frisch', 'TK', 'trocken', 'konserviert'];
        $az = $this->naming->normalisiereZustand($felder['condition'] ?? null);
        $gz = $this->naming->normalisiereZustand($gp->condition);
        if (in_array($az, $basis, true) && in_array($gz, $basis, true) && $az !== $gz) {
            return false;
        }

        return true;
    }
}
