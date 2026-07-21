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
            'title' => 'Food DNA (Team)',
            'kontext_label' => 'Küchen-/Marken-DNA des Mandanten (verbindlicher Stil-/Geschmacks-Rahmen)',
            'felder' => [
                ['key' => 'leitbild', 'label' => 'Leitbild / Positionierung', 'group_name' => 'Identität', 'type' => 'longtext'],
                ['key' => 'signature_stil', 'label' => 'Signature-Stil / Handschrift', 'group_name' => 'Identität', 'type' => 'longtext'],
                ['key' => 'ziel_gaeste', 'label' => 'Ziel-Gäste / Anlässe', 'group_name' => 'Identität', 'type' => 'longtext'],
                ['key' => 'aromatik', 'label' => 'Aromatik / Leit-Aromen', 'group_name' => 'Aromatik & Tabus', 'type' => 'longtext'],
                ['key' => 'no_gos', 'label' => 'No-Gos / Tabus', 'group_name' => 'Aromatik & Tabus', 'type' => 'longtext'],
                ['key' => 'qualitaet_leitlinien', 'label' => 'Qualität / Herkunft', 'group_name' => 'Qualität & Preis', 'type' => 'longtext'],
                ['key' => 'preis_positionierung', 'label' => 'Preis-Positionierung', 'group_name' => 'Qualität & Preis', 'type' => 'text'],
                ['key' => 'default_schreibstil_id', 'label' => 'Default-Schreibstil', 'group_name' => 'Referenzen', 'type' => 'ref_schreibstil'],
            ],
        ],
        // Ebene 2 der DNA-Kette (Team → KUNDE → Foodbook): stabile Wer-/Kommunikations-Identität
        // des Endkunden, hängt am CRM-Kunden (owner_type=crm_company). Fließt in jedes Foodbook
        // dieses Kunden, ohne neu getippt zu werden.
        'kunde_dna' => [
            'title' => 'Kunde-DNA (CRM)',
            'kontext_label' => 'Kunde/Marke des Endkunden (stabiler Wer-/Kommunikations-Rahmen dieses Kunden)',
            'felder' => [
                ['key' => 'marke_positionierung', 'label' => 'Marke / Positionierung', 'group_name' => 'Wer', 'type' => 'longtext'],
                ['key' => 'ziel_gaeste_anlaesse', 'label' => 'Ziel-Gäste & Anlässe', 'group_name' => 'Wer', 'type' => 'longtext'],
                ['key' => 'kommunikation_ton', 'label' => 'Kommunikation / Ton', 'group_name' => 'Stimme', 'type' => 'longtext'],
                ['key' => 'default_schreibstil_id', 'label' => 'Default-Schreibstil', 'group_name' => 'Stimme', 'type' => 'ref_schreibstil'],
                ['key' => 'erwartungen_nogos', 'label' => 'Erwartungen / No-Gos', 'group_name' => 'Rahmen', 'type' => 'longtext'],
                ['key' => 'preis_erwartung', 'label' => 'Preis-Erwartung', 'group_name' => 'Rahmen', 'type' => 'text'],
            ],
        ],
        'foodbook' => [
            'title' => 'Foodbook-Leitidee',
            'kontext_label' => 'Foodbook-Leitidee (was das Foodbook erfüllen muss)',
            'felder' => [
                ['key' => 'leitidee', 'label' => 'Leitidee / roter Faden', 'group_name' => 'Idee', 'type' => 'longtext'],
                ['key' => 'zweck_anforderungen', 'label' => 'Zweck & Anforderungen', 'group_name' => 'Idee', 'type' => 'longtext'],
                ['key' => 'pflicht_konzepte', 'label' => 'Pflicht-Konzepte & Formate', 'group_name' => 'Inhalt', 'type' => 'longtext'],
                ['key' => 'struktur_kapitel', 'label' => 'Struktur / Kapitel', 'group_name' => 'Inhalt', 'type' => 'longtext'],
                ['key' => 'spektrum', 'label' => 'Spektrum (Anlass / Saison)', 'group_name' => 'Rahmen', 'type' => 'longtext'],
                ['key' => 'umfang_rahmen', 'label' => 'Umfang / Rahmen', 'group_name' => 'Rahmen', 'type' => 'longtext'],
            ],
        ],
        'concept' => [
            'title' => 'Konzept-Brief (kreativ)',
            'kontext_label' => 'Kreatives Foodkonzept (Leitidee, Inszenierung, Geschmackswelten)',
            'felder' => [
                ['key' => 'name_claim', 'label' => 'Name + Claim', 'group_name' => 'Idee', 'type' => 'text'],
                ['key' => 'leitidee', 'label' => 'Leitidee', 'group_name' => 'Idee', 'type' => 'longtext'],
                ['key' => 'usp_eignung', 'label' => 'Vorteil / USP + Eignung', 'group_name' => 'Verkauf', 'type' => 'longtext'],
                ['key' => 'inszenierung', 'label' => 'Inszenierung & Servierform', 'group_name' => 'Inszenierung', 'type' => 'longtext'],
                ['key' => 'intern', 'label' => 'Intern (Constraints / Inklusiv)', 'group_name' => 'Inszenierung', 'type' => 'longtext'],
                ['key' => 'geschmackswelten', 'label' => 'Geschmackswelten', 'group_name' => 'Geschmackswelten', 'type' => 'repeatable', 'sub' => ['claim' => 'Claim', 'description' => 'Beschreibung']],
            ],
        ],
        'angebot' => [
            'title' => 'Angebot — Business Case',
            'kontext_label' => 'Kundenprojekt / Business Case dieses Angebots',
            'felder' => [
                ['key' => 'kunde_beziehung', 'label' => 'Kunde & Beziehung', 'group_name' => 'Kunde & Ziel', 'type' => 'longtext'],
                ['key' => 'ziel_business_case', 'label' => 'Ziel / Business Case', 'group_name' => 'Kunde & Ziel', 'type' => 'longtext'],
                ['key' => 'erfolgskriterien', 'label' => 'Erfolgskriterien', 'group_name' => 'Kunde & Ziel', 'type' => 'longtext'],
                ['key' => 'budget', 'label' => 'Budget-Rahmen', 'group_name' => 'Rahmen', 'type' => 'text'],
                ['key' => 'zielgruppen', 'label' => 'Zielgruppen', 'group_name' => 'Positionierung', 'type' => 'longtext'],
                ['key' => 'rahmen', 'label' => 'Rahmen & Constraints', 'group_name' => 'Rahmen', 'type' => 'longtext'],
            ],
        ],
    ];

    public function template(string $type): array
    {
        return self::TEMPLATES[$type] ?? ['title' => $type, 'kontext_label' => $type, 'felder' => []];
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
            if (($f['type'] ?? '') === 'repeatable') {
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
            if (($f['type'] ?? '') !== 'repeatable' && array_key_exists($f['key'], $form)) {
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
            $typ = $f['type'] ?? 'text';
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
     * KI-Kaskade: Team-DNA → Kunde-DNA → Angebot → Foodbook → Concept übereinander für die
     * Generierung (jede Ebene spezialisiert die darüber). Liefert ['marken_kontext' => block]
     * oder [] (nichts injizieren). $crmCompanyId = Endkunde (Ebene 2, aus foodbook.crm_company_id).
     */
    public function cascadeKontext(Team $team, ?int $conceptId = null, ?int $foodbookId = null, ?int $angebotId = null, ?int $crmCompanyId = null): array
    {
        $bloecke = [];
        $reihen = [
            ['food_dna', 'team', $team->id],
            ['kunde_dna', 'crm_company', $crmCompanyId],
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
