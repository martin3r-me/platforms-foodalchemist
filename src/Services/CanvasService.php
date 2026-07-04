<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvas;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasEntry;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;

/**
 * Zentrales Canvas-Modul — EINE Mechanik für alle Brief-/Markenkern-Ebenen
 * (food_dna|foodbook|concept|angebot). Feste Templates je Typ (TEMPLATES) über generischem
 * Speicher (canvases + canvas_entries). promptKontext() baut den KI-Block je Canvas;
 * cascadeKontext() schichtet Team-DNA → Foodbook → Concept für die Generierung.
 */
class CanvasService
{
    /**
     * Feste Feld-Templates je canvas_type. typ ∈ text|longtext|ref_schreibstil|repeatable.
     * Reihenfolge = Anzeige-Reihenfolge; gruppe = Gliederung im Board.
     */
    public const TEMPLATES = [
        'food_dna' => [
            'titel' => 'Food DNA (Team)',
            'kontext_label' => 'Küchen-/Marken-DNA des Mandanten (verbindlicher Stil-/Geschmacks-Rahmen)',
            'felder' => [
                ['key' => 'leitbild', 'label' => 'Leitbild / Positionierung', 'gruppe' => 'Identität', 'typ' => 'longtext'],
                ['key' => 'signature_stil', 'label' => 'Signature-Stil / Handschrift', 'gruppe' => 'Identität', 'typ' => 'longtext'],
                ['key' => 'ziel_gaeste', 'label' => 'Ziel-Gäste / Anlässe', 'gruppe' => 'Identität', 'typ' => 'longtext'],
                ['key' => 'aromatik', 'label' => 'Aromatik / Leit-Aromen', 'gruppe' => 'Aromatik & Tabus', 'typ' => 'longtext'],
                ['key' => 'no_gos', 'label' => 'No-Gos / Tabus', 'gruppe' => 'Aromatik & Tabus', 'typ' => 'longtext'],
                ['key' => 'qualitaet_leitlinien', 'label' => 'Qualität / Herkunft', 'gruppe' => 'Qualität & Preis', 'typ' => 'longtext'],
                ['key' => 'preis_positionierung', 'label' => 'Preis-Positionierung', 'gruppe' => 'Qualität & Preis', 'typ' => 'text'],
                ['key' => 'default_schreibstil_id', 'label' => 'Default-Schreibstil', 'gruppe' => 'Referenzen', 'typ' => 'ref_schreibstil'],
            ],
        ],
        'foodbook' => [
            'titel' => 'Foodbook-Leitidee',
            'kontext_label' => 'Foodbook-Leitidee (was das Foodbook erfüllen muss)',
            'felder' => [
                ['key' => 'leitidee', 'label' => 'Leitidee / roter Faden', 'gruppe' => 'Idee', 'typ' => 'longtext'],
                ['key' => 'zweck_anforderungen', 'label' => 'Zweck & Anforderungen', 'gruppe' => 'Idee', 'typ' => 'longtext'],
                ['key' => 'pflicht_konzepte', 'label' => 'Pflicht-Konzepte & Formate', 'gruppe' => 'Inhalt', 'typ' => 'longtext'],
                ['key' => 'struktur_kapitel', 'label' => 'Struktur / Kapitel', 'gruppe' => 'Inhalt', 'typ' => 'longtext'],
                ['key' => 'spektrum', 'label' => 'Spektrum (Anlass / Saison)', 'gruppe' => 'Rahmen', 'typ' => 'longtext'],
                ['key' => 'umfang_rahmen', 'label' => 'Umfang / Rahmen', 'gruppe' => 'Rahmen', 'typ' => 'longtext'],
            ],
        ],
        'concept' => [
            'titel' => 'Konzept-Brief (kreativ)',
            'kontext_label' => 'Kreatives Foodkonzept (Leitidee, Inszenierung, Geschmackswelten)',
            'felder' => [
                ['key' => 'name_claim', 'label' => 'Name + Claim', 'gruppe' => 'Idee', 'typ' => 'text'],
                ['key' => 'leitidee', 'label' => 'Leitidee', 'gruppe' => 'Idee', 'typ' => 'longtext'],
                ['key' => 'usp_eignung', 'label' => 'Vorteil / USP + Eignung', 'gruppe' => 'Verkauf', 'typ' => 'longtext'],
                ['key' => 'inszenierung', 'label' => 'Inszenierung & Servierform', 'gruppe' => 'Inszenierung', 'typ' => 'longtext'],
                ['key' => 'intern', 'label' => 'Intern (Constraints / Inklusiv)', 'gruppe' => 'Inszenierung', 'typ' => 'longtext'],
                ['key' => 'geschmackswelten', 'label' => 'Geschmackswelten', 'gruppe' => 'Geschmackswelten', 'typ' => 'repeatable', 'sub' => ['claim' => 'Claim', 'description' => 'Beschreibung']],
            ],
        ],
        'angebot' => [
            'titel' => 'Angebot — Business Case',
            'kontext_label' => 'Kundenprojekt / Business Case dieses Angebots',
            'felder' => [
                ['key' => 'kunde_beziehung', 'label' => 'Kunde & Beziehung', 'gruppe' => 'Kunde & Ziel', 'typ' => 'longtext'],
                ['key' => 'ziel_business_case', 'label' => 'Ziel / Business Case', 'gruppe' => 'Kunde & Ziel', 'typ' => 'longtext'],
                ['key' => 'erfolgskriterien', 'label' => 'Erfolgskriterien', 'gruppe' => 'Kunde & Ziel', 'typ' => 'longtext'],
                ['key' => 'budget', 'label' => 'Budget-Rahmen', 'gruppe' => 'Rahmen', 'typ' => 'text'],
                ['key' => 'zielgruppen', 'label' => 'Zielgruppen', 'gruppe' => 'Positionierung', 'typ' => 'longtext'],
                ['key' => 'rahmen', 'label' => 'Rahmen & Constraints', 'gruppe' => 'Rahmen', 'typ' => 'longtext'],
            ],
        ],
    ];

