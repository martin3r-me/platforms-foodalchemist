<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\RezeptFolgeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotUebernahme;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamSperre;

/**
 * Spec 82 §12 B · Schritt 4d (Entscheidung Dominique 13.10.): EIGENE Rezepte eines Mandanten, die eine Abo-Fassung als Zutat nutzen, FOLGEN beim
 * Veröffentlichen automatisch der neuen Fassung — AUSSER
 *  - die neue Fassung ändert Allergene/Zusatzstoffe (Endstand zur referenzierten Fassung oder das Flag irgendeiner Zwischenfassung): das Rezept bleibt
 *    stehen und fällt unter das Aktions-Signal „Übernehmen am Rezept“ (Kritisch, Bestätigung nur durch die Rolle Freigeben, {@see rezeptUebernehmen});
 *  - das Rezept steckt im Baum eines EINGEFROREN Angebots (eingefroren = versprochen, Entscheidung Kurator D1): es bleibt, das Signal meldet es.
 * Nie angefasst: eingefrorene Angebots-Snapshots (4a/4b) und Historie. Archivierte Ausgaben haben keinen Snapshot und zeigen den lebenden Stand (wie bei
 * jeder Bearbeitung des eigenen Rezepts).
 *
 * Wer darf umgestellt werden? Die EINE Sichtbarkeits-Autorität: nur wenn die NEUE Fassung für das Team des Rezepts sichtbar ist (Abo-Hülle des Oberteams,
 * erbt_alles, 77d-Freigabe). Gesperrte Teams (Mandant beendet) und das Master-Team nie. Läuft nach dem Commit der Veröffentlichung, je Rezept eigene
 * Transaktion, Fehler werden geloggt, idempotent (umgestellt wird nur, was noch auf eine ÄLTERE Fassung zeigt). Über {@see SYNC_GRENZE} Rezepte:
 * ein Job je Mandant.
 */
class RezeptFolgeService
{
    public const SYNC_GRENZE = 50;

    /** @var array<int,true>|null Rezepte im Baum eingefrorener Angebote (pro Instanz, einmal je Lauf) */
    private ?array $eingefroren = null;

    public function __construct(
        private readonly FassungsVergleich $vergleich,
        private readonly FassungsUmstellung $umstellung,
        private readonly RecipeRecomputeService $recompute,
        private readonly FaRechte $rechte,
        private readonly PlattformProtokoll $protokoll,
    ) {}

