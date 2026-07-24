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
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'pairing.salbei')->value('category'))->toBe('pairing')
        ->and(DB::table('foodalchemist_knowledge_aliases')->count())->toBe(2)
        ->and(DB::table('foodalchemist_knowledge_routings')->count())->toBe(12);   // Spec 19 E6.4: +foodbook.plan/concept.plan (je 2)

    // 2. Lauf: nichts ändert sich (idempotent)
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();
    expect(DB::table('foodalchemist_knowledge_documents')->where('version', '>', 1)->count())->toBe(0);

    // Inhalts-Änderung ⇒ version+1, Hash neu
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/salbei.md", "# Salbei\nNEUES Pairing-Wissen");
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();
    $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', 'pairing.salbei')->first();
    expect($doc->version)->toBe(2)
        ->and($doc->content_md)->toContain('NEUES');
});

it('verdrahtet Anker über quelle_pfad und löst Slug-Dubletten per Suffix', function () {
    // Anker, der auf die Salbei-MD zeigt (wie der Slice-Import ihn anlegt)
    DB::table('foodalchemist_vocab_pairing_anchors')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'slug' => 'salbei', 'display_de' => 'Salbei',
        'source_path' => '07_WISSEN/07.02_Flavor_Pairing/pairings/salbei.md',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Dublette: zwei Dateien, ein Slug
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/schwarzer-knoblauch.md", "# Alt");
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/schwarzer_knoblauch.md", "# Neu");

    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();

    expect(DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', 'salbei')->whereNotNull('knowledge_document_id')->exists())->toBeTrue()
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'like', 'pairing.schwarzer_knoblauch%')->count())->toBe(2);
});

it('Import-Guard: etabliert imported_hash und überschreibt ein in der App editiertes Doc NICHT (App-wins)', function () {
    // 1. Erst-Import → Guard-Baseline imported_hash == content_hash
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();
    $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', 'pairing.salbei')->first();
    expect($doc->imported_hash)->not->toBeNull()->and($doc->imported_hash)->toBe($doc->content_hash);

    // 2. App-Edit simulieren (wie Browser::save: content_hash ändert sich, imported_hash NICHT)
    $appInhalt = "# Salbei\nIN DER APP KURATIERT";
    DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update([
        'content_md' => $appInhalt, 'content_hash' => hash('sha256', $appInhalt), 'version' => $doc->version + 1,
    ]);

    // 3. Vault ändert sich ebenfalls
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/salbei.md", "# Salbei\nVAULT-NEU");

    // 4. Re-Import ohne --force → App-Kuration bleibt geschützt
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();
    $after = DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->first();
    expect($after->content_md)->toContain('IN DER APP KURATIERT')
        ->and($after->content_md)->not->toContain('VAULT-NEU');
});

it('Import-Guard: --force zieht den Vault-Stand über ein editiertes Doc und aktualisiert den Snapshot', function () {
    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc])->assertSuccessful();
    $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', 'pairing.salbei')->first();
    $appInhalt = "# Salbei\nAPP-EDIT";
    DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update([
        'content_md' => $appInhalt, 'content_hash' => hash('sha256', $appInhalt),
    ]);
    file_put_contents("{$this->vault}/07.02_Flavor_Pairing/pairings/salbei.md", "# Salbei\nVAULT-NEU");

    $this->artisan('foodalchemist:knowledge-import', ['--vault' => $this->vault, '--rust-src' => $this->rustSrc, '--force' => true])->assertSuccessful();
    $after = DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->first();
    expect($after->content_md)->toContain('VAULT-NEU')
        ->and($after->imported_hash)->toBe($after->content_hash);   // Snapshot nachgezogen ⇒ wieder „clean"
});
