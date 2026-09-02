<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * W2-5: der fehlende RÜCKWEG aus dem Wissensmodul.
 *
 * Ausgangslage (CLAUDE.md, SSOT-Shift 2026-08-21): das Modul ist die Source of Truth, der
 * Vault nur noch Spiegel/Backup. Es gab aber ausschliesslich `knowledge-import` —
 * 3,2 Millionen Zeichen kuratiertes Wissen ohne jeden Weg hinaus. Ein Backup, das man nicht
 * schreiben kann, ist keines, und „Vault = Spiegel" war damit eine Behauptung ohne Werkzeug.
 *
 * Der Export spiegelt die Ordner-/Präfix-Logik des Imports, damit der Rückweg wirklich einer
 * ist: `regelwerk.foo` landet als `Regelwerke/foo.md`, weil der Import genau dort mit dem
 * Präfix `regelwerk.` liest. Kategorien, die es im Vault nie gab (event_playbook, segment,
 * kueche … — im Modul entstanden), gehen nach `_modul/<kategorie>/`; sie sind vault-fremd
 * und sollen den gespiegelten Baum nicht verunreinigen.
 *
 * ZWEI ENTSCHEIDUNGEN, die den Round-Trip erst korrekt machen:
 *
 * 1. Die .md-Datei enthält `content_md` WORTWÖRTLICH — kein Frontmatter, keine Kopfzeile.
 *    Grund: `KnowledgeImportCommand::importOrdner` liest die Datei komplett INKLUSIVE
 *    Frontmatter in `content_md` (dokumentierte Altlast). Würde der Export Frontmatter
 *    schreiben, wüchse bei jedem Import/Export-Zyklus ein weiterer Kopf ins Dokument.
 *    Die Metadaten liegen darum in `_manifest.json` daneben.
 *
 * 2. Es wird NICHTS gelöscht. Der Befehl schreibt und lässt liegen — auch Dateien zu
 *    Dokumenten, die im Modul verschwunden sind. Ein Backup-Werkzeug, das aufräumt, kann
 *    einen Fehler im Modul in einen Datenverlust im Backup übersetzen.
 *
 * Idempotent über `content_hash`: unveränderte Dokumente werden übersprungen, `--force`
 * schreibt trotzdem.
 */
class KnowledgeExportCommand extends Command
{
    protected $signature = 'foodalchemist:knowledge-export
        {--dir= : Ziel-Ordner (üblich: der 07_WISSEN-Pfad des Vaults)}
        {--team= : nur Dokumente dieses Teams (ohne Angabe: alle, inkl. global)}
        {--category= : nur eine Kategorie}
        {--include-inactive : auch inaktive Dokumente mitschreiben}
        {--force : auch unveränderte Dateien neu schreiben}
        {--dry-run : nur zählen, nichts schreiben}';

    protected $description = 'Wissens-Export Modul → Vault (Spiegel/Backup, Gegenstück zu knowledge-import)';

    /**
     * Kategorie → Vault-Ordner + Slug-Präfix, INVERS zu KnowledgeImportCommand::handle().
     * Wer hier etwas ändert, muss dort mitziehen — sonst ist der Rückweg gebrochen.
     */
    private const VAULT_PFADE = [
        'cross_cutting' => ['07.01_Lebensmittel_und_Gastronomie/Cross_Cutting', ''],
        'domain' => ['07.01_Lebensmittel_und_Gastronomie/Domains', ''],
        'pairing' => ['07.02_Flavor_Pairing/pairings', 'pairing.'],
        'regelwerk' => ['07.01_Lebensmittel_und_Gastronomie/Regelwerke', 'regelwerk.'],
        'niveau' => ['07.01_Lebensmittel_und_Gastronomie/Niveau_System', 'niveau.'],
        'trend' => ['07.03_Trend_Scouting', 'trend.'],
        'workflow' => ['07.01_Lebensmittel_und_Gastronomie/Workflows', 'workflow.'],
        'concept' => ['07.01_Lebensmittel_und_Gastronomie/Concepting', 'concept.'],
    ];