    public function template(string $type): array
    {
        return self::TEMPLATES[$type] ?? ['titel' => $type, 'kontext_label' => $type, 'felder' => []];
    }

    /** Existierenden Canvas finden (KEIN Create) — für Lese-/Kontext-Pfad. */
    public function find(string $type, string $ownerType, ?int $ownerId): ?FoodAlchemistCanvas
    {
        return FoodAlchemistCanvas::where('canvas_type', $type)
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();
    }

    /** Canvas holen oder anlegen (Edit-Pfad). */
    public function canvasFor(Team $team, string $type, string $ownerType, ?int $ownerId): FoodAlchemistCanvas
    {
        return FoodAlchemistCanvas::firstOrCreate(
            ['canvas_type' => $type, 'owner_type' => $ownerType, 'owner_id' => $ownerId],
            ['team_id' => $team->id, 'status' => 'draft'],
        );
    }

    /**
     * Werte eines Canvas, keyed by field_key. Skalar = string|null; repeatable = Liste
     * von ['id','value','meta'].
     */
    public function werte(FoodAlchemistCanvas $canvas): array
    {
        $out = [];
        foreach ($this->template($canvas->canvas_type)['felder'] as $f) {
            if (($f['typ'] ?? '') === 'repeatable') {
                $out[$f['key']] = $canvas->entries->where('field_key', $f['key'])->sortBy('position')
                    ->map(fn ($e) => ['id' => $e->id, 'value' => $e->value, 'meta' => $e->meta ?? []])->values()->all();
            } else {
                $out[$f['key']] = optional($canvas->entries->firstWhere('field_key', $f['key']))->value;
            }
        }

        return $out;
    }

    /** Skalare Felder aus einem Formular speichern (leer → Entry löschen). */
    public function setSkalar(FoodAlchemistCanvas $canvas, string $key, ?string $value): void
    {
        $value = $value !== null && trim($value) !== '' ? trim($value) : null;
        $entry = $canvas->entries()->where('field_key', $key)->where('position', 0)->first();
        if ($value === null) {
            $entry?->delete();

            return;
        }
        if ($entry) {
            $entry->update(['value' => $value]);
        } else {
            $canvas->entries()->create(['field_key' => $key, 'position' => 0, 'value' => $value]);
        }
    }

