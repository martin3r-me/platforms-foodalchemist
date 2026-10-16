<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Platform\FoodAlchemist\Jobs\ProduktpassLesenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\Warteschlange;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Interaktive Schlange (16.10.2026): nutzerausgelöste Einzeljobs, hinter denen ein Mensch wartet (Produktpass lesen), laufen NICHT in der Schlange
 * der Massenläufe. Anlass: auf io stand der erste Produktpass hinter 648 Non-Food-KI-Stapeln in `default` (ein Worker).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    Storage::fake('public');
    Storage::fake('local');
    Queue::fake();
    $this->kurator = $this->makeUser($this->rootTeam, 'Kurator M', 'member');
    $lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Lieferant', 'stamm_class' => StammPaket::PAKET]);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $lieferant->id, 'designation' => 'Mehl', 'stamm_class' => StammPaket::PAKET]);
    $this->hochladen = fn () => app(ProduktpassService::class)->hochladen($this->rootTeam, $this->la, UploadedFile::fake()->create('pass.pdf', 100, 'application/pdf'), $this->kurator->id);
});

it('Produktpass-Lesen läuft auf der interaktiven Schlange — auch beim erneuten Lesen —, nie auf der Anreicherungs-Schlange der Massenläufe', function () {
    config(['foodalchemist.queue.interaktiv' => 'fa-interaktiv', 'foodalchemist.queue.anreichern' => 'fa-anreichern']);

    $pass = ($this->hochladen)();
    app(ProduktpassService::class)->erneutLesen($this->rootTeam, $pass->refresh(), $this->kurator->id);

    Queue::assertPushed(ProduktpassLesenJob::class, 2);
    Queue::assertPushed(ProduktpassLesenJob::class, fn ($job) => $job->queue === 'fa-interaktiv');
    Queue::assertNotPushed(ProduktpassLesenJob::class, fn ($job) => $job->queue === 'fa-anreichern' || $job->queue === null);
});

it('Rückfall ohne Konfiguration: keine Schlange gesetzt, der Job läuft wie bisher auf default (der Deploy ändert nichts, bis Worker + Env stehen)', function () {
    $pass = ($this->hochladen)();

    Queue::assertPushed(ProduktpassLesenJob::class, fn ($job) => $job->queue === null);
    expect(Warteschlange::interaktiv())->toBeNull();
});

it('Die Schlangen bleiben getrennt: interaktiv ist ein eigener Schlüssel, leere/Leerzeichen-Werte fallen auf default zurück', function () {
    config(['foodalchemist.queue.interaktiv' => '  ', 'foodalchemist.queue.anreichern' => 'fa-anreichern']);
    expect(Warteschlange::interaktiv())->toBeNull()->and(Warteschlange::anreichern())->toBe('fa-anreichern');

    config(['foodalchemist.queue.interaktiv' => 'fa-interaktiv']);
    expect(Warteschlange::interaktiv())->toBe('fa-interaktiv')->and(Warteschlange::anreichern())->toBe('fa-anreichern');
});
