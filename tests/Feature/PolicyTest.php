<?php

use Illuminate\Support\Facades\Gate;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M8-02: Policy-Vertrag (D1 als Gate) + statischer Trait-Vertrag über ALLE
 * Models (LogsActivity/BelongsToTeamHierarchy/HasUuidV7/SoftDeletes — der
 * LogsActivity-Stub ist in der Sandbox no-op, deshalb statisch statt
 * verhaltensbasiert; CLAUDE.md-Wissensbasis).
 */
it('Policy: Kind sieht Eltern-Daten (view), darf sie aber nie kuratieren (update/delete)', function () {
    $this->seedTeamHierarchy();
    $rootGp = $this->makeGp($this->rootTeam, 'Root-Katalog-GP');
    $rootUser = $this->makeUser($this->rootTeam, 'Root');
    $kindUser = $this->makeUser($this->childA, 'Kind A');
    $geschwisterGp = $this->makeGp($this->childB, 'B-GP');

    expect(Gate::forUser($rootUser)->allows('view', $rootGp))->toBeTrue()
        ->and(Gate::forUser($rootUser)->allows('update', $rootGp))->toBeTrue()
        ->and(Gate::forUser($kindUser)->allows('view', $rootGp))->toBeTrue()      // Kette aufwärts
        ->and(Gate::forUser($kindUser)->allows('update', $rootGp))->toBeFalse()   // Curate nur Besitzer
        ->and(Gate::forUser($kindUser)->allows('delete', $rootGp))->toBeFalse()
        ->and(Gate::forUser($kindUser)->allows('view', $geschwisterGp))->toBeFalse();  // Geschwister nie
});

it('Policy gilt auch für Rezepte (geteiltes Modell, beide Sichten)', function () {
    $this->seedTeamHierarchy();
    $kindUser = $this->makeUser($this->childA, 'Kind A');
    $rootRezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'r', 'name' => 'Fond: Root', 'status' => 'approved']);

    expect(Gate::forUser($kindUser)->allows('view', $rootRezept))->toBeTrue()
        ->and(Gate::forUser($kindUser)->allows('update', $rootRezept))->toBeFalse();
});

it('Policy gilt für Foodbooks (M11-10): Kind sieht Eltern-Foodbook, kuratiert es aber nicht', function () {
    $this->seedTeamHierarchy();
    $kindUser = $this->makeUser($this->childA, 'Kind A');
    $rootFb = app(\Platform\FoodAlchemist\Services\FoodbookService::class)->create($this->rootTeam, ['label' => 'Root-FB']);

    expect(Gate::forUser($kindUser)->allows('view', $rootFb))->toBeTrue()        // Kette aufwärts
        ->and(Gate::forUser($kindUser)->allows('update', $rootFb))->toBeFalse()  // Curate nur Besitzer
        ->and(Gate::forUser($kindUser)->allows('delete', $rootFb))->toBeFalse();
});

