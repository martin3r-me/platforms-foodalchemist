<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;

/**
 * Spec 50 Strang III: ARBEITSLISTE der zu großen Dossiers — ein Thema pro Dossier, hart nie über
 * dem Deckel (`semantic_search.dossier_max_chars`). Der Befehl liefert pro Dossier die `##`-Struktur,
 * damit der Split (per MCP knowledge.POST + SET_ACTIVE) von Hand oder per Skill sauber entlang der
 * Themen läuft und nicht mitten im Absatz.
 *
 * Nur lesen. Der Split selbst ist Kuration (Dominique), nicht Automatik — ein Programm weiß nicht,
 * wo ein Thema endet; die Überschriften sind der beste Hinweis, den es gibt.
 */
class KnowledgeOversizedCommand extends Command
{
    protected $signature = 'foodalchemist:knowledge-oversized
        {--team= : nur Dossiers dieses Teams (leer = ganzer Korpus, globale eingeschlossen)}
        {--kategorie= : nur diese Kategorie}
        {--deckel= : Deckel überschreiben (Standard aus config)}
        {--include-inactive : auch deaktivierte Dossiers}
        {--struktur : ##-Überschriften je Dossier ausgeben (Split-Vorschlag)}
        {--json : Maschinenlesbar}';

    protected $description = 'Listet Wissens-Dossiers über dem Größen-Deckel mit ##-Struktur als Split-Arbeitsliste';

    public function handle(KnowledgeCanonService $canon): int
    {
        $deckel = (int) ($this->option('deckel') ?: $canon->dossierMaxChars());
        $fenster = (int) config('foodalchemist.semantic_search.embed_lead_chars', 2000);

        $q = DB::table('foodalchemist_knowledge_documents')
            ->whereNull('deleted_at')
            ->where('char_count', '>', $deckel)
            ->orderByDesc('char_count');
        if ($this->option('team') !== null && $this->option('team') !== '') {
            $q->where('team_id', (int) $this->option('team'));
        }
        if ($this->option('kategorie')) {
            $q->where('category', (string) $this->option('kategorie'));
        }
        if (! $this->option('include-inactive')) {
            $q->where('active', 1);
        }

        $rows = $q->get(['id', 'team_id', 'slug', 'title', 'category', 'char_count', 'active', 'content_md']);

        $liste = $rows->map(function ($r) use ($fenster) {
            preg_match_all('/^\s{0,3}(##)\s+(.+?)\s*$/mu', (string) $r->content_md, $m, PREG_OFFSET_CAPTURE);
            $abschnitte = [];
            $starts = array_map(static fn ($x) => mb_strlen(substr((string) $r->content_md, 0, $x[1])), $m[0]);
            foreach ($m[2] as $i => $h) {
                $von = $starts[$i];
                $bis = $starts[$i + 1] ?? (int) $r->char_count;
                $abschnitte[] = ['titel' => $h[0], 'zeichen' => $bis - $von, 'jenseits_fenster' => $von >= $fenster];
            }

            return [
                'slug' => $r->slug, 'title' => $r->title, 'category' => $r->category,
                'team_id' => $r->team_id, 'active' => (bool) $r->active,
                'char_count' => (int) $r->char_count,
                'faktor' => round((int) $r->char_count / max(1, $fenster), 1),
                'abschnitte' => $abschnitte,
            ];
        })->values();

        if ($this->option('json')) {
            $this->line(json_encode(['deckel' => $deckel, 'fenster' => $fenster, 'total' => $liste->count(), 'dossiers' => $liste], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d Dossier(s) über %d Zeichen (Embedding-Fenster %d).', $liste->count(), $deckel, $fenster));
        $proKat = $liste->groupBy('category')->map->count()->sortDesc();
        foreach ($proKat as $kat => $n) {
            $this->line(sprintf('  %-22s %3d', $kat, $n));
        }
        $this->newLine();
        $this->table(
            ['Zeichen', '×Fenster', 'Kat', 'Slug', '##', 'aktiv'],
            $liste->map(fn ($d) => [
                number_format($d['char_count'], 0, ',', '.'), $d['faktor'] . '×', $d['category'], $d['slug'],
                count($d['abschnitte']), $d['active'] ? 'ja' : 'nein',
            ])->all()
        );

        if ($this->option('struktur')) {
            foreach ($liste as $d) {
                $this->newLine();
                $this->line(sprintf('<info>%s</info> (%s Z.)', $d['slug'], number_format($d['char_count'], 0, ',', '.')));
                if ($d['abschnitte'] === []) {
                    $this->line('  — keine ##-Überschriften: Split von Hand entlang der Themen');
                }
                foreach ($d['abschnitte'] as $a) {
                    $this->line(sprintf('  %s %-60s %6s Z.', $a['jenseits_fenster'] ? '·' : '▲', mb_strimwidth($a['titel'], 0, 60, '…'), number_format($a['zeichen'], 0, ',', '.')));
                }
            }
            $this->newLine();
            $this->comment('▲ = im Embedding-Fenster (findbar) · · = jenseits (heute semantisch fast unsichtbar)');
        }

        return self::SUCCESS;
    }
}
