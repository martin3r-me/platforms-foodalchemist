<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeChunker;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeSectionizer;

/**
 * W1-5: füllt `foodalchemist_knowledge_sections` + `_chunks`.
 *
 * Die zwei Tabellen existierten seit dem 2026-09-03 als LEERES Schema — ohne Produzenten und
 * ohne Leser. Das ist ein irreführender Zustand: die nächste Session hätte annehmen können,
 * Chunking sei vorhanden. Dieses Kommando ist der Produzent.
 *
 * VORSCHAU IST DEFAULT (wie `wissen-steuerdaten-w0`): die Spec verlangt ausdrücklich, die
 * `kind`-Verteilung mit einem Menschen durchzugehen, BEVOR geschrieben wird — eine
 * Fehlklassifikation als `changelog`/`referenz` nimmt dem Konformitäts-Critic still das
 * Pflichtwissen weg, und `ladeRegelwerke()` merkt es nicht (`isEmpty() → return ''`).
 *
 * Das Embedding hängt NICHT daran. Dieses Kommando erzeugt nur Zeilen; solange
 * `KnowledgeEmbeddingService` weiter Doc-granular embeddet, ändert sich am Retrieval NICHTS.
 * Der Umschalter ist ein eigener, messbarer Schritt mit Kalibrierung und Off-Peak-Re-Index.
 */
class KnowledgeSectionizeCommand extends Command
{
    protected $signature = 'foodalchemist:knowledge-sectionize
        {--apply : Schreiben (ohne dieses Flag nur Vorschau)}
        {--doc= : Nur EIN Doc (slug oder id)}
        {--force : Auch neu zerlegen, wenn die Hashes gleich sind}
        {--verify : Nur prüfen: hat jedes regelwerk-Doc normative Abschnitte?}';

    protected $description = 'W1-5: Wissens-Dokumente in §-genaue Abschnitte + Chunks zerlegen (Vorschau ist Default)';

