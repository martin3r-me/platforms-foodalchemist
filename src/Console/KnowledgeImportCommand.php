<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * M5-02 / ⚠D4: EINBAHN-Import Vault → DB (wiederholbar — Wissen wird auch NACH
 * dem Seed aktualisiert). Scope = NUR Klasse A: Cross_Cutting (33) + Domains (36)
 * + 07.02 pairings/ (767) ; Aliasse (258, aus vault_context.rs) + Routings
 * (GL-13 §4.1) als Seed. Upsert per slug + content_hash (unverändert ⇒ skip,
 * geändert ⇒ version+1). Nachlauf: vocab_pairing_ankers.knowledge_document_id
 * über source_path verdrahten (ersetzt file_path, D4).
 *
 * 07.03–07.06 werden NICHT importiert (Klasse B Vault-only / Klasse C Phase 2);
 * Niveau_System geht NICHT hierher (→ Hüllen, GL-06/⚠D3).
 */
class KnowledgeImportCommand extends Command
{
    protected $signature = 'foodalchemist:knowledge-import
        {--vault= : Pfad zum 07_WISSEN-Ordner}
        {--rust-src= : Pfad zu vault_context.rs (Alias-Quelle, 258 Paare)}
        {--dry-run : nur zählen}';

    protected $description = 'D4-Wissens-Import (Klasse A): Cross_Cutting + Domains + Pairings + Aliasse + Routings';

