<?php

use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\KontextResolver;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\MachbarkeitKontext;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Schritt 6 (§7, §12 E): KontextResolver — Reihenfolge, Verbindliches, Teamprofil-Block,
 * Gesamtbudget mit Bilanz, Kunden-Ebene nur mit Firma, Anlass aus dem Ausführungskontext, Zusatzfeld-Pflege.
 * Das unveränderte Verhalten (Format, Reihenfolge, Tonalität) sichert Spec82GoldenKontextTest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(CanvasService::class);
    $this->res = app(KontextResolver::class);
    $this->profil = app(TeamProfilService::class);
    $this->setze = function ($team, string $key, string $wert, ?bool $verbindlich = null) {
        $this->svc->setSkalar($this->svc->canvasFor($team, 'food_dna', 'team', $team->id), $key, $wert, $verbindlich);
    };
});

it('Reihenfolge: Vorgaben → Team-DNA → Teamprofil → Kunde → Angebot → Foodbook → Concept → Tonalität; Quellenliste benennt sie', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'Regional.');
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.', true);
    $this->profil->setzeProfil($this->rootTeam, $this->user, ['max_pax_parallel' => 120]);
    $stil = FoodAlchemistWritingStyle::create(['team_id' => $this->rootTeam->id, 'slug' => 's', 'name' => 'Stil', 'sprach_duktus' => 'Kurz.', 'is_inactive' => false, 'sort_order' => 0]);
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Gala']);
    app(FoodbookService::class)->update($this->rootTeam, $fb->id, ['writing_style_id' => $stil->id]);
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'foodbook', 'foodbook', $fb->id), 'leitidee', 'Wasser');
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'kunde_dna', 'crm_company', 77), 'kommunikation_ton', 'Du');
    $concept = $this->makeConcept($this->rootTeam, 'M');
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'concept', 'concept', $concept->id), 'leitidee', 'Fisch');
    $angebot = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'A', 'status' => 'anfrage', 'personen' => 10]);
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'angebot', 'angebot', $angebot->id), 'budget', '50');

    $r = $this->res->fuer($this->rootTeam, ['concept_id' => $concept->id, 'foodbook_id' => $fb->id, 'angebot_id' => $angebot->id, 'crm_company_id' => 77]);

    expect(array_column($r['quellen'], 'ebene'))->toBe(['vorgaben', 'team_dna', 'teamprofil', 'kunde', 'angebot', 'foodbook', 'concept', 'tonalitaet'])
        ->and($r['bilanz']['gekuerzt'])->toBe([])->and($r['bilanz']['verworfen'])->toBe([])
        ->and($r['bilanz']['budget'])->toBe(12000);
    $t = $r['marken_kontext'];
    expect(strpos($t, 'Verbindliche Vorgaben'))->toBeLessThan(strpos($t, 'Küchen-/Marken-DNA'))
        ->and(strpos($t, 'Küchen-/Marken-DNA'))->toBeLessThan(strpos($t, 'Küchen-Fakten'))
        ->and(strpos($t, 'Küchen-Fakten'))->toBeLessThan(strpos($t, 'Kunde/Marke'))
        ->and(strpos($t, 'Kreatives Foodkonzept'))->toBeLessThan(strpos($t, 'Tonalität dieses Foodbooks'));
});

it('deterministisch: gleicher Eingang, byte-gleicher Block (Prompt-Cache), kein Zeitstempel', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'Regional.');
    $this->profil->setzeProfil($this->rootTeam, $this->user, ['convenience_grad' => 'teil_convenience']);

    expect($this->res->fuer($this->rootTeam)['marken_kontext'])->toBe($this->res->fuer($this->rootTeam)['marken_kontext']);
});

it('Verbindliches steht als eigener Block, nicht doppelt in der Team-DNA, und gilt auch für das Unter-Team', function () {
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.', true);
    ($this->setze)($this->rootTeam, 'leitbild', 'Regional.');
    ($this->setze)($this->childA, 'leitbild', 'Kita-Küche.');
    ($this->setze)($this->childA, 'no_gos', 'KIND-VERSUCH');

    $t = $this->res->fuer($this->childA)['marken_kontext'];

    expect($t)->toContain("Verbindliche Vorgaben des Oberteams (nicht abweichen):\n- No-Gos / Tabus: Kein Foie gras.")
        ->and($t)->toContain('Leitbild / Positionierung: Kita-Küche.')
        ->and($t)->not->toContain('KIND-VERSUCH')
        ->and(substr_count($t, 'No-Gos / Tabus'))->toBe(1);
});

