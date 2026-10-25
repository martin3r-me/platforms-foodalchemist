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
        $pool = $this->recall($team, $la, $felder, $profil);
        $artikelTexte = [$la->designation, $la->regulated_name, $la->marketing_name, $la->ingredients_supplier];
        $artikelBio = (bool) $la->is_organic;
        $text = mb_strtolower(implode(' | ', array_filter(array_map('trim', array_map('strval', [$la->designation, $la->regulated_name, $la->marketing_name])))));
        $slug = $this->naming->slugify((string) ($felder['hauptzutat'] ?? ''));

        $out = [];
        foreach ($pool as $c) {
            /** @var FoodAlchemistGp $gp */
            $gp = $c['gp'];
            if ($this->sperrgrund($gp, $felder, $profil) !== null) {
                continue;
            }
            $score = $c['score'];
            if ($slug !== '' && (string) $gp->main_ingredient_slug === $slug) {
                $score = min(1.0, $score + 0.05);   // Hauptzutat gleich: weicher Bonus
            }
            $out[] = ['gp' => $gp, 'score' => round($score, 4), 'methoden' => array_values(array_unique($c['methoden'])), 'exakt' => $c['exakt'],
                'bonus' => $this->bonus($gp, $text, $felder)];
        }
        usort($out, fn ($a, $b) => [$b['exakt'], $b['score'] + $b['bonus'], $a['gp']->id] <=> [$a['exakt'], $a['score'] + $a['bonus'], $b['gp']->id]);
        $out = array_slice($out, 0, max($limit, 1) * 2);

        // S4 + S5 nur für die Spitze
        foreach ($out as &$c) {
            $c = $this->bewerte($team, $la, $c, $felder, $artikelTexte, $artikelBio);
        }
        unset($c);
        usort($out, fn ($a, $b) => [$b['exakt'], $b['rang'], $a['gp']->id] <=> [$a['exakt'], $a['rang'], $b['gp']->id]);

        return array_slice($out, 0, $limit);
    }

    /**
     * Diagnose für genau EIN GP (Eval „warum nicht?“): im Recall? Welche Methode/Score? Harter Filter? Diff, Guard, Rang, Position in der Kandidatenliste.
     *
     * @param  array<string,mixed>  $felder
     */
    public function diagnose(Team $team, FoodAlchemistSupplierItem $la, array $felder, int $gpId, string $profil = 'gleich'): string
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            return "GP {$gpId}: für das Team nicht sichtbar.";
        }
        $pool = $this->recall($team, $la, $felder, $profil);
        $teile = [];
        if (! isset($pool[$gpId])) {
            $teile[] = 'NICHT im Recall (weder exakte Brücke, Namens-Fuzzy, Hybrid, Hauptzutat noch Wortteil)';
        } else {
            $teile[] = 'im Recall: ' . implode('+', array_unique($pool[$gpId]['methoden'])) . ' (Score ' . round($pool[$gpId]['score'], 2) . ')';
        }
        $grund = $this->sperrgrund($gp, $felder, $profil);
        $teile[] = $grund === null ? 'harte Filter: ok' : 'harter Filter SPERRT: ' . $grund;
        $c = ['gp' => $gp, 'score' => (float) ($pool[$gpId]['score'] ?? 0.0), 'methoden' => $pool[$gpId]['methoden'] ?? [], 'exakt' => (bool) ($pool[$gpId]['exakt'] ?? false),
            'bonus' => 0.0];
        $c = $this->bewerte($team, $la, $c, $felder, [$la->designation, $la->regulated_name, $la->marketing_name, $la->ingredients_supplier], (bool) $la->is_organic);
        $teile[] = 'Diff: ' . ($c['diff']['abweichungen'] === [] ? 'keine Abweichung' : implode('; ', $c['diff']['abweichungen']));
        $teile[] = 'Guard: ' . ($c['guard'] === [] ? 'ok' : implode('; ', $c['guard']));
        $liste = $this->kandidaten($team, $la, $felder, $profil, 5);
        $pos = null;
        foreach ($liste as $i => $k) {
            if ((int) $k['gp']->id === $gpId) {
                $pos = $i + 1;
            }
        }
        $teile[] = 'Position in den Kandidaten: ' . ($pos ?? 'nicht in den Top ' . count($liste)) . ' (Rang ' . $c['rang'] . ')';

        return implode(' | ', $teile);
    }

    /**
     * S4 + S5 für einen Kandidaten: Merkmals-Diff, Guard-Vorschau und deterministischer Rang (Score + Textüberlapp, abzüglich je abweichendem Merkmal
     * und bei Guard-Widerspruch).
     *
     * @param  array<string,mixed>  $c  @param array<string,mixed> $felder  @param list<?string> $artikelTexte
     * @return array<string,mixed>
     */
    private function bewerte(Team $team, FoodAlchemistSupplierItem $la, array $c, array $felder, array $artikelTexte, bool $artikelBio): array
    {
        $c['diff'] = $this->diff->vergleiche($felder, $artikelTexte, $artikelBio, $c['gp']);
        try {
            $c['guard'] = $this->lead->guardAbweichungen($team, $c['gp'], (int) $la->id);
        } catch (\Throwable) {
            $c['guard'] = [];   // Artikel nicht sichtbar o. Ä.: keine Aussage → kein Veto
        }
        $c['rang'] = round($c['score'] + ($c['bonus'] ?? 0.0) - 0.15 * count($c['diff']['abweichungen']) - ($c['guard'] !== [] ? 0.5 : 0.0), 4);

        return $c;
    }

    /**
     * S2 — Recall: Vereinigung aller Quellen, je GP bester Score + beteiligte Methoden.
     *
     * @param  array<string,mixed>  $felder
     * @return array<int, array{gp: FoodAlchemistGp, score: float, methoden: list<string>, exakt: bool}>
     */
    private function recall(Team $team, FoodAlchemistSupplierItem $la, array $felder, string $profil = 'gleich'): array
    {
        if ($profil === 'nonfood') {
            return $this->recallNonFood($team, $la, $felder);
        }
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
        // Fantasiename (zusatz) exakt im Artikel: starkes Merkmal (§6.6) — ein bereits angelegtes GP mit demselben Klammer-Zusatz wird sicher gefunden
        $zusatz = trim((string) ($felder['zusatz'] ?? ''));
        if ($zusatz !== '') {
            $treffer = FoodAlchemistGp::visibleToTeam($team)->whereIn('status', ['approved', 'tentative'])
                ->where(fn ($q) => $q->whereRaw('LOWER(zusatz) = ?', [mb_strtolower($zusatz)])->orWhere('name', 'like', '%(' . $zusatz . ')%'))
                ->orderBy('id')->limit(10)->get();
            foreach ($treffer as $gp) {
                $merke($gp, 0.90, 'zusatz');
            }
        }
        // Wortteil-Treffer: Komposita enthalten das Kernwort („Sonnenblumenbolognese“ ⊃ „Bolognese“) — Namens-Fuzzy und Terminologie sehen sie nicht
        foreach ($this->kernwoerter($la, $felder) as $wort) {
            $treffer = FoodAlchemistGp::visibleToTeam($team)->whereIn('status', ['approved', 'tentative'])
                ->where('name', 'like', '%' . $wort . '%')->orderBy('id')->limit(15)->get();
            foreach ($treffer as $gp) {
                $merke($gp, 0.55, 'wortteil');
            }
        }

        return $pool;
    }

    /**
     * Recall für Non-Food-Artikel (WG 16, §8.18): Hybrid/Terminologie kennen keine Non-Food-GPs, deshalb eigener Weg — exakte Brücke plus Wortüberlapp
     * zwischen Artikeltext und ALLEN sichtbaren Non-Food-GPs (Komposita zählen: „Salatschale“ ⊃ „Schale“).
     *
     * @param  array<string,mixed>  $felder
     * @return array<int, array{gp: FoodAlchemistGp, score: float, methoden: list<string>, exakt: bool}>
     */
    private function recallNonFood(Team $team, FoodAlchemistSupplierItem $la, array $felder): array
    {
        $pool = [];
        foreach ($this->match->exakteBruecken($la, $team) as $t) {
            $pool[(int) $t['gp']->id] = ['gp' => $t['gp'], 'score' => 1.0, 'methoden' => [(string) $t['methode']], 'exakt' => true];
        }
        $text = mb_strtolower(implode(' ', array_filter([(string) $la->designation, (string) $la->marketing_name, (string) ($felder['hauptzutat'] ?? ''), (string) ($felder['processing'] ?? '')])));
        $artikel = $this->woerter($text);
        if ($artikel === []) {
            return $pool;
        }
        foreach (FoodAlchemistGp::visibleToTeam($team)->nonFood()->whereIn('status', ['approved', 'tentative'])->limit(5000)->get() as $gp) {
            if (isset($pool[(int) $gp->id])) {
                continue;
            }
            $gpWorte = $this->woerter(mb_strtolower((string) $gp->name));
            if ($gpWorte === []) {
                continue;
            }
            $treffer = 0;
            foreach ($gpWorte as $w) {
                foreach ($artikel as $a) {
                    if ($a === $w || (mb_strlen($w) >= 5 && str_contains($a, $w)) || (mb_strlen($a) >= 5 && str_contains($w, $a))) {
                        $treffer++;

                        break;
                    }
                }
            }
            $score = 0.9 * $treffer / count($gpWorte);
            if ($score >= 0.30) {
                $pool[(int) $gp->id] = ['gp' => $gp, 'score' => $score, 'methoden' => ['nonfood_wort'], 'exakt' => false];
            }
        }

        return $pool;
    }

    /** @return list<string> Wörter (Buchstaben ≥ 3 Zeichen) ohne Füllwörter */
    private function woerter(string $text): array
    {
        $stopp = ['mit', 'und', 'für', 'der', 'die', 'das', 'pro', 'stück'];

        return array_values(array_unique(array_filter(preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, $stopp, true))));
    }

    /**
     * Kernwörter für den Wortteil-Recall: Hauptzutat und Geschmack (≥ 5 Zeichen) sowie längere Bezeichnungswörter (≥ 7), nur Buchstaben.
     *
     * @param  array<string,mixed>  $felder
     * @return list<string>
     */
    private function kernwoerter(FoodAlchemistSupplierItem $la, array $felder): array
    {
        $stopp = ['frisch', 'tiefgekühlt', 'tiefgekuehlt', 'gewürfelt', 'geschnitten', 'vorgegart', 'verzehrfertig', 'portion', 'beutel'];
        $quellen = [(string) ($felder['hauptzutat'] ?? ''), (string) ($felder['flavor'] ?? ''), (string) $la->designation];
        $out = [];
        foreach ($quellen as $i => $q) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                if (mb_strlen($w) >= ($i < 2 ? 5 : 7) && ! in_array($w, $stopp, true)) {
                    $out[$w] = true;
                }
            }
        }

        return array_slice(array_keys($out), 0, 6);
    }

    /**
     * Textüberlapp GP-Name ↔ Artikeltext als Vorrang-Bonus (0 … 0,3): gemeinsame Merkmalswörter (vegan, bio, Sorte …) und Kompositum-Treffer der Hauptzutat.
     *
     * @param  array<string,mixed>  $felder
     */
    private function bonus(FoodAlchemistGp $gp, string $artikelText, array $felder): float
    {
        $zust = array_map('mb_strtolower', GpNamingService::ZUSTAND_VOCAB);
        $tokens = array_values(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower((string) $gp->name), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, $zust, true) && ! in_array($t, ['pro', 'stück', 'ganz', 'mit', 'und'], true)));
        $b = 0.0;
        if ($tokens !== []) {
            $treffer = count(array_filter($tokens, fn ($t) => str_contains($artikelText, $t)));
            $b += 0.2 * $treffer / count($tokens);
        }
        $kern = mb_strtolower(trim((string) ($felder['hauptzutat'] ?? '')));
        if (mb_strlen($kern) >= 5 && str_contains(mb_strtolower((string) $gp->name), $kern)) {
            $b += 0.1;
        }

        return $b;
    }

    /**
     * S3 — harte Filter. Liefert den Sperrgrund oder null, wenn das GP zulässig ist.
     *
     * @param  array<string,mixed>  $felder
     */
    private function sperrgrund(FoodAlchemistGp $gp, array $felder, string $profil): ?string
    {
        $status = $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status;
        if (! in_array($status, ['approved', 'tentative'], true) || $gp->is_derivat || $gp->is_platzhalter) {
            return 'Status/Derivat/Platzhalter (' . $status . ')';
        }
        if ($profil === 'nonfood') {
            return $gp->istNonFood() ? null : 'kein Non-Food-GP (Non-Food-Artikel nur gegen WG 16)';   // kein Zustand, keine Warengruppen-Prüfung (§8.18)
        }
        if ((string) $gp->commodity_group_code === '16') {
            return 'Non-Food-GP (WG 16)';   // Food-Artikel gehört nie dorthin
        }
        if ($profil === 'ersatz') {
            return null;
        }
        $awg = (string) ($felder['commodity_group_code'] ?? '');
        if ($awg !== '' && (string) $gp->commodity_group_code !== '' && $awg !== (string) $gp->commodity_group_code) {
            return 'Warengruppe: Artikel ' . $awg . ' ≠ GP ' . $gp->commodity_group_code;
        }
        $basis = ['frisch', 'TK', 'trocken', 'konserviert'];
        $az = $this->naming->normalisiereZustand($felder['condition'] ?? null);
        $gz = $this->naming->normalisiereZustand($gp->condition);
        if (! in_array($gz, $basis, true)) {
            // Bestands-GP ohne gepflegtes Zustandsfeld: Zustand aus dem Namen (§6-Schema) ableiten, nie raten — ohne Treffer bleibt er offen
            $gz = $this->naming->normalisiereZustand($this->naming->felderAusName((string) $gp->name)['condition'] ?? null);
        }
        if (in_array($az, $basis, true) && in_array($gz, $basis, true) && $az !== $gz) {
            return 'Zustand: Artikel ' . $az . ' ≠ GP ' . $gz;
        }

        return null;
    }
}
