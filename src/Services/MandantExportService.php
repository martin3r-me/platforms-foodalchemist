<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\MandantExportJob;
use Platform\FoodAlchemist\Models\FoodAlchemistMandantExport;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §13 C3 — Datenexport eines Mandanten: ALLE eigenen Inhalte des Mandanten und seiner Unter-Teams als ZIP
 * (eine JSON-Lines-Datei je Tabelle, dazu `manifest.json` mit Zählung und Prüfsummen und `LIESMICH.txt`).
 *
 * Was exportiert wird: jede Tabelle `foodalchemist_*` mit Spalte `team_id` (nur Zeilen der Mandanten-Teams, nie Stamm- oder
 * fremde Zeilen, keine weich gelöschten) plus die Kind-Tabellen ohne eigene `team_id` (über ihren Eltern-Datensatz, {@see KINDER}).
 * Ausgenommen sind Verwaltungs- und Technik-Tabellen ({@see AUSGENOMMEN}). NICHT enthalten: hochgeladene Dateien (Fotos, Logos) —
 * die liegen nicht in der Datenbank; das Manifest nennt die Verweise darauf.
 *
 * Rechte (§13 G): AUSLÖSEN darf der Plattform-Admin (er sieht nur Zählung/Status) oder Inhaber/Admin des Mandanten. LADEN darf
 * ausschließlich, wer WIRKLICH Inhaber/Admin im Mandanten-Team ist (Mitgliedschaft, nicht die Plattform-Admin-Abkürzung von FaRechte)
 * sowie Inhaber/Admin des Master-Teams, aber nur bei KURATIERTEN Mandanten. Ein reiner Plattform-Admin lädt nie Inhalte.
 */
class MandantExportService
{
    /** Tabellen mit `team_id`, die nicht in den Export gehören (Verwaltung, Protokoll, Technik, Caches). */
    public const AUSGENOMMEN = [
        'foodalchemist_mandanten', 'foodalchemist_mandant_beenden_laeufe', 'foodalchemist_mandant_exporte',
        'foodalchemist_team_bereiche', 'foodalchemist_user_bereich_sperren', 'foodalchemist_team_stamm_optins', 'foodalchemist_team_stamm_lieferanten',
        'foodalchemist_team_pakete', 'foodalchemist_paket_kopien',   // Spec 82 §13 I: Buchung + Zuordnung Quelle→Kopie (Verwaltung)
        'foodalchemist_plattform_einstellungen', 'foodalchemist_bearbeitungssperren',
    ];

    /** Präfixe technischer Tabellen (Embeddings, Aufruf-Protokolle, Jobs-Läufe) — kein Inhalt des Mandanten. */
    public const AUSGENOMMEN_PRAEFIX = ['foodalchemist_embedding', 'foodalchemist_ai_call', 'foodalchemist_job_', 'foodalchemist_signal'];

    /**
     * Kind-Tabellen ohne eigene `team_id`: tabelle => [Eltern-Tabelle, Spalte im Kind, Spalte im Eltern (meist id)].
     * Zeilen kommen mit, wenn der Eltern-Datensatz zu den Mandanten-Teams gehört.
     */
    public const KINDER = [
        'foodalchemist_canvas_entries' => ['foodalchemist_canvases', 'canvas_id', 'id'],
        'foodalchemist_recipe_equipment' => ['foodalchemist_recipes', 'recipe_id', 'id'],
        'foodalchemist_recipe_profile' => ['foodalchemist_recipes', 'recipe_id', 'id'],
        'foodalchemist_recipe_textures' => ['foodalchemist_recipes', 'recipe_id', 'id'],
        'foodalchemist_recipe_taste_vectors' => ['foodalchemist_recipes', 'recipe_id', 'id'],
        'foodalchemist_recipe_step_photo_links' => ['foodalchemist_recipes', 'recipe_id', 'id'],
        'foodalchemist_chapter_target_groups' => ['foodalchemist_foodbook_chapters', 'chapter_id', 'id'],
        'foodalchemist_foodbook_service_moments' => ['foodalchemist_foodbooks', 'foodbook_id', 'id'],
        'foodalchemist_foodbook_target_groups' => ['foodalchemist_foodbooks', 'foodbook_id', 'id'],
        'foodalchemist_concept_service_moments' => ['foodalchemist_concepts', 'concept_id', 'id'],
        'foodalchemist_format_seasons' => ['foodalchemist_formats', 'format_id', 'id'],
        'foodalchemist_format_service_moments' => ['foodalchemist_formats', 'format_id', 'id'],
        'foodalchemist_format_target_groups' => ['foodalchemist_formats', 'format_id', 'id'],
        'foodalchemist_gp_forms' => ['foodalchemist_gps', 'gp_id', 'id'],
        'foodalchemist_gp_textures' => ['foodalchemist_gps', 'gp_id', 'id'],
        'foodalchemist_gp_taste_vectors' => ['foodalchemist_gps', 'gp_id', 'id'],
        'foodalchemist_sammlung_objekte' => ['foodalchemist_sammlungen', 'sammlung_id', 'id'],
    ];

