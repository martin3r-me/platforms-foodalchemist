<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * W1-1-MESSUNG: ist Wissen JENSEITS des Embedding-Fensters findbar?
 *
 * `KnowledgeEmbeddingService` embeddet ein Doc als `Titel + erste N Zeichen`
 * (DOMAIN_LEAD_CHARS). Bei N = 2000 sind rechnerisch nur 52 % des Korpus im Vektor —
 * 78 % der Dokumente sind länger. Diese Zahl ist aber eine ZEICHEN-Rechnung, keine über
 * FINDBARKEIT. Ohne Messung wäre „Fenster hoch = besser" eine Behauptung.
 *
 * Der Probe misst es direkt: für jedes lange Dokument werden Begriffe gesucht, die
 * AUSSCHLIESSLICH hinter dem Fenster stehen (im Kopf nicht vorkommen), daraus eine
 * Suchanfrage gebaut und geprüft, ob das Dokument dafür in den Top-K auftaucht.
 *
 *   Trifft es NICHT → dieser Teil des Korpus ist semantisch unerreichbar.
 *   Trifft es       → das Fenster deckt ihn ab (oder der Kopf reicht zufällig).
 *
 * Fair und vergleichbar: die Begriffs-Auswahl ist deterministisch (längste, im Kopf
 * fehlende Tokens, sortiert), damit ein Lauf vor und nach der Fenster-Änderung dieselben
 * Anfragen stellt. Nur der Index dazwischen ändert sich.
 *
 * Kosten: eine winzige Query-Einbettung je Stichprobe.
 */
class WissenRecallProbeCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-recall-probe
        {--limit=40 : Anzahl Stichproben-Dokumente}
        {--k=10 : Top-K, in denen das Dokument auftauchen muss}
        {--fenster=2000 : angenommenes Embedding-Fenster (Kopf/Schwanz-Grenze)}
        {--kategorie=* : nur diese Kategorien}
        {--min-score= : Score-Untergrenze der Suche (Default: Service-Wert)}
        {--kontrolle : GEGENPROBE — Anfrage aus dem KOPF (innerhalb des Fensters) bauen}
        {--json : Ergebnis als JSON-Zeile (für vor/nach-Vergleich)}';

    protected $description = 'W1-1: misst, ob Wissen jenseits des Embedding-Fensters semantisch findbar ist';

    /** Kurze/Funktionswörter taugen nicht als Anfrage-Anker. */
    private const MIN_TOKEN = 7;

    private const TOKENS_PRO_ANFRAGE = 6;

    public function handle(KnowledgeEmbeddingService $emb): int
    {
        if (! $emb->isProviderAvailable()) {
            $this->error('Kein Embedding-Provider verfügbar.');

            return self::FAILURE;
        }
        $fenster = max(200, (int) $this->option('fenster'));
        $k = max(1, (int) $this->option('k'));
        $limit = max(1, (int) $this->option('limit'));
        $minScore = $this->option('min-score') !== null && $this->option('min-score') !== ''
            ? (float) $this->option('min-score') : null;

        $q = DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)->whereNull('deleted_at')
            ->where('char_count', '>', $fenster * 1.5);          // klar jenseits des Fensters
        if (($kats = (array) $this->option('kategorie')) !== []) {
            $q->whereIn('category', $kats);
        }
        // Deterministische Stichprobe: nach slug sortiert, nicht zufällig — sonst wären
        // zwei Läufe nicht vergleichbar.
        $docs = $q->orderBy('slug')->limit($limit * 3)->get(['id', 'slug', 'category', 'title', 'content_md', 'char_count']);

        $faelle = [];
        $kontrolle = (bool) $this->option('kontrolle');
        foreach ($docs as $d) {
            $anfrage = $kontrolle
                ? $this->anfrageAusKopf((string) $d->content_md, $fenster)
                : $this->anfrageAusSchwanz((string) $d->content_md, $fenster);
            if ($anfrage === null) {
                continue;                                        // kein brauchbarer Anker im Schwanz
            }
            $faelle[] = ['doc' => $d, 'anfrage' => $anfrage];
            if (count($faelle) >= $limit) {
                break;
            }
        }

        if ($faelle === []) {
            $this->warn('Keine Stichprobe möglich — keine Dokumente mit brauchbaren Begriffen jenseits des Fensters.');

            return self::SUCCESS;
        }

        $treffer = 0;
        $zeilen = [];
        foreach ($faelle as $f) {
            $ids = $emb->searchDocIds($f['anfrage'], $k, $minScore);
            $rang = array_search((int) $f['doc']->id, array_map('intval', $ids), true);
            $ok = $rang !== false;
            $treffer += $ok ? 1 : 0;
            $zeilen[] = ['slug' => $f['doc']->slug, 'kategorie' => $f['doc']->category,
                'zeichen' => (int) $f['doc']->char_count, 'anfrage' => $f['anfrage'],
                'rang' => $ok ? ($rang + 1) : null];
        }

        $quote = $treffer / count($faelle);
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'fenster' => $fenster, 'k' => $k, 'stichproben' => count($faelle),
                'treffer' => $treffer, 'quote' => round($quote, 4), 'faelle' => $zeilen,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(sprintf('W1-1-RECALL-PROBE%s · Fenster %d · Top-%d · %d Stichproben',
            $kontrolle ? ' [GEGENPROBE: Anfrage aus dem KOPF]' : '', $fenster, $k, count($faelle)));
        $this->line('');
        foreach ($zeilen as $z) {
            $this->line(sprintf('  %-46s %6d Z.  %s',
                mb_strimwidth($z['slug'], 0, 46, '…'), $z['zeichen'],
                $z['rang'] !== null ? 'Rang ' . $z['rang'] : '— nicht in Top-' . $k));
        }
        $this->line('');
        if ($kontrolle) {
            $this->info(sprintf('GEGENPROBE — findbar über den KOPF: %d von %d = %.0f %%', $treffer, count($faelle), $quote * 100));
            $this->line('  Ist diese Quote hoch, sind die Dokumente indiziert und die Suche funktioniert.');
            $this->line('  Nur DANN belegt eine Null im Schwanz-Lauf, dass es am Fenster liegt.');
        } else {
            $this->info(sprintf('Findbar jenseits des Fensters: %d von %d = %.0f %%', $treffer, count($faelle), $quote * 100));
            $this->line('  Eine niedrige Quote heisst: dieser Teil des Korpus ist semantisch unerreichbar,');
            $this->line('  egal wie gut die Anfrage ist. Genau das soll ein grösseres Fenster heben.');
            $this->line('  ZUERST --kontrolle fahren: ohne sie ist eine Null nicht interpretierbar.');
        }

        return self::SUCCESS;
    }

    /**
     * Baut eine Anfrage aus Begriffen, die NUR hinter dem Fenster stehen. Deterministisch:
     * längste zuerst, alphabetisch als Tie-Break — damit zwei Läufe dieselbe Frage stellen.
     */
    private function anfrageAusSchwanz(string $inhalt, int $fenster): ?string
    {
        $kopf = mb_strtolower(mb_substr($inhalt, 0, $fenster));
        $schwanz = mb_substr($inhalt, $fenster);
        if (trim($schwanz) === '') {
            return null;
        }
        preg_match_all('/[A-Za-zÄÖÜäöüß]{' . self::MIN_TOKEN . ',}/u', $schwanz, $m);
        $kandidaten = [];
        foreach (array_unique($m[0] ?? []) as $t) {
            if (! str_contains($kopf, mb_strtolower($t))) {
                $kandidaten[] = $t;
            }
        }
        if (count($kandidaten) < 3) {
            return null;
        }
        usort($kandidaten, fn ($a, $b) => [mb_strlen($b), $a] <=> [mb_strlen($a), $b]);

        return implode(' ', array_slice($kandidaten, 0, self::TOKENS_PRO_ANFRAGE));
    }

    /**
     * Gegenprobe: Anker aus dem KOPF, also aus dem Bereich, der sicher im Vektor steht.
     * Absichtlich dieselbe Auswahl-Regel (längste zuerst), damit sich die beiden Läufe
     * nur in der Herkunft der Begriffe unterscheiden — nicht in der Methode.
     */
    private function anfrageAusKopf(string $inhalt, int $fenster): ?string
    {
        $kopf = mb_substr($inhalt, 0, $fenster);
        preg_match_all('/[A-Za-zÄÖÜäöüß]{' . self::MIN_TOKEN . ',}/u', $kopf, $m);
        $kandidaten = array_values(array_unique($m[0] ?? []));
        if (count($kandidaten) < 3) {
            return null;
        }
        usort($kandidaten, fn ($a, $b) => [mb_strlen($b), $a] <=> [mb_strlen($a), $b]);

        return implode(' ', array_slice($kandidaten, 0, self::TOKENS_PRO_ANFRAGE));
    }
}
