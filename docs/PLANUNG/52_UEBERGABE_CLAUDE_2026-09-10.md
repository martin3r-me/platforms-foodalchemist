# Übergabe an Claude — Food Alchemist, Spec 52

Stand: 2026-09-10. Nutzer: Dominique. Auftrag zuletzt: vollständige Suite fertigstellen, dann diese Übergabe. Keine neue Implementierung nach der Übergabe begonnen.

## Sofort relevante Fakten

- **Vollständige Suite ist grün. Keine offenen Testfehler und kein Testprozess mehr aktiv.**
- **Geprüfter funktionaler Commit:** `02b74488`.
- **Aktueller Worktree:** `/Users/dbeutin/COOKING JARVIS/15_GITHUB/wt-wissen-budget`
- **Aktueller Branch:** `feat/wissen-lauf-snapshot`
- Der Verzeichnisname ist historisch: In diesem Tree wurden nacheinander Budget, Conformance und Lauf-Snapshot auf aufeinander aufbauenden Branches gebaut. **Nicht im alten `wt-wissen-architektur` weiterarbeiten.**
- Alles ist lokal committed. Codex hat diese Änderungen **nicht gepusht, gemergt oder deployt** und keine Live-Steuerdaten/Dossiers verändert.
- Nach dem geprüften Code-Commit folgt lediglich ein Dokumentationscommit mit aktualisierter Spec und dieser Übergabe. `git log -3 --oneline` zeigt ihn.

## Arbeitsverzeichnisse und Git-Kette

Hauptclone des Moduls:

`/Users/dbeutin/COOKING JARVIS/15_GITHUB/platform/modules/platforms-foodalchemist`

Dieser steht weiterhin auf `main`, `cb826dc2`. Er wurde zu Beginn gepullt und danach nicht für die Implementierung benutzt. Es erfolgte am Übergabetag kein erneuter Remote-Abgleich.

Eigene ältere Trees, deren Änderungen vollständig im aktuellen Branch enthalten sind:

| Worktree unter `15_GITHUB` | Branch | Letzter relevanter Commit |
| --- | --- | --- |
| `wt-wissen-riegel` | `feat/wissen-riegel` | `a212272e` |
| `wt-wissen-rechner` | `feat/wissen-rechner` | `dd7db2b6` |
| `wt-wissen-arten-achsen` | `feat/wissen-arten-achsen` | `f1ff7c72` |
| **`wt-wissen-budget`** | **`feat/wissen-lauf-snapshot`** | **`02b74488` + Übergabe-Dokumentation** |

Aufeinander aufbauende Code-Commits seit `cb826dc2`:

1. `a212272e` — Quellgrenzen und tatsächliche Pflichtmengen beim Retrieval-Budget.
2. `dd7db2b6` — gemeinsames Retrieval-Ranking und Kontextvorschau.
3. `61360545` — Arten/Achsen, Routing nach Wissensart, strukturierter Datenwerk-Resolver.
4. `f1ff7c72` — Volltexte nur für Gewinner-IDs/Slugs laden, keine unbeschränkte Kandidatenabfrage.
5. `e3986b9c` — gemeinsames Kanon-/Retrieval-Budget, ganze Dossiers.
6. `db9e9e88` — Kanon-Renderer vom Gateway entkoppelt, Budget-Regressionen angepasst.
7. `13c5d9ef` — Basisrezept-Critic über zentralen Kontextbau und Generator-Kanon.
8. `17bfea8a` — persistierte Kanon-Snapshots und Wissens-Lauf-ID über Queue-Grenzen.
9. `02b74488` — MCP-Nutzer-/Team-Kontext für Generierung, Snapshot und Audit vereinheitlicht.

Die Branches `feat/wissen-budget` und `feat/wissen-conformance` existieren ebenfalls als Zwischenstände. **Keine einzelnen Commits erneut cherry-picken: Der aktuelle Branch enthält die gesamte Kette.** Andere Worktrees und Parallel-Arbeit nicht anfassen.

## Was gebaut wurde

### Riegel, Suche und Budget (B/E, B1/D4)

