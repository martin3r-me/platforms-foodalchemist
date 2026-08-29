<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 22·H4a — Golden-Riegel VOR dem Lifecycle-Umbau (V-011 Schließ-Zweig + V-009 Wiederkehr).
 *
 * Eingefroren ist genau das, was der Umbau NICHT verschieben darf: die **Emissions-Form**
 * (welche Zeile mit welchem Titel/Payload/Dedup entsteht), die **Dedup-Identität** (zweiter
 * Lauf aktualisiert dieselbe Zeile statt eine zweite anzulegen, `created_at` bleibt stehen)
 * und die **Rückgabe-Bedeutung** von `emittiereSignale()` (Anzahl erzeugter/aktualisierter,
 * nicht geschlossener) — an ihr hängen `DataQualityCommand` und `SignalDetektorService`.
 *
 * Die Fehlerklasse dieses Umbaus ist die stille Verschiebung, nicht der Crash: ein
 * Schließ-Zweig, der eine Zeile zu viel greift, sieht beim Schreiber unauffällig aus und
 * fällt erst auf, wenn ein Mensch ein Signal vermisst, das nie erledigt wurde.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
    $this->signals = app(SignalService::class);
});

/** @return array<string,array{typ:string,titel:string,payload:mixed,severity:string,source:string}> */
function signalAbbild(int $teamId): array
{
    return FoodAlchemistSignal::where('team_id', $teamId)
        ->orderBy('id')->get()
        ->mapWithKeys(fn ($s) => [
            ($s->type instanceof BackedEnum ? $s->type->value : $s->type).'|'.$s->dedup_key => [
                'typ' => $s->type instanceof BackedEnum ? $s->type->value : $s->type,
                'titel' => $s->title,
                'payload' => $s->payload,
                'severity' => $s->severity instanceof BackedEnum ? $s->severity->value : $s->severity,
                'source' => $s->source,
            ],
        ])->all();
}

it('Golden: die Emissions-Form je Lücke bleibt Zeichen für Zeichen stehen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Lachs');
    $gp->update(['status' => 'approved']);

    $n = $this->dq->emittiereSignale($this->rootTeam);
    $abbild = signalAbbild($this->rootTeam->id);

    expect($n)->toBeGreaterThan(0)
        ->and($abbild)->toHaveCount($n);

    // Ein frisch angelegter approved-GP trägt keine Allergen-Konfidenz — Titel-Form
    // „<zahl> — <label>", Payload mit anzahl/metrik/ebene, source = data-quality.
    $key = SignalTyp::DatenqualitaetGpLa->value.'|dq-gp-allergen-konfidenz';
    expect($abbild)->toHaveKey($key)
        ->and($abbild[$key]['titel'])->toBe('1 — approved-GPs ohne Allergen-Konfidenz')
        ->and($abbild[$key]['payload'])->toBe(['anzahl' => 1, 'metrik' => 'gp_allergen_konfidenz', 'ebene' => 'Grundprodukte'])
        ->and($abbild[$key]['severity'])->toBe(SignalSeverity::Warnung->value)
        ->and($abbild[$key]['source'])->toBe('data-quality');

    // Jede emittierte Zeile trägt einen dedup_key mit `dq-`-Präfix und steht offen.
    $alle = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->get();
    foreach ($alle as $s) {
        expect($s->dedup_key)->toStartWith('dq-')
            ->and($s->status->value)->toBe(SignalStatus::Offen->value)
            ->and($s->erledigt_at)->toBeNull();
    }
});

it('Golden: eine mit 0 gemessene Metrik erzeugt keine Zeile', function () {
    // Leeres Team ⇒ jede Lücken-Metrik misst 0.
    $n = $this->dq->emittiereSignale($this->childB);

    expect($n)->toBe(0)
        ->and(FoodAlchemistSignal::where('team_id', $this->childB->id)->count())->toBe(0);
});

it('Golden: der zweite Lauf aktualisiert dieselbe Zeile — Identität und created_at bleiben', function () {
    $gp = $this->makeGp($this->rootTeam, 'Lachs');
    $gp->update(['status' => 'approved']);

    $n1 = $this->dq->emittiereSignale($this->rootTeam);
    $vorher = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->orderBy('id')->get()->map(fn ($s) => [$s->id, (string) $s->created_at])->all();

    $this->travel(2)->seconds();
    $n2 = $this->dq->emittiereSignale($this->rootTeam);
    $nachher = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->orderBy('id')->get()->map(fn ($s) => [$s->id, (string) $s->created_at])->all();

    expect($n2)->toBe($n1)
        ->and($nachher)->toBe($vorher)                                  // kein Dauerfeuer, keine neue Zeile
        ->and(signalAbbild($this->rootTeam->id))->toHaveCount($n1);
});

