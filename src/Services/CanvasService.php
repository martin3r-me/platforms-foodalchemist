<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvas;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasTemplate;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasEntry;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
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
        // Spec 82 §9 (Schritt 9): Projekt-Akte im Master-Team. owner_type=projekt, owner_id = Projekt. Die Phasen sind die Gruppen
        // (Reiter); Listen-Felder (`liste` => true) tragen Einträge mit Zusatzfeldern (`sub`: text | zahl | check | kontakt).
        // Die Phasen DNA, Paketlogik und Foodbook haben ihre eigenen Oberflächen (Kunden-DNA, Paketlogik, Foodbook) und hier keine Felder.
        'projekt' => [
            'title' => 'Projekt-Akte (Agentur)',
            'kontext_label' => 'Projekt-Akte (Agentur-Arbeit für diesen Kunden)',
            'felder' => [
                ['key' => 'zielbild_wortlaut', 'label' => 'Zielbild in Kundenworten (wörtlich, nicht interpretiert)', 'group_name' => 'Zielbild & Umfang', 'type' => 'longtext'],
                ['key' => 'zielbild_quelle', 'label' => 'Quelle des Zitats (wer, wann)', 'group_name' => 'Zielbild & Umfang', 'type' => 'text'],
                ['key' => 'umfang_anlaesse', 'label' => 'Anlässe & Flächen', 'group_name' => 'Zielbild & Umfang', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['pax' => ['label' => 'Pax', 'type' => 'zahl'], 'flaeche' => ['label' => 'Fläche', 'type' => 'text']]],
                ['key' => 'umfang_linien', 'label' => 'Linien', 'group_name' => 'Zielbild & Umfang', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['notiz' => ['label' => 'Notiz', 'type' => 'text']]],
                ['key' => 'umfang_bausteine', 'label' => 'Bausteine (Foodbook, Buchungstool, Beratung)', 'group_name' => 'Zielbild & Umfang', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['status' => ['label' => 'Stand', 'type' => 'text']]],
                ['key' => 'rollen', 'label' => 'Rollen im Projekt (Kontakte aus dem CRM)', 'group_name' => 'Zielbild & Umfang', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['rolle' => ['label' => 'Rolle im Projekt', 'type' => 'text'], 'crm_contact_id' => ['label' => 'CRM-Kontakt', 'type' => 'kontakt']]],
                ['key' => 'material', 'label' => 'Material vom Kunden (Checkliste)', 'group_name' => 'Zielbild & Umfang', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['erhalten' => ['label' => 'Erhalten', 'type' => 'check'], 'notiz' => ['label' => 'Notiz', 'type' => 'text']]],
                ['key' => 'termine', 'label' => 'Termine & Protokolle (nur Ergebnisse; geplant wird im Planner)', 'group_name' => 'Zielbild & Umfang', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['datum' => ['label' => 'Datum', 'type' => 'text'], 'ergebnis' => ['label' => 'Ergebnis', 'type' => 'text']]],
                ['key' => 'begehung', 'label' => 'Begehung vor Ort (Checkliste; die Antworten gehören ins Standortprofil)', 'group_name' => 'Standortprofil', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['erledigt' => ['label' => 'Erledigt', 'type' => 'check'], 'antwort' => ['label' => 'Antwort', 'type' => 'text']]],
                ['key' => 'uebergabe', 'label' => 'Übergabe-Checkliste (Rezepturen nach Necta, Buchungsstrecke je Standort, Schulung, Pflege-Rhythmus)', 'group_name' => 'Übergabe & Pflege', 'type' => 'repeatable', 'liste' => true,
                    'sub' => ['erledigt' => ['label' => 'Erledigt', 'type' => 'check'], 'wer' => ['label' => 'Wer', 'type' => 'text']]],
                ['key' => 'lessons_learned', 'label' => 'Abschluss: Lessons Learned', 'group_name' => 'Übergabe & Pflege', 'type' => 'longtext'],
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

    /**
     * Feld-Definitionen eines Canvas-Typs: Kern-Felder (TEMPLATES) PLUS die pflegbaren Zusatzfelder
     * (`canvas_templates`) der Team-Kette bis zur Kundengrenze. Ein Kern-Feld wird nie überschrieben, ein
     * Zusatzfeld mit gleichem Key ignoriert. Zusatzfelder stehen hinter den Kern-Feldern, je Team von oben
     * (Oberteam) nach unten, danach nach `position`. Ohne Team oder ohne Zeilen = nur die Kern-Felder.
     *
     * @return list<array<string, mixed>>
     */
    public function felder(string $type, ?Team $team = null): array
    {
        $felder = $this->template($type)['felder'];
        if ($team === null || ! \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_canvas_templates')) {
            return $felder;
        }
        $kette = app(\Platform\FoodAlchemist\Services\DnaResolver::class)->teamIds($team);   // oben → eigenes Team
        $zeilen = FoodAlchemistCanvasTemplate::query()->where('canvas_type', $type)->where('active', true)
            ->whereIn('team_id', $kette)->get();
        $belegt = array_column($felder, 'key');
        foreach ($kette as $teamId) {
            foreach ($zeilen->where('team_id', $teamId)->sortBy('position') as $z) {
                if (in_array($z->field_key, $belegt, true)) {
                    continue;
                }
                $felder[] = $z->alsFeld();
                $belegt[] = $z->field_key;
            }
        }

        return $felder;
    }

    /** Existierenden Canvas finden (KEIN Create) — für Lese-/Kontext-Pfad. */
    public function find(string $type, string $ownerType, ?int $ownerId): ?FoodAlchemistCanvas
    {
        return FoodAlchemistCanvas::where('canvas_type', $type)
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();
    }

    /**
     * Spec 82 §7 (Vorab-Fix): Food-DNA des Teams MIT Vererbung. Hat das Team selbst keinen Canvas,
     * gilt der des nächsten Eltern-Teams (Unter-Team → Oberteam). Vorher suchte die Kaskade exakt
     * `team_id = eigenes Team` — ein Unter-Team ohne eigene DNA bekam gar keine.
     * Feldweises Überschreiben kommt erst mit Schritt 6; hier gilt: nächster Canvas der Kette gewinnt.
     *
     * Nur bis zur Kundengrenze ({@see \Platform\FoodAlchemist\Services\FaRechte::kundenHauptTeam}): ein
     * Mandant erbt nie die DNA des Master-Teams, auch wenn jemand später ein Eltern-Team setzt.
     */
    public function findFoodDna(Team $team): ?FoodAlchemistCanvas
    {
        $grenze = (int) app(FaRechte::class)->kundenHauptTeam($team)->id;
        $current = $team;
        for ($guard = 0; $current !== null && $guard < 32; $guard++) {   // Zyklen-Schutz
            $canvas = $this->find('food_dna', 'team', (int) $current->id);
            if ($canvas !== null) {
                return $canvas;
            }
            if ((int) $current->id === $grenze) {
                return null;
            }
            $current = $current->parent_team_id ? $current->parentTeam : null;
        }

        return null;
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
    public function werte(FoodAlchemistCanvas $canvas, ?Team $team = null): array
    {
        $out = [];
        // Kern + Zusatzfelder der Team-Kette: das übergebene Team, sonst das Team des Canvas
        $team ??= $canvas->team_id !== null ? Team::find($canvas->team_id) : null;
        foreach ($this->felder($canvas->canvas_type, $team) as $f) {
            if (($f['type'] ?? '') === 'repeatable') {
                $out[$f['key']] = $canvas->entries->where('field_key', $f['key'])->sortBy('position')
                    ->map(fn ($e) => ['id' => $e->id, 'value' => $e->value, 'meta' => $e->meta ?? [], 'is_binding' => (bool) $e->is_binding])->values()->all();
            } else {
                $out[$f['key']] = optional($canvas->entries->firstWhere('field_key', $f['key']))->value;
            }
        }

        return $out;
    }

    /** Welche Skalar-Felder des Canvas sind verbindlich? @return array<string, bool> field_key => true */
    public function verbindlichkeit(FoodAlchemistCanvas $canvas): array
    {
        return $canvas->entries->where('is_binding', true)->where('position', 0)->mapWithKeys(fn ($e) => [$e->field_key => true])->all();
    }

    /**
     * Spec 82 §7: „verbindlich“ setzt nur das OBERTEAM (kein Eltern-Team) und nur mit Admin-Recht; ein Unter-Team
     * ergänzt an verbindlichen Feldern nichts. Wirft RuntimeException bzw. FaRechtFehltException.
     */
    public function pruefeVerbindlichSetzen(Team $team, ?\Platform\Core\Models\User $user): void
    {
        if ($team->parent_team_id !== null) {
            throw new \RuntimeException('Verbindlich setzt nur das Oberteam; ein Unter-Team kann Vorgaben des Oberteams nicht verbindlich machen.');
        }
        app(FaRechte::class)->pruefe($user, $team, \Platform\FoodAlchemist\Enums\FaRolle::Admin, 'Verbindliche DNA-Felder setzen');
    }

    /** Skalare Felder aus einem Formular speichern (leer → Entry löschen). */
    public function setSkalar(FoodAlchemistCanvas $canvas, string $key, ?string $value, ?bool $verbindlich = null): void
    {
        $value = $value !== null && trim($value) !== '' ? trim($value) : null;
        $entry = $canvas->entries()->where('field_key', $key)->where('position', 0)->first();
        if ($value === null) {
            $entry?->delete();

            return;
        }
        // $verbindlich: null = unverändert (Bestand + alle alten Aufrufer), true/false = setzen (Spec 82 §7)
        if ($entry) {
            $entry->update(['value' => $value] + ($verbindlich === null ? [] : ['is_binding' => $verbindlich]));
        } else {
            $canvas->entries()->create(['field_key' => $key, 'position' => 0, 'value' => $value, 'is_binding' => (bool) $verbindlich]);
        }
    }

    public function saveSkalare(FoodAlchemistCanvas $canvas, array $form, ?Team $team = null): void
    {
        $team ??= $canvas->team_id !== null ? Team::find($canvas->team_id) : null;
        foreach ($this->felder($canvas->canvas_type, $team) as $f) {
            if (($f['type'] ?? '') !== 'repeatable' && array_key_exists($f['key'], $form)) {
                $this->setSkalar($canvas, $f['key'], is_scalar($form[$f['key']]) ? (string) $form[$f['key']] : null);
            }
        }
    }

    // ── Repeatable (Geschmackswelten) ──────────────────────────────────
    public function addEntry(FoodAlchemistCanvas $canvas, string $key, string $value, array $meta = [], bool $verbindlich = false): FoodAlchemistCanvasEntry
    {
        $pos = (int) ($canvas->entries()->where('field_key', $key)->max('position') ?? -1) + 1;

        return $canvas->entries()->create(['field_key' => $key, 'position' => $pos, 'value' => trim($value), 'meta' => $meta, 'is_binding' => $verbindlich]);
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
        $team = $canvas->team_id !== null ? Team::find($canvas->team_id) : null;

        return $this->promptKontextAusWerten($canvas->canvas_type, $this->felder($canvas->canvas_type, $team), $this->werte($canvas, $team));
    }

    /**
     * Der Block-Aufbau, einmal für alle: Feld-Definitionen + Werte (Skalar = string|null, Repeatable = Liste
     * von ['value','meta']) → „Kontext-Label:\n- Feld: Wert\n- …“. Aufrufer: promptKontext (ein Canvas) und der
     * DnaResolver (aufgelöste Werte über die Team-Kette). Das Format ist durch den Golden-Test eingefroren.
     *
     * @param  list<array<string, mixed>>  $felder
     * @param  array<string, mixed>  $werte
     */
    public function promptKontextAusWerten(string $type, array $felder, array $werte): ?string
    {
        $tpl = $this->template($type);
        $zeilen = [];
        foreach ($felder as $f) {
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
                    // Phase 4: Tonalität muss WIRKEN — nicht nur der Stil-Name, sondern der
                    // Sprach-Duktus (Prompt-Material) fließt in den KI-Kontext (GL-06).
                    $duktus = trim((string) $stil->sprach_duktus);
                    $zeilen[] = $f['label'] . ': ' . $stil->name . ($duktus !== '' ? ' — Sprach-Duktus: ' . $duktus : '');
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
     * KI-Kaskade: Team-DNA → Kunde-DNA → Angebot → Foodbook → Concept (+ Tonalität des Foodbooks) für die
     * Generierung. Liefert ['marken_kontext' => block] oder [] (nichts injizieren). $crmCompanyId = Endkunde.
     *
     * Seit Spec 82 Schritt 6 nur noch ein ADAPTER: den Block setzt der {@see KontextResolver} zusammen (Team-DNA
     * feldweise, verbindliche Vorgaben, Teamprofil, Gesamtbudget). Die Signatur bleibt, damit die bestehenden
     * Aufrufer (Gateway, Planung, Plan-aus-Brief-Tools) unverändert weiterlaufen; Quellen und Budget-Bilanz liefert
     * `KontextResolver::fuer()` direkt.
     */
    public function cascadeKontext(Team $team, ?int $conceptId = null, ?int $foodbookId = null, ?int $angebotId = null, ?int $crmCompanyId = null): array
    {
        $r = app(KontextResolver::class)->fuer($team, [
            'concept_id' => $conceptId, 'foodbook_id' => $foodbookId, 'angebot_id' => $angebotId, 'crm_company_id' => $crmCompanyId,
        ]);

        return $r['marken_kontext'] === null ? [] : ['marken_kontext' => $r['marken_kontext']];
    }

    /** Zusatzfeld ein-/ausschalten (aus = fließt nirgends ein, Werte bleiben erhalten). Nur das eigene Team. */
    public function zusatzfeldAktiv(Team $team, int $id, bool $aktiv): FoodAlchemistCanvasTemplate
    {
        $feld = FoodAlchemistCanvasTemplate::where('team_id', $team->id)->findOrFail($id);
        $feld->update(['active' => $aktiv]);

        return $feld;
    }

    /** Zusatzfeld löschen (weich). Die Werte in den Canvases bleiben liegen, werden aber nicht mehr gelesen. */
    public function zusatzfeldLoeschen(Team $team, int $id): void
    {
        FoodAlchemistCanvasTemplate::where('team_id', $team->id)->findOrFail($id)->delete();
    }

    /**
     * Pflegbares ZUSATZ-Feld anlegen (Spec 82 §7, Variante A). Die Tabelle hat einen Unique-Index auf
     * (team_id, canvas_type, field_key); der greift bei team_id NULL nicht und zählt gelöschte Zeilen mit —
     * deshalb prüft der Service zusätzlich: Team ist Pflicht, der Key darf kein Kern-Feld sein, ein Duplikat
     * wird abgewiesen, eine gelöschte Zeile mit gleichem Key wird wiederbelebt statt am Index zu scheitern.
     *
     * @param  array{group_name?: ?string, type?: string, sub?: ?array, position?: int}  $opt
     */
    public function zusatzfeldAnlegen(Team $team, string $type, string $key, string $label, array $opt = []): FoodAlchemistCanvasTemplate
    {
        if (! array_key_exists($type, self::TEMPLATES)) {
            throw new \RuntimeException("Unbekannter Canvas-Typ: {$type}");
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,47}$/', $key) !== 1) {
            throw new \RuntimeException('Feld-Key: Kleinbuchstaben, Ziffern und Unterstrich, 2 bis 48 Zeichen, beginnt mit einem Buchstaben.');
        }
        if (in_array($key, array_column(self::TEMPLATES[$type]['felder'], 'key'), true)) {
            throw new \RuntimeException("„{$key}“ ist ein Kern-Feld und kann nicht als Zusatzfeld angelegt werden.");
        }
        $typ = $opt['type'] ?? 'longtext';
        if (! in_array($typ, ['text', 'longtext', 'repeatable'], true)) {
            throw new \RuntimeException('Feld-Typ: text | longtext | repeatable.');
        }
        if (trim($label) === '') {
            throw new \RuntimeException('Ein Zusatzfeld braucht eine Beschriftung.');
        }
        $vorhanden = FoodAlchemistCanvasTemplate::withTrashed()
            ->where('team_id', $team->id)->where('canvas_type', $type)->where('field_key', $key)->first();
        if ($vorhanden !== null && ! $vorhanden->trashed()) {
            throw new \RuntimeException("Das Zusatzfeld „{$key}“ gibt es in diesem Team schon.");
        }
        $werte = [
            'label' => trim($label), 'group_name' => $opt['group_name'] ?? null, 'type' => $typ,
            'sub' => $typ === 'repeatable' ? ($opt['sub'] ?? ['claim' => 'Claim', 'description' => 'Beschreibung']) : null,
            'position' => (int) ($opt['position'] ?? 0), 'active' => true,
        ];
        if ($vorhanden !== null) {
            $vorhanden->restore();
            $vorhanden->update($werte);

            return $vorhanden->refresh();
        }

        return FoodAlchemistCanvasTemplate::create(['team_id' => $team->id, 'canvas_type' => $type, 'field_key' => $key] + $werte);
    }
}