    public function saveSkalare(FoodAlchemistCanvas $canvas, array $form): void
    {
        foreach ($this->template($canvas->canvas_type)['felder'] as $f) {
            if (($f['typ'] ?? '') !== 'repeatable' && array_key_exists($f['key'], $form)) {
                $this->setSkalar($canvas, $f['key'], is_scalar($form[$f['key']]) ? (string) $form[$f['key']] : null);
            }
        }
    }

    // ── Repeatable (Geschmackswelten) ──────────────────────────────────
    public function addEntry(FoodAlchemistCanvas $canvas, string $key, string $value, array $meta = []): FoodAlchemistCanvasEntry
    {
        $pos = (int) ($canvas->entries()->where('field_key', $key)->max('position') ?? -1) + 1;

        return $canvas->entries()->create(['field_key' => $key, 'position' => $pos, 'value' => trim($value), 'meta' => $meta]);
    }

    public function updateEntry(int $entryId, string $value, array $meta = []): void
    {
        FoodAlchemistCanvasEntry::where('id', $entryId)->update(['value' => trim($value), 'meta' => $meta]);
    }

    public function removeEntry(int $entryId): void
    {
        FoodAlchemistCanvasEntry::where('id', $entryId)->delete();
    }

    /** KI-Kontext-Block eines Canvas (gefüllte Felder, gelabelt). NULL wenn leer. */
    public function promptKontext(FoodAlchemistCanvas $canvas): ?string
    {
        $tpl = $this->template($canvas->canvas_type);
        $werte = $this->werte($canvas);
        $zeilen = [];
        foreach ($tpl['felder'] as $f) {
            $typ = $f['typ'] ?? 'text';
            if ($typ === 'repeatable') {
                $items = $werte[$f['key']] ?? [];
                if ($items) {
                    $teile = array_map(function ($it) {
                        $claim = $it['meta']['claim'] ?? null;
                        $beschr = $it['meta']['description'] ?? null;
                        return $it['value'] . ($claim ? " ({$claim})" : '') . ($beschr ? " – {$beschr}" : '');
                    }, $items);
                    $zeilen[] = $f['label'] . ': ' . implode('; ', $teile);
                }
            } elseif ($typ === 'ref_schreibstil') {
                $id = $werte[$f['key']] ?? null;
                if ($id !== null && ($stil = FoodAlchemistWritingStyle::find((int) $id)) !== null) {
                    $zeilen[] = $f['label'] . ': ' . $stil->name;
                }
            } else {
                $v = $werte[$f['key']] ?? null;
                if ($v !== null && trim($v) !== '') {
                    $zeilen[] = $f['label'] . ': ' . $v;
                }
            }
        }
        if ($zeilen === []) {
            return null;
        }

        return $tpl['kontext_label'] . ":\n- " . implode("\n- ", $zeilen);
    }

    /**
     * KI-Kaskade: Team-DNA → Foodbook → Concept (→ Angebot) übereinander für die
     * Generierung. Liefert ['marken_kontext' => block] oder [] (nichts injizieren).
     */
    public function cascadeKontext(Team $team, ?int $conceptId = null, ?int $foodbookId = null, ?int $angebotId = null): array
    {
        $bloecke = [];
        $reihen = [
            ['food_dna', 'team', $team->id],
            ['angebot', 'angebot', $angebotId],
            ['foodbook', 'foodbook', $foodbookId],
            ['concept', 'concept', $conceptId],
        ];
        foreach ($reihen as [$type, $ownerType, $ownerId]) {
            if ($ownerId === null) {
                continue;
            }
            $canvas = $this->find($type, $ownerType, $ownerId);
            if ($canvas !== null && ($block = $this->promptKontext($canvas)) !== null) {
                $bloecke[] = $block;
            }
        }

        return $bloecke === [] ? [] : ['marken_kontext' => implode("\n\n", $bloecke)];
    }
}
