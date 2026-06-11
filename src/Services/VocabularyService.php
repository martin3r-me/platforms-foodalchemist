<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
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

    private static function dezimalOrNull(mixed $wert): ?float
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $wert);
    }
}
