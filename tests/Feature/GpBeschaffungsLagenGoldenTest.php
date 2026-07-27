<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Support\SignalCockpit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H2c — V-014: die Ampel trennt die Beschaffungs-Lagen, die die
 * Ursachen-Kette (`SignalCauseService::gpBeschaffung`) längst unterscheidet.
 *
 * Golden-Riegel VOR dem Umbau (Bau-Rahmen: „kein Verhaltenswechsel ohne Golden-Test"),
 * denn `gp_ohne_lead` ist die **Adressierung eines schreibenden Fixers**: der Satz, den
 * die Metrik listet, ist der Satz, den `SignalFixService` mutiert. Verschiebt sich die
 * Menge still, greift ein Massen-Fix woanders hin als gestern.
 *
 * Die alte Fassung `lead_la_supplier_item_id IS NULL OR n_las_total = 0` wirft drei
 * fachlich verschiedene Lagen in einen Topf — und benutzt dafür den **Zähler**
 * `n_las_total` statt der Struktur-Tabelle, aus der der Money-Path seine LAs holt
 * (`RecipeRecomputeService::laMitPreis`). Dieser Riegel hält jede Lage einzeln fest,
 * damit belegbar ist, welche sich bewegt:
 *
 *  | Lage                                    | alt (lumped)     | neu                    | Fixer     |
 *  |-----------------------------------------|------------------|------------------------|-----------|
 *  | kein LA am GP                           | drin             | `gp_kein_la`           | keiner    |
 *  | LAs da, keiner bepreist, kein Lead      | drin             | `gp_kein_preis`        | keiner    |
 *  | bepreister LA da, kein Lead gewählt     | drin             | `gp_kein_lead`         | `lead_la` |
 *  | Lead gewählt + bepreist, Zähler auf 0   | **drin (falsch)**| in keiner Lage         | —         |
 *  | Lead gewählt, kein LA bepreist          | draußen          | `gp_lead_ohne_preis`   | `lead_la` |
 *
 * Die vierte Zeile ist die einzige Verschiebung: ein GP mit gesetztem, bepreistem Lead
 * wird heute als „ohne Lead-LA" gemeldet, nur weil der inkrementell geführte Zähler
 * `n_las_total` auf 0 stehengeblieben ist (im Bestand 2 von 183 approved GPs).
 *
 * Die fünfte Zeile ist die Abgrenzung nach unten: sie gehört der H2b-Metrik
 * `gp_lead_ohne_preis` und darf in keiner der drei Lagen mitzählen (sonst zählt die
 * Ampel dieselbe Arbeit zweimal). Sie fehlte in der ersten Fassung dieser Fixture und
 * wurde erst von der Bestandsmessung sichtbar — ohne sie hätte die Lage `kein_preis`
 * still 8 zusätzliche GPs aufgenommen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);

    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Beschaffungs-Lieferant',
    ]);

    // ID-Versatz zwischen Artikel und GP: in einer frischen Test-DB starten beide
    // Auto-Increments bei 1, eine auf die falsche Spalte verbogene EXISTS-Korrelation
    // träfe dieselben Zeilen und der Riegel wäre blind (Lehre aus der M4-Gegenprobe
    // in H2b). Diese Artikel hängen an keinem GP und haben keinen Preis.
    for ($i = 0; $i < 7; $i++) {
        FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => 'ID-Versatz '.$i, 'unit_code' => 'kg',
        ]);
    }

    /**
     * Ein approved GP in genau einer Beschaffungs-Lage.
     *
     * @param  bool  $mitLa        Struktur-Zeile LA↔GP anlegen (die Quelle des Money-Paths)
     * @param  bool  $bepreist     aktive Preiszeile am LA (GL-11 T1: Status 0/2, > 0)
     * @param  bool  $mitLead      `lead_la_supplier_item_id` auf diesen LA setzen
     * @param  ?int  $zaehler      `n_las_total` erzwingen (null = passend zur Wahrheit)
     */
    $this->mkLage = function (string $name, bool $mitLa, bool $bepreist = false, bool $mitLead = false, ?int $zaehler = null) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = null;

        if ($mitLa) {
            $la = FoodAlchemistSupplierItem::create([
                'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
                'designation' => $name.' Artikel', 'unit_code' => 'kg',
            ]);
            FoodAlchemistSupplierItemStructure::create([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
            ]);
            if ($bepreist) {
                FoodAlchemistPrice::create([
                    'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
                    'is_blocked' => false, 'status' => '0', 'price' => 9.5,
                ]);
            }
        }

        $gp->update([
            'status' => 'approved',
            'requires_la' => true,
            'lead_la_supplier_item_id' => $mitLead && $la !== null ? $la->id : null,
            'n_las_total' => $zaehler ?? ($mitLa ? 1 : 0),
        ]);

        return $gp->fresh();
    };

    /** Namen, die eine Metrik listet — dieselbe Quelle, aus der der Fixer seinen Satz nimmt. */
    $this->satz = fn (string $metrik): array => collect($this->dq->betroffene($this->rootTeam, $metrik, 100))
        ->pluck('name')->sort()->values()->all();

    /** Die alte, lumped Fassung — als Vergleichsmaßstab im Test festgehalten, nicht im Code. */
    $this->alteFassung = fn (): array => FoodAlchemistGp::visibleToTeam($this->rootTeam)
        ->where('status', 'approved')->where('requires_la', true)
        ->where(fn ($w) => $w->whereNull('lead_la_supplier_item_id')->orWhere('n_las_total', 0))
        ->orderBy('name')->pluck('name')->all();
});