    /**
     * Nach dem Veröffentlichen: betroffene Rezepte folgen lassen. Ab mehr als {@see SYNC_GRENZE} Rezepten wird je Mandant ein Job eingereiht.
     *
     * @param  list<int>  $neueFassungIds
     * @return array{umgestellt:int, gehalten:int, signale: list<array{team:int, familie:int}>, eingereiht: list<int>}
     */
    public function folgen(array $neueFassungIds): array
    {
        $this->eingefroren = null;
        $familien = FoodAlchemistRecipe::mitFassungen()->whereIn('id', $neueFassungIds)->where('ist_fassung', true)->pluck('fassung_von_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        $bericht = ['umgestellt' => 0, 'gehalten' => 0, 'signale' => [], 'eingereiht' => []];
        if ($familien === []) {
            return $bericht;
        }
        $kandidaten = $this->kandidaten($familien);
        $nachTeam = $kandidaten->groupBy('team_id');
        $rezepteGesamt = $kandidaten->pluck('recipe_id')->unique()->count();

        foreach ($nachTeam as $teamId => $zeilen) {
            if ($rezepteGesamt > (int) config('foodalchemist.rezept_folge.sync_grenze', self::SYNC_GRENZE)) {
                RezeptFolgeJob::dispatch((int) $teamId, $familien)->afterCommit();
                $bericht['eingereiht'][] = (int) $teamId;

                continue;
            }
            $r = $this->folgenFuerTeam((int) $teamId, $familien, false);
            $bericht['umgestellt'] += $r['umgestellt'];
            $bericht['gehalten'] += $r['gehalten'];
            array_push($bericht['signale'], ...$r['signale']);
        }

        return $bericht;
    }

    /**
     * Alle betroffenen Rezepte EINES Teams bearbeiten. `$signaleNachfuehren`: im Job (kein Aufrufer, der es danach tut) die Aktions-Signale gleich mit.
     *
     * @param  list<int>  $familien
     * @return array{umgestellt:int, gehalten:int, signale: list<array{team:int, familie:int}>}
     */
    public function folgenFuerTeam(int $teamId, array $familien, bool $signaleNachfuehren): array
    {
        $this->eingefroren = null;
        $team = Team::find($teamId);
        $out = ['umgestellt' => 0, 'gehalten' => 0, 'signale' => []];
        if ($team === null) {
            return $out;
        }
        $umgestellte = [];
        $letzteZeile = null;
        foreach ($this->kandidaten($familien)->where('team_id', $teamId)->groupBy('recipe_id') as $recipeId => $zeilen) {
            try {
                $recipe = FoodAlchemistRecipe::find((int) $recipeId);
                if ($recipe === null) {
                    continue;
                }
                $umgestellt = false;
                foreach ($zeilen as $z) {
                    $out['signale'][] = ['team' => $teamId, 'familie' => (int) $z->familie_id];
                    $alt = FoodAlchemistRecipe::mitFassungen()->find((int) $z->alt_id);
                    $e = $this->entscheide($team, $alt, (int) $recipeId);
                    if ($e['aktion'] === 'halten') {
                        $out['gehalten']++;

                        continue;
                    }
                    if ($e['aktion'] !== 'folgen') {
                        continue;
                    }
                    DB::transaction(function () use ($recipe, $alt, $e, &$letzteZeile) {
                        $this->umstellung->einRezept($recipe, (int) $alt->id, (int) $e['neu']->id);
                        $letzteZeile = FoodAlchemistAngebotUebernahme::create([
                            'team_id' => $recipe->team_id, 'angebot_id' => null, 'rezept_id' => $recipe->id, 'art' => 'auto', 'familie_id' => (int) $alt->fassung_von_id,
                            'von_fassung_id' => $alt->id, 'nach_fassung_id' => $e['neu']->id, 'allergen_geaendert' => false, 'allergen_bestaetigt' => false, 'diff' => [],
                        ]);
                    });
                    $umgestellt = true;
                }
                $out['umgestellt'] += $umgestellt ? 1 : 0;
                if ($umgestellt) {
                    $umgestellte[] = (int) $recipe->id;
                }
            } catch (\Throwable $ex) {
                Log::error('Rezept folgt der neuen Abo-Fassung nicht (Rezept '.$recipeId.'): '.$ex->getMessage(), ['exception' => $ex, 'recipe_id' => $recipeId]);
            }
        }
        if ($umgestellte !== []) {
            try {
                $this->recompute->recomputeMany($umgestellte);   // mit Team-Kosten: das Rezept gehört dem Team, die Kostenschicht rechnet für dieses Team
            } catch (\Throwable $ex) {
                Log::error('Neuberechnung nach Fassungs-Folge fehlgeschlagen: '.$ex->getMessage(), ['exception' => $ex]);
            }
            $this->protokoll->schreibe('rezept_fassung_gefolgt', ['team_id' => $teamId, 'rezepte' => count($umgestellte)], null, $letzteZeile);
        }
        $out['signale'] = collect($out['signale'])->unique(fn ($s) => $s['team'].':'.$s['familie'])->values()->all();
        if ($signaleNachfuehren) {
            foreach ($out['signale'] as $s) {
                try {
                    app(FassungSignalService::class)->aktion(Team::findOrFail($s['team']), $s['familie']);
                } catch (\Throwable $ex) {
                    Log::error('Signal nach Fassungs-Folge fehlgeschlagen: '.$ex->getMessage(), ['exception' => $ex]);
                }
            }
        }

        return $out;
    }

    /**
     * Zutatenzeilen EIGENER Rezepte, die auf eine Fassung zeigen, zu der es eine neuere derselben Familie gibt (nach Nummer). Eine indizierte Rückwärts-Abfrage.
     *
     * @param  list<int>  $familien
     * @return Collection<int, object{recipe_id:int, team_id:int, alt_id:int, familie_id:int}>
     */
    public function kandidaten(array $familien): Collection
    {
        $fassungen = FoodAlchemistRecipe::mitFassungen()->where('ist_fassung', true)->whereIn('fassung_von_id', $familien)->get(['id', 'fassung_von_id', 'fassung_nr']);
        $maxNr = $fassungen->groupBy('fassung_von_id')->map(fn ($g) => (int) $g->max('fassung_nr'));
        $aeltere = $fassungen->filter(fn ($f) => (int) $f->fassung_nr < (int) $maxNr[$f->fassung_von_id])->keyBy('id');
        if ($aeltere->isEmpty()) {
            return collect();
        }

        return collect(DB::table('foodalchemist_recipe_ingredients AS z')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'z.recipe_id')
            ->whereIn('z.referenced_recipe_id', $aeltere->keys()->all())->whereNull('z.deleted_at')
            ->where('r.ist_fassung', false)->whereNull('r.deleted_at')->whereNotNull('r.team_id')
            ->select('z.recipe_id', 'r.team_id', 'z.referenced_recipe_id AS alt_id')->distinct()->get())
            ->map(fn ($row) => (object) ['recipe_id' => (int) $row->recipe_id, 'team_id' => (int) $row->team_id, 'alt_id' => (int) $row->alt_id,
                'familie_id' => (int) $aeltere[$row->alt_id]->fassung_von_id]);
    }