- `KnowledgeContextBlock` hält Quellgrenzen bis zur Endauswahl. Pflichtquellen werden reserviert; optionale Dossiers passen vollständig oder entfallen. Spätere kleinere Quellen können nachrücken.
- `KnowledgeSearchService` vereinheitlicht Ranking/Tokenizer über die betroffenen Browser-, MCP- und Kontextpfade (RRF). Volltexte werden erst für die ausgewählten Treffer geladen. Den Fix `f1ff7c72` erhalten: frische Abfrage nur mit Gewinner-IDs/Slugs.
- `KnowledgeBudget` liest ausschließlich `foodalchemist.ai.knowledge_budget`, einen Gesamtwert je Prompt-Key. Der alte Generator-Alias wird normalisiert. `bound_knowledge_budget` ist entfernt.
- Kanon und Retrieval teilen sich ein Zeichenbudget einschließlich ihrer gerenderten Überschriften/Trenner. Zu große Pflichtmengen werfen `KnowledgeBudgetExceeded` vor dem Modellaufruf.
- Keine `truncate()`-Aufrufe mehr im Prompt-Kontextbau. Die öffentliche Kürzung für einen expliziten MCP-GET-Leseauszug bleibt bestehen.
- `max_chars_per_doc` bleibt als historisches, **inaktives** Kompatibilitätsfeld erhalten; UI/MCP-Hinweise sagen das. Einige alte Konstanten/Signaturen existieren weiterhin, sind aber keine wirksamen Dossier-Kappungen.
- `KnowledgeCanonText` ist der gemeinsame Renderer für tatsächlichen Prompt und Größenberechnung. Dadurch braucht der Kontextbau keinen Gateway für reine Textmessung.
- Profil, Versorgung und Vorschau zeigen `budget_total`. Die Startwerte sind überwiegend die Summe alter Kanalgrenzen, **keine neue Live-Korpus-Messung**. Budget gilt für Wissenszeichen, nicht für den gesamten Prompt oder Tokenverbrauch.

### Arten und Achsen

- Wissensarten: `regel`, `datenwerk`, `fachwissen`, `referenz`, `ablauf`.
- Geltungsachsen: `gang`, `komponentenrolle`, `portionskontext`, `niveau`, `saison`, `warengruppe`, `occasion`, `sektor`, `format`. Achsen werden mit UND, Werte innerhalb einer Achse mit ODER kombiniert; `level` wird als `niveau` normalisiert.
- Art-Routing ergänzt die Übergangs-Kompatibilität zu Kategorien. Regeln laufen über expliziten Kanon, Abläufe gehören nicht in Modellprompts. Klassifizierte Dokumente werden nicht wieder über alte Kategoriepfade eingeschleust.
- `DatenwerkResolver` liefert strukturierte Werte nach Geltung, markiert Konflikte und Lücken statt Werte zu erfinden. Wissens-Browser/MCP verwenden denselben Schreibdienst.
- Migration: `2026_09_09_120000_add_wissen_arten_achsen.php`.
- **Kein Korpus-Umbau durchgeführt.** Neue Klassifikation/Felder sind im UI/MCP bearbeitbar. Der Vault-Frontmatter-Import dieser neuen Felder ist noch nicht umgesetzt. `mengen_defaults` bleibt im Kanon, bis ein tatsächlich kuratierter und geprüfter strukturierter Ersatz besteht.

### C4: erster migrierter Critic-Pfad

- Nur **Basisrezepte** sind migriert, entsprechend dem ersten Durchlauf in der Spec.
- `ConformanceService` übergibt dafür keinen per `slug LIKE` geladenen Regeltext mehr. `AiGatewayService` baut Retrieval für `conformance.check` und lädt den Kanon aus `recipe.generator`.
- Externe Wissensoptionen werden für diesen Pfad abgewiesen. Fehlender oder unvollständiger Pflichtkanon führt zu einem Fehler, ohne Präfix-Fallback.
- Kanon steht im Systemblock, geroutetes Fachwissen im Userblock. Die tatsächlich übermittelten Quellen werden protokolliert.
- `conformance.check` hat 48.000 Wissenszeichen Budget; dieser Config-Wert betrifft auch die noch nicht migrierten Artefakttypen.
- **VK/GP/LA verwenden noch den alten Präfix-Lader.** Die aktuelle Pfaderkennung für Basisrezepte verwendet das vorhandene Kontextfeld `artefakt_typ = Basisrezept/Komponente`. Das ist noch nicht der vollständige typisierte Auftrag aus C1/C2.

