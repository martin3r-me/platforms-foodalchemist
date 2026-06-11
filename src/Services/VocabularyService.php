<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use RuntimeException;

/**
 * D-1: Vokabular-/Lookup-Pflege (stateless). M1-Ausbaustufe:
 * Einheiten (M1-02) — die generische 20-Tabellen-Familie folgt mit dem
 * Vokabular-Vollausbau (D-1 §1, V-20).
 *
 * Regeln: Tenancy via visibleToTeam (D1), Edit nur Besitzer (Curate, M1-08),
 * typisierte Fehler (V-06), Soft-Lebenszyklus is_inactive statt Löschen (AT-D1-04).
 */
class VocabularyService
{
    // ── Einheiten (M1-02) ───────────────────────────────────────────────

    public function listEinheiten(Team $team, bool $includeInactive = false): Collection
    {
        return FoodAlchemistVocabEinheit::visibleToTeam($team)
            ->when(! $includeInactive, fn ($q) => $q->where('is_inactive', false))
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get();
    }

    public function createEinheit(Team $team, array $input): FoodAlchemistVocabEinheit
    {
        $slug = Str::slug($input['slug'] ?? $input['display_de'] ?? '');
        if ($slug === '') {
            throw new RuntimeException('Einheit braucht einen Slug.');
        }
        if (FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', $slug)->exists()) {
            throw new RuntimeException("Slug [{$slug}] existiert bereits in der Team-Kette."); // V-06
        }

        return FoodAlchemistVocabEinheit::create([
            'team_id' => $team->id,
            'slug' => $slug,
            'display_de' => $input['display_de'] ?: $slug,
            'dimension' => $input['dimension'] ?: null,
            'default_in_g' => self::dezimalOrNull($input['default_in_g'] ?? null),
            'default_in_ml' => self::dezimalOrNull($input['default_in_ml'] ?? null),
            'is_approximate' => (bool) ($input['is_approximate'] ?? false),
            'sort_order' => (int) ($input['sort_order'] ?? 50),
        ]);
    }

    public function updateEinheit(Team $team, int $id, array $input): FoodAlchemistVocabEinheit
    {
        $einheit = FoodAlchemistVocabEinheit::visibleToTeam($team)->findOrFail($id);
        if (! $einheit->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Katalog-Einheit — Pflege nur durch das Besitzer-Team (D1).');
        }

        $einheit->update([
            'display_de' => $input['display_de'] ?: $einheit->display_de,
            'dimension' => $input['dimension'] ?: null,
            'default_in_g' => self::dezimalOrNull($input['default_in_g'] ?? null),
            'default_in_ml' => self::dezimalOrNull($input['default_in_ml'] ?? null),
            'is_approximate' => (bool) ($input['is_approximate'] ?? false),
            'sort_order' => (int) ($input['sort_order'] ?? $einheit->sort_order),
        ]);

        return $einheit;
    }

    public function setEinheitInactive(Team $team, int $id, bool $inactive): void
    {
        $einheit = FoodAlchemistVocabEinheit::visibleToTeam($team)->findOrFail($id);
        if (! $einheit->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Katalog-Einheit — Pflege nur durch das Besitzer-Team (D1).');
        }
        $einheit->update(['is_inactive' => $inactive]); // AT-D1-04: ausblenden statt löschen
    }

    public function deleteEinheit(Team $team, int $id): void
    {
        $einheit = FoodAlchemistVocabEinheit::visibleToTeam($team)->findOrFail($id);
        if (! $einheit->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Katalog-Einheit — Pflege nur durch das Besitzer-Team (D1).');
        }

        $referenzen = FoodAlchemistGp::where('preferred_count_unit_id', $einheit->id)->count();
        if ($referenzen > 0) {
            throw new RuntimeException("Einheit wird von {$referenzen} GP(s) referenziert — erst umhängen oder inaktiv setzen."); // V-06
        }

        $einheit->delete();
    }

    private static function dezimalOrNull(mixed $wert): ?float
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $wert);
    }
}
