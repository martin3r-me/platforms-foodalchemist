<?php

namespace Platform\FoodAlchemist\Services\Rezept;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Plan 98 · 3.5 Schritt 6c · Abweichungsreport vor dem Entfernen der gespiegelten Darreichungsfelder am Rezept. NUR LESEND.
 *
 * Je Spiegelfeld: wie viele Rezepte einen Wert tragen, der von ihrer Standard-Darreichung abweicht („abweichend“), und wie viele einen
 * Wert, den die Darreichung gar nicht hat („nur_rezept“ — ginge beim Drop verloren, ließe sich vorher in die Darreichung übernehmen).
 * Extra (Entscheid Dominique): Gerichte mit `sales_unit_count` > 1 — heute teilt {@see DarreichungService::grammJeEinheit} noch
 * durch diese Zahl, danach gilt fest 1; der Report zeigt die Grammatur heute und mit Teiler 1. Dazu Rezepte mit Spiegelwerten ohne
 * Standard-Darreichung und die belegten Altspalten (`*_legacy_id`).
 *
 * Geprüft werden alle nicht gelöschten Rezeptzeilen inkl. Fassungen (die Spalten fallen für alle weg), ohne Team-Sicht.
 */
final class SpiegelfelderReport
{
    /** Spiegelfeld am Rezept → Feld an der Standard-Darreichung. */
    public const SPIEGEL = [
        'sales_unit_vocab_id' => 'unit_vocab_id',
        'container_warm_vocab_id' => 'container_warm_vocab_id',
        'container_cold_vocab_id' => 'container_cold_vocab_id',
        'serving_vehicle_vocab_id' => 'serving_vehicle_vocab_id',
    ];

    /** Grammatur: Spiegel von {@see DarreichungService::grammJeEinheit} (gerundet auf 0,1 g, Toleranz wie kopfSpiegeln). */
    public const GRAMMATUR = 'sales_quantity_per_unit_g';

    public const PORTIONEN = 'sales_unit_count';

    public const ALTSPALTEN = ['container_warm_legacy_id', 'container_cold_legacy_id', 'serving_vehicle_legacy_id'];

    public function __construct(private DarreichungService $darreichungen) {}

    /**
     * @return array{rezepte: int, fassungen: int, felder: array<string, array{abweichend: int, nur_rezept: int, beispiele: list<array>}>,
     *               portionen_gt_1: array{anzahl: int, beispiele: list<array>}, ohne_standard: array{anzahl: int, beispiele: list<array>},
     *               altspalten: array<string, int>, entfernt: bool}
     */
    public function bericht(int $beispiele = 10): array
    {
        $felder = array_fill_keys([...array_keys(self::SPIEGEL), self::GRAMMATUR], ['abweichend' => 0, 'nur_rezept' => 0, 'beispiele' => []]);
        $out = ['rezepte' => 0, 'fassungen' => 0, 'felder' => $felder, 'portionen_gt_1' => ['anzahl' => 0, 'beispiele' => []],
            'ohne_standard' => ['anzahl' => 0, 'beispiele' => []], 'altspalten' => [], 'entfernt' => false];
        $spiegelSpalten = [...array_keys(self::SPIEGEL), self::GRAMMATUR];
        if (\Platform\FoodAlchemist\Support\Datenmodell\SpiegelfelderRueckbau::vorhanden() === []) {
            // 98 · 3.5 6c: Spalten gedroppt — nichts mehr zu vergleichen (die Werte liegen im Backup des Rückbaus)
            $out['entfernt'] = true;

            return $out;
        }

        FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('spiegelfelder-report')
            ->where(function ($q) use ($spiegelSpalten) {
                $q->where('is_sales_recipe', true)->orWhere(self::PORTIONEN, '>', 1);
                foreach ($spiegelSpalten as $s) {
                    $q->orWhereNotNull($s);
                }
            })
            ->with(['standardPresentation', 'ingredients'])
            ->chunkById(200, function ($rezepte) use (&$out, $beispiele) {
                foreach ($rezepte as $r) {
                    $this->pruefe($r, $out, $beispiele);
                }
            });

        foreach (self::ALTSPALTEN as $s) {
            $out['altspalten'][$s] = Schema::hasColumn('foodalchemist_recipes', $s)
                ? (int) DB::table('foodalchemist_recipes')->whereNull('deleted_at')->whereNotNull($s)->count() : 0;
        }

        return $out;
    }

    private function pruefe(FoodAlchemistRecipe $r, array &$out, int $beispiele): void
    {
        $out['rezepte']++;
        $out['fassungen'] += (int) (bool) $r->ist_fassung;
        $kopf = ['id' => (int) $r->id, 'name' => (string) $r->name, 'team_id' => (int) $r->team_id, 'fassung' => (bool) $r->ist_fassung];
        $d = $r->standardPresentation;

        if ((int) ($r->{self::PORTIONEN} ?? 1) > 1) {
            $out['portionen_gt_1']['anzahl']++;
            if (count($out['portionen_gt_1']['beispiele']) < $beispiele) {
                $heute = $d !== null ? $this->darreichungen->grammJeEinheit($d, $r) : null;
                $out['portionen_gt_1']['beispiele'][] = $kopf + ['portionen' => (int) $r->{self::PORTIONEN},
                    'gramm_heute' => $heute !== null ? round($heute, 1) : null,
                    'gramm_teiler_1' => $heute !== null ? round($heute * (int) $r->{self::PORTIONEN}, 1) : null];
            }
        }

        if ($d === null) {
            $belegt = array_values(array_filter([...array_keys(self::SPIEGEL), self::GRAMMATUR], fn ($s) => $r->{$s} !== null));
            if ($belegt !== []) {
                $out['ohne_standard']['anzahl']++;
                if (count($out['ohne_standard']['beispiele']) < $beispiele) {
                    $out['ohne_standard']['beispiele'][] = $kopf + ['felder' => $belegt];
                }
            }

            return;
        }

        foreach (self::SPIEGEL as $amRezept => $anDarreichung) {
            $rw = $r->{$amRezept};
            $dw = $d->{$anDarreichung};
            if ($rw === null) {
                continue;
            }
            $art = $dw === null ? 'nur_rezept' : ((int) $rw !== (int) $dw ? 'abweichend' : null);
            if ($art !== null) {
                $this->zaehle($out, $amRezept, $art, $kopf + ['rezept' => (int) $rw, 'darreichung' => $dw === null ? null : (int) $dw, 'art' => $art], $beispiele);
            }
        }

        $rw = $r->{self::GRAMMATUR};
        if ($rw !== null) {
            $soll = $this->darreichungen->grammJeEinheit($d, $r);
            $art = $soll === null ? 'nur_rezept' : (abs((float) $rw - round($soll, 1)) >= 0.05 ? 'abweichend' : null);
            if ($art !== null) {
                $this->zaehle($out, self::GRAMMATUR, $art, $kopf + ['rezept' => (float) $rw, 'darreichung' => $soll === null ? null : round($soll, 1), 'art' => $art], $beispiele);
            }
        }
    }

    private function zaehle(array &$out, string $feld, string $art, array $beispiel, int $beispiele): void
    {
        $out['felder'][$feld][$art]++;
        if (count($out['felder'][$feld]['beispiele']) < $beispiele) {
            $out['felder'][$feld]['beispiele'][] = $beispiel;
        }
    }
}
