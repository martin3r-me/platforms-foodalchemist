<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 50 · Etappe 8b — die zwei GLOBALEN Workflow-Dossiers unter den Deckel bringen.
 *
 * Kein Wissens-Dossier darf über 4.000 Zeichen gehen: das Embedding-Fenster liegt bei 2.000,
 * alles dahinter ist semantisch kaum auffindbar, und die Wissens-Oberfläche meldet grössere
 * Dossiers als Fehler. Die team-eigenen Dossiers liessen sich per MCP kuratieren — diese zwei
 * nicht: `team_id IS NULL` ist globales Master-Wissen und über MCP weder editier- noch
 * deaktivierbar (TeamScope::owns(null) === false). Der Weg dorthin führt nur über eine
 * Migration, weil sie hier angelegt wurden (2026_08_04_000001).
 *
 *  · `workflow.rezept_anlegen_mcp`  (8.691 Z.) → vier Ein-Thema-Dossiers, ebenfalls global.
 *  · `workflow.gericht_anlegen_mcp` (6.862 Z.) → stillgelegt; ersetzt durch die vier
 *    team-eigenen Gericht-Dossiers (verkaufsgericht_anlegen_mcp + weg_a + weg_b + abschluss),
 *    die den Prozess als „EIN Prozess, zwei Ausführende" führen und dabei die Regeln tragen,
 *    die das globale Dossier nicht kannte (Shared-Entity-Schutz, §4-Zerlegung, GL-07).
 *
 * ⚠ Nur `gericht_anlegen_mcp` ist wirklich global. `rezept_anlegen_mcp` steht auf v4, wurde
 * also per MCP editiert und liegt team-eigen — die beiden stammen aus derselben Migration,
 * teilen aber nicht dasselbe Eigentum. Deshalb filtert das UPDATE unten NICHT auf `team_id`.
 *
 * Der Schnitt folgt der Bedeutung, nicht der Zeichenzahl: Regeln · Erzeugen · Komponenten
 * auflösen · Abschluss. Ein Ablauf-Dossier mechanisch entlang der Überschriften zu bündeln
 * würde „Schritt 5" ohne „Schritt 1–4" hinterlassen. `ablauf.GET` führt die Teile über
 * `VorgangsRegisterService::VORGAENGE[...]['doc_slugs']` wieder zusammen.
 *
 * Stilllegen statt löschen: der Inhalt bleibt les- und wiederherstellbar, und `down()` dreht
 * die Deaktivierung zurück.
 */
