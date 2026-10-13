<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;

/**
 * Spec 82 §12 B · Signale zu neuen Master-Fassungen (Wunsch Dominique 13.10.). EREIGNISGETRIEBEN statt Detektor-Lauf: ein periodischer Lauf meldete erst
 * Stunden später und ließe ein Signal nach der Übernahme stehen. Angehängt an die Veröffentlichung ({@see FassungService::veroeffentlichen}) und an
 * Übernehmen, Verwerfen und Einfrieren ({@see AngebotEinfrierService}); Fehler hier verhindern nie die eigentliche Aktion.
 *
 *  1. `fassung_aktualisiert` (Info): bei JEDER neuen veröffentlichten Fassung in der Abo-Hülle eines Mandanten, auch wenn sie in keinem Angebot steckt.
 *     Ein Signal je Rezept und Mandant (dedup), mehrere Fassungen werden gebündelt (Notizen und Allergen-Änderungen sammeln sich).
 *  2. `fassung_neuer_verfuegbar` (Warnung): ein eingefrorenes Angebot steht auf einer älteren Fassung. Ein Signal je Rezept und Team, mit Liste der Angebote.
 *     Schließt sich selbst, sobald niemand mehr auf der älteren Fassung steht (übernommen oder Snapshot verworfen); „bewusst verwerfen“ = Signal ignorieren.
 * Allergen- oder Zusatzstoff-Änderung hebt beide auf Kritisch und nennt den Stoff.
 *
 * Sichtbarkeit: Signale liegen im Team, in dem das Betroffene liegt (SignalService ist team-scoped). Ein anderer Mandant, der Master und ein Plattform-Admin
 * ohne Mitgliedschaft sehen sie nie; Unter-Teams sehen die des Oberteams über die Team-Kette (§13 G).
 */
class FassungSignalService
{
    private const QUELLE = 'fassung';

    /** EU-Allergene in der Schreibweise des Hauses. */
    private const ALLERGENE = ['gluten' => 'Gluten', 'crustaceans' => 'Krebstiere', 'eggs' => 'Eier', 'fish' => 'Fisch', 'peanuts' => 'Erdnüsse', 'soy' => 'Soja',
        'milk' => 'Milch', 'tree_nuts' => 'Schalenfrüchte', 'celery' => 'Sellerie', 'mustard' => 'Senf', 'sesame' => 'Sesam', 'sulphites' => 'Sulfite',
        'lupin' => 'Lupinen', 'molluscs' => 'Weichtiere'];

    public function __construct(private readonly SignalService $signale, private readonly FassungsVergleich $vergleich) {}

    /**
     * Nach dem Veröffentlichen: Info-Signale für die Mandanten mit der neuen Fassung in der Hülle, Aktions-Signale für Teams mit eingefrorenen Angeboten.
     *
     * @param  list<int>  $neueFassungIds  die in diesem Lauf NEU angelegten Fassungen (nicht die wiederverwendeten)
     */
    public function nachVeroeffentlichung(array $neueFassungIds): int
    {
        $n = 0;
        $familien = [];
        foreach (FoodAlchemistRecipe::mitFassungen()->whereIn('id', $neueFassungIds)->where('ist_fassung', true)->orderBy('fassung_nr')->get() as $fassung) {
            $familien[(int) $fassung->fassung_von_id] = true;
            $mandanten = DB::table('foodalchemist_abo_objekte')->where('typ', 'recipe')->where('objekt_id', $fassung->id)->pluck('mandant_team_id');
            foreach ($mandanten as $teamId) {
                if (($team = Team::find((int) $teamId)) !== null) {
                    $this->info($team, $fassung);
                    $n++;
                }
            }
        }
        // 4d: eigene Rezepte folgen der neuen Fassung (nach dem Commit, Fehler geloggt); danach die Aktions-Signale für die berührten Team × Familie
        $folge = ['signale' => []];
        try {
            $folge = app(RezeptFolgeService::class)->folgen($neueFassungIds);
        } catch (\Throwable $e) {
            Log::error('Eigene Rezepte folgen der neuen Abo-Fassung nicht: '.$e->getMessage(), ['exception' => $e]);
        }
        $behandelt = [];
        foreach ($folge['signale'] as $s) {
            if (($team = Team::find($s['team'])) !== null) {
                $this->aktion($team, $s['familie']);
                $behandelt[$s['team'].':'.$s['familie']] = true;
                $n++;
            }
        }
        foreach (array_keys($familien) as $familieId) {
            $n += $this->aktionFuerAlleTeams($familieId, $behandelt);
        }

        return $n;
    }

