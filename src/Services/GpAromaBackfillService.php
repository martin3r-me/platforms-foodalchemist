<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\GpAromaNachholen;

/**
 * Bestand nachziehen: Kern-Anker + Geschmacksprofil für GPs mit Lücke — EIN Service für den Artisan-Befehl
 * `foodalchemist:gp-aroma-backfill` und das MCP-Tool `foodalchemist.gp_aroma_backfill.RUN` (kein Nachbau, #1002).
 * Reihenfolge je GP: erst die Geschwister (kostenlos), dann die KI (Plattform-Provider oder, extern, KI-Aufträge für einen MCP-Client).
 * Nur Lücken: ein vorhandenes Profil/ein Anker wird nie neu bezahlt, ein manuelles Profil nie angefasst.
 */
class GpAromaBackfillService
{
    /**
     * Quellen, deren Profil neu geschätzt werden darf (manual nie). `rezept` = gegarte Profile in recipe_taste_vectors (source ai).
     * `anker_neutral` = Anker-Frage für GPs mit „geprüft, kein Anker" erneut stellen (io-Probe 01.11.2026).
     */
    public const NEU_QUELLEN = ['ai', 'geschwister', 'gemini', 'rule', 'rezept', 'anker_neutral'];

    /** Token-Annahme ohne Call-Historie (gemessen an recipe.sensorik/gp.anker auf demo, 19.09.). */
    private const ANNAHME = ['gp.sensorik' => ['in' => 1200, 'out' => 250], 'gp.anker' => ['in' => 1500, 'out' => 120]];

