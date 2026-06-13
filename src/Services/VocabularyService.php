<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
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
            'display_de' => ($input['display_de'] ?? '') ?: $slug,
            'dimension' => ($input['dimension'] ?? '') ?: null,
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
            'display_de' => ($input['display_de'] ?? '') ?: $einheit->display_de,
            'dimension' => ($input['dimension'] ?? '') ?: null,
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

    // ── Warengruppen & Sub-Kategorien (M1-03, Regelwerk GP §3) ─────────

    /** Die 15 fachlichen §3-Warengruppen — Codes sind FIX, nie löschbar. */
    public const PARAGRAF3_CODES = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15'];

    /** WG-Liste mit GP-Zählern je Team-Kette (read-mostly). */
    public function listWarengruppen(Team $team): Collection
    {
        $counts = FoodAlchemistGp::visibleToTeam($team)
            ->selectRaw('warengruppe_code, COUNT(*) AS n')
            ->groupBy('warengruppe_code')
            ->pluck('n', 'warengruppe_code');

        return FoodAlchemistLookupWarengruppe::visibleToTeam($team)
            ->orderBy('sort_order')->orderBy('code')
            ->get()
            ->each(fn ($wg) => $wg->setAttribute('gp_count', (int) ($counts[$wg->code] ?? 0)));
    }

    /** Name pflegen (read-MOSTLY) — der §3-Code selbst ist unantastbar. */
    public function updateWarengruppeName(Team $team, int $id, string $name): FoodAlchemistLookupWarengruppe
    {
        $wg = FoodAlchemistLookupWarengruppe::visibleToTeam($team)->findOrFail($id);
        if (! $wg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Warengruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        if (trim($name) === '') {
            throw new RuntimeException('Warengruppen-Name darf nicht leer sein.');
        }
        $wg->update(['name' => trim($name)]);

        return $wg;
    }

    /** §3-Codes sind fix: Löschen wird IMMER verweigert (Regelwerk GP §3). */
    public function deleteWarengruppe(Team $team, int $id): void
    {
        $wg = FoodAlchemistLookupWarengruppe::visibleToTeam($team)->findOrFail($id);
        if (in_array($wg->code, self::PARAGRAF3_CODES, true)) {
            throw new RuntimeException("Warengruppe {$wg->code} ist ein fixer §3-Code — nicht löschbar (Regelwerk GP §3).");
        }
        if (! $wg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Warengruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        if (FoodAlchemistGp::where('warengruppe_code', $wg->code)->exists()) {
            throw new RuntimeException('Warengruppe wird von GPs referenziert — nicht löschbar.'); // V-06
        }
        $wg->delete();
    }

    /** Sub-Kategorie-Übersicht: distinct Werte + GP-Zähler je Team-Kette (D-1 §1). */
    public function listSubCategories(Team $team, ?string $warengruppeCode = null): Collection
    {
        return FoodAlchemistGp::visibleToTeam($team)
            ->whereNotNull('sub_kategorie')
            ->when($warengruppeCode, fn ($q) => $q->where('warengruppe_code', $warengruppeCode))
            ->selectRaw('warengruppe_code, sub_kategorie, COUNT(*) AS n')
            ->groupBy('warengruppe_code', 'sub_kategorie')
            ->orderBy('warengruppe_code')->orderBy('sub_kategorie')
            ->get();
    }

    /** Rename propagiert auf die GPs (AT-D1-03) — via D-3-Service, nur eigene Zeilen. */
    public function renameSubCategory(Team $team, string $warengruppeCode, string $alt, string $neu): int
    {
        $neu = trim($neu);
        if ($neu === '') {
            throw new RuntimeException('Neuer Sub-Kategorie-Name darf nicht leer sein.');
        }

        return app(GpService::class)->renameSubKategorie($team, $warengruppeCode, $alt, $neu);
    }

    /** Wert auf NULL setzen (Housekeeping) — via D-3-Service, nur eigene Zeilen. */
    public function clearSubCategory(Team $team, string $warengruppeCode, string $wert): int
    {
        return app(GpService::class)->clearSubKategorie($team, $warengruppeCode, $wert);
    }

    // ── Produktions-Taxonomie (M1-04, D-1) — von M4-Browser-Bäumen gelesen ──

    /** Hauptgruppen mit Kategorie-Zählern, sortiert (M4-Baum-Quelle). */
    public function listMainGroups(Team $team): Collection
    {
        $counts = FoodAlchemistRecipeCategory::visibleToTeam($team)
            ->selectRaw('main_group_id, COUNT(*) AS n')
            ->groupBy('main_group_id')
            ->pluck('n', 'main_group_id');

        return FoodAlchemistRecipeMainGroup::visibleToTeam($team)
            ->orderBy('sort_order')->orderBy('code')
            ->get()
            ->each(fn ($hg) => $hg->setAttribute('kategorie_count', (int) ($counts[$hg->id] ?? 0)));
    }

    public function listRecipeCategories(Team $team, ?int $mainGroupId = null): Collection
    {
        return FoodAlchemistRecipeCategory::visibleToTeam($team)
            ->when($mainGroupId, fn ($q) => $q->where('main_group_id', $mainGroupId))
            ->orderBy('sort_order')->orderBy('bezeichnung')
            ->get()
            ->each(fn ($kat) => $kat->setAttribute('recipe_count', $this->recipeCount($kat->id)));
    }

    /**
     * Hauptgruppe (oberste Ebene der Rezept-Taxonomie) anlegen. Bisher fehlte das — Kategorien
     * konnten nur INNERHALB einer HG entstehen, eine neue HG gar nicht (Bug 2026-06-14).
     * `code` = Slug der Bezeichnung, team-eindeutig (unique[team_id, code]); `bereich` optional.
     */
    public function createMainGroup(Team $team, array $input): FoodAlchemistRecipeMainGroup
    {
        $bezeichnung = trim($input['bezeichnung'] ?? '');
        if ($bezeichnung === '') {
            throw new RuntimeException('Hauptgruppe braucht eine Bezeichnung.');
        }

        $basis = Str::slug($bezeichnung, '_') ?: 'hauptgruppe';
        $code = $basis;
        $i = 2;
        while (FoodAlchemistRecipeMainGroup::where('team_id', $team->id)->where('code', $code)->exists()) {
            $code = $basis.'_'.$i++;
        }

        $maxSort = FoodAlchemistRecipeMainGroup::where('team_id', $team->id)->max('sort_order');

        return FoodAlchemistRecipeMainGroup::create([
            'team_id' => $team->id,
            'code' => $code,
            'bezeichnung' => $bezeichnung,
            'bereich' => ($input['bereich'] ?? '') ?: null,
            'sort_order' => (int) ($input['sort_order'] ?? ((int) $maxSort + 1)),
        ]);
    }

    public function createRecipeCategory(Team $team, int $mainGroupId, array $input): FoodAlchemistRecipeCategory
    {
        $hg = FoodAlchemistRecipeMainGroup::visibleToTeam($team)->findOrFail($mainGroupId);
        $bezeichnung = trim($input['bezeichnung'] ?? '');
        if ($bezeichnung === '') {
            throw new RuntimeException('Kategorie braucht eine Bezeichnung.');
        }

        return FoodAlchemistRecipeCategory::create([
            'team_id' => $team->id,
            'main_group_id' => $hg->id,
            'code' => Str::slug($bezeichnung, '_'),
            'bezeichnung' => $bezeichnung,
            'technik' => ($input['technik'] ?? '') ?: null,
            'sort_order' => (int) ($input['sort_order'] ?? 999),
        ]);
    }

    public function updateRecipeCategory(Team $team, int $id, array $input): FoodAlchemistRecipeCategory
    {
        $kat = FoodAlchemistRecipeCategory::visibleToTeam($team)->findOrFail($id);
        if (! $kat->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Kategorie — Pflege nur durch das Besitzer-Team (D1).');
        }
        $kat->update([
            'bezeichnung' => ($input['bezeichnung'] ?? '') ?: $kat->bezeichnung,
            'technik' => ($input['technik'] ?? '') ?: null,
            'sort_order' => (int) ($input['sort_order'] ?? $kat->sort_order),
        ]);

        return $kat;
    }

    /** Delete-Guard AT-D1-02: blockt bei recipe_count > 0 (sobald M4-01 die Tabelle bringt). */
    public function deleteRecipeCategory(Team $team, int $id): void
    {
        $kat = FoodAlchemistRecipeCategory::visibleToTeam($team)->findOrFail($id);
        if (! $kat->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Kategorie — Pflege nur durch das Besitzer-Team (D1).');
        }
        $n = $this->recipeCount($kat->id);
        if ($n > 0) {
            throw new RuntimeException("Kategorie hat {$n} Rezept(e) — erst mergen/umhängen (AT-D1-02).");
        }
        $kat->delete();
    }

    public function updateMainGroupSort(Team $team, int $id, int $sortOrder): void
    {
        $hg = FoodAlchemistRecipeMainGroup::visibleToTeam($team)->findOrFail($id);
        if (! $hg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Hauptgruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        $hg->update(['sort_order' => $sortOrder]);
    }

    /** Abgeleitet — echte Zählung sobald foodalchemist_recipes existiert (M4-01). */
    private function recipeCount(int $kategorieId): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_recipes')) {
            return 0;
        }

        return (int) \Illuminate\Support\Facades\DB::table('foodalchemist_recipes')
            ->where('recipe_category_id', $kategorieId)->whereNull('deleted_at')->count();
    }

    private static function dezimalOrNull(mixed $wert): ?float
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $wert);
    }
}