### C1: Wissens-Lauf-ID und persistierter Kanonstand

Neue Klassen unter `src/Services/Knowledge/`:

- `RecipeKnowledgeRun`: unveränderlicher Lauf mit Team, UUID, Snapshot-Hash und kopierten Quellzeilen.
- `KnowledgeRunContext`: scoped Laufkontext; `within()` setzt ihn mit `try/finally` zurück, auch bei Exceptions und Verschachtelung.
- `KnowledgeRunService`: Snapshot anlegen/laden, Integritätsprüfung, Wiederaufnahme am Rezept.

Migration `2026_09_10_120000_add_knowledge_runs.php` ergänzt:

- `foodalchemist_knowledge_runs` mit UUID, Team, vollständigem Snapshot, Hash und Erstellzeit.
- `foodalchemist_recipes.knowledge_run_id`.
- `foodalchemist_ai_call_log.knowledge_run_id` und `.knowledge_snapshot_hash`.

Verhalten:

- Eine neue Basisrezept-Generierung friert den sichtbaren expliziten Kanon ein: alle Scopes/Rollen, Volltexte, Dossierversionen und bereits bestehende Integritätsbefunde.
- Der Lauf wird am erzeugten Rezept gespeichert. Generator, One-Shot-Anreicherung, Review sowie Konformität mit Heilrunde können ihn verwenden.
- `KnowledgeCanonService::documentsFor()` und `unaufloesbareZeilen()` lesen innerhalb des Laufs den gespeicherten Stand. Neue Dossier-/Bindungsänderungen verändern ihn nicht.
- Enrich-/Conformance-Jobs tragen die beim Dispatch ermittelte Lauf-ID. Eine spätere Änderung am Rezeptzeiger überschreibt die bereits festgelegte Job-ID nicht.
- Ein ausdrücklich neuer Re-Check in der Planungs-Leitstelle beginnt einen neuen Wissenslauf mit aktuellem Kanon.
- Bestandsrezepte ohne Laufzuordnung verwenden weiterhin Live-Wissen. VK-Generierung und Override-/Streaming-Einstieg erzeugen in diesem Slice keinen neuen Snapshot.
- Snapshot-Laden ist teamgebunden und prüft den Hash; kein stiller Live-Fallback bei fehlenden/beschädigten Snapshots. Die Hash-Kodierung ist stabil bei einer Umsortierung von JSON-Objektschlüsseln durch die Datenbank.
- `RecipeKiKontextService::alleCallsFuerRezept()` findet Folgeaufrufe auch über die Lauf-ID, wenn ihnen ein eigenes Rezept-Ziel fehlt. Die neuen Metadaten werden mitgeliefert. Die vollständige UI-Darstellung ist noch offen.

**Wichtige Grenze:** Eingefroren ist der **explizite Kanon**, nicht das gesamte Wissenssystem. Discovery, Routing, Datenwerte, Budgets und Prompt-Texte bleiben live. `knowledge_snapshot_hash` ist deshalb kein vollständiger Profil-/Korpus-Fingerprint. Der komplette `RecipeAuftrag` mit Benutzer-/Fachkontext und die umfassende Gateway-Vertragsumstellung fehlen noch.

### Letzter Fehler und seine Korrektur

Der erste Snapshot-Gesamtlauf hatte genau einen Fehler in `McpRecipesGenerateTest`: ToolContext gehörte Kind B, während der globale Auth-Nutzer noch Root war. Der neue Snapshot-Teamcheck deckte diese bereits vorhandene Diskrepanz zwischen Tool- und Gateway-Kontext auf.

