# Spec 52 — Wissen ziehen statt zuteilen (Modell-getriebener Recall)

**Status:** Entwurf · **Anlass:** Dominique, 2026-09-07, nach der Diagnose von Lauf 65

> „Das Problem ist, dass es so viele unterschiedliche Anwendungsfälle benötigt, dass man gar
> nicht sagen kann: nimm nur zwei aus cross_cutting, dann hast du alle Infos. Sondern die LLM
> muss gezielt aus dem Quadranten wählen, je nach Kontext."

## Das Problem mit dem heutigen Modell

Der Wissens-Kontext wird **vorab zugeteilt** (Push): vor dem Generator-Call holt
`KnowledgeContextService::contextFor` je Kategorie eine feste Zahl Dossiers, per Ähnlichkeit zu
EINEM Query-String, und klebt sie in den Prompt. Daraus folgen vier Zwänge:

1. **Quoten müssen geraten werden** — heute 73 Routing-Zeilen (`feature × category × max_docs`).
   Jeder neue Anwendungsfall braucht eine neue Schätzung.
2. **Eine Query für alle Kategorien.** `discoveryQuery()` = Brief + Reglerwerte. Für die
   Tomatensuppe hieß das: ein Vektor, der gegen Suppen-Technik, Tomaten-Domäne, Produktions-
   Kennwerte und Weltküche gleichzeitig antreten musste.
3. **Das Budget ist Nullsummen-Spiel.** `RECIPE_MAX_KNOWLEDGE_CHARS = 12000` ist fix und wird
   heute schon gerissen (`contextFor` gibt `dropped_chars` zurück). Einen Kanal öffnen heißt
   einen anderen aushungern — `kueche` steht bei 2 von 108 Dossiers.
4. **Das Modell kann nicht nachfragen.** Fehlt ihm etwas, merkt niemand es.

Belegt am Fall: 108 Küchen-/Technik-Dossiers, zwei Plätze, einer davon an `ganache_kennwerte`
vergeben; `weltkueche_uruguayisch` als Zwangs-Auffüllung; die Tomate ohne Domain-Dossier.

## Zielbild: zwei Wissensklassen, zwei Mechanismen

Die Klassen-Trennung ist nicht neu erfunden, sie ist der Grund, warum der Kanon funktioniert
und die Recherche nicht:

| | **Aufgaben-Wissen** | **Inhaltliches Wissen** |
|---|---|---|
| Beispiele | Regelwerke, Mengen-Defaults, Produktions-Zeitkennwerte, Behälter-Füllgrad | domain, kueche, weltkueche, signatur_kuechen, cross_cutting-Inhalte |
| Frage | „Wie berechne ich Rüstzeit? Welcher Füllgrad gilt für 20 l?" | „Was ist eine Tomate? Wie bindet man eine Suppe?" |
| Auslöser | die **Aufgabe** | die **Zutaten / das Gericht** |
| Kann das Modell danach fragen? | **Nein** — es weiß nicht, dass der Begriff existiert | **Ja** — es weiß, was es kocht |
| Mechanismus | **Kanon (Push, verbindlich)** | **Pull (Modell sucht gezielt)** |

**Das ist die entscheidende Grenze.** Ein Modell, das ein Basisrezept baut, fragt nie
spontan nach dem Plausibilitätsband für Rüstzeiten — der Begriff kommt in seiner Welt nicht
vor. Aufgaben-Wissen MUSS gepusht bleiben. Pull ersetzt die **Recherche**, nicht den **Kanon**.

## Machbarkeit: die Mechanik existiert

- `AiGatewayService::callWithTools($auftrag, $toolNames, $maxRuns = 6, $optionen)` — agentischer
  Tool-Loop über ein provider-agnostisches JSON-Protokoll, mit `policy` (Tool-Gate),
  `arg_guard` (Argument-Entschärfung) und Runden-Deckel. **Läuft produktiv** im
  `VoiceCommandService`.
- `foodalchemist.knowledge.SEARCH` ist bereits ein hybrides Registry-Tool (lexikalisch +
  semantisch), `knowledge.GET` lädt den Volltext per Slug.
