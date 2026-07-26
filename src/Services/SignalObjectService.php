<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Support\SignalCockpit;

/**
 * Spec 21 · Tranche P — die Brücke zwischen Signal und Objekt, in BEIDE Richtungen:
 *
 *  - `betroffene()`  Signal → Objekte („welche Rezepte/GPs stecken hinter diesem Befund?")
 *  - `signaleAmObjekt()` Objekt → Signale („was hat dieses Rezept sonst noch offen?")
 *
 * Die zweite Richtung ist das Kern-Feature des Signal-Panels (Spec 21 §7 Punkt 2): ein
 * Rezept kann gleichzeitig `vk_ek_teil` + `vk_anker_fehlt` + `rezept_mengen_luecke`
 * haben. Wer signal-zentrisch arbeitet, öffnet es dreimal; wer objekt-zentrisch
 * arbeitet, einmal. Deshalb wird hier nicht die Trefferliste jeder Metrik geladen,
 * sondern je Metrik EIN EXISTS gegen das eine Objekt gestellt
 * (`DataQualityService::trifftObjekt`).
 *
 * Read-only: dieser Service mutiert nichts, er löst nur auf.
 *
 * Warum eigener Service und nicht `SignalService`: die Auflösung braucht
 * `DataQualityService`, und der hängt selbst am `SignalService` (Emission) — eine
 * Injection dort wäre ein Container-Zyklus.
 */
class SignalObjectService
{
    /** Kappungsgrenze der Objekt-Liste im Panel — darüber wird ehrlich „… und n weitere" gemeldet. */
    public const PANEL_LIMIT = 300;

    public function __construct(private DataQualityService $dq)
    {
    }

    /**
     * Löst die betroffenen Objekte hinter einem Signal auf. Drei Quellen, in dieser
     * Reihenfolge: Lücken-Metrik (Live-Query, damit die Liste NIE veraltet), Payload-
     * Beispiele der Detektoren, Einzelobjekt-Referenz (`ref_type`/`ref_id`).
     *
     * @return array{items:list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>,total:int,gezeigt:int}
     */
    public function betroffene(Team $team, FoodAlchemistSignal $sig, int $limit = self::PANEL_LIMIT): array
    {
        $pl = is_array($sig->payload) ? $sig->payload : [];

        // Lücken-Metrik: exakt dieselbe Query wie der Zähler, als Liste (kein Payload-Abbild).
        if ($sig->source === 'data-quality' && ! empty($pl['metrik'])) {
            $items = $this->dq->betroffene($team, (string) $pl['metrik'], $limit);
            // total aus der Live-Query, nicht aus dem Payload: ein Fix zwischen Detektor-Lauf
            // und Panel-Öffnung soll die Zahl senken, nicht den alten Stand zeigen.
            $total = count($items) < $limit ? count($items) : $this->dq->countFor($team, (string) $pl['metrik']);

            return ['items' => $items, 'total' => $total, 'gezeigt' => count($items)];
        }

        // Detektor-Signale mit Beispielen im Payload (Struktur variiert je Detektor — best effort).
        if (! empty($pl['beispiele']) && is_array($pl['beispiele'])) {
            $items = [];
            foreach (array_slice($pl['beispiele'], 0, $limit) as $b) {
                if (is_array($b)) {
                    $id = (int) ($b['recipe_id'] ?? $b['id'] ?? 0);
                    $items[] = [
                        'kind' => isset($b['recipe_id']) ? 'recipe' : ($b['kind'] ?? 'text'),
                        'id' => $id,
                        'name' => (string) ($b['name'] ?? $b['label'] ?? ('#' . $id)),
                        'is_sales_recipe' => (bool) ($b['is_sales_recipe'] ?? true),
                    ];
                } else {
                    $items[] = ['kind' => 'text', 'id' => 0, 'name' => (string) $b, 'is_sales_recipe' => false];
                }
            }

            return ['items' => $items, 'total' => (int) ($pl['anzahl'] ?? count($pl['beispiele'])), 'gezeigt' => count($items)];
        }

        // Einzelobjekt-Signal (ref_type/ref_id).
        if ($sig->ref_type === 'recipe' && $sig->ref_id) {
            $r = FoodAlchemistRecipe::visibleToTeam($team)->find($sig->ref_id);
            if ($r !== null) {
                return ['items' => [['kind' => 'recipe', 'id' => (int) $r->id, 'name' => (string) $r->name,
                    'is_sales_recipe' => (bool) $r->is_sales_recipe]], 'total' => 1, 'gezeigt' => 1];
            }
        }
        if ($sig->ref_type === 'gp' && $sig->ref_id) {
            $g = FoodAlchemistGp::visibleToTeam($team)->find($sig->ref_id);
            if ($g !== null) {
                return ['items' => [['kind' => 'gp', 'id' => (int) $g->id, 'name' => (string) $g->name,
                    'is_sales_recipe' => false]], 'total' => 1, 'gezeigt' => 1];
            }
        }

        return ['items' => [], 'total' => (int) ($pl['anzahl'] ?? 0), 'gezeigt' => 0];
    }

