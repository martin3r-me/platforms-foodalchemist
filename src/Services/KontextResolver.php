<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Models\FoodAlchemistProjektStandort;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Support\MachbarkeitKontext;
use Platform\FoodAlchemist\Support\Profiltraeger;

/**
 * Spec 82 · Schritt 6 (§7, §12 E) — der EINE Ort, an dem der KI-Marken-Kontext zusammengesetzt wird.
 *
 * Aufrufer übergeben das Objekt (Foodbook, Angebot, Concept, Speisekarte) bzw. dessen Ids; die Ebenen löst
 * der Resolver selbst auf. Reihenfolge laut §7 — allgemein zuerst, speziell zuletzt (statisch vor objekt-
 * spezifisch, deterministisch: kein Zeitstempel, damit der Prompt-Cache trägt):
 *
 *  1. Verbindliche Vorgaben des Oberteams (aus der Team-DNA, nie gekürzt)
 *  2. Team-DNA (feldweise über die Team-Kette, {@see DnaResolver}) — ohne die schon unter 1 genannten Felder
 *  3. Teamprofil (nur AUSDRÜCKLICHE Fakten; „unbekannt" ist nie „fehlt")
 *  4. Endkunde — nur wenn die Ausgabe eine CRM-Firma trägt (Angebot immer; Entscheidung 4, Dominique 13.10.)
 *  5. Angebot → Foodbook → Concept (spezifischer = später) und die Tonalität des Foodbooks (nie gekürzt)
 *
 * Spec 82 §9 (Agentur-Akte): Kaskade Team → Standort → Kunde → Projekt. Mit `projekt_id` kommen der Standort (Pilot oder `standort_id`)
 * und der Projekt-Block dazu. „Bleibt“ des Kunden (verbindliche Kunden-DNA) steht GANZ VORN und hat bei Widerspruch VORRANG vor den
 * verbindlichen Vorgaben des Teams (Entscheidung Dominique 13.10.); der Widerspruch wird nicht still aufgelöst, die KI benennt ihn im Ergebnis.
 *
 * GESAMTBUDGET statt eines Deckels je Ebene (`foodalchemist.kontext.budget_zeichen`, Standard 12.000): Verbindliches
 * und die Tonalität werden nie gekürzt; ist der Rest zu lang, wird von UNSPEZIFISCH nach SPEZIFISCH gekürzt
 * (Team-DNA zuerst, Concept zuletzt) und die Bilanz sagt, was fehlt.
 *
 * Reine Lese-Logik. Ohne Teamprofil/Verbindliches ist der Block für einen Ein-Team-Mandanten byte-gleich zum
 * früheren `CanvasService::cascadeKontext` (Golden-Test), bis auf das aufgehobene 3.000er-Ebenen-Limit.
 */
class KontextResolver
{
    public const BUDGET_STANDARD = 12000;

    /** Kürzungs-Marke am Ende eines gekürzten Blocks (wie bisher am Ebenen-Deckel). */
    private const KUERZUNG = "\n- […gekürzt für KI-Kontext…]";

    /** Ein Rest unter dieser Länge lohnt kein gekürztes Stück: der Block entfällt ganz. */
    private const MIN_REST = 120;

    public function __construct(
        private CanvasService $canvas,
        private DnaResolver $dna,
        private TeamProfilService $profil,
    ) {
    }

