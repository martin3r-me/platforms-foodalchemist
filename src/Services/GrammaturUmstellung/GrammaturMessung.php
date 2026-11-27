<?php

namespace Platform\FoodAlchemist\Services\GrammaturUmstellung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;

/**
 * Plan 98 B: misst je Darreichung die Werte, die die Umstellung nicht stillschweigend ändern darf — über den echten Code
 * (grammJeEinheit, gespeicherte Preise, produktionsAnteil), nie über eine Nachrechnung. Toleranzen vom Kurator (22.11.2026).
 */
final class GrammaturMessung
{
    public const TOL_GRAMM = 0.1;

    public const TOL_EURO = 0.005;

    public const TOL_PRODUKTION_REL = 0.0001;   // 0,01 %

    public const PORTIONEN = 10;

    /** Master 23.11.: ab hier gilt ein EK-Sprung in Phase 0 als groß (er hält bei vielen davon vor dem Apply an) */
    public const SPRUNG_WARN_PROZENT = 20.0;

    public function __construct(
        private DarreichungService $darreichungen,
        private RecipeRecomputeService $recompute,
    ) {}

    /**
     * Rezept-Kennzahlen für den Report: B (Netto-Summe der Bestandteile), Y (Kalkulations-Ertrag), fester Ertrag, Zutaten.
     *
     * `ohne_gewicht`: Bestandteile mit Menge, aber ohne Gramm (z. B. 0,5 Stück Ei ohne Stückgewicht). Das Komponentenmodell der
     * Darreichung rechnet nur über Gramm — solche Zeilen fielen bei der Umstellung aus EK und Produktion heraus (Trockenlauf io 23.11.,
     * R149). Nicht gezählt: optionale Zeilen und „q. s.“ (die wiegen bewusst 0 g).
     *
     * @return array{b_g: float, y_g: ?float, fester_ertrag: bool, zutaten: int, unbepreist: int, portionen: ?int, kopf_g: ?float, ek_per_kg: ?float, ohne_gewicht: list<string>}
     */
    public function rezept(FoodAlchemistRecipe $r, Team $team): array
    {
        $zeilen = $this->recompute->zeilenKostenUndMassen($r, $team);
        $zutaten = $r->ingredients->keyBy('id');
        $ohneGewicht = [];
        foreach ($zeilen as $id => $z) {
            $zutat = $zutaten->get($id);
            if ($zutat !== null && $z['masse_g'] <= 0 && ! $zutat->is_optional && $zutat->unit?->slug !== 'qs' && (float) $zutat->quantity > 0) {
                $ohneGewicht[] = trim((string) ($zutat->display_name ?: $zutat->raw_text)).' ('.rtrim(rtrim(number_format((float) $zutat->quantity, 3, ',', ''), '0'), ',')
                    .' '.($zutat->unit?->display_de ?? $zutat->unit?->slug ?? '?').')';
            }
        }
        $y = $r->yield_kg_manual !== null ? (float) $r->yield_kg_manual : ($r->yield_kg !== null ? (float) $r->yield_kg : null);

        return [
            'b_g' => round(array_sum(array_column($zeilen, 'masse_g')), 2),
            'y_g' => $y !== null && $y > 0 ? round($y * 1000, 2) : null,
            'fester_ertrag' => $r->yield_kg_manual !== null,
            'zutaten' => count($zeilen),
            'unbepreist' => count(array_filter($zeilen, fn ($z) => $z['kosten'] === null)),
            // 98 · 3.5 6b/6c: Rezept-Spiegelspalten entfallen — fehlt die Spalte, ist der Wert leer (Portionszahl leer = 1)
            'portionen' => isset($r->getAttributes()['sales_unit_count']) ? (int) $r->getAttributes()['sales_unit_count'] : null,
            'kopf_g' => isset($r->getAttributes()['sales_quantity_per_unit_g']) ? (float) $r->getAttributes()['sales_quantity_per_unit_g'] : null,
            'ek_per_kg' => $r->ek_per_kg_eur !== null ? round((float) $r->ek_per_kg_eur, 6) : null,
            'ohne_gewicht' => $ohneGewicht,
        ];
    }

    /** @return array<string, mixed> */
    public function darreichung(FoodAlchemistRecipe $r, FoodAlchemistRecipeDarreichung $d, Team $team): array
    {
        $zahl = fn ($v, int $n = 4) => $v === null ? null : round((float) $v, $n);
        $anteil = $this->darreichungen->produktionsAnteil($r, $d, self::PORTIONEN, $team);
        $jeZutat = $anteil['je_zutat'];
        if ($jeZutat === null) {   // freie Grammatur: alle Zutaten im selben Verhältnis
            $jeZutat = array_fill_keys(array_keys($this->recompute->zeilenKostenUndMassen($r, $team)), $anteil['ansaetze']);
        }
        ksort($jeZutat);

        return [
            'darreichung_id' => (int) $d->id,
            'standard' => (bool) $d->is_standard,
            'gramm' => $zahl($this->darreichungen->grammJeEinheit($d, $r), 2),
            'ek_portion' => $zahl($d->ek_portion),
            'calculated_sales_net' => $zahl($d->calculated_sales_net),
            'sales_net' => $zahl($d->sales_net),
            'price_mode' => $d->price_mode,
            'ansaetze' => round((float) $anteil['ansaetze'], 8),
            'je_zutat' => array_map(fn ($a) => round((float) $a, 8), $jeZutat),
        ];
    }

