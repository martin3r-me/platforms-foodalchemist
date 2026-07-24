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
     * @return array{hat_geruest:bool, befunde:list<array>, zusammenfassung:array, ampel_gesamt:?string, wareneinsatz:list<array>}
     */
    public function coverage(Team $team, string $ownerType, int $ownerId): array
    {
        $owner = $this->frames->resolveOwner($team, $ownerType, $ownerId);
        $frame = $this->frames->find($ownerType, $ownerId);
        if ($frame === null) {
            return ['hat_geruest' => false, 'befunde' => [], 'zusammenfassung' => [], 'ampel_gesamt' => null, 'wareneinsatz' => []];
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
        // Kapitel-Ziele (M3 SOLL, foodbook-only): Menge/Preis am Kapitel-Rollup. Greift ein
        // Kapitel-Ziel, hat pruefeSlot dieselbe Dimension übersprungen (Vorrangregel).
        foreach ($ist['kapitel'] as $cid => $data) {
            if (($data['hat_menge_ziel'] ?? false) || ($data['hat_preis_ziel'] ?? false)) {
                $befunde = array_merge($befunde, $this->pruefeKapitel((int) $cid, (string) $data['titel'], $data['gerichte'], $data['ziele']));
            }
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

        // Spec 19 E4.6: Wareneinsatz-Sektion je Kapitel (foodbook-only) — IST-Food-Cost vs.
        // Ziel-Kaskade (FoodbookService::wareneinsatzAmpel, E4.4). Die Kapitel-Befunde selbst
        // stecken schon in `befunde` (pruefeKapitel, E4.3); die WE-Ampel ist die separate
        // Kalkulations-Sicht (gruen|gelb|rot|unbekannt + partiell-Vorbehalt bei Pauschal-Anteilen).
        $wareneinsatz = [];
        if ($ownerType === 'foodbook') {
            foreach ($owner->chapters as $kap) {
                $we = $this->foodbooks->wareneinsatzAmpel($team, $owner, $kap);
                $wareneinsatz[] = ['chapter_id' => (int) $kap->id, 'titel' => (string) $kap->title] + $we;
            }
        }

        return [
            'hat_geruest' => true,
            'befunde' => $befunde,
            'zusammenfassung' => $zaehler,
            'ampel_gesamt' => $gesamt ?? 'erfuellt',
            'wareneinsatz' => $wareneinsatz,
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
        $kapitelMap = [];   // chapter_id → ['titel', 'gerichte' (rollup), 'vk_pp']
        $direktMap = [];    // chapter_id → direkte Gerichte (nur eigene Blöcke)
        $kinder = [];       // parent_id (0 = Wurzel) → [child_id, …] — für Nachfahren-Rollup
        $saisonIds = [];
        // 1. Durchlauf: direkte Gerichte je Kapitel + Baum-Kanten sammeln.
        foreach ($fb->chapters as $kapitel) {
            $kinder[$kapitel->parent_id ?? 0][] = $kapitel->id;
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
            $direktMap[$kapitel->id] = $zeilen;
            $agg = $this->foodbooks->kapitelAggregat($team, $kapitel, $fb->personen);
            $kapitelMap[$kapitel->id] = [
                'titel' => (string) $kapitel->title,
                'gerichte' => $zeilen,   // wird unten durch Nachfahren-Rollup ersetzt
                'vk_pp' => $agg['vk_pro_person'] > 0 ? (float) $agg['vk_pro_person'] : null,
            ];
            $gerichte = $gerichte->merge($zeilen);
            $key = mb_strtolower(trim((string) $kapitel->title));
            if ($key !== '') {
                $scopes[$key] = ($scopes[$key] ?? collect())->merge($zeilen);
            }
        }
        // 2. Durchlauf: Kapitel-Scope = Kapitel + alle Nachfahren (Ziel-/Slot-Sicht;
        // vk_pp bleibt aus kapitelAggregat, das ist bereits rekursiv). unique('id') dedupt.
        // Zusätzlich Kapitel-Ketten-Ziele (M3 SOLL, Spec 19 E4.3): Menge/Preis aus
        // Kapitel + Eltern (NICHT Slot-Fallback — der Slot ist der andere Prüfpfad,
        // die Vorrangregel entscheidet, wer misst). Trägt die Vorrang-Flags.
        $byId = $fb->chapters->keyBy('id');
        foreach ($kapitelMap as $cid => $data) {
            $roll = $direktMap[$cid] ?? collect();
            foreach ($this->nachfahrenIds($cid, $kinder) as $did) {
                $roll = $roll->merge($direktMap[$did] ?? collect());
            }
            $kapitelMap[$cid]['gerichte'] = $roll->unique('id')->values();

            $ziele = $this->kapitelKettenZiel($byId[$cid], $byId);
            $kapitelMap[$cid]['ziele'] = $ziele;
            $kapitelMap[$cid]['hat_menge_ziel'] = $ziele['target_count'] !== null;
            $kapitelMap[$cid]['hat_preis_ziel'] = $ziele['price_anchor'] !== null
                || $ziele['price_min'] !== null || $ziele['price_max'] !== null;
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

    /**
     * Iterative Nachfahren-Sammlung aus der parent→children-Map (spiegelt
     * FoodbookService::descendantKapitelIds, aber ohne DB — der Baum ist schon geladen).
     *
     * @param  array<int,list<int>>  $kinder  parent_id (0 = Wurzel) → child ids
     * @return list<int>
     */
    private function nachfahrenIds(int $kapitelId, array $kinder): array
    {
        $ids = [];
        $stack = $kinder[$kapitelId] ?? [];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($kinder[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    /**
     * M3-SOLL eines Kapitels über die Kapitel-Kette (self + Eltern hoch, erstes
     * gesetztes gewinnt) — OHNE Slot-Fallback (das ist der pruefeSlot-Pfad, die
     * Vorrangregel trennt beide). Spiegelt FoodbookService::kapitelZiele, aber
     * kettenlokal auf den schon geladenen Kapiteln (kein DB-Zugriff).
     *
     * @param  Collection<int,\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel>  $byId
     * @return array{target_count:?int, price_anchor:?float, price_min:?float, price_max:?float}
     */
    private function kapitelKettenZiel($kapitel, Collection $byId): array
    {
        $out = ['target_count' => null, 'price_anchor' => null, 'price_min' => null, 'price_max' => null];
        $node = $kapitel;
        $besucht = [];
        while ($node !== null && ! isset($besucht[(int) $node->id])) {
            $besucht[(int) $node->id] = true;
            if ($out['target_count'] === null && $node->target_count !== null && $node->target_count !== '') {
                $out['target_count'] = (int) $node->target_count;
            }
            foreach (['price_anchor', 'price_min', 'price_max'] as $f) {
                if ($out[$f] === null && $node->{$f} !== null && $node->{$f} !== '') {
                    $out[$f] = (float) $node->{$f};
                }
            }
            $node = $node->parent_id !== null ? ($byId[$node->parent_id] ?? null) : null;
        }

        return $out;
    }

    // ── Prüfungen ───────────────────────────────────────────────────────

    private function befund(string $dimension, ?int $slotId, string $label, string $soll, string $istText, string $ampel, ?string $hinweis = null, ?array $fillFilter = null): array
    {
        return [
            'dimension' => $dimension, 'slot_id' => $slotId, 'chapter_id' => null, 'label' => $label,
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

    /**
     * Gerichte im Slot-Scope (Kapitel-ID > Label-Match), null = kein Ist-Bezug.
     * Kapitel-Verweis liefert Kapitel + ALLE Nachfahren (Rollup aus istFoodbook),
     * damit ein Eltern-Slot Enkel-Gerichte sieht (Coverage-Tiefe, Spec 19 E2.2).
     *
     * @return Collection|null
     */
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

        // Vorrangregel (Spec 19 E4.3): trägt das Kapitel dieses Slots eigene M3-Ziele,
        // gewinnt das Kapitel — pruefeKapitel misst Menge/Preis, der Slot überspringt sie
        // (sonst Doppel-Befund am gestempelten Slot↔Kapitel-Paar). Dramaturgie/Regeln bleiben.
        $kapZiel = ($slot->chapter_id !== null) ? ($ist['kapitel'][$slot->chapter_id] ?? null) : null;
        $skipMenge = (bool) ($kapZiel['hat_menge_ziel'] ?? false);
        $skipPreis = (bool) ($kapZiel['hat_preis_ziel'] ?? false);

        // Dramaturgie: Pflicht-Slot muss ≥1 Gericht tragen
        if ($slot->is_pflicht && $scope->isEmpty()) {
            $befunde[] = $this->befund('dramaturgie', $slot->id, $label, 'Pflicht-Slot belegt', '0 Gerichte', 'verletzt', 'Pflicht-Slot ist leer.');
        }

        // Mengengerüst
        if ($slot->target_count !== null && ! $skipMenge) {
            $n = $scope->count();
            $ampel = $n >= $slot->target_count ? 'erfuellt' : ($n === 0 ? 'verletzt' : 'teilerfuellt');
            $befunde[] = $this->befund('menge', $slot->id, $label,
                "{$slot->target_count} Gerichte", "{$n} Gerichte", $ampel,
                $ampel === 'erfuellt' ? null : ($slot->target_count - $n) . ' fehlen.',
                $ampel === 'erfuellt' ? null : ['slot_label' => $slot->label]);
        }

        // Preis-Anker/Spanne je Slot (gegen Ø sales_net der Gerichte im Scope)
        if (($slot->price_anchor !== null || $slot->price_min !== null || $slot->price_max !== null) && $scope->isNotEmpty() && ! $skipPreis) {
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

    /**
     * Kapitel-Ziele (M3 SOLL, Spec 19 E4.3): Menge + Preis gegen den Kapitel-Rollup-Scope
     * (Kapitel + alle Nachfahren, aus istFoodbook). Befund-Shape identisch zu pruefeSlot,
     * slot_id=null, chapter_id gesetzt. Logik gespiegelt aus pruefeSlot (Menge/Preis-Anker),
     * damit Mensch/KI dieselbe Messlatte sehen. Diät/No-Go/Saison bleiben Slot-/Regel-Ebene.
     *
     * @param  array{target_count:?int, price_anchor:?float, price_min:?float, price_max:?float}  $ziele
     */
    private function pruefeKapitel(int $chapterId, string $titel, Collection $scope, array $ziele): array
    {
        $befunde = [];
        $label = 'Kapitel „' . $titel . '“';

        // Mengengerüst
        if ($ziele['target_count'] !== null) {
            $n = $scope->count();
            $ampel = $n >= $ziele['target_count'] ? 'erfuellt' : ($n === 0 ? 'verletzt' : 'teilerfuellt');
            $befunde[] = $this->befund('menge', null, $label,
                "{$ziele['target_count']} Gerichte", "{$n} Gerichte", $ampel,
                $ampel === 'erfuellt' ? null : ($ziele['target_count'] - $n) . ' fehlen.',
                $ampel === 'erfuellt' ? null : ['chapter_id' => $chapterId]);
        }

        // Preis-Anker/Spanne (gegen Ø sales_net der Gerichte im Kapitel-Scope)
        if (($ziele['price_anchor'] !== null || $ziele['price_min'] !== null || $ziele['price_max'] !== null) && $scope->isNotEmpty()) {
            $preise = $scope->pluck('sales_net')->filter(fn ($v) => $v !== null && $v > 0);
            if ($preise->isEmpty()) {
                $befunde[] = $this->befund('preis', null, $label, 'Preisrahmen je Gericht', 'keine VK-Preise', 'teilerfuellt', 'Gerichte im Kapitel sind unbepreist.');
            } else {
                $avg = (float) $preise->avg();
                $istText = 'Ø ' . number_format($avg, 2, ',', '.') . ' €';
                if ($ziele['price_min'] !== null && $avg < $ziele['price_min']) {
                    $befunde[] = $this->befund('preis', null, $label, 'Spanne ab ' . number_format($ziele['price_min'], 2, ',', '.') . ' €', $istText, 'verletzt', 'Unter der Kapitel-Preisspanne.');
                } elseif ($ziele['price_max'] !== null && $avg > $ziele['price_max']) {
                    $befunde[] = $this->befund('preis', null, $label, 'Spanne bis ' . number_format($ziele['price_max'], 2, ',', '.') . ' €', $istText, 'verletzt', 'Über der Kapitel-Preisspanne.');
                } elseif ($ziele['price_anchor'] !== null && abs($avg - $ziele['price_anchor']) / max(0.01, $ziele['price_anchor']) > 0.15) {
                    $befunde[] = $this->befund('preis', null, $label, 'Anker ' . number_format($ziele['price_anchor'], 2, ',', '.') . ' €', $istText, 'teilerfuellt', 'Weicht >15 % vom Preis-Anker ab.');
                } else {
                    $befunde[] = $this->befund('preis', null, $label, 'Preisrahmen je Gericht', $istText, 'erfuellt');
                }
            }
        }

        // chapter_id in alle Kapitel-Befunde stempeln (Befund-Shape additiv)
        return array_map(function (array $b) use ($chapterId): array {
            $b['chapter_id'] = $chapterId;

            return $b;
        }, $befunde);
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
