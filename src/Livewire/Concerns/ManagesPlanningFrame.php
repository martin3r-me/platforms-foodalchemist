<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use RuntimeException;

/**
 * R4.1: Wiederverwendbarer Planungs-Gerüst-State für Livewire-Hosts (Foodbook,
 * Concepter) — Muster wie ManagesCanvas: genau EIN Gerüst je Hostkomponente,
 * gerendert über `foodalchemist::livewire.planning.partials.frame-board`.
 * Das Gerüst ist die messbare Soll-Ebene neben dem Freitext-Canvas.
 */
trait ManagesPlanningFrame
{
    public string $frameOwnerType = '';

    public ?int $frameOwnerId = null;

    public ?int $frameId = null;

    /** @var array<string,string> Kopf-Felder (Preisarchitektur p. P. + Notiz) */
    public array $frameHead = ['target_price_pp' => '', 'price_min_pp' => '', 'price_max_pp' => '', 'note' => ''];

    /** @var list<array<string,mixed>> Slots (editierbar, wire:model je Zeile) */
    public array $frameSlots = [];

    /** @var list<array<string,mixed>> Frame-Regeln (slot_id NULL) */
    public array $frameRules = [];

    public array $frameNeuSlot = ['label' => '', 'slot_type' => '', 'target_count' => '', 'price_anchor' => '', 'price_min' => '', 'price_max' => '', 'is_pflicht' => false];

    public array $frameNeuRule = ['rule_type' => 'diet_quota', 'slot_id' => '', 'ref_key' => '', 'ref_id' => '', 'operator' => 'min', 'value_num' => '', 'unit' => 'count', 'value_text' => '', 'severity' => 'hart'];

    public bool $frameGespeichert = false;

    public ?string $frameFehler = null;

    protected function frameInit(string $ownerType, ?int $ownerId): void
    {
        $this->frameOwnerType = $ownerType;
        $this->frameOwnerId = $ownerId;
        $this->frameGespeichert = false;
        $this->frameFehler = null;
        $this->frameLaden();
    }

    protected function frameTeam(): Team
    {
        return Auth::user()->currentTeamRelation;
    }

    public function frameLaden(): void
    {
        $this->frameHead = ['target_price_pp' => '', 'price_min_pp' => '', 'price_max_pp' => '', 'note' => ''];
        $this->frameSlots = [];
        $this->frameRules = [];
        $this->frameId = null;
        if ($this->frameOwnerType === '' || $this->frameOwnerId === null) {
            return;
        }
        // Lese-Pfad: NICHT eager anlegen — das Gerüst entsteht erst beim ersten Speichern.
        $frame = app(PlanningFrameService::class)->find($this->frameOwnerType, $this->frameOwnerId);
        if ($frame === null) {
            return;
        }
        $this->frameId = $frame->id;
        $summary = app(PlanningFrameService::class)->summary($frame);
        $this->frameHead = [
            'target_price_pp' => $summary['target_price_pp'] !== null ? (string) $summary['target_price_pp'] : '',
            'price_min_pp' => $summary['price_min_pp'] !== null ? (string) $summary['price_min_pp'] : '',
            'price_max_pp' => $summary['price_max_pp'] !== null ? (string) $summary['price_max_pp'] : '',
            'note' => (string) ($summary['note'] ?? ''),
        ];
        $this->frameSlots = array_map(fn ($s) => [
            'id' => $s['id'],
            'label' => (string) $s['label'],
            'slot_type' => (string) ($s['slot_type'] ?? ''),
            'target_count' => $s['target_count'] !== null ? (string) $s['target_count'] : '',
            'price_anchor' => $s['price_anchor'] !== null ? (string) $s['price_anchor'] : '',
            'price_min' => $s['price_min'] !== null ? (string) $s['price_min'] : '',
            'price_max' => $s['price_max'] !== null ? (string) $s['price_max'] : '',
            'is_pflicht' => (bool) $s['is_pflicht'],
            'rules' => $s['rules'],
        ], $summary['slots']);
        $this->frameRules = $summary['rules'];
    }

    private function frameOderAnlegen(): FoodAlchemistPlanningFrame
    {
        $svc = app(PlanningFrameService::class);
        $frame = $svc->frameFor($this->frameTeam(), $this->frameOwnerType, (int) $this->frameOwnerId);
        $this->frameId = $frame->id;

        return $frame;
    }

    public function frameKopfSpeichern(): void
    {
        $this->frameFehler = null;
        try {
            app(PlanningFrameService::class)->setHead($this->frameTeam(), $this->frameOderAnlegen(), $this->frameHead);
            $this->frameGespeichert = true;
            $this->frameLaden();
        } catch (RuntimeException $e) {
            $this->frameFehler = $e->getMessage();
        }
    }