    public function __construct(private readonly FaRechte $rechte, private readonly StandortService $standorte)
    {
    }

    // ── Rechte ────────────────────────────────────────────────────────────────────────────────────

    /** Wirklich Inhaber/Admin im Team (Mitgliedschaft in `team_user`) — ohne die Plattform-Admin-Abkürzung. */
    private function istInhaberVon(?User $user, Team $team): bool
    {
        return $user !== null && ! $user->isAiUser()
            && DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)->whereIn('role', ['owner', 'admin'])->exists();
    }

    /** Auslösen: Plattform-Admin ODER Inhaber/Admin des Mandanten. */
    public function darfAusloesen(?User $user, Team $mandant): bool
    {
        return $user !== null && ! $user->isAiUser() && ($this->rechte->istPlattformAdmin($user) || $this->istInhaberVon($user, $mandant));
    }

    /** Laden: nur wer WIRKLICH Inhaber/Admin im Mandanten ist, oder Master-Inhaber/-Admin bei kuratierten Mandanten. */
    public function darfLaden(?User $user, Team $mandant): bool
    {
        if ($this->istInhaberVon($user, $mandant)) {
            return true;
        }
        $masterId = MasterTeam::id();
        $master = $masterId !== null ? Team::find($masterId) : null;

        return $master !== null && $this->istInhaberVon($user, $master) && app(MandantenService::class)->modusVon($mandant) === 'kuratiert';
    }

    private function pruefeMandant(Team $mandant): void
    {
        if (MasterTeam::istMaster($mandant)) {
            throw new \RuntimeException('Das Master-Team hat keinen Mandanten-Export.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Exportiert wird der Mandant (das Haupt-Team des Kunden), nicht ein Unter-Team.');
        }
    }

    // ── Auslösen / Auskunft ───────────────────────────────────────────────────────────────────────

    /** Export anstoßen (ein laufender wird wiederverwendet). Das Bauen übernimmt der Job. */
    public function starte(Team $mandant, ?User $user): FoodAlchemistMandantExport
    {
        // Spec 84 B3 C: Export des Mandanten; der Job trägt den Mandanten, das Recht prüft der Rumpf
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm($mandant, fn () => $this->starteImTeam($mandant, $user));
    }

    private function starteImTeam(Team $mandant, ?User $user): FoodAlchemistMandantExport
    {
        $this->pruefeMandant($mandant);
        if (! $this->darfAusloesen($user, $mandant)) {
            throw new FaRechtFehltException(FaRolle::Admin, null, 'Datenexport auslösen (Plattform-Admin oder Inhaber des Mandanten)');
        }
        $laeuft = FoodAlchemistMandantExport::where('team_id', $mandant->id)->where('status', FoodAlchemistMandantExport::STATUS_LAEUFT)
            ->where('created_at', '>', now()->subHours(2))->latest('id')->first();   // ein hängengebliebener Lauf blockiert nicht ewig
        if ($laeuft !== null) {
            return $laeuft;
        }
        $export = FoodAlchemistMandantExport::create(['team_id' => $mandant->id, 'status' => FoodAlchemistMandantExport::STATUS_LAEUFT, 'ausgeloest_von' => $user->id]);
        MandantExportJob::dispatch($export);

        return $export->refresh();
    }

    public function neuester(Team $mandant): ?FoodAlchemistMandantExport
    {
        return FoodAlchemistMandantExport::where('team_id', $mandant->id)->latest('id')->first();
    }

    /** Liegt ein fertiger Export vor, dessen Datei noch da ist? */
    public function vorhanden(Team $mandant): bool
    {
        $e = FoodAlchemistMandantExport::where('team_id', $mandant->id)->where('status', FoodAlchemistMandantExport::STATUS_FERTIG)->latest('id')->first();

        return $e !== null && $e->pfad !== null && Storage::disk(self::disk())->exists($e->pfad);
    }

    /** Verwaltungsdaten eines Exports (keine Inhalte, kein Pfad). @return array<string,mixed> */
    public function darstellung(FoodAlchemistMandantExport $e, ?User $betrachter = null, ?Team $mandant = null): array
    {
        return [
            'id' => (int) $e->id, 'team_id' => (int) $e->team_id, 'status' => $e->status, 'groesse' => $e->groesse, 'pruefsumme' => $e->pruefsumme,
            'zeilen_gesamt' => array_sum($e->manifest ?? []), 'manifest' => $e->manifest ?? [], 'fehler' => $e->fehler,
            'fertig_am' => $e->fertig_at?->toDateTimeString(), 'zuletzt_geladen_am' => $e->zuletzt_geladen_at?->toDateTimeString(),
            'ladbar' => $e->status === FoodAlchemistMandantExport::STATUS_FERTIG && $betrachter !== null && $mandant !== null && $this->darfLaden($betrachter, $mandant),
        ];
    }

    public static function disk(): string
    {
        return (string) config('foodalchemist.mandant_export.disk', 'local');
    }

    /** Download: prüft das Recht und stempelt „zuletzt geladen“. Gibt den Pfad im Storage zurück. */
    public function fuerDownload(FoodAlchemistMandantExport $e, ?User $user): string
    {
        $mandant = Team::findOrFail($e->team_id);
        if (! $this->darfLaden($user, $mandant)) {
            throw new FaRechtFehltException(FaRolle::Admin, null, 'Datenexport laden (nur Inhaber/Admin des Mandanten, Master bei kuratierten)');
        }
        if ($e->status !== FoodAlchemistMandantExport::STATUS_FERTIG || $e->pfad === null || ! Storage::disk(self::disk())->exists($e->pfad)) {
            throw new \RuntimeException('Der Export ist nicht (mehr) verfügbar.');
        }
        $e->update(['zuletzt_geladen_at' => now(), 'zuletzt_geladen_von' => $user->id]);

        return $e->pfad;
    }

    // ── Bauen ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Das ZIP schreiben (vom Job). Streamt je Tabelle in eine Temp-Datei, danach ins private Storage.
     */
    public function baue(FoodAlchemistMandantExport $export): void
    {
        // Spec 84 B3 C: Export liest in der Sicht des exportierten Mandanten
        \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm((int) $export->team_id, fn () => $this->baueImTeam($export));
    }

    private function baueImTeam(FoodAlchemistMandantExport $export): void
    {
        $mandant = Team::findOrFail($export->team_id);
        $teamIds = array_merge([(int) $mandant->id], $this->standorte->unterTeamIds($mandant));
        $teamNamen = Team::whereIn('id', $teamIds)->pluck('name', 'id')->all();

        $tmp = tempnam(sys_get_temp_dir(), 'faexp');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('ZIP-Datei konnte nicht angelegt werden.');
        }
        $manifest = [];
        $dateien = [];
        $temps = [];
        try {
            foreach ($this->tabellen() as $tabelle => $spezifikation) {
                $zeilenDatei = tempnam(sys_get_temp_dir(), 'fat');
                $temps[] = $zeilenDatei;
                $n = $this->schreibeTabelle($tabelle, $spezifikation, $teamIds, $zeilenDatei);
                if ($n === 0) {
                    continue;
                }
                $manifest[$tabelle] = $n;
                $dateien[$tabelle.'.jsonl'] = hash_file('sha256', $zeilenDatei);
                $zip->addFile($zeilenDatei, 'daten/'.$tabelle.'.jsonl');
            }
            $inhalt = json_encode([
                'format' => 'foodalchemist-mandant-export', 'version' => 1, 'erzeugt_am' => now()->toIso8601String(),
                'mandant' => ['id' => (int) $mandant->id, 'name' => (string) $mandant->name], 'teams' => $teamNamen,
                'tabellen' => $manifest, 'zeilen_gesamt' => array_sum($manifest), 'pruefsummen_sha256' => $dateien,
                'hinweis' => 'Hochgeladene Dateien (Fotos, Logos) sind nicht enthalten; sie liegen nicht in der Datenbank.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $zip->addFromString('manifest.json', $inhalt);
            $zip->addFromString('LIESMICH.txt', "FoodAlchemist — Datenexport des Mandanten „{$mandant->name}“\n\n"
                ."daten/<tabelle>.jsonl   eine JSON-Zeile je Datensatz (alle Spalten der Datenbank)\n"
                ."manifest.json          Zählung je Tabelle und SHA-256-Prüfsummen der Dateien\n\n"
                ."Enthalten: alle eigenen Inhalte des Mandanten und seiner Unter-Teams (Rezepte, Gerichte, Konzepte, Foodbooks, Angebote, eigene Stammdaten, DNA, Einstellungen).\n"
                ."Nicht enthalten: Stamm-Daten der Plattform, hochgeladene Dateien (Fotos, Logos), Verwaltungs- und Protokolldaten.\n");
            $zip->close();

            $pfad = 'foodalchemist/mandant-exporte/'.$mandant->id.'/export-'.$export->id.'-'.now()->format('Ymd-His').'.zip';
            $strom = fopen($tmp, 'r');
            Storage::disk(self::disk())->put($pfad, $strom);
            if (is_resource($strom)) {
                fclose($strom);
            }
            $export->update([
                'status' => FoodAlchemistMandantExport::STATUS_FERTIG, 'pfad' => $pfad, 'groesse' => filesize($tmp),
                'pruefsumme' => hash_file('sha256', $tmp), 'manifest' => $manifest, 'fertig_at' => now(), 'fehler' => null,
            ]);
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
            @unlink($tmp);
        }
    }

    /**
     * Welche Tabellen mit welcher Auswahl: tabelle => ['art' => 'team'|'kind', ...].
     *
     * @return array<string, array<string,mixed>>
     */
    public function tabellen(): array
    {
        $liste = [];
        foreach (Schema::getTableListing() as $name) {
            $tabelle = (string) preg_replace('/^.*\./', '', $name);   // evtl. Schema-Präfix
            if (! str_starts_with($tabelle, 'foodalchemist_') || in_array($tabelle, self::AUSGENOMMEN, true)) {
                continue;
            }
            foreach (self::AUSGENOMMEN_PRAEFIX as $p) {
                if (str_starts_with($tabelle, $p)) {
                    continue 2;
                }
            }
            if (isset(self::KINDER[$tabelle])) {
                if (Schema::hasTable(self::KINDER[$tabelle][0])) {
                    $liste[$tabelle] = ['art' => 'kind', 'eltern' => self::KINDER[$tabelle]];
                }
            } elseif (Schema::hasColumn($tabelle, 'team_id')) {
                $liste[$tabelle] = ['art' => 'team'];
            }
        }
        ksort($liste);

        return $liste;
    }

    /** @param array<string,mixed> $spez  @param list<int> $teamIds */
    private function schreibeTabelle(string $tabelle, array $spez, array $teamIds, string $datei): int
    {
        $q = DB::table($tabelle);
        if ($spez['art'] === 'team') {
            $q->whereIn('team_id', $teamIds);
        } else {
            [$eltern, $kindSpalte, $elternSpalte] = $spez['eltern'];
            $elternHatTeam = Schema::hasColumn($eltern, 'team_id');
            if (! $elternHatTeam) {
                return 0;
            }
            $q->whereIn($kindSpalte, DB::table($eltern)->whereIn('team_id', $teamIds)->select($elternSpalte));
        }
        if (Schema::hasColumn($tabelle, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        $h = fopen($datei, 'w');
        $n = 0;
        foreach ($q->cursor() as $zeile) {
            fwrite($h, json_encode($zeile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            $n++;
        }
        fclose($h);

        return $n;
    }
}
