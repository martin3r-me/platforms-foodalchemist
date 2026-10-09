<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Spec 80 G1 — Grundprodukt-Zuordnung beim ANLEGEN nach Regelwerk richtigstellen, statt es der KI-Prüfung
 * zu überlassen. Gemessen auf demo: §2 (Schnittform statt Rohform, 36 Befunde) und §10/§5 (Bio ohne
 * Bio-Vorgabe, 24) entstehen in der GP-Zuordnung nach der KI und kamen erst über die teure Prüfung ans
 * Licht — die Heilung konnte sie bis Paket 5 nicht einmal korrigieren.
 *
 *  · §10 Bio: GP ist Bio, der Lauf verlangt kein Bio → konventionelles Gegenstück gleichen Namens.
 *  · §2 Rohform: frisches GP mit Verarbeitungs-Suffix aus den §2-Regeln („Wuerfel 5 mm", „gehackt") →
 *    Gegenstück ohne Suffix, die Verarbeitung wandert in die Zeilen-Notiz. Nur Zustand `frisch`: was trocken,
 *    TK oder konserviert zugekauft wird, ist industriell verarbeitet (F2.1 Convenience) — so bleibt auch
 *    „Pfeffer schwarz: trocken, gemahlen" (§5 F2.3) unangetastet. Kein Tausch bei `voll_convenience`.
 *
 * Getauscht wird nur auf ein sichtbares, freigegebenes Nicht-Platzhalter-GP. Gibt es keins, bleibt die
 * Zuordnung und die deterministische Prüfung meldet den Befund (Teil G1, RecipeConformanceAdapter).
 */
final class GpKorrektur
{
    private const SPALTEN = ['id', 'name', 'main_ingredient_slug', 'condition', 'processing', 'form', 'bio', 'team_id'];

    /**
     * @param  array<string, mixed>  $parameter  Lauf-Parameter (bio_pref, bio, convenience)
     * @return array{gp_id: int, notiz: ?string, gruende: list<string>}|null  null = nichts zu ändern
     */
    public static function korrigiere(Team $team, int $gpId, array $parameter): ?array
    {
        $gp = self::lade($gpId);
        if ($gp === null) {
            return null;
        }
        $gruende = [];
        $notiz = null;

        $bioGewollt = ($parameter['bio_pref'] ?? null) === 'bio' || ($parameter['bio'] ?? false) === true;
        if (! $bioGewollt && self::istBio($gp)) {
            $ersatz = self::gegenstueck($team, $gp, fn (FoodAlchemistGp $k) => ! self::istBio($k)
                && self::attributeOhneBio($k) === self::attributeOhneBio($gp)
                && mb_strtolower((string) $k->condition) === mb_strtolower((string) $gp->condition));
            if ($ersatz !== null) {
                $gruende[] = "§10 Bio ohne Bio-Vorgabe: „{$gp->name}“ → „{$ersatz->name}“";
                $gp = $ersatz;
            }
        }

        if (($parameter['convenience'] ?? null) !== 'voll_convenience' && self::istFrisch($gp)
            && ($suffix = self::verarbeitungIn($gp)) !== null) {
            $ersatz = self::gegenstueck($team, $gp, fn (FoodAlchemistGp $k) => self::istFrisch($k)
                && self::istBio($k) === self::istBio($gp) && self::verarbeitungIn($k) === null);
            if ($ersatz !== null) {
                $gruende[] = "§2 Rohform statt „{$suffix}“: „{$gp->name}“ → „{$ersatz->name}“";
                $notiz = $suffix;
                $gp = $ersatz;
            }
        }

        return $gruende === [] ? null : ['gp_id' => (int) $gp->id, 'notiz' => $notiz, 'gruende' => $gruende];
    }