it('Teamprofil: nur ausdrückliche Fakten; unbekannt ist nie „fehlt“; ohne Fakten kein Block', function () {
    $mk = fn (string $slug, string $name, bool $std = false) => FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => $slug, 'name' => $name, 'sort_order' => 1, 'is_standard' => $std]);
    $topf = $mk('topf', 'Topf', true);
    $kombi = $mk('kombi', 'Kombidämpfer');
    $mk('sous', 'Sous-vide-Stick');   // bleibt unbekannt

    // nur Unbekanntes/Standard, keine ausdrückliche Angabe: kein Block
    expect($this->res->fuer($this->childA)['marken_kontext'])->toBeNull();

    $this->profil->setzeEquipment($this->childA, $this->makeUser($this->childA, 'Chef'), $kombi->id, 'nicht_vorhanden');
    $this->profil->setzeProfil($this->childA, $this->user, ['max_pax_parallel' => 80, 'convenience_grad' => 'from_scratch', 'notes' => 'Aufzug ist klein.']);
    $this->profil->setzeFlaeche($this->childA, $this->user, null, 'Terrasse', 60, 5, 9);

    $t = $this->res->fuer($this->childA)['marken_kontext'];

    expect($t)->toContain("Küchen-Fakten des Teams (Teamprofil, ausdrücklich gepflegt):")
        ->and($t)->toContain('Nicht vorhanden: Kombidämpfer')
        ->and($t)->not->toContain('Nicht vorhanden: Kombidämpfer, Sous-vide-Stick')
        ->and($t)->not->toContain('Topf')                                      // Standardausstattung wird nicht aufgezählt
        ->and($t)->toContain('Max. Gäste parallel zum Tagesgeschäft: 80')
        ->and($t)->toContain('Convenience-Grad: from_scratch')
        ->and($t)->toContain('Flächen: Terrasse (max. 60 Gäste, 5–9)')
        ->and($t)->toContain('Notiz: Aufzug ist klein.')
        ->and($t)->toContain('Profil unvollständig (1 Geräte unbekannt)');     // Sous-vide: unbekannt, nicht „fehlt“
});

it('Budget: verbindlich und Tonalität werden nie gekürzt, sonst von unspezifisch nach spezifisch; die Bilanz sagt es', function () {
    config(['foodalchemist.kontext.budget_zeichen' => 1500]);
    ($this->setze)($this->rootTeam, 'no_gos', 'VERBINDLICH-MARKER', true);
    ($this->setze)($this->rootTeam, 'leitbild', str_repeat('D', 2500));          // Team-DNA: groß, unspezifisch
    $concept = $this->makeConcept($this->rootTeam, 'M');
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'concept', 'concept', $concept->id), 'leitidee', 'CONCEPT-MARKER');

    $r = $this->res->fuer($this->rootTeam, ['concept_id' => $concept->id]);

    expect($r['marken_kontext'])->toContain('VERBINDLICH-MARKER')->and($r['marken_kontext'])->toContain('CONCEPT-MARKER')
        ->and($r['marken_kontext'])->toContain('[…gekürzt für KI-Kontext…]')
        ->and(mb_strlen($r['marken_kontext']))->toBeLessThanOrEqual(1500)
        ->and($r['bilanz']['gekuerzt'])->toHaveKey('team_dna')
        ->and($r['bilanz']['zeichen'])->toBeLessThanOrEqual(1500)
        ->and($r['bilanz']['zeichen_vorher'])->toBeGreaterThan(1500);
});

it('Budget: reicht das Kürzen eines Blocks nicht, entfällt er ganz und steht unter „verworfen“', function () {
    config(['foodalchemist.kontext.budget_zeichen' => 1000]);
    ($this->setze)($this->rootTeam, 'leitbild', 'kurz');                         // winzige Team-DNA
    $concept = $this->makeConcept($this->rootTeam, 'M');
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'concept', 'concept', $concept->id), 'leitidee', str_repeat('C', 1200));

    $r = $this->res->fuer($this->rootTeam, ['concept_id' => $concept->id]);

    expect($r['bilanz']['verworfen'])->toHaveKey('team_dna')
        ->and($r['marken_kontext'])->not->toContain('Küchen-/Marken-DNA')
        ->and($r['bilanz']['gekuerzt'])->toHaveKey('concept');
});