    /**
     * Entscheidung für EINE Zutatenzeile eines eigenen Rezepts: folgen | halten | uebersprungen (stateless, dieselbe Regel für Auto-Folgen und Signal).
     *
     * @return array{aktion:string, grund:?string, neu:?FoodAlchemistRecipe, diff:array<string,mixed>}
     */
    public function entscheide(Team $team, FoodAlchemistRecipe $alt, ?int $recipeId = null): array
    {
        $leer = fn (string $grund) => ['aktion' => 'uebersprungen', 'grund' => $grund, 'neu' => null, 'diff' => []];
        if (MasterTeam::istMaster($team)) {
            return $leer('master');
        }
        if (TeamSperre::istGesperrt($team)) {
            return $leer('gesperrt');
        }
        $neu = $this->vergleich->neuesteFuer((int) $alt->fassung_von_id, $team);   // die eine Sichtbarkeits-Autorität
        if ($neu === null) {
            return $leer('nicht_sichtbar');
        }
        if ((int) $neu->fassung_nr <= (int) $alt->fassung_nr) {
            return $leer('aktuell');
        }
        $diff = $this->vergleich->bildDiff($this->vergleich->allergenBild($alt), $neu);
        $zwischen = $this->vergleich->zwischen((int) $alt->fassung_von_id, (int) $alt->fassung_nr, (int) $neu->fassung_nr);
        if ($diff !== [] || collect($zwischen)->contains('allergen_geaendert', true)) {
            return ['aktion' => 'halten', 'grund' => 'allergen', 'neu' => $neu, 'diff' => $diff];
        }
        if ($recipeId !== null && isset($this->eingefrorenIds()[$recipeId])) {
            return ['aktion' => 'halten', 'grund' => 'eingefroren', 'neu' => $neu, 'diff' => $diff];
        }

        return ['aktion' => 'folgen', 'grund' => null, 'neu' => $neu, 'diff' => $diff];
    }

