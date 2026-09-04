<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConformanceFinding;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Conformance\ConformanceAdapter;
use Platform\FoodAlchemist\Services\Conformance\GpConformanceAdapter;
use Platform\FoodAlchemist\Services\Conformance\LaConformanceAdapter;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Support\DossierText;

/**
 * Schicht 3 — der GENERISCHE Konformitäts-Critic. EIN Prompt (`conformance.check`),
 * EIN Service, ein kleiner Adapter je Artefakt-Typ. Prüft ein Artefakt §-genau
 * gegen die VOLLEN Regelwerk-Dossiers (bewusst ungekappt — hier zählt Vollständigkeit,
 * nicht Relevanz) und liefert Regelverstöße mit §-Referenz + Schweregrad.
 *
 * Slice 1: read-only. Persistiert nichts ausser dem Gateway-Audit. Die Ablage
 * (artefakt-agnostische Findings-Tabelle) und die Selbstheil-Runde (bei Verstoß
 * 1× autonom revidieren → nachprüfen → Rest als Hinweis) folgen in Slice 2/3.
 * User-Entscheid 2026-08-27: kein Pass pro Generator; kein Hardstop-Block —
 * Verstoß löst eine autonome Runde aus, danach nur prominenter Hinweis.
 */
class ConformanceService
{
    public const SCHWEREGRADE = ['hart', 'weich'];

    /**
     * @return array{gesamturteil: string, confidence: float, befunde: array<int, array<string, mixed>>}
     */
    public function pruefe(Team $team, string $typ, int $id): array
    {
        return $this->pruefeAdapter($team, $this->adapter($typ), $id);
    }

    /**
     * Selbstheil-Prüfung (auto nach Generierung + on-demand): prüfen → bei Verstoß
     * EINE autonome Revise-Runde → nachprüfen → Rest persistieren. KEIN Hardstop-Block
     * (User-Entscheid 2026-08-27) — was die Runde nicht heilt, bleibt als Hinweis.
     *
     * @return array{gesamturteil:string, confidence:float, befunde:array<int,array<string,mixed>>, geheilt:int, ablage:array<string,int>}
     */
    public function pruefeUndHeile(Team $team, string $typ, int $id, ?int $runId = null): array
    {
        $adapter = $this->adapter($typ);
        $vorher = $this->pruefeAdapter($team, $adapter, $id);
        $aktuell = $vorher;

        // Selbstheil-Runde nur, wenn der Adapter sie beherrscht (Rezept/VK). GP/LA haben
        // v1 keinen Freitext-Revise → kein sinnloser zweiter Prüf-Call; Verstöße gehen
        // direkt als Hinweis in die Ablage.
        if ($vorher['befunde'] !== [] && $adapter->unterstuetztHeilung()) {
            try {
                $adapter->revise($team, $id, $this->heilDirektive($vorher['befunde']), $vorher['befunde']);
            } catch (\Throwable $e) {
                // best-effort: schlägt die Runde fehl, bleibt es beim Erst-Befund (nur Hinweis)
            }
            $aktuell = $this->pruefeAdapter($team, $adapter, $id);
        }

        $ablage = $this->speichere($team, $adapter->artifactType(), $id, $aktuell['befunde'], $runId);

        return $aktuell + [
            'geheilt' => max(0, count($vorher['befunde']) - count($aktuell['befunde'])),
            'ablage' => $ablage,
        ];
    }

    /**
     * Offene Konformitäts-Hinweise eines Artefakts (Leitstelle / MCP-Anzeige) —
     * hart vor weich, dann nach Konfidenz. Reine Lese-Sicht, kein KI-Call.
     *
     * @return array<int, array<string, mixed>>
     */
    public function offeneFuer(Team $team, string $artifactType, int $artifactId): array
    {
        return FoodAlchemistConformanceFinding::query()
            ->where('team_id', $team->id)
            ->where('artifact_type', $artifactType)
            ->where('artifact_id', $artifactId)
            ->where('status', 'offen')
            ->orderByRaw("CASE schweregrad WHEN 'hart' THEN 0 ELSE 1 END")
            ->orderByDesc('confidence')
            ->get(['paragraph', 'schweregrad', 'feld', 'reason', 'vorschlag', 'confidence', 'seen_count'])
            ->toArray();
    }