it('Trait-Vertrag: ALLE Models tragen LogsActivity + BelongsToTeamHierarchy + HasUuidV7 + SoftDeletes', function () {
    // Satelliten scopen BEWUSST über ihr Eltern-Aggregat (Zugriff nur via
    // GP-/Rezept-Relation bzw. TeamSettingsService) — kein eigener Team-Scope
    $satelliten = [
        'FoodAlchemistGpCountUnitDefault', 'FoodAlchemistGpForm', 'FoodAlchemistGpLaPreference', 'FoodAlchemistMatchProposal',
        'FoodAlchemistRecipeIngredient', 'FoodAlchemistRecipeNiveauEignung', 'FoodAlchemistRecipeSektorEignung',
        'FoodAlchemistConceptSektorEignung', 'FoodAlchemistTeamSetting',
        'FoodAlchemistCanvasEntry',                                    // Zugriff nur via Canvas-Aggregat (CanvasService)
        'FoodAlchemistPlanningFrameSlot', 'FoodAlchemistPlanningFrameRule', // R4.1: Zugriff nur via Frame-Aggregat (PlanningFrameService-Guard)
    ];
    /**
     * Messungen und Urteile: haben ein team_id, dürfen aber NICHT vererben — sie
     * gehören dem messenden bzw. urteilenden Team, nicht dem Katalog.
     *  - `FoodAlchemistSignalSnapshot` (Spec 21 · E1): eine Zeile ist der Zähler-Stand
     *    aus der Sicht des messenden Teams. Ein Kind-Team zählt den Eltern-Katalog schon
     *    mit; blendete man zusätzlich die Eltern-Zeitreihe ein, lägen zwei Serien
     *    derselben Objekte übereinander.
     *  - `FoodAlchemistRecipeFinding` (Spec 21 · S5a): ein KI-Befund am Rezept **plus die
     *    menschliche Entscheidung darüber** (`offen`/`uebernommen`/`verworfen`). Eltern und
     *    Kind dürfen zum selben Eltern-Rezept getrennt urteilen; geerbte Sichtbarkeit
     *    würde ein fremdes „verworfen" in die eigene Inbox mischen und den Befund dort
     *    stumm stellen.
     *  - `FoodAlchemistBulkRun` (Spec 22 · H3a): ein *Vorgang* des Teams, das ihn gestartet
     *    hat — kein Katalog-Datensatz. Die strikte Lesart ist nicht neu, sondern die, die
     *    `BulkEnrichService::runStatus` und `IngestStatusService::laeufe` seit jeher
     *    implementieren (`where team_id`); der Trait hätte die Sichtbarkeit *geändert*, statt
     *    sie abzubilden — ein Kind-Team sähe die Provider-Läufe des Eltern-Teams in seiner
     *    eigenen Fortschritts-Anzeige.
     *  - `FoodAlchemistBulkProposal` + `FoodAlchemistBulkGpProposal` (Spec 22 · H3c-2):
     *    dieselbe Lesart wie der Lauf, an dem sie hängen — ein Vorschlag ist Teil EINES
     *    Vorgangs samt der menschlichen Entscheidung darüber (`offen` → `uebernommen` |
     *    `verworfen`), kein vererbbarer Katalog-Eintrag. Mit Vererbung fände ein Kind-Team
     *    die offenen Vorschläge des Eltern-Teams in seiner Review-Liste und könnte sie
     *    annehmen. ⚠️ Die Review-Queue liest sie heute trotzdem über die Team-Kette, während
     *    `BulkEnrichService` strikt filtert — dieselbe Tabelle, zwei Sicht-Weiten; als Befund
     *    hochgegeben, nicht hier entschieden (das wäre ein Sichtbarkeits-Wechsel).
     */
    $messreihen = [
        'FoodAlchemistSignalSnapshot', 'FoodAlchemistRecipeFinding', 'FoodAlchemistBulkRun',
        'FoodAlchemistBulkProposal', 'FoodAlchemistBulkGpProposal',
        // Schicht-3-Konformitätsbefund: Urteil EINES Teams über ein Artefakt (wie RecipeFinding) —
        // team_id vorhanden, aber NICHT vererbt (sonst mischte ein „verworfen" des Eltern-Teams in die Kind-Inbox).
        'FoodAlchemistConformanceFinding',
        // Spec 82 · Schritt 4d: Preislisten-Import eines Mandanten — Vorgang des hochladenden Teams (Lauf) und
        // dessen Zeilen. Ein Kind-Team darf die Preisliste des Oberteams weder sehen noch anwenden.
        'FoodAlchemistPriceImportRun', 'FoodAlchemistPriceImportLine',
        // Spec 82 · Schritt 5: Teamprofil (Fakten über die Küche) — strikt team-eigen, nicht vererbt. Ein Unter-Team
        // pflegt sein Profil selbst, das Oberteam liest es nur über die Brille (TeamProfilService::darfLesen).
        'FoodAlchemistTeamProfile', 'FoodAlchemistTeamProfileEquipment', 'FoodAlchemistTeamProfileArea',
    ];
    $modelDir = dirname((new ReflectionClass(FoodAlchemistRecipe::class))->getFileName());
    $fehlend = [];
    foreach (glob($modelDir . '/*.php') as $datei) {
        $klasse = 'Platform\\FoodAlchemist\\Models\\' . basename($datei, '.php');
        if (! class_exists($klasse) || (new ReflectionClass($klasse))->isAbstract()) {
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistProductionEvent') {
            // Spec 35 K4: append-only Fachprotokoll (eigenes id/created_at, $timestamps=false).
            // Bewusst ohne die vier Standard-Traits — kein zweites Activity-Log, kein Team-Aggregat,
            // kein Soft-Delete. Ein Ereignis wird geschrieben, nie geändert.
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistRuleVersion') {
            // Spec 81 C2: append-only Fassung einer Regel (wie ProductionEvent). Lebt nur über die Regel
            // (FoodAlchemistRule trägt alle vier Traits), wird nie geändert, kein eigener Team-Scope.
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistCascadeRecipeDependency') {
            // Technische Kaskaden-interne Join-Tabelle (parent_step→child_step→ingredient). Team-scoped
            // über team_id + den Eltern-Cascade-Run; kein eigener Lebenszyklus (lebt/stirbt mit dem Run),
            // kein uuid/deleted_at, kein Audit — bewusst ohne die vier Standard-Traits (wie ProductionEvent).
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistStorageBinItem') {
            // Spec 66b: reiner Zuordnungs-ZEIGER Grundprodukt → Stellplatz je (Team, Lagerort), wie
            // ActiveOutlet. unique(team_id, inventory_location_id, gp_id) + updateOrCreate — eine
            // trashed Zeile wäre für updateOrCreate unsichtbar und kollidierte am Unique (dieselbe Falle).
            // Kein eigener Lebenszyklus (lebt/stirbt mit Stellplatz bzw. Umsortieren), keine externe ID,
            // kein kuratierter Datensatz; team-strikt über where team_id, nicht vererbt. Audit-Wert
            // trägt der Stellplatz selbst (FoodAlchemistStorageBin hat alle vier).
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistPlattformEinstellung') {
            // Spec 82 §13 B4: plattformweite FA-Einstellung (Schlüssel/Wert) — gehört weder einem Team noch einem Datensatz:
            //  - kein BelongsToTeamHierarchy: es gibt bewusst kein team_id, die Zeile gilt für die ganze Plattform;
            //  - kein HasUuidV7/SoftDeletes: adressiert wird über den eindeutigen Schlüssel, das Entfernen eines Werts
            //    (Master-Team zurück auf ENV) ist ein hartes Löschen, nie ein Zwischenzustand;
            //  - LogsActivity: trägt es (seit dem Protokoll-Fix): created/updated/deleted automatisch, dazu der Klartext-Eintrag über PlattformProtokoll.
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistPlanPreis') {
            // Spec 82 §13 D: Gebühren-Preis je Baustein, append-only mit „gültig ab“ — gehört weder einem Team noch einem Datensatz:
            //  - kein BelongsToTeamHierarchy: bewusst kein team_id, der Preis gilt für die ganze Plattform;
            //  - kein SoftDeletes: Zeilen werden nie gelöscht, die Historie bleibt (Preis-Snapshot der Tageswerte verweist darauf);
            //  - kein HasUuidV7: adressiert wird über (baustein, gültig ab), nie über eine externe ID;
            //  - LogsActivity: trägt es (Protokoll-Subjekt für PlattformProtokoll, plan_preis_gesetzt), die Zeile trägt zusätzlich geaendert_von.
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistMandant') {
            // Spec 82 §9/§13 B3 + G: Verwaltungszeile je Mandant (Status, Modus, CRM-Firma), eine Zeile je Kunden-Hauptteam.
            //  - kein BelongsToTeamHierarchy: die Zeile ist Plattform-Verwaltung über den Mandanten, kein Inhalt des Teams;
            //    scopeVisibleToTeam würde `team_id IS NULL` + Eltern-Zeilen einORen. Gelesen wird nur über MandantenService
            //    (Plattform-Admin bzw. Master-Admin), nie über den Team-Scope eines Mandanten.
            //  - kein SoftDeletes/HasUuidV7: unique(team_id), adressiert über team_id, nie über eine externe ID.
            //  - LogsActivity: trägt es (seit dem Protokoll-Fix), die Notiz ist über $ignoreAttributes aus dem Protokoll ausgenommen;
            //    jede Modus-/Statusänderung geht zusätzlich als Klartext über PlattformProtokoll, die Zeile trägt Wer und Wann.
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistMandantExport') {
            // Spec 82 §13 C3: Datenexport eines Mandanten (Zustand, Ablage, Größe, Prüfsumme, Manifest), eine Zeile je Export.
            //  - kein BelongsToTeamHierarchy: Plattform-Verwaltung über den Mandanten, kein Inhalt des Teams; gelesen und geschrieben nur
            //    über MandantExportService (eigene Rechteprüfung: Plattform-Admin/Inhaber lösen aus, nur Inhaber/Admin laden).
            //  - kein SoftDeletes/HasUuidV7: adressiert über die Id (Download-Route), Verlauf bleibt.
            //  - LogsActivity: trägt es (created/updated mit Größe/Prüfsumme/Zustand; der Inhalt steht nur in der Datei).
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistMandantBeendenLauf') {
            // Spec 82 §13 C3: Fortschritt des Ablaufs „Mandant beenden“, eine Zeile je Lauf je Kunden-Hauptteam.
            //  - kein BelongsToTeamHierarchy: Plattform-Verwaltung über den Mandanten, kein Inhalt des Teams; gelesen und
            //    geschrieben nur über MandantBeendenService (Plattform-Admin), nie über den Team-Scope eines Mandanten.
            //  - kein SoftDeletes/HasUuidV7: adressiert über die Id, Verlauf bleibt (abgebrochen/abgeschlossen statt löschen).
            //  - LogsActivity: trägt es; die Klartext-Einträge laufen zusätzlich über PlattformProtokoll mit dem Lauf als Subjekt.
            continue;
        }
        if (in_array(class_basename($klasse), ['FoodAlchemistTeamPaket', 'FoodAlchemistPaketKopie'], true)) {
            // Spec 82 §13 I: Paket „Grundeinstellungen" — Buchungs-Zeile (Team × Paket) und Zuordnung Quelle → Kopie je Mandant.
            //  - kein BelongsToTeamHierarchy: Plattform-Verwaltung über den Mandanten (nur PaketGrundeinstellungenService, Plattform-Admin),
            //    nie über den Team-Scope; eine Vererbung an Unter-Teams passiert über die kopierten Zeilen selbst.
            //  - kein SoftDeletes/HasUuidV7: adressiert über Team + Paket bzw. Quelle; die Zuordnung ist das Gedächtnis der Idempotenz.
            //  - LogsActivity: nur FoodAlchemistTeamPaket (Subjekt des Plattform-Protokolls); die Zuordnung ist reine Hilfstabelle.
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistTeamStammOptin') {
            // Spec 82 §12 A3: Schalter „Mandant hat das Stamm-Paket gebucht", eine Zeile je Mandant.
            //  - kein BelongsToTeamHierarchy: die Vererbung an Unter-Teams löst StammPaket::istFreigeschaltet
            //    über die Team-Kette selbst auf; scopeVisibleToTeam würde `team_id IS NULL` + Eltern-Zeilen
            //    einORen — für einen Buchungsschalter eine Sichtbarkeits-AUSWEITUNG.
            //  - kein SoftDeletes: unique(team_id) — abbestellen löscht hart, eine trashed Zeile würde
            //    freischalten (firstOrCreate) am Unique scheitern lassen.
            //  - kein HasUuidV7: adressiert wird über team_id, nie über eine externe ID.
            //  - LogsActivity: trägt es (seit dem Protokoll-Fix); Buchen/Abbestellen gehen zusätzlich als Klartext über PlattformProtokoll,
            //    die Zeile trägt opted_in_at/opted_in_by.
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistAbo') {
            // Spec 82 §12 A1: Abo = Sammlung des Master-Teams an einen Mandanten. Trägt LogsActivity + HasUuidV7.
            //  - kein BelongsToTeamHierarchy: die Zeile gehört ZWEI Teams (master_team_id, mandant_team_id), es gibt
            //    kein team_id. Gelesen wird nur über AboService (Master: seine, Mandant: die seines Kunden-Hauptteams);
            //    scopeVisibleToTeam würde Eltern-Ketten einORen — eine Sichtbarkeits-AUSWEITUNG.
            //  - kein SoftDeletes: Ende eines Abos ist ein Status (gekuendigt | zurueckgezogen, mit beendet_at/-_von);
            //    unique(mandant, sammlung) + Wiederaufnahme per firstOrNew — eine trashed Zeile kollidierte am Unique.
            $traits = collect(class_uses_recursive($klasse))->keys()->map(fn ($t) => class_basename($t));
            foreach (['LogsActivity', 'HasUuidV7'] as $pflicht) {
                if (! $traits->contains($pflicht)) {
                    $fehlend[] = class_basename($klasse) . " ohne {$pflicht}";
                }
            }
            continue;
        }
        if (class_basename($klasse) === 'FoodAlchemistActiveOutlet') {
            // Kein Katalog-Datensatz, sondern der persistierte ZEIGER der Betriebs-„Brille" je
            // (User, Team) — durabler Zwilling der HTTP-Session (ActiveOutletContext:14-17).
            // Die Absicht stand schon am Model selbst („bewusst schlank", :10), nur nie hier.
            //  - kein BelongsToTeamHierarchy: die Zeile gehört EINEM User in EINEM Team.
            //    scopeVisibleToTeam ORt `team_id IS NULL` + die Eltern-Kette ein — für einen
            //    User-Zeiger wäre das eine Sichtbarkeits-AUSWEITUNG, dieselbe Lesart wie bei
            //    $messreihen oben („team_id vorhanden, darf aber NICHT vererben").
            //  - kein SoftDeletes: unique(user_id, team_id) + updateOrCreate — eine trashed
            //    Zeile wäre für updateOrCreate unsichtbar und kollidierte am Unique; genau die
            //    Falle, die GpFormService:43 mit withTrashed() umgeht. Kein Nutzen, nur Risiko.
            //  - kein HasUuidV7: adressiert wird über (user_id, team_id), nie über eine externe
            //    ID — Web-Dropdown und MCP (outlets.SET_ACTIVE) reichen User+Team durch.
            //  - kein LogsActivity: ein UI-Umschalter, kein kuratierter Datensatz.
            // Abgrenzung nach oben: die kuratierten Geschwister FoodAlchemistOutletSetting und
            // FoodAlchemistTeamSetting tragen alle vier — ActiveOutlet ist kein Setting,
            // sondern ein Zeiger auf eines.
            continue;
        }

        $traits = collect(class_uses_recursive($klasse))->keys()->map(fn ($t) => class_basename($t));
        $ohneHierarchie = array_merge($satelliten, $messreihen);
        $pflichten = in_array(class_basename($klasse), $ohneHierarchie, true)
            ? ['LogsActivity', 'HasUuidV7', 'SoftDeletes']
            : ['LogsActivity', 'BelongsToTeamHierarchy', 'HasUuidV7', 'SoftDeletes'];
        if (in_array(class_basename($klasse), ['FoodAlchemistPrice', 'FoodAlchemistPriceImportLine', 'FoodAlchemistTeamProfileEquipment', 'FoodAlchemistTeamProfileArea'], true)) {
            // Bewusst ohne LogsActivity: Massendaten (221k), Audit über Import-Reports (M2-Entscheid im Model);
            // die Import-Zeilen hängen am Lauf (er trägt LogsActivity)
            $pflichten = array_diff($pflichten, ['LogsActivity']);
        }
        foreach ($pflichten as $pflicht) {
            if (! $traits->contains($pflicht)) {
                $fehlend[] = class_basename($klasse) . " ohne {$pflicht}";
            }
        }
    }

    expect($fehlend)->toBe([]);
});
