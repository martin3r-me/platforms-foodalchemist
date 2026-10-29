<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Geschmacksprofil + Kern-Anker eines Grundprodukts nachziehen (Dominique/Kurator 27.10.2026).
 *
 * Bis hierhin schrieb niemand `foodalchemist_gp_taste_vectors` (Einmal-Import 17.06., auf io seit dem Stamm-Ersatz 26.10. leer),
 * und der Kern-Anker kam automatisch nur beim Rezept-Mint. Reihenfolge, erst kostenlos, dann KI:
 *
 *  ANKER   1. Geschwister: alle lebenden GPs derselben Hauptzutat (`main_ingredient_slug`) mit Anker sind sich über die Anker-Menge
 *             einig → übernehmen (Quelle `geschwister`). Anker hängen an der Hauptzutat, nicht an Marke/Zustand/Zuschnitt.
 *          2. Sonst KI aus dem Anker-Vokabular (`BulkEnrichService::gpAnkerVorschlag`, derselbe Weg wie der Knopf).
 *
 *  PROFIL  Wahrnehmung auf allen 7 Achsen (Dominique 29.10.2026): ein Profil sagt, wie das Produkt SCHMECKT, nicht wie viel drin ist.
 *          Zucker, Salz und Fett aus den LA-Nährwerten stehen als Fakt im KI-Kontext (`naehrwerte`), die Kurve nur als Hinweis
 *          (`konzentration_ohne_maskierung`). Nach der Antwort fängt {@see SensorikService::begrenze()} klare Ausreißer ab.
 *          1. Geschwister: gleiche Hauptzutat UND gleicher Zustand UND gleiche Verarbeitung, Profil aus KI/Hand (nicht die alten
 *             Import-Vektoren), alle einig (Spannweite je Achse ≤ GESCHWISTER_SPANNE) → Mittelwert (Quelle `geschwister`);
 *             die Grenzen prüfen mit den eigenen Nährwerten.
 *          2. Sonst KI `gp.sensorik` mit Anker, „Geschmacksbild in Worten" aus dem Zutaten-Dossier (ungeprüft) und Nachbar-Profilen.
 *          Nichts wird beim Lesen überlagert. `source='manual'` wird nie überschrieben.
 */
class GpAromaService
{
    /** Achsen, die kein Etikett hergibt (Nachbar-Hinweise, Vergleich). */
    public const NIE_GEMESSEN = ['sauer', 'bitter', 'umami', 'scharf'];

    /** Geschwister gelten als einig, wenn je Achse max − min höchstens so groß ist. */
    public const GESCHWISTER_SPANNE = 0.15;

    /** Profil-Quellen, die als Geschwister-Vorlage taugen (die alten Import-Vektoren gemini/rule nicht). */
    public const VERLAESSLICHE_QUELLEN = ['ai', 'manual', 'geschwister'];

    /** Deckel für das „Geschmacksbild" aus dem Dossier. */
    private const DOSSIER_MAX = 1200;

    public function __construct(private SensorikService $sensorik) {}

    // ── Lücken ─────────────────────────────────────────────────────────────────────────────────────

    public function hatAnker(int $gpId): bool
    {
        return DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->whereNull('deleted_at')->exists();
    }

    /** Fehlt ein brauchbares Profil? Kein Eintrag oder lauter Nullen (Import-Artefakt). */
    public function profilFehlt(int $gpId): bool
    {
        $t = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gpId)->first();
        if ($t === null) {
            return true;
        }
        foreach (SensorikService::DIMS as $d) {
            if ((float) $t->{$d} > 0) {
                return false;
            }
        }

