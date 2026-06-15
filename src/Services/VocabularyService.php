<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
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

    /**
     * Die 15 kanonischen §3-Warengruppen — seit 2026-06-15 nur noch EMPFEHLUNG/Seed,
     * nicht mehr unveränderlich (Entscheid Dominique: jeder Kunde ist anders, wir geben
     * das Set als Vorschlag mit). Teams dürfen eigene WG anlegen/umbenennen/löschen.
     * Schutz läuft NICHT mehr über diese Liste, sondern über den GP-Referenz-Guard:
     * eine WG mit verknüpften GPs ist nicht löschbar (egal ob kanonisch oder custom).
     * Regelwerk_Grundprodukte §3 → v3.4.
     */
    public const PARAGRAF3_CODES = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15'];

    /** Ist dieser Code eine der 15 kanonischen §3-WG (für UI-Kennzeichnung „Empfehlung")? */
    public function istKanonischeWarengruppe(string $code): bool
    {
        return in_array($code, self::PARAGRAF3_CODES, true);
    }

    /**
     * Eigene Warengruppe anlegen (team-eigen). Code = bereinigter Vorgabe-Code oder
     * Slug der Bezeichnung (≤8 Z., GROSS), team-eindeutig. Custom-WG haben keine
     * §8-Pflichtangaben/Necta-Regel (reine Team-Klassifikation, Regelwerk §3 v3.4).
     */
    public function createWarengruppe(Team $team, string $name, ?string $code = null): FoodAlchemistLookupWarengruppe
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Warengruppe braucht einen Namen.');
        }
        $code = mb_strtoupper(trim((string) ($code ?? '')));
        if ($code === '') {
            $code = mb_strtoupper(Str::slug($name, '')) ?: 'WG';
        }
        $code = mb_substr($code, 0, 8);
        $basis = $code;
        $i = 2;
        while (FoodAlchemistLookupWarengruppe::where('team_id', $team->id)->where('code', $code)->exists()) {
            $suffix = (string) $i++;
            $code = mb_substr($basis, 0, max(1, 8 - mb_strlen($suffix))).$suffix;
        }
        $maxSort = FoodAlchemistLookupWarengruppe::where('team_id', $team->id)->max('sort_order');

        return FoodAlchemistLookupWarengruppe::create([
            'team_id' => $team->id,
            'code' => $code,
            'name' => $name,
            'sort_order' => (int) $maxSort + 1,
        ]);
    }

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

    /**
     * WG löschen (v3.4): hart wenn UNBENUTZT, sonst gesperrt. Kein §3-Sonderfall mehr —
     * der GP-Referenz-Guard schützt die genutzten (kanonischen wie custom) automatisch.
     */
    public function deleteWarengruppe(Team $team, int $id): void
    {
        $wg = FoodAlchemistLookupWarengruppe::visibleToTeam($team)->findOrFail($id);
        if (! $wg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Warengruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        $n = FoodAlchemistGp::where('warengruppe_code', $wg->code)->whereNull('deleted_at')->count();
        if ($n > 0) {
            throw new RuntimeException("Warengruppe wird von {$n} GP(s) genutzt — erst umhängen, dann löschen."); // V-06
        }
        $wg->delete();
    }

    /**
     * Sub-Kategorie-Übersicht (#371): verwaltete Einträge (anlegbar) + vorhandene GP-Freitext-
     * werte (Bestand), gemerged je Warengruppe. Felder bleiben `sub_kategorie` + `n` (GP-Zähler)
     * UI-kompatibel; `managed_id` markiert verwaltete Einträge.
     */
    public function listSubCategories(Team $team, ?string $warengruppeCode = null): \Illuminate\Support\Collection
    {
        $gpRows = FoodAlchemistGp::visibleToTeam($team)
            ->whereNotNull('sub_kategorie')
            ->when($warengruppeCode, fn ($q) => $q->where('warengruppe_code', $warengruppeCode))
            ->selectRaw('warengruppe_code, sub_kategorie, COUNT(*) AS n')
            ->groupBy('warengruppe_code', 'sub_kategorie')
            ->get();
        $counts = $gpRows->keyBy(fn ($r) => $r->warengruppe_code.'|'.$r->sub_kategorie);

        $managed = FoodAlchemistWarengruppeSubkategorie::visibleToTeam($team)
            ->when($warengruppeCode, fn ($q) => $q->where('warengruppe_code', $warengruppeCode))
            ->orderBy('position')->orderBy('name')->get();

        $out = collect();
        $gesehen = [];
        foreach ($managed as $m) {
            $key = $m->warengruppe_code.'|'.$m->name;
            $gesehen[$key] = true;
            $out->push((object) [
                'warengruppe_code' => $m->warengruppe_code,
                'sub_kategorie' => $m->name,
                'n' => (int) ($counts[$key]->n ?? 0),
                'managed_id' => $m->id,
            ]);
        }
        foreach ($gpRows->sortBy('sub_kategorie') as $r) {  // Bestands-Freitextwerte (noch nicht verwaltet)
            $key = $r->warengruppe_code.'|'.$r->sub_kategorie;
            if (! isset($gesehen[$key])) {
                $out->push((object) [
                    'warengruppe_code' => $r->warengruppe_code,
                    'sub_kategorie' => $r->sub_kategorie,
                    'n' => (int) $r->n,
                    'managed_id' => null,
                ]);
            }
        }

        return $out;
    }

    /**
     * #371: verwaltete Sub-Kategorie zu einer Warengruppe anlegen (ohne GP nötig). Die §3-Codes
     * der Warengruppe bleiben fix — nur die Sub-Kategorie-Liste ist pflegbar.
     */
    public function createSubCategory(Team $team, string $warengruppeCode, string $name): FoodAlchemistWarengruppeSubkategorie
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Sub-Kategorie braucht einen Namen.');
        }
        if ($warengruppeCode === '') {
            throw new RuntimeException('Erst eine Warengruppe wählen.');
        }
        $vorhanden = FoodAlchemistWarengruppeSubkategorie::where('team_id', $team->id)
            ->where('warengruppe_code', $warengruppeCode)->where('name', $name)->exists();
        if ($vorhanden) {
            throw new RuntimeException('Diese Sub-Kategorie gibt es in der Warengruppe schon.');
        }
        $maxPos = FoodAlchemistWarengruppeSubkategorie::where('team_id', $team->id)
            ->where('warengruppe_code', $warengruppeCode)->max('position');

        return FoodAlchemistWarengruppeSubkategorie::create([
            'team_id' => $team->id,
            'warengruppe_code' => $warengruppeCode,
            'name' => $name,
            'position' => (int) $maxPos + 1,
        ]);
    }

    /** Rename propagiert auf die GPs (AT-D1-03) — via D-3-Service, nur eigene Zeilen. */
    public function renameSubCategory(Team $team, string $warengruppeCode, string $alt, string $neu): int
    {
        $neu = trim($neu);
        if ($neu === '') {
            throw new RuntimeException('Neuer Sub-Kategorie-Name darf nicht leer sein.');
        }

        // #371: verwalteten Eintrag mitziehen, damit Liste und GP-Werte synchron bleiben.
        FoodAlchemistWarengruppeSubkategorie::where('team_id', $team->id)
            ->where('warengruppe_code', $warengruppeCode)->where('name', $alt)
            ->update(['name' => $neu]);

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

    /**
     * #372: Speisen-Hauptgruppe (VK-Taxonomie) anlegen — bisher read-only Referenzdaten.
     * `code` = Slug der Bezeichnung (≤16 Zeichen, team-eindeutig). Diätform-Flags pflegen
     * Klassen, nicht die HG.
     */
    public function createDishMainGroup(Team $team, array $input): FoodAlchemistDishMainGroup
    {
        $bezeichnung = trim($input['bezeichnung'] ?? '');
        if ($bezeichnung === '') {
            throw new RuntimeException('Speisen-Hauptgruppe braucht eine Bezeichnung.');
        }

        $basis = mb_substr(Str::slug($bezeichnung, '_') ?: 'hg', 0, 14);
        $code = $basis;
        $i = 2;
        while (FoodAlchemistDishMainGroup::where('team_id', $team->id)->where('code', $code)->exists()) {
            $code = mb_substr($basis, 0, 12).'_'.$i++;
        }

        return FoodAlchemistDishMainGroup::create([
            'team_id' => $team->id,
            'code' => $code,
            'bezeichnung' => $bezeichnung,
            'sort_order' => (int) FoodAlchemistDishMainGroup::max('sort_order') + 1,
        ]);
    }

    /**
     * #372: Speisen-Klasse zu einer Hauptgruppe anlegen. `diaetform` aus der festen Liste
     * (fleisch|fisch|vegi|vegan|neutral|allergie); is_vegi/is_vegan werden daraus abgeleitet.
     */
    public function createDishClass(Team $team, int $mainGroupId, array $input): FoodAlchemistDishClass
    {
        $hg = FoodAlchemistDishMainGroup::findOrFail($mainGroupId);
        $bezeichnung = trim($input['bezeichnung'] ?? '');
        if ($bezeichnung === '') {
            throw new RuntimeException('Klasse braucht eine Bezeichnung.');
        }

        $diaet = in_array($input['diaetform'] ?? '', ['fleisch', 'fisch', 'vegi', 'vegan', 'neutral', 'allergie'], true)
            ? $input['diaetform'] : 'neutral';

        $basis = mb_substr(Str::slug($bezeichnung, '_') ?: 'klasse', 0, 30);
        $code = $basis;
        $i = 2;
        while (FoodAlchemistDishClass::where('team_id', $team->id)->where('code', $code)->exists()) {
            $code = mb_substr($basis, 0, 28).'_'.$i++;
        }

        return FoodAlchemistDishClass::create([
            'team_id' => $team->id,
            'dish_main_group_id' => $hg->id,
            'code' => $code,
            'bezeichnung' => $bezeichnung,
            'diaetform' => $diaet,
            'is_vegi' => in_array($diaet, ['vegi', 'vegan'], true),
            'is_vegan' => $diaet === 'vegan',
        ]);
    }

    /** VK-Hauptgruppe umbenennen (Code bleibt stabil — er ist Namings-Präfix, D-6 §4.4). */
    public function updateDishMainGroup(Team $team, int $id, string $name): FoodAlchemistDishMainGroup
    {
        $hg = FoodAlchemistDishMainGroup::visibleToTeam($team)->findOrFail($id);
        if (! $hg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Speisen-Hauptgruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        if (trim($name) === '') {
            throw new RuntimeException('Bezeichnung darf nicht leer sein.');
        }
        $hg->update(['bezeichnung' => trim($name)]);

        return $hg;
    }

    /** VK-Hauptgruppe löschen: hart wenn keine Klassen hängen, sonst gesperrt. */
    public function deleteDishMainGroup(Team $team, int $id): void
    {
        $hg = FoodAlchemistDishMainGroup::visibleToTeam($team)->findOrFail($id);
        if (! $hg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Speisen-Hauptgruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        $n = FoodAlchemistDishClass::where('dish_main_group_id', $hg->id)->whereNull('deleted_at')->count();
        if ($n > 0) {
            throw new RuntimeException("Hauptgruppe hat {$n} Klasse(n) — erst dort entfernen, dann löschen.");
        }
        $hg->delete();
    }

    /** VK-Klasse umbenennen (+ Diätform; is_vegi/is_vegan werden neu abgeleitet). Code stabil. */
    public function updateDishClass(Team $team, int $id, array $input): FoodAlchemistDishClass
    {
        $klasse = FoodAlchemistDishClass::visibleToTeam($team)->findOrFail($id);
        if (! $klasse->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Klasse — Pflege nur durch das Besitzer-Team (D1).');
        }
        $bezeichnung = trim($input['bezeichnung'] ?? '');
        $diaet = in_array($input['diaetform'] ?? '', ['fleisch', 'fisch', 'vegi', 'vegan', 'neutral', 'allergie'], true)
            ? $input['diaetform'] : $klasse->diaetform;
        $klasse->update([
            'bezeichnung' => $bezeichnung !== '' ? $bezeichnung : $klasse->bezeichnung,
            'diaetform' => $diaet,
            'is_vegi' => in_array($diaet, ['vegi', 'vegan'], true),
            'is_vegan' => $diaet === 'vegan',
        ]);

        return $klasse;
    }

    /** VK-Klasse löschen: hart wenn kein Rezept darauf zeigt, sonst gesperrt. */
    public function deleteDishClass(Team $team, int $id): void
    {
        $klasse = FoodAlchemistDishClass::visibleToTeam($team)->findOrFail($id);
        if (! $klasse->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Klasse — Pflege nur durch das Besitzer-Team (D1).');
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('foodalchemist_recipes')) {
            $n = (int) \Illuminate\Support\Facades\DB::table('foodalchemist_recipes')
                ->where('speisen_klasse_id', $klasse->id)->whereNull('deleted_at')->count();
            if ($n > 0) {
                throw new RuntimeException("Klasse wird von {$n} Gericht(en) genutzt — erst umhängen, dann löschen.");
            }
        }
        $klasse->delete();
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

    /** Rezept-Hauptgruppe umbenennen (Code bleibt stabil — Rezept-Kategorien hängen am code/id). */
    public function updateMainGroup(Team $team, int $id, array $input): FoodAlchemistRecipeMainGroup
    {
        $hg = FoodAlchemistRecipeMainGroup::visibleToTeam($team)->findOrFail($id);
        if (! $hg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Hauptgruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        $bezeichnung = trim($input['bezeichnung'] ?? '');
        if ($bezeichnung === '') {
            throw new RuntimeException('Bezeichnung darf nicht leer sein.');
        }
        $hg->update([
            'bezeichnung' => $bezeichnung,
            'bereich' => array_key_exists('bereich', $input) ? (($input['bereich'] ?? '') ?: null) : $hg->bereich,
            'sort_order' => (int) ($input['sort_order'] ?? $hg->sort_order),
        ]);

        return $hg;
    }

    /** Rezept-Hauptgruppe löschen: hart wenn keine Kategorien hängen, sonst gesperrt. */
    public function deleteMainGroup(Team $team, int $id): void
    {
        $hg = FoodAlchemistRecipeMainGroup::visibleToTeam($team)->findOrFail($id);
        if (! $hg->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Hauptgruppe — Pflege nur durch das Besitzer-Team (D1).');
        }
        $n = FoodAlchemistRecipeCategory::where('main_group_id', $hg->id)->whereNull('deleted_at')->count();
        if ($n > 0) {
            throw new RuntimeException("Hauptgruppe hat {$n} Kategorie(n) — erst dort entfernen, dann löschen.");
        }
        $hg->delete();
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
