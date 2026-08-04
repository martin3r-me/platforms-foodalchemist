<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_documents')) {
            return;
        }

        $this->upsertDocument(
            'workflow.rezept_anlegen_mcp',
            'Skill: Basisrezept anlegen (über MCP, geerdet)',
            $this->basisrezeptWorkflow(),
        );

        $this->upsertDocument(
            'workflow.gericht_anlegen_mcp',
            'Skill: Gericht / VK-Rezept anlegen (über MCP, geerdet)',
            $this->gerichtWorkflow(),
        );
    }

    public function down(): void
    {
        // Wissensstände werden nicht automatisch zurückgedreht.
    }

    private function upsertDocument(string $slug, string $title, string $content): void
    {
        $now = now();
        $row = DB::table('foodalchemist_knowledge_documents')
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->first(['id', 'version']);

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
            DB::table('foodalchemist_knowledge_documents')->where('id', $row->id)->update([
                ...$payload,
                'version' => ((int) $row->version) + 1,
            ]);

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

    private function basisrezeptWorkflow(): string
    {
        return <<<'MARKDOWN'
---
typ: Skill_Workflow
code: fa.basisrezept_anlegen
zweck: "Wie lege ich über die Food-Alchemist-MCP-Tools ein geerdetes Basisrezept mit verschachtelten Basisrezepten, LA-First-Grundprodukten und Complete-Coverage an?"
zielgruppe: agent
letzte_sync: 2026-08-04
required_tools:
  - foodalchemist.settings.GET
  - foodalchemist.canvas.GET
  - foodalchemist.knowledge.SEARCH
  - foodalchemist.recipes.SEARCH
  - foodalchemist.recipes.GENERATE
  - foodalchemist.gps.MATCH
  - foodalchemist.gps.SEARCH
  - foodalchemist.artikel.SEARCH
  - foodalchemist.gps.MINT_FROM_LA
  - foodalchemist.recipe_ingredients.PUT
  - foodalchemist.recipe_steps.GET
  - foodalchemist.recipe_steps.PUT
  - foodalchemist.process_anchors.GROUND
  - foodalchemist.recipes.PUT
  - foodalchemist.runs.GET
trigger_phrases:
  - Rezept anlegen
  - Basisrezept erstellen
  - Basisrezept über MCP bauen
tags: [skill, workflow, rezept, basisrezept, mcp, erdung, la-first, coverage, draft]
---

# Skill: Basisrezept anlegen (über MCP, geerdet)

## Eiserne Regeln

- Alles entsteht als **Entwurf (`status=draft`)**. `approved` setzt ausschließlich ein Mensch.
- Dieser Workflow ist nur für Basisrezepte. Für Verkaufsgerichte den getrennten Workflow `workflow.gericht_anlegen_mcp` verwenden.
- Erst Kontext laden, dann generieren: Wissen, Bestand und geeignete Templates gehören vor die Zutatenentscheidung.
- Bestand vor Neuanlage: bestehendes GP oder Basisrezept immer wiederverwenden, wenn es fachlich passt.
- Ein Basisrezept darf andere Basisrezepte enthalten, etwa eine Suppe den Geflügelfond.
- Ein fehlendes GP wird nie frei erfunden. Es entsteht ausschließlich aus einem realen Lieferantenartikel (LA).
- Generierung, Auflösung offener Zutaten, Anreicherung und Freigabe sind getrennte Schritte.
- Lange Ketten laufen im Hintergrund und persistent. Der Mensch bekommt am Ende eine Fertigmeldung und Review-Aufgabe.

## Schritt 1 — Rahmen und Wissen laden

1. `foodalchemist.settings.GET`: Team-, Warengruppen- und Lieferantenstrategie lesen.
2. `foodalchemist.canvas.GET` mit `type=food_dna`: Leitbild, Aromatik und No-Gos des Teams.
3. `foodalchemist.knowledge.SEARCH`: Technik, Mengen, Prozesswissen, Garverluste, Haltbarkeit, Substitutionen und Regelwerke zur Hauptzutat laden.
4. `foodalchemist.recipes.SEARCH`: vorhandene Basisrezepte und geeignete Templates prüfen. Treffer wiederverwenden statt duplizieren.

Templates sind Leitplanken, kein Endzustand: Struktur, typische Teilkomponenten, Arbeitsschritte und Mengenlogik dürfen daraus abgeleitet werden, aber die konkrete Zutatenliste wird mit Wissen und Bestand geerdet.

## Schritt 2 — Aufbaufragen für den Basisrezept-Generator klären

Vor `foodalchemist.recipes.GENERATE` die entscheidenden Parameter wie im KI-Rezeptgenerator erfassen:

- `description`: Was soll entstehen? Hauptzutat, Zweck, Stil, Menge/Yield und Grenzen konkret beschreiben.
- `convenience`: `from_scratch`, `teil_convenience`, `voll_convenience` oder leer.
- `level`: `haute_cuisine`, `gehoben`, `klassisch` oder leer.
- `bestand`: `hybrid`, `nur_bestand`, `komplett_neu`. Default ist `hybrid`.
- `frische`: `frisch`, `tk`, `konserve`.
- `bio`: nur bevorzugen, wenn ausdrücklich gewünscht.
- `diaet_hart`: harte Constraints wie vegan, vegetarisch, glutenfrei, laktosefrei, halal oder low_carb.
- `aroma`: freie Aromarichtung.
- `sektor`: betriebsgastronomie, catering, restaurant, care oder schule_kita.
- `use_favorites_list` und `favorites_convenience_only`: nur setzen, wenn kuratierte Favoriten-GPs genutzt werden sollen.

Für ein vollständig erzeugtes Basisrezept `voll_anreichern=true` und `complete_coverage=true` setzen. `complete_coverage` ist ohne `voll_anreichern=true` ungültig.

## Schritt 3 — Basisrezept-Draft erzeugen

`foodalchemist.recipes.GENERATE` mit `vk=false`, der präzisen `description` und den geklärten Richtungsparametern aufrufen.

Die Antwortfelder `recipe.id`, `statistik`, `offene` und optional `anreicherung.coverage` immer auswerten. Die Generierung legt weder fehlende Basisrezepte noch GPs automatisch an. Bereits vorhandene GPs und Basisrezepte werden gebunden; Lücken bleiben ausdrücklich offen.

## Schritt 4 — Verschachtelte Basisrezepte auflösen

Für jede offene Zeile mit `primaer=basisrezept_anlegen`:

1. `foodalchemist.recipes.SEARCH` mit der Komponentenbezeichnung ausführen.
2. Passenden Treffer als `referenced_recipe_id` wiederverwenden.
3. Gibt es keinen Treffer, diese Komponente als eigenes Basisrezept mit `foodalchemist.recipes.GENERATE` erzeugen.
4. Dessen offene Zutaten nach demselben Basisrezept-Workflow auflösen.
5. Danach das fertige Draft-Basisrezept über `foodalchemist.recipe_ingredients.PUT` in das Elternrezept einbinden.

Identische Komponenten innerhalb eines Auftrags nur einmal erzeugen und in allen Eltern wiederverwenden. Rekursion begrenzen: maximal drei Ebenen; bei tieferen oder zyklischen Abhängigkeiten stoppen und menschliche Entscheidung anfordern.

## Schritt 5 — Fehlende GPs strikt LA-first auflösen

Für jede offene Zeile mit `primaer=lieferantenartikel_waehlen`:

1. `foodalchemist.gps.MATCH`, danach bei Bedarf `foodalchemist.gps.SEARCH`: vorhandenes GP hat Vorrang.
2. Ohne GP `foodalchemist.artikel.SEARCH`: passende reale Lieferantenartikel ermitteln.
3. Kandidaten anhand der Team-/Warengruppenstrategie beurteilen.
4. Dem Menschen Lieferant, Artikelbezeichnung und vorhandenes GP-Mapping zeigen und die Auswahl bestätigen lassen.
5. Erst nach Bestätigung `foodalchemist.gps.MINT_FROM_LA` ausführen.
6. Die erhaltene `gp_id` mit `foodalchemist.recipe_ingredients.PUT` in die Rezeptzeile eintragen.

Gibt es keinen passenden LA, entsteht kein GP. Die Zeile bleibt offen und wird als Beschaffungs-/Stammdatenlücke gemeldet.

## Schritt 6 — Complete-Coverage für Basisrezepte

Erst wenn alle Pflichtzutaten auf `gp_id` oder `referenced_recipe_id` auflösen, ist die Anreicherung fachlich belastbar.

`voll_anreichern=true` füllt Text-/Klassifikations-Lücken. Bereits gefüllte Text-/Stammdatenfelder bleiben geschützt.

`complete_coverage=true` synchronisiert zusätzlich die abhängigen Detail-Bausteine neu:

- Fertigungstiefe.
- Arbeitszeit, Temperatur und Funktion.
- Equipment.
- Default-Posten, nur wenn aus aktiven Team-Posten belastbar ableitbar.
- Prozessanker aus der aktuellen Zubereitung.
- Step-by-step: bestehende Schritte werden bewusst ersetzt.
- Sensorik: wird mit dem aktuellen gegarten Rezept neu bewertet und überschreibt vorhandene Sensorik.

Produktionsauftrags-Zeilen bleiben Snapshots und werden nicht rückwirkend verändert.

## Schritt 7 — Review

Mengen, Yield, EK, Preisabdeckung, Step-by-step, Prozessanker, Sensorik und offene Zeilen kontrollieren. Mit `foodalchemist.recipes.PUT` höchstens auf `status=review` setzen. Nie selbst `approved` setzen.
MARKDOWN;
    }

    private function gerichtWorkflow(): string
    {
        return <<<'MARKDOWN'
---
typ: Skill_Workflow
code: fa.gericht_anlegen
zweck: "Wie lege ich über die Food-Alchemist-MCP-Tools ein geerdetes VK-Gericht mit Know-how, Pairing, Komponenten-Kette, Wirtschaftlichkeit und Complete-Coverage an?"
zielgruppe: agent
letzte_sync: 2026-08-04
required_tools:
  - foodalchemist.settings.GET
  - foodalchemist.canvas.GET
  - foodalchemist.knowledge.SEARCH
  - foodalchemist.pairings.GET
  - foodalchemist.recipes.SEARCH
  - foodalchemist.recipes.GENERATE
  - foodalchemist.gps.MATCH
  - foodalchemist.gps.SEARCH
  - foodalchemist.artikel.SEARCH
  - foodalchemist.gps.MINT_FROM_LA
  - foodalchemist.recipe_ingredients.PUT
  - foodalchemist.recipe_steps.GET
  - foodalchemist.recipe_steps.PUT
  - foodalchemist.process_anchors.GROUND
  - foodalchemist.recipes.PUT
  - foodalchemist.runs.GET
trigger_phrases:
  - Gericht anlegen
  - VK-Rezept erstellen
  - Verkaufsgericht bauen
  - Gericht über MCP bauen
tags: [skill, workflow, gericht, vk, mcp, pairing, know-how, komposition, wirtschaftlichkeit, coverage, draft]
---

# Skill: Gericht / VK-Rezept anlegen (über MCP, geerdet)

## Eiserne Regeln

- Alles entsteht als **Entwurf (`status=draft`)**. `approved` setzt ausschließlich ein Mensch.
- Dieser Workflow ist für VK-Gerichte. Einzelne Komponenten, die als Basisrezept fehlen, werden über den getrennten Basisrezept-Workflow erzeugt.
- Gericht ≠ Basisrezept: Das Gericht braucht zusätzlich Komposition, Pairing, Know-how, Serviceform, Plating, Wirtschaftlichkeit und Produktionsfähigkeit.
- Bestand vor Neuanlage: bestehende Basisrezepte und GPs immer wiederverwenden, wenn sie fachlich passen.
- Ein Gericht kann Basisrezepte, andere Basisrezept-Komponenten und GPs enthalten.
- Klar kaufbare Einzel-/Deko-/Convenience-Artikel wie Fleur de Sel, Microgreens oder fertig belegte Brötchen laufen GP-first; wenn kein GP passt, dann LA→GP.
- Fehlende GPs entstehen nur aus realen Lieferantenartikeln.
- Große Gericht-/Konzeptläufe laufen persistent in Queue-/Run-Schritten, nicht in einem einzigen synchronen Request.

## Schritt 1 — Know-how, Pairing und Rahmen laden

1. `foodalchemist.settings.GET`: Team-, Warengruppen-, Lieferanten-, Aufschlags- und Ziel-Wareneinsatz-Strategie lesen.
2. `foodalchemist.canvas.GET` mit `type=food_dna`: Küchenstil, No-Gos, Aromatik und Positionierung laden.
3. `foodalchemist.knowledge.SEARCH`: Know-how zur Hauptkomponente, Technik, Textur, Gargrad, Plating, Serviceform, Transport, Regeneration und Skalierung laden.
4. `foodalchemist.pairings.GET`: belegte Paarungen, Kontraste, Brücken und No-Gos laden. Pairing ist beim Gericht zentral, nicht Beiwerk.
5. `foodalchemist.recipes.SEARCH`: vorhandene Basisrezepte, passende Komponenten und Templates prüfen. Wiederverwenden statt duplizieren.

## Schritt 2 — Gericht-Aufbaufragen klären

Vor `foodalchemist.recipes.GENERATE` mit `vk=true` alle richtungsgebenden Parameter erfassen:

- `description`: Gerichtsidee, Hauptkomponente, Stil, Zielgruppe, Serviceform, Portion, Einschränkungen.
- `occasion`: fruehstueck, lunch, konferenz, empfang, dinner oder late_night.
- `serviceform`: tellerservice, buffet, flying, stehempfang oder boxed.
- `kompositions_stil`: klassisch, kreativ oder gewagt.
- `ziel_vk`: optionaler Netto-Zielpreis je Portion. Er steuert den Vorschlag und wird nach der Kalkulation geprüft, aber nie blind als Preis gesetzt.
- `convenience`, `level`, `bestand`, `frische`, `bio`, `diaet_hart`, `aroma`, `sektor`.
- `voll_anreichern=true` und `complete_coverage=true`, wenn das Gericht produktionsreif durchlaufen soll.

## Schritt 3 — Gericht-Draft erzeugen

`foodalchemist.recipes.GENERATE` mit `vk=true` aufrufen.

Das Tool erzeugt ein Verkaufsrezept/Gericht und aktiviert die VK-Ebene:

- Beschreibung, VK-Wording und Plating.
- Speisen-Hauptgruppe/Klasse, Aufschlagsklasse und Darreichung.
- Komponenten-Matching gegen Basisrezepte und GPs.
- Ziel-VK-Abgleich, Wareneinsatz-Ampel und Kohärenz.

Die Antwortfelder `recipe.id`, `statistik`, `offene`, `kohaerenz` und optional `anreicherung` immer prüfen.

## Schritt 4 — Komponenten-Kette auflösen

Offene Komponenten werden getrennt behandelt:

- `primaer=basisrezept_anlegen`: echtes Rezeptbauteil, etwa Fond, Sauce, Jus, Creme, Marinade, Pesto, Reduktion. Erst Bestand suchen, sonst als eigenes Basisrezept erzeugen. Kind-Basisrezepte erben bei Vollanreicherung die Complete-Coverage.
- `primaer=lieferantenartikel_waehlen`: kaufbarer Artikel oder GP-Lücke. Erst vorhandenes GP prüfen, dann passenden LA nach Teamstrategie wählen, daraus GP minten und mit `recipe_ingredients.PUT` einbinden.

Ein Gericht darf dadurch 1:n Basisrezepte erzeugen. In Konzepten mit fünf Gerichten können schnell 20–25 Basisrezepte entstehen; darum Dedupe pro Run und Queue statt synchroner Monster-Call.

## Schritt 5 — Pairing- und Know-how-Prüfung

Nach stabilen Komponenten:

1. Pairing-Netz prüfen: Kernanker, Brücken, Kontraste und kritische Kanten.
2. `kompositions_stil` respektieren: bei `gewagt` nur belegte Paarungen oder klar begründete Brücken nutzen.
3. Know-how gegen Zubereitung und Serviceform prüfen: Gargrad, Textur, Temperatur, Standzeit, Transport, Regeneration, Anrichtefähigkeit.
4. Wenn Pairing oder Know-how neue Basisrezept-Komponenten auslöst, diese als eigene Schritte behandeln.

## Schritt 6 — Complete-Coverage für Gerichte

`voll_anreichern=true` füllt VK-Text-/Klassifikations-Lücken. Bereits gefüllte Text-/Stammdatenfelder bleiben geschützt.

`complete_coverage=true` synchronisiert die abhängigen Detail-Bausteine neu:

- Fertigungstiefe.
- Arbeitszeit, Temperatur und Funktion.
- Equipment.
- Default-Posten, nur wenn aus aktiven Team-Posten belastbar ableitbar.
- Prozessanker aus der aktuellen Zubereitung.
- Step-by-step: bestehende Schritte werden bewusst ersetzt.
- Sensorik: wird neu bewertet und überschreibt vorhandene Sensorik.
- VK-spezifisch: Wirtschaftlichkeit, Standard-Darreichung, Ziel-VK-Abgleich, Wareneinsatz-Ampel und Kohärenz.

Produktionsauftrags-Zeilen bleiben Snapshots und werden nicht rückwirkend verändert.

## Schritt 7 — Review

Prüfen:

- Sind alle Komponenten geerdet?
- Ist EK vollständig genug?
- Sind Pairing, Sensorik, Plating und Step-by-step plausibel?
- Sind Posten, Arbeitszeit, Batch-/Vorlaufdaten und Darreichung produktionsfähig?
- Ist der Ziel-VK wirtschaftlich tragfähig?

Mit `foodalchemist.recipes.PUT` höchstens auf `status=review` setzen. Nie selbst `approved` setzen.

## Anti-Patterns

- Gericht wie ein flaches Basisrezept behandeln.
- Pairing/Know-how nur nachträglich kosmetisch betrachten.
- Fehlende Basisrezept-Komponenten als GP-Zeilen verflachen.
- Kaufbare Einzelartikel als Basisrezept erzeugen.
- GP ohne realen Lieferantenartikel anlegen.
- Große Konzept-Gericht-Ketten synchron in einem Request erzwingen.
- Bestehende Text-/Stammdaten überschreiben, wenn nur abhängige Coverage synchronisiert werden sollte.
MARKDOWN;
    }
};