    /**
     * Verarbeitungs-Suffixe aus den §2-Regeln (Spec 81, Einstellungen › Regeln): Schnittformen + weitere Küchen-
     * Verarbeitung. Schreibweise wie in der Regel (für Notiz und Meldung); verglichen wird tolerant.
     *
     * @return list<string>
     */
    public static function verarbeitungsSuffixe(): array
    {
        $out = [];
        foreach (['basisrezept.2.schnittform', 'basisrezept.2.verarbeitung_weitere'] as $schluessel) {
            foreach ((array) (\Platform\FoodAlchemist\Services\Regeln\RegelBuch::per($schluessel)?->params['tokens'] ?? []) as $t) {
                $out[] = (string) $t;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Das erste §2-Suffix in den GP-Feldern Verarbeitung/Form, sonst im Attribut-Teil des Namens (nach dem
     * Doppelpunkt). Die Felder gewinnen; der Name ist Rückfall, weil sie im Bestand meist leer sind
     * (demo 09.10.: Verarbeitung bei 210 von 6.950 freigegebenen GPs, Form bei 3 — „Wuerfel 5 mm" steht im Namen).
     */
    public static function verarbeitungIn(FoodAlchemistGp $gp): ?string
    {
        $attr = RezeptTypVokabular::norm(trim((string) $gp->processing . ' ' . (string) $gp->form . ', ' . self::attribute($gp), ' ,'));
        if ($attr === '') {
            return null;
        }
        foreach (self::verarbeitungsSuffixe() as $suffix) {
            $s = RezeptTypVokabular::norm($suffix);
            // Auch innerhalb eines Wortes: im Feld steht „gewürfelt", im Dossier „würfel".
            if ($s !== '' && str_contains($attr, $s)) {
                return $suffix;
            }
        }

        return null;
    }

    public static function istBio(FoodAlchemistGp $gp): bool
    {
        if (mb_strtolower((string) $gp->bio) === 'bio') {
            return true;
        }
        foreach (\Platform\FoodAlchemist\Services\Regeln\RegelBuch::liste('basisrezept.10.bio') as $wort) {
            if (\Platform\FoodAlchemist\Services\Regeln\RegelText::hatWort(self::attribute($gp), $wort)) {
                return true;
            }
        }

        return false;
    }

    private static function istFrisch(FoodAlchemistGp $gp): bool
    {
        return mb_strtolower((string) $gp->condition) === 'frisch';
    }

    private static function attribute(FoodAlchemistGp $gp): string
    {
        $name = (string) $gp->name;

        return str_contains($name, ':') ? trim((string) substr($name, strpos($name, ':') + 1)) : '';
    }

    private static function attributeOhneBio(FoodAlchemistGp $gp): string
    {
        return trim((string) preg_replace('/\s*,?\s*\bbio\b\s*,?/iu', ',', self::attribute($gp)), " ,");
    }

    private static function produkt(FoodAlchemistGp $gp): string
    {
        return trim((string) strstr((string) $gp->name . ':', ':', true));
    }

    private static function lade(int $id): ?FoodAlchemistGp
    {
        return FoodAlchemistGp::query()->whereKey($id)->first(self::SPALTEN);
    }

    /**
     * Freigegebenes, sichtbares GP mit gleichem Produktnamen, das `$passt` erfüllt. Bei mehreren: eines mit
     * „ganz" zuerst, dann der kürzeste Name (am wenigsten Zusatz).
     *
     * @param  callable(FoodAlchemistGp): bool  $passt
     */
    private static function gegenstueck(Team $team, FoodAlchemistGp $gp, callable $passt): ?FoodAlchemistGp
    {
        // Gleiche Hauptzutat über das Feld (fast immer gepflegt); ohne Feld über den Produktnamen vor dem Doppelpunkt.
        $slug = trim((string) $gp->main_ingredient_slug);
        $produkt = self::produkt($gp);
        if ($slug === '' && $produkt === '') {
            return null;
        }
        $kandidaten = FoodAlchemistGp::query()->visibleToTeam($team)
            ->where('status', 'approved')->where('is_platzhalter', false)
            ->where('id', '!=', $gp->id)
            ->when($slug !== '', fn ($q) => $q->where('main_ingredient_slug', $slug),
                fn ($q) => $q->where('name', 'like', $produkt . ':%'))
            ->limit(40)->get(self::SPALTEN)
            ->filter(fn (FoodAlchemistGp $k) => ($slug !== '' || mb_strtolower(self::produkt($k)) === mb_strtolower($produkt)) && $passt($k))
            ->sortBy(fn (FoodAlchemistGp $k) => [str_contains(mb_strtolower($k->name), 'ganz') ? 0 : 1, mb_strlen($k->name)])
            ->values();

        return $kandidaten->first();
    }
}
