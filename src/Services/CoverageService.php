<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;

/**
 * R4.2 Soll/Ist-Coverage — vergleicht Foodbook-/Konzept-IST gegen das Planungs-
 * Gerüst-SOLL (R4.1) je Dimension: Menge, Diät-Quote, Preis, Saison, Dramaturgie,
 * No-Gos. DIESELBE Messlatte für Mensch und KI (UI-Ampel + MCP-Tool + R4.3-Gate).
 *
 * Ampel je Befund: erfuellt | teilerfuellt | verletzt | info (nicht maschinell
 * messbar, z. B. allergen_line-Freitext). Ehrliche Degradation: fehlender Ist-Bezug
 * oder unbestimmte Diät wird als Hinweis ausgewiesen, nie geraten.
 *
 * Ist-Quellen: Foodbook → Kapitel/Blöcke (concept_ref-Slots + recipe_ref),
 * kapitelAggregat/gesamt (Preis p. P.); Konzept → Slots (dish|package.dishes),
 * preisCockpit. Diät = dish_classes.diet_form (kanonische VK-Taxonomie);
 * No-Go-Zutat = Namens-Match über Gericht + direkte Zutaten (GP/Sub-Rezept, 1 Ebene).
 */
class CoverageService
{
    public function __construct(
        private PlanningFrameService $frames,
        private ConceptService $concepts,
        private FoodbookService $foodbooks,
    ) {}

    private const AMPEL_RANG = ['info' => 0, 'erfuellt' => 1, 'teilerfuellt' => 2, 'verletzt' => 3];

    /**
     * @return array{hat_geruest:bool, befunde:list<array>, zusammenfassung:array, ampel_gesamt:?string}
     */
    public function coverage(Team $team, string $ownerType, int $ownerId): array
    {
        $owner = $this->frames->resolveOwner($team, $ownerType, $ownerId);
        $frame = $this->frames->find($ownerType, $ownerId);
        if ($frame === null) {
            return ['hat_geruest' => false, 'befunde' => [], 'zusammenfassung' => [], 'ampel_gesamt' => null];
        }
        $frame->loadMissing(['slots.rules', 'rules']);

        $ist = $ownerType === 'foodbook'
            ? $this->istFoodbook($team, $owner)
            : $this->istConcept($owner);

        $befunde = [];
        $befunde = array_merge($befunde, $this->pruefePreisKopf($frame, $ist));
        foreach ($frame->slots as $slot) {
            $befunde = array_merge($befunde, $this->pruefeSlot($slot, $ist));
        }
        foreach ($frame->rules->whereNull('slot_id') as $rule) {
            $befunde = array_merge($befunde, $this->pruefeRegel($rule, $ist['gerichte'], null, $ist));
        }

        $zaehler = ['erfuellt' => 0, 'teilerfuellt' => 0, 'verletzt' => 0, 'info' => 0];
        $gesamt = null;
        foreach ($befunde as $b) {
            $zaehler[$b['ampel']]++;
            if ($b['ampel'] !== 'info' && ($gesamt === null || self::AMPEL_RANG[$b['ampel']] > self::AMPEL_RANG[$gesamt])) {
                $gesamt = $b['ampel'];
            }
        }

        return [
            'hat_geruest' => true,
            'befunde' => $befunde,
            'zusammenfassung' => $zaehler,
            'ampel_gesamt' => $gesamt ?? 'erfuellt',
        ];
    }

    /** Rote Ampeln (verletzt) — Gate-Frage für R4.3 Kalkulation→Freigabe. */
    public function hatRoteAmpeln(Team $team, string $ownerType, int $ownerId): bool
    {
        $cov = $this->coverage($team, $ownerType, $ownerId);

        return $cov['hat_geruest'] && ($cov['zusammenfassung']['verletzt'] ?? 0) > 0;
    }

    // ── Ist-Erhebung ────────────────────────────────────────────────────

