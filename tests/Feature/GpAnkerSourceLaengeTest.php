<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket J Nachtrag — Befund aus dem Vault-Bridge-Import (PR #143): `source` auf
 * `foodalchemist_gp_anchor_mappings` war 16 Zeichen breit, das Label `bridge_alt_neu+exact`
 * (20 Zeichen) warf 506× SQLSTATE 22001 (Data too long) statt einer sauberen Ablehnung.
 *
 * SQLite erzwingt KEINE VARCHAR-Länge (anders als MySQL/demo) — der SQL-Fehler selbst ist hier
 * nicht reproduzierbar. Die eigentliche Absicherung ist darum die PHP-Validierung in
 * PairingService::setGpAnker() (wirft VOR der Query), nicht die (MySQL-Raw-guarded) Migration —
 * die Spaltenbreite allein hätte das nächste zu lange Label (z. B. exact_name_v2) wieder als
 * rohen SQL-Fehler durchgelassen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->gp = $this->makeGp($this->rootTeam, 'Source-Laenge-Test');
    $this->ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
        'uuid' => (string) Str::uuid(), 'team_id' => null,
        'slug' => 'source-laenge-test-'.bin2hex(random_bytes(3)), 'display_de' => 'Source-Laenge-Test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('setGpAnker: source bis 32 Zeichen (neue Spaltenbreite) geht durch', function () {
    $source32 = str_repeat('a', 32);

    app(PairingService::class)->setGpAnker($this->rootTeam, $this->gp->id, $this->ankerId, 'kern', $source32);

    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $this->gp->id)->value('source'))->toBe($source32);
});

it('setGpAnker: source über 32 Zeichen wirft sauber (kein SQL-Fehler, kein 500er)', function () {
    $zuLang = 'bridge_alt_neu+exact_variante_mit_vielen_zeichen';   // 49 Zeichen

    expect(fn () => app(PairingService::class)->setGpAnker($this->rootTeam, $this->gp->id, $this->ankerId, 'kern', $zuLang))
        ->toThrow(RuntimeException::class, 'source zu lang');
});

it('gp_anchors.IMPORT: zu langes source-Label wird sauber abgelehnt statt einen SQL-Fehler zu werfen', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);
    $slug = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $this->ankerId)->value('slug');

    $res = $registry->get('foodalchemist.gp_anchors.IMPORT')->execute(['eintraege' => [
        ['gp_id' => $this->gp->id, 'anchor_slug' => $slug, 'source' => 'bridge_alt_neu+exact_zu_lang_fuer_die_spalte'],
    ]], $kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['abgelehnt'])->toBe(1)
        ->and($res->data['eintraege'][0]['status'])->toBe('abgelehnt')
        ->and($res->data['eintraege'][0]['grund'])->toContain('source zu lang');
});