`RecipesGenerateTool::execute()` setzt jetzt während des gesamten Aufrufs einen **temporären Klon des MCP-Nutzers mit dem MCP-Team** im Auth-Guard. `finally` stellt den vorherigen Nutzer wieder her. Kein Login und kein persistierter Teamwechsel. Der eigentliche Tool-Code liegt in `executeInContext()`.

Regressionen belegen: Rezept, Snapshot und Call-Log gehören zum MCP-Team; fremde GPs werden nicht gematcht; der vorherige Auth-Nutzer und der gespeicherte Teamwert bleiben auch bei Fehlern erhalten.

## Testergebnis und Wiederholung

**Letzter vollständiger Lauf auf `02b74488`:**

```json
{"tool":"pest","result":"passed","tests":4263,"passed":4257,"assertions":22154,"duration_ms":1749245,"skipped":6}
```

Dauer: 29 Minuten, 9 Sekunden. Ergebnislog: `/tmp/wissen-lauf-full-rerun.log`. Der Prozess ist beendet; es läuft keine Suite mehr.

Eigene Testsandbox:

`/Users/dbeutin/COOKING JARVIS/15_GITHUB/sandbox-wissen-budget`

Deren `vendor/martin3r/platform-foodalchemist` zeigt auf `../../../wt-wissen-budget`. Der Vendor-Bestand ist separat kopiert; keine Symlinks fremder Sandboxes umbiegen.

```bash
cd '/Users/dbeutin/COOKING JARVIS/15_GITHUB/sandbox-wissen-budget'
./fa_test.sh --testsuite=FoodAlchemist > /tmp/wissen-next-full.log 2>&1
```

Der Helfer erzwingt SQLite `:memory:`, Testumgebung und acht parallele Worker. Keine Tests gegen eine produktive DB. `.env` nicht ausgeben. Die Suite läuft erfahrungsgemäß 25–40 Minuten; alte Kommentare im Helfer nennen unrealistische kürzere Zeiten.

Der Runner schreibt die JSON-Zusammenfassung erst am Ende. Leeres Ergebnislog bedeutet **nicht**, dass kein Prozess läuft. Fortschritt konnte zusätzlich über Paratest-Workerdateien gelesen werden; der temporäre Helfer `/tmp/wissen-lauf-progress.py` bezieht sich nur auf den abgeschlossenen Lauf und muss für einen neuen Lauf angepasst werden.

**Filter-Falle:** Kein breites `--filter='Wissen|Knowledge'`! Pest nimmt den Worktree-Namen `Wtwissenbudget` in Testnamen auf; dadurch kann `Wissen` versehentlich die ganze Suite auswählen. Konkrete Klassennamen verwenden, z. B. `WissenLaufSnapshotTest|McpRecipesGenerateTest`.

Wichtige neue/erweiterte Tests:

- `tests/Feature/WissenLaufSnapshotTest.php` (7 Verhaltenstests).
- `tests/Feature/McpRecipesGenerateTest.php` (Team-, Audit- und Auth-Restore-Vertrag).
- `tests/Feature/ConformanceServiceTest.php` (zentraler Aufbau, Fingerprint, Budget, fehlende Pflichtquellen).
- `tests/Feature/WissenGemeinsamesBudgetTest.php` (ganze Quellen und kombiniertes Budget).

Vorherige vollständige grüne Meilensteine: Arten/Achsen 4.243 Tests; Budget 4.249 Tests; erster C4-Slice 4.255 Tests. Diese Zahlen sind historisch, die aktuelle Gesamtzahl ist 4.263.

## Was noch offen ist — empfohlene Fortsetzung