    /**
     * Offene Hinweise für VIELE Artefakte in EINER Abfrage (Cockpit-Liste, kein N+1) —
     * je Artefakt hart vor weich, dann nach Konfidenz.
     *
     * @param  array<int, int>  $artifactIds
     * @return array<int, array<int, array<string, mixed>>>  artifact_id → Hinweise
     */
    public function offeneFuerViele(Team $team, string $artifactType, array $artifactIds): array
    {
        if ($artifactIds === []) {
            return [];
        }

        return FoodAlchemistConformanceFinding::query()
            ->where('team_id', $team->id)
            ->where('artifact_type', $artifactType)
            ->whereIn('artifact_id', $artifactIds)
            ->where('status', 'offen')
            ->orderByRaw("CASE schweregrad WHEN 'hart' THEN 0 ELSE 1 END")
            ->orderByDesc('confidence')
            ->get(['artifact_id', 'paragraph', 'schweregrad', 'feld', 'reason', 'vorschlag', 'confidence'])
            ->groupBy('artifact_id')
            ->map(fn ($g) => $g->map->only(['paragraph', 'schweregrad', 'feld', 'reason', 'vorschlag', 'confidence'])->values()->all())
            ->all();
    }

    /** Der eigentliche read-only Prüf-Pass gegen einen bereits aufgelösten Adapter. */
    private function pruefeAdapter(Team $team, ConformanceAdapter $adapter, int $id): array
    {
        $auftrag = $adapter->pruefauftrag($team, $id);

        $wissen = $this->ladeRegelwerke($team, $auftrag['regelwerk_praefixe']);
        if ($wissen === '') {
            throw new \RuntimeException('Kein aktives Regelwerk-Dossier für die Prüfung gefunden.');
        }

        $vorschlag = app(AiGatewayService::class)->propose(
            'conformance.check',
            $auftrag['kontext'],
            ['knowledge' => $wissen, 'target_table' => $auftrag['target_table'], 'target_id' => $id],
        );

        $roh = $vorschlag->werte['befunde'] ?? [];

        return [
            'gesamturteil' => trim((string) ($vorschlag->werte['gesamturteil'] ?? '')),
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'befunde' => is_array($roh) ? $this->normalisiere($roh) : [],
        ];
    }

    private function adapter(string $typ): ConformanceAdapter
    {
        return match ($typ) {
            'recipe', 'basisrezept', 'vk', 'gericht' => app(RecipeConformanceAdapter::class),
            'gp', 'grundprodukt' => app(GpConformanceAdapter::class),
            'la', 'lieferantenartikel' => app(LaConformanceAdapter::class),
            default => throw new \InvalidArgumentException("Kein Conformance-Adapter für Artefakt-Typ «{$typ}»."),
        };
    }

