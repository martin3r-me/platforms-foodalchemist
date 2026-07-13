<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use RuntimeException;

/**
 * R4.1 Planungs-Gerüst — Service über frames/slots/rules. Das Gerüst ist die
 * MESSBARE Soll-Ebene (Mengengerüst, Preisarchitektur, Kunden-Politik, Saison,
 * Dramaturgie) neben dem Freitext-Canvas: R4.2 misst dagegen, R6.1 promptet daraus.
 * Lesen visibleToTeam, schreiben isOwnedBy (D1). Jedes Feld optional.
 */
class PlanningFrameService
{
    /** Owner (foodbook|concept) team-sichtbar auflösen — wirft bei unsichtbar/fremd. */
    public function resolveOwner(Team $team, string $ownerType, int $ownerId): object
    {
        return match ($ownerType) {
            'foodbook' => FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($ownerId),
            'concept' => FoodAlchemistConcept::visibleToTeam($team)->findOrFail($ownerId),
            default => throw new RuntimeException("Unbekannter Gerüst-Owner-Typ „{$ownerType}“ — erlaubt: foodbook|concept."),
        };
    }

    /** Existierendes Gerüst finden (KEIN Create) — Lese-/Kontext-Pfad. */
    public function find(string $ownerType, int $ownerId): ?FoodAlchemistPlanningFrame
    {
        return FoodAlchemistPlanningFrame::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)->first();
    }

    /** Gerüst holen oder anlegen (Edit-Pfad). Owner muss team-sichtbar sein. */
    public function frameFor(Team $team, string $ownerType, int $ownerId, string $createdVia = 'ui'): FoodAlchemistPlanningFrame
    {
        $this->resolveOwner($team, $ownerType, $ownerId);

        return FoodAlchemistPlanningFrame::firstOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId],
            ['team_id' => $team->id, 'status' => 'draft', 'created_via' => $createdVia],
        );
    }

    private function guardWrite(Team $team, FoodAlchemistPlanningFrame $frame): void
    {
        if (! $frame->isOwnedBy($team)) {
            throw new RuntimeException('Geerbtes Planungs-Gerüst — Pflege nur durch das Besitzer-Team (D1).');
        }
    }

    /**
     * R6.1: Gerüst auf einen anderen Owner KOPIEREN (Kopf + Slots + Regeln, inkl.
     * Slot-Regel-Zuordnung). Nutzt der Konzept-Generator: das Quell-Gerüst (z. B. am
     * Foodbook) bleibt, das generierte Konzept bekommt seine eigene Kopie — damit
     * läuft der R4.2-Coverage-Check direkt am Konzept.
     */
    public function kopiereZu(Team $team, FoodAlchemistPlanningFrame $quelle, string $ownerType, int $ownerId, string $createdVia = 'concept_generator'): FoodAlchemistPlanningFrame
    {
        $quelle->loadMissing(['slots.rules', 'rules']);
        $ziel = $this->frameFor($team, $ownerType, $ownerId, $createdVia);
        $this->setHead($team, $ziel, [
            'target_price_pp' => $quelle->target_price_pp,
            'price_min_pp' => $quelle->price_min_pp,
            'price_max_pp' => $quelle->price_max_pp,
            'note' => $quelle->note,
        ]);

        $slotMap = [];  // Quell-Slot-ID → Ziel-Slot
        foreach ($quelle->slots as $slot) {
            $slotMap[$slot->id] = $this->addSlot($team, $ziel, [
                'position' => $slot->position, 'label' => $slot->label, 'slot_type' => $slot->slot_type,
                'target_count' => $slot->target_count, 'price_anchor' => $slot->price_anchor,
                'price_min' => $slot->price_min, 'price_max' => $slot->price_max,
                'is_pflicht' => (bool) $slot->is_pflicht, 'note' => $slot->note,
                // chapter_id bewusst NICHT kopiert — der Ist-Bezug gilt nur beim Quell-Owner
            ]);
        }
        // rules (hasMany über frame_id) enthält frame- UND slot-scoped Regeln — kein Merge nötig
        foreach ($quelle->rules as $rule) {
            $this->addRule($team, $ziel, [
                'slot_id' => $rule->slot_id !== null ? ($slotMap[$rule->slot_id]->id ?? null) : null,
                'rule_type' => $rule->rule_type, 'ref_key' => $rule->ref_key, 'ref_id' => $rule->ref_id,
                'operator' => $rule->operator, 'value_num' => $rule->value_num, 'unit' => $rule->unit,
                'value_text' => $rule->value_text, 'severity' => $rule->severity,
            ]);
        }

        return $ziel->refresh();
    }

    // ── Kopf (Preisarchitektur p. P.) ──────────────────────────────────

    /** Kopf-Felder setzen; nur bekannte Keys, leer/null löscht den Wert. */
    public function setHead(Team $team, FoodAlchemistPlanningFrame $frame, array $attrs): FoodAlchemistPlanningFrame
    {
        $this->guardWrite($team, $frame);
        $erlaubt = ['target_price_pp', 'price_min_pp', 'price_max_pp', 'note', 'status'];
        $daten = [];
        foreach ($erlaubt as $key) {
            if (array_key_exists($key, $attrs)) {
                $wert = $attrs[$key];
                $daten[$key] = ($wert === '' || $wert === null) ? null : $wert;
            }
        }
        if (($daten['status'] ?? null) !== null && ! in_array($daten['status'], ['draft', 'aktiv'], true)) {
            throw new RuntimeException('Ungültiger Gerüst-Status — erlaubt: draft|aktiv.');
        }
        if ($daten !== []) {
            $frame->update($daten);
        }

        return $frame->refresh();
    }

    // ── Slots (Dramaturgie + Mengengerüst) ─────────────────────────────

    public function addSlot(Team $team, FoodAlchemistPlanningFrame $frame, array $attrs): FoodAlchemistPlanningFrameSlot
    {
        $this->guardWrite($team, $frame);
        $label = trim((string) ($attrs['label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('Slot braucht ein Label (z. B. „Vorspeisen", „Buffet-Station Süß").');
        }
        $slotType = $attrs['slot_type'] ?? null;
        if ($slotType !== null && ! in_array($slotType, FoodAlchemistPlanningFrameSlot::SLOT_TYPES, true)) {
            throw new RuntimeException('Ungültiger slot_type — erlaubt: ' . implode('|', FoodAlchemistPlanningFrameSlot::SLOT_TYPES) . '.');
        }
        $pos = (int) ($frame->slots()->max('position') ?? -1) + 1;

        return $frame->slots()->create([
            'position' => $attrs['position'] ?? $pos,
            'label' => $label,
            'slot_type' => $slotType,
            'chapter_id' => $attrs['chapter_id'] ?? null,
            'target_count' => $attrs['target_count'] ?? null,
            'price_anchor' => $attrs['price_anchor'] ?? null,
            'price_min' => $attrs['price_min'] ?? null,
            'price_max' => $attrs['price_max'] ?? null,
            'is_pflicht' => (bool) ($attrs['is_pflicht'] ?? false),
            'note' => $attrs['note'] ?? null,
        ]);
    }

    public function updateSlot(Team $team, int $slotId, array $attrs): FoodAlchemistPlanningFrameSlot
    {
        $slot = FoodAlchemistPlanningFrameSlot::with('frame')->findOrFail($slotId);
        $this->guardWrite($team, $slot->frame);
        $erlaubt = ['position', 'label', 'slot_type', 'chapter_id', 'target_count', 'price_anchor', 'price_min', 'price_max', 'is_pflicht', 'note'];
        $daten = array_intersect_key($attrs, array_flip($erlaubt));
        foreach (['target_count', 'price_anchor', 'price_min', 'price_max', 'chapter_id', 'slot_type', 'note'] as $nullable) {
            if (array_key_exists($nullable, $daten) && ($daten[$nullable] === '' || $daten[$nullable] === null)) {
                $daten[$nullable] = null;
            }
        }
        if (array_key_exists('label', $daten) && trim((string) $daten['label']) === '') {
            unset($daten['label']); // Label nie leeren — Slot löschen ist der Weg
        }
        if (($daten['slot_type'] ?? null) !== null && ! in_array($daten['slot_type'], FoodAlchemistPlanningFrameSlot::SLOT_TYPES, true)) {
            throw new RuntimeException('Ungültiger slot_type — erlaubt: ' . implode('|', FoodAlchemistPlanningFrameSlot::SLOT_TYPES) . '.');
        }
        $slot->update($daten);

        return $slot->refresh();
    }

    public function removeSlot(Team $team, int $slotId): void
    {
        $slot = FoodAlchemistPlanningFrameSlot::with('frame')->findOrFail($slotId);
        $this->guardWrite($team, $slot->frame);
        $slot->rules()->delete();
        $slot->delete();
    }

    // ── Rules (Quoten + Kunden-Politik + Saison) ───────────────────────

    public function addRule(Team $team, FoodAlchemistPlanningFrame $frame, array $attrs): FoodAlchemistPlanningFrameRule
    {
        $this->guardWrite($team, $frame);
        $type = (string) ($attrs['rule_type'] ?? '');
        if (! in_array($type, FoodAlchemistPlanningFrameRule::RULE_TYPES, true)) {
            throw new RuntimeException('Ungültiger rule_type — erlaubt: ' . implode('|', FoodAlchemistPlanningFrameRule::RULE_TYPES) . '.');
        }
        $slotId = $attrs['slot_id'] ?? null;
        if ($slotId !== null && ! $frame->slots()->whereKey((int) $slotId)->exists()) {
            throw new RuntimeException('slot_id gehört nicht zu diesem Gerüst.');
        }

        $daten = [
            'slot_id' => $slotId,
            'rule_type' => $type,
            'ref_key' => $attrs['ref_key'] ?? null,
            'ref_id' => $attrs['ref_id'] ?? null,
            'operator' => $attrs['operator'] ?? 'min',
            'value_num' => $attrs['value_num'] ?? null,
            'unit' => $attrs['unit'] ?? null,
            'value_text' => $attrs['value_text'] ?? null,
            'severity' => $attrs['severity'] ?? null,
            'meta' => $attrs['meta'] ?? null,
        ];

        // Typ-spezifische Validierung — messbar heißt: kanonische Keys, keine freien Strings wo Vokabular existiert.
        switch ($type) {
            case 'diet_quota':
                if (! in_array($daten['ref_key'], FoodAlchemistPlanningFrameRule::DIET_FORMS, true)) {
                    throw new RuntimeException('diet_quota braucht ref_key aus: ' . implode('|', FoodAlchemistPlanningFrameRule::DIET_FORMS) . '.');
                }
                if ($daten['value_num'] === null || ! in_array($daten['unit'], FoodAlchemistPlanningFrameRule::UNITS, true)) {
                    throw new RuntimeException('diet_quota braucht value_num + unit (count|percent).');
                }
                break;
            case 'season_coverage':
                if ($daten['ref_id'] === null || FoodAlchemistSaison::visibleToTeam($team)->whereKey((int) $daten['ref_id'])->doesntExist()) {
                    throw new RuntimeException('season_coverage braucht ref_id einer team-sichtbaren Saison.');
                }
                break;
            case 'nogo_ingredient':
                if (trim((string) $daten['value_text']) === '') {
                    throw new RuntimeException('nogo_ingredient braucht value_text (Zutat/Begriff).');
                }
                break;
            case 'nogo_allergen':
                if (! in_array($daten['ref_key'], \Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS, true)) {
                    throw new RuntimeException('nogo_allergen braucht ref_key aus den EU-14-Allergen-Keys (z. B. gluten, milk, tree_nuts).');
                }
                break;
            case 'allergen_line':
                if (trim((string) $daten['value_text']) === '') {
                    throw new RuntimeException('allergen_line braucht value_text (z. B. „durchgängig glutenfreie Linie").');
                }
                break;
        }
        if (! in_array($daten['operator'], FoodAlchemistPlanningFrameRule::OPERATORS, true)) {
            throw new RuntimeException('Ungültiger operator — erlaubt: min|max|exact.');
        }
        if (in_array($type, ['nogo_ingredient', 'nogo_allergen'], true)) {
            $daten['severity'] = in_array($daten['severity'], ['hart', 'weich'], true) ? $daten['severity'] : 'hart';
        }

        return $frame->rules()->create($daten);
    }

    public function removeRule(Team $team, int $ruleId): void
    {
        $rule = FoodAlchemistPlanningFrameRule::with('frame')->findOrFail($ruleId);
        $this->guardWrite($team, $rule->frame);
        $rule->delete();
    }

    // ── Deklarativ (MCP-PUT: Brief → Gerüst in einem Call) ─────────────

    /**
     * Slots und/oder Frame-Regeln deklarativ ERSETZEN (nur was übergeben wird).
     * Slots dürfen eingebettete 'rules' tragen. Transaktional + idempotent —
     * derselbe Payload erzeugt denselben Zustand.
     */
    public function replaceStructure(Team $team, FoodAlchemistPlanningFrame $frame, ?array $slots = null, ?array $rules = null): FoodAlchemistPlanningFrame
    {
        $this->guardWrite($team, $frame);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($team, $frame, $slots, $rules) {
            if ($slots !== null) {
                $frame->rules()->whereNotNull('slot_id')->delete();
                $frame->slots()->delete();
                $frame->unsetRelation('slots');
                foreach (array_values($slots) as $i => $slotAttrs) {
                    $slotAttrs['position'] = $slotAttrs['position'] ?? $i;
                    $slotRules = $slotAttrs['rules'] ?? [];
                    unset($slotAttrs['rules']);
                    $slot = $this->addSlot($team, $frame, $slotAttrs);
                    foreach ($slotRules as $ruleAttrs) {
                        $ruleAttrs['slot_id'] = $slot->id;
                        $this->addRule($team, $frame, $ruleAttrs);
                    }
                }
            }
            if ($rules !== null) {
                $frame->rules()->whereNull('slot_id')->delete();
                foreach ($rules as $ruleAttrs) {
                    unset($ruleAttrs['slot_id']); // Frame-Ebene — Slot-Regeln laufen über slots[].rules
                    $this->addRule($team, $frame, $ruleAttrs);
                }
            }

            return $frame->refresh();
        });
    }

    // ── Ausgabe (UI / MCP / R6-Prompt) ─────────────────────────────────

    /** Strukturierte Voll-Sicht des Gerüsts (UI-State + MCP-GET). */
    public function summary(FoodAlchemistPlanningFrame $frame): array
    {
        $frame->loadMissing(['slots.rules', 'rules']);
        $ruleOut = fn (FoodAlchemistPlanningFrameRule $r) => [
            'id' => $r->id,
            'slot_id' => $r->slot_id,
            'rule_type' => $r->rule_type,
            'ref_key' => $r->ref_key,
            'ref_id' => $r->ref_id,
            'operator' => $r->operator,
            'value_num' => $r->value_num !== null ? (float) $r->value_num : null,
            'unit' => $r->unit,
            'value_text' => $r->value_text,
            'severity' => $r->severity,
        ];

        return [
            'id' => $frame->id,
            'owner_type' => $frame->owner_type,
            'owner_id' => $frame->owner_id,
            'status' => $frame->status,
            'target_price_pp' => $frame->target_price_pp !== null ? (float) $frame->target_price_pp : null,
            'price_min_pp' => $frame->price_min_pp !== null ? (float) $frame->price_min_pp : null,
            'price_max_pp' => $frame->price_max_pp !== null ? (float) $frame->price_max_pp : null,
            'note' => $frame->note,
            'slots' => $frame->slots->map(fn ($s) => [
                'id' => $s->id,
                'position' => $s->position,
                'label' => $s->label,
                'slot_type' => $s->slot_type,
                'chapter_id' => $s->chapter_id,
                'target_count' => $s->target_count,
                'price_anchor' => $s->price_anchor !== null ? (float) $s->price_anchor : null,
                'price_min' => $s->price_min !== null ? (float) $s->price_min : null,
                'price_max' => $s->price_max !== null ? (float) $s->price_max : null,
                'is_pflicht' => (bool) $s->is_pflicht,
                'note' => $s->note,
                'rules' => $s->rules->map($ruleOut)->values()->all(),
            ])->values()->all(),
            'rules' => $frame->rules->whereNull('slot_id')->map($ruleOut)->values()->all(),
        ];
    }

    /**
     * KI-Kontext-Block des Gerüsts (R6-Prompt-Material): nur gefüllte Dimensionen,
     * gelabelt und kompakt. NULL wenn das Gerüst leer ist.
     */
    public function promptKontext(FoodAlchemistPlanningFrame $frame): ?string
    {
        $frame->loadMissing(['slots.rules', 'rules']);
        $zeilen = [];

        if ($frame->target_price_pp !== null || $frame->price_min_pp !== null || $frame->price_max_pp !== null) {
            $teile = [];
            if ($frame->target_price_pp !== null) {
                $teile[] = 'Zielpreis ' . number_format((float) $frame->target_price_pp, 2, ',', '.') . ' € p. P.';
            }
            if ($frame->price_min_pp !== null || $frame->price_max_pp !== null) {
                $teile[] = 'Spanne ' . ($frame->price_min_pp !== null ? number_format((float) $frame->price_min_pp, 2, ',', '.') : '—')
                    . '–' . ($frame->price_max_pp !== null ? number_format((float) $frame->price_max_pp, 2, ',', '.') : '—') . ' € p. P.';
            }
            $zeilen[] = 'Preisarchitektur: ' . implode(' · ', $teile);
        }

        foreach ($frame->slots as $slot) {
            $teile = [];
            if ($slot->target_count !== null) {
                $teile[] = "Soll {$slot->target_count} Gerichte";
            }
            if ($slot->price_anchor !== null) {
                $teile[] = 'Preis-Anker ' . number_format((float) $slot->price_anchor, 2, ',', '.') . ' €';
            }
            if ($slot->price_min !== null || $slot->price_max !== null) {
                $teile[] = 'Spanne ' . ($slot->price_min !== null ? number_format((float) $slot->price_min, 2, ',', '.') : '—')
                    . '–' . ($slot->price_max !== null ? number_format((float) $slot->price_max, 2, ',', '.') : '—') . ' €';
            }
            if ($slot->is_pflicht) {
                $teile[] = 'Pflicht-Slot';
            }
            foreach ($slot->rules as $rule) {
                $teile[] = $this->ruleText($rule);
            }
            $zeilen[] = 'Slot ' . ($slot->position + 1) . " „{$slot->label}“"
                . ($slot->slot_type ? " ({$slot->slot_type})" : '')
                . ($teile !== [] ? ': ' . implode(' · ', $teile) : '');
        }

        foreach ($frame->rules->whereNull('slot_id') as $rule) {
            $zeilen[] = $this->ruleText($rule);
        }

        if ($frame->note !== null && trim($frame->note) !== '') {
            $zeilen[] = 'Notiz: ' . trim($frame->note);
        }

        if ($zeilen === []) {
            return null;
        }

        return "Planungs-Gerüst (verbindlicher Soll-Rahmen):\n- " . implode("\n- ", $zeilen);
    }

    private function ruleText(FoodAlchemistPlanningFrameRule $rule): string
    {
        $op = ['min' => 'mind.', 'max' => 'max.', 'exact' => 'genau'][$rule->operator] ?? $rule->operator;
        $menge = $rule->value_num !== null
            ? ($rule->unit === 'percent' ? ((float) $rule->value_num) . ' %' : ((int) $rule->value_num) . '×')
            : '';

        return match ($rule->rule_type) {
            'diet_quota' => "Diät-Quote: {$op} {$menge} {$rule->ref_key}",
            'season_coverage' => 'Saison-Abdeckung: ' . (FoodAlchemistSaison::find($rule->ref_id)?->name ?? "Saison #{$rule->ref_id}"),
            'nogo_ingredient' => "No-Go ({$rule->severity}): {$rule->value_text}",
            'nogo_allergen' => "No-Go-Allergen ({$rule->severity}): {$rule->ref_key}",
            'allergen_line' => "Allergen-Linie: {$rule->value_text}",
            default => $rule->rule_type,
        };
    }
}
