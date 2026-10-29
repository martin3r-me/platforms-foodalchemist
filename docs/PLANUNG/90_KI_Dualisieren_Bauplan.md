# 90 – KI dualisieren (#1002): Bauplan für Punkte 2 bis 4

Stand 2026-10-29, Basis origin/main e595ae03. Vorbild: Grundgerüst #611 (`KiAuftragService`, `KiExternModus`, `chatMitBackoff` → `antwortOderAuftrag`). Pflichtliste: `90_KI_Dualisieren_Pflichtliste.md`.

## Punkt 2 · Wächter-Test `Spec90KiPflichtlisteTest` (tests/Feature, ohne DB)

Liest die Pflichtliste und scannt `src/` per Regex (kein Laufzeit-Container, schnell). Rot, wenn:

1. eine Methode `->propose(` enthält, die nicht in Tabelle A steht, oder die Anzahl je Methode abweicht;
2. eine Zeile in Tabelle A keine Entsprechung mehr im Code hat (veralteter Eintrag);
3. `callWithTools(` / `ImageGenerationService` / `->embed(` / `include_web_search` in einer Datei außerhalb der in Tabelle B genannten Stellen auftaucht;
4. ein Prompt-Key aus `config/foodalchemist.php` weder in Tabelle A noch in C steht;
5. ein Eintrag ohne Einstufung/Status ist, oder Status `Ausnahme` ohne Begründung.
Die 2 Falschtreffer (`GpProposalService::propose`) stehen als feste Ausnahmeliste im Test, mit Begründung.
Grenze: dynamische Keys (`$key`) sieht nur Regel 4 (Config-Abgleich). Gegenproben nach Commit: (a) neue `propose()`-Zeile in einer fremden Methode, (b) Zeile aus der Tabelle löschen, (c) neuer Key in der Config, (d) Anzahl +1 in einer gelisteten Methode. Jede Mutation per Python, Anker muss genau einmal treffen.

## Punkt 3 · Umleitung: ein Aufruf per MCP läuft extern

Zentraler Haken statt 28+ Einzeleingriffe: `FaRechteToolHuelle::execute()` (Spec 77a, wickelt jedes FA-Tool). Dort:
- `KiExternModus::within(fn () => $tool->execute(...))`;
- fängt `KiAuftragAusstehendException` und liefert ein einheitliches `ToolResult` `ki_auftrag_offen` mit Auftrags-IDs, Feature, Anweisung „ki_auftraege.GET → ANTWORT → denselben Aufruf wiederholen“. Kein Fehler, kein Teilergebnis.
- Livewire und Jobs laufen nicht durch die Hülle, bleiben also bei OpenAI. Core bleibt unverändert.

**Risiko Teilschreibzugriffe (Klasse b/c):** Schreibt ein Tool vor dem ersten `propose()` oder zwischen zwei `propose()`, bliebe bei „ausstehend“ ein halber Zustand. Vorschlag: Hülle öffnet für KI-Tools eine Transaktion, bei `KiAuftragAusstehendException` Rollback, der Auftrag wird NACH dem Rollback angelegt (Exception trägt dafür die Auftragsdaten, `KiAuftragService` legt erst im Haken an). Das ist eine Änderung an #611 und braucht Ihre Freigabe. Ohne sie bleiben Klasse-c-Tools bis zur Idempotenz-Arbeit (Phase 2) von der Umleitung ausgenommen (Pflichtliste: „offen“).
**Je Werkzeug ein Test:** parametrisierter Test über eine Liste „KI-Tools“ (aus Pflichtliste Spalte MCP-Einstieg abgeleitet): Aufruf mit FakeAiProvider, der bei Aufruf einen Fehler wirft → Ergebnis ist `ki_auftrag_offen`, Provider nie gerufen, 1 Auftragszeile, zweiter Aufruf legt keine Doublette an. Werkzeugliste alphabetisch, Spec84ToolListeTest/McpToolsTest/Schema im Lockstep.

## Punkt 4 · Phase 1 fertig

- `gp.sensorik`: Backfill schon extern (#611); ergänzt `gps.ENRICH` (Umleitung greift über die Hülle) + Vergleichstest.
- `gp.anker`/`condition`/`tags` über `BulkEnrichService::proposeGpFeld` (dyn. Key), Anker-Auslese `AnkerWissenAuslese::lese`.
- **Vergleichstest Plattform = MCP:** gleiche Fake-Antwort, einmal normal, einmal extern (Auftrag → ANTWORT → Wiederholung): gleiche GP-Felder, gleiche Lineage-Werte (`quelle=mcp`, Anbieter/Modell), 1 Call-Log je Antwort.
- **Ablehnungstests:** ungültige, abgelaufene, fremde Team-ID, bereits beantwortete ID, kein JSON, Schema-Verstoß (über `structural_retry` des Wiederholungslaufs); Gegenproben rot.
- **Fingerabdruck-Messung (Ihr Hinweis):** `messages_hash` hängt an den gesamten Messages. Zu prüfen und als Test zu belegen: Reihenfolge der Suchtreffer/Retrieval (Wissenskontext), Nachbar-GPs, Zeit/Zufall im Prompt. Messung: 2× denselben Auftrag bauen (auch nach einer Datenänderung irrelevanter Art), Hash vergleichen. Ist er instabil, wird die Reihenfolge sortiert (stabile Sortierung im Kontext-Bau) oder der Hash über einen normalisierten Kern gebildet. Zusätzlich Zähler: je Lauf „Aufträge angelegt / Antworten verbraucht / verwaist“, ausgegeben vom Backfill (Dublettenquote sichtbar).
- Deploy übernimmt der Kurator.

## Offene Punkte für die Reihenfolge (Punkt 5, nicht jetzt gebaut)

Bereichsprüfung bei Wiederholung, Aufräumregel für verbrauchte Aufträge, Bild/PDF, Websuche, Bildgenerierung: Vorschläge stehen je Zeile in der Pflichtliste (Tabelle B und Hinweisspalte).