1. **Vorschau/Profil/Inspektor passend machen (C7, Rest C4).** Die generische Vorschau kennt die artefaktspezifische Kanonquelle `recipe.generator` für den Basisrezept-Critic noch nicht. Sie muss tatsächliche Quelle und gespeicherten Lauf korrekt darstellen. Run-ID und Kanon-Hash sind im Audit vorhanden; vollständige sichtbare Darstellung/Profilidentität fehlen.
2. **Vollständiger typisierter Auftrag und zentraler Eingabevertrag (Rest C1/C2).** `RecipeKnowledgeRun` ist noch kein `RecipeAuftrag`. Generatoren akzeptieren weiterhin vorbereitetes/externes Wissen; nur der migrierte Critic weist die Wissensoptionen ab. Keine allgemeine Umgehungssicherheit behaupten. Scope-, Queue- und UI/MCP-Grenzen sauber schließen.
3. **Selbstheilung fachlich erden (C5).** Sie läuft bei vorhandenem Rezeptlauf bereits im eingefrorenen Kanonkontext; konkrete Regel-ID/Paragraph plus Befund müssen aber noch gezielt in den Überarbeitungs-Kontext und die Suche eingehen. Nicht behaupten, der aktuelle Snapshot erledige C5.
4. **Sidebar-Mikrofon (C3).** Auf denselben strukturierten Basisrezept-Auftrag führen; freier Recherche-Toolloop bleibt separat. Das Leitstellen-Diktat ist laut Spec bereits der richtige Pfad und soll nicht unnötig verändert werden.
5. **End-to-End-Abnahme (C8, C4-Rest).** Basisrezept erstellen → anreichern → absichtlich eingebauten Verstoß finden und korrigieren, Quellen/Lauf nachweisen. Die automatisierten Snapshot-Tests ersetzen keine Live-Abnahme dieses gesamten fachlichen Ablaufs.
6. **Danach ausrollen.** Zuerst Verkaufsgerichte, dann Konzepte/Foodbooks/weitere Generatoren und GP-/LA-Prüfung. Gemeinsame Grundlagen gelten schon breiter; Laufvertrag/Snapshot/zentraler Critic sind nicht überall migriert. Erst danach alte Ladewege vollständig entfernen.
7. **Weitere Spec-Reste:** H7 hängende §-Verweise; noch vorhandene Alt-Bindungen/UNBIND prüfen und bereinigen; Riegel und Vokabular ausweiten; 56 Keys nach dem Korpus-Umbau triagieren. Keine alten Live-Zahlen ohne erneute Messung als aktuellen Bestand ausgeben.
8. **Korpus-Kuration und Live-Validierung:** Arten/Achsen tatsächlich befüllen, Datenwerke fachlich kuratieren, Frontmatter-Import erweitern, `mengen_defaults` erst nach geprüftem Ersatz ablösen.

C9-Wiederherstellungsprobe wurde in der bisherigen Planung als durch Kanon-Sicherung abgedeckt gestrichen; die Massenmarkierung H3 soll weitgehend mit dem Korpus-Umbau erledigt werden. Die vorhandene Spec enthält historische Diagnosen und ältere Statusabschnitte: **die Umsetzungseinträge ganz oben und diese Übergabe sind neuer**.

## Zusammenarbeit und Abgrenzung

- Dominique beginnt den Dossier-Umbau **erst, wenn das Wissensmodul steht**.
- Er möchte autonome Fehlerbehebung und durchgezogene Tests. Bei einem Testfehler nicht lediglich berichten und erneut um ein „go“ bitten. Er hat zuletzt ausdrücklich verlangt, diesen Test abzuschließen und dann zu übergeben.
- Status nur nach echter Prozess-/Logprüfung melden. Keine Behauptung, eine Suite laufe, wenn sie beendet oder nie gestartet wurde.
- Er kann hier kein MCP einrichten. Für spätere Live-Validierung einen verfügbaren Zugang/UI nutzen; nicht erneut MCP-Einrichtung vom Nutzer verlangen.
- Keine produktiven Änderungen, keine Dossier-Migration, kein Push/Merge/Deploy durch diese Codex-Arbeit erfolgt. Für Deployment die vorhandenen Repo-Vorgaben beachten, insbesondere vollständige Suite vor jedem Deploy und Ansage/off-peak. Der aktuelle Auftrag endet mit der Übergabe.
- Primäre Spec: `docs/PLANUNG/52_Wissens_Architektur_Entwirrung.md` im **aktuellen** Tree.