    public function handle(): int
    {
        $dir = rtrim((string) $this->option('dir'), '/');
        if ($dir === '') {
            $this->error('--dir=/pfad/zum/ziel angeben (üblich: der 07_WISSEN-Ordner).');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! is_dir($dir) && ! @mkdir($dir, 0775, true)) {
            $this->error("Ziel-Ordner nicht anlegbar: {$dir}");

            return self::FAILURE;
        }

        $q = DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at');
        if (! $this->option('include-inactive')) {
            $q->where('active', 1);
        }
        if (($team = $this->option('team')) !== null && $team !== '') {
            $q->where('team_id', (int) $team);
        }
        if (($kat = $this->option('category')) !== null && $kat !== '') {
            $q->where('category', $kat);
        }

        $docs = $q->orderBy('category')->orderBy('slug')
            ->get(['id', 'team_id', 'slug', 'title', 'category', 'version', 'content_md', 'content_hash', 'char_count', 'active', 'source_path', 'created_via', 'updated_at']);

        if ($docs->isEmpty()) {
            $this->warn('Keine Dokumente für diese Auswahl — nichts zu tun.');

            return self::SUCCESS;
        }

        $manifest = [];
        $neu = $gleich = 0;
        $zeichen = 0;

        foreach ($docs as $doc) {
            [$ordner, $praefix] = self::VAULT_PFADE[$doc->category] ?? ["_modul/{$doc->category}", ''];
            // Präfix abziehen: der Import setzt ihn beim Lesen wieder davor. Bleibt er in
            // der Datei, entsteht beim Rückweg `regelwerk.regelwerk.foo`.
            $name = $praefix !== '' && str_starts_with($doc->slug, $praefix)
                ? substr($doc->slug, strlen($praefix))
                : $doc->slug;
            $relativ = $ordner . '/' . $this->dateiName($name) . '.md';
            $ziel = $dir . '/' . $relativ;

            $manifest[] = [
                'slug' => $doc->slug, 'datei' => $relativ, 'kategorie' => $doc->category,
                'titel' => $doc->title, 'version' => (int) $doc->version, 'team_id' => $doc->team_id,
                'aktiv' => (bool) $doc->active, 'zeichen' => (int) $doc->char_count,
                'content_hash' => $doc->content_hash, 'source_path' => $doc->source_path,
                'created_via' => $doc->created_via, 'stand' => (string) $doc->updated_at,
            ];
            $zeichen += (int) $doc->char_count;

            $inhalt = (string) $doc->content_md;
            $unveraendert = is_file($ziel) && hash('sha256', (string) file_get_contents($ziel)) === hash('sha256', $inhalt);
            if ($unveraendert && ! $this->option('force')) {
                $gleich++;

                continue;
            }
            $neu++;
            if ($dryRun) {
                continue;
            }
            $unter = dirname($ziel);
            if (! is_dir($unter) && ! @mkdir($unter, 0775, true)) {
                $this->error("Unterordner nicht anlegbar: {$unter}");

                return self::FAILURE;
            }
            if (file_put_contents($ziel, $inhalt) === false) {
                $this->error("Schreiben fehlgeschlagen: {$ziel}");

                return self::FAILURE;
            }
        }

        if (! $dryRun) {
            file_put_contents(
                $dir . '/_manifest.json',
                (string) json_encode([
                    'exportiert_am' => now()->toIso8601String(),
                    'dokumente' => count($manifest),
                    'zeichen' => $zeichen,
                    'hinweis' => 'Die .md-Dateien enthalten content_md wortwörtlich (kein Frontmatter) — '
                        . 'der Import liest Dateien komplett in content_md. Metadaten stehen hier.',
                    'docs' => $manifest,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        $this->info(sprintf(
            '%s %d Dokumente (%s Zeichen): %d geschrieben, %d unverändert übersprungen.',
            $dryRun ? '[dry-run]' : '✓', count($manifest), number_format($zeichen, 0, ',', '.'), $neu, $gleich,
        ));
        if (! $dryRun) {
            $this->line("  Manifest: {$dir}/_manifest.json");
        }
        $this->line('  Es wurde nichts gelöscht — verwaiste Dateien bleiben absichtlich liegen.');

        return self::SUCCESS;
    }

    /** Slug → Dateiname: nur was in einem Vault-Dateinamen gefahrlos steht. */
    private function dateiName(string $slug): string
    {
        $n = preg_replace('/[^A-Za-z0-9_.\- äöüÄÖÜß]/u', '_', $slug) ?? $slug;

        return trim(preg_replace('/_+/', '_', $n) ?? $n, '_') ?: 'unbenannt';
    }
}
