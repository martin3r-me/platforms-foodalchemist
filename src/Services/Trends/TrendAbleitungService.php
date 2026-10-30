<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\TrendAbleitungJob;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Spec 93 D · „Was heißt das für uns“: Kurzfazit, Bedeutung je Sparte und 3–5 Gericht-/Konzept-Impulse am Trend.
 *
 * Die KI (TrendAbleitungJob, Prompt `trend.ableitung`, dual) schreibt NUR in die ableitung*-Spalten — Definition, historische
 * Einordnung und Begründung fasst sie nie an; eine von Hand bearbeitete Ableitung (`ableitung_quelle = manuell`) nie wieder.
 * Automatisch angestoßen nur für Trends der Automatik (herkunft = automatisch) beim Erreichen von „geprüft“ oder „auf dem Radar“;
 * Bestandstrends nur per Knopf oder Tool. Ein gescheiterter Lauf steht sichtbar in `ableitung_fehler`.
 */
class TrendAbleitungService
{
    public const IMPULSE_MIN = 3;

    public const IMPULSE_MAX = 5;

    public function __construct(private readonly FaRechte $rechte) {}

    /** Prompt-Kontext: stabil sortiert (Belege nach ID), damit der Fingerabdruck des externen Wegs hält. */
    public function kontext(FoodAlchemistTrend $t): array
    {
        return [
            'trend' => array_filter([
                'name' => $t->name, 'typ' => $t->typ, 'ebene' => $t->ebene, 'kategorie' => $t->kategorie,
                'sparten' => $t->sparten ?: null, 'definition' => $t->definition, 'begruendung' => $t->einordnung_begruendung,
            ], fn ($v) => $v !== null && $v !== ''),
            'belege' => $t->belege()->orderBy('id')->limit(12)->pluck('titel')->filter()->values()->all(),
            'sparten_vokabular' => V::SPARTEN,
        ];
    }

    /**
     * Ableitung anstoßen (nach dem Commit). Extern, wenn ein MCP-Aufruf dahintersteht — das Flag wird JETZT gelesen.
     * Mit Benutzer: Kuratieren im eigenen Team. Eine manuelle Ableitung bleibt (Rückgabe false).
     */
    public function anstossen(Team $team, int $trendId, ?int $userId = null): bool
    {
        if ($userId !== null) {
            $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Trend-Ableitung erzeugen');
        } else {
            $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Trend-Ableitung erzeugen');
        }
        $t = FoodAlchemistTrend::where('team_id', $team->id)->findOrFail($trendId);
        if ($t->ableitung_quelle === 'manuell') {
            return false;
        }
        $extern = app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->fuerDispatch();
        $id = (int) $t->id;
        $teamId = (int) $team->id;
        DB::afterCommit(fn () => TrendAbleitungJob::dispatch($id, $teamId, $extern));

        return true;
    }

    /** Vom Job: Antwort prüfen und übernehmen; ungültig → ableitung_fehler, die bisherige Ableitung bleibt. */
    public function uebernehmen(FoodAlchemistTrend $t, array $w): FoodAlchemistTrend
    {
        if ($t->ableitung_quelle === 'manuell') {
            return $t;
        }
        $ableitung = $this->pruefe($w);
        if (is_string($ableitung)) {
            return $this->fehler($t, $ableitung);
        }
        $t->forceFill(['ableitung' => $ableitung, 'ableitung_quelle' => 'ki', 'ableitung_at' => now(), 'ableitung_fehler' => null])->save();

        return $t->refresh();
    }

    public function fehler(FoodAlchemistTrend $t, string $grund): FoodAlchemistTrend
    {
        $t->forceFill(['ableitung_fehler' => mb_substr($grund, 0, 1000)])->save();

        return $t->refresh();
    }

    /** Von Hand setzen (UI, trends.PUT): gilt dauerhaft, die KI fasst sie nicht mehr an. null = Ableitung entfernen, KI darf wieder. */
    public function manuellSetzen(Team $team, int $trendId, ?array $daten, ?int $userId = null): FoodAlchemistTrend
    {
        if ($userId !== null) {
            $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Trend-Ableitung bearbeiten');
        } else {
            $this->rechte->pruefeAngemeldet($team, FaRolle::Kuratieren, 'Trend-Ableitung bearbeiten');
        }
        $t = FoodAlchemistTrend::where('team_id', $team->id)->findOrFail($trendId);
        if ($daten === null) {
            $t->forceFill(['ableitung' => null, 'ableitung_quelle' => null, 'ableitung_at' => null, 'ableitung_fehler' => null])->save();

            return $t->refresh();
        }
        $ableitung = $this->pruefe($daten, false);
        if (is_string($ableitung)) {
            throw new \RuntimeException($ableitung);
        }
        $t->forceFill(['ableitung' => $ableitung, 'ableitung_quelle' => 'manuell', 'ableitung_at' => now(), 'ableitung_fehler' => null])->save();

        return $t->refresh();
    }

    /** Gültige Ableitung ohne Fehler (für Planung und Anzeige), sonst null. */
    public function gueltig(FoodAlchemistTrend $t): ?array
    {
        return in_array($t->ableitung_quelle, ['ki', 'manuell'], true) && $t->ableitung_fehler === null && is_array($t->ableitung) && $t->ableitung !== []
            ? $t->ableitung : null;
    }

    /**
     * @return array{kurzfazit:?string, bedeutung:array<string,string>, impulse:list<array{art:string,titel:string,beschreibung:?string}>}|string
     *                                                                                                                                          string = Fehlergrund
     */
    private function pruefe(array $w, bool $streng = true): array|string
    {
        $kurz = function (mixed $v, int $max): ?string {
            $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($v ?? ''))) ?? '');

            return $t === '' ? null : mb_substr($t, 0, $max);
        };
        $bedeutung = [];
        foreach ((array) ($w['bedeutung'] ?? []) as $sparte => $text) {
            if (array_key_exists((string) $sparte, V::SPARTEN) && ($t = $kurz($text, 400)) !== null) {
                $bedeutung[(string) $sparte] = $t;
            }
        }
        ksort($bedeutung);
        $impulse = [];
        foreach ((array) ($w['impulse'] ?? []) as $i) {
            $titel = is_array($i) ? $kurz($i['titel'] ?? null, 120) : null;
            if ($titel === null) {
                continue;
            }
            $impulse[] = ['art' => ($i['art'] ?? '') === 'konzept' ? 'konzept' : 'gericht', 'titel' => $titel, 'beschreibung' => $kurz($i['beschreibung'] ?? null, 200)];
        }
        if ($streng && (count($impulse) < self::IMPULSE_MIN || count($impulse) > self::IMPULSE_MAX)) {
            return 'KI-Antwort mit '.count($impulse).' Impulsen (erwartet '.self::IMPULSE_MIN.'–'.self::IMPULSE_MAX.')';
        }
        if (! $streng && $impulse === [] && $bedeutung === [] && $kurz($w['kurzfazit'] ?? null, 300) === null) {
            return 'Die Ableitung ist leer.';
        }

        return ['kurzfazit' => $kurz($w['kurzfazit'] ?? null, 300), 'bedeutung' => $bedeutung, 'impulse' => array_slice($impulse, 0, self::IMPULSE_MAX)];
    }
}
