<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\BausteinFolgeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotUebernahme;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\VerweisRegister;
use Platform\FoodAlchemist\Support\TeamSperre;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 5 (Folgen, wie {@see RezeptFolgeService} in 4d): Eigene Konzepte, Formate, Foodbooks, Speisekarten,
 * Speisepläne und Angebote des Mandanten, die eine Abo-FASSUNG von Konzept oder Paket referenzieren, FOLGEN beim Veröffentlichen automatisch der
 * neuen Fassung (der Verweis wird umgehängt) — AUSSER
 *  - die neue Fassung ändert das Allergen-/Zusatzstoffbild über die ganze Kette (Endstand oder Flag einer Zwischenfassung): der Verweis bleibt, das
 *    Aktions-Signal „Übernehmen“ (Kritisch) meldet ihn, bestätigt durch die Rolle Freigeben;
 *  - der Verweis steckt in einem EINGEFROREN Angebot (eingefroren = versprochen): er bleibt, das Signal meldet ihn.
 * Nie angefasst: historische (angenommen/abgelehnt) Angebote, archivierte Ausgaben, die Einfrier-Snapshots und die Historie. Eine Fassung ist unveränderlich;
 * ein Verweis auf eine ältere Fassung zeigt also weiter genau diesen Stand, bis er umgehängt wird.
 *
 * Wer darf umgestellt werden? Die EINE Sichtbarkeits-Autorität: nur wenn die NEUE Fassung für das Team sichtbar ist (Abo-Hülle, erbt_alles, 77d). Gesperrte
 * Teams und das Master-Team nie. Je Verweis eine Transaktion, Fehler werden geloggt, idempotent (umgehängt wird nur, was auf eine ÄLTERE Fassung zeigt).
 * Über {@see SYNC_GRENZE} Verweise: ein Job je Mandant.
 */
class BausteinFolgeService
{
    public const SYNC_GRENZE = 50;

    public function __construct(
        private readonly FassungsVergleich $vergleich,
        private readonly FaRechte $rechte,
        private readonly PlattformProtokoll $protokoll,
    ) {}

