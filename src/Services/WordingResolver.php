<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
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
    public function fuerBlockSlot(FoodAlchemistFoodbookBlock|FoodAlchemistFormatSlot $block, FoodAlchemistConceptSlot $slot): array
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
    public function gerichtZeilen(FoodAlchemistConcept $concept, FoodAlchemistFoodbookBlock|FoodAlchemistFormatSlot|null $block = null): array
    {
        // Preisdarstellung (2026-08-25): bei `einzel` trägt jede DIREKTE Gericht-/Package-Zeile ihren
        // eigenen VK (kein Concept-Summenpreis). Die Preise kommen aus der EINEN Preis-Stelle
        // (ConceptService::preisCockpit) — keine Dublette der Darreichungs-/Portions-Mathematik hier.
        // Weil price_display PRO Concept gilt, bleiben Gerichte INNERHALB eingebetteter Pakete
        // automatisch preislos (das Paket ist per Default `gesamt` → die Rekursion hängt nichts an).
        $einzel = $concept->istEinzelpreis();
        $preisMap = [];
        if ($einzel) {
            // Frische Instanz fürs Pricing: der Aufrufer hat `slots.dish` evtl. mit Spalten-Subset OHNE
            // sales_net geladen (dokumentDaten lädt name/sales_wording_standard) — preisCockpit::loadMissing
            // würde die bereits geladene Relation dann NICHT nachladen (Laravel-Subset-Falle) → Preis 0.
            // Slot-IDs sind stabil, der Map-Lookup je $slot->id greift auf dieselben DB-Zeilen.
            $priceConcept = $concept->fresh() ?? $concept;
            foreach (app(ConceptService::class)->preisCockpit($priceConcept)['zeilen'] as $z) {
                if (isset($z['slot_id']) && ($z['price'] ?? null) !== null) {
                    // VK für die Kundensicht, EK für die interne Sicht (Blade rendert EK nur bei $istIntern).
                    $preisMap[(int) $z['slot_id']] = ['vk' => (float) $z['price'], 'ek' => $z['ek'] ?? null];
                }
            }
        }

        $zeilen = [];
        foreach ($concept->slots->sortBy('position') as $slot) {
            // Kaskade 2026-08-24: eingebettetes Paket = kind=paket-Concept (embedded_concept_id).
            // Paketname (consumer_name bevorzugt) + seine Gerichte rekursiv, eingerückt.
            if ($slot->embedded_concept_id !== null && $slot->embeddedConcept !== null) {
                $paket = $slot->embeddedConcept;
                $zeilen[] = ['type' => 'paket', 'text' => (string) ($paket->consumer_name ?: $paket->name), 'source' => null, 'einrueckung' => 0,
                    'preis' => $paket->price_per_person_cache !== null ? (float) $paket->price_per_person_cache : null];
                // Wording-Kette gilt AUCH für die Gerichte des eingebetteten Pakets: der Block wird in die
                // Rekursion durchgereicht, damit foodbook-lokale Overrides (payload_json['wording_overrides']
                // je Paket-Slot-ID) greifen — sonst blieben Paket-Gerichte beim Kapitel-Wording „Wording fehlt".
                foreach ($this->gerichtZeilen($paket, $block) as $z) {
                    $zeilen[] = ['type' => $z['type'], 'text' => $z['text'], 'source' => $z['source'] ?? null,
                        'einrueckung' => ($z['einrueckung'] ?? 0) + 1] + array_intersect_key($z, ['recipe_id' => true, 'slot_id' => true]);
                }

                continue;
            }
            if ($slot->package_id !== null && $slot->package !== null) {
                $zeilen[] = ['type' => 'paket', 'text' => (string) $slot->package->name, 'source' => null, 'einrueckung' => 0,
                    'preis' => $einzel ? ($preisMap[$slot->id]['vk'] ?? null) : null,
                    'ek' => $einzel ? ($preisMap[$slot->id]['ek'] ?? null) : null];
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
            $zeilen[] = ['type' => 'gericht', 'text' => $r['text'], 'source' => $r['source'], 'einrueckung' => 0, 'slot_id' => $slot->id, 'recipe_id' => $slot->sales_recipe_id,
                'preis' => $einzel ? ($preisMap[$slot->id]['vk'] ?? null) : null,
                'ek' => $einzel ? ($preisMap[$slot->id]['ek'] ?? null) : null];
        }

        return $zeilen;
    }
}
