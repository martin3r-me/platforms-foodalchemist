<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 33 · P0 — ein Status-Vokabular für die drei Ausgabeformen.
 *
 * **Der Anlass war ein echter Fund**, nicht eine Aufräum-Idee: im Dev-Bestand lag ein Foodbook
 * auf `final` — einem Wert, den weder Migration noch Service noch UI kennen. Möglich war das,
 * weil `status` in den `FELDER`-Listen steht und damit über Service UND MCP mit jedem
 * beliebigen String beschreibbar war. Dazu schrieb das Foodbook-Dropdown `active`, während die
 * Migration `aktiv` meinte.
 *
 * Diese Datei nagelt beide Hälften fest: die Abbildungsregel und die Tatsache, dass kein Weg
 * mehr an ihr vorbeiführt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
});

// ── Die Abbildungsregel ──────────────────────────────────────────────────────

it('bildet alle bekannten Schreibweisen auf das Vokabular ab', function () {
    expect(AusgabeStatus::normalisiere('entwurf'))->toBe(AusgabeStatus::Entwurf)
        ->and(AusgabeStatus::normalisiere('draft'))->toBe(AusgabeStatus::Entwurf)
        ->and(AusgabeStatus::normalisiere('aktiv'))->toBe(AusgabeStatus::Aktiv)
        ->and(AusgabeStatus::normalisiere('active'))->toBe(AusgabeStatus::Aktiv)
        ->and(AusgabeStatus::normalisiere('inaktiv'))->toBe(AusgabeStatus::Inaktiv)
        ->and(AusgabeStatus::normalisiere('archiviert'))->toBe(AusgabeStatus::Archiviert)
        ->and(AusgabeStatus::normalisiere('ARCHIVED'))->toBe(AusgabeStatus::Archiviert)
        ->and(AusgabeStatus::normalisiere('  Aktiv  '))->toBe(AusgabeStatus::Aktiv);
});

it('macht „draußen" zu aktiv — kein Zustand daneben', function () {
    // Entscheid Dominique: Versenden bzw. Veröffentlichen SETZT auf aktiv. Das Versand-Ereignis
    // selbst hängt am Kapitel-Snapshot, nicht am Kopf-Status.
    expect(AusgabeStatus::normalisiere('versendet'))->toBe(AusgabeStatus::Aktiv)
        ->and(AusgabeStatus::normalisiere('veroeffentlicht'))->toBe(AusgabeStatus::Aktiv)
        ->and(AusgabeStatus::normalisiere('veröffentlicht'))->toBe(AusgabeStatus::Aktiv)
        ->and(AusgabeStatus::normalisiere('sent'))->toBe(AusgabeStatus::Aktiv);
});

it('setzt Unbekanntes auf Entwurf, nicht auf aktiv', function () {
    // Die Richtung ist Absicht: ein fälschlich auf „aktiv" gehobener Datensatz landet im
    // Portfolio und in der Umsatz-Auswertung. Der umgekehrte Fehler macht ihn nur unsichtbar.
    // `final` ist kein erfundenes Beispiel — genau das lag im Dev-Bestand.
    expect(AusgabeStatus::normalisiere('final'))->toBe(AusgabeStatus::Entwurf)
        ->and(AusgabeStatus::normalisiere('irgendwas'))->toBe(AusgabeStatus::Entwurf)
        ->and(AusgabeStatus::normalisiere(''))->toBe(AusgabeStatus::Entwurf)
        ->and(AusgabeStatus::normalisiere(null))->toBe(AusgabeStatus::Entwurf);
});

it('lässt nur aktiv als laufend gelten', function () {
    expect(AusgabeStatus::Aktiv->laeuft())->toBeTrue()
        ->and(AusgabeStatus::Entwurf->laeuft())->toBeFalse()
        ->and(AusgabeStatus::Inaktiv->laeuft())->toBeFalse()
        ->and(AusgabeStatus::Archiviert->laeuft())->toBeFalse();
});

