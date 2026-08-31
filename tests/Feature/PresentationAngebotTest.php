<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #380 Composer / Spec 43 — Angebot-Präsentation (digitales Kundenbuch): interna-freier
 * Snapshot (normalizeAngebot spiegelt normalizeFoodbook), öffentlicher Public-Link, 404-Gate.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->pres = app(PresentationService::class);
    $this->comp = app(OfferCompositionService::class);
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, [
        'name' => 'Gala-Angebot 2027', 'personen' => 40, 'occasion' => 'Hochzeit',
    ]);
    // Realistischer Preis-Pfad: preisCockpit summiert Concept-Slots (nicht den Cache).
    // Ein bepreistes embedded-Paket im Concept → 12 €/P (deterministisch, wie im Betrieb).
    $paket = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Menü-Paket', 'kind' => 'paket', 'status' => 'active',
        'price_per_person_cache' => 12.0, 'ek_per_person_cache' => 4.0,
    ]);
    $c = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Vorspeise', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 12.0, 'ek_per_person_cache' => 4.0,
    ]);
    $this->makeConceptSlot($c, ['position' => 1, 'type' => 'paket', 'embedded_concept_id' => $paket->id]);
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Menü', 'consumer_title' => 'Unser Menü']);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $c->id]);
});

it('buildSnapshot des Angebots ist interna-frei (Allowlist)', function () {
    $snap = $this->pres->buildSnapshot($this->rootTeam, $this->angebot->fresh(), 'angebot', ['design' => 'editorial']);
    expect($snap['type'])->toBe('angebot')
        ->and($snap['title'])->toBe('Gala-Angebot 2027')
        ->and($snap['content']['sections'][0]['title'])->toBe('Unser Menü');

    $keys = [];
    $walk = function ($n) use (&$walk, &$keys) {
        foreach ($n as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($snap['content']);
    foreach (['ek', 'ek_pp', 'ek_pro_person', 'intern', 'kaskaden', 'preis_quelle', 'source', 'slot_id', 'recipe_id'] as $verboten) {
        expect($keys)->not->toContain($verboten);
    }
});

it('öffentlicher Angebot-Link ohne Login + 404 nach Zurückziehen', function () {
    $res = $this->pres->publish($this->rootTeam, 'angebot', $this->angebot->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $this->get('/p/angebot/' . $res['token'])
        ->assertOk()->assertSee('Gala-Angebot 2027')->assertSee('Unser Menü')
        ->assertSee('Preis-Übersicht')  // #380 Q2: kundensichere Preis-Aufschlüsselung im editorial-Default
        ->assertDontSee('Wareneinsatz')->assertDontSee('preis_quelle');

    $this->pres->withdraw($this->rootTeam, 'angebot', $this->angebot->id);
    $this->get('/p/angebot/' . $res['token'])->assertNotFound();
});

it('Pro-Person-Total trägt netto + brutto (inkl. MwSt) und leere Kapitel fehlen in der Aufschlüsselung', function () {
    // Ein zweites, leeres Kapitel (nur Kopf, keine bepreisten Bausteine) → darf keine 0,00-€-Zeile erzeugen.
    $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Leer', 'consumer_title' => 'Leer']);

    $snap = $this->pres->buildSnapshot($this->rootTeam, $this->angebot->fresh(), 'angebot', ['design' => 'editorial', 'price_display' => true]);

    // #1: Pro-Person netto UND brutto
    $total = $snap['content']['total'];
    expect($total['vk_pro_person'])->toBeGreaterThan(0.0)
        ->and($total['mwst_satz'])->not->toBeNull()
        ->and($total['vk_pro_person_brutto'])->toBeGreaterThan($total['vk_pro_person']);

    // #2: Aufschlüsselung listet nur das bepreiste Kapitel (Kunden-Titel), kein leeres 0,00-€-„Leer".
    $titel = array_column($snap['content']['preis_aufschluesselung']['zeilen'], 'titel');
    expect($titel)->toContain('Unser Menü')->not->toContain('Leer');
});