    /**
     * Ids statt Objekten (die bestehenden Aufrufer): `concept_id`, `foodbook_id`, `angebot_id`, `crm_company_id`.
     *
     * @param  array{concept_id?: ?int, foodbook_id?: ?int, angebot_id?: ?int, crm_company_id?: ?int, projekt_id?: ?int, standort_id?: ?int}  $ids
     * @return array{marken_kontext: ?string, quellen: list<array<string, mixed>>, bilanz: array<string, mixed>}
     */
    public function fuer(Team $team, array $ids = [], ?MachbarkeitKontext $kontext = null): array
    {
        $ziel = $kontext?->ziel ?? $team;
        $bloecke = [];   // [ebene, label, text, geschuetzt, rang]

        // Kunde zuerst auflösen: „Bleibt“ steht ganz vorn (Vorrang vor dem Team, Spec 82 §9), der Rest später an Rang 3
        $kundeBleibt = null;
        $kundeText = null;
        $kundeId = $ids['crm_company_id'] ?? null;
        if ($kundeId !== null) {
            $kundenCanvas = $this->canvas->find('kunde_dna', 'crm_company', (int) $kundeId);
            if ($kundenCanvas !== null && $this->canvas->verbindlichkeit($kundenCanvas) !== []) {
                [$kundeBleibt, $kundeText] = $this->kundeGetrennt($kundenCanvas);
            } elseif ($kundenCanvas !== null) {
                $kundeText = $this->canvas->promptKontext($kundenCanvas);
            }
        }
        if ($kundeBleibt !== null) {
            $bloecke[] = ['ebene' => 'kunde_bleibt', 'label' => 'Kunde: Bleibt', 'geschuetzt' => true, 'rang' => 0,
                'text' => "Verbindliche Vorgaben des Kunden (Bleibt, nicht abweichen). Für Ausgaben dieses Kunden gehen sie VOR den Vorgaben des Teams:\n- ".implode("\n- ", $kundeBleibt)
                    ."\n- Widerspricht eine Vorgabe des Teams einer dieser Kunden-Vorgaben, gilt die Kunden-Vorgabe; benenne den Widerspruch im Ergebnis ausdrücklich."];
        }

        // 1 + 2 — Team-DNA feldweise; Verbindliches als eigener, ungekürzter Block
        $dna = $this->dna->foodDna($ziel);
        $verbindlich = $this->verbindlicheZeilen($dna);
        if ($verbindlich !== []) {
            $bloecke[] = ['ebene' => 'vorgaben', 'label' => 'Verbindliche Vorgaben', 'geschuetzt' => true, 'rang' => 0,
                'text' => "Verbindliche Vorgaben des Oberteams (nicht abweichen):\n- ".implode("\n- ", $verbindlich)
                    .($kundeBleibt !== null ? "\n- Vorbehalt: Bei Widerspruch zu den Kunden-Vorgaben (Bleibt) gelten für diesen Kunden die Kunden-Vorgaben." : '')];
        }
        $felder = [];
        $werte = [];
        foreach ($dna as $key => $zeile) {
            if ($zeile['verbindlich']) {
                continue;   // steht schon unter den Vorgaben
            }
            $felder[] = $zeile['feld'];
            $werte[$key] = $zeile['wert'];
        }
        $dnaText = $this->canvas->promptKontextAusWerten('food_dna', $felder, $werte);
        if ($dnaText !== null) {
            $bloecke[] = ['ebene' => 'team_dna', 'label' => 'Team-DNA', 'geschuetzt' => false, 'rang' => 1, 'text' => $dnaText];
        }

        // 3 — Teamprofil
        $profilText = $this->profilBlock($ziel, $kontext);
        if ($profilText !== null) {
            $bloecke[] = ['ebene' => 'teamprofil', 'label' => 'Teamprofil', 'geschuetzt' => false, 'rang' => 2, 'text' => $profilText];
        }

        // 3b — Standort (Agentur-Akte: Kunden-Standort des Projekts, Pilot oder ausdrücklich `standort_id`)
        $projekt = ($ids['projekt_id'] ?? null) !== null ? FoodAlchemistProjekt::where('team_id', $ziel->id)->find((int) $ids['projekt_id']) : null;
        $standort = $this->standortFuer($ziel, $projekt, $ids['standort_id'] ?? null);
        if ($standort !== null) {
            $standortText = $this->profilBlock(Profiltraeger::standort($ziel, (int) $standort->id), $kontext,
                'Küchen-Fakten des Kunden-Standorts '.$standort->name.' (Standortprofil, ausdrücklich gepflegt)');
            if ($standortText !== null) {
                $bloecke[] = ['ebene' => 'standort', 'label' => 'Standortprofil', 'geschuetzt' => false, 'rang' => 2, 'text' => $standortText];
            }
        }

        // 4 — Kunde (Rest ohne „Bleibt“), dann Projekt-Akte, Angebot, Foodbook, Concept (jeweils nur mit Id)
        if ($kundeText !== null) {
            $bloecke[] = ['ebene' => 'kunde', 'label' => 'Kunde', 'geschuetzt' => false, 'rang' => 3, 'text' => $kundeText];
        }
        $projektText = $projekt !== null ? $this->projektBlock($projekt) : null;
        if ($projektText !== null) {
            $bloecke[] = ['ebene' => 'projekt', 'label' => 'Projekt-Akte', 'geschuetzt' => false, 'rang' => 3, 'text' => $projektText];
        }
        $reihen = [
            ['angebot', 'angebot', $ids['angebot_id'] ?? null, 'angebot', 'Angebot', 4],
            ['foodbook', 'foodbook', $ids['foodbook_id'] ?? null, 'foodbook', 'Foodbook', 5],
            ['concept', 'concept', $ids['concept_id'] ?? null, 'concept', 'Concept', 6],
        ];
        foreach ($reihen as [$type, $ownerType, $ownerId, $ebene, $label, $rang]) {
            if ($ownerId === null) {
                continue;
            }
            $canvas = $this->canvas->find($type, $ownerType, (int) $ownerId);
            if ($canvas !== null && ($text = $this->canvas->promptKontext($canvas)) !== null) {
                $bloecke[] = ['ebene' => $ebene, 'label' => $label, 'geschuetzt' => false, 'rang' => $rang, 'text' => $text];
            }
        }

        // Tonalität des Foodbooks: führt, überschreibt die Default-Schreibstile — letzter Block, nie gekürzt
        $tonalitaet = $this->tonalitaet($ids['foodbook_id'] ?? null);
        if ($tonalitaet !== null) {
            $bloecke[] = ['ebene' => 'tonalitaet', 'label' => 'Tonalität', 'geschuetzt' => true, 'rang' => 7, 'text' => $tonalitaet];
        }

        // Anlass (Gästezahl/Termin) aus dem Ausführungskontext, nur wenn übergeben
        $anlass = $this->anlassZeile($ziel, $kontext);
        if ($anlass !== null) {
            $bloecke[] = ['ebene' => 'anlass', 'label' => 'Anlass', 'geschuetzt' => true, 'rang' => 8, 'text' => $anlass];
        }

        return $this->mitBudget($bloecke);
    }