    public function handle(KnowledgeSectionizer $sectionizer, KnowledgeChunker $chunker): int
    {
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->when($this->option('doc') !== null, function ($q) {
                $d = (string) $this->option('doc');

                return ctype_digit($d) ? $q->where('id', (int) $d) : $q->where('slug', $d);
            })
            ->orderBy('id')
            ->get(['id', 'slug', 'category', 'title', 'content_md', 'team_id']);

        if ($docs->isEmpty()) {
            $this->error('Keine passenden Dokumente.');

            return self::FAILURE;
        }

        if ($this->option('verify')) {
            return $this->verify($docs, $sectionizer);
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'W1-5: SCHREIBE' : 'W1-5: VORSCHAU (--apply zum Schreiben)');

        $kinds = [];
        $summe = ['docs' => 0, 'sections' => 0, 'chunks' => 0, 'chunk_chars' => 0, 'uebersprungen' => 0];
        $luecken = [];

        foreach ($docs as $doc) {
            $abschnitte = $sectionizer->sectionize($doc);
            if ($abschnitte === []) {
                $luecken[] = $doc->slug . ' (0 Abschnitte)';

                continue;
            }

            // Fail-Fast: ein regelwerk-Doc ohne normative Abschnitte würde
            // `ladeRegelwerke(kind='normativ')` still leer laufen lassen.
            if ((string) $doc->category === 'regelwerk'
                && ! collect($abschnitte)->contains(fn ($a) => $a['kind'] === 'normativ')) {
                $luecken[] = $doc->slug . ' (regelwerk OHNE normativ)';
            }

            foreach ($abschnitte as $a) {
                $kinds[$a['kind']] = ($kinds[$a['kind']] ?? 0) + 1;
            }

            $summe['docs']++;

            if (! $apply) {
                // In der Vorschau ohne Section-IDs chunken, nur für die Mengen-Schätzung.
                $chunks = $chunker->chunk($doc, $abschnitte);
                $summe['sections'] += count($abschnitte);
                $summe['chunks'] += count($chunks);
                $summe['chunk_chars'] += array_sum(array_column($chunks, 'char_count'));

                continue;
            }

            // Nur ZÄHLEN, was wirklich geschrieben wurde — ein übersprungenes Doc (Hash
            // gleich) darf die Summe nicht aufblähen, sonst liest der Bericht wie Arbeit,
            // die nicht stattgefunden hat.
            [$sekt, $chunk, $skip] = $this->schreibe($doc, $abschnitte, $chunker);
            $summe['sections'] += $sekt;
            $summe['chunks'] += $chunk;
            $summe['uebersprungen'] += $skip;
        }

        ksort($kinds);
        $this->newLine();
        $this->line('  kind-Verteilung der Abschnitte:');
        foreach ($kinds as $k => $n) {
            $this->line(sprintf('    %-12s %5d', $k, $n));
        }
        $this->newLine();
        $this->line(sprintf('  %d Docs → %d Abschnitte → %d Chunks (%s Zeichen Embed-Text)',
            $summe['docs'], $summe['sections'], $summe['chunks'], number_format($summe['chunk_chars'])));
        if ($summe['uebersprungen'] > 0) {
            $this->line(sprintf('  %d Docs unverändert übersprungen (Hash gleich)', $summe['uebersprungen']));
        }

        if ($luecken !== []) {
            $this->newLine();
            $this->warn('  ⚠ ' . count($luecken) . ' Auffälligkeit(en) — VOR dem Schreiben klären:');
            foreach (array_slice($luecken, 0, 12) as $l) {
                $this->line('    · ' . $l);
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->line('  Nichts geschrieben. Das Retrieval ist unberührt: der Embedding-Pfad');
            $this->line('  bleibt Doc-granular, bis er in einem eigenen Schritt umgestellt wird.');
        }

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int,2:int} sections, chunks, übersprungen */
    private function schreibe(object $doc, array $abschnitte, KnowledgeChunker $chunker): array
    {
        $neueHashes = array_column($abschnitte, 'content_hash');
        $alteHashes = DB::table('foodalchemist_knowledge_sections')
            ->where('knowledge_document_id', $doc->id)->whereNull('deleted_at')
            ->orderBy('ord')->pluck('content_hash')->all();

        if (! $this->option('force') && $alteHashes === $neueHashes) {
            return [0, 0, 1];
        }

        return DB::transaction(function () use ($doc, $abschnitte, $chunker) {
            // Vollständig ersetzen statt zu diffen: die `ord`-UNIQUE macht ein Teil-Update
            // fehleranfällig, und Chunks hängen per FK an den Abschnitten.
            $alteIds = DB::table('foodalchemist_knowledge_sections')
                ->where('knowledge_document_id', $doc->id)->pluck('id');
            if ($alteIds->isNotEmpty()) {
                DB::table('foodalchemist_knowledge_chunks')->whereIn('knowledge_section_id', $alteIds)->delete();
                DB::table('foodalchemist_knowledge_sections')->whereIn('id', $alteIds)->delete();
            }

            $mitIds = [];
            foreach ($abschnitte as $a) {
                $id = DB::table('foodalchemist_knowledge_sections')->insertGetId($a + [
                    'uuid' => (string) Str::uuid(),
                    'knowledge_document_id' => (int) $doc->id,
                    'team_id' => $doc->team_id,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $mitIds[] = $a + ['id' => $id];
            }

            $chunks = $chunker->chunk($doc, $mitIds);
            foreach ($chunks as $c) {
                DB::table('foodalchemist_knowledge_chunks')->insert($c + [
                    'uuid' => (string) Str::uuid(),
                    'team_id' => $doc->team_id,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [count($abschnitte), count($chunks), 0];
        });
    }

    private function verify(\Illuminate\Support\Collection $docs, KnowledgeSectionizer $sectionizer): int
    {
        $fehler = [];
        foreach ($docs->where('category', 'regelwerk') as $doc) {
            $n = DB::table('foodalchemist_knowledge_sections')
                ->where('knowledge_document_id', $doc->id)->where('kind', 'normativ')
                ->whereNull('deleted_at')->count();
            if ($n === 0) {
                $fehler[] = $doc->slug;
            }
        }

        if ($fehler === []) {
            $this->info('✓ Jedes regelwerk-Doc hat normative Abschnitte.');

            return self::SUCCESS;
        }

        $this->error('✗ ' . count($fehler) . ' regelwerk-Doc(s) ohne normative Abschnitte:');
        foreach (array_slice($fehler, 0, 15) as $f) {
            $this->line('    · ' . $f);
        }
        $this->line('  Folge: ladeRegelwerke(kind=normativ) läuft für diese Docs LEER — und');
        $this->line('  ConformanceService merkt es nicht (isEmpty() → return \'\').');

        return self::FAILURE;
    }
}