return new class extends Migration
{
    private const ALT = ['workflow.rezept_anlegen_mcp', 'workflow.gericht_anlegen_mcp'];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_documents')) {
            return;
        }

        foreach ($this->teile() as $slug => [$titel, $inhalt]) {
            $this->upsert($slug, $titel, $inhalt);
        }

        // BEWUSST OHNE `whereNull('team_id')`: der erste Wurf hatte den Filter als „Sicherheit"
        // drin — in der Annahme, beide Dossiers seien global, weil sie aus derselben Migration
        // stammen. `workflow.rezept_anlegen_mcp` wurde seither per MCP editiert (v4) und ist
        // damit team-eigen; der Filter übersprang es, und das 8.691-Zeichen-Dossier blieb neben
        // seinen vier Nachfolgern aktiv. Der Slug ist unique — ein team_id-Filter schützt hier
        // vor nichts und verhindert nur den beabsichtigten Effekt.
        DB::table('foodalchemist_knowledge_documents')
            ->whereIn('slug', self::ALT)
            ->whereNull('deleted_at')
            ->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_documents')) {
            return;
        }

        // Die Teil-Dossiers bleiben stehen (Wissensstände werden nicht zurückgedreht),
        // aber die abgelösten Originale werden wieder sichtbar.
        DB::table('foodalchemist_knowledge_documents')
            ->whereIn('slug', self::ALT)
            ->update(['active' => true, 'updated_at' => now()]);
    }

    private function upsert(string $slug, string $title, string $content): void
    {
        $now = now();
        $row = DB::table('foodalchemist_knowledge_documents')
            ->where('slug', $slug)->whereNull('deleted_at')->first(['id', 'version']);

        $payload = [
            'title' => $title,
            'category' => 'workflow',
            'content_md' => $content,
            'content_hash' => hash('sha256', $content),
            'char_count' => mb_strlen($content),
            'active' => true,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('foodalchemist_knowledge_documents', 'created_via')) {
            $payload['created_via'] = 'migration';
        }
        if (Schema::hasColumn('foodalchemist_knowledge_documents', 'imported_hash')) {
            $payload['imported_hash'] = null;
        }

        if ($row !== null) {
            DB::table('foodalchemist_knowledge_documents')->where('id', $row->id)
                ->update([...$payload, 'version' => ((int) $row->version) + 1]);

            return;
        }

        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => null,
            'slug' => $slug,
            ...$payload,
            'version' => 1,
            'source_path' => null,
            'created_at' => $now,
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function teile(): array
    {
        return [
            'workflow.basisrezept_regeln' => ['Skill: Basisrezept anlegen — Regeln', $this->regeln()],
            'workflow.basisrezept_erzeugen' => ['Skill: Basisrezept — Rahmen und Draft erzeugen', $this->erzeugen()],
            'workflow.basisrezept_komponenten' => ['Skill: Basisrezept — Komponenten und GPs auflösen', $this->komponenten()],
            'workflow.basisrezept_abschluss' => ['Skill: Basisrezept — Coverage und Review', $this->abschluss()],
        ];
    }

    private function regeln(): string
    {
        return <<<'MARKDOWN'
---
typ: Skill_Workflow
code: fa.basisrezept_anlegen
gilt_fuer_vorgang: basisrezept_anlegen
zweck: "Die Regeln, die beim Anlegen eines Basisrezepts immer gelten."
zielgruppe: agent
letzte_sync: 2026-09-07
teil_von: basisrezept_anlegen
weiter:
  - workflow.basisrezept_erzeugen
  - workflow.basisrezept_komponenten
  - workflow.basisrezept_abschluss
ersetzt: workflow.rezept_anlegen_mcp
required_tools:
  - foodalchemist.ablauf.GET
  - foodalchemist.recipes.SEARCH
  - foodalchemist.recipes.DUPLICATE
  - foodalchemist.reife.GET
trigger_phrases:
  - Rezept anlegen
  - Basisrezept erstellen
  - Basisrezept über MCP bauen
  - Komponente anlegen
tags: [skill, workflow, rezept, basisrezept, regeln]
---

# Basisrezept anlegen — Regeln

Nur für **Basisrezepte**. Verkaufsgerichte laufen über den Vorgang `gericht_anlegen`.

Die verbindliche Aspekt-Liste steht im Code, nicht hier: `ablauf.GET(vorgang="basisrezept_anlegen")`
nennt sie als `soll_aspekte`, `reife.GET kind=recipe` sagt an jedem Rezept, welche offen sind.

Weiter: Rahmen und Draft → `workflow.basisrezept_erzeugen` · Komponenten auflösen →
`workflow.basisrezept_komponenten` · Coverage und Review → `workflow.basisrezept_abschluss`.

## Regel 1 — Alles ist Entwurf
`status=draft`. `approved` setzt ausschliesslich ein Mensch; der Agent geht höchstens auf `review`.

## Regel 2 — Bestehendes NIE umwidmen (Shared-Entity-Schutz)
Ein bestehendes Basisrezept **niemals** durch Umbenennen, Umwidmen oder Zutaten-Tausch „passend
machen", um es in ein neues Gericht oder Konzept zu zwingen. Dieselbe `recipe_id` hängt als
`referenced_recipe_id` in vielen Gerichten, Konzepten, Foodbooks und Paketen — jede Änderung
schlägt dort überall durch, es gibt **kein** Copy-on-Write. Ein Fond, der in Rezept A stimmt,
wird in Rezept B zerstört.

1. `recipes.SEARCH` — passt ein bestehendes fachlich **unverändert**? → per
   `referenced_recipe_id` wiederverwenden, nichts umschreiben.
2. Passt es nur *fast*? → Original **nicht** ändern, sondern **fragen**: ein anderes
   Bestandsrezept (Datenbank), ein neues anlegen (kreativ) oder `recipes.DUPLICATE` und an der
   **Kopie** arbeiten (hybrid).
3. Ein Original nur direkt bearbeiten, wenn ein Mensch das **ausdrücklich für genau dieses
   Basisrezept** angewiesen hat — im Wissen, dass es alle Referenzen betrifft.

## Regel 3 — Erst Kontext, dann Zutaten
Wissen, Bestand und geeignete Templates gehören **vor** die Zutatenentscheidung. Templates sind
Leitplanken, kein Endzustand: Struktur, typische Teilkomponenten und Mengenlogik darf man
ableiten, die konkrete Zutatenliste wird mit Wissen und Bestand geerdet.

## Regel 4 — Bestand vor Neuanlage, GP nie erfinden
Ein bestehendes GP oder Basisrezept immer wiederverwenden, wenn es fachlich passt. Ein Basisrezept
darf andere Basisrezepte enthalten (eine Suppe den Geflügelfond). Ein fehlendes GP wird **nie**
frei erfunden — es entsteht ausschliesslich aus einem realen Lieferantenartikel.
**Nährwerte, Allergene und Zusatzstoffe kommen vom Lieferantenartikel, nie aus einer Schätzung.**

## Regel 5 — Getrennte Schritte
Generierung, Auflösung offener Zutaten, Anreicherung und Freigabe sind getrennt. Lange Ketten
laufen im Hintergrund und persistent; der Mensch bekommt am Ende eine Fertigmeldung und eine
Review-Aufgabe.
MARKDOWN;
    }

    private function erzeugen(): string
    {
        return <<<'MARKDOWN'
---
typ: Skill_Workflow
code: fa.basisrezept_erzeugen
gilt_fuer_vorgang: basisrezept_anlegen
zweck: "Rahmen und Wissen laden, die Aufbaufragen klären, den Basisrezept-Draft erzeugen."
zielgruppe: agent
letzte_sync: 2026-09-07
teil_von: basisrezept_anlegen
voraussetzung: workflow.basisrezept_regeln
required_tools:
  - foodalchemist.settings.GET
  - foodalchemist.canvas.GET
  - foodalchemist.knowledge.SEARCH
  - foodalchemist.recipes.SEARCH
  - foodalchemist.recipes.GENERATE
trigger_phrases:
  - Basisrezept generieren
  - Rezept-Draft erzeugen
tags: [skill, workflow, rezept, basisrezept, generator]
---

# Basisrezept — Rahmen und Draft

Voraussetzung: die Regeln aus `workflow.basisrezept_regeln` gelten unverändert.

## Schritt 1 — Rahmen und Wissen laden
1. `settings.GET` — Team-, Warengruppen- und Lieferantenstrategie.
2. `canvas.GET type=food_dna` — Leitbild, Aromatik und No-Gos des Teams.
3. `knowledge.SEARCH` — Technik, Mengen, Prozesswissen, Garverluste, Haltbarkeit, Substitutionen
   und Regelwerke zur Hauptzutat.
4. `recipes.SEARCH` — vorhandene Basisrezepte und geeignete Templates. Treffer wiederverwenden
   statt duplizieren — aber nie umwidmen (Regel 2).

## Schritt 2 — Aufbaufragen klären
Vor `recipes.GENERATE` die richtungsgebenden Parameter erfassen:

- `description` — was soll entstehen? Hauptzutat, Zweck, Stil, Menge/Yield und Grenzen konkret.
- `convenience` — `from_scratch`, `teil_convenience`, `voll_convenience` oder leer.
- `level` — `haute_cuisine`, `gehoben`, `klassisch` oder leer.
- `bestand` — `hybrid` (Default), `nur_bestand`, `komplett_neu`.
- `frische` — `frisch`, `tk`, `konserve`.
- `bio` — nur bevorzugen, wenn ausdrücklich gewünscht.
- `diaet_hart` — harte Constraints: vegan, vegetarisch, glutenfrei, laktosefrei, halal, low_carb.
- `aroma` — freie Aromarichtung. · `sektor` — betriebsgastronomie, catering, restaurant, care,
  schule_kita.
- `use_favorites_list` / `favorites_convenience_only` — nur bei kuratierten Favoriten-GPs.

Für ein vollständig erzeugtes Basisrezept `voll_anreichern=true` **und** `complete_coverage=true`
setzen. `complete_coverage` ist ohne `voll_anreichern=true` ungültig.

## Schritt 3 — Draft erzeugen
`recipes.GENERATE` mit `vk=false`, der präzisen `description` und den geklärten Parametern.

Die Antwortfelder `recipe.id`, `statistik`, `offene` und `anreicherung.coverage` immer auswerten.
**Die Generierung legt weder fehlende Basisrezepte noch GPs automatisch an.** Vorhandene GPs und
Basisrezepte werden gebunden; Lücken bleiben ausdrücklich offen — sie aufzulösen ist der nächste
Schritt (`workflow.basisrezept_komponenten`).
MARKDOWN;
    }

    private function komponenten(): string
    {
        return <<<'MARKDOWN'
---
typ: Skill_Workflow
code: fa.basisrezept_komponenten
gilt_fuer_vorgang: basisrezept_anlegen
zweck: "Offene Zeilen auflösen: verschachtelte Basisrezepte und fehlende GPs streng LA-First."
zielgruppe: agent
letzte_sync: 2026-09-07
teil_von: basisrezept_anlegen
voraussetzung: workflow.basisrezept_regeln
required_tools:
  - foodalchemist.recipes.SEARCH
  - foodalchemist.recipes.GENERATE
  - foodalchemist.gps.MATCH
  - foodalchemist.gps.SEARCH
  - foodalchemist.artikel.SEARCH
  - foodalchemist.gps.MINT_FROM_LA
  - foodalchemist.recipe_ingredients.PUT
trigger_phrases:
  - offene Zutaten auflösen
  - Komponente als Basisrezept anlegen
  - GP aus Lieferantenartikel minten
tags: [skill, workflow, rezept, basisrezept, komponenten, la-first]
---

# Basisrezept — Komponenten und GPs auflösen

Voraussetzung: die Regeln aus `workflow.basisrezept_regeln`. Die `offene`-Zeilen aus
`recipes.GENERATE` sind typisiert — behandle sie getrennt.

## `primaer=basisrezept_anlegen` — verschachtelte Basisrezepte
1. `recipes.SEARCH` mit der Komponentenbezeichnung.
2. Passenden Treffer als `referenced_recipe_id` wiederverwenden — **unverändert**; sonst
   duplizieren, neu anlegen oder fragen (Regel 2).
3. Kein Treffer? Die Komponente als eigenes Basisrezept mit `recipes.GENERATE` erzeugen.
4. Deren offene Zutaten nach demselben Ablauf auflösen.
5. Das fertige Draft über `recipe_ingredients.PUT` ins Elternrezept einbinden.

Identische Komponenten innerhalb eines Auftrags **nur einmal** erzeugen und in allen Eltern
wiederverwenden. Rekursion begrenzen: **maximal drei Ebenen**; bei tieferen oder zyklischen
Abhängigkeiten stoppen und eine menschliche Entscheidung anfordern.

## `primaer=lieferantenartikel_waehlen` — fehlende GPs, streng LA-First
1. `gps.MATCH`, bei Bedarf `gps.SEARCH` — ein vorhandenes GP hat Vorrang.
2. Ohne GP: `artikel.SEARCH` — passende **reale** Lieferantenartikel ermitteln.
3. Kandidaten anhand der Team- und Warengruppenstrategie beurteilen.
4. Dem Menschen Lieferant, Artikelbezeichnung und vorhandenes GP-Mapping zeigen und die Auswahl
   **bestätigen lassen**.
5. Erst nach Bestätigung `gps.MINT_FROM_LA`.
6. Die erhaltene `gp_id` mit `recipe_ingredients.PUT` in die Rezeptzeile eintragen.

**Gibt es keinen passenden LA, entsteht kein GP.** Die Zeile bleibt offen und wird als
Beschaffungs- oder Stammdatenlücke gemeldet — das ist das richtige Ergebnis, kein Fehlschlag.

## Anti-Patterns
- Fehlende GPs frei erfinden, statt LA-First aus einem realen Lieferantenartikel.
- Verschachtelung ohne Dedupe oder ohne Rekursionsgrenze.
- Ein bestehendes Basisrezept umwidmen, damit es „passt" (Regel 2).
MARKDOWN;
    }

    private function abschluss(): string
    {
        return <<<'MARKDOWN'
---
typ: Skill_Workflow
code: fa.basisrezept_abschluss
gilt_fuer_vorgang: basisrezept_anlegen
zweck: "Complete-Coverage anwenden und das Basisrezept in den Review geben."
zielgruppe: agent
letzte_sync: 2026-09-07
teil_von: basisrezept_anlegen
voraussetzung: workflow.basisrezept_regeln
required_tools:
  - foodalchemist.recipes.ENRICH
  - foodalchemist.recipe_steps.PUT
  - foodalchemist.process_anchors.GROUND
  - foodalchemist.reife.GET
  - foodalchemist.recipes.PUT
trigger_phrases:
  - Basisrezept anreichern
  - Basisrezept fertigstellen
  - Ist das Rezept fertig
tags: [skill, workflow, rezept, basisrezept, coverage, review]
---

# Basisrezept — Coverage und Review

Voraussetzung: die Regeln aus `workflow.basisrezept_regeln`.

## Schritt 1 — Erst erden, dann anreichern
**Erst wenn alle Pflichtzutaten auf `gp_id` oder `referenced_recipe_id` auflösen, ist die
Anreicherung fachlich belastbar.** Vorher angereichert heisst: Texte über einem Rezept, das noch
gar nicht steht.

## Schritt 2 — Was die Anreicherung tut
`voll_anreichern=true` füllt Text- und Klassifikations-Lücken. Bereits gefüllte Text- und
Stammdatenfelder bleiben **geschützt**.

`complete_coverage=true` synchronisiert zusätzlich die abhängigen Bausteine neu:

- Fertigungstiefe · Arbeitszeit, Temperatur, Funktion · Equipment
- Default-Posten — nur wenn aus aktiven Team-Posten belastbar ableitbar
- Prozessanker aus der aktuellen Zubereitung
- **Step-by-step: bestehende Schritte werden bewusst ersetzt**
- **Sensorik: wird neu bewertet und überschreibt vorhandene**

Produktionsauftrags-Zeilen bleiben Snapshots und werden **nicht** rückwirkend verändert.

## Schritt 3 — Review
Mengen, Yield, EK, Preisabdeckung, Step-by-step, Prozessanker, Sensorik und offene Zeilen
kontrollieren. `reife.GET kind=recipe` nennt die verbleibenden Lücken mit dem Werkzeug dazu;
steht dort `wie: null`, gibt es kein MCP-Werkzeug — dann ist es ein Fall für die Oberfläche,
nicht zum Raten.

Mit `recipes.PUT` höchstens auf `status=review`. **Nie selbst `approved`.**

## Anti-Patterns
- Anreichern, solange Zutaten ungeerdet sind.
- Bestehende Text- oder Stammdaten überschreiben, wenn nur abhängige Coverage synchronisiert
  werden soll.
- `approved` selbst setzen.
MARKDOWN;
    }
};