    /**
     * Volle §-Texte der aktiven, für das Team sichtbaren Regelwerk-Dossiers laden.
     * Bewusst UNGEKAPPT — Schicht 3 prüft gegen das komplette Regelwerk (anders als
     * das relevanz-gedeckelte Generator-Grounding). Sichtbarkeit Slice 1: eigenes
     * Team + globale (team_id NULL); volle Team-Ahnen-Sicht ist Slice-2-Feinschliff.
     *
     * @param  array<int, string>  $praefixe  Slug-Präfixe (z. B. "regelwerk-basisrezepte-")
     */
    private function ladeRegelwerke(Team $team, array $praefixe): string
    {
        if ($praefixe === []) {
            return '';
        }

        $dossiers = DB::table('foodalchemist_knowledge_documents')
            ->whereNull('deleted_at')
            ->where('active', 1)
            ->where(fn ($w) => $w->where('team_id', $team->id)->orWhereNull('team_id'))
            ->where(function ($w) use ($praefixe) {
                foreach ($praefixe as $p) {
                    $w->orWhere('slug', 'like', $p . '%');
                }
            })
            ->orderBy('slug')
            ->get(['slug', 'title', 'content_md']);

        if ($dossiers->isEmpty()) {
            return '';
        }

        // Vorspann raus (siehe DossierText): 18,1 % / 22,0 % / 12,5 % der drei
        // Regelwerk-Präfixe sind derselbe Provenienz-Textbaustein, 17 bzw. 20 bzw. 9 mal
        // wiederholt. Der Block bleibt ungekappt — jede REGEL kommt weiter vollständig an,
        // es fällt nur die Wiederholung. `orderBy('slug')` oben macht ihn zusätzlich
        // byte-identisch über alle Calls, womit er cache-prefix-fähig wird (W3-1).
        $bloecke = $dossiers
            ->map(fn ($d) => "## REGELWERK: {$d->title}\n\n" . trim(DossierText::ohneVorspann((string) $d->content_md)))
            ->all();

        return "# REGELWERKE (vollständig — §-genau prüfen)\n\n"
            . implode("\n\n---\n\n", $bloecke) . "\n\n";
    }