- `propose()` ist heute single-shot (`'tools' => false`).

Der Bau ist also **Verdrahtung, kein Neubau**: den Generator-Call vom Single-Shot auf den
Tool-Loop heben und ihm `knowledge.SEARCH` + `knowledge.GET` freigeben.

## Was dabei schiefgehen kann (und die Gegenmittel)

1. **Kosten und Latenz.** Heute 1 Call, agentisch 3–6. Der Generator ist schon einmal bei
   ~1,8 GB an OOM gestorben (ewiger Spinner, kein Provider-Fehler). → Such-Budget: max N
   Suchen und max Zeichen je Generierung, hart gedeckelt; vorher/nachher `tokens_in` +
   Wanduhr messen, nicht schätzen.
2. **Determinismus geht verloren.** Derselbe Brief liefert nicht mehr dieselbe Wissensmenge.
   Der Post-Condition-Assert („`fruchtgemuse-*` MUSS im Prompt sein") ist dann nicht mehr
   formulierbar. → Die Invariante wird schwächer, aber prüfbar: *„das Modell hat nach der
   Hauptzutat gesucht"* — die `tool_laeufe` stehen im Rückgabewert und im `ai_call_log`.
3. **Tool-Freigabe.** Strikt nach der Eigenschaft `read_only`, **nie** nach Namensmuster —
   `match_proposals.PUT` ist der dokumentierte Gegenbeweis dafür, dass Muster durchlassen, was
   sie nicht sollen.
4. **Das Modell sucht schlecht.** Eine vage Suche ist so blind wie eine vage Vorab-Query. →
   Der Prompt braucht eine Such-Anleitung („frage nach der Hauptzutat, nach der Bauart, nach
   der Technik — je eine Suche"), und `wissen-kanal-probe` misst, was zurückkommt.
5. **Reihenfolge.** Ein Pull-Recall ruft `searchScoredSlugs` je Frage. Ohne den
   Kategorie-Filter-Fix (C1) liefert **jede** dieser Fragen wieder nur, was in den globalen
   Top-12 stand. Die Suche selbst muss zuerst stimmen.

## Etappen

- **E0 — Voraussetzung (in dieser Runde erledigt):** Kategorie-Filter vor dem Schnitt,
  Score-Sortierung, Relevanz-Boden, Zutaten in der Query, Mess-Sonde `wissen-kanal-probe`.
  Die Suche taugt danach für beide Architekturen.
- **E1 — Kanon vervollständigen:** `produktion_kapazitat` in den Kanon von `recipe.generator`
  (Aufgaben-Wissen, das die Semantik nie findet). Reine Datenänderung.
- **E2 — Pull für EINEN Prompt-Key:** `recipe.generator` auf `callWithTools` mit
  `knowledge.SEARCH`/`knowledge.GET`, Such-Budget, `read_only`-Policy, Such-Anleitung im
  Prompt. Discovery-Quoten bleiben als Fallback stehen (Single-Shot-Pfade, Batch-Läufe).
- **E3 — Messen:** derselbe Brief-Satz durch beide Pfade; verglichen werden Wissens-Treffer,
  `tokens_in`, Wanduhr und Rezeptqualität. `feedback_mehr_kontext_ist_nicht_besser` ist hier
  die reale Gefahr — mehr Zugriff ist nicht automatisch besser.
- **E4 — Ausrollen** auf `vk.generator`, `concept.plan`, `foodbook.plan`, wenn E3 trägt.
  **Erst dann** werden die 73 Routing-Zeilen zur Fallback-Konfiguration statt zur
  Hauptsteuerung.

## Was das für die Quoten-Arbeit bedeutet

Die Deckel-Umverteilung (`kueche` 2 → 5) bleibt sinnvoll als **Zwischenschritt** — sie wirkt
sofort und ohne Architektur-Risiko. Sie ist aber nicht das Ziel: mit E2 entscheidet der Kontext,
nicht die Zeile in der Routing-Tabelle.