/** Alle sechs Lagen auf einmal — die Tabelle im Dateikopf als Fixture. */
function alleLagen(object $t): void
{
    ($t->mkLage)('A Ohne LA', mitLa: false);
    ($t->mkLage)('B LA unbepreist', mitLa: true, bepreist: false);
    ($t->mkLage)('C Bepreist ohne Lead', mitLa: true, bepreist: true);
    ($t->mkLage)('D Lead bepreist', mitLa: true, bepreist: true, mitLead: true);
    ($t->mkLage)('E Lead bepreist Zaehler luegt', mitLa: true, bepreist: true, mitLead: true, zaehler: 0);
    // F ist der Fall, den die erste Fassung dieser Fixture NICHT enthielt und den erst
    // die Bestandsmessung zeigte (8 von 11): Lead gewählt, aber kein LA bepreist. Er
    // gehört `gp_lead_ohne_preis` (H2b) und darf in keiner der drei Lagen auftauchen,
    // sonst zählt die Ampel ihn doppelt.
    ($t->mkLage)('F Lead unbepreist', mitLa: true, bepreist: false, mitLead: true);
}

// ── Der Freeze: was die alte Fassung sah ────────────────────────────────────

it('haelt fest, was die alte lumped Fassung meldete (inkl. des Zaehler-Fehlalarms)', function () {
    alleLagen($this);

    // Vier von fünf Lagen — Fall D fällt zu Recht heraus, Fall E ist der Fehlalarm.
    expect(($this->alteFassung)())->toBe([
        'A Ohne LA', 'B LA unbepreist', 'C Bepreist ohne Lead', 'E Lead bepreist Zaehler luegt',
    ]);
});

// ── Die Zusicherungen, die der Umbau neu gibt ───────────────────────────────

it('teilt die drei Lagen trennscharf auf (jede GP in genau einer)', function () {
    alleLagen($this);

    expect(($this->satz)('gp_kein_la'))->toBe(['A Ohne LA'])
        ->and(($this->satz)('gp_kein_preis'))->toBe(['B LA unbepreist'])
        ->and(($this->satz)('gp_kein_lead'))->toBe(['C Bepreist ohne Lead']);
});

