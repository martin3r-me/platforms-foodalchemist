<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistKalkulation;
use Platform\FoodAlchemist\Models\FoodAlchemistKalkulationPosition;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M-K10 / Doc 16 §11: Standalone Kalkulations-Composer.
 *
 * Eine Kalkulation = Positionsliste. Positionen referenzieren Gericht / Basisrezept /
 * GP (ziehen Wareneinsatz + Arbeitszeit als Snapshot) oder sind freie Zeilen. HK1 =
 * Σ Wareneinsatz; HK2 = + Settings-Zuschläge (mehrstufig, via KalkulationService).
 * Bewusst entkoppelt vom Concepter.
 */
class KalkulationDokService
{
    public const TYPEN = ['gericht', 'basisrezept', 'gp', 'frei'];

    public function __construct(
        private KalkulationService $kalk,
        private RecipeRecomputeService $recompute,
    ) {}

    // ── Kalkulation-CRUD ────────────────────────────────────────────────────

    public function liste(Team $team)
    {
        return FoodAlchemistKalkulation::query()
            ->where('team_id', $team->id)
            ->withCount('positionen')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function create(Team $team, string $titel): FoodAlchemistKalkulation
    {
        $titel = trim($titel) !== '' ? trim($titel) : 'Neue Kalkulation';

        return FoodAlchemistKalkulation::create(['team_id' => $team->id, 'titel' => $titel]);
    }

    public function update(Team $team, int $id, array $data): FoodAlchemistKalkulation
    {
        $k = $this->find($team, $id);
        $patch = [];
        if (array_key_exists('titel', $data) && trim((string) $data['titel']) !== '') {
            $patch['titel'] = trim((string) $data['titel']);
        }
        if (array_key_exists('note', $data)) {
            $patch['note'] = $data['note'];
        }
        if (array_key_exists('marge_override_pct', $data)) {
            $v = $data['marge_override_pct'];
            $patch['marge_override_pct'] = ($v === '' || $v === null) ? null : (float) $v;
        }
        if ($patch) {
            $k->update($patch);
            $k->touch();
        }

        return $k->refresh();
    }

    public function delete(Team $team, int $id): void
    {
        $k = $this->find($team, $id);
        $k->positionen()->delete();
        $k->delete();
    }

    // ── Positionen ──────────────────────────────────────────────────────────

    /**
     * Position hinzufügen. Bei Referenz-Typen wird der Snapshot (label/einheit/
     * einzel_ek/arbeitszeit) aus der Quelle gezogen.
     */
    public function addPosition(Team $team, int $kalkulationId, string $typ, ?int $refId = null, array $override = []): FoodAlchemistKalkulationPosition
    {
        $k = $this->find($team, $kalkulationId);
        if (! in_array($typ, self::TYPEN, true)) {
            $typ = 'frei';
        }

        $snap = $typ === 'frei' ? $this->leereZeile() : $this->snapshot($team, $typ, $refId);
        $next = (int) $k->positionen()->max('position') + 1;

        $pos = $k->positionen()->create([
            'team_id' => $team->id,
            'typ' => $typ,
            'ref_id' => $typ === 'frei' ? null : $refId,
            'label' => $override['label'] ?? $snap['label'],
            'einheit' => $override['einheit'] ?? $snap['einheit'],
            'menge' => $override['menge'] ?? 1,
            'einzel_ek' => $override['einzel_ek'] ?? $snap['einzel_ek'],
            'arbeitszeit_min' => $override['arbeitszeit_min'] ?? $snap['arbeitszeit_min'],
            'position' => $next,
        ]);
        $k->touch();

        return $pos;
    }

    public function updatePosition(Team $team, int $positionId, array $data): FoodAlchemistKalkulationPosition
    {
        $pos = $this->findPosition($team, $positionId);
        $patch = [];
        foreach (['label', 'einheit'] as $f) {
            if (array_key_exists($f, $data)) {
                $patch[$f] = $data[$f];
            }
        }
        foreach (['menge', 'einzel_ek'] as $f) {
            if (array_key_exists($f, $data)) {
                $patch[$f] = max(0, (float) str_replace(',', '.', (string) $data[$f]));
            }
        }
        if (array_key_exists('arbeitszeit_min', $data)) {
            $v = $data['arbeitszeit_min'];
            $patch['arbeitszeit_min'] = ($v === '' || $v === null) ? null : max(0, (int) $v);
        }
        if ($patch) {
            $pos->update($patch);
            $pos->kalkulation?->touch();
        }

        return $pos->refresh();
    }

    public function removePosition(Team $team, int $positionId): void
    {
        $pos = $this->findPosition($team, $positionId);
        $k = $pos->kalkulation;
        $pos->delete();
        $k?->touch();
    }

    /** Snapshot aus der Quelle neu ziehen (z. B. nach Preisänderung). */
    public function refreshPosition(Team $team, int $positionId): FoodAlchemistKalkulationPosition
    {
        $pos = $this->findPosition($team, $positionId);
        if ($pos->typ !== 'frei' && $pos->ref_id) {
            $snap = $this->snapshot($team, $pos->typ, (int) $pos->ref_id);
            $pos->update(['einzel_ek' => $snap['einzel_ek'], 'arbeitszeit_min' => $snap['arbeitszeit_min']]);
            $pos->kalkulation?->touch();
        }

        return $pos->refresh();
    }

    // ── Berechnung ──────────────────────────────────────────────────────────

    /**
     * Vollständige Kalkulation: Positionen → HK1 (Σ Wareneinsatz) + Arbeitszeit-Rollup
     * → Settings-Zuschläge (mehrstufig) → HK2 → VK-Vorschlag (Marge bzw. Override).
     *
     * @return array{positionen: list<array>, hk1: float, arbeitszeit_min: float,
     *               bloecke: list<array>, hk2: float, marge_pct: float, vk_vorschlag: float}
     */
    public function berechne(Team $team, FoodAlchemistKalkulation $kalkulation): array
    {
        $positionen = $kalkulation->positionen()->get();

        $hk1 = 0.0;
        $azTotal = 0.0;
        $zeilen = [];
        foreach ($positionen as $p) {
            $we = $p->wareneinsatz();
            $az = (float) (($p->arbeitszeit_min ?? 0)) * (float) $p->menge;
            $hk1 += $we;
            $azTotal += $az;
            $zeilen[] = [
                'id' => $p->id,
                'typ' => $p->typ,
                'ref_id' => $p->ref_id,
                'label' => $p->label,
                'einheit' => $p->einheit,
                'menge' => (float) $p->menge,
                'einzel_ek' => (float) $p->einzel_ek,
                'arbeitszeit_min' => $p->arbeitszeit_min !== null ? (int) $p->arbeitszeit_min : null,
                'wareneinsatz' => $we,
            ];
        }

        $r = $this->kalk->berechne($team, $hk1, $azTotal, 0.0);

        $marge = $kalkulation->marge_override_pct !== null
            ? (float) $kalkulation->marge_override_pct
            : (float) $r['marge_pct'];
        $vkVorschlag = round((float) $r['hk2'] * (1 + $marge / 100), 2);

        return [
            'positionen' => $zeilen,
            'hk1' => round($hk1, 4),
            'arbeitszeit_min' => round($azTotal, 2),
            'bloecke' => $r['bloecke'],
            'hk2' => (float) $r['hk2'],
            'marge_pct' => $marge,
            'vk_vorschlag' => $vkVorschlag,
        ];
    }

    // ── Picker-Quellen (für den Editor) ──────────────────────────────────────

    /** Auswählbare Quellen je Typ (id + label + Einheit + Einzel-EK-Vorschau). */
    public function quellen(Team $team, string $typ, string $suche = '', int $limit = 30): array
    {
        $suche = trim($suche);

        if ($typ === 'gp') {
            $q = FoodAlchemistGp::query()->where('team_id', $team->id);
            if ($suche !== '') {
                $q->where('name', 'like', "%{$suche}%");
            }

            return $q->orderBy('name')->limit($limit)->get()
                ->map(fn ($gp) => ['id' => $gp->id, 'label' => (string) $gp->name])->all();
        }

        $q = FoodAlchemistRecipe::query()->where('team_id', $team->id);
        $q = $typ === 'basisrezept' ? $q->basis() : $q->verkauf();
        if ($suche !== '') {
            $q->where('name', 'like', "%{$suche}%");
        }

        return $q->orderBy('name')->limit($limit)->get()
            ->map(fn ($r) => ['id' => $r->id, 'label' => (string) $r->name])->all();
    }

    // ── intern ────────────────────────────────────────────────────────────────

    /**
     * Snapshot der Einzel-Daten einer Quelle.
     *
     * @return array{label: string, einheit: string, einzel_ek: float, arbeitszeit_min: ?int}
     */
    private function snapshot(Team $team, string $typ, ?int $refId): array
    {
        if ($refId === null) {
            return $this->leereZeile();
        }

        if ($typ === 'gp') {
            $gp = FoodAlchemistGp::where('team_id', $team->id)->find($refId);
            $proKg = $gp ? (($this->recompute->preisProGrammPublic($gp) ?? 0) * 1000) : 0.0;

            return ['label' => $gp?->name ?? 'GP', 'einheit' => 'kg', 'einzel_ek' => round($proKg, 4), 'arbeitszeit_min' => null];
        }

        $r = FoodAlchemistRecipe::where('team_id', $team->id)->find($refId);
        if (! $r) {
            return $this->leereZeile();
        }

        if ($typ === 'basisrezept') {
            // €/kg; Arbeitszeit pro kg bleibt offen (nicht-linear → KI), daher null.
            return [
                'label' => (string) $r->name,
                'einheit' => 'kg',
                'einzel_ek' => round((float) ($r->ek_per_kg_eur ?? 0), 4),
                'arbeitszeit_min' => null,
            ];
        }

        // Gericht (Verkaufsrezept): pro Portion.
        $anzahl = max(1, (int) ($r->vk_anzahl_einheiten ?? 1));

        return [
            'label' => (string) $r->name,
            'einheit' => 'Portion',
            'einzel_ek' => round((float) ($r->ek_total_eur ?? 0) / $anzahl, 4),
            'arbeitszeit_min' => $r->arbeitszeit_min !== null ? (int) round((float) $r->arbeitszeit_min / $anzahl) : null,
        ];
    }

    private function leereZeile(): array
    {
        return ['label' => 'Freie Position', 'einheit' => null, 'einzel_ek' => 0.0, 'arbeitszeit_min' => null];
    }

    private function find(Team $team, int $id): FoodAlchemistKalkulation
    {
        return FoodAlchemistKalkulation::where('team_id', $team->id)->findOrFail($id);
    }

    private function findPosition(Team $team, int $id): FoodAlchemistKalkulationPosition
    {
        return FoodAlchemistKalkulationPosition::where('team_id', $team->id)->findOrFail($id);
    }
}