    /** Aktions-Signale einer Familie für alle Teams, die ein eingefrorenes Angebot auf dieser Familie haben. */
    public function aktionFuerAlleTeams(int $familieId, array $schonBehandelt = []): int
    {
        $teams = [];
        foreach (FoodAlchemistAngebotEinfrierung::whereNull('verworfen_at')->with('zeilen')->get() as $e) {
            if ($e->zeilen->contains(fn ($z) => collect($z->fassungen)->contains('fassung_von_id', $familieId))) {
                $teams[(int) $e->team_id] = true;
            }
        }
        $n = 0;
        foreach (array_keys($teams) as $teamId) {
            if (! isset($schonBehandelt[$teamId.':'.$familieId]) && ($team = Team::find($teamId)) !== null) {
                $this->aktion($team, $familieId);
                $n++;
            }
        }

        return $n;
    }

    /** Nach Übernehmen/Verwerfen/Einfrieren: die Aktions-Signale der Familien dieses Angebots neu bewerten (anlegen, aktualisieren oder schließen). */
    public function nachAngebotsAenderung(FoodAlchemistAngebot $angebot, ?FoodAlchemistAngebotEinfrierung $einfrierung = null): void
    {
        $team = Team::find($angebot->team_id);
        if ($team === null) {
            return;
        }
        $einfrierung ??= FoodAlchemistAngebotEinfrierung::where('angebot_id', $angebot->id)->latest('id')->first();
        $familien = $einfrierung === null ? [] : $einfrierung->zeilen()->get()->flatMap(fn ($z) => collect($z->fassungen)->pluck('fassung_von_id'))->unique()->map(fn ($v) => (int) $v)->all();
        foreach ($familien as $familieId) {
            $this->aktion($team, $familieId);
        }
    }

    /** Info-Signal „Abo-Rezept aktualisiert“, gebündelt je Rezept und Mandant. */
    private function info(Team $team, FoodAlchemistRecipe $fassung): void
    {
        $familieId = (int) $fassung->fassung_von_id;
        $vorher = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $familieId)->where('ist_fassung', true)
            ->where('fassung_nr', '<', $fassung->fassung_nr)->orderByDesc('fassung_nr')->first();
        $diff = $vorher === null ? [] : $this->vergleich->bildDiff($this->vergleich->allergenBild($vorher), $fassung);
        $allergen = $diff !== [] || (bool) $fassung->fassung_allergen_geaendert;
        $key = 'fassung-aktualisiert:'.$familieId;

        $offen = FoodAlchemistSignal::where('team_id', $team->id)->where('type', SignalTyp::FassungAktualisiert->value)->where('dedup_key', $key)
            ->where('status', SignalStatus::Offen->value)->first();
        $fassungen = collect((array) ($offen?->payload['fassungen'] ?? []))->keyBy('nr');
        $fassungen[(int) $fassung->fassung_nr] = ['nr' => (int) $fassung->fassung_nr, 'fassung_id' => (int) $fassung->id, 'notiz' => $fassung->aenderungsnotiz,
            'allergen_geaendert' => $allergen, 'allergene' => $this->allergenTexte($diff)];
        $fassungen = $fassungen->sortKeys()->values();
        $allergenGesamt = $fassungen->contains('allergen_geaendert', true);