it('verliert dabei keinen Fall — die drei Lagen deckten die alte Menge ohne den Fehlalarm', function () {
    alleLagen($this);

    $neu = collect([
        ($this->satz)('gp_kein_la'), ($this->satz)('gp_kein_preis'), ($this->satz)('gp_kein_lead'),
    ])->flatten()->sort()->values()->all();

    // Genau EINE Verschiebung: der Zähler-Fehlalarm (E) ist weg, alles andere bleibt.
    expect($neu)->toBe(['A Ohne LA', 'B LA unbepreist', 'C Bepreist ohne Lead'])
        ->and(array_values(array_diff(($this->alteFassung)(), $neu)))->toBe(['E Lead bepreist Zaehler luegt']);
});

it('zaehlt einen gesetzten, aber unbepreisten Lead nicht doppelt (Abgrenzung zu gp_lead_ohne_preis)', function () {
    alleLagen($this);

    // Fall F gehört der H2b-Metrik — dort ist er sichtbar …
    expect(($this->satz)('gp_lead_ohne_preis'))->toContain('F Lead unbepreist')
        // … und in keiner der drei Beschaffungs-Lagen, obwohl er „LAs da, keiner bepreist" erfüllt.
        ->and(($this->satz)('gp_kein_preis'))->not->toContain('F Lead unbepreist')
        ->and(($this->satz)('gp_kein_la'))->not->toContain('F Lead unbepreist')
        ->and(($this->satz)('gp_kein_lead'))->not->toContain('F Lead unbepreist');
});

it('haelt Zaehl-Query und betroffene() je Lage deckungsgleich (eine Regel-Stelle)', function () {
    alleLagen($this);

    $ebenen = $this->dq->messeAlleEbenen($this->rootTeam);
    $wert = function (string $key) use ($ebenen): int {
        foreach ($ebenen as $ebene) {
            foreach ($ebene['metriken'] as $m) {
                if ($m['key'] === $key) {
                    return (int) $m['wert'];
                }
            }
        }
        throw new RuntimeException("Metrik {$key} nicht gefunden");
    };

    foreach (['gp_kein_la', 'gp_kein_preis', 'gp_kein_lead'] as $key) {
        expect($wert($key))->toBe(count(($this->satz)($key)), "Metrik {$key}: Zählung ≠ Liste");
    }
    expect($wert('gp_kein_la') + $wert('gp_kein_preis') + $wert('gp_kein_lead'))->toBe(3);
});

it('gibt den Auto-Fix-Knopf nur der Lage, in der er etwas bewegen kann', function () {
    $sig = fn (string $metrik) => new FoodAlchemistSignal([
        'type' => SignalTyp::DatenqualitaetGpLa, 'severity' => SignalSeverity::Warnung,
        'title' => 't', 'payload' => ['metrik' => $metrik],
    ]);

    // Nur „Auswahl offen" ist fixbar: der lead_la-Fixer braucht einen bepreisten Artikel,
    // den er setzen kann. Ohne LA / ohne Preis kann er nichts, und der Knopf verspräche
    // eine Reparatur, die keine Zahl bewegt (V-014).
    expect(SignalCockpit::planFor($sig('gp_kein_lead'))['fixer'])->toBe('lead_la')
        ->and(SignalCockpit::planFor($sig('gp_kein_la')))->toBeNull()
        ->and(SignalCockpit::planFor($sig('gp_kein_preis')))->toBeNull();
});

it('haengt das Detektor-Signal an die fixbare Lage (der Knopf trifft nur, wo er wirkt)', function () {
    alleLagen($this);

    // SignalDetektorService::datenqualitaetGpLa trägt kein `metrik` im Payload, nur den
    // stabilen dedup_key — die Ableitung muss auf die fixbare Lage zeigen, sonst greift
    // ein Massen-Fix über Beschaffungs-Lücken, in denen er nichts setzen kann.
    $sig = new FoodAlchemistSignal([
        'type' => SignalTyp::DatenqualitaetGpLa, 'severity' => SignalSeverity::Warnung,
        'title' => 't', 'dedup_key' => 'datenqualitaet-gp-ohne-la', 'payload' => [],
    ]);

    $metrik = SignalCockpit::metrik($sig);
    expect($metrik)->toBe('gp_kein_lead')
        ->and(($this->satz)($metrik))->toBe(['C Bepreist ohne Lead']);
});