    public function frameSlotHinzu(): void
    {
        $this->frameFehler = null;
        try {
            app(PlanningFrameService::class)->addSlot($this->frameTeam(), $this->frameOderAnlegen(), $this->frameNeuSlot);
            $this->frameNeuSlot = ['label' => '', 'slot_type' => '', 'target_count' => '', 'price_anchor' => '', 'price_min' => '', 'price_max' => '', 'is_pflicht' => false];
            $this->frameLaden();
        } catch (RuntimeException $e) {
            $this->frameFehler = $e->getMessage();
        }
    }

    public function frameSlotSpeichern(int $index): void
    {
        $this->frameFehler = null;
        $zeile = $this->frameSlots[$index] ?? null;
        if ($zeile === null || $this->frameId === null) {
            return;
        }
        try {
            app(PlanningFrameService::class)->updateSlot($this->frameTeam(), (int) $zeile['id'], $zeile);
            $this->frameGespeichert = true;
            $this->frameLaden();
        } catch (RuntimeException $e) {
            $this->frameFehler = $e->getMessage();
        }
    }

    public function frameSlotLoeschen(int $slotId): void
    {
        $this->frameFehler = null;
        try {
            app(PlanningFrameService::class)->removeSlot($this->frameTeam(), $slotId);
            $this->frameLaden();
        } catch (RuntimeException $e) {
            $this->frameFehler = $e->getMessage();
        }
    }

    public function frameRegelHinzu(): void
    {
        $this->frameFehler = null;
        try {
            $attrs = $this->frameNeuRule;
            foreach (['slot_id', 'ref_key', 'ref_id', 'value_num', 'value_text', 'severity', 'unit'] as $key) {
                if (($attrs[$key] ?? '') === '') {
                    $attrs[$key] = null;
                }
            }
            app(PlanningFrameService::class)->addRule($this->frameTeam(), $this->frameOderAnlegen(), $attrs);
            $this->frameNeuRule = ['rule_type' => $this->frameNeuRule['rule_type'], 'slot_id' => '', 'ref_key' => '', 'ref_id' => '', 'operator' => 'min', 'value_num' => '', 'unit' => 'count', 'value_text' => '', 'severity' => 'hart'];
            $this->frameLaden();
        } catch (RuntimeException $e) {
            $this->frameFehler = $e->getMessage();
        }
    }

    public function frameRegelLoeschen(int $ruleId): void
    {
        $this->frameFehler = null;
        try {
            app(PlanningFrameService::class)->removeRule($this->frameTeam(), $ruleId);
            $this->frameLaden();
        } catch (RuntimeException $e) {
            $this->frameFehler = $e->getMessage();
        }
    }

    /** Vokabulare fürs Partial (Selects). */
    public function framePlanningVokabular(): array
    {
        return [
            'diet_forms' => FoodAlchemistPlanningFrameRule::DIET_FORMS,
            'allergens' => FoodAlchemistGp::ALLERGEN_FIELDS,
            'slot_types' => FoodAlchemistPlanningFrameSlot::SLOT_TYPES,
            'seasons' => FoodAlchemistSaison::visibleToTeam($this->frameTeam())
                ->where('is_inactive', false)->orderBy('name')->get(['id', 'name']),
            'rule_types' => [
                'diet_quota' => 'Diät-Quote',
                'season_coverage' => 'Saison-Abdeckung',
                'nogo_ingredient' => 'No-Go Zutat',
                'nogo_allergen' => 'No-Go Allergen',
                'allergen_line' => 'Allergen-Linie',
            ],
        ];
    }

    /** Lesbare Kurzform einer Regel (Chips im Board). */
    public function frameRegelLabel(array $r): string
    {
        $op = ['min' => 'mind.', 'max' => 'max.', 'exact' => 'genau'][$r['operator'] ?? 'min'] ?? '';
        $menge = ($r['value_num'] ?? null) !== null
            ? (($r['unit'] ?? '') === 'percent' ? $r['value_num'] . ' %' : (int) $r['value_num'] . '×')
            : '';

        return match ($r['rule_type']) {
            'diet_quota' => trim("{$op} {$menge} " . ($r['ref_key'] ?? '')),
            'season_coverage' => 'Saison: ' . (FoodAlchemistSaison::find($r['ref_id'])?->name ?? ('#' . $r['ref_id'])),
            'nogo_ingredient' => 'No-Go: ' . ($r['value_text'] ?? ''),
            'nogo_allergen' => 'No-Go-Allergen: ' . ($r['ref_key'] ?? ''),
            'allergen_line' => 'Linie: ' . ($r['value_text'] ?? ''),
            default => (string) $r['rule_type'],
        };
    }
}