    /**
     * Alle OFFENEN Signale, die dasselbe Objekt betreffen — die objekt-zentrische Sicht.
     *
     * Kosten: ein EXISTS je offener Lücken-Metrik (heute ≤ ~25 Typen), plus ein
     * In-Memory-Scan der Detektor-Payloads. Bewusst kein Cache: das Panel wird nach
     * einem Fix erneut gefragt und muss dann den neuen Stand zeigen.
     *
     * @param  string  $kind  'recipe'|'gp'|'concept'
     * @return list<array{id:int,type:string,label:string,icon:string,severity:string,title:string,hat_ki:bool}>
     */
    public function signaleAmObjekt(Team $team, string $kind, int $id): array
    {
        if (! in_array($kind, ['recipe', 'gp', 'concept'], true) || $id <= 0) {
            return [];
        }

        $treffer = [];
        // Ein Metrik-Key wird oft von mehreren Signalen getragen (Ampel + Detektor) — je
        // Metrik nur EIN EXISTS, das Ergebnis wird für alle Signale derselben Metrik genutzt.
        $metrikCache = [];

        foreach (FoodAlchemistSignal::visibleToTeam($team)->offen()->orderByDesc('created_at')->get() as $sig) {
            if (! $this->betrifft($team, $sig, $kind, $id, $metrikCache)) {
                continue;
            }
            $treffer[] = [
                'id' => (int) $sig->id,
                'type' => $sig->type->value,
                'label' => $sig->type->label(),
                'icon' => $sig->type->icon(),
                'severity' => $sig->severity->value,
                'title' => (string) $sig->title,
                'hat_ki' => SignalCockpit::planFor($sig) !== null,
            ];
        }

        // Kritisch zuerst — im Panel soll oben stehen, was das Objekt blockiert.
        usort($treffer, fn (array $a, array $b) => [self::rang($a['severity']), $a['label']] <=> [self::rang($b['severity']), $b['label']]);

        return $treffer;
    }

    /**
     * Betrifft dieses Signal das Objekt? Drei Wege, analog zu betroffene() — nur
     * rückwärts gefragt und ohne Listen zu materialisieren.
     *
     * @param  array<string,bool>  $metrikCache
     */
    private function betrifft(Team $team, FoodAlchemistSignal $sig, string $kind, int $id, array &$metrikCache): bool
    {
        // 1) Direkte Referenz.
        if ($sig->ref_type === $kind && (int) $sig->ref_id === $id) {
            return true;
        }

        $pl = is_array($sig->payload) ? $sig->payload : [];

        // 2) Lücken-Metrik → ein EXISTS gegen dieses Objekt (Prädikat aus dem Check-Register).
        $metrik = SignalCockpit::metrik($sig);
        if ($metrik !== null) {
            $cacheKey = $metrik . '|' . $kind;
            if (! array_key_exists($cacheKey, $metrikCache)) {
                $metrikCache[$cacheKey] = $this->dq->trifftObjekt($team, $metrik, $kind, $id);
            }
            if ($metrikCache[$cacheKey]) {
                return true;
            }
        }

        // 3) Detektor-Beispiele (nur Rezepte — die Detektoren führen keine GP-Beispiele).
        if ($kind === 'recipe' && ! empty($pl['beispiele']) && is_array($pl['beispiele'])) {
            foreach ($pl['beispiele'] as $b) {
                if (is_array($b) && (int) ($b['recipe_id'] ?? $b['id'] ?? 0) === $id) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function rang(string $severity): int
    {
        return match ($severity) {
            'kritisch' => 0,
            'warnung' => 1,
            default => 2,
        };
    }
}