    /**
     * Objekt statt Ids: Foodbook, Angebot, Concept oder Speisekarte. Die Kunden-Ebene greift nur, wenn das Objekt
     * eine CRM-Firma trägt (Angebot: `crm_company_id` ist dort immer das Kundenfeld).
     */
    public function fuerObjekt(Team $team, object $objekt, ?MachbarkeitKontext $kontext = null): array
    {
        $ids = match (true) {
            $objekt instanceof FoodAlchemistFoodbook => ['foodbook_id' => (int) $objekt->id, 'crm_company_id' => $objekt->crm_company_id, 'projekt_id' => $objekt->projekt_id],
            $objekt instanceof FoodAlchemistFoodbookKapitel => ['foodbook_id' => (int) $objekt->foodbook_id, 'crm_company_id' => $objekt->foodbook?->crm_company_id, 'projekt_id' => $objekt->foodbook?->projekt_id],
            $objekt instanceof FoodAlchemistAngebot => ['angebot_id' => (int) $objekt->id, 'crm_company_id' => $objekt->crm_company_id],
            $objekt instanceof FoodAlchemistConcept => ['concept_id' => (int) $objekt->id],
            isset($objekt->crm_company_id) => ['crm_company_id' => $objekt->crm_company_id],   // Speisekarte & Co.: nur die Kunden-Ebene
            default => [],
        };

        return $this->fuer($team, array_map(static fn ($v) => $v === null ? null : (int) $v, $ids), $kontext);
    }

    /** Budget aus der Konfiguration (Zeichen). */
    public function budget(): int
    {
        return max(1000, (int) config('foodalchemist.kontext.budget_zeichen', self::BUDGET_STANDARD));
    }

    /**
     * Kunden-DNA in „Bleibt“ (verbindliche Felder) und Rest teilen, beide im Format des Canvas-Blocks.
     *
     * @return array{0: ?list<string>, 1: ?string}
     */
    private function kundeGetrennt(\Platform\FoodAlchemist\Models\FoodAlchemistCanvas $canvas): array
    {
        $team = $canvas->team_id !== null ? \Platform\Core\Models\Team::find($canvas->team_id) : null;
        $felder = $this->canvas->felder($canvas->canvas_type, $team);
        $werte = $this->canvas->werte($canvas, $team);
        $bindung = $this->canvas->verbindlichkeit($canvas);
        $bleibtFelder = array_values(array_filter($felder, static fn ($f) => isset($bindung[$f['key']])));
        $restFelder = array_values(array_filter($felder, static fn ($f) => ! isset($bindung[$f['key']])));
        $bleibtText = $this->canvas->promptKontextAusWerten($canvas->canvas_type, $bleibtFelder, $werte);

        return [
            $bleibtText === null ? null : array_slice(explode("\n- ", $bleibtText), 1),
            $this->canvas->promptKontextAusWerten($canvas->canvas_type, $restFelder, $werte),
        ];
    }