    /**
     * Nach dem Veröffentlichen: betroffene Verweise folgen lassen. Ab mehr als {@see SYNC_GRENZE} Verweisen wird je Mandant ein Job eingereiht.
     *
     * @param  'concept'|'paket'|'format'  $art
     * @param  list<int>  $neueFassungIds
     * @return array{umgehaengt:int, gehalten:int, signale: list<array{team:int, familie:int}>, eingereiht: list<int>}
     */
    public function folgen(string $art, array $neueFassungIds): array
    {
        $familien = $this->modell($art)::mitFassungen()->whereIn('id', $neueFassungIds)->where('ist_fassung', true)->pluck('fassung_von_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        $bericht = ['umgehaengt' => 0, 'gehalten' => 0, 'signale' => [], 'eingereiht' => []];
        if ($familien === []) {
            return $bericht;
        }
        $kandidaten = $this->kandidaten($art, $familien);
        $gesamt = $kandidaten->count();
        foreach ($kandidaten->groupBy('team_id') as $teamId => $zeilen) {
            if ($gesamt > (int) config('foodalchemist.baustein_folge.sync_grenze', self::SYNC_GRENZE)) {
                BausteinFolgeJob::dispatch((int) $teamId, $art, $familien)->afterCommit();
                $bericht['eingereiht'][] = (int) $teamId;

                continue;
            }
            $r = \Platform\FoodAlchemist\Support\FaTeam::imTeam((int) $teamId, fn () => $this->folgenFuerTeam((int) $teamId, $art, $familien, false));   // Spec 84 A-4: synchron für ein fremdes Team
            $bericht['umgehaengt'] += $r['umgehaengt'];
            $bericht['gehalten'] += $r['gehalten'];
            array_push($bericht['signale'], ...$r['signale']);
        }

        return $bericht;
    }

    /**
     * Alle betroffenen Verweise EINES Teams. `$signaleNachfuehren`: im Job die Aktions-Signale gleich mit.
     *
     * @param  list<int>  $familien
     * @return array{umgehaengt:int, gehalten:int, signale: list<array{team:int, familie:int}>}
     */
    public function folgenFuerTeam(int $teamId, string $art, array $familien, bool $signaleNachfuehren): array
    {
        $team = Team::find($teamId);
        $out = ['umgehaengt' => 0, 'gehalten' => 0, 'signale' => []];
        if ($team === null) {
            return $out;
        }
        $konzepte = [];
        $letzte = null;
        foreach ($this->kandidaten($art, $familien)->where('team_id', $teamId) as $k) {
            try {
                $out['signale'][] = ['team' => $teamId, 'familie' => $k->familie_id];
                $e = $this->entscheide($team, $art, $k);
                if ($e['aktion'] === 'halten') {
                    $out['gehalten']++;

                    continue;
                }
                if ($e['aktion'] !== 'folgen') {
                    continue;
                }
                $letzte = $this->haengeUm($team, $art, $k, (int) $e['neu']->id, null, 'auto', [], false);
                $out['umgehaengt']++;
                if ($k->konzept_eltern_id !== null) {
                    $konzepte[(int) $k->konzept_eltern_id] = true;
                }
            } catch (\Throwable $ex) {
                Log::error('Verweis folgt der neuen Abo-Fassung nicht ('.$k->quelle.' #'.$k->row_id.'): '.$ex->getMessage(), ['exception' => $ex]);
            }
        }
        $this->neuBerechnen(array_keys($konzepte));
        if ($out['umgehaengt'] > 0) {
            $this->protokoll->schreibe('baustein_fassung_gefolgt', ['team_id' => $teamId, 'art' => $art, 'verweise' => $out['umgehaengt']], null, $letzte);
        }
        $out['signale'] = collect($out['signale'])->unique(fn ($s) => $s['team'].':'.$s['familie'])->values()->all();
        if ($signaleNachfuehren) {
            foreach ($out['signale'] as $s) {
                try {
                    app(FassungSignalService::class)->aktionBaustein($team, $art, $s['familie']);
                } catch (\Throwable $ex) {
                    Log::error('Signal nach Baustein-Folge fehlgeschlagen: '.$ex->getMessage(), ['exception' => $ex]);
                }
            }
        }

        app(AboService::class)->huelleNachfuehren($teamId);   // Spec 84 B3 Regel B: nicht mehr referenzierte ältere Fassung fällt aus der Hülle

        return $out;
    }

    /**
     * Verweise, die auf einer älteren Fassung der Familie stehen. Je Quelle des Registers eine indizierte Abfrage.
     *
     * @param  'concept'|'paket'|'format'  $art
     * @param  list<int>  $familien
     * @return Collection<int, object{quelle:string, tab:string, spalte:string, row_id:int, team_id:int, alt_id:int, familie_id:int, eltern_id:int, eltern_name:?string, wo:string, eingefroren:bool, konzept_eltern_id:?int}>
     */
    public function kandidaten(string $art, array $familien): Collection
    {
        $fassungen = $this->modell($art)::mitFassungen()->where('ist_fassung', true)->whereIn('fassung_von_id', $familien)->get(['id', 'fassung_von_id', 'fassung_nr']);
        $maxNr = $fassungen->groupBy('fassung_von_id')->map(fn ($g) => (int) $g->max('fassung_nr'));
        $aeltere = $fassungen->filter(fn ($f) => (int) $f->fassung_nr < (int) $maxNr[$f->fassung_von_id])->keyBy('id');
        if ($aeltere->isEmpty()) {
            return collect();
        }
        $eingefroren = DB::table('foodalchemist_angebot_einfrierungen')->whereNull('verworfen_at')->pluck('angebot_id')->map(fn ($v) => (int) $v)->flip();
        $alle = collect();
        foreach (VerweisRegister::REFERENZEN as $quelle => $r) {
            if ($r['art'] !== $art) {
                continue;
            }
            $q = VerweisRegister::basis($quelle)->whereIn('x.'.$r['spalte'], $aeltere->keys()->all());
            foreach ($q->select('x.id AS row_id', 'x.team_id', 'x.'.$r['spalte'].' AS alt_id', 'p.id AS eltern_id', 'p.'.$r['name'].' AS eltern_name')->get() as $row) {
                $alle->push((object) ['quelle' => $quelle, 'tab' => $r['tab'], 'spalte' => $r['spalte'], 'row_id' => (int) $row->row_id, 'team_id' => (int) $row->team_id,
                    'alt_id' => (int) $row->alt_id, 'familie_id' => (int) $aeltere[(int) $row->alt_id]->fassung_von_id, 'eltern_id' => (int) $row->eltern_id,
                    'eltern_name' => $row->eltern_name, 'wo' => $r['wo'], 'eingefroren' => ! empty($r['angebot']) && $eingefroren->has((int) $row->eltern_id),
                    'konzept_eltern_id' => $quelle === 'konzept_paket' || $quelle === 'konzept_eingebettet' ? (int) $row->eltern_id : null]);
            }
        }

        return $alle;
    }

    /**
     * Entscheidung für EINEN Verweis: folgen | halten | uebersprungen (stateless, dieselbe Regel für Auto-Folgen und Signal).
     *
     * @return array{aktion:string, grund:?string, neu:?object, diff:array<string,mixed>, zwischen:list<array>}
     */
    public function entscheide(Team $team, string $art, object $k): array
    {
        $leer = fn (string $grund) => ['aktion' => 'uebersprungen', 'grund' => $grund, 'neu' => null, 'diff' => [], 'zwischen' => []];
        if (MasterTeam::istMaster($team)) {
            return $leer('master');
        }
        if (TeamSperre::istGesperrt($team)) {
            return $leer('gesperrt');
        }
        $modell = $this->modell($art);
        $alt = $modell::mitFassungen()->find($k->alt_id);
        $neu = $alt === null ? null : $modell::visibleToTeam($team)->where($alt->getTable().'.ist_fassung', true)->where($alt->getTable().'.fassung_von_id', $k->familie_id)
            ->orderByDesc('fassung_nr')->orderByDesc('id')->first();   // die eine Sichtbarkeits-Autorität
        if ($alt === null || $neu === null) {
            return $leer('nicht_sichtbar');
        }
        if ((int) $neu->fassung_nr <= (int) $alt->fassung_nr) {
            return $leer('aktuell');
        }
        $zwischen = $modell::mitFassungen()->where('ist_fassung', true)->where('fassung_von_id', $k->familie_id)
            ->where('fassung_nr', '>', $alt->fassung_nr)->where('fassung_nr', '<=', $neu->fassung_nr)->orderBy('fassung_nr')->get(['id', 'fassung_nr', 'aenderungsnotiz', 'fassung_allergen_geaendert']);
        $diff = $this->vergleich->bilderDiff($this->vergleich->kettenBild($art, (int) $alt->id), $this->vergleich->kettenBild($art, (int) $neu->id));
        $zw = $zwischen->map(fn ($f) => ['fassung_id' => (int) $f->id, 'fassung_nr' => (int) $f->fassung_nr, 'aenderungsnotiz' => $f->aenderungsnotiz,
            'allergen_geaendert' => (bool) $f->fassung_allergen_geaendert])->all();
        if ($diff !== [] || $zwischen->contains(fn ($f) => (bool) $f->fassung_allergen_geaendert)) {
            return ['aktion' => 'halten', 'grund' => 'allergen', 'neu' => $neu, 'diff' => $diff, 'zwischen' => $zw];
        }
        if ($k->eingefroren) {
            return ['aktion' => 'halten', 'grund' => 'eingefroren', 'neu' => $neu, 'diff' => [], 'zwischen' => $zw];
        }

        return ['aktion' => 'folgen', 'grund' => null, 'neu' => $neu, 'diff' => [], 'zwischen' => $zw];
    }

    /**
     * Die gehaltenen Verweise eines Teams zu einer Familie (für Signal und Übernehmen-Liste).
     *
     * @return list<array{quelle:string, row_id:int, wo:string, name:?string, von_nr:int, nach_nr:int, grund:string, allergen_diff:array, zwischen:list<array>}>
     */
    public function gehalten(Team $team, string $art, int $familieId): array
    {
        $out = [];
        foreach ($this->kandidaten($art, [$familieId])->where('team_id', $team->id) as $k) {
            $e = $this->entscheide($team, $art, $k);
            if ($e['aktion'] !== 'halten') {
                continue;
            }
            $alt = $this->modell($art)::mitFassungen()->find($k->alt_id);
            $out[] = ['quelle' => $k->quelle, 'row_id' => $k->row_id, 'wo' => $k->wo, 'name' => $k->eltern_name, 'von_nr' => (int) $alt->fassung_nr,
                'nach_nr' => (int) $e['neu']->fassung_nr, 'grund' => (string) $e['grund'], 'allergen_diff' => $e['diff'], 'zwischen' => $e['zwischen']];
        }

        return $out;
    }

    /**
     * Übernehmen EINES gehaltenen Verweises (Eigenes Konzept, Format, Foodbook, Speisekarte, Speiseplan, Angebot). Ohne `$bestaetigt` nur die Vorschau.
     * Allergen-Änderung: Bestätigung Pflicht und Rolle Freigeben; sonst Rolle Kuratieren. Historie + Protokoll.
     *
     * @param  'concept'|'paket'|'format'  $art
     * @return array{vorschau?: array, umgehaengt?: bool, nach_fassung_id?: int}
     */
    public function uebernehmen(Team $team, ?User $actor, string $art, string $quelle, int $rowId, bool $bestaetigt): array
    {
        if (! isset(VerweisRegister::REFERENZEN[$quelle]) || VerweisRegister::REFERENZEN[$quelle]['art'] !== $art) {
            throw new \RuntimeException('Unbekannte Quelle für diese Art.');
        }
        $k = $this->kandidatFuer($team, $art, $quelle, $rowId);
        $e = $this->entscheide($team, $art, $k);
        if (! in_array($e['aktion'], ['halten', 'folgen'], true)) {
            throw new \RuntimeException('Hier gibt es nichts zu übernehmen ('.($e['grund'] ?? 'aktuell').').');
        }
        $allergen = $e['grund'] === 'allergen';
        $vorschau = ['wo' => $k->wo, 'name' => $k->eltern_name, 'von_fassung_id' => $k->alt_id, 'nach_fassung_id' => (int) $e['neu']->id, 'grund' => $e['grund'],
            'allergen_diff' => $e['diff'], 'zwischen' => $e['zwischen']];
        if (! $bestaetigt) {
            return ['vorschau' => $vorschau];
        }
        $this->rechte->pruefe($actor, $team, $allergen ? FaRolle::Freigeben : FaRolle::Kuratieren, 'Neue Fassung übernehmen');
        if ($allergen && $e['diff'] === [] && ! collect($e['zwischen'])->contains('allergen_geaendert', true)) {
            throw new \RuntimeException('Allergen-Änderung ohne Befund.');
        }
        $this->haengeUm($team, $art, $k, (int) $e['neu']->id, $actor, 'manuell', $e['diff'], $allergen);
        $this->neuBerechnen($k->konzept_eltern_id !== null ? [(int) $k->konzept_eltern_id] : []);
        $this->protokoll->schreibe('baustein_fassung_uebernommen', ['team_id' => $team->id, 'art' => $art, 'quelle' => $quelle, 'row_id' => $rowId,
            'von' => $k->alt_id, 'nach' => (int) $e['neu']->id, 'allergen' => $allergen], $actor, null);
        try {
            app(FassungSignalService::class)->aktionBaustein($team, $art, $k->familie_id);
        } catch (\Throwable $ex) {
            Log::error('Signal nach Übernehmen fehlgeschlagen: '.$ex->getMessage(), ['exception' => $ex]);
        }

        app(AboService::class)->huelleNachfuehren($team);   // Spec 84 B3 Regel B: nicht mehr referenzierte ältere Fassung fällt aus der Hülle

        return ['umgehaengt' => true, 'nach_fassung_id' => (int) $e['neu']->id];
    }

    private function kandidatFuer(Team $team, string $art, string $quelle, int $rowId): object
    {
        $r = VerweisRegister::REFERENZEN[$quelle];
        $alt = DB::table($r['tab'])->where('id', $rowId)->where('team_id', $team->id)->whereNull('deleted_at')->value($r['spalte']);
        $fassung = $alt === null ? null : $this->modell($art)::mitFassungen()->where('ist_fassung', true)->find((int) $alt);
        if ($fassung === null) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        }
        $k = $this->kandidaten($art, [(int) $fassung->fassung_von_id])->where('team_id', $team->id)->where('quelle', $quelle)->firstWhere('row_id', $rowId);
        if ($k === null) {
            throw new \RuntimeException('Dieser Verweis steht auf der neuesten Fassung oder gehört zu einer historischen Ausgabe.');
        }

        return $k;
    }

