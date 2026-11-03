<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabLeistungskategorie;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Spec 94b · Stufe 7a · Leistungskategorien als Vokabular (eine Stelle für Prüfung, Oberfläche und MCP).
 *
 * Sichtbar ist, was das Team über die Team-Kette sieht (global + eigene + Eltern). Wählbar ist davon, was aktiv ist und
 * weder das Team noch ein Team darüber für sich ausgeschaltet hat (`foodalchemist_leistungskategorie_team_aus`): das
 * Ausschalten erbt nach unten, Oberteam und Geschwister merken nichts. Eigene Kategorien werden am Original deaktiviert.
 */
final class LeistungsKategorieService
{
    /** Wählbare Kategorien in Reihenfolge. @return Collection<int, FoodAlchemistVocabLeistungskategorie> */
    public function waehlbar(Team $team): Collection
    {
        $aus = $this->ausgeschaltet($team);

        return $this->sichtbar($team)->reject(fn ($k) => $k->is_inactive || in_array($k->id, $aus, true))->values();
    }

    /** Slug → Name aller sichtbaren (auch ausgeschalteter) Kategorien, für Anzeige und Gruppen-Namen. @return array<string, string> */
    public function namen(Team $team): array
    {
        return $this->sichtbar($team)->mapWithKeys(fn ($k) => [$k->slug => $k->name])->all();
    }

    /** Slug → Name der wählbaren Kategorien (Select, Filter, MCP). @return array<string, string> */
    public function auswahl(Team $team): array
    {
        return $this->waehlbar($team)->mapWithKeys(fn ($k) => [$k->slug => $k->name])->all();
    }

    /** Für die Einstellungsseite: jede sichtbare Kategorie mit eigen / für dieses Team ausgeschaltet / ererbt ausgeschaltet. */
    public function liste(Team $team): Collection
    {
        $eigenAus = DB::table('foodalchemist_leistungskategorie_team_aus')->where('team_id', $team->id)->pluck('kategorie_id')->map(fn ($v) => (int) $v)->all();
        $alleAus = $this->ausgeschaltet($team);

        return $this->sichtbar($team)->map(fn ($k) => (object) [
            'kategorie' => $k,
            'eigen' => $k->isOwnedBy($team),
            'aus_hier' => in_array($k->id, $eigenAus, true),
            'aus_oben' => in_array($k->id, $alleAus, true) && ! in_array($k->id, $eigenAus, true),
        ]);
    }

    public function anlegen(Team $team, string $name): FoodAlchemistVocabLeistungskategorie
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Bitte einen Namen angeben.');
        }
        $slug = Str::limit(Str::slug($name, '_'), 32, '');
        if ($slug === '' || isset($this->namen($team)[$slug])) {
            throw new \InvalidArgumentException('Diese Kategorie gibt es schon.');
        }
        $max = (int) $this->sichtbar($team)->max('sort_order');

        return FoodAlchemistVocabLeistungskategorie::create(['team_id' => $team->id, 'slug' => $slug, 'name' => mb_substr($name, 0, 80), 'sort_order' => $max + 10]);
    }

    /** Name und Reihenfolge — nur am eigenen Datensatz (geerbte sind nur lesbar). */
    public function aendern(Team $team, int $id, array $in): FoodAlchemistVocabLeistungskategorie
    {
        $k = $this->eigene($team, $id);
        $daten = [];
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('Bitte einen Namen angeben.');
            }
            $daten['name'] = mb_substr($name, 0, 80);
        }
        if (array_key_exists('sort_order', $in)) {
            $daten['sort_order'] = max(0, (int) $in['sort_order']);
        }
        $k->update($daten);

        return $k->fresh();
    }

    /** Eigene Kategorie am Original (de)aktivieren, geerbte nur für dieses Team (und darunter) aus- bzw. einschalten. */
    public function schalten(Team $team, int $id, bool $aktiv): void
    {
        $k = FoodAlchemistVocabLeistungskategorie::visibleToTeam($team)->findOrFail($id);
        if ($k->isOwnedBy($team)) {
            $k->update(['is_inactive' => ! $aktiv]);

            return;
        }
        if ($aktiv) {
            DB::table('foodalchemist_leistungskategorie_team_aus')->where('team_id', $team->id)->where('kategorie_id', $k->id)->delete();
        } else {
            DB::table('foodalchemist_leistungskategorie_team_aus')->insertOrIgnore(['team_id' => $team->id, 'kategorie_id' => $k->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return Collection<int, FoodAlchemistVocabLeistungskategorie> */
    private function sichtbar(Team $team): Collection
    {
        return FoodAlchemistVocabLeistungskategorie::visibleToTeam($team)->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Ids, die das Team oder ein Team darüber ausgeschaltet hat. @return list<int> */
    private function ausgeschaltet(Team $team): array
    {
        return DB::table('foodalchemist_leistungskategorie_team_aus')->whereIn('team_id', app(FaRechte::class)->teamKette($team))
            ->pluck('kategorie_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function eigene(Team $team, int $id): FoodAlchemistVocabLeistungskategorie
    {
        $k = FoodAlchemistVocabLeistungskategorie::visibleToTeam($team)->findOrFail($id);
        GeerbtNurLesenException::wennNichtEigen($k, $team, 'Kategorie');

        return $k;
    }
}