    public function handle(): int
    {
        $vault = rtrim((string) $this->option('vault'), '/');
        if ($vault === '' || ! is_dir($vault)) {
            $this->error('--vault=/pfad/zu/07_WISSEN angeben.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $stats = [];
        $stats['cross_cutting'] = $this->importOrdner("{$vault}/07.01_Lebensmittel_und_Gastronomie/Cross_Cutting", 'cross_cutting', $dryRun);
        $stats['domain'] = $this->importOrdner("{$vault}/07.01_Lebensmittel_und_Gastronomie/Domains", 'domain', $dryRun);
        $stats['pairing'] = $this->importOrdner("{$vault}/07.02_Flavor_Pairing/pairings", 'pairing', $dryRun, slugPrefix: 'pairing.');

        $stats['aliases'] = $this->importAliases($dryRun);
        $stats['routings'] = $this->seedRoutings($dryRun);
        $stats['anker_links'] = $this->verdrahteAnker($dryRun);

        $this->table(['Phase', 'Quelle', 'neu', 'aktualisiert', 'unverändert/übersprungen'],
            collect($stats)->map(fn ($s, $k) => [$k, $s['source'] ?? '—', $s['neu'] ?? '—', $s['geaendert'] ?? '—', $s['skip'] ?? '—'])->all());

        // Gates (07 §5-Stil)
        if (! $dryRun) {
            foreach ([['cross_cutting', 33], ['domain', 36], ['pairing', 767]] as [$kategorie, $soll]) {
                $ist = DB::table('foodalchemist_knowledge_documents')->where('category', $kategorie)->count();
                $this->line(($ist >= $soll ? '✅' : '❌') . " knowledge.{$kategorie}: {$ist} (Soll ≥ {$soll})");
            }
            $aliasIst = DB::table('foodalchemist_knowledge_aliases')->count();
            $this->line(($aliasIst === 258 ? '✅' : '⚠️ ') . " aliases: {$aliasIst} (Soll 258)");
            $offen = DB::table('foodalchemist_vocab_pairing_anchors')->whereNotNull('source_path')->whereNull('knowledge_document_id')->count();
            $this->line(($offen === 0 ? '✅' : '⚠️ ') . " anker→knowledge-Links: {$offen} offen");
        }

        return self::SUCCESS;
    }

    private function importOrdner(string $pfad, string $kategorie, bool $dryRun, string $slugPrefix = ''): array
    {
        if (! is_dir($pfad)) {
            $this->warn("Ordner fehlt: {$pfad}");

            return ['source' => 0, 'neu' => 0, 'geaendert' => 0, 'skip' => 0];
        }
        $dateien = glob("{$pfad}/*.md");
        $neu = $geaendert = $skip = 0;
        $now = now()->toDateTimeString();

        foreach ($dateien as $datei) {
            $basis = basename($datei, '.md');
            if (str_starts_with($basis, '_')) {                       // _Index/_README etc. sind Meta
                continue;
            }
            $slug = $slugPrefix . $this->slug($basis);
            $inhalt = (string) file_get_contents($datei);
            $hash = hash('sha256', $inhalt);

            if ($dryRun) {
                $skip++;

                continue;
            }

            // Vault-Dubletten (gleicher Slug, ANDERE Datei — z. B. schwarzer-knoblauch.md vs
            // schwarzer_knoblauch.md): Identität ist der source_path; Kollisionen bekommen _2 (§1.8-Muster)
            $vorhanden = DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->first();
            if ($vorhanden !== null && $vorhanden->source_path !== $this->relativ($datei)) {
                $basisSlug = $slug;
                for ($n = 2; $vorhanden !== null && $vorhanden->source_path !== $this->relativ($datei); $n++) {
                    $slug = "{$basisSlug}_{$n}";
                    $vorhanden = DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->first();
                }
            }
            if ($vorhanden === null) {
                DB::table('foodalchemist_knowledge_documents')->insert([
                    'uuid' => (string) UuidV7::generate(),
                    'slug' => $slug,
                    'titel' => $this->titel($inhalt, $basis),
                    'category' => $kategorie,
                    'inhalt_md' => $inhalt,
                    'version' => 1,
                    'content_hash' => $hash,
                    'char_count' => mb_strlen($inhalt),
                    'active' => true,
                    'source_path' => $this->relativ($datei),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $neu++;
            } elseif ($vorhanden->content_hash !== $hash) {
                DB::table('foodalchemist_knowledge_documents')->where('id', $vorhanden->id)->update([
                    'inhalt_md' => $inhalt,
                    'titel' => $this->titel($inhalt, $basis),
                    'version' => $vorhanden->version + 1,            // monoton bei Inhalts-Änderung
                    'content_hash' => $hash,
                    'char_count' => mb_strlen($inhalt),
                    'source_path' => $this->relativ($datei),
                    'updated_at' => $now,
                ]);
                $geaendert++;
            } else {
                $skip++;                                              // idempotent
            }
        }

        return ['source' => count($dateien), 'neu' => $neu, 'geaendert' => $geaendert, 'skip' => $skip];
    }

    /** Die 258 Paare aus vault_context.rs (HAUPTZUTAT_TO_DOMAIN) → alias → Domain-Dokument. */
    private function importAliases(bool $dryRun): array
    {
        $rs = (string) $this->option('rust-src');
        if ($rs === '' || ! is_readable($rs)) {
            $this->warn('--rust-src fehlt — Alias-Phase übersprungen.');

            return ['source' => 0, 'neu' => 0, 'geaendert' => 0, 'skip' => 0];
        }
        $source = (string) file_get_contents($rs);
        if (! preg_match('/const HAUPTZUTAT_TO_DOMAIN[^=]*=\s*&\[(.*?)\];/s', $source, $m)) {
            $this->error('HAUPTZUTAT_TO_DOMAIN nicht gefunden.');

            return ['source' => 0, 'neu' => 0, 'geaendert' => 0, 'skip' => 0];
        }
        preg_match_all('/\("([^"]+)",\s*"([^"]+)"\)/', $m[1], $paare, PREG_SET_ORDER);

        $neu = $skip = 0;
        $ohneDoc = [];
        $now = now()->toDateTimeString();
        foreach ($paare as [, $alias, $domainName]) {
            if ($dryRun) {
                $skip++;

                continue;
            }
            $doc = DB::table('foodalchemist_knowledge_documents')
                ->where('category', 'domain')->where('slug', $this->slug($domainName))->first();
            if ($doc === null) {
                $ohneDoc[$domainName] = true;

                continue;
            }
            $vorhanden = DB::table('foodalchemist_knowledge_aliases')->where('alias_slug', $alias)->exists();
            if ($vorhanden) {
                $skip++;
            } else {
                DB::table('foodalchemist_knowledge_aliases')->insert([
                    'alias_slug' => $alias, 'knowledge_document_id' => $doc->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $neu++;
            }
        }
        if ($ohneDoc !== []) {
            $this->warn('Aliasse ohne Domain-Dokument: ' . implode(', ', array_keys($ohneDoc)));
        }

        return ['source' => count($paare), 'neu' => $neu, 'geaendert' => 0, 'skip' => $skip];
    }

    /** GL-13 Tabelle 4.1 als Daten (pro KI-Feature konfigurierbar). */
    private function seedRoutings(bool $dryRun): array
    {
        $routings = [
            ['ai_generate_recipe', 'cross_cutting', 'always', null, null],
            ['ai_generate_recipe', 'domain', 'discovery', null, null],
            ['ai_generate_recipe', 'pairing', 'discovery', null, null],
            ['ai_plan_dishes', 'cross_cutting', 'always', null, null],
            ['ai_plan_dishes', 'domain', 'discovery', null, null],
            ['ai_extract_recipe', 'cross_cutting', 'none', null, null],     // bewusst leer
            ['ai_suggest_pairings', 'pairing', 'grounding', 5, 1200],
            ['ai_infer_ankers', 'pairing', 'grounding', 3, 1400],
        ];
        if ($dryRun) {
            return ['source' => count($routings), 'neu' => 0, 'geaendert' => 0, 'skip' => count($routings)];
        }
        $neu = $skip = 0;
        $now = now()->toDateTimeString();
        foreach ($routings as [$feature, $kategorie, $modus, $maxDocs, $maxChars]) {
            $ok = DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
                'feature' => $feature, 'category' => $kategorie, 'modus' => $modus,
                'max_docs' => $maxDocs, 'max_chars_per_doc' => $maxChars,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $ok ? $neu++ : $skip++;
        }

        return ['source' => count($routings), 'neu' => $neu, 'geaendert' => 0, 'skip' => $skip];
    }

    /** Anker → Pairing-Dokument über source_path (ersetzt file_path, D4). */
    private function verdrahteAnker(bool $dryRun): array
    {
        if ($dryRun) {
            return ['source' => 0, 'neu' => 0, 'geaendert' => 0, 'skip' => 0];
        }
        $docs = DB::table('foodalchemist_knowledge_documents')->where('category', 'pairing')
            ->pluck('id', 'source_path');
        $neu = 0;
        foreach (DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNotNull('source_path')->whereNull('knowledge_document_id')->get(['id', 'source_path']) as $anker) {
            $docId = $docs[$anker->source_path] ?? null;
            if ($docId !== null) {
                DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $anker->id)
                    ->update(['knowledge_document_id' => $docId, 'updated_at' => now()]);
                $neu++;
            }
        }

        return ['source' => $docs->count(), 'neu' => $neu, 'geaendert' => 0, 'skip' => 0];
    }

    private function slug(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);

        return trim(preg_replace('/_+/', '_', $s), '_');
    }

    private function titel(string $inhalt, string $fallback): string
    {
        return preg_match('/^#\s+(.+)$/m', $inhalt, $m) ? trim($m[1]) : str_replace('_', ' ', $fallback);
    }

    private function relativ(string $pfad): string
    {
        $pos = mb_strpos($pfad, '07_WISSEN');

        return $pos !== false ? mb_substr($pfad, $pos) : $pfad;
    }
}
