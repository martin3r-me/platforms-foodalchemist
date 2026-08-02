<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

/**
 * Trendradar — clustert die importierten Trend-Wissens-Docs
 * (knowledge_documents WHERE category='trend') in die zweistufige Taxonomie
 * Kategorie → Klasse und schreibt Cluster-/Facetten-Metadaten je Doc.
 *
 * Mechanik: KI-Labeling in Batches über den Core-AiGateway (Prompt
 * `trend.cluster_label`). Kategorie STRIKT aus dem Seed-Vokabular; neue Klassen
 * landen `tentative` in foodalchemist_trend_taxonomy (Review-Queue, wie LA-First).
 * Der Cluster = die zugeordnete Klasse (Docs derselben Klasse = ein Cluster,
 * Cluster-Größe = Relevanz-/Hitze-Signal im Radar).
 *
 * Global (team_id=NULL) — Trends sind teamübergreifend. Idempotent: bereits
 * gelabelte Docs werden übersprungen, --reklassifizieren erzwingt Neu-Labeling.
 */
class TrendClusterCommand extends Command
{
    protected $signature = 'foodalchemist:trend-cluster
        {--dry-run : Nur zählen/anzeigen, nichts schreiben und keine KI-Calls}
        {--reklassifizieren : Auch schon gelabelte Trends neu einordnen}
        {--batch=15 : Trends pro KI-Call}';

    protected $description = 'Clustert Trend-Wissens-Docs in die Kategorie→Klasse-Taxonomie (KI-Labeling)';

    /** Reifegrad-Vokabular (englisch, Schema-konform). */
    private const MATURITIES = ['niche', 'emerging', 'mainstream', 'declining'];

    /** Frontmatter-Relevanz (deutsch) → Schema-Wert (englisch). */
    private const RELEVANCE_MAP = ['hoch' => 'high', 'mittel' => 'medium', 'niedrig' => 'low'];

    public function handle(KnowledgeContextService $knowledge): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reklass = (bool) $this->option('reklassifizieren');
        $batchSize = max(1, (int) $this->option('batch'));

        // Kategorie-Ebene (fixes Seed-Vokabular) + bestehende Klassen je Kategorie.
        $kategorien = DB::table('foodalchemist_trend_taxonomy')
            ->whereNull('deleted_at')->whereNull('trend_class')
            ->where('status', 'approved')->orderBy('sort_order')
            ->pluck('category')->all();
        if ($kategorien === []) {
            $this->error('Keine Trend-Kategorien geseedet — Migration foodalchemist_trend_taxonomy fehlt?');

            return self::FAILURE;
        }
        $existingClasses = $this->bestehendeKlassen();