        $name = (string) $fassung->name;
        $nr = (int) $fassungen->max('nr');
        $titel = ($allergenGesamt ? 'Allergen-Änderung im Abo-Rezept: ' : 'Abo-Rezept aktualisiert: ').$name.' (Fassung #'.$nr.')';
        $zeilen = $fassungen->map(fn ($f) => '#'.$f['nr'].($f['notiz'] ? ': '.$f['notiz'] : '').($f['allergene'] !== [] ? ' — '.implode('; ', $f['allergene']) : ''))->all();
        $this->signale->erzeuge($team, SignalTyp::FassungAktualisiert, $allergenGesamt ? SignalSeverity::Kritisch : SignalSeverity::Info, $titel, [
            'dedup_key' => $key, 'source' => self::QUELLE, 'ref_type' => 'recipe', 'ref_id' => (int) $fassung->id,
            'description' => 'Die Bibliothek nutzt die neue Fassung automatisch. Änderungen: '.implode(' · ', $zeilen),
            'payload' => ['familie_id' => $familieId, 'name' => $name, 'nr' => $nr, 'fassungen' => $fassungen->all(), 'allergen_geaendert' => $allergenGesamt],
        ]);
    }

    /** Aktions-Signal „Neuere Fassung verfügbar“ für ein Team und eine Familie: anlegen, aktualisieren oder schließen. */
    public function aktion(Team $team, int $familieId): void
    {
        $key = 'fassung-neu:'.$familieId;
        $neu = $this->vergleich->neuesteFuer($familieId, $team);
        $betroffen = [];
        $allergenDiff = [];
        $vonNr = null;
        if ($neu !== null) {
            $einfrierungen = FoodAlchemistAngebotEinfrierung::where('team_id', $team->id)->whereNull('verworfen_at')->with('zeilen')->get();
            foreach ($einfrierungen as $e) {
                $angebot = FoodAlchemistAngebot::find($e->angebot_id);
                foreach ($e->zeilen as $z) {
                    $eintrag = collect($z->fassungen)->firstWhere('fassung_von_id', $familieId);
                    if ($angebot === null || $eintrag === null || (int) $eintrag['fassung_nr'] >= (int) $neu->fassung_nr) {
                        continue;
                    }
                    $betroffen[] = ['angebot_id' => (int) $angebot->id, 'name' => $angebot->name, 'status' => (string) ($angebot->status->value ?? $angebot->status),
                        'zeile_id' => (int) $z->id, 'eingefroren_nr' => (int) $eintrag['fassung_nr']];
                    $vonNr = $vonNr === null ? (int) $eintrag['fassung_nr'] : min($vonNr, (int) $eintrag['fassung_nr']);
                    $allergenDiff += $this->vergleich->bildDiff((array) ($eintrag['allergene'] ?? []), $neu);
                }
            }
        }
        $rezepte = app(RezeptFolgeService::class)->gehalten($team, $familieId);
        if ($betroffen === [] && $rezepte === []) {
            $this->signale->schliesseGemessen($team, SignalTyp::FassungNeuerVerfuegbar, $key, self::QUELLE, 'Übernommen oder eingefrorenen Stand verworfen — niemand steht mehr auf der älteren Fassung');

            return;
        }
        foreach ($rezepte as $r) {
            $vonNr = $vonNr === null ? $r['von_nr'] : min($vonNr, $r['von_nr']);
            $allergenDiff += $r['allergen_diff'];
        }
        $allergen = $allergenDiff !== [] || collect($rezepte)->contains('grund', 'allergen');
        $texte = $this->allergenTexte($allergenDiff);
        $name = (string) ($neu?->name ?? '');
        $titel = ($allergen ? 'Allergen-Änderung: ' : 'Neuere Fassung verfügbar: ').$name.' (#'.$vonNr.' → #'.(int) ($neu?->fassung_nr ?? 0).')';
        $teile = [];
        if ($betroffen !== []) {
            $teile[] = 'Eingefrorene Angebote: '.collect($betroffen)->map(fn ($b) => $b['name'].' ('.$b['status'].')')->implode(', ').'.';
        }
        if ($rezepte !== []) {
            $teile[] = 'Eigene Rezepte auf der älteren Fassung (Übernehmen am Rezept): '.collect($rezepte)->map(fn ($r) => $r['name'].' ('.($r['grund'] === 'allergen' ? 'Allergen-Änderung' : 'in eingefrorenem Angebot').')')->implode(', ').'.';
        }
        $this->signale->erzeuge($team, SignalTyp::FassungNeuerVerfuegbar, $allergen ? SignalSeverity::Kritisch : SignalSeverity::Warnung, $titel, [
            'dedup_key' => $key, 'source' => self::QUELLE, 'ref_type' => $betroffen !== [] ? 'angebot' : 'recipe', 'ref_id' => $betroffen !== [] ? $betroffen[0]['angebot_id'] : $rezepte[0]['recipe_id'],
            'description' => implode(' ', $teile).($allergen ? ' Änderung am Allergenbild: '.implode('; ', $texte).'. Die Übernahme verlangt die Rolle Freigeben.' : ''),
            'payload' => ['familie_id' => $familieId, 'name' => $name, 'von_nr' => $vonNr, 'nach_nr' => (int) ($neu?->fassung_nr ?? 0), 'nach_fassung_id' => $neu?->id,
                'aenderungsnotiz' => $neu?->aenderungsnotiz, 'allergen_geaendert' => $allergen, 'allergene' => $texte, 'angebote' => $betroffen, 'rezepte' => $rezepte],
        ]);
    }

    /**
     * @param  array<string, array{von:mixed, nach:mixed}>  $diff
     * @return list<string>
     */
    private function allergenTexte(array $diff): array
    {
        $out = [];
        foreach ($diff as $feld => $d) {
            $name = str_starts_with($feld, 'allergen_') ? (self::ALLERGENE[substr($feld, 9)] ?? substr($feld, 9)) : 'Zusatzstoff '.str_replace('_', ' ', substr($feld, 9));
            $out[] = $name.': '.($d['von'] ?? 'unbekannt').' → '.($d['nach'] ?? 'unbekannt');
        }

        return $out;
    }
}