    /** Kunden-Standort für die Kaskade: ausdrücklich `standort_id`, sonst der Pilot-Standort des Projekts. Nur im Team des Projekts. */
    private function standortFuer(Team $ziel, ?FoodAlchemistProjekt $projekt, ?int $standortId): ?FoodAlchemistProjektStandort
    {
        $id = $standortId ?? $projekt?->pilot_standort_id;
        if ($id === null) {
            return null;
        }

        return FoodAlchemistProjektStandort::where('team_id', $ziel->id)
            ->when($projekt !== null, fn ($q) => $q->where('projekt_id', $projekt->id))->find((int) $id);
    }

    /**
     * Projekt-Block (Agentur-Akte): nur, was eine Erzeugung für den Kunden braucht — Zielbild in Kundenworten, Ziel, Anlässe mit Gästezahl,
     * Paketstufen, Stufe × Anlass und die Musterseite. Rollen, Termine, Material, Begehung und Übergabe sind Agentur-intern und bleiben draußen.
     */
    private function projektBlock(FoodAlchemistProjekt $p): ?string
    {
        $canvas = $this->canvas->find('projekt', 'projekt', (int) $p->id);
        $e = $canvas?->entries ?? collect();
        $liste = fn (string $key) => $e->where('field_key', $key)->sortBy('position')->filter(fn ($x) => trim((string) $x->value) !== '');
        $meta = fn ($x, string $k) => trim((string) ($x->meta[$k] ?? ''));
        $zeilen = [];
        if (($wort = trim((string) $e->firstWhere('field_key', 'zielbild_wortlaut')?->value)) !== '') {
            $zeilen[] = 'Zielbild in Kundenworten: '.$wort;
        }
        if (trim((string) $p->ziel) !== '') {
            $zeilen[] = 'Ziel: '.trim((string) $p->ziel);
        }
        if ($liste('umfang_anlaesse')->isNotEmpty()) {
            $zeilen[] = 'Anlässe: '.$liste('umfang_anlaesse')->map(fn ($x) => $x->value.($meta($x, 'pax') !== '' ? ' ('.$meta($x, 'pax').' Gäste)' : ''))->implode('; ');
        }
        if ($liste('paket_stufen')->isNotEmpty()) {
            $zeilen[] = 'Paketstufen: '.$liste('paket_stufen')->map(fn ($x) => $x->value
                .($meta($x, 'beschreibung') !== '' ? ' – '.$meta($x, 'beschreibung') : '').($meta($x, 'preis') !== '' ? ' ('.$meta($x, 'preis').' je Gast)' : ''))->implode('; ');
        }
        if ($liste('paket_anlaesse')->isNotEmpty()) {
            $zeilen[] = 'Anlässe der Paketlogik: '.$liste('paket_anlaesse')->pluck('value')->implode(', ');
        }
        if ($liste('paket_matrix')->isNotEmpty()) {
            $zeilen[] = 'Stufe × Anlass: '.$liste('paket_matrix')->map(fn ($x) => trim($meta($x, 'stufe').' × '.$meta($x, 'anlass'), ' ×').': '.($meta($x, 'inhalt') !== '' ? $meta($x, 'inhalt') : $x->value))->implode('; ');
        }
        if (($muster = trim((string) $e->firstWhere('field_key', 'musterseite')?->value)) !== '') {
            $zeilen[] = 'Musterseite: '.$muster;
        }

        return $zeilen === [] ? null : "Projekt-Akte (Agentur-Arbeit für diesen Kunden):\n- ".implode("\n- ", $zeilen);
    }