    /**
     * Eigene Rezepte eines Teams, die auf einer älteren Fassung der Familie stehen und NICHT automatisch folgen (Allergen oder eingefroren) — für das Signal.
     *
     * @return list<array{recipe_id:int, name:string, von_nr:int, nach_nr:int, grund:string, allergen_diff:array<string,mixed>}>
     */
    public function gehalten(Team $team, int $familieId): array
    {
        $this->eingefroren = null;
        $out = [];
        foreach ($this->kandidaten([$familieId])->where('team_id', $team->id) as $k) {
            $alt = FoodAlchemistRecipe::mitFassungen()->find($k->alt_id);
            $e = $this->entscheide($team, $alt, $k->recipe_id);
            if ($e['aktion'] !== 'halten') {
                continue;
            }
            $out[] = ['recipe_id' => $k->recipe_id, 'name' => (string) FoodAlchemistRecipe::mitFassungen()->whereKey($k->recipe_id)->value('name'), 'von_nr' => (int) $alt->fassung_nr,
                'nach_nr' => (int) $e['neu']->fassung_nr, 'grund' => $e['grund'], 'allergen_diff' => $e['diff']];
        }

        return $out;
    }

    /**
     * Was „Übernehmen am Rezept“ täte: neue Fassung, Allergen-Diff (Endstand), Zwischenfassungen, ob eine Bestätigung nötig ist, ob das Rezept in einem
     * eingefrorenen Angebot steckt (dessen Snapshot bleibt unverändert).
     *
     * @return array<string,mixed>
     */
    public function uebernahmeVorschau(Team $team, int $recipeId, int $familieId): array
    {
        $this->eingefroren = null;
        [$recipe, $alte, $neu] = $this->uebernahmeZiel($team, $recipeId, $familieId);
        $erste = $alte->sortBy('fassung_nr')->first();
        $diff = $this->vergleich->bildDiff($this->vergleich->allergenBild($erste), $neu);
        $zwischen = $this->vergleich->zwischen($familieId, (int) $erste->fassung_nr, (int) $neu->fassung_nr);
        $allergen = $diff !== [] || collect($zwischen)->contains('allergen_geaendert', true);

        return ['recipe_id' => (int) $recipe->id, 'name' => (string) $recipe->name, 'familie_id' => $familieId, 'von_nr' => (int) $erste->fassung_nr,
            'nach_fassung_id' => (int) $neu->id, 'nach_nr' => (int) $neu->fassung_nr, 'aenderungsnotiz' => $neu->aenderungsnotiz, 'allergen_geaendert' => $allergen,
            'allergen_diff' => $diff, 'zwischen' => $zwischen, 'braucht_bestaetigung' => $allergen, 'in_eingefrorenem_angebot' => isset($this->eingefrorenIds()[$recipeId])];
    }