    /** Verweis umhängen + Historie in EINER Transaktion. */
    private function haengeUm(Team $team, string $art, object $k, int $neuId, ?User $actor, string $modus, array $diff, bool $allergen): FoodAlchemistAngebotUebernahme
    {
        return DB::transaction(function () use ($team, $art, $k, $neuId, $actor, $modus, $diff, $allergen) {
            DB::table($k->tab)->where('id', $k->row_id)->where($k->spalte, $k->alt_id)->update([$k->spalte => $neuId]);

            return FoodAlchemistAngebotUebernahme::create([
                'team_id' => $team->id, 'angebot_id' => $k->quelle === 'angebot' ? $k->eltern_id : null, 'rezept_id' => null, 'art' => 'baustein', 'familie_id' => $k->familie_id,
                'von_fassung_id' => $k->alt_id, 'nach_fassung_id' => $neuId, 'allergen_geaendert' => $allergen, 'allergen_bestaetigt' => $allergen && $actor !== null,
                'bestaetigt_von' => $actor?->id, 'bestaetigt_at' => $actor !== null ? now() : null, 'diff' => $diff,
                'umgehaengt' => ['modus' => $modus, 'art' => $art, 'quelle' => $k->quelle, 'tabelle' => $k->tab, 'spalte' => $k->spalte, 'row_id' => $k->row_id, 'eltern_id' => $k->eltern_id],
            ]);
        });
    }

    /** Eigene Konzepte, deren Slots umgehängt wurden: Preis-Cache neu (mit den Team-Preisen). */
    private function neuBerechnen(array $konzeptIds): void
    {
        foreach ($konzeptIds as $id) {
            try {
                $c = FoodAlchemistConcept::find((int) $id);
                if ($c !== null) {
                    app(ConceptService::class)->recomputeCache($c);
                }
            } catch (\Throwable $ex) {
                Log::error('Neuberechnung nach Baustein-Folge fehlgeschlagen (Konzept '.$id.'): '.$ex->getMessage(), ['exception' => $ex]);
            }
        }
    }

    /** @return class-string<FoodAlchemistConcept|FoodAlchemistPaket|\Platform\FoodAlchemist\Models\FoodAlchemistFormat> */
    private function modell(string $art): string
    {
        return match ($art) {
            'paket' => FoodAlchemistPaket::class,
            'format' => \Platform\FoodAlchemist\Models\FoodAlchemistFormat::class,
            default => FoodAlchemistConcept::class,
        };
    }
}