    /**
     * Roh-Befunde → normierte Regelverstöße. Ohne Begründung = Rauschen (raus),
     * Schweregrad auf {hart, weich} geklemmt (Default weich = nur Hinweis, nie Block).
     *
     * @param  array<int, mixed>  $roh
     * @return array<int, array<string, mixed>>
     */
    private function normalisiere(array $roh): array
    {
        $out = [];
        foreach ($roh as $b) {
            if (! is_array($b)) {
                continue;
            }
            $begruendung = trim((string) ($b['begruendung'] ?? $b['grund'] ?? ''));
            if ($begruendung === '') {
                continue;
            }

            $schwere = mb_strtolower(trim((string) ($b['schweregrad'] ?? $b['severity'] ?? 'weich')));
            if (! in_array($schwere, self::SCHWEREGRADE, true)) {
                $schwere = 'weich';
            }

            $out[] = [
                'paragraph' => trim((string) ($b['paragraph'] ?? $b['par'] ?? '')),
                'schweregrad' => $schwere,
                'feld' => trim((string) ($b['feld'] ?? $b['field'] ?? '')),
                'begruendung' => $begruendung,
                'vorschlag' => trim((string) ($b['vorschlag'] ?? '')),
                'konfidenz' => $this->konfidenz($b['konfidenz'] ?? $b['confidence'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Konformitäts-Befunde als Zeilen ablegen (die Schreiber-Hälfte, artefakt-agnostisch).
     * Idempotent über den WERTFREIEN Fingerprint (§ + Feld): derselbe Verstoß am
     * unveränderten Artefakt erzeugt keine Dublette, sondern erhöht seen_count. `verworfen`
     * bleibt verworfen (menschliche Entscheidung hält); nicht mehr gemeldete offene Befunde
     * → verschwunden. Muster aus {@see RecipeFindingService::speichere}.
     *
     * @param  array<int, array<string, mixed>>  $befunde  Form: {@see self::normalisiere}
     * @return array{neu:int, wieder:int, offen:int, verschwunden:int}
     */
    public function speichere(Team $team, string $artifactType, int $artifactId, array $befunde, ?int $runId = null): array
    {
        // withTrashed: der Unique-Index greift auch auf gelöschte Zeilen — ein weggeräumter
        // Befund, der wiederkommt, taucht wieder auf statt am Insert zu scheitern.
        $bestand = FoodAlchemistConformanceFinding::withTrashed()
            ->where('team_id', $team->id)
            ->where('artifact_type', $artifactType)
            ->where('artifact_id', $artifactId)
            ->get()
            ->keyBy('fingerprint');

        $zaehler = ['neu' => 0, 'wieder' => 0, 'offen' => 0, 'verschwunden' => 0];
        $gesehen = [];
        $jetzt = now();

        foreach ($befunde as $b) {
            $fp = $this->fingerprint($artifactType, $artifactId, $b);
            $gesehen[$fp] = true;
            $alt = $bestand->get($fp);

            $felder = [
                'paragraph' => ($b['paragraph'] ?? '') !== '' ? $b['paragraph'] : null,
                'schweregrad' => $b['schweregrad'],
                'feld' => ($b['feld'] ?? '') !== '' ? $b['feld'] : null,
                'reason' => $b['begruendung'],
                'vorschlag' => ($b['vorschlag'] ?? '') !== '' ? $b['vorschlag'] : null,
                'confidence' => $b['konfidenz'],
                'last_seen_at' => $jetzt,
                'run_id' => $runId,
            ];

            if ($alt === null) {
                FoodAlchemistConformanceFinding::create($felder + [
                    'team_id' => $team->id,
                    'artifact_type' => $artifactType,
                    'artifact_id' => $artifactId,
                    'status' => 'offen',
                    'fingerprint' => $fp,
                    'seen_count' => 1,
                    'first_seen_at' => $jetzt,
                ]);
                $zaehler['neu']++;
                $zaehler['offen']++;

                continue;
            }

            if ($alt->trashed()) {
                $alt->restore();
            }
            $status = $alt->status === 'verworfen' ? 'verworfen' : 'offen';
            $alt->update($felder + ['status' => $status, 'seen_count' => $alt->seen_count + 1]);
            if ($status === 'offen') {
                $zaehler['wieder']++;
                $zaehler['offen']++;
            }
        }

        // Offene Befunde, die dieser Lauf NICHT mehr meldet (meist von der Selbstheil-
        // Runde behoben), sind verschwunden.
        foreach ($bestand as $fp => $alt) {
            if (! isset($gesehen[$fp]) && $alt->status === 'offen' && ! $alt->trashed()) {
                $alt->update(['status' => 'verschwunden']);
                $zaehler['verschwunden']++;
            }
        }

        return $zaehler;
    }

    /** Wertfreier Dedup-Schlüssel: Artefakt + § + Feld (nicht der Grund-Text — sonst spawnt jede Umformulierung eine Zeile). */
    private function fingerprint(string $artifactType, int $artifactId, array $b): string
    {
        $identity = trim(($b['paragraph'] ?? '') . '|' . ($b['feld'] ?? ''), '|');
        if ($identity === '') {
            $identity = mb_substr((string) ($b['begruendung'] ?? ''), 0, 80);
        }

        return sha1("{$artifactType}:{$artifactId}:{$identity}");
    }

    /** Verstoß-Liste → eine Freitext-Direktive für die autonome Revise-Runde. */
    private function heilDirektive(array $befunde): string
    {
        $zeilen = array_map(function (array $b) {
            $p = ($b['paragraph'] ?? '') !== '' ? "{$b['paragraph']}: " : '';
            $f = ($b['feld'] ?? '') !== '' ? " [Feld: {$b['feld']}]" : '';
            $v = ($b['vorschlag'] ?? '') !== '' ? " → konform: {$b['vorschlag']}" : '';

            return "- {$p}{$b['begruendung']}{$f}{$v}";
        }, $befunde);

        return "Behebe AUSSCHLIESSLICH die folgenden Regelverstöße und schreibe das Rezept sonst NICHT um:\n"
            . implode("\n", $zeilen);
    }

    /** Konfidenz robust lesen: Zahl 0..1, Prozentzahl oder hoch/mittel/niedrig. */
    private function konfidenz(mixed $wert): float
    {
        if (is_numeric($wert)) {
            $z = (float) $wert;

            return max(0.0, min(1.0, $z > 1 ? $z / 100 : $z));
        }

        return match (mb_strtolower(trim((string) $wert))) {
            'hoch', 'high' => 0.9,
            'niedrig', 'low' => 0.3,
            default => 0.6,
        };
    }
}
