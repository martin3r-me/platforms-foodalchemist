<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\InhaltsFreigabeHuelleJob;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\InhaltsFreigabe;

/**
 * Spec 77d · Inhalte für Standorte. Das Oberteam entscheidet je Unter-Team:
 *  - Haken „übernimmt alles vom Oberteam" (Standard, heutiges Verhalten) oder
 *  - nur Freigegebenes: Sammlungen (Basisrezepte, Gerichte, Konzepte, Formate) und fertige Ausgaben
 *    (Foodbook, Speiseplan, Speisekarte). Die Abhängigkeiten (Format → Konzepte → Pakete/Gerichte →
 *    Basisrezepte, alle Ebenen) werden als Hülle gespeichert und bei Änderung neu gerechnet.
 * Freigegebenes ist im Standort lesend; anpassen = eigene Kopie (Original gemerkt, Hinweis bei Änderung).
 * Grundprodukte und Lieferantenartikel bleiben Stammdaten (immer geerbt), siehe InhaltsFreigabe.
 */
class InhaltsFreigabeService
{
    public const AUSGABE_TYPEN = ['sammlung' => 'Sammlung', 'foodbook' => 'Foodbook', 'speiseplan' => 'Speiseplan', 'speisekarte' => 'Speisekarte'];

    public const SAMMLUNG_TYPEN = ['recipe' => 'Rezept', 'concept' => 'Konzept', 'paket' => 'Paket', 'format' => 'Format'];

    private const AUSGABE_TABELLEN = ['foodbook' => 'foodalchemist_foodbooks', 'speiseplan' => 'foodalchemist_menu_plans', 'speisekarte' => 'foodalchemist_menu_cards'];

    private const CACHE_VORHANDEN = 'foodalchemist.inhaltsfreigaben.vorhanden';

    /** @var array<string, list<string>> */
    private array $spalten = [];

    public function __construct(private FaRechte $rechte, private StandortService $standorte) {}

    // ── Schnellstart-Haken ──

    public function erbtAlles(Team $unterTeam): bool
    {
        $wert = DB::table('foodalchemist_team_inhalte')->where('team_id', $unterTeam->id)->value('erbt_alles');

        return $wert === null || (bool) $wert;
    }

    /** Oberteam (FA-Admin) schaltet für ein Unter-Team „übernimmt alles" an/aus. */
    public function setzeErbtAlles(Team $oberteam, int $unterTeamId, bool $erbtAlles, ?User $actor): void
    {
        $this->rechte->pruefe($actor, $oberteam, FaRolle::Admin, 'Inhalte für Standorte festlegen');
        $this->pruefeUnterTeam($oberteam, $unterTeamId);
        DB::table('foodalchemist_team_inhalte')->updateOrInsert(['team_id' => $unterTeamId],
            ['erbt_alles' => $erbtAlles, 'set_by' => $actor?->id, 'updated_at' => now(), 'created_at' => now()]);
        InhaltsFreigabe::flushTeamAncestryCache();
        $this->neuBerechnen($unterTeamId);
    }

    // ── Sammlungen ──