    /** @return list<string> */
    private function verbindlicheZeilen(array $dna): array
    {
        $zeilen = [];
        foreach ($dna as $zeile) {
            if (! $zeile['verbindlich']) {
                continue;
            }
            $feld = $zeile['feld'];
            if (($feld['type'] ?? '') === 'repeatable') {
                $items = array_filter($zeile['wert'], static fn ($i) => $i['verbindlich']);
                $teile = array_map(static function ($it) {
                    $claim = $it['meta']['claim'] ?? null;
                    $beschr = $it['meta']['description'] ?? null;

                    return $it['value'].($claim ? " ({$claim})" : '').($beschr ? " – {$beschr}" : '');
                }, $items);
                if ($teile !== []) {
                    $zeilen[] = $feld['label'].': '.implode('; ', $teile);
                }

                continue;
            }
            if (($feld['type'] ?? '') === 'ref_schreibstil') {
                $stil = $zeile['wert'] !== null ? FoodAlchemistWritingStyle::find((int) $zeile['wert']) : null;
                if ($stil !== null) {
                    $duktus = trim((string) $stil->sprach_duktus);
                    $zeilen[] = $feld['label'].': '.$stil->name.($duktus !== '' ? ' — Sprach-Duktus: '.$duktus : '');
                }

                continue;
            }
            if ($zeile['wert'] !== null && trim((string) $zeile['wert']) !== '') {
                $zeilen[] = $feld['label'].': '.$zeile['wert'];
            }
        }

        return $zeilen;
    }

    /**
     * Nur AUSDRÜCKLICHE Fakten. „Unbekannt" wird nie als „fehlt" ausgegeben; höchstens eine Zeile
     * „Profil unvollständig". Ohne ein einziges ausdrückliches Faktum entfällt der Block ganz.
     */
    private function profilBlock(Team|Profiltraeger $ziel, ?MachbarkeitKontext $kontext, string $kopf = 'Küchen-Fakten des Teams (Teamprofil, ausdrücklich gepflegt)'): ?string
    {
        $t = Profiltraeger::von($ziel);
        try {
            $p = $this->profil->lies($t->team, $t);
        } catch (\Throwable) {
            return null;   // Erdung ist eine Verbesserung, keine Voraussetzung
        }
        $zeilen = [];
        $fehlt = array_values(array_map(static fn ($a) => $a['name'], array_filter($p['ausstattung'], static fn ($a) => $a['status'] === TeamProfilService::STATUS_NICHT_VORHANDEN)));
        if ($fehlt !== []) {
            $zeilen[] = 'Nicht vorhanden: '.implode(', ', $fehlt);
        }
        if (($p['profil']['max_pax_parallel'] ?? null) !== null) {
            $zeilen[] = 'Max. Gäste parallel zum Tagesgeschäft: '.(int) $p['profil']['max_pax_parallel'];
        }
        if (! empty($p['profil']['convenience_grad'])) {
            $zeilen[] = 'Convenience-Grad: '.$p['profil']['convenience_grad'];
        }
        if ($p['flaechen'] !== []) {
            $teile = array_map(static function ($f) {
                $zusatz = array_filter([
                    $f['max_pax'] !== null ? 'max. '.(int) $f['max_pax'].' Gäste' : null,
                    ($f['saison_von'] ?? null) || ($f['saison_bis'] ?? null) ? trim(($f['saison_von'] ?? '').'–'.($f['saison_bis'] ?? ''), '–') : null,
                ]);

                return $f['name'].($zusatz !== [] ? ' ('.implode(', ', $zusatz).')' : '');
            }, $p['flaechen']);
            $zeilen[] = 'Flächen: '.implode('; ', $teile);
        }
        if (! empty($p['profil']['notes']) && trim((string) $p['profil']['notes']) !== '') {
            $zeilen[] = 'Notiz: '.trim((string) $p['profil']['notes']);
        }
        if ($zeilen === []) {
            return null;
        }
        $unbekannt = count(array_filter($p['ausstattung'], static fn ($a) => $a['status'] === TeamProfilService::STATUS_UNBEKANNT && ! $a['standard']));
        if ($unbekannt > 0) {
            $zeilen[] = "Profil unvollständig ($unbekannt Geräte unbekannt) — daraus nichts als fehlend annehmen";
        }

        return $kopf.":\n- ".implode("\n- ", $zeilen);
    }

