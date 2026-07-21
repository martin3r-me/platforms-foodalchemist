<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Wording-Kette (UX-Umbau 2026-07-03) — EINE Auflösungslogik für alle
 * kundensichtbaren Anzeigenamen (Concepter-Menü-Ansicht, Foodbook-Editor,
 * Foodbook-Kundendokument):
 *
 *     Foodbook-Block-Override → Concept-Slot.wording → Gericht.sales_wording_standard → Gericht.name
 *
 * Jede Stufe liefert neben dem Text die Quelle mit ('foodbook'|'konzept'|
 * 'standard'|'name'), damit die UIs zeigen können, woher ein Name kommt —
 * 'name' heißt: es würde der INTERNE Pipe-Name drucken → Handlungsbedarf.
 *
 * Per-Gericht-Override im Foodbook liegt in block.payload_json['wording_overrides']
 * (Map slot_id → Text) — Foodbooks komponieren Concepts (BLOCK_TYPES), daher
 * kein eigenes Schema pro Gericht-Zeile nötig. Verwaiste slot_ids (Concept
 * später umgebaut) matchen einfach nicht mehr und sind harmlos.
 */
class WordingResolver
{
    /** @return array{text: string, source: string} */
    public function fuerGericht(?FoodAlchemistRecipe $gericht): array
    {
        if ($gericht === null) {
            return ['text' => '—', 'source' => 'name'];
        }
        $std = trim((string) $gericht->sales_wording_standard);

        // Fallback = interner Pipe-Name. Führenden Gang-/Klassen-Code kappen ([HG]/[KAE]/…),
        // damit er nie in der Kundensicht landet (Dominique 2026-07-21). source bleibt 'name'
        // → die „Wording fehlt"-Amber-Markierung im Editor bleibt erhalten (Handlungssignal).
        return $std !== ''
            ? ['text' => $std, 'source' => 'standard']
            : ['text' => $this->ohneInternenMarker((string) $gericht->name), 'source' => 'name'];
    }

    /** Führenden Gang-/Klassen-Marker `[XX] ` am Zeilenanfang entfernen (nur dort, nie mitten im Text). */
    private function ohneInternenMarker(string $name): string
    {
        return trim(preg_replace('/^\s*\[[A-Z0-9]{1,6}\]\s*/u', '', $name) ?? $name);
    }

    /** @return array{text: string, source: string} */
    public function fuerSlot(FoodAlchemistConceptSlot $slot): array
    {
        $w = trim((string) $slot->wording);
        if ($w !== '') {
            return ['text' => $w, 'source' => 'konzept'];
        }

        return $this->fuerGericht($slot->dish);
    }

    /**
     * Gericht-Zeile INNERHALB eines Foodbook-Blocks (concept_ref):
     * payload_json['wording_overrides'][slot_id] → Slot-Kette.
     *
     * @return array{text: string, source: string}
     */
    public function fuerBlockSlot(FoodAlchemistFoodbookBlock $block, FoodAlchemistConceptSlot $slot): array
    {
        $override = trim((string) (($block->payload_json['wording_overrides'] ?? [])[(string) $slot->id]
            ?? ($block->payload_json['wording_overrides'] ?? [])[$slot->id] ?? ''));
        if ($override !== '') {
            return ['text' => $override, 'source' => 'foodbook'];
        }

        return $this->fuerSlot($slot);
    }

    /**
     * Titel eines Foodbook-Blocks (concept_ref/recipe_ref):
     * block.wording → (Legacy) block.customer_text → Concept-/Gericht-Kette.
     * kundentext bleibt als Fallback, weil Bestandsdaten ihn als Label nutzen —
     * neue Pflege schreibt `wording`, kundentext ist wieder Beschreibungstext.
     *
     * @return array{text: string, source: string}
     */
    public function blockTitel(FoodAlchemistFoodbookBlock $block): array
    {
        $w = trim((string) $block->wording);
        if ($w !== '') {
            return ['text' => $w, 'source' => 'foodbook'];
        }
        $legacy = trim((string) $block->customer_text);
        if ($legacy !== '' && $block->wording === null) {
            return ['text' => $legacy, 'source' => 'foodbook'];
        }

        return match ($block->type) {
            'concept_ref' => ['text' => (string) ($block->concept?->name ?? '—'), 'source' => 'name'],
            'recipe_ref' => $this->fuerGericht($block->dish),
            default => ['text' => (string) ($block->label ?? '—'), 'source' => 'name'],
        };
    }

    /**
     * Kundensichtbare Gericht-Zeilen eines Concepts, in Slot-Reihenfolge —
     * Gericht-Slots als Zeilen, Paket-Slots als Gruppe (Paketname + Gerichte).
     * Struktur-Slots (header/text/spacer) liefern Header als Zwischenzeile.
     *
     * @return list<array{typ: string, text: string, source: ?string, einrueckung: int}>
     */
    public function gerichtZeilen(FoodAlchemistConcept $concept, ?FoodAlchemistFoodbookBlock $block = null): array
    {
        $zeilen = [];
        foreach ($concept->slots->sortBy('position') as $slot) {
            if ($slot->package_id !== null && $slot->package !== null) {
                $zeilen[] = ['type' => 'paket', 'text' => (string) $slot->package->name, 'source' => null, 'einrueckung' => 0];
                foreach ($slot->package->dishes as $pg) {
                    $r = $this->fuerGericht($pg->dish);
                    $zeilen[] = ['type' => 'gericht', 'text' => $r['text'], 'source' => $r['source'], 'einrueckung' => 1, 'recipe_id' => $pg->dish?->id];
                }

                continue;
            }
            if (in_array($slot->type, ['header', 'header_preis'], true) && trim((string) $slot->title) !== '') {
                $zeilen[] = ['type' => 'header', 'text' => (string) $slot->title, 'source' => null, 'einrueckung' => 0];

                continue;
            }
            if ($slot->sales_recipe_id === null || $slot->dish === null) {
                continue; // spacer/text/leere Slots sind im Kundendokument unsichtbar
            }
            $r = $block !== null ? $this->fuerBlockSlot($block, $slot) : $this->fuerSlot($slot);
            $zeilen[] = ['type' => 'gericht', 'text' => $r['text'], 'source' => $r['source'], 'einrueckung' => 0, 'slot_id' => $slot->id, 'recipe_id' => $slot->sales_recipe_id];
        }

        return $zeilen;
    }
}