        return $t->source !== 'manual';
    }

    /** Lebendes, gepflegtes GP (kein Platzhalter, nicht gemergt/gelöscht)? Nur solche werden angereichert. */
    public function anreicherbar(?FoodAlchemistGp $gp): bool
    {
        return $gp !== null && $gp->deleted_at === null && $gp->merged_into_id === null && ! $gp->is_platzhalter;
    }

    /** Das Team, das die Anker dieses GP pflegen darf (Besitzer; globales GP = Master). */
    public function besitzerTeam(FoodAlchemistGp $gp): ?Team
    {
        $id = $gp->team_id ?? MasterTeam::id();

        return $id !== null ? Team::find($id) : null;
    }

    /**
     * Darf der Besitzer dieses GP überhaupt pflegen? Globale GPs außerhalb des Stamm-Pakets (stamm_class ≠ stamm_paket) sind nach
     * Spec 82 für ALLE nur lesbar, auch für den Master ({@see \Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy::isOwnedBy()}).
     * Fund io 27.10.2026: 43 solche GPs galten bei jedem Backfill wieder als „Geschwister einig" und scheiterten dann am Guard
     * von setGpAnker. Profil wie Anker: wer den Anker nicht pflegen darf, bekommt auch kein Profil geschrieben.
     */
    public function pflegbar(FoodAlchemistGp $gp): bool
    {
        $team = $this->besitzerTeam($gp);

        return $team !== null && app(PairingService::class)->darfAnkerPflegen($gp, $team);
    }

    // ── Geschwister ────────────────────────────────────────────────────────────────────────────────

    /**
     * Anker-Menge der Geschwister, wenn alle einig sind.
     *
     * @return array{status: 'einig'|'uneinig'|'keine', anker: list<array{anchor_id: int, role: string}>, geschwister: int}
     */
    public function geschwisterAnker(FoodAlchemistGp $gp): array
    {
        $slug = trim((string) $gp->main_ingredient_slug);
        if ($slug === '') {
            return ['status' => 'keine', 'anker' => [], 'geschwister' => 0];
        }
        $rows = DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 'm.gp_id')
            ->where('g.main_ingredient_slug', $slug)->where('g.id', '!=', $gp->id)
            ->whereNull('g.deleted_at')->whereNull('g.merged_into_id')->where('g.is_platzhalter', false)
            ->whereIn('g.id', $this->sichtbareIds($gp))
            ->whereNull('m.deleted_at')
            ->get(['m.gp_id', 'm.anchor_id', 'm.role']);
        if ($rows->isEmpty()) {
            return ['status' => 'keine', 'anker' => [], 'geschwister' => 0];
        }
        $mengen = [];
        foreach ($rows->groupBy('gp_id') as $gpRows) {
            $menge = $gpRows->map(fn ($r) => (int) $r->anchor_id . ':' . $r->role)->sort()->values()->implode('|');
            $mengen[$menge] = $gpRows;
        }
        if (count($mengen) !== 1) {
            return ['status' => 'uneinig', 'anker' => [], 'geschwister' => $rows->pluck('gp_id')->unique()->count()];
        }

        return ['status' => 'einig', 'geschwister' => $rows->pluck('gp_id')->unique()->count(),
            'anker' => array_values(reset($mengen)->map(fn ($r) => ['anchor_id' => (int) $r->anchor_id, 'role' => (string) $r->role])->all())];
    }

    /** Übernimmt einige Geschwister-Anker. Nur ohne vorhandenen Anker. Rückgabe: Anzahl gesetzter Anker. */
    public function ankerVonGeschwistern(FoodAlchemistGp $gp): int
    {
        if (! $this->anreicherbar($gp) || $this->hatAnker($gp->id) || ! $this->pflegbar($gp)) {
            return 0;
        }
        $g = $this->geschwisterAnker($gp);
        $team = $this->besitzerTeam($gp);
        if ($g['status'] !== 'einig' || $team === null) {
            return 0;
        }
        $pairing = app(PairingService::class);
        $grund = "Von {$g['geschwister']} Geschwister-GP(s) derselben Hauptzutat ({$gp->main_ingredient_slug}) übernommen";
        foreach ($g['anker'] as $a) {
            $pairing->setGpAnker($team, $gp->id, $a['anchor_id'], $a['role'], 'geschwister', null, $grund);
        }

        return count($g['anker']);
    }

    /**
     * Profil der Geschwister (gleiche Hauptzutat, gleicher Zustand, gleiche Verarbeitung; nur verlässliche Quellen), wenn einig.
     *
     * @return array{status: 'einig'|'uneinig'|'keine', werte: array<string,float>, geschwister: int}
     */
    public function geschwisterProfil(FoodAlchemistGp $gp): array
    {
        $slug = trim((string) $gp->main_ingredient_slug);
        if ($slug === '') {
            return ['status' => 'keine', 'werte' => [], 'geschwister' => 0];
        }
        $q = DB::table('foodalchemist_gp_taste_vectors AS t')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 't.gp_id')
            ->where('g.main_ingredient_slug', $slug)->where('g.id', '!=', $gp->id)
            ->whereNull('g.deleted_at')->whereNull('g.merged_into_id')
            ->whereIn('g.id', $this->sichtbareIds($gp))
            ->whereIn('t.source', self::VERLAESSLICHE_QUELLEN);
        foreach (['condition', 'processing'] as $feld) {
            $wert = trim((string) $gp->{$feld});
            $wert === '' ? $q->where(fn ($w) => $w->whereNull("g.{$feld}")->orWhere("g.{$feld}", '')) : $q->where("g.{$feld}", $wert);
        }
        $rows = $q->get(array_map(fn ($d) => "t.{$d}", SensorikService::DIMS));
        if ($rows->isEmpty()) {
            return ['status' => 'keine', 'werte' => [], 'geschwister' => 0];
        }
        $werte = [];
        foreach (SensorikService::DIMS as $d) {
            $spalte = $rows->pluck($d)->map(fn ($v) => (float) $v);
            if ($spalte->max() - $spalte->min() > self::GESCHWISTER_SPANNE + 1e-9) {
                return ['status' => 'uneinig', 'werte' => [], 'geschwister' => $rows->count()];
            }
            $werte[$d] = round($spalte->avg(), 2);
        }

        return ['status' => 'einig', 'werte' => $werte, 'geschwister' => $rows->count()];
    }

    /** IDs der GPs, die das Besitzer-Team sieht (Geschwister nur aus dieser Sicht, nie aus fremden Mandanten). */
    private function sichtbareIds(FoodAlchemistGp $gp): \Illuminate\Database\Query\Builder
    {
        $team = $this->besitzerTeam($gp);

        return $team !== null
            ? FoodAlchemistGp::visibleToTeam($team)->select('id')->toBase()
            : DB::table('foodalchemist_gps')->whereNull('team_id')->select('id');
    }

    // ── KI-Schätzung ───────────────────────────────────────────────────────────────────────────────

    /**
     * Schätzt die Wahrnehmung auf allen 7 Achsen (+ Texturen, wenn das GP keine hat) per KI und wendet danach die Grenzen an.
     * Schreibt nichts.
     *
     * @return array{werte: array<string,float>, geschaetzt: list<string>, naehrwerte: array<string,float>, konzentration: array<string,float>, eingriffe: list<string>, texturen: list<array{slug: string, intensity: float}>, confidence: float, reasoning: ?string, call_log_id: ?int}|null
     */
    public function schaetze(FoodAlchemistGp $gp): ?array
    {
        $erdung = $this->sensorik->erdungBulk([$gp->id])[$gp->id] ?? [];
        $naehrwerte = array_map(fn ($m) => round((float) $m['gehalt'], 2), $erdung);
        $konzentration = array_map(fn ($m) => round((float) $m['wert'], 2), $erdung);
        $mitTextur = ! DB::table('foodalchemist_gp_textures')->where('gp_id', $gp->id)->exists();

        $p = app(AiGatewayService::class)->propose('gp.sensorik', $this->kontext($gp, $naehrwerte, $konzentration, $mitTextur),
            ['target_table' => 'foodalchemist_gp_taste_vectors', 'target_id' => $gp->id]);
        $roh = $p->werte['geschmack'] ?? null;
        if (! is_array($roh)) {
            return null;                                              // Fake-Provider / kaputte Antwort → nichts
        }
        $werte = [];
        foreach (SensorikService::DIMS as $d) {
            if (! is_numeric($roh[$d] ?? null)) {
                return null;                                          // unvollständig = nicht verwertbar, lieber kein Profil
            }
            $werte[$d] = max(0.0, min(1.0, round((float) $roh[$d], 2)));
        }
        $begrenzt = $this->sensorik->begrenze($werte, $naehrwerte);
        $werte = $begrenzt['werte'];
        $vocab = DB::table('foodalchemist_vocab_textures')->pluck('id', 'slug');
        $texturen = [];
        foreach ($mitTextur && is_array($p->werte['texturen'] ?? null) ? $p->werte['texturen'] : [] as $t) {
            $slug = is_array($t) ? ($t['slug'] ?? null) : null;
            if (is_string($slug) && $vocab->has($slug) && count($texturen) < 3) {
                $texturen[] = ['slug' => $slug, 'intensity' => max(0.0, min(1.0, round((float) ($t['intensitaet'] ?? $t['intensity'] ?? 1), 2)))];
            }
        }
        $begruendung = trim((string) $p->reasoning . ' ' . implode(' ', $begrenzt['eingriffe']));

        return ['werte' => $werte, 'geschaetzt' => SensorikService::DIMS, 'naehrwerte' => $naehrwerte, 'konzentration' => $konzentration,
            'eingriffe' => $begrenzt['eingriffe'], 'texturen' => $texturen,
            'confidence' => max(0.0, min(1.0, (float) $p->confidence)), 'reasoning' => $begruendung !== '' ? $begruendung : null, 'call_log_id' => $p->callLogId];
    }

    /**
     * @param  array<string, float>  $naehrwerte  g/100 g je Achse (suess = Zucker, salzig = Salz, fettig = Fett)
     * @param  array<string, float>  $konzentration  Kurvenwert ohne Maskierung, nur als Hinweis
     * @return array<string, mixed>
     */
    private function kontext(FoodAlchemistGp $gp, array $naehrwerte, array $konzentration, bool $mitTextur): array
    {
        $anker = app(PairingService::class)->gpAnkerAlle($gp->id);
        $kern = $anker->firstWhere('role', 'kern') ?? $anker->first();
        $nachbarn = DB::table('foodalchemist_gp_taste_vectors AS t')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 't.gp_id')
            ->where('g.main_ingredient_slug', (string) $gp->main_ingredient_slug)->where('g.id', '!=', $gp->id)
            ->whereNull('g.deleted_at')->whereNull('g.merged_into_id')->whereIn('g.id', $this->sichtbareIds($gp))
            ->whereIn('t.source', self::VERLAESSLICHE_QUELLEN)
            ->orderBy('g.name')->limit(3)->get(array_merge(['g.name'], array_map(fn ($d) => "t.{$d}", SensorikService::DIMS)));

        // Nährwerte mit sprechenden Schlüsseln: der Fakt, an dem die Einstufung hängt.
        $fakten = [];
        foreach ($naehrwerte as $dim => $g) {
            $fakten[match ($dim) { 'suess' => 'zucker_g_je_100g', 'salzig' => 'salz_g_je_100g', default => 'fett_g_je_100g' }] = $g;
        }

        return [
            'name' => $gp->name,
            'zustand' => $gp->condition,
            'verarbeitung' => $gp->processing,
            'form' => $gp->form,
            'warengruppe' => $gp->commodity_group?->name,
            'kern_anker' => $kern?->display_de,
            'geschmacksbild_aus_dossier' => $kern !== null ? $this->geschmacksbild((int) $kern->id) : null,
            'naehrwerte' => $fakten === [] ? null : $fakten,
            'konzentration_ohne_maskierung' => $konzentration === [] ? null : $konzentration,
            'zu_schaetzen' => SensorikService::DIMS,
            'nachbarn' => $nachbarn->isEmpty() ? null : $nachbarn->map(fn ($n) => (array) $n)->all(),
            'texturen_schaetzen' => $mitTextur,
        ];
    }

    /**
     * „Geschmacksbild in Worten" aus dem Steckbrief-Dossier des Kern-Ankers (Zutaten-Dossiers sind KI-Entwurf, ungeprüft —
     * darum so im Kontext ausgewiesen). Fehlt der Abschnitt, nichts: lieber kein Dossier als ein beliebiger Ausschnitt.
     */
    public function geschmacksbild(int $ankerId): ?string
    {
        $docs = DB::table('foodalchemist_knowledge_documents')->where('anchor_id', $ankerId)->where('category', 'zutat')
            ->where('active', true)->whereNull('deleted_at')->where('slug', 'like', '%--steckbrief%')->orderBy('slug')->pluck('content_md');
        foreach ($docs as $md) {
            if (preg_match('/^##\s*Geschmacksbild[^\n]*\n(.*?)(?=^##\s|\z)/ms', (string) $md, $m) === 1 && trim($m[1]) !== '') {
                return '(aus Dossier, ungeprüft) ' . mb_strimwidth(trim($m[1]), 0, self::DOSSIER_MAX, '…');
            }
        }

        return null;
    }

    // ── Schreiben ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Speichert ein Profil: die 7 Wahrnehmungswerte, nach den Nährwert-Grenzen (mit den eigenen Nährwerten des GP — auch beim
     * Geschwister-Weg). Es wird nichts mehr aus den Nährwerten eingespielt. Ein manuelles Profil bleibt immer; ein vorhandenes
     * anderes nur mit `$ueberschreiben` (Knopf nach Vorschau, Neu-Schätzen im Backfill).
     */
    public function speichereProfil(int $gpId, array $werte, string $quelle, ?float $conf, ?string $begr, array $texturen = [], bool $ueberschreiben = false): bool
    {
        $ist = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gpId)->first();
        if ($ist !== null && ($ist->source === 'manual' || (! $ueberschreiben && ! $this->profilFehlt($gpId)))) {
            return false;
        }
        $clamp = fn ($x) => max(0.0, min(1.0, round((float) $x, 2)));
        $roh = [];
        foreach (SensorikService::DIMS as $d) {
            $roh[$d] = $clamp($werte[$d] ?? 0);
        }
        $naehrwerte = array_map(fn ($m) => (float) $m['gehalt'], $this->sensorik->erdungBulk([$gpId])[$gpId] ?? []);
        $begrenzt = $this->sensorik->begrenze($roh, $naehrwerte);
        if ($begrenzt['eingriffe'] !== [] && ! str_contains((string) $begr, '[Grenze:')) {
            $begr = trim((string) $begr . ' ' . implode(' ', $begrenzt['eingriffe']));
        }
        $row = ['source' => $quelle, 'ai_confidence' => $conf, 'ai_reasoning' => $begr !== null ? mb_strimwidth($begr, 0, 2000) : null, 'updated_at' => now()];
        foreach (SensorikService::DIMS as $d) {
            $row[$d] = $begrenzt['werte'][$d];
        }
        DB::table('foodalchemist_gp_taste_vectors')->updateOrInsert(['gp_id' => $gpId], $row + ['created_at' => $ist->created_at ?? now()]);

        if ($texturen !== [] && ! DB::table('foodalchemist_gp_textures')->where('gp_id', $gpId)->exists()) {
            $vocab = DB::table('foodalchemist_vocab_textures')->pluck('id', 'slug');
            foreach ($texturen as $t) {
                if ($vocab->has($t['slug'])) {
                    DB::table('foodalchemist_gp_textures')->insert(['gp_id' => $gpId, 'texture_vocab_id' => $vocab[$t['slug']],
                        'intensity' => $t['intensity'], 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        return true;
    }

    /** Profil von einigen Geschwistern übernehmen (kostenlos). Nur, wenn das Profil fehlt. */
    public function profilVonGeschwistern(FoodAlchemistGp $gp): bool
    {
        if (! $this->anreicherbar($gp) || ! $this->profilFehlt($gp->id) || ! $this->pflegbar($gp)) {
            return false;
        }
        $g = $this->geschwisterProfil($gp);
        if ($g['status'] !== 'einig') {
            return false;
        }

        return $this->speichereProfil($gp->id, $g['werte'], 'geschwister', null,
            "Von {$g['geschwister']} Geschwister-GP(s) mit gleicher Hauptzutat, gleichem Zustand und gleicher Verarbeitung übernommen");
    }

    /**
     * Kostenloser Teil für frisch angelegte bzw. lückenhafte GPs (Anker + Profil von Geschwistern). Läuft auch ohne Nutzer/KI —
     * Importe, Paket, Marken-Split. Fail-soft.
     *
     * @param  list<int>  $gpIds
     * @return array{anker: int, profil: int}
     */
    public function geschwisterNachziehen(array $gpIds): array
    {
        $n = ['anker' => 0, 'profil' => 0];
        foreach (FoodAlchemistGp::whereIn('id', $gpIds)->get() as $gp) {
            try {
                $n['anker'] += $this->ankerVonGeschwistern($gp) > 0 ? 1 : 0;
                $n['profil'] += $this->profilVonGeschwistern($gp) ? 1 : 0;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Geschwister-Nachzug GP {$gp->id}: {$e->getMessage()}");
            }
        }

        return $n;
    }
}
