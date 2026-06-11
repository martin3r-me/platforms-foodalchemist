<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M5-02: knowledge-import — Upsert per slug+hash (idempotent, version+1 bei
 * Änderung), Dubletten-Suffix, Anker-Verdrahtung über quelle_pfad.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Mini-Vault auf Platte (Command liest echtes FS)
    $this->vault = sys_get_temp_dir() . '/fa_vault_' . uniqid() . '/07_WISSEN'; // relativ() ankert auf 07_WISSEN
    foreach (['07.01_Lebensmittel_und_Gastronomie/Cross_Cutting', '07.01_Lebensmittel_und_Gastronomie/Domains', '07.02_Flavor_Pairing/pairings'] as $d) {
        mkdir("{$this->vault}/{$d}", 0777, true);
    }
    file_put_contents("{$this->vault}/07.01_Lebensmittel_und_Gastronomie/Cross_Cutting/Substitutionen.md", "---\ntyp: x\n---\n# Substitutionen\nInhalt A");
    file_put_contents("{$this->vault}/07.01_Lebensmittel_und_Gastronomie/Domains/Milchprodukte.md", "# Milchprodukte\nDomain-Wissen");
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/salbei.md", "# Salbei\nPairing-Wissen");
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/_Index.md", "Meta — wird übersprungen");

    $this->rustSrc = sys_get_temp_dir() . '/fa_vault_ctx_' . uniqid() . '.rs';
    file_put_contents($this->rustSrc, 'const HAUPTZUTAT_TO_DOMAIN: &[(&str, &str)] = &[("milch", "Milchprodukte"), ("sahne", "Milchprodukte")];');
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg(dirname($this->vault)));
    @unlink($this->rustSrc);
});

it('importiert Klasse A, ist idempotent und zählt version bei Inhalts-Änderung hoch', function () {
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])
        ->assertSuccessful();

    expect(DB::table('foodalchemist_knowledge_documents')->count())->toBe(3)
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'pairing.salbei')->value('kategorie'))->toBe('pairing')
        ->and(DB::table('foodalchemist_knowledge_aliases')->count())->toBe(2)
        ->and(DB::table('foodalchemist_knowledge_routings')->count())->toBe(8);

    // 2. Lauf: nichts ändert sich (idempotent)
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();
    expect(DB::table('foodalchemist_knowledge_documents')->where('version', '>', 1)->count())->toBe(0);

    // Inhalts-Änderung ⇒ version+1, Hash neu
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/salbei.md", "# Salbei\nNEUES Pairing-Wissen");
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();
    $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', 'pairing.salbei')->first();
    expect($doc->version)->toBe(2)
        ->and($doc->inhalt_md)->toContain('NEUES');
});

it('verdrahtet Anker über quelle_pfad und löst Slug-Dubletten per Suffix', function () {
    // Anker, der auf die Salbei-MD zeigt (wie der Slice-Import ihn anlegt)
    DB::table('foodalchemist_vocab_pairing_ankers')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'slug' => 'salbei', 'display_de' => 'Salbei',
        'quelle_pfad' => '07_WISSEN/07.02_Flavor_Pairing/pairings/salbei.md',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Dublette: zwei Dateien, ein Slug
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/schwarzer-knoblauch.md", "# Alt");
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/schwarzer_knoblauch.md", "# Neu");

    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();

    expect(DB::table('foodalchemist_vocab_pairing_ankers')->where('slug', 'salbei')->whereNotNull('knowledge_document_id')->exists())->toBeTrue()
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'like', 'pairing.schwarzer_knoblauch%')->count())->toBe(2);
});