    /**
     * Unterschiede alt → neu außerhalb der Toleranz. Leer = „gleich“.
     *
     * @return list<string>
     */
    public static function unterschiede(array $alt, array $neu): array
    {
        $out = [];
        $euro = fn (?float $a, ?float $b) => ($a === null) !== ($b === null) || ($a !== null && abs($a - $b) > self::TOL_EURO);
        if (($alt['gramm'] === null) !== ($neu['gramm'] === null) || ($alt['gramm'] !== null && abs($alt['gramm'] - $neu['gramm']) > self::TOL_GRAMM)) {
            $out[] = 'gramm';
        }
        foreach (['ek_portion', 'calculated_sales_net', 'sales_net'] as $f) {
            if ($euro($alt[$f], $neu[$f])) {
                $out[] = $f;
            }
        }
        if ($alt['price_mode'] !== $neu['price_mode']) {
            $out[] = 'price_mode';
        }
        $ids = array_unique([...array_keys($alt['je_zutat']), ...array_keys($neu['je_zutat'])]);
        foreach ($ids as $id) {
            $a = $alt['je_zutat'][$id] ?? 0.0;
            $b = $neu['je_zutat'][$id] ?? 0.0;
            if (abs($a - $b) > max(1e-9, abs($a) * self::TOL_PRODUKTION_REL)) {
                $out[] = 'produktion';
                break;
            }
        }

        return $out;
    }

    /**
     * Phase 0 (Master 23.11.): EK-Änderungen durch das Aufholen veralteter Caches, je Tabelle und team_id — Zahl der Rezepte mit
     * Änderung, größter Sprung in % und dessen Rezept, Zahl der Rezepte ab {@see SPRUNG_WARN_PROZENT}. Eingabe je Tabelle:
     * Zeilen-Id → [team_id, recipe_id, ek]. Ein Sprung von 0/leer auf einen Wert zählt als Änderung ohne Prozent.
     *
     * @param  array<string, array<int, array{0: int|string|null, 1: int, 2: ?float}>>  $vorher
     * @param  array<string, array<int, array{0: int|string|null, 1: int, 2: ?float}>>  $nachher
     * @return array<string, array<string, array{rezepte: int, max_prozent: ?float, max_recipe_id: ?int, ab_warnschwelle: int}>>
     */
    public static function ekSpruenge(array $vorher, array $nachher): array
    {
        $out = [];
        foreach ($nachher as $tabelle => $zeilen) {
            $je = [];   // team → recipe_id → größter |%| (oder null = ohne Prozent)
            foreach ($zeilen as $id => [$team, $rid, $neu]) {
                $alt = $vorher[$tabelle][$id][2] ?? null;
                if (abs((float) $alt - (float) $neu) <= self::TOL_EURO) {   // leer und 0 gelten als gleich
                    continue;
                }
                $p = self::prozent($alt, $neu);
                $t = (string) ($team ?? '–');
                $bisher = $je[$t][$rid] ?? false;
                $je[$t][$rid] = $p === null ? ($bisher === false ? null : $bisher) : max(abs($p), (float) ($bisher ?? 0));
            }
            foreach ($je as $t => $rezepte) {
                $mit = array_filter($rezepte, fn ($p) => $p !== null);
                arsort($mit);
                $out[$tabelle][$t] = ['rezepte' => count($rezepte), 'max_prozent' => $mit === [] ? null : round((float) reset($mit), 2),
                    'max_recipe_id' => $mit === [] ? null : (int) array_key_first($mit),
                    'ab_warnschwelle' => count(array_filter($mit, fn ($p) => $p >= self::SPRUNG_WARN_PROZENT))];
            }
        }

        return $out;
    }

    /**
     * Master 23.11.: Darreichungen der Verwender (Eltern) außerhalb der umgestellten Rezepte müssen bleiben — Grammatur, EK, VK,
     * Preismodus und Produktion innerhalb der Toleranzen. Eingabe wie messen(): recipe_id → [rezept, darreichungen].
     *
     * @return list<string> Verstöße
     */
    public static function elternVerstoesse(array $alt, array $neu): array
    {
        $out = [];
        foreach ($alt as $rid => $r) {
            foreach ($r['darreichungen'] as $did => $a) {
                $n = $neu[$rid]['darreichungen'][$did] ?? null;
                $diff = $n === null ? ['fehlt'] : self::unterschiede($a, $n);
                if ($diff !== []) {
                    $out[] = "Verwender Rezept {$rid}, Darreichung {$did}: ".implode(', ', $diff).' änderte sich — Eltern dürfen sich durch die Umstellung nicht bewegen';
                }
            }
        }

        return $out;
    }

    /** Abweichung in % (neu gegen alt), für die Sortierung im Report; null, wenn alt fehlt oder 0 ist. */
    public static function prozent(?float $alt, ?float $neu): ?float
    {
        return $alt === null || $neu === null || abs($alt) < 1e-9 ? null : round(($neu - $alt) / $alt * 100, 2);
    }
}