    /** Tonalität des Foodbooks: führt und überschreibt die Default-Schreibstile (wie bisher, unverändert im Wortlaut). */
    private function tonalitaet(?int $foodbookId): ?string
    {
        if ($foodbookId === null) {
            return null;
        }
        $fb = FoodAlchemistFoodbook::find($foodbookId);
        if ($fb === null || $fb->writing_style_id === null || ($stil = FoodAlchemistWritingStyle::find((int) $fb->writing_style_id)) === null) {
            return null;
        }
        $duktus = trim((string) $stil->sprach_duktus);

        return 'Tonalität dieses Foodbooks (führt — überschreibt die Default-Schreibstile):'
            ."\n- Schreibstil: ".$stil->name.($duktus !== '' ? ' — Sprach-Duktus: '.$duktus : '');
    }

    private function anlassZeile(Team $ziel, ?MachbarkeitKontext $kontext): ?string
    {
        if ($kontext === null || ($kontext->gaeste === null && $kontext->termin === null)) {
            return null;
        }
        $teile = [];
        if ($kontext->gaeste !== null) {
            $teile[] = $kontext->gaeste.' Gäste';
        }
        if ($kontext->termin !== null) {
            $teile[] = 'Termin '.$kontext->termin->format('d.m.Y');
        }
        $zeile = 'Anlass dieser Erzeugung: '.implode(', ', $teile);
        try {
            $max = $this->profil->lies($ziel, $ziel)['profil']['max_pax_parallel'] ?? null;
        } catch (\Throwable) {
            $max = null;
        }
        if ($max !== null && $kontext->gaeste !== null && $kontext->gaeste > (int) $max) {
            $zeile .= " — übersteigt die ausdrücklich gepflegte Kapazität von $max Gästen parallel";
        }

        return $zeile;
    }

    /**
     * Blöcke zusammenführen und gegen das Gesamtbudget kürzen: Geschütztes nie, sonst von unspezifisch
     * (kleiner Rang) nach spezifisch. Rückgabe mit Quellenliste und Bilanz.
     *
     * @param  list<array{ebene: string, label: string, text: string, geschuetzt: bool, rang: int}>  $bloecke
     */
    private function mitBudget(array $bloecke): array
    {
        $budget = $this->budget();
        $trenner = 2;   // "\n\n"
        $gesamt = static fn (array $b) => array_sum(array_map(static fn ($x) => mb_strlen($x['text']), $b)) + $trenner * max(0, count($b) - 1);

        $vorher = $gesamt($bloecke);
        $gekuerzt = [];
        $verworfen = [];
        if ($vorher > $budget) {
            $reihenfolge = array_keys(array_filter($bloecke, static fn ($b) => ! $b['geschuetzt']));
            usort($reihenfolge, static fn ($a, $b) => $bloecke[$a]['rang'] <=> $bloecke[$b]['rang']);
            foreach ($reihenfolge as $i) {
                $ueber = $gesamt($bloecke) - $budget;
                if ($ueber <= 0) {
                    break;
                }
                $laenge = mb_strlen($bloecke[$i]['text']);
                $rest = $laenge - $ueber - mb_strlen(self::KUERZUNG);
                if ($rest < self::MIN_REST) {
                    $verworfen[$bloecke[$i]['ebene']] = $laenge;
                    $bloecke[$i]['text'] = '';
                } else {
                    $gekuerzt[$bloecke[$i]['ebene']] = $laenge - $rest;
                    $bloecke[$i]['text'] = mb_substr($bloecke[$i]['text'], 0, $rest).self::KUERZUNG;
                }
            }
            $bloecke = array_values(array_filter($bloecke, static fn ($b) => $b['text'] !== ''));
        }

        $text = $bloecke === [] ? null : implode("\n\n", array_column($bloecke, 'text'));

        return [
            'marken_kontext' => $text,
            'quellen' => array_map(static fn ($b) => ['ebene' => $b['ebene'], 'label' => $b['label'], 'zeichen' => mb_strlen($b['text']), 'geschuetzt' => $b['geschuetzt']], $bloecke),
            'bilanz' => [
                'budget' => $budget,
                'zeichen_vorher' => $vorher,
                'zeichen' => $text === null ? 0 : mb_strlen($text),
                'gekuerzt' => $gekuerzt,      // ebene => entfernte Zeichen
                'verworfen' => $verworfen,    // ebene => Länge des ganz entfallenen Blocks
            ],
        ];
    }
}
