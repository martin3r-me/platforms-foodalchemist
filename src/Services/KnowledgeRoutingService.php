<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;

/**
 * S1b (Wissens-Skalierbarkeit, 2026-08-07): Routing zur LAUFZEIT pflegbar. Bisher lag die
 * Zuordnung „welches KI-Feature lädt welche Wissens-Kategorie in welchem Modus" nur im Code
 * (KnowledgeImportCommand::seedRoutings + Migrationen) — eine neue Kategorie zu routen brauchte
 * einen Deploy. Dieser stateless Service ist die EINE Schreibstelle auf `foodalchemist_knowledge_routings`
 * (genutzt von den MCP-Tools knowledge_routings.GET/PUT), damit Dominique/Agenten eine Kategorie
 * ohne Deploy routen/deckeln können. Identität = (feature, category): eine Kategorie hat je Feature
 * genau EINEN Modus. Wirkt sofort beim nächsten KnowledgeContextService::contextFor.
 */
class KnowledgeRoutingService
{
    private const T = 'foodalchemist_knowledge_routings';

    /** Erlaubte Modi (Spiegel der seedRoutings/Migrationen). `none` = bewusst leer (kein Auto-Grounding). */
    public const MODES = ['always', 'discovery', 'grounding', 'none'];

    /**
     * Alle Routings, optional auf ein Feature gefiltert.
     *
     * @return list<array{feature:string, category:string, mode:string, max_docs:?int, max_chars_per_doc:?int}>
     */
    public function list(?string $feature = null): array
    {
        return DB::table(self::T)
            ->when($feature !== null && $feature !== '', fn ($q) => $q->where('feature', $feature))
            ->orderBy('feature')->orderBy('category')
            ->get(['feature', 'category', 'mode', 'max_docs', 'max_chars_per_doc'])
            ->map(fn ($r) => [
                'feature' => (string) $r->feature,
                'category' => (string) $r->category,
                'mode' => (string) $r->mode,
                'max_docs' => $r->max_docs !== null ? (int) $r->max_docs : null,
                'max_chars_per_doc' => $r->max_chars_per_doc !== null ? (int) $r->max_chars_per_doc : null,
            ])->all();
    }

    /**
     * Routing setzen (upsert auf feature+category). `null`-Caps = Service-Default greift.
     *
     * @return array{feature:string, category:string, mode:string, max_docs:?int, max_chars_per_doc:?int}
     */
    public function set(string $feature, string $category, string $mode, ?int $maxDocs = null, ?int $maxChars = null): array
    {
        $feature = trim($feature);
        $category = trim($category);
        $mode = trim($mode);
        if ($feature === '' || $category === '') {
            throw new \InvalidArgumentException('feature und category sind Pflicht.');
        }
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Ungültiger Modus [{$mode}] — erlaubt: " . implode(' | ', self::MODES) . '.');
        }
        $maxDocs = $maxDocs !== null && $maxDocs > 0 ? $maxDocs : null;
        $maxChars = $maxChars !== null && $maxChars > 0 ? $maxChars : null;

        $now = now()->toDateTimeString();
        $row = DB::table(self::T)->where('feature', $feature)->where('category', $category)->first();
        if ($row !== null) {
            DB::table(self::T)->where('id', $row->id)->update([
                'mode' => $mode, 'max_docs' => $maxDocs, 'max_chars_per_doc' => $maxChars, 'updated_at' => $now,
            ]);
        } else {
            DB::table(self::T)->insert([
                'feature' => $feature, 'category' => $category, 'mode' => $mode,
                'max_docs' => $maxDocs, 'max_chars_per_doc' => $maxChars,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return [
            'feature' => $feature, 'category' => $category, 'mode' => $mode,
            'max_docs' => $maxDocs, 'max_chars_per_doc' => $maxChars,
        ];
    }

    /** Routing entfernen (feature+category) → search-only (keine Auto-Grounding-Zeile mehr). Anzahl gelöschter Zeilen. */
    public function remove(string $feature, string $category): int
    {
        return (int) DB::table(self::T)
            ->where('feature', trim($feature))->where('category', trim($category))->delete();
    }
}
