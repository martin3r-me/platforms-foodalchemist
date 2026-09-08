<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\VorgangsRegisterService;

/**
 * Spec 50 · Etappe 8b — zwei Invarianten der Wissensbasis, die niemand von Hand halten kann.
 *
 * **(1) Der Zeichen-Deckel.** Kein Dossier darf über {@see KnowledgeCanonService::dossierMaxChars()}
 * gehen: das Embedding-Fenster liegt seit 2026-09-07 bei 4.000 Zeichen — genau auf dem Deckel,
 * damit ein Dossier, das die Kuration erlaubt, auch vollständig im Vektor steckt. Alles
 * DARÜBER ist semantisch nur noch über den Kopf auffindbar (gemessen 55 %), und die
 * Wissens-Oberfläche meldet grössere Dossiers als Fehler. Gemessen
 * 2026-09-06 lagen 6 von 864 Dokumenten darüber — nach dem Split keines mehr, aber **sieben
 * Dossiers liegen zwischen 3.900 und 3.993**. Die nächste inhaltliche Ergänzung kippt eines
 * davon, und auffallen würde es erst, wenn jemand die Fehlermeldung sieht.
 *
 * **(2) Die Verdrahtung der Vorgangs-Dossiers.** Ein Workflow-Dossier trägt im Frontmatter
 * `gilt_fuer_vorgang: <code>`; das Register hält dazu `doc_slugs`. Beide Richtungen können
 * auseinanderlaufen, und **beide Fehler sind still**:
 *   · Dossier vorhanden, aber nicht im Register → `ablauf.GET` liefert es nie. Genau das ist
 *     am 2026-09-07 passiert: `workflow.gericht_abschluss` war angelegt und aktiv, fehlte aber
 *     in `doc_slugs`. Der Agent bekam Regeln und beide Wege, aber nie die Definition of Done.
 *     Kein Test konnte das finden — er prüft, was im Register steht.
 *   · Slug im Register, aber Dossier fehlt oder inaktiv → nur eine Warnung, denn das meldet
 *     `ablauf.GET` dem Agenten selbst als `ablauf_prosa_teilweise`. Sichtbarer Rückstand,
 *     kein stiller Bug — nur Richtung B lässt den Lauf fehlschlagen.
 *
 * Rein lesend. Exit-Code 1 bei Befunden, damit ein Scheduler-Lauf sichtbar fehlschlägt.
 */
class WissenDeckelCheckCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-deckel-check
        {--warnband=200 : Zeichen unter dem Deckel, ab denen ein Dossier als knapp gilt}
        {--nur-deckel : nur den Zeichen-Deckel prüfen, die Register-Verdrahtung überspringen}';

    protected $description = 'Prüft (lesend) den 4.000-Zeichen-Deckel und die Verdrahtung der Vorgangs-Dossiers';

    public function handle(KnowledgeCanonService $canon, KnowledgeContextService $wissen): int
    {
        $deckel = $canon->dossierMaxChars();
        $warnband = max(0, (int) $this->option('warnband'));
        $befunde = 0;

        // ── 1. Zeichen-Deckel über den ganzen aktiven Korpus ──────────────────────
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)->whereNull('deleted_at')
            ->orderByDesc('char_count')
            ->get(['slug', 'category', 'char_count', 'team_id']);

        $ueber = $docs->filter(fn ($d) => (int) $d->char_count > $deckel);
        $knapp = $docs->filter(fn ($d) => (int) $d->char_count <= $deckel && (int) $d->char_count > $deckel - $warnband);

        $this->info("Wissens-Deckel: {$docs->count()} aktive Dossiers, Deckel {$deckel} Zeichen.");

        if ($ueber->isNotEmpty()) {
            $befunde += $ueber->count();
            $this->error("✗ {$ueber->count()} Dossier(s) über dem Deckel:");
            $this->table(['Zeichen', 'Kategorie', 'Slug', 'Eigentum'], $ueber->map(fn ($d) => [
                (int) $d->char_count, $d->category, $d->slug,
                // Global heisst: über MCP nicht editierbar — der Weg führt nur über eine Migration.
                $d->team_id === null ? 'global (nur per Migration)' : 'team-eigen',
            ])->all());
        } else {
            $this->line('✓ Kein Dossier über dem Deckel.');
        }

        if ($knapp->isNotEmpty()) {
            $this->warn("⚠ {$knapp->count()} Dossier(s) im Warnband (>" . ($deckel - $warnband) . " Zeichen) — die nächste Ergänzung kippt sie:");
            foreach ($knapp->take(10) as $d) {
                $this->line(sprintf('   %5d  %-24s %s', (int) $d->char_count, $d->category, $d->slug));
            }
            if ($knapp->count() > 10) {
                $this->line('   … und ' . ($knapp->count() - 10) . ' weitere.');
            }
        }

        if ($this->option('nur-deckel')) {
            return $befunde > 0 ? self::FAILURE : self::SUCCESS;
        }

        // ── 2. Verdrahtung Register ⇄ Dossiers, in BEIDE Richtungen ───────────────
        $this->newLine();
        $this->info('Vorgangs-Dossiers: Register ⇄ Wissensbasis.');

        $imRegister = [];
        foreach (VorgangsRegisterService::VORGAENGE as $code => $v) {
            foreach ($v['doc_slugs'] as $slug) {
                $imRegister[$slug] = $code;
            }
        }

        // Richtung A: Slug im Register → Dossier muss aktiv existieren.
        $fehlend = [];
        foreach ($imRegister as $slug => $code) {
            if ($docs->firstWhere('slug', $slug) === null) {
                $fehlend[] = "$code → $slug";
            }
        }
        if ($fehlend !== []) {
            // Bewusst nur eine Warnung: das meldet `ablauf.GET` dem Agenten selbst als
            // `ablauf_prosa_teilweise` — es ist ein sichtbarer Kuratier-Rückstand, kein
            // stiller Bug. Hart failen würde jede Umgebung rot färben, in der ein Teil der
            // Dossiers schlicht noch nicht angelegt ist.
            $this->warn('⚠ Im Register verdrahtet, aber nicht aktiv vorhanden (ablauf.GET meldet das selbst):');
            foreach ($fehlend as $z) {
                $this->line("   $z");
            }
        } else {
            $this->line('✓ Jeder verdrahtete Slug existiert und ist aktiv (' . count($imRegister) . ').');
        }

        // Richtung B: Dossier sagt `gilt_fuer_vorgang` → muss im Register stehen.
        // Das ist die Richtung, die 2026-09-07 gefehlt hat.
        $verwaist = [];
        foreach ($docs->where('category', 'workflow') as $d) {
            $inhalt = DB::table('foodalchemist_knowledge_documents')
                ->where('slug', $d->slug)->value('content_md');
            $kopf = $wissen->frontmatterOf((string) $inhalt);
            $vorgang = trim((string) ($kopf['gilt_fuer_vorgang'] ?? ''));
            if ($vorgang === '') {
                continue;
            }
            if (! isset($imRegister[$d->slug])) {
                $verwaist[] = "{$d->slug} (nennt gilt_fuer_vorgang: {$vorgang})";
            } elseif ($imRegister[$d->slug] !== $vorgang) {
                $verwaist[] = "{$d->slug} (Dossier sagt «{$vorgang}», Register führt es unter «{$imRegister[$d->slug]}»)";
            }
        }
        if ($verwaist !== []) {
            $befunde += count($verwaist);
            $this->error('✗ Dossier nennt einen Vorgang, das Register kennt es dort nicht — ablauf.GET liefert es NIE:');
            foreach ($verwaist as $z) {
                $this->line("   $z");
            }
        } else {
            $this->line('✓ Jedes Dossier mit `gilt_fuer_vorgang` ist im Register verdrahtet.');
        }

        $this->newLine();
        if ($befunde > 0) {
            $this->error("$befunde Befund(e) — siehe oben.");

            return self::FAILURE;
        }
        $this->info('Alles sauber.');

        return self::SUCCESS;
    }
}