    /**
     * Übernehmen am Rezept: gleiche Regeln wie im Angebot (4b). Ändert die neue Fassung das Allergen-/Zusatzstoffbild: ausdrückliche Bestätigung und Rolle
     * Freigeben, sonst Kuratieren. Person und Zeitpunkt in der Historie und im Plattform-Protokoll.
     *
     * @return array<string,mixed>
     */
    public function rezeptUebernehmen(Team $team, ?User $user, int $recipeId, int $familieId, bool $bestaetigt = false): array
    {
        $v = $this->uebernahmeVorschau($team, $recipeId, $familieId);
        $this->rechte->pruefe($user, $team, $v['braucht_bestaetigung'] ? FaRolle::Freigeben : FaRolle::Kuratieren,
            $v['braucht_bestaetigung'] ? 'Fassung mit geändertem Allergenbild am Rezept übernehmen' : 'Neuere Fassung am Rezept übernehmen');
        if ($v['braucht_bestaetigung'] && ! $bestaetigt) {
            throw new \RuntimeException('Die neue Fassung ändert das Allergen- oder Zusatzstoffbild. Übernehmen verlangt die ausdrückliche Bestätigung (bestaetigt=true) nach Durchsicht des Unterschieds.');
        }
        [$recipe, $alte, $neu] = $this->uebernahmeZiel($team, $recipeId, $familieId);

        $historie = DB::transaction(function () use ($recipe, $alte, $neu, $v, $user, $familieId) {
            foreach ($alte as $alt) {
                $this->umstellung->einRezept($recipe, (int) $alt->id, (int) $neu->id);
            }
            $this->recompute->recomputeMany([(int) $recipe->id]);

            return FoodAlchemistAngebotUebernahme::create([
                'team_id' => $recipe->team_id, 'angebot_id' => null, 'rezept_id' => $recipe->id, 'art' => 'rezept', 'familie_id' => $familieId,
                'von_fassung_id' => $alte->sortBy('fassung_nr')->first()->id, 'nach_fassung_id' => $neu->id,
                'allergen_geaendert' => $v['allergen_geaendert'], 'allergen_bestaetigt' => $v['braucht_bestaetigung'],
                'bestaetigt_von' => $v['braucht_bestaetigung'] ? $user?->id : null, 'bestaetigt_at' => $v['braucht_bestaetigung'] ? now() : null,
                'diff' => $v['allergen_diff'], 'umgehaengt' => ['rezept' => (int) $recipe->id, 'zeilen' => $alte->count()],
            ]);
        });
        $this->protokoll->schreibe('rezept_fassung_uebernommen', ['recipe_id' => (int) $recipe->id, 'familie_id' => $familieId, 'von' => $v['von_nr'], 'nach' => $v['nach_nr'],
            'allergen_geaendert' => $v['allergen_geaendert'], 'bestaetigt' => $v['braucht_bestaetigung']], $user, $historie);
        try {
            app(FassungSignalService::class)->aktion($team, $familieId);
        } catch (\Throwable $e) {
            Log::error('Signal nach Übernehmen am Rezept fehlgeschlagen: '.$e->getMessage(), ['exception' => $e]);
        }

        return ['uebernahme_id' => (int) $historie->id, 'von_nr' => $v['von_nr'], 'nach_nr' => $v['nach_nr'], 'allergen_geaendert' => $v['allergen_geaendert']];
    }

    /** @return array{0: FoodAlchemistRecipe, 1: Collection<int, FoodAlchemistRecipe>, 2: FoodAlchemistRecipe} Rezept (eigenes des Teams), ältere referenzierte Fassungen, neue Fassung */
    private function uebernahmeZiel(Team $team, int $recipeId, int $familieId): array
    {
        $recipe = FoodAlchemistRecipe::where('team_id', $team->id)->where('ist_fassung', false)->find($recipeId)
            ?? throw new \RuntimeException('Das Rezept gehört nicht zu diesem Team.');
        $neu = $this->vergleich->neuesteFuer($familieId, $team) ?? throw new \RuntimeException('Es gibt keine für dieses Team sichtbare Fassung.');
        $alte = FoodAlchemistRecipe::mitFassungen()->whereIn('id', $recipe->ingredients()->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id'))
            ->where('ist_fassung', true)->where('fassung_von_id', $familieId)->where('fassung_nr', '<', $neu->fassung_nr)->get();
        if ($alte->isEmpty()) {
            throw new \RuntimeException('Das Rezept nutzt keine ältere Fassung dieses Rezepts.');
        }

        return [$recipe, $alte, $neu];
    }

    /** Rezepte im Baum eingefrorener Angebote (alle aktiven Einfrierungen), einmal je Instanz. @return array<int,true> */
    private function eingefrorenIds(): array
    {
        if ($this->eingefroren !== null) {
            return $this->eingefroren;
        }
        $ids = [];
        foreach (FoodAlchemistAngebotEinfrierung::whereNull('verworfen_at')->with('zeilen')->get() as $e) {
            foreach ($e->zeilen as $z) {
                $offen = [(int) $z->recipe_id];
                $tiefe = 0;
                while ($offen !== [] && $tiefe++ < 8) {
                    $neu = [];
                    foreach ($offen as $id) {
                        if (isset($ids[$id])) {
                            continue;
                        }
                        $ids[$id] = true;
                        $neu = array_merge($neu, DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $id)->whereNull('deleted_at')
                            ->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id')->map(fn ($v) => (int) $v)->all());
                    }
                    $offen = array_values(array_unique($neu));
                }
            }
        }

        return $this->eingefroren = $ids;
    }
}