    /**
     * @param  array{fuer_team?: bool, nur?: string, apply?: bool, nur_geschwister?: bool, limit?: int, extern?: bool, neu_quellen?: list<string>, chunk?: int, pause?: int, bericht?: ?string}  $o
     * @param  callable(string, mixed): void|null  $ausgabe  (Art 'line'|'warn'|'ergebnis', Text bzw. Zwischenergebnis) — Fortschritt für den Befehl
     * @return array<string, mixed>
     */
    public function lauf(?Team $team, ?User $user, array $o, ?callable $ausgabe = null): array
    {
        $ausgabe ??= static function (string $art, mixed $text): void {
        };
        $nur = (string) ($o['nur'] ?? 'beide');
        $apply = (bool) ($o['apply'] ?? false);
        $nurGeschwister = (bool) ($o['nur_geschwister'] ?? false);
        $limit = max(0, (int) ($o['limit'] ?? 0));
        $extern = (bool) ($o['extern'] ?? false);
        $neuQuellen = array_values((array) ($o['neu_quellen'] ?? []));
        $chunkGroesse = max(1, (int) ($o['chunk'] ?? 25));
        $pause = max(0, (int) ($o['pause'] ?? 3));
        // fuer_team: nur die GPs, die DIESES Team pflegen darf (team-eigen; das Master-Team zusätzlich die globalen). MCP-Aufruf eines Mandanten.
        $scope = ($o['fuer_team'] ?? false) && $team !== null ? $team : null;
        $bericht = (string) (($o['bericht'] ?? null) ?: storage_path('app/fa-gp-aroma-backfill'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');
        $aroma = app(GpAromaService::class);
        $bulk = app(BulkEnrichService::class);

        $neutralNeu = in_array('anker_neutral', $neuQuellen, true);
        $ohneAnker = $nur !== 'profil' ? $this->ohneAnker($scope, $neutralNeu) : [];
        $ohneProfil = $nur !== 'anker' ? $this->ohneProfil($scope) : [];
        $alle = array_values(array_unique(array_merge($ohneAnker, $ohneProfil)));
        sort($alle);
        $istOhneAnker = array_flip($ohneAnker);
        $istOhneProfil = array_flip($ohneProfil);

        // ── Geschwister-Teil (kostenlos) ──
        $gAnker = ['einig' => 0, 'uneinig' => 0, 'keine' => 0, 'gesperrt' => 0];
        $gProfil = ['einig' => 0, 'uneinig' => 0, 'keine' => 0, 'gesperrt' => 0];
        $geschrieben = ['anker' => 0, 'profil' => 0];
        $warnungen = [];
        foreach (array_chunk($alle, 500) as $chunk) {
            foreach (FoodAlchemistGp::whereIn('id', $chunk)->get() as $gp) {
                if (! $aroma->pflegbar($gp)) {                       // globaler Basis-Stamm: für alle nur lesbar → weder Geschwister noch KI
                    $gAnker['gesperrt'] += isset($istOhneAnker[$gp->id]) ? 1 : 0;
                    $gProfil['gesperrt'] += isset($istOhneProfil[$gp->id]) ? 1 : 0;

                    continue;
                }
                if (isset($istOhneAnker[$gp->id])) {
                    $gAnker[$aroma->geschwisterAnker($gp)['status']]++;
                }
                if (isset($istOhneProfil[$gp->id])) {
                    $gProfil[$aroma->geschwisterProfil($gp)['status']]++;
                }
                if ($apply) {
                    try {
                        $geschrieben['anker'] += $nur !== 'profil' && $aroma->ankerVonGeschwistern($gp) > 0 ? 1 : 0;
                        $geschrieben['profil'] += $nur !== 'anker' && $aroma->profilVonGeschwistern($gp) ? 1 : 0;
                    } catch (\Throwable $e) {
                        $warnungen[] = "GP {$gp->id}: {$e->getMessage()}";
                        $ausgabe('warn', "GP {$gp->id}: {$e->getMessage()}");
                    }
                }
            }
        }
        $kiAnker = $gAnker['uneinig'] + $gAnker['keine'];
        $kiProfil = $gProfil['uneinig'] + $gProfil['keine'];
        $nAnker = $limit > 0 ? min($limit, $kiAnker) : $kiAnker;
        $nProfil = $limit > 0 ? min($limit, $kiProfil) : $kiProfil;
        $neuGps = array_values(array_intersect($neuQuellen, ['ai', 'geschwister', 'gemini', 'rule'])) !== [] ? $this->neuGps($neuQuellen, $scope) : [];
        $neuRezepte = in_array('rezept', $neuQuellen, true) ? $this->neuRezepte($scope) : [];
        if ($neuQuellen !== []) {
            $nProfil += $limit > 0 ? min($limit, count($neuGps)) : count($neuGps);
        }
        $teile = [$this->kosten('gp.anker', $nAnker), $this->kosten('gp.sensorik', $nProfil)];

        $ergebnis = [
            'luecken' => ['anker' => count($ohneAnker), 'profil' => count($ohneProfil)],
            'geschwister' => ['anker' => $gAnker, 'profil' => $gProfil],
            'ki_geplant' => ['anker' => $kiAnker, 'profil' => $kiProfil],
            'ki_calls' => ['anker' => $nAnker, 'profil' => $nProfil],
            'geschrieben' => $geschrieben,
            'neu' => $neuQuellen === [] ? null : ['quellen' => $neuQuellen, 'gp' => count($neuGps), 'rezepte' => count($neuRezepte)],
            'kosten' => ['eur' => array_sum(array_column($teile, 'eur')), 'eur_unbekannt' => in_array(null, array_column($teile, 'eur'), true),
                'tin' => array_sum(array_column($teile, 'tin')), 'tout' => array_sum(array_column($teile, 'tout')), 'basis' => array_column($teile, 'basis')],
            'pflegbar_gesamt' => count($alle), 'apply' => $apply, 'nur_geschwister' => $nurGeschwister, 'limit' => $limit, 'extern' => $extern,
            'ki' => null, 'warnungen' => $warnungen,
        ];
        $ausgabe('ergebnis', $ergebnis);   // der Befehl druckt Tabelle/Kosten vor dem KI-Teil
        if (! $apply || $nurGeschwister) {
            return $ergebnis;
        }
        if ($team === null || $user === null) {
            throw new \RuntimeException('Der KI-Teil braucht ein Team und einen Nutzer (die KI läuft in dessen Namen).');
        }

        // ── KI-Teil ──
        Auth::setUser($user);   // MCP läuft über den Token-Guard (kein login()); setUser gilt für jeden Guard, CLI wie MCP
        $offen = array_values(array_unique(array_merge($nur !== 'profil' ? $this->ohneAnker($scope, $neutralNeu) : [], $nur !== 'anker' ? $this->ohneProfil($scope) : [])));
        sort($offen);
        $eigene = [];                                                // nur, was dieses Team pflegen darf — sonst zählt limit Leerläufe mit
        foreach (array_chunk($offen, 500) as $chunk) {
            foreach (FoodAlchemistGp::whereIn('id', $chunk)->get() as $gp) {
                if ($gp->isOwnedBy($team)) {
                    $eigene[] = (int) $gp->id;
                }
            }
        }
        sort($eigene);
        $offen = $this->streueNachWg($eigene);                       // eine limit-Messung soll alle Warengruppen treffen, nicht nur die ersten IDs
        if ($limit > 0) {
            $offen = array_slice($offen, 0, $limit);
        }
        $seit = now();
        $ausstehend = 0;
        $uebernommen = 0;
        $protokoll = "{$bericht}/{$stempel}_aroma_protokoll.jsonl";
        $n = 0;
        foreach (array_chunk($offen, $chunkGroesse) as $i => $chunk) {
            if ($i > 0 && $pause > 0) {
                sleep($pause);
            }
            foreach ($chunk as $gpId) {
                $gp = FoodAlchemistGp::find($gpId);
                if (! $aroma->anreicherbar($gp) || ! $gp->isOwnedBy($team)) {
                    continue;
                }
                $schritte = array_values(array_filter(GpAromaNachholen::schritte($gpId, $neutralNeu),
                    fn ($s) => $nur === 'beide' || ($nur === 'anker' && $s === 'anker') || ($nur === 'profil' && $s === 'sensorik')));
                if ($schritte === []) {
                    continue;                                            // inzwischen gefüllt (Wiederaufnahme)
                }
                try {
                    $r = $extern
                        ? app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->within(fn () => $bulk->nachziehenGp($team, [$gpId], $schritte, 'backfill'))
                        : $bulk->nachziehenGp($team, [$gpId], $schritte, 'backfill');
                } catch (\Throwable $e) {
                    $r = ['fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
                }
                $ausstehend += (int) ($r['ausstehend'] ?? 0);
                $uebernommen += (int) ($r['uebernommen'] ?? 0);
                File::append($protokoll, json_encode(['gp_id' => $gpId, 'schritte' => $schritte, 'zeit' => now()->toIso8601String()] + $r, JSON_UNESCAPED_UNICODE)."\n");
                $n++;
            }
            $ausgabe('line', 'Paket '.($i + 1).": {$n} GPs bearbeitet");
        }
        if ($neuQuellen !== []) {
            $n += $this->neuSchaetzen($bulk, $team, $neuGps, $neuRezepte, $limit, $extern, $protokoll, $ausgabe);
        }
        $ki = ['bearbeitet' => $n, 'ausstehend' => $ausstehend, 'uebernommen' => $uebernommen, 'protokoll' => $protokoll, 'zaehler' => null, 'offene_auftraege' => null];
        if ($extern) {
            $ki['zaehler'] = app(\Platform\FoodAlchemist\Services\Ai\KiAuftragService::class)->zaehler((int) $team->id, $seit);
            File::append($protokoll, json_encode(['zaehler' => $ki['zaehler'], 'ausstehend_schritte' => $ausstehend, 'zeit' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE)."\n");
            $ki['offene_auftraege'] = DB::table('foodalchemist_ki_auftraege')->where('team_id', $team->id)->where('status', 'offen')->count();
        }
        $ergebnis['ki'] = $ki;

        return $ergebnis;
    }

    /**
     * GPs mit vorhandenem Profil aus den genannten Quellen (nie manual), das neu geschätzt werden soll.
     *
     * @param  list<string>  $quellen
     * @return list<int>
     */
    private function neuGps(array $quellen, ?Team $scope = null): array
    {
        $gp = array_values(array_intersect($quellen, ['ai', 'geschwister', 'gemini', 'rule']));

        return $this->basis($scope)->join('foodalchemist_gp_taste_vectors AS t', 't.gp_id', '=', 'g.id')
            ->whereIn('t.source', $gp)->where('t.source', '!=', 'manual')
            ->pluck('g.id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<int> Rezepte mit gegartem KI-Profil (source ai), nie manual */
    private function neuRezepte(?Team $scope = null): array
    {
        return DB::table('foodalchemist_recipe_taste_vectors AS t')->join('foodalchemist_recipes AS r', 'r.id', '=', 't.recipe_id')
            ->whereNull('r.deleted_at')->when($scope !== null, fn ($q) => $q->where('r.team_id', $scope->id))->where('t.source', 'ai')->orderBy('t.recipe_id')->pluck('t.recipe_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @param  bool  $neutralNeu  auch GPs mit „geprüft, kein Anker" (Wiederholungslauf)
     * @return list<int> lebende, gepflegte GPs ohne Anker und ohne neutral-Antwort — dieselbe Regel wie {@see GpAromaService::ankerGeklaert()}
     */
    private function ohneAnker(?Team $scope = null, bool $neutralNeu = false): array
    {
        return $this->basis($scope)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_gp_anchor_mappings AS m')
            ->whereColumn('m.gp_id', 'g.id')->whereNull('m.deleted_at'))
            ->when(! $neutralNeu && GpAromaService::neutralTabelle(), fn ($q) => $q->whereNotExists(fn ($n) => $n->select(DB::raw(1))
                ->from(GpAromaService::NEUTRAL_TABELLE.' AS n')->whereColumn('n.gp_id', 'g.id')))
            ->pluck('g.id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @return list<int> lebende, gepflegte GPs ohne brauchbares Profil: keins, oder lauter Nullen aus einem alten Import — dieselbe Regel
     *                   wie {@see GpAromaService::profilFehlt()} (lauter Nullen von Hand, KI oder Geschwistern sind eine Antwort)
     */
    private function ohneProfil(?Team $scope = null): array
    {
        $summe = implode(' + ', array_map(fn ($d) => "t.{$d}", SensorikService::DIMS));

        return $this->basis($scope)->leftJoin('foodalchemist_gp_taste_vectors AS t', 't.gp_id', '=', 'g.id')
            ->where(fn ($q) => $q->whereNull('t.id')->orWhere(fn ($w) => $w->whereRaw("({$summe}) = 0")
                ->where(fn ($s) => $s->whereNull('t.source')->orWhereNotIn('t.source', GpAromaService::NULL_PROFIL_IST_ANTWORT))))
            ->pluck('g.id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Reihum je Warengruppe (Fund io 27.10.2026: die ersten 50 IDs waren fast nur WG 11 Öle/Essige — als Kostenmessung nicht
     * repräsentativ). Innerhalb der WG bleibt die ID-Reihenfolge, damit ein Neustart dieselbe Folge nimmt.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function streueNachWg(array $ids): array
    {
        $wg = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $wg += DB::table('foodalchemist_gps')->whereIn('id', $chunk)->pluck('commodity_group_code', 'id')->all();
        }
        $gruppen = [];
        foreach ($ids as $id) {
            $gruppen[(string) ($wg[$id] ?? '')][] = $id;
        }
        ksort($gruppen);
        $out = [];
        for ($i = 0; count($out) < count($ids); $i++) {
            foreach ($gruppen as $liste) {
                if (isset($liste[$i])) {
                    $out[] = $liste[$i];
                }
            }
        }

        return $out;
    }

    /**
     * Lebende, gepflegte Lebensmittel-GPs (Non-Food/WG 16 hat weder Geschmack noch Anker, {@see GpAromaService::anreicherbar}). Mit `$scope`:
     * nur, was dieses Team besitzt (team-eigen; das Master-Team zusätzlich die globalen, deren Pflegbarkeit {@see GpAromaService::pflegbar}
     * danach entscheidet).
     */
    public function basis(?Team $scope = null): \Illuminate\Database\Query\Builder
    {
        return DB::table('foodalchemist_gps AS g')->whereNull('g.deleted_at')->whereNull('g.merged_into_id')
            ->when($scope !== null, fn ($q) => $q->where(fn ($w) => $w->where('g.team_id', $scope->id)
                ->when(\Platform\FoodAlchemist\Support\MasterTeam::istMaster($scope), fn ($m) => $m->orWhereNull('g.team_id'))))
            ->where('g.is_platzhalter', false)->whereIn('g.status', ['approved', 'tentative'])
            ->where(fn ($w) => $w->whereNull('g.commodity_group_code')->orWhere('g.commodity_group_code', '!=', \Platform\FoodAlchemist\Support\NonFoodRegeln::WG_CODE))
            ->orderBy('g.id');
    }

    /** @return array{eur: ?float, tin: float, tout: float, basis: string} eur = null: keine Preisdaten */
    private function kosten(string $feature, int $n): array
    {
        if ($n === 0) {
            return ['eur' => 0.0, 'tin' => 0.0, 'tout' => 0.0, 'basis' => "{$feature}: keine Calls"];
        }
        $h = DB::table('foodalchemist_ai_call_log')->where('feature', $feature)->whereNull('error')->orderByDesc('id')->limit(200)
            ->selectRaw('COUNT(*) AS n, AVG(kosten_usd * COALESCE(usd_eur, 0.92)) AS eur, AVG(tokens_in) AS tin, AVG(tokens_out) AS tout')->first();
        $mitHistorie = $h !== null && (int) $h->n > 0 && $h->tin !== null;
        $tin = $mitHistorie ? (float) $h->tin : self::ANNAHME[$feature]['in'];
        $tout = $mitHistorie ? (float) $h->tout : self::ANNAHME[$feature]['out'];
        $herkunft = $mitHistorie ? "Ø aus {$h->n} Calls" : 'Annahme ohne Call-Historie';
        if ($mitHistorie && $h->eur !== null) {
            return ['eur' => $n * (float) $h->eur, 'tin' => $n * $tin, 'tout' => $n * $tout,
                'basis' => "{$feature}: Ø ".number_format((float) $h->eur, 4, ',', '.')." € je Call ({$herkunft})"];
        }
        // Ohne Kosten-Historie des Features: Token × Preis je Mio. aus dem jüngsten bepreisten Call (gleiches Modell vorausgesetzt).
        $preis = DB::table('foodalchemist_ai_call_log')->whereNotNull('preis_in_mio')->orderByDesc('id')->first(['preis_in_mio', 'preis_out_mio', 'usd_eur', 'model']);
        if ($preis === null) {
            return ['eur' => null, 'tin' => $n * $tin, 'tout' => $n * $tout, 'basis' => "{$feature}: ".round($tin).' in / '.round($tout)." out je Call ({$herkunft}), kein Preis bekannt"];
        }
        $eur = ($tin * (float) $preis->preis_in_mio + $tout * (float) $preis->preis_out_mio) / 1_000_000 * (float) ($preis->usd_eur ?: 0.92);

        return ['eur' => $n * $eur, 'tin' => $n * $tin, 'tout' => $n * $tout,
            'basis' => "{$feature}: ".round($tin).'/'.round($tout)." Token je Call ({$herkunft}) × Preis {$preis->model}"];
    }

    /**
     * Neu-Schätzen (Dominique 29.10.2026: Profile tragen noch den alten Messwert). GPs über dieselbe Bulk-Strecke wie die Lücken (Vorschlag →
     * Übernehmen, auf dem externen Weg über KI-Aufträge), Rezept-Profile über {@see SensorikService::bewerteRezept()} (nur Plattform-KI).
     *
     * @param  list<int>  $gpIds
     * @param  list<int>  $rezeptIds
     */
    private function neuSchaetzen(BulkEnrichService $bulk, Team $team, array $gpIds, array $rezeptIds, int $limit, bool $extern, string $protokoll, callable $ausgabe): int
    {
        $n = 0;
        $gpIds = array_values(array_filter($this->streueNachWg($gpIds), fn ($id) => ($gp = FoodAlchemistGp::find($id)) !== null && app(GpAromaService::class)->anreicherbar($gp) && $gp->isOwnedBy($team)));
        foreach ($limit > 0 ? array_slice($gpIds, 0, $limit) : $gpIds as $gpId) {
            try {
                $r = $extern
                    ? app(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class)->within(fn () => $bulk->nachziehenGp($team, [$gpId], ['sensorik'], 'backfill-neu', true))
                    : $bulk->nachziehenGp($team, [$gpId], ['sensorik'], 'backfill-neu', true);
            } catch (\Throwable $e) {
                $r = ['fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
            }
            File::append($protokoll, json_encode(['gp_id' => $gpId, 'schritte' => ['sensorik-neu'], 'zeit' => now()->toIso8601String()] + $r, JSON_UNESCAPED_UNICODE) . "\n");
            $n++;
        }
        if ($rezeptIds !== [] && $extern) {
            $ausgabe('warn', 'Rezept-Profile werden nur mit --anbieter=plattform neu geschätzt.');
            $rezeptIds = [];
        }
        $sensorik = app(SensorikService::class);
        foreach ($limit > 0 ? array_slice($rezeptIds, 0, $limit) : $rezeptIds as $rid) {
            try {
                $r = $sensorik->bewerteRezept($rid, true);
            } catch (\Throwable $e) {
                $r = ['status' => 'fehler', 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
            }
            File::append($protokoll, json_encode(['recipe_id' => $rid, 'schritte' => ['rezept-sensorik-neu'], 'zeit' => now()->toIso8601String()] + $r, JSON_UNESCAPED_UNICODE) . "\n");
            $n++;
        }

        return $n;
    }
}