it('Kunden-Ebene nur mit CRM-Firma: Foodbook ohne Firma bekommt sie nicht, mit Firma ja; Angebot über sein Kundenfeld', function () {
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'kunde_dna', 'crm_company', 4711), 'kommunikation_ton', 'KUNDE-MARKER');
    $fbSvc = app(FoodbookService::class);
    $ohne = $fbSvc->create($this->rootTeam, ['label' => 'Ohne']);
    $mit = $fbSvc->create($this->rootTeam, ['label' => 'Mit', 'crm_company_id' => 4711]);

    expect($this->res->fuerObjekt($this->rootTeam, $ohne)['marken_kontext'])->toBeNull()
        ->and($this->res->fuerObjekt($this->rootTeam, $mit)['marken_kontext'])->toContain('KUNDE-MARKER');

    $angebot = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'A', 'status' => 'anfrage', 'personen' => 10, 'crm_company_id' => 4711]);
    expect($this->res->fuerObjekt($this->rootTeam, $angebot)['marken_kontext'])->toContain('KUNDE-MARKER');
});

it('Ausführungskontext: Ziel-Team und Anlass (Gäste, Termin); mehr Gäste als die gepflegte Kapazität wird benannt', function () {
    $this->profil->setzeProfil($this->childA, $this->user, ['max_pax_parallel' => 50]);
    $kontext = new MachbarkeitKontext($this->childA, $this->user, 80, \Carbon\Carbon::create(2026, 6, 20));

    $r = $this->res->fuer($this->rootTeam, [], $kontext);   // Ziel ist childA, nicht der aufrufende Team-Kontext

    expect($r['marken_kontext'])->toContain('Max. Gäste parallel zum Tagesgeschäft: 50')
        ->and($r['marken_kontext'])->toContain('Anlass dieser Erzeugung: 80 Gäste, Termin 20.06.2026')
        ->and($r['marken_kontext'])->toContain('übersteigt die ausdrücklich gepflegte Kapazität von 50 Gästen parallel');

    // ohne Gäste/Termin keine Anlass-Zeile
    expect($this->res->fuer($this->rootTeam, [], new MachbarkeitKontext($this->childA, $this->user))['marken_kontext'])->not->toContain('Anlass dieser Erzeugung');
});

it('cascadeKontext ist nur noch Adapter: gleiches Ergebnis wie der Resolver, [] ohne Inhalt', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'Regional.');

    expect($this->svc->cascadeKontext($this->rootTeam))->toBe(['marken_kontext' => $this->res->fuer($this->rootTeam)['marken_kontext']]);
    $leer = \Platform\Core\Models\Team::create(['name' => 'Leer', 'user_id' => 1, 'personal_team' => false]);
    expect($this->svc->cascadeKontext($leer))->toBe([]);
});

it('Zusatzfeld anlegen: Duplikat, Kern-Key, falscher Key und Typ werden abgewiesen; gelöschtes wird wiederbelebt', function () {
    $f = $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'nachhaltigkeit', 'Nachhaltigkeit', ['group_name' => 'Haltung']);
    expect($f->team_id)->toBe((int) $this->rootTeam->id)->and($f->type)->toBe('longtext');

    expect(fn () => $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'nachhaltigkeit', 'Nochmal'))->toThrow(\RuntimeException::class, 'schon');
    expect(fn () => $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'leitbild', 'Kern'))->toThrow(\RuntimeException::class, 'Kern-Feld');
    expect(fn () => $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'Ungültig Key', 'X'))->toThrow(\RuntimeException::class, 'Feld-Key');
    expect(fn () => $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'ok_key', 'X', ['type' => 'bild']))->toThrow(\RuntimeException::class, 'Feld-Typ');
    expect(fn () => $this->svc->zusatzfeldAnlegen($this->rootTeam, 'unbekannt', 'ok_key', 'X'))->toThrow(\RuntimeException::class, 'Canvas-Typ');
    // anderes Team: derselbe Key ist erlaubt
    expect($this->svc->zusatzfeldAnlegen($this->childB, 'food_dna', 'nachhaltigkeit', 'Nachhaltigkeit B')->team_id)->toBe((int) $this->childB->id);

    // gelöscht → wiederbeleben statt Unique-Fehler
    $f->delete();
    $neu = $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'nachhaltigkeit', 'Neu', ['type' => 'text']);
    expect($neu->id)->toBe($f->id)->and($neu->trashed())->toBeFalse()->and($neu->label)->toBe('Neu')->and($neu->type)->toBe('text');
});

it('Unique-Index: zwei Zusatzfelder mit gleichem Key im selben Team sind auch direkt in der DB nicht möglich', function () {
    $row = ['canvas_type' => 'food_dna', 'field_key' => 'dopp', 'label' => 'X', 'type' => 'text', 'position' => 0, 'active' => true];
    \Platform\FoodAlchemist\Models\FoodAlchemistCanvasTemplate::create($row + ['team_id' => $this->rootTeam->id]);

    expect(fn () => \Platform\FoodAlchemist\Models\FoodAlchemistCanvasTemplate::create($row + ['team_id' => $this->rootTeam->id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
