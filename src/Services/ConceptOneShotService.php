<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;

/**
 * Spec 50 · Paket C-4 — der Anreicherungs-Pass für ein BESTEHENDES Konzept, das Analogon zu
 * {@see RecipeOneShotService::anreichern()} (Befund B9: für Rezepte gab es den One-Shot, für
 * Concepte nur den Wording-Button in der UI).
 *
 * Zielfelder in zwei Karten — Kopf und Struktur — und Override-First durchgängig: der Pass
 * schneidet seine Schrittfolge aus den LÜCKEN ({@see luecken()}), nie aus dem Bestand. Was ein
 * Mensch oder ein früherer Lauf gesetzt hat, bleibt stehen. Zwei Wege je Zielfeld:
 *
 *  - deterministisch, kein Provider-Call: `target_price_per_person` aus dem Planungs-Gerüst
 *    (Frame-Zielpreis p. P.) — {@see ConceptGeneratorService::uebernehmeKopfAusFrame}.
 *    `price_display` ist bewusst KEIN Zielfeld: die Spalte ist NOT NULL mit DB-Default `gesamt`,
 *    „nicht gesetzt" ist dort nicht messbar — eine Lücke, die nie auftreten kann, wäre ein Etikett, das lügt.
 *  - KI (EIN Call `concept.wording`): `description`, `consumer_name`, `claim`, Titel der
 *    Generator-Header (title === role, {@see ConceptGeneratorService::headerFuerFrameSlot}) und
 *    das Wording gefüllter Positionen — {@see ConceptService::generateWording} mit `nur_luecken`.
 *
 * Run-Protokoll wie beim Rezept: eine Lauf-Zeile `enrich_concept` ({@see BulkRunType::EnrichConcept})
 * mit Schrittfolge + `concept_id` im Kontext, damit „wer hat wann was am Konzept anreichern lassen"
 * dieselbe Antwort hat wie bei Rezepten (`runs.GET`). Ohne KI-Lücke wird kein Lauf angelegt —
 * sonst zählte ein leerer Pass als Provider-Kosten.
 */
class ConceptOneShotService
{
    /** Kopf-Zielfelder — Reihenfolge = Ausgabereihenfolge in `luecken()`. */
    public const ZIELFELDER_KOPF = ['description', 'consumer_name', 'claim', 'target_price_per_person'];

    /** Struktur-Zielfelder (je Slot-Liste). */
    public const ZIELFELDER_STRUKTUR = ['header_titel', 'slot_wording'];

    /** Kopf-Felder, die ohne Provider aus dem Gerüst kommen. */
    public const DETERMINISTISCH = ['target_price_per_person'];

    public function __construct(
        private readonly ConceptService $concepts,
        private readonly ConceptGeneratorService $generator,
        private readonly BulkEnrichService $bulk,
        private readonly PlanningFrameService $frames,
    ) {}

    /**
     * Die Lücken eines Konzepts — was der Pass überhaupt füllen würde.
     *
     * @return array{kopf: list<string>, struktur: array{header_titel: list<int>, slot_wording: list<int>}}
     */
    public function luecken(Team $team, FoodAlchemistConcept $concept): array
    {
        $concept->refresh();
        $concept->load('slots');

        $kopf = [];
        foreach (self::ZIELFELDER_KOPF as $feld) {
            $wert = $concept->{$feld};
            if ($wert === null || (is_string($wert) && trim($wert) === '')) {
                $kopf[] = $feld;
            }
        }

        // Generator-Header (title === role) gelten als „noch nicht betitelt" — von Menschen
        // betitelte Header sind keine Lücke (gleiche Regel wie im Wording-Pass).
        $headerTitel = $concept->slots
            ->filter(fn ($s) => in_array($s->type, ['header', 'header_preis'], true)
                && trim((string) $s->title) !== '' && (string) $s->title === (string) $s->role)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        // Gefüllte Positionen ohne Wording. Leere Positionen sind KEINE Wording-Lücke — sie
        // sind eine Befüllungs-Lücke (concept_slots.PUT), die kein Text schließt.
        $slotWording = $concept->slots
            ->filter(fn ($s) => $s->sales_recipe_id !== null && trim((string) $s->wording) === '')
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return ['kopf' => $kopf, 'struktur' => ['header_titel' => $headerTitel, 'slot_wording' => $slotWording]];
    }

    /**
     * Der Pass. Synchron — es ist höchstens EIN Provider-Call, das braucht keinen Worker.
     * Wirft nie: Fehler landen in `fehler` und am Lauf (`markiereGescheitert`), das Konzept
     * steht unabhängig davon.
     *
     * @return array{run_id: ?int, schritte: list<string>, deterministisch: list<string>, wording: ?array, fehler: ?string, luecken_vorher: array, luecken_nachher: array}
     */
    public function anreichern(Team $team, FoodAlchemistConcept $concept, ?int $writingStyleId = null): array
    {
        $vorher = $this->luecken($team, $concept);

        $ergebnis = [
            'run_id' => null,
            'schritte' => [],
            'deterministisch' => [],
            'wording' => null,
            'fehler' => null,
            'luecken_vorher' => $vorher,
            'luecken_nachher' => $vorher,
        ];

        // 1. Deterministischer Teil: Zielpreis + Preisdarstellung aus dem Gerüst, wenn eines dranhängt.
        $detOffen = array_values(array_intersect($vorher['kopf'], self::DETERMINISTISCH));
        if ($detOffen !== [] && ($frame = $this->frames->find('concept', (int) $concept->id)) !== null) {
            $this->generator->uebernehmeKopfAusFrame($concept, $frame);
            $concept->refresh();
            $ergebnis['deterministisch'] = array_values(array_filter($detOffen, fn ($f) => $concept->{$f} !== null));
        }

        // 2. KI-Teil: Schrittfolge = die KI-fähigen Lücken.
        $schritte = array_values(array_diff($vorher['kopf'], self::DETERMINISTISCH));
        foreach (self::ZIELFELDER_STRUKTUR as $feld) {
            if ($vorher['struktur'][$feld] !== []) {
                $schritte[] = $feld;
            }
        }
        $ergebnis['schritte'] = $schritte;

        if ($schritte !== []) {
            $runId = $this->bulk->laufAnlegen($team, 1, BulkRunType::EnrichConcept, [
                'schritte' => $schritte, 'quelle' => 'one_shot', 'concept_id' => (int) $concept->id,
            ]);
            $ergebnis['run_id'] = $runId;
            try {
                $stil = $writingStyleId ?? ($concept->writing_style_id ? (int) $concept->writing_style_id : null);
                $ergebnis['wording'] = $this->concepts->generateWording($team, (int) $concept->id, $stil, ['nur_luecken' => true]);
                $this->bulk->laufZaehlen($runId, false);
            } catch (\Throwable $e) {
                $ergebnis['fehler'] = mb_strimwidth($e->getMessage(), 0, 300);
                FoodAlchemistBulkRun::markiereGescheitert($runId, $e);
            }
        }

        $ergebnis['luecken_nachher'] = $this->luecken($team, $concept);

        return $ergebnis;
    }
}