it('Golden: der Dedup greift nur auf OFFENE Zeilen — eine erledigte wird nicht wiederbelebt', function () {
    $a = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Erster', [
        'dedup_key' => 'x:1',
    ]);
    $this->signals->abschliessen($this->rootTeam, $a->id);

    $b = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Zweiter', [
        'dedup_key' => 'x:1',
    ]);

    expect($b->id)->not->toBe($a->id)
        ->and($a->refresh()->status->value)->toBe(SignalStatus::Erledigt->value)
        ->and($a->title)->toBe('Erster')                                 // die geschlossene Zeile bleibt unangetastet
        ->and($b->status->value)->toBe(SignalStatus::Offen->value);
});

it('Golden: der Dedup-Zweig schreibt genau vier Felder fort', function () {
    $a = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Alt', [
        'dedup_key' => 'x:2', 'description' => 'alte Beschreibung', 'payload' => ['n' => 1],
        'ref_type' => 'recipe', 'ref_id' => 42, 'source' => 'detektor',
    ]);

    $b = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Kritisch, 'Neu', [
        'dedup_key' => 'x:2', 'description' => 'neue Beschreibung', 'payload' => ['n' => 2],
        'ref_type' => 'gp', 'ref_id' => 99, 'source' => 'data-quality',
    ]);

    expect($b->id)->toBe($a->id)
        ->and($b->severity->value)->toBe(SignalSeverity::Kritisch->value)
        ->and($b->title)->toBe('Neu')
        ->and($b->description)->toBe('neue Beschreibung')
        ->and($b->payload)->toBe(['n' => 2])
        // NICHT fortgeschrieben: Bezug und Herkunft bleiben die der ersten Sichtung.
        ->and($b->ref_type)->toBe('recipe')
        ->and($b->ref_id)->toBe(42)
        ->and($b->source)->toBe('detektor');
});

it('V-011 Detektor-Sweep: schließt offene Signale, deren Key nicht mehr emittiert wurde (Auto-Close)', function () {
    $a = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'A', ['dedup_key' => 'marge-recipe-1', 'source' => 'detektor']);
    $b = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'B', ['dedup_key' => 'marge-recipe-2', 'source' => 'detektor']);

    // Nur Key 1 ist diesmal noch „live" → Gericht 2 (behoben) schließt sich, 1 bleibt offen.
    $n = $this->signals->schliesseVerschwundene($this->rootTeam, SignalTyp::MargeUnterZiel, 'detektor', ['marge-recipe-1'], 'Marge wieder ≥ Ziel');
    expect($n)->toBe(1)
        ->and($a->refresh()->status->value)->toBe(SignalStatus::Offen->value)
        ->and($b->refresh()->status->value)->toBe(SignalStatus::Erledigt->value)
        ->and($b->payload['auto_geschlossen'] ?? null)->toBe('Marge wieder ≥ Ziel')
        ->and($b->erledigt_at)->not->toBeNull();

    // Leere liveKeys (Lauf fand NICHTS mehr) ⇒ alle restlichen offenen dieses Typs schließen.
    $n2 = $this->signals->schliesseVerschwundene($this->rootTeam, SignalTyp::MargeUnterZiel, 'detektor', [], 'alles behoben');
    expect($n2)->toBe(1)->and($a->refresh()->status->value)->toBe(SignalStatus::Erledigt->value);
});

it('V-011 Detektor-Sweep: greift NICHT über Typ/Quelle/Team hinweg', function () {
    $anderTyp = $this->signals->erzeuge($this->rootTeam, SignalTyp::WareneinsatzUeberZiel, SignalSeverity::Warnung, 'X', ['dedup_key' => 'we-quote-recipe-1', 'source' => 'detektor']);
    $andereQuelle = $this->signals->erzeuge($this->rootTeam, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Y', ['dedup_key' => 'dq-y', 'source' => 'data-quality']);
    $anderesTeam = $this->signals->erzeuge($this->childA, SignalTyp::MargeUnterZiel, SignalSeverity::Warnung, 'Z', ['dedup_key' => 'marge-recipe-9', 'source' => 'detektor']);

    // Voll-Sweep über rootTeam/MargeUnterZiel/detektor mit leeren Keys darf nichts Fremdes greifen.
    $this->signals->schliesseVerschwundene($this->rootTeam, SignalTyp::MargeUnterZiel, 'detektor', [], 'weg');

    expect($anderTyp->refresh()->status->value)->toBe(SignalStatus::Offen->value)      // anderer Typ
        ->and($andereQuelle->refresh()->status->value)->toBe(SignalStatus::Offen->value) // andere Quelle (Ampel)
        ->and($anderesTeam->refresh()->status->value)->toBe(SignalStatus::Offen->value); // anderes Team
});