    /** @return list<array{id:int, name:string, beschreibung:?string, objekte:list<array{typ:string, id:int, name:string}>}> */
    public function sammlungen(Team $team): array
    {
        $rows = DB::table('foodalchemist_sammlungen')->where('team_id', $team->id)->whereNull('deleted_at')->orderBy('name')->get();
        $objekte = DB::table('foodalchemist_sammlung_objekte')->whereIn('sammlung_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('sammlung_id');
        $namen = $this->namen($objekte->flatten(1)->all());

        return $rows->map(fn ($s) => [
            'id' => (int) $s->id, 'name' => (string) $s->name, 'beschreibung' => $s->beschreibung,
            'objekte' => collect($objekte->get($s->id, []))->map(fn ($o) => ['typ' => (string) $o->typ, 'id' => (int) $o->objekt_id,
                'name' => $namen[$o->typ][(int) $o->objekt_id] ?? '—'])->values()->all(),
        ])->all();
    }

    public function sammlungAnlegen(Team $team, string $name, ?string $beschreibung, ?User $actor): int
    {
        $this->rechte->pruefe($actor, $team, FaRolle::Kuratieren, 'Sammlung anlegen');
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Die Sammlung braucht einen Namen.');
        }

        return (int) DB::table('foodalchemist_sammlungen')->insertGetId(['uuid' => (string) Str::orderedUuid(), 'team_id' => $team->id,
            'name' => mb_substr($name, 0, 160), 'beschreibung' => $beschreibung, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function sammlungLoeschen(Team $team, int $sammlungId, ?User $actor): void
    {
        $this->rechte->pruefe($actor, $team, FaRolle::Kuratieren, 'Sammlung löschen');
        $this->eigeneSammlung($team, $sammlungId);
        DB::table('foodalchemist_sammlungen')->where('id', $sammlungId)->update(['deleted_at' => now()]);
        DB::table('foodalchemist_ausgabe_freigaben')->where('ausgabe_typ', 'sammlung')->where('ausgabe_id', $sammlungId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        $this->nachAenderung();
    }

    /** Objekt (Rezept, Konzept, Format) in die Sammlung — mehrere Objekte auf einmal möglich. @param list<int> $ids */
    public function sammlungHinzu(Team $team, int $sammlungId, string $typ, array $ids, ?User $actor): int
    {
        $this->rechte->pruefe($actor, $team, FaRolle::Kuratieren, 'Sammlung befüllen');
        $this->eigeneSammlung($team, $sammlungId);
        $model = $this->modelFuer($typ);
        $sichtbar = $model::visibleToTeam($team)->whereIn($model->getTable().'.id', $ids)->pluck($model->getTable().'.id')->map(fn ($v) => (int) $v)->all();
        if ($sichtbar === []) {
            throw new \RuntimeException('Nichts gefunden, was dieses Team sehen darf.');
        }
        $n = 0;
        foreach ($sichtbar as $id) {
            $n += DB::table('foodalchemist_sammlung_objekte')->insertOrIgnore(['sammlung_id' => $sammlungId, 'typ' => $typ, 'objekt_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->nachAenderung();

        return $n;
    }

    public function sammlungEntfernen(Team $team, int $sammlungId, string $typ, int $objektId, ?User $actor): void
    {
        $this->rechte->pruefe($actor, $team, FaRolle::Kuratieren, 'Sammlung bearbeiten');
        $this->eigeneSammlung($team, $sammlungId);
        DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $sammlungId)->where('typ', $typ)->where('objekt_id', $objektId)->delete();
        $this->nachAenderung();
    }

    // ── Freigaben ──

    /** @return list<array{id:int, ausgabe_typ:string, ausgabe_id:int, ausgabe:string, empfaenger_team_id:int, empfaenger:string}> */
    public function freigaben(Team $oberteam): array
    {
        $rows = DB::table('foodalchemist_ausgabe_freigaben')->where('team_id', $oberteam->id)->whereNull('deleted_at')->orderBy('empfaenger_team_id')->orderBy('id')->get();
        $teams = $this->standorte->teamNamen($rows->pluck('empfaenger_team_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $namen = $this->ausgabeNamen($rows);

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'ausgabe_typ' => (string) $r->ausgabe_typ, 'ausgabe_id' => (int) $r->ausgabe_id,
            'ausgabe' => $namen[$r->ausgabe_typ][(int) $r->ausgabe_id] ?? '—',
            'empfaenger_team_id' => (int) $r->empfaenger_team_id, 'empfaenger' => $teams[(int) $r->empfaenger_team_id] ?? '—',
        ])->all();
    }

    /** Eigene Ausgabe (Kunden-IP-Regel: nur eigene, nie geerbte) einem eigenen Unter-Team freigeben. FA-Admin. */
    public function freigeben(Team $oberteam, string $ausgabeTyp, int $ausgabeId, int $empfaengerTeamId, ?User $actor): void
    {
        $this->rechte->pruefe($actor, $oberteam, FaRolle::Admin, 'Inhalte für Standorte freigeben');
        $this->pruefeUnterTeam($oberteam, $empfaengerTeamId);
        if (! isset(self::AUSGABE_TYPEN[$ausgabeTyp])) {
            throw new \RuntimeException('Freigeben lassen sich Sammlungen, Foodbooks, Speisepläne und Speisekarten.');
        }
        $tabelle = $ausgabeTyp === 'sammlung' ? 'foodalchemist_sammlungen' : self::AUSGABE_TABELLEN[$ausgabeTyp];
        if (! DB::table($tabelle)->where('id', $ausgabeId)->where('team_id', $oberteam->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Freigeben lassen sich nur eigene Ausgaben dieses Teams.');
        }
        $vorhanden = DB::table('foodalchemist_ausgabe_freigaben')->where('ausgabe_typ', $ausgabeTyp)->where('ausgabe_id', $ausgabeId)->where('empfaenger_team_id', $empfaengerTeamId)->first();
        if ($vorhanden !== null) {
            DB::table('foodalchemist_ausgabe_freigaben')->where('id', $vorhanden->id)->update(['deleted_at' => null, 'freigegeben_von' => $actor?->id, 'updated_at' => now()]);
        } else {
            DB::table('foodalchemist_ausgabe_freigaben')->insert(['uuid' => (string) Str::orderedUuid(), 'team_id' => $oberteam->id, 'ausgabe_typ' => $ausgabeTyp,
                'ausgabe_id' => $ausgabeId, 'empfaenger_team_id' => $empfaengerTeamId, 'freigegeben_von' => $actor?->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::forget(self::CACHE_VORHANDEN);
        $this->neuBerechnen($empfaengerTeamId);
    }

    public function freigabeEntziehen(Team $oberteam, int $freigabeId, ?User $actor): void
    {
        $this->rechte->pruefe($actor, $oberteam, FaRolle::Admin, 'Freigabe entziehen');
        $r = DB::table('foodalchemist_ausgabe_freigaben')->where('id', $freigabeId)->where('team_id', $oberteam->id)->whereNull('deleted_at')->first();
        if ($r === null) {
            throw new \RuntimeException('Freigabe nicht gefunden.');
        }
        DB::table('foodalchemist_ausgabe_freigaben')->where('id', $freigabeId)->update(['deleted_at' => now()]);
        Cache::forget(self::CACHE_VORHANDEN);
        $this->neuBerechnen((int) $r->empfaenger_team_id);
    }

    // ── Hülle ──

    /** Model-Hook (Slot, Block, Zutat, Position geändert): nur wenn es überhaupt Freigaben gibt, Hüllen neu rechnen. */
    public function vormerken(): void
    {
        if (Cache::remember(self::CACHE_VORHANDEN, 60, fn () => Schema::hasTable('foodalchemist_ausgabe_freigaben')
            && DB::table('foodalchemist_ausgabe_freigaben')->whereNull('deleted_at')->exists())) {
            InhaltsFreigabeHuelleJob::dispatch();
        }
    }

    public function neuBerechnenAlle(): void
    {
        foreach (DB::table('foodalchemist_ausgabe_freigaben')->whereNull('deleted_at')->distinct()->pluck('empfaenger_team_id') as $id) {
            $this->neuBerechnen((int) $id);
        }
        // Empfänger ohne Freigaben mehr: Hülle leeren
        DB::table('foodalchemist_freigabe_objekte')->whereNotIn('team_id', DB::table('foodalchemist_ausgabe_freigaben')->whereNull('deleted_at')->select('empfaenger_team_id'))->delete();
    }

    /** Hülle eines Empfängers aus allen seinen Freigaben rechnen und speichern. @return array<string, list<int>> */
    public function neuBerechnen(int $empfaengerTeamId): array
    {
        $huelle = $this->huelle($empfaengerTeamId);
        DB::transaction(function () use ($empfaengerTeamId, $huelle) {
            DB::table('foodalchemist_freigabe_objekte')->where('team_id', $empfaengerTeamId)->delete();
            $zeilen = [];
            foreach ($huelle as $typ => $ids) {
                foreach ($ids as $id) {
                    $zeilen[] = ['team_id' => $empfaengerTeamId, 'typ' => $typ, 'objekt_id' => $id];
                }
            }
            foreach (array_chunk($zeilen, 500) as $teil) {
                DB::table('foodalchemist_freigabe_objekte')->insert($teil);
            }
        });

        return $huelle;
    }

    /** @return array<string, list<int>> Typ → Objekt-IDs (Ausgaben + alle Abhängigkeiten) */
    public function huelle(int $empfaengerTeamId): array
    {
        $offen = [];
        foreach (DB::table('foodalchemist_ausgabe_freigaben')->where('empfaenger_team_id', $empfaengerTeamId)->whereNull('deleted_at')->get() as $f) {
            if ($f->ausgabe_typ === 'sammlung') {
                foreach (DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $f->ausgabe_id)
                    ->whereIn('sammlung_id', DB::table('foodalchemist_sammlungen')->whereNull('deleted_at')->select('id'))->get() as $o) {
                    $offen[$o->typ][] = (int) $o->objekt_id;
                }
            } else {
                $offen[$f->ausgabe_typ][] = (int) $f->ausgabe_id;
            }
        }

        $fertig = [];
        while ($offen !== []) {
            $neu = [];
            foreach ($offen as $typ => $ids) {
                $ids = array_values(array_diff(array_unique($ids), $fertig[$typ] ?? []));
                if ($ids === []) {
                    continue;
                }
                $fertig[$typ] = array_merge($fertig[$typ] ?? [], $ids);
                foreach ($this->abhaengigkeiten($typ, $ids) as $t => $kinder) {
                    $neu[$t] = array_merge($neu[$t] ?? [], $kinder);
                }
            }
            $offen = array_filter($neu);
        }
        ksort($fertig);

        return array_map(function ($ids) {
            sort($ids);

            return $ids;
        }, $fertig);
    }

    // ── Eigene Kopie ──

    /** Freigegebenes (oder geerbtes) Rezept/Konzept/Format als eigene Kopie ins Team holen. Ab Kuratieren. */
    public function kopieAnlegen(Team $team, string $typ, int $id, ?User $actor): \Illuminate\Database\Eloquent\Model
    {
        $this->rechte->pruefe($actor, $team, FaRolle::Kuratieren, 'eigene Kopie anlegen');
        if (! in_array($typ, ['recipe', 'concept', 'format'], true)) {
            throw new \RuntimeException('Eine eigene Kopie gibt es für Rezepte, Konzepte und Formate.');
        }
        $original = $this->modelFuer($typ)::visibleToTeam($team)->findOrFail($id);
        if ($original->isOwnedBy($team)) {
            throw new \RuntimeException('Das gehört schon diesem Team — eine eigene Kopie ist nicht nötig.');
        }
        $kopie = match ($typ) {
            'recipe' => app(RecipeService::class)->duplicate($team, $id, (string) $original->name),
            'concept' => app(ConceptService::class)->duplicate($team, $id),
            'format' => $this->formatKopie($team, $original),
        };
        if ($typ !== 'recipe') {
            $kopie->forceFill(['name' => (string) $original->name])->save();
        }
        $kopie->forceFill(['kopie_von_id' => $original->id, 'kopie_stand_at' => $original->updated_at ?? now()])->save();

        return $kopie->refresh();
    }

    /** Hinweis an der Kopie: hat sich das Original seit dem Kopieren geändert? null = keine Kopie / Original weg. */
    public function originalGeaendert(\Illuminate\Database\Eloquent\Model $kopie): ?bool
    {
        if (empty($kopie->kopie_von_id)) {
            return null;
        }
        $original = $kopie::query()->withoutGlobalScopes()->find($kopie->kopie_von_id);
        if ($original === null) {
            return null;
        }

        return $kopie->kopie_stand_at === null || $original->updated_at?->gt($kopie->kopie_stand_at);
    }

    // ── intern ──

    /** Format samt Gerüst (Slots) kopieren; die Slots zeigen weiter auf die freigegebenen Konzepte. */
    private function formatKopie(Team $team, FoodAlchemistFormat $original): FoodAlchemistFormat
    {
        return DB::transaction(function () use ($team, $original) {
            $neu = $original->replicate(['uuid', 'legacy_id', 'kopie_von_id', 'kopie_stand_at']);
            $neu->forceFill(['team_id' => $team->id, 'status' => 'draft'])->save();
            foreach (\Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot::where('format_id', $original->id)->get() as $slot) {
                $k = $slot->replicate(['uuid']);
                $k->forceFill(['format_id' => $neu->id, 'team_id' => $team->id])->save();
            }

            return $neu;
        });
    }

    /** @param list<int> $ids @return array<string, list<int>> */
    private function abhaengigkeiten(string $typ, array $ids): array
    {
        $out = [];
        $hol = function (string $tabelle, string $fk, array $ziele, ?array $fkIds = null) use ($ids, &$out) {
            $fkIds ??= $ids;
            $da = $this->spaltenVon($tabelle);
            if ($da === [] || ! in_array($fk, $da, true)) {
                return;
            }
            foreach ($ziele as $spalte => $zielTyp) {
                if (! in_array($spalte, $da, true)) {
                    continue;
                }
                $q = DB::table($tabelle)->whereIn($fk, $fkIds)->whereNotNull($spalte);
                if (in_array('deleted_at', $da, true)) {
                    $q->whereNull('deleted_at');
                }
                foreach ($q->distinct()->pluck($spalte) as $v) {
                    $out[$zielTyp][] = (int) $v;
                }
            }
        };
        match ($typ) {
            'format' => $hol('foodalchemist_format_slots', 'format_id', ['concept_id' => 'concept']),
            'concept' => $hol('foodalchemist_concept_slots', 'concept_id', ['sales_recipe_id' => 'recipe', 'package_id' => 'paket', 'embedded_concept_id' => 'concept']),
            'paket' => $hol('foodalchemist_package_dishes', 'package_id', ['sales_recipe_id' => 'recipe']),
            'recipe' => $hol('foodalchemist_recipe_ingredients', 'recipe_id', ['referenced_recipe_id' => 'recipe']),
            'foodbook' => [
                $hol('foodalchemist_foodbook_chapters', 'foodbook_id', ['format_id' => 'format', 'concept_id' => 'concept', 'sales_recipe_id' => 'recipe']),
                // Blöcke hängen am Kapitel, nicht am Foodbook
                $hol('foodalchemist_foodbook_blocks', 'chapter_id', ['concept_id' => 'concept', 'sales_recipe_id' => 'recipe'],
                    $this->kinderIds('foodalchemist_foodbook_chapters', 'foodbook_id', $ids)),
            ],
            'speiseplan' => $hol('foodalchemist_menu_plan_entries', 'menu_plan_id', ['concept_id' => 'concept', 'package_id' => 'paket', 'sales_recipe_id' => 'recipe']),
            'speisekarte' => [
                // Positionen hängen an der Sektion
                $hol('foodalchemist_menu_card_items', 'section_id', ['concept_id' => 'concept', 'sales_recipe_id' => 'recipe'],
                    $this->kinderIds('foodalchemist_menu_card_sections', 'menu_card_id', $ids)),
                $hol('foodalchemist_menu_card_sections', 'menu_card_id', ['format_id' => 'format', 'concept_id' => 'concept', 'sales_recipe_id' => 'recipe']),
            ],
            default => null,
        };

        return $out;
    }

    /** @param list<int> $ids @return list<int> IDs der Kind-Zeilen (mind. [0], damit whereIn nie leer ist) */
    private function kinderIds(string $tabelle, string $fk, array $ids): array
    {
        if (! in_array($fk, $this->spaltenVon($tabelle), true)) {
            return [0];
        }
        $q = DB::table($tabelle)->whereIn($fk, $ids);
        if (in_array('deleted_at', $this->spaltenVon($tabelle), true)) {
            $q->whereNull('deleted_at');
        }

        return $q->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [0];
    }

    /** @return list<string> */
    private function spaltenVon(string $tabelle): array
    {
        return $this->spalten[$tabelle] ??= Schema::hasTable($tabelle) ? Schema::getColumnListing($tabelle) : [];
    }

    private function nachAenderung(): void
    {
        Cache::forget(self::CACHE_VORHANDEN);
        $this->neuBerechnenAlle();
    }

    private function pruefeUnterTeam(Team $oberteam, int $unterTeamId): void
    {
        if (! in_array($unterTeamId, $this->standorte->unterTeamIds($oberteam), true)) {
            throw new \RuntimeException('Dieses Team ist kein Unter-Team (Standort) dieses Teams.');
        }
    }

    private function eigeneSammlung(Team $team, int $sammlungId): void
    {
        if (! DB::table('foodalchemist_sammlungen')->where('id', $sammlungId)->where('team_id', $team->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Sammlung nicht gefunden.');
        }
    }

    private function modelFuer(string $typ): \Illuminate\Database\Eloquent\Model
    {
        return match ($typ) {
            'recipe' => new FoodAlchemistRecipe(),
            'concept' => new FoodAlchemistConcept(),
            'format' => new FoodAlchemistFormat(),
            'paket' => new \Platform\FoodAlchemist\Models\FoodAlchemistPaket(),
            default => throw new \RuntimeException('In eine Sammlung kommen Rezepte, Konzepte, Pakete und Formate.'),
        };
    }

    /** @param iterable<object> $objekte @return array<string, array<int,string>> */
    private function namen(iterable $objekte): array
    {
        $je = [];
        foreach ($objekte as $o) {
            $je[$o->typ][] = (int) $o->objekt_id;
        }
        $out = [];
        foreach ($je as $typ => $ids) {
            $tabelle = array_search($typ, InhaltsFreigabe::TABELLEN, true);
            if ($tabelle !== false) {
                $out[$typ] = DB::table($tabelle)->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
            }
        }

        return $out;
    }

    /** @return array<string, array<int,string>> */
    private function ausgabeNamen(\Illuminate\Support\Collection $rows): array
    {
        $out = [];
        foreach ($rows->groupBy('ausgabe_typ') as $typ => $gruppe) {
            $tabelle = $typ === 'sammlung' ? 'foodalchemist_sammlungen' : (self::AUSGABE_TABELLEN[$typ] ?? null);
            if ($tabelle !== null) {
                $da = $this->spaltenVon($tabelle);
                $spalte = in_array('name', $da, true) ? 'name' : (in_array('label', $da, true) ? 'label' : 'title');
                $out[$typ] = DB::table($tabelle)->whereIn('id', $gruppe->pluck('ausgabe_id'))->pluck($spalte, 'id')->map(fn ($n) => (string) $n)->all();
            }
        }

        return $out;
    }
}