it('nennt für jeden nicht-laufenden Zustand einen Grund', function () {
    // Ein grauer Punkt ohne Begründung ist in einer Steuerungsfläche wertlos.
    expect(AusgabeStatus::Aktiv->grundNichtLaufend())->toBeNull()
        ->and(AusgabeStatus::Entwurf->grundNichtLaufend())->not->toBeNull()
        ->and(AusgabeStatus::Inaktiv->grundNichtLaufend())->toContain('vom Netz')
        ->and(AusgabeStatus::Archiviert->grundNichtLaufend())->not->toBeNull();
});

// ── Der Cast: tolerant beim Lesen, normalisierend beim Schreiben ─────────────

dataset('ausgabeformen', [
    'Foodbook' => [FoodAlchemistFoodbook::class, 'foodalchemist_foodbooks', ['label' => 'X']],
    'Speisekarte' => [FoodAlchemistSpeisekarte::class, 'foodalchemist_menu_cards', ['name' => 'X']],
    'Speiseplan' => [FoodAlchemistSpeiseplan::class, 'foodalchemist_menu_plans', ['name' => 'X']],
]);

it('liest einen Altwert, ohne zu werfen', function (string $model, string $tabelle, array $felder) {
    $id = DB::table($tabelle)->insertGetId($felder + [
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->rootTeam->id, 'status' => 'final',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Der eingebaute Enum-Cast würde hier eine Exception werfen und die ganze Liste zerlegen,
    // in der dieser eine Datensatz auftaucht.
    $m = $model::find($id);
    expect($m->status)->toBe(AusgabeStatus::Entwurf)
        ->and($m->statusWert())->toBe(AusgabeStatus::Entwurf);
})->with('ausgabeformen');

it('schreibt niemals einen ungültigen Wert in die Spalte', function (string $model, string $tabelle, array $felder) {
    // Auch der direkte Weg (Seeder, Fixture, Alt-Code) am Service vorbei.
    $m = $model::create($felder + ['team_id' => $this->rootTeam->id, 'status' => 'draft']);

    expect(DB::table($tabelle)->where('id', $m->id)->value('status'))->toBe('entwurf');

    $m->update(['status' => 'active']);
    expect(DB::table($tabelle)->where('id', $m->id)->value('status'))->toBe('aktiv');
})->with('ausgabeformen');

it('filtert mit scopeLaeuft nur die aktiven', function (string $model, string $tabelle, array $felder) {
    foreach (['entwurf', 'aktiv', 'inaktiv', 'archiviert'] as $i => $st) {
        $model::create($felder + ['name' => 'X' . $i, 'label' => 'X' . $i,
            'team_id' => $this->rootTeam->id, 'status' => $st]);
    }

    expect($model::query()->laeuft()->count())->toBe(1);
})->with('ausgabeformen');

// ── Kein Weg vorbei: Services und MCP ────────────────────────────────────────

it('normalisiert den Status auch über die Services', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'FB', 'status' => 'versendet']);
    expect($fb->statusWert())->toBe(AusgabeStatus::Aktiv);

    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'K', 'status' => 'veroeffentlicht']);
    expect($karte->statusWert())->toBe(AusgabeStatus::Aktiv);

    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'P', 'status' => 'final']);
    expect($plan->statusWert())->toBe(AusgabeStatus::Entwurf);

    // Und beim Update — das war der Weg, über den `final` in den Bestand kam.
    $aktualisiert = app(FoodbookService::class)->update($this->rootTeam, (int) $fb->id, ['status' => 'quatsch']);
    expect($aktualisiert->statusWert())->toBe(AusgabeStatus::Entwurf);
});

it('behandelt beide Alt-Schreibweisen des Aktiv-Status gleich', function () {
    // Ersetzt die frühere Duldung zweier Schreibweisen (FoodbookQualitaetSignaleTest): der
    // Kanon ist jetzt geschlossen, `aktiv` und `active` landen auf demselben Wert.
    $a = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'A', 'status' => 'aktiv']);
    $b = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'B', 'status' => 'active']);

    expect($a->statusWert())->toBe($b->statusWert())
        ->and(FoodAlchemistFoodbook::query()->laeuft()->count())->toBe(2);
});