        // Schon gelabelte Docs (für Idempotenz).
        $bereitsGelabelt = $reklass ? [] : DB::table('foodalchemist_trend_meta')
            ->pluck('knowledge_document_id')->all();
        $bereitsGelabelt = array_flip($bereitsGelabelt);

        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'trend')->where('active', 1)->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'slug', 'title', 'content_md'])
            ->reject(fn ($d) => isset($bereitsGelabelt[$d->id]))
            ->values();

        $this->info(sprintf('%d Trend-Docs zu clustern (Batch %d)%s.',
            $docs->count(), $batchSize, $dryRun ? ' [DRY-RUN]' : ''));
        if ($docs->isEmpty()) {
            $this->line('Nichts zu tun.');

            return self::SUCCESS;
        }
        if ($dryRun) {
            $this->line('DRY-RUN: keine KI-Calls, kein Schreiben. Kategorien: ' . implode(', ', $kategorien));

            return self::SUCCESS;
        }

        $ai = app(AiGatewayService::class);
        $written = 0;
        $neueKlassen = 0;
        $fehler = 0;

        foreach ($docs->chunk($batchSize) as $chunk) {
            $trends = [];
            $byIndex = [];
            $i = 0;
            foreach ($chunk as $doc) {
                $fm = $knowledge->frontmatterOf((string) $doc->content_md);
                $trends[] = [
                    'index' => $i,
                    'title' => $doc->title,
                    'summary' => $this->summary((string) $doc->content_md),
                    'tags' => $fm['tags'] ?? [],
                ];
                $byIndex[$i] = ['doc' => $doc, 'fm' => $fm];
                $i++;
            }

            try {
                $proposal = $ai->propose('trend.cluster_label', [
                    'categories' => $kategorien,
                    'existing_classes' => $existingClasses,
                    'trends' => $trends,
                ]);
            } catch (\Throwable $e) {
                $fehler += count($chunk);
                $this->warn('KI-Batch fehlgeschlagen: ' . $e->getMessage());

                continue;
            }

            $items = is_array($proposal->werte['items'] ?? null) ? $proposal->werte['items'] : [];
            foreach ($items as $item) {
                $idx = (int) ($item['index'] ?? -1);
                if (! isset($byIndex[$idx])) {
                    continue;
                }
                $doc = $byIndex[$idx]['doc'];
                $fm = $byIndex[$idx]['fm'];

                $category = (string) ($item['category'] ?? '');
                $category = in_array($category, $kategorien, true) ? $category : null;
                $klasseLabel = trim((string) ($item['trend_class'] ?? ''));
                $maturity = strtolower((string) ($item['maturity'] ?? ''));
                $maturity = in_array($maturity, self::MATURITIES, true) ? $maturity : null;
                $isHype = (bool) ($item['is_hype'] ?? false);
                $confidence = is_numeric($item['confidence'] ?? null) ? (float) $item['confidence'] : null;

                // Klassen-Zeile sicherstellen (nur wenn Kategorie gültig + Klasse vergeben).
                $taxId = null;
                $klasseSlug = null;
                $status = 'tentative';
                if ($category !== null && $klasseLabel !== '') {
                    [$taxId, $klasseSlug, $neu, $status] = $this->ensureKlasse($category, $klasseLabel);
                    if ($neu) {
                        $neueKlassen++;
                        // Cache aktualisieren, damit der nächste Batch die Klasse schon kennt.
                        $existingClasses[$category][] = $klasseLabel;
                    }
                }

                $relevanz = strtolower((string) ($fm['relevanz'] ?? ''));
                $relevance = self::RELEVANCE_MAP[$relevanz] ?? null;

                DB::table('foodalchemist_trend_meta')->updateOrInsert(
                    ['knowledge_document_id' => $doc->id],
                    [
                        'uuid' => DB::table('foodalchemist_trend_meta')
                            ->where('knowledge_document_id', $doc->id)->value('uuid') ?? (string) Str::uuid(),
                        'trend_taxonomy_id' => $taxId,
                        'cluster_id' => $klasseSlug,
                        'category' => $category,
                        'trend_class' => $klasseLabel !== '' ? $klasseLabel : null,
                        'maturity' => $maturity,
                        'is_hype' => $isHype,
                        'relevance' => $relevance,
                        'confidence' => $confidence,
                        'method' => 'embedding+ai',
                        'status' => $status,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $written++;
            }
        }

        $this->info(sprintf('Fertig: %d Docs gelabelt, %d neue tentative Klassen, %d Fehler.',
            $written, $neueKlassen, $fehler));
        if ($neueKlassen > 0) {
            $this->line('→ Neue Klassen sind tentative — im Trendradar prüfen/freigeben.');
        }

        return $fehler > 0 && $written === 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, list<string>> category-slug → Liste bestehender Klassen */
    private function bestehendeKlassen(): array
    {
        $rows = DB::table('foodalchemist_trend_taxonomy')
            ->whereNull('deleted_at')->whereNotNull('trend_class')
            ->get(['category', 'trend_class']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->category][] = $r->trend_class;
        }

        return $out;
    }

    /**
     * Klassen-Zeile (category, trend_class) sicherstellen. Bestehende approved-Klasse
     * gewinnt; neue Klasse wird tentative angelegt.
     *
     * @return array{0:int,1:string,2:bool,3:string} [taxonomy_id, slug, ist_neu, status]
     */
    private function ensureKlasse(string $category, string $label): array
    {
        $slug = $category . '.' . Str::slug($label);
        $vorhanden = DB::table('foodalchemist_trend_taxonomy')
            ->whereNull('team_id')->where('slug', $slug)->first(['id', 'status']);
        if ($vorhanden !== null) {
            return [(int) $vorhanden->id, $slug, false, (string) $vorhanden->status];
        }

        $maxSort = (int) DB::table('foodalchemist_trend_taxonomy')
            ->where('category', $category)->max('sort_order');
        $id = DB::table('foodalchemist_trend_taxonomy')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'team_id' => null,
            'category' => $category,
            'trend_class' => $label,
            'slug' => $slug,
            'description' => null,
            'sort_order' => $maxSort + 10,
            'status' => 'tentative',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(int) $id, $slug, true, 'tentative'];
    }

    /** Kurz-Zusammenfassung aus dem Body (ohne Frontmatter), max ~400 Zeichen. */
    private function summary(string $md): string
    {
        $body = preg_replace('/\A\x{FEFF}?\s*---\R.*?\R---\R?/su', '', $md) ?? $md;
        // Bevorzugt den Zusammenfassungs-Abschnitt, sonst den Anfang.
        if (preg_match('/##\s*Zusammenfassung\s*\R+(.+?)(?:\R##\s|\z)/su', $body, $m)) {
            $body = $m[1];
        }
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        return mb_substr($body, 0, 400);
    }
}