    /**
     * Gericht-Zeile fürs Coverage-Ist: id, name, diet_form (aus dish_class, sonst
     * spec-Flags als Fallback vegan/vegi), sales_net, Allergen-Werte, Begriffs-Korpus.
     */
    private function gerichtZeile(FoodAlchemistRecipe $r): array
    {
        $diet = $r->dishClass?->diet_form;
        if ($diet === null || $diet === 'neutral') {
            // Fallback nur in die "sichere" Richtung: Spec-Flag TRUE ist belastbar.
            if ($r->spec_is_vegan === true) {
                $diet = 'vegan';
            } elseif ($r->spec_is_vegetarian === true) {
                $diet = $diet ?? 'vegi';
            }
        }
        $begriffe = mb_strtolower($r->name . ' ' . $r->ingredients->map(
            fn ($z) => ($z->gp?->name ?? '') . ' ' . ($z->referencedRecipe?->name ?? '')
        )->implode(' '));

        $allergene = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS as $key) {
            $allergene[$key] = $r->{'allergen_' . $key} ?? null;
        }

        return [
            'id' => $r->id, 'name' => $r->name,
            'diet_form' => $diet,
            'sales_net' => $r->sales_net !== null ? (float) $r->sales_net : null,
            'allergene' => $allergene,
            'begriffe' => $begriffe,
        ];
    }

    /** @return array{gerichte:Collection, scopes:array<string,Collection>, preis_pp:?float, saison_ids:list<int>, kapitel:array} */
    private function istConcept(FoodAlchemistConcept $concept): array
    {
        $concept->load([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.dish', 'slots.dish.dishClass:id,diet_form',
            'slots.dish.ingredients.gp:id,name', 'slots.dish.ingredients.referencedRecipe:id,name',
            'slots.package.dishes.dish', 'slots.package.dishes.dish.dishClass:id,diet_form',
            'slots.package.dishes.dish.ingredients.gp:id,name', 'slots.package.dishes.dish.ingredients.referencedRecipe:id,name',
            'seasons:id,name',
        ]);

        $gerichte = collect();
        $scopes = [];   // Scope-Schlüssel (lowercase Rolle/Titel des Concept-Slots) → Gerichte
        foreach ($concept->slots as $slot) {
            $zeilen = collect();
            if ($slot->dish) {
                $zeilen->push($this->gerichtZeile($slot->dish));
            } elseif ($slot->package) {
                foreach ($slot->package->dishes as $pg) {
                    if ($pg->dish) {
                        $zeilen->push($this->gerichtZeile($pg->dish));
                    }
                }
            }
            if ($zeilen->isEmpty()) {
                continue;
            }
            $gerichte = $gerichte->merge($zeilen);
            foreach ([mb_strtolower(trim((string) $slot->role)), mb_strtolower(trim((string) $slot->title))] as $key) {
                if ($key !== '') {
                    $scopes[$key] = ($scopes[$key] ?? collect())->merge($zeilen);
                }
            }
        }

        $cockpit = $this->concepts->preisCockpit($concept);

        return [
            'gerichte' => $gerichte->unique('id')->values(),
            'scopes' => $scopes,
            'preis_pp' => $cockpit['price_per_person'] > 0 ? (float) $cockpit['price_per_person'] : null,
            'saison_ids' => $concept->seasons->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'kapitel' => [],
        ];
    }

    /** @return array{gerichte:Collection, scopes:array<string,Collection>, preis_pp:?float, saison_ids:list<int>, kapitel:array<int,array>} */
    private function istFoodbook(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $fb->load([
            'chapters' => fn ($q) => $q->orderBy('position'),
            'chapters.blocks' => fn ($q) => $q->where('visible', true)->orderBy('position'),
            'chapters.blocks.dish', 'chapters.blocks.dish.dishClass:id,diet_form',
            'chapters.blocks.dish.ingredients.gp:id,name', 'chapters.blocks.dish.ingredients.referencedRecipe:id,name',
            'chapters.blocks.concept.slots.dish', 'chapters.blocks.concept.slots.dish.dishClass:id,diet_form',
            'chapters.blocks.concept.slots.dish.ingredients.gp:id,name', 'chapters.blocks.concept.slots.dish.ingredients.referencedRecipe:id,name',
            'chapters.blocks.concept.slots.package.dishes.dish', 'chapters.blocks.concept.slots.package.dishes.dish.dishClass:id,diet_form',
            'chapters.blocks.concept.slots.package.dishes.dish.ingredients.gp:id,name', 'chapters.blocks.concept.slots.package.dishes.dish.ingredients.referencedRecipe:id,name',
            'chapters.blocks.concept.seasons:id,name',
        ]);

        $gerichte = collect();
        $scopes = [];
        $kapitelMap = [];   // chapter_id → ['titel', 'gerichte', 'vk_pp']
        $saisonIds = [];
        foreach ($fb->chapters as $kapitel) {
            $zeilen = collect();
            foreach ($kapitel->blocks as $block) {
                if ($block->dish) {
                    $zeilen->push($this->gerichtZeile($block->dish));
                }
                if ($block->concept) {
                    $saisonIds = array_merge($saisonIds, $block->concept->seasons->pluck('id')->map(fn ($v) => (int) $v)->all());
                    foreach ($block->concept->slots as $slot) {
                        if ($slot->dish) {
                            $zeilen->push($this->gerichtZeile($slot->dish));
                        } elseif ($slot->package) {
                            foreach ($slot->package->dishes as $pg) {
                                if ($pg->dish) {
                                    $zeilen->push($this->gerichtZeile($pg->dish));
                                }
                            }
                        }
                    }
                }
            }
            $agg = $this->foodbooks->kapitelAggregat($team, $kapitel, $fb->personen);
            $kapitelMap[$kapitel->id] = [
                'titel' => (string) $kapitel->title,
                'gerichte' => $zeilen,
                'vk_pp' => $agg['vk_pro_person'] > 0 ? (float) $agg['vk_pro_person'] : null,
            ];
            $gerichte = $gerichte->merge($zeilen);
            $key = mb_strtolower(trim((string) $kapitel->title));
            if ($key !== '') {
                $scopes[$key] = ($scopes[$key] ?? collect())->merge($zeilen);
            }
        }

        $gesamt = $this->foodbooks->gesamt($team, $fb);

        return [
            'gerichte' => $gerichte->unique('id')->values(),
            'scopes' => $scopes,
            'preis_pp' => ($gesamt['vk_pro_person'] ?? 0) > 0 ? (float) $gesamt['vk_pro_person'] : null,
            'saison_ids' => array_values(array_unique($saisonIds)),
            'kapitel' => $kapitelMap,
        ];
    }

    // ── Prüfungen ───────────────────────────────────────────────────────

    private function befund(string $dimension, ?int $slotId, string $label, string $soll, string $istText, string $ampel, ?string $hinweis = null, ?array $fillFilter = null): array
    {
        return [
            'dimension' => $dimension, 'slot_id' => $slotId, 'label' => $label,
            'soll' => $soll, 'ist' => $istText, 'ampel' => $ampel,
            'hinweis' => $hinweis, 'fill_filter' => $fillFilter,
        ];
    }

    private function pruefePreisKopf(FoodAlchemistPlanningFrame $frame, array $ist): array
    {
        if ($frame->target_price_pp === null && $frame->price_min_pp === null && $frame->price_max_pp === null) {
            return [];
        }
        $sollTeile = [];
        if ($frame->target_price_pp !== null) {
            $sollTeile[] = 'Ziel ' . number_format((float) $frame->target_price_pp, 2, ',', '.') . ' €';
        }
        if ($frame->price_min_pp !== null || $frame->price_max_pp !== null) {
            $sollTeile[] = 'Spanne ' . ($frame->price_min_pp !== null ? number_format((float) $frame->price_min_pp, 2, ',', '.') : '—')
                . '–' . ($frame->price_max_pp !== null ? number_format((float) $frame->price_max_pp, 2, ',', '.') : '—') . ' €';
        }
        $soll = implode(' · ', $sollTeile) . ' p. P.';

        if ($ist['preis_pp'] === null) {
            return [$this->befund('preis', null, 'Preis pro Person', $soll, 'kein Ist-Preis', 'teilerfuellt', 'Noch keine bepreisten Positionen.')];
        }
        $istPreis = $ist['preis_pp'];
        $istText = number_format($istPreis, 2, ',', '.') . ' € p. P.';
        if ($frame->price_min_pp !== null && $istPreis < (float) $frame->price_min_pp) {
            return [$this->befund('preis', null, 'Preis pro Person', $soll, $istText, 'verletzt', 'Unter der Preisspanne.')];
        }
        if ($frame->price_max_pp !== null && $istPreis > (float) $frame->price_max_pp) {
            return [$this->befund('preis', null, 'Preis pro Person', $soll, $istText, 'verletzt', 'Über der Preisspanne.')];
        }
        if ($frame->target_price_pp !== null) {
            $abw = abs($istPreis - (float) $frame->target_price_pp) / max(0.01, (float) $frame->target_price_pp);
            if ($abw > 0.10) {
                return [$this->befund('preis', null, 'Preis pro Person', $soll, $istText, 'teilerfuellt', 'Weicht >10 % vom Zielpreis ab (innerhalb der Spanne).')];
            }
        }

        return [$this->befund('preis', null, 'Preis pro Person', $soll, $istText, 'erfuellt')];
    }

    /** @return Collection|null Gerichte im Slot-Scope (Kapitel-ID > Label-Match), null = kein Ist-Bezug */
    private function slotScope(FoodAlchemistPlanningFrameSlot $slot, array $ist): ?Collection
    {
        if ($slot->chapter_id !== null && isset($ist['kapitel'][$slot->chapter_id])) {
            return $ist['kapitel'][$slot->chapter_id]['gerichte'];
        }
        $key = mb_strtolower(trim((string) $slot->label));

        return $ist['scopes'][$key] ?? null;
    }

    private function pruefeSlot(FoodAlchemistPlanningFrameSlot $slot, array $ist): array
    {
        $befunde = [];
        $scope = $this->slotScope($slot, $ist);
        $label = 'Slot „' . $slot->label . '“';

        if ($scope === null) {
            $hatSoll = $slot->target_count !== null || $slot->is_pflicht || $slot->rules->isNotEmpty();
            if ($hatSoll) {
                $befunde[] = $this->befund('dramaturgie', $slot->id, $label,
                    $slot->is_pflicht ? 'Pflicht-Slot belegt' : 'Slot belegt',
                    'kein Ist-Bezug',
                    $slot->is_pflicht ? 'verletzt' : 'teilerfuellt',
                    'Kein Kapitel/Slot mit passendem Namen' . ($slot->chapter_id ? ' (Kapitel-Verweis läuft ins Leere)' : '') . ' — anlegen oder Slot-Label angleichen.');
            }

            return $befunde;
        }

        // Dramaturgie: Pflicht-Slot muss ≥1 Gericht tragen
        if ($slot->is_pflicht && $scope->isEmpty()) {
            $befunde[] = $this->befund('dramaturgie', $slot->id, $label, 'Pflicht-Slot belegt', '0 Gerichte', 'verletzt', 'Pflicht-Slot ist leer.');
        }

        // Mengengerüst
        if ($slot->target_count !== null) {
            $n = $scope->count();
            $ampel = $n >= $slot->target_count ? 'erfuellt' : ($n === 0 ? 'verletzt' : 'teilerfuellt');
            $befunde[] = $this->befund('menge', $slot->id, $label,
                "{$slot->target_count} Gerichte", "{$n} Gerichte", $ampel,
                $ampel === 'erfuellt' ? null : ($slot->target_count - $n) . ' fehlen.',
                $ampel === 'erfuellt' ? null : ['slot_label' => $slot->label]);
        }

        // Preis-Anker/Spanne je Slot (gegen Ø sales_net der Gerichte im Scope)
        if (($slot->price_anchor !== null || $slot->price_min !== null || $slot->price_max !== null) && $scope->isNotEmpty()) {
            $preise = $scope->pluck('sales_net')->filter(fn ($v) => $v !== null && $v > 0);
            if ($preise->isEmpty()) {
                $befunde[] = $this->befund('preis', $slot->id, $label, 'Preisrahmen je Gericht', 'keine VK-Preise', 'teilerfuellt', 'Gerichte im Slot sind unbepreist.');
            } else {
                $avg = (float) $preise->avg();
                $istText = 'Ø ' . number_format($avg, 2, ',', '.') . ' €';
                if ($slot->price_min !== null && $avg < (float) $slot->price_min) {
                    $befunde[] = $this->befund('preis', $slot->id, $label, 'Spanne ab ' . number_format((float) $slot->price_min, 2, ',', '.') . ' €', $istText, 'verletzt', 'Unter der Slot-Preisspanne.');
                } elseif ($slot->price_max !== null && $avg > (float) $slot->price_max) {
                    $befunde[] = $this->befund('preis', $slot->id, $label, 'Spanne bis ' . number_format((float) $slot->price_max, 2, ',', '.') . ' €', $istText, 'verletzt', 'Über der Slot-Preisspanne.');
                } elseif ($slot->price_anchor !== null && abs($avg - (float) $slot->price_anchor) / max(0.01, (float) $slot->price_anchor) > 0.15) {
                    $befunde[] = $this->befund('preis', $slot->id, $label, 'Anker ' . number_format((float) $slot->price_anchor, 2, ',', '.') . ' €', $istText, 'teilerfuellt', 'Weicht >15 % vom Preis-Anker ab.');
                } else {
                    $befunde[] = $this->befund('preis', $slot->id, $label, 'Preisrahmen je Gericht', $istText, 'erfuellt');
                }
            }
        }

        // Slot-Regeln (Diät-Quoten etc.) gegen den Slot-Scope
        foreach ($slot->rules as $rule) {
            $befunde = array_merge($befunde, $this->pruefeRegel($rule, $scope, $slot, null));
        }

        return $befunde;
    }

    private function pruefeRegel(FoodAlchemistPlanningFrameRule $rule, Collection $gerichte, ?FoodAlchemistPlanningFrameSlot $slot, ?array $ist): array
    {
        $slotId = $slot?->id;
        $prefix = $slot !== null ? 'Slot „' . $slot->label . '“: ' : '';

        switch ($rule->rule_type) {
            case 'diet_quota':
                $treffer = $gerichte->where('diet_form', $rule->ref_key)->count();
                $gesamt = $gerichte->count();
                $unbestimmt = $gerichte->whereNull('diet_form')->count();
                if ($rule->unit === 'percent') {
                    $istWert = $gesamt > 0 ? round($treffer / $gesamt * 100, 1) : 0.0;
                    $istText = $istWert . ' % (' . $treffer . '/' . $gesamt . ')';
                } else {
                    $istWert = $treffer;
                    $istText = $treffer . '×';
                }
                $sollWert = (float) $rule->value_num;
                $ok = match ($rule->operator) {
                    'max' => $istWert <= $sollWert,
                    'exact' => abs($istWert - $sollWert) < 0.001,
                    default => $istWert >= $sollWert,
                };
                $op = ['min' => 'mind.', 'max' => 'max.', 'exact' => 'genau'][$rule->operator] ?? $rule->operator;
                $soll = "{$op} " . ($rule->unit === 'percent' ? $sollWert . ' %' : (int) $sollWert . '×') . " {$rule->ref_key}";
                // min/exact mit Teil-Ist → teilerfüllt; max-Überschreitung und 0-Ist → verletzt
                $ampel = $ok ? 'erfuellt' : (($rule->operator === 'max' || $istWert <= 0) ? 'verletzt' : 'teilerfuellt');
                $hinweis = $unbestimmt > 0 ? "{$unbestimmt} Gericht(e) ohne bestimmbare Diätform (nicht mitgezählt bei {$rule->ref_key})." : null;

                return [$this->befund('diaet', $slotId, $prefix . 'Diät-Quote ' . $rule->ref_key, $soll, $istText, $ampel,
                    $ok ? $hinweis : trim(($hinweis ?? '') . ' Quote nicht erfüllt.'),
                    $ok ? null : ['diet_form' => $rule->ref_key, 'slot_label' => $slot?->label])];

            case 'season_coverage':
                if ($ist === null && $slot !== null) {
                    return []; // Saison ist owner-weit — Slot-Ebene nicht sinnvoll
                }
                $name = FoodAlchemistSaison::find($rule->ref_id)?->name ?? ('Saison #' . $rule->ref_id);
                $drin = in_array((int) $rule->ref_id, $ist['saison_ids'] ?? [], true);

                return [$this->befund('saison', null, 'Saison-Abdeckung', $name, $drin ? 'abgedeckt' : 'fehlt',
                    $drin ? 'erfuellt' : 'verletzt', $drin ? null : "Kein Inhalt mit Saison „{$name}“.")];

            case 'nogo_ingredient':
                $term = mb_strtolower(trim((string) $rule->value_text));
                $treffer = $gerichte->filter(fn ($g) => $term !== '' && str_contains($g['begriffe'], $term));
                if ($treffer->isEmpty()) {
                    return [$this->befund('nogo', $slotId, $prefix . 'No-Go „' . $rule->value_text . '“', 'kommt nicht vor', 'kein Treffer', 'erfuellt')];
                }
                $ampel = $rule->severity === 'weich' ? 'teilerfuellt' : 'verletzt';

                return [$this->befund('nogo', $slotId, $prefix . 'No-Go „' . $rule->value_text . '“', 'kommt nicht vor',
                    $treffer->count() . ' Treffer: ' . $treffer->pluck('name')->take(3)->implode(', ') . ($treffer->count() > 3 ? ' …' : ''),
                    $ampel, 'Namens-Match über Gericht + direkte Zutaten (1 Ebene).')];

            case 'nogo_allergen':
                $key = (string) $rule->ref_key;
                $enthalten = $gerichte->filter(fn ($g) => ($g['allergene'][$key] ?? null) === 'enthalten');
                $spuren = $gerichte->filter(fn ($g) => ($g['allergene'][$key] ?? null) === 'spuren');
                $unbekannt = $gerichte->filter(fn ($g) => in_array($g['allergene'][$key] ?? null, [null, 'unbekannt'], true));
                if ($enthalten->isNotEmpty()) {
                    return [$this->befund('nogo', $slotId, $prefix . 'No-Go-Allergen ' . $key, 'nicht enthalten',
                        $enthalten->count() . '× enthalten: ' . $enthalten->pluck('name')->take(3)->implode(', ') . ($enthalten->count() > 3 ? ' …' : ''),
                        $rule->severity === 'weich' ? 'teilerfuellt' : 'verletzt')];
                }
                if ($spuren->isNotEmpty()) {
                    return [$this->befund('nogo', $slotId, $prefix . 'No-Go-Allergen ' . $key, 'nicht enthalten',
                        $spuren->count() . '× Spuren', 'teilerfuellt', 'Spuren-Kennzeichnung prüfen.')];
                }
                $hinweis = $unbekannt->isNotEmpty() ? $unbekannt->count() . ' Gericht(e) mit unbekanntem Allergen-Status.' : null;

                return [$this->befund('nogo', $slotId, $prefix . 'No-Go-Allergen ' . $key, 'nicht enthalten',
                    'kein Treffer', $hinweis !== null ? 'teilerfuellt' : 'erfuellt', $hinweis)];

            case 'allergen_line':
                return [$this->befund('nogo', $slotId, $prefix . 'Allergen-Linie', (string) $rule->value_text,
                    'manuell prüfen', 'info', 'Freitext-Linie — nicht maschinell messbar.')];
        }

        return [];
    }
}
