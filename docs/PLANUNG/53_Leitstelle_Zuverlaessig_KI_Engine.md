# Spec 53 — Planungs-Leitstelle: KI-Engine zuverlässig nutzbar

> **Tracking:** Office Dev-Package 23, Features-Board (`dev_board_id=53`, Slot 265).
> **Status:** Diagnose abgeschlossen, Plan freigegeben von Dominique 2026-09-17. Umsetzung in vier parallelen Paketen (A–D), Abnahme E nach Deploy.
> **Basis-Commit:** `727abe19` (origin/main, PR #92) = demo-Pin.
> **Branches:** A `fix/basisrezept-grounding-zustand` (Haupt-Clone) · B `fix/wissen-retrieval-tomatensuppe` (`wt-wissen-retrieval`) · C `feat/ki-feedback-phasen` (`wt-ki-feedback`) · D `fix/voice-sprachbefehl` (`wt-voice`) · Doku `docs/spec53-leitstelle` (`wt-spec53-doku`).
> **Merge-Reihenfolge:** A → B → C → D, danach **ein** Deploy auf demo + `queue:restart`, dann Paket E.
>
> **Umfang in einem Satz:** Basisrezept-Generierung fachlich richtig (Grounding, Zustand/Form), Wissensauswahl relevant und ehrlich ausgewiesen, jeder KI-Knopf mit sofortigem Feedback und sichtbaren Phasen, Sprachbefehl auf Chrome und Safari funktionsfähig inklusive bestätigungspflichtiger Planungs-Vorschläge.

## Anlass

Seit dem Wissens-Umbau (Spec 50–52, PRs #68–#92) läuft die Leitstelle nicht rund. Beobachtet von Dominique am 2026-09-17 (Screenshots, Tomatensuppen-Brief):

- Basisrezept „Suppe: Tomate-Basilikum": „Stückige Tomaten, aus der Dose" ohne Verknüpfung (EK-Lücke), Tomatenmark/Karotten ohne Preis, Sub-Rezept „Gemüsebrühe" als „übernommen" trotz „Bestand unfertig — keine Schritte, 1 Zutat ohne Verknüpfung".
- Verwendetes Wissen (19): Kanon 13 plausibel, Recherche 6 generisch (`convenience_und_recipe_engineering`, `niveau_basis_2_gehoben`, `bindemittel`, `allergen_patterns--ramen`) — kein Tomaten-, kein Suppen-Dossier.
- KI-Knöpfe ohne Rückmeldung: nach Klick passiert sichtbar nichts, das Ergebnis erscheint irgendwann.
- Sprachbefehl: „da kommt nichts an".

Dazu lagen ungesicherte Codex-Änderungen vom 16.09. im Haupt-Clone (Dosentomaten-Normalisierung, Brief-Stoppwörter, Zustands-Prüfung der KI-`gp_id`, Laufzeitmessung) plus ein redundanter Stash und eine 42-MB-Repo-Kopie im Repo-Root.

## Befunde (verifiziert am Code `727abe19`, 3 Explore- + 2 Design-Durchgänge)

### A — Basisrezept-Generierung

| Befund | Ort |
|---|---|
| 1 LLM-Call `recipe.generator` (+0–2 Structural-Re-Rolls, Temperatur-Treppe), Kritiker nur bei verdrahteten Sub-Rezepten, Conformance eigener Job | `RecipeGeneratorService:127-138`, `AiGatewayService:349-362` |
| Grounding kappt Tokens **positionsbasiert** (`MAX_TOKENS=8`) — spät genannte Zutaten fallen raus; statische Denylist; zweiter Token-Kanal `bestandsInventar()` inkonsistent | `GenerationContextService:307-318`, `RecipeGeneratorService:1006-1049` |
| Zustands-Prüfung nur bei expliziter Dose; „stückige Tomaten" ≠ konserviert; trocken/konserviert kollabieren zu `preserved` | `IngredientMatchService:500-512`, `RecipeGeneratorService:1126-1147` |
| Timings nur Log + Cache (TTL 15 Min), keine Senke; `generator_ms` ohne Kontextzeit im Kaskadenpfad | `RecipeGeneratorService:76-502`, `GenerateRecipeJob:111-117` |
| „übernommen" = `skipped` mit `deferred.reuse{reif,luecken}`; Lauf zählt unreifen Bestand als abgeschlossen | `RecipeDependencyWorkflowService:375-407`, `RecipeService::reifegrad():628-647` |
| demo 16.09.: `GenerateRecipeJob has timed out` (Job `timeout=300`, `tries=1`) | `failed_jobs` |

### B — Wissensrecherche

| Befund | Ort |
|---|---|
| `max_docs` überall respektiert, Ranking RRF — **aber `max_chars_per_doc` auf jedem Pfad tot** (`truncate()` 0 Aufrufer), `pflichtZeichen()` rechnet mit Fiktion | `KnowledgeContextService:277, 288, 1141, 1270, 1368, 1396, 1442, 1652, 725-745` |
| Deutsches Kompositum: `tomatensuppe` ein Token, Substring nur Dokumentwort ⊇ Query-Token → Tomaten-/Suppen-Dossiers scoren 0 | `KnowledgeSearchService:44-48` |
| Query-Verdünnung: Werte von 19 Leitplanken werden an den Brief gehängt → generische Slugs gewinnen per Ein-Token-Treffer | `KnowledgeContextService::discoveryQuery():961-983` |
| Budget-Schnitt ganze Dokumente, aber in Einfügereihenfolge statt nach Rang | `KnowledgeContextBlock::assemble():60-72` |
| „Kanon (13)" zählt pflicht+wenn_platz vor der Gateway-Auswahl; `selectKanon()` droppt später | `RecipeGenerationContextService:120-127`, `AiGatewayService:479-511` |
| Verworfene Quellen (`files_dropped`) nirgends gerendert, nirgends persistiert | `KnowledgeContextService:491`, `kontext-inspektor.blade.php` |
| demo-Routing `recipe.generator`: `domain` ohne `max_docs` (→ 4), `cross_cutting`/`regelwerk` auf `discovery` (Sonderkategorien, Leerlauf-Verdacht); Budget 64.000; Semantik an | `foodalchemist_knowledge_routings` (Team 6) |

### C — Feedback & Phasen

| Befund | Ort |
|---|---|
| Phasen-Texte existieren im Cache `fa:recipe-gen:{runId}`, liest nur das Rezept-Modal; Cockpit zeigt „läuft" | `GenerateRecipeJob::fortschritt():223-235`, `HatGeneratorLauf:112-130`, `ergebnis.blade.php:4` |
| 11 KI-Aktionen ohne jeden Ladezustand (goKaskade, neuGenerieren, neuAnreichern, bilderNeu, gibStufeFrei, alleFrei/alleVerwerfen, gibFrei/verwirf, erzeugeGeplant, vorschlagUeberarbeiten, ergaenzeSubRezept), `konformitaetPruefen` ohne `wire:target` | `index.blade.php`, `step-zeile.blade.php`, `ergebnis.blade.php` |
| Kein wiederverwendbarer Baustein; `wire:loading`-Paar ~12× kopiert | — |
| Poll-Gate = gespeichertes Flag aus 17 Aufrufstellen; Lücken `goKaskade`, `konformitaetPruefen`; kein Broadcasting | `Index.php:2597, 3682, 4038` |
| Globaler Status nur Worker-Ampel + Session-Zähler im Board | `board-worker-kopf.blade.php` |

### D — Sprachbefehl

| Befund | Ort |
|---|---|
| `audio/webm;codecs=opus` hart kodiert, kein `isTypeSupported` → Safari `NotSupportedError` vor Aufnahme; `getUserMedia` ohne try/catch | `voice-modal.blade.php:8-10` |
| Upload-Callbacks leer; Blob ohne Dateiname; Safari `video/mp4` ∉ `ENDUNGEN` → `.webm` → OpenAI lehnt ab | `voice-modal.blade.php:15`, `OpenAiSttService:33-37` |
| Kein `wire:loading`; transkribieren→verstehen→ausführen ein Roundtrip (5–20 s ohne Anzeige); Tipp-Pfad ohne Ladezustand | `VoiceModal:42-52`, blade `:37-40` |
| Provider unsichtbar; Fake liefert stumm „Suche BBQ Sauce" | `FoodAlchemistServiceProvider:274-290`, `config:412` |
| `ui.OPEN` 13 Typen, Modal verarbeitet 2; `ui.NAVIGATE` nicht im Tool-Set; Planungs-Tools `read_only=false` → unerreichbar; kein Proposal „Planung starten" | `UiOpenTool:19-33`, `VoiceModal:73-77`, `VoiceCommandService:47-109` |
| demo: STT-Binding = OpenAI, Key gesetzt. Laravel-Log ohne Voice-Einträge, **aber Call-Log zeigt 15 `voice.command`-Läufe in 21 Tagen** — der Allrounder erreicht den Server (Korrektur des Erstbefunds, Details unter „Offen / Entscheidungen") | Server-Prüfung 2026-09-17 |

## Zielbild und Pakete

Vollständiger Bauplan mit Datei-/Funktionsbezug: `~/.claude/plans/f-r-den-food-alchemisten-w-rde-dazzling-goblet.md` (Dominique) bzw. Briefs in `00_INBOX/_Spec53_Leitstelle/`. Kurzfassung:

- **A (Paul):** Codex-Diff fertigstellen; Grounding score-basiert (Sondierung aller Tokens, Kappung nach Trefferqualität); `TokenEngine::produktForm()` trennt Identität/Zustand/Form, `acceptsProductForm()` erzwingt Zustand ohne Schwellen-Absenkung; KI-`gp_id`/`sub_rezept_id` gegen Zustand; Timings in `context_snapshot['timings']` + `laufStatus()`; unfertiger Bestand im Header gezählt; Job-Timeout → Step `failed` mit Text.
- **B (Lisa):** Messung vorher/nachher per `knowledge.PREVIEW`; `max_chars_per_doc` reaktivieren (Absatzgrenze); Query ohne Leitplanken-Werte; Decompounding + beidseitiger Substring; Budget nach Rang; `kanon_files` erst nach Gateway-Auswahl; Verworfen persistieren und rendern; Routing-Leerläufe belegen; Golden-Test Tomatensuppe.
- **C (Peter):** Spalte `cascade_run_steps.phase/phase_at`, `setzePhase()` aus Generate-/Enrich-/Conformance-Job; Poll-Gate abgeleitet; Server-Guards gegen Doppel-Enqueue; Komponente `<x-foodalchemist::ki-action>` (Browser-Zustand sofort + `wire:loading` zweite Stufe); Phase in Zeile, Worker-Header, Board; Status-Leiste `kiStatusFuerTeam()` im Planung-Kopf.
- **D (Oskar):** gemeinsamer Recorder (`isTypeSupported`, Fehlertexte, Timer/Pegel, Upload-Callbacks); Client-Mime + erweiterte Endungen; Roundtrip gesplittet mit Phasenzeile; `SttServiceContract::name()`, `UnkonfiguriertSttService`, `allow_fake`-Gate; `VoiceFehlerText`; Navigation für 13 Typen + `ui.NAVIGATE`; read-only Proposal-Tools `planung_vorschlag.POST`, `anreicherung_vorschlag.POST`, Status `planung_kaskade.LETZTE`; Bestätigung erst per Klick (GL-07).
- **E (alle, nach Deploy):** Live-Läufe Basisrezept / Gericht / Speisekarte / Foodbook mit Phasen, Timings, Call-Zahl, Wissensauswahl; Voice-Abnahme Chrome × Safari.

## Umsetzung

_(wird je Paket mit Commit, Testzahl und Messwerten vorher/nachher ergänzt)_

### Paket B — Golden-Referenz „vorher" (Lisa, 2026-09-17, MCP `knowledge.PREVIEW`, `recipe.generator`, Team 6/demo, Tomatensuppen-Brief, VOR jeder Code-Änderung)

**Ohne Leitplanken (nur Brief):** Retrieval Top-5 = `rezept_aufbau_prozessstufen--aufbau-skelette-je-bereich` (0,0276; lex 22 / sem 5) · `fonds_jus_consomme_kennwerte--5-jus-demi-glace-protein-fond-100` (0,0263) · `fonds_jus_consomme_kennwerte--methode-zeit` (0,0257) · `wurzel-zwiebelgemuse-verwendung-garung-mengen` (0,0242; lex 51 / sem 6) · `fruchtgemuse-verwendung-zubereitung-mengen` (0,0239; lex 46 / sem 9). Kanon 13 (8× `regelwerk-basisrezepte-*`, Workflow-Dossier, 2× Geschmacksbalance, 2× Mengen-Defaults). Verworfen: `pflanzlich_konfieren_kennwerte`, `fruchtgemuse-substitutionen`, `zutaten_default_logik`, 3 Referenz-Rezepte. Budget 64.000 · Pflicht 46.605 · verworfen 14.050 · gesendet 62.349. **Kein Tomaten-/Suppen-Dossier in der Top-5** — Kompositum-Befund bestätigt; die Zutaten-Domäne kommt nur über die semantische Spur (sem 5/6/9 bei lex 22/51/46).

**Mit typischen Leitplanken** (level=gehoben, occasion=business_catering, sektor=catering, saison=herbst, rezept_typ=basisrezept, kompositions_stil=klassisch): Top-5 = `niveau.niveau_basis_3_klassisch` (0,0310) · `niveau.niveau_3_klassisch--1-kernaussage` · `niveau.niveau_basis_2_gehoben` · `event_playbook_business_lunch` · `niveau.niveau_2_gehoben--1-kernaussage`; `rezept_aufbau_prozessstufen` fällt auf Rang 6, Fonds/Wurzelgemüse/Fruchtgemüse **komplett raus**. Kanon unverändert. Verworfen: `gemuese_kohl`, Fonds-Jus, 2 Referenz-Rezepte, `referenzgericht.niveau2_plant_forward_sellerie`. Gesendet 63.440. — Query-Verdünnung bestätigt: die Leitplanken-Werte gewinnen die gesamte Recherche.

**Nebenbefund Budget:** Der Kanon belegt 46.605 von 64.000 Zeichen (73 %); für die Recherche bleiben ~17.000. Ob 13 Pflicht-Dossiers für ein Basisrezept nötig sind, ist eine Kurations-Frage für Paket E (Dominique), kein Code-Thema.

**Routing-Leerlauf (vorläufig):** `cross_cutting discovery 6/8000` und `regelwerk discovery 2` liefern für diesen Brief in beiden Läufen keinen Slug; code-seitig nicht tot (generischer Discovery-Pfad greift), praktisch leer — Kanon-Exklusion zieht die Regelwerk-Dossiers vorab, Cross-Cutting rankt für „Tomatensuppe" nicht. Neu vermessen nach Query-Hygiene, keine Steuerdaten geändert.

## Offen / Entscheidungen

- 2026-09-17 Dominique: ein Deploy am Ende (nach Merge A–D); verwaister Ordner gelöscht; Codex-Stash nach Gegencheck gedroppt (Inhalt war in main).
- 2026-09-17 Paket B, Aufgabe 1 (Blocker Lisa, Entscheidung Orchestrierung): `max_chars_per_doc` wird **nicht reaktiviert**, sondern ehrlich stillgelegt. Grund: Spec 50 Welle 0 (W0-3/W0-4) hat Pro-Dossier-Kappung bewusst durch Ganzdokument-Budgetierung ersetzt; `WissenTokenWelle0Test:148-161` und `RecipeKnowledgeBudgetTest:15-44` schreiben das als Vertrag fest (Pflichtwissen nie als Fragment). Umsetzung: tote `$maxChars`-Pfade + `truncate()` entfernen, `pflichtZeichen()`/Versorgung/Riegel rechnen mit realen Dossierlängen, Spalte bleibt, UI zeigt „ohne Wirkung seit Spec 50", `knowledge_routings.PUT` warnt, Seed setzt den Wert nicht mehr.
- 2026-09-17 Paket D, Kernbefund aus demo-Call-Log (`foodalchemist_ai_call_log`, `feature=voice.command`, 16.09., User 7): Der Allrounder erreicht den Server. Zwei Muster: (a) 57–67 s, 6 Runden, 6 Tools, `final=false`, ~91.500 Input-Tokens — Loop läuft bis `maxRuns` ohne Ergebnis, Modal zeigt nur „6 Runde(n) · 6 Tool-Aufruf(e)"; (b) 8–10 s, 1 Runde, 0 Tools, kurzer Text. Beides ohne Zwischenanzeige ⇒ für den Nutzer „nichts kommt an". Server-Timeouts ausgeschlossen (nginx `fastcgi_read_timeout 1h`, PHP `max_execution_time 0`). Diktat in der Planung funktioniert (8 `planung.leitplanken`-Aufrufe in 21 Tagen). Anforderungen an Oskar: Runden 6→4 + Zeitbudget ~30 s, `final=false` ehrlich rendern, Tool-Ergebnisse im Loop kappen, Frühabbruch bei Wiederholung, Phasen-Split. Asynchrone Job-Variante (wie Paket C) als Folgeentscheidung.

### Paket C — Stand 2026-09-17 (Peter, Branch `feat/ki-feedback-phasen`, HEAD `30e68bb4`, 6 Commits, Review durch Orchestrierung)

| Commit | Inhalt |
|---|---|
| `3c65c185` | Migration `phase`/`phase_at` an `cascade_run_steps`, `PlanningCascadeService::setzePhase()`, Schreiber in Generate-/Enrich-/ConformanceJob, Server-Guards gegen Doppel-Enqueue, `laufStatus()` exportiert `phase` + `timings` |
| `f0bc8e91` | Poll-Gate `$pollAktiv` in `render()` abgeleitet (statt gespeichertem Flag), `pruefeLauf` bleibt bei offener Phase aktiv, Board sieht Phase; `deferred.enrich.dauer_ms`, `deferred.conformance.ms` |
| `0b05aba7` | Review-Fixes: `EnrichGeneratedRecipeJob` reicht Step-ID an Conformance durch (Standardpfad war ohne Phase); Phasentexte als Konstanten `PHASE_*`; `PlanungAktionLaeuftBereitsException` → neutrale Meldung statt Fehler; `konformitaetPruefen` setzt Phase synchron vor Dispatch; `kiStatusFuerTeam()` |
| `15e655ee` | Komponente `<x-foodalchemist::ki-action>` (Browser-Zustand sofort, `wire:loading` zweite Stufe, 45-s-Fallback gegen hängenden Spinner, Tastatur enter/space, Zeilen-Scope `busy`), 20 Aufrufstellen umgestellt, Phase-Anzeige in Step-Zeile und Worker-Header |
| `443b3630` | Globale KI-Status-Leiste (Board-Kopf + Editor-Kopf), Phase unter dem Board-Fortschrittsbalken |
| `30e68bb4` | Tests: `PlanningCascadeTest` (+147), neue `GenerateRecipeJobPhaseTest` (90), `PlanungLeitstelleTest` (+98: Poll-Regeln, Doppel-Enqueue `assertNotPushed`, `data-ki-action`/`wire:target`, Status-Leiste) |

| `e91a314b` | Test-Fixes nach frischem 4-Datei-Lauf (298/298, 1.040 Assertions): Cockpit-Bild-Status-Assertion auf `data-ki-action`, fehlender Job-Import (hätte `assertNotPushed` stumm grün gemacht — geprüft: alle Job-Klassen in Queue-Assertions importiert), `assertSeeText` statt `assertSee` über Tag-Grenze, Phase mid-flight im Callback abgegriffen + Beleg, dass `done` die Phase nullt |

**Rebased auf A (`b2b2f86f`), 8 Commits, HEAD `3a7834cc`. Volle Suite (sandbox-ki-feedback, 4 Prozesse, 46,8 Min): 4.472 Tests, 4.466 passed, 6 skipped, 0 failed, 22.842 Assertions.** PR #96 gegen main. Umgestellte Knöpfe: neuAnreichern ×3, bilderNeu, neuGenerieren ×2, gibFrei/verwirf, erzeugeGeplant ×2, verwirfGeplant, vorschlagUeberarbeiten, konformitaetPruefen, gibStufeFrei, alleFrei/alleVerwerfen, ergaenzeSubRezept, laufWiederAufnehmen, goKaskade ×3. Bewusst ausgelassen: die ~12 kopierten `wire:loading`-Span-Paare auf `variant=primary` (optisch, kein Funktionsnachteil).

### Paket D — Stand 2026-09-17 (Oskar, Branch `fix/voice-sprachbefehl`, HEAD `0c454901`, 3 Commits, Review durch Orchestrierung)

| Commit | Inhalt |
|---|---|
| `8de511fd` | Gemeinsamer Recorder-Baustein `resources/js/voice-recorder/index.js` (Mime-Kette per `isTypeSupported`, Fehlertexte je `getUserMedia`-Fehler, Timer/Pegel, Auto-Stopp, Mindestdauer, Upload-Callbacks), zweites esbuild-Bundle, Script-Tags. **Review: war noch nicht verkabelt** — Blades liefen weiter mit hart kodiertem WebM. |
| `c9f24e25` | `VoiceMime::aufgeloest()` (Client-Mime bevorzugt), `ENDUNGEN` += `video/mp4`, `video/webm`, `audio/aac`; `SttServiceContract::name()`, `UnkonfiguriertSttService`, `stt.allow_fake` (Fake nur testing/local); `VoiceFehlerText`. HTTP-Status als Exception-Code. |
| `0c454901` | Recorder in `voice-modal.blade.php` + `diktat.blade.php` verkabelt; Roundtrip-Split (`updatedAudio` → `$phase='verstehen'` → `$this->js('$wire.verstehen()')`); Provider-Pill; `ZIELE` für 13 `ui.OPEN`-Typen + `ui.NAVIGATE` (Route::has-Regressionstest); Loop-Härtung aus dem Call-Log-Befund: `MAX_RUNDEN` 6→4, `zeitbudget_ms` 28 s in `callWithTools`, Tool-Ergebnis-Kappung 2.000 Zeichen (markiert), Frühabbruch bei Tool+Argument-Wiederholung, `final=false` ehrlich mit versuchten Werkzeugen; GL-07: `planung_vorschlag.POST`, `anreicherung_vorschlag.POST`, `planung_kaskade.LETZTE` (alle `read_only`), Bestätigen-Knöpfe `planungStarten()` (Session + Editor-Tab) und `anreicherungStarten()` (EnrichRecipeJob wie Editor-Knopf); Rezept-Kontext via Event/`?rezept=`. 55/55 grün (3 Voice-Testdateien). |

| `9baee417` | **Review-Fix:** drei Vorschlags-Tools in `TOOLS` + `PROPOSAL_TOOLS` (Warm-Start), Prompt „direkt aufrufen", `ablauf.GET`-Pflicht für Vorschläge entschärft; Katalog 8.340 → 10.047 Zeichen (Testgrenze 10.500). |

Verdrahtungs-Protokoll (Fake-KI, Runden/Tools/final, `MAX_RUNDEN=4`): Suche BBQ-Sauce 2/1/✓ · Öffne Rezept Gemüsebrühe 3/2/✓ · Öffne die Planung 2/1/✓ · Erstelle Gericht Rinderfilet/Püree/Jus 2/1/✓ (vorher 4 Runden = Limit) · Erstelle Basisrezept Tomatensuppe 2/1/✓ · Reichere dieses Rezept an 2/1/✓ · Wie weit ist die Generierung 2/1/✓ · konfuser Befehl 4/4/✗ mit ehrlichem Text (4 versuchte Tools). **Belegt nur die Verdrahtung** — Live-Verhalten des Modells wird nach dem Deploy im Call-Log gemessen (Dominique, Chrome × Safari).

Offen: volle Suite (Letzter in der Reihe), Rebase nach Merge C.

### Paket A — Stand 2026-09-17 (Paul, Branch `fix/basisrezept-grounding-zustand` im Haupt-Clone, HEAD `b2b2f86f`, 9 Commits, Review durch Orchestrierung, volle Suite freigegeben)

| Commit | Inhalt |
|---|---|
| `5eb5f240` | Codex-Diff (16.09.) fertiggestellt; Timing-Konsistenz: Summe der Phasen == `generator_ms` (Toleranz < 5 ms), Kohäsion zu `checks_ms` |
| `b697a5da` | Grounding score-basiert: `TokenEngine::leitTokens()` als geteilte Denylist (auch `bestandsInventar()`), Sondierung bis 16 Tokens, Kappung nach Trefferqualität statt Position. Messung 12-Zutaten-Brief: vorher 5 von 13 Tokens verloren, jetzt alle sondiert |
| `8218701e` | `TokenEngine::produktForm()` trennt Identität / §9-Zustand / Form; `acceptsProductForm()` gilt für alle vier Zustände; `gpZustandErlaubt()` trocken ≠ konserviert. Golden: Dosentomaten→konserviert, TK-Erbsen→TK, getrocknete Tomaten→trocken, Tomaten frisch→frisch; „TK, getrocknet"→unentschieden |
| `ea53a457` | `validiereProposedSub()` verwirft KI-Sub-Rezept bei expliziter Einkaufsform |
| `7ba9be6c` | `context_snapshot['timings']` je Step (Schreibpfad; Anzeige Paket C) |
| `5675dedb` | Run-Kopf `uebernommen`/`uebernommen_unreif` aus `deferred.reuse`, `laufStatusHinweis()` nennt es |
| `f619c332` | `GenerateRecipeJob::failed()`: Timeout → „Zeitüberschreitung nach 300 s" |
| `9590b049` | Tests Aufgaben 1–7 |
| `b2b2f86f` | **Review-Fix:** „frisch" + Zubereitungs-Partizip (gemahlen/gerieben/gepresst/…) ist kein §9-Zustand („Pfeffer, schwarz, frisch gemahlen" matcht trockenen Pfeffer); Sub-Verwurf nur bei TK/trocken/konserviert („Pesto, frisch" bleibt Sub-Rezept) |

**Volle Suite (sandbox-ek-stk, 4 Prozesse, 58,1 Min): 4.452 Tests, 4.446 passed, 6 skipped, 0 failed — Basis 4.391, +61.** PR #94 `fix(recipe-generator): Basisrezept-Grounding, §9-Zustand/Form, Timing-Konsistenz` (MERGEABLE/CLEAN), Merge wartet auf Freigabe Dominique (Auto-Modus blockiert `gh pr merge`).

Messung Sondierung (SQLite-Harness, 12-Zutaten-Brief): 8 Tokens 7,6 ms → 13 Tokens 41,5 ms (überlinear) — auf demo mit `context_ms` gegenprüfen (Paket E).

**Folgeschritt für Paket E (aus Rückfrage Dominique):** Generator-Schema kennt je Zutat nur `text`; Zustand steckt im Freitext und muss per Heuristik gelesen werden. Zielbild: Felder `zustand` (frisch|TK|trocken|konserviert|null) + `form` je Zutat im `recipe.generator`/`vk.generator`-Schema → Struktur gegen Struktur (`gps.condition`, demo: 7.196 von 7.750 GPs gesetzt; 554 leer = Getränke „flüssig"/Pulver ohne §9-Wert → Vokabular-Frage, nicht Naming).

### Paket B — Zwischenstand 2026-09-17 (Lisa, Branch `fix/wissen-retrieval-tomatensuppe`)

| Commit | Inhalt |
|---|---|
| `2dd154b5` | Kompositum-Grenze: Query-Tokens per `TerminologyService::decompoundPhrasesFor()` zerlegt (additiv), Substring-Regel beidseitig ab 5 Zeichen. `KnowledgeSearchDecompoundTest` 3/3 (inkl. Negativfall) |
| `badfa771` | `max_chars_per_doc` ehrlich stillgelegt: tote `$maxChars`-Pfade + Konstanten entfernt, Seed/W0-Command setzen den Wert nicht mehr, `pflichtZeichen()` rechnet mit realen Dossierlängen (Test 9.000/1.500 → 9.000). Tests umgestellt von Formel-Fiktion auf reale Längen |

Befund dabei: `pflichtZeichen()` hatte produktiv keinen Aufrufer — Riegel (`KnowledgeBudgetPutTool` → `WissensProfilService::pflichtBudgetFuer()`) und `wissen-steuerdaten-w0 --verify` rechnen seit jeher über den gemessenen `contextFor()`-Pfad. Die Explore-Diagnose „Rechner mit Fiktion" war produktiv nicht wirksam. **Folgepunkt (klein):** zwei Rechenwege (`pflichtZeichen` vs. `pflichtBudgetFuer`, Differenz = gemeinsamer Block-Header ~200 Z.) auf einen ziehen — bewusst nicht in diesem Paket.
| `b40d1768` | Query-Hygiene: `discoveryQuery()` = Brief + Hauptzutat-Slugs; die 19 Leitplanken-Werte sind raus (Golden/KnowledgeContext + RecipeKnowledgeBudget + WissenTokenWelle0 46/46). Offen: lokaler Test, ob `aroma_kueche`/`diaet_hart` als einzige Schlüssel zurück in die Query müssen |

**Messung „nachher" (Paket B) ist erst nach dem Deploy möglich** — `knowledge.PREVIEW` läuft gegen den auf demo deployten Code. Die drei Läufe (ohne Leitplanken · mit Leitplanken · Brief + `aroma_kueche=thai` + `diaet_hart=vegan`) gehören damit in Paket E, Referenz = Vorher-Tabelle oben; Vergleich nach Rängen, nicht Scores (Decompounding vergrößert den Jaccard-Nenner).
| `8fb1c0af` | Budget nach Rang: `KnowledgeContextBlock::assemble()` befüllt optionale Dokumente global nach Score (deterministische Blöcke Achsen/Pairing/always = 1,0 vorne, Discovery dahinter nach RRF), nicht mehr in Einfügereihenfolge; `ACHSEN_TRUNCATE_CHARS` entfällt. 70/70 in 7 Testdateien |
| `eddad086` | `aroma_kueche` + `diaet_hart` zurück in die Discovery-Query — lokaler Test belegt: weltkueche-/ernaehrung-Dossier verschwinden, wenn die Info nur in der Leitplanke steht (`QueryHygieneKuecheDiaetTest`); die anderen 16 Schlüssel bleiben draußen (eigene Selektoren oder keine Kategorie-Discovery) |
| `0260f811` | Golden-Test fachliches Zielbild Tomatensuppe (Aufgabe 8) + mechanischer Beleg Budget-nach-Rang direkt gegen `assemble()` (großes Rang-1-Dossier mit niedrigem Score verliert gegen kleines mit hohem; deterministisches Dossier nie verdrängt). 54/54 Kombi-Lauf |
| `84048e16` | „Verwendet" = gesendet (Aufgabe 5): `RecipeDependencyWorkflowService::afterGenerated()` liest die real gesendete Kanon-Liste aus dem bereits per `target_table/target_id` verknüpften Call-Log zurück und korrigiert `context_snapshot['kanon_files']` — statt der Vorab-Liste (pflicht + wenn_platz). Kein Eingriff in `AiGatewayService`/`GenerateRecipeJob`/`RecipeGenerationContextService`. `RecipeDependencyKanonFilesTest` 3/3 |
| `54d999de` | Verworfen getrennt ausweisen (Aufgabe 6): `context_snapshot['knowledge_dropped']` {retrieval, kanon} aus EINER Quelle (`contextFor()::files_dropped` + Call-Log-Rückweg), `laufStatus()` additiv, Kontext-Inspektor Chipgruppe „verworfen (k)" nach Quelle. 18/18 + PlanningCascadeTest 140/140. Rebase-Entscheid: Pauls flaches `kontext['wissen_verworfen']` (Codex-Vorarbeit, ungerendert) entfällt zugunsten des strukturierten Felds |

**Aufgabe 7 — Routing-Prüfung `recipe.generator` (Lisa, Ist von demo, Beleg = Vorher-PREVIEW):**

| Zeile | Ist | Beleg |
|---|---|---|
| `domain` | discovery, `max_docs` null (→ 4) | 2–5 Treffer in beiden Läufen — funktioniert |
| `cross_cutting` | discovery, `max_docs` 6 (`max_chars_per_doc` 8000 wirkungslos) | **0 Treffer** in beiden Läufen; 272 aktive Dossiers im Korpus |
| `regelwerk` | discovery, `max_docs` 2 | **0 Treffer** in beiden Läufen; die 8 `regelwerk-basisrezepte-*` kommen vollständig über den **Kanon**, nicht über diese Zeile; 77 Dossiers im Korpus |
| `kueche`/`weltkueche`/`signatur_kuechen`/`kreativ_input`/`niveau`/`ernaehrung`/`prasentation_service` | discovery, `max_docs` 1–2 | mit Leitplanken von `niveau.*`/`event_playbook` verdrängt (Aufgabe 2 behoben); ohne Leitplanken 0, Brief hatte keinen Bezug |

Code-seitig sind `cross_cutting`/`regelwerk` **nicht tot** (laufen durch `discoverGenericBlock`, nicht in `$spezial`) — der Leerlauf-Verdacht aus der Planung bestätigt sich nicht. **Entscheidung: keine Routing-Änderung jetzt.** Die 0 Treffer können an Query-Verdünnung und Kompositum-Blindheit gelegen haben (z. B. `mengen_defaults--suppen-eintopf-mengen` matcht „Tomatensuppe" erst nach Decompounding). Beide Zeilen gehen explizit in die Nachher-Messung (Paket E); bleiben sie dann bei 0, ist das ein Befund für Routing oder Korpus.

### Paket E — Vorbereitung 2026-09-17 (Paul, read-only)

Protokoll-Datei: `00_INBOX/_Spec53_Leitstelle/E_Abnahme_Protokoll.md` — Deploy-Checkliste (Lock-Pin 3 Felder, FA-scoped Migration `phase/phase_at`, `queue:restart` + supervisorctl + `ps`-Beleg), Mess-Protokoll 6 Messpunkte × 4 Läufe (Basisrezept Tomatensuppe · Gericht Rinderfilet/Püree/Jus · Speisekarte-aus-Brief · Foodbook-Kapitel), PREVIEW-Rang-Vergleich (3 Läufe), Tinker-Snippets. Befund: Speisekarte- und Foodbook-Brief laufen beide über `scope='vollkaskade'` (nur `owner_type` unterscheidet), Kapitel entstehen beim Andocken — kein separater Kapitel-KI-Pfad.

**Messlücke:** Structural-Re-Rolls sind im Call-Log nicht sichtbar (eine Zeile pro `propose()`, Tokens über alle Versuche summiert, kein Zähler). Zusatz-Auftrag Paul: Versuchszähler in `prompt_parts` der Call-Log-Zeile (Branch `fix/call-log-reroll-zaehler`), damit §9 „Wie oft greift der Retry?" messbar wird.

Re-Roll-Zähler gebaut: PR #95 `fix/call-log-reroll-zaehler` (Basis #94, Commit `576dba09`, +41 Zeilen): `prompt_parts.versuche` + `prompt_parts.reroll_grund` (strukturell | json_ungueltig | provider_fehler) in der bestehenden Call-Log-Zeile, Modell-Fallback bewusst nicht als Re-Roll gezählt (läuft in `chatMitBackoff()` intern). Review-Fix `5ee66ed8`: Klassifikation per Exception-Typ (`KiAntwortKeinJsonException`, `KiAntwortStrukturellUnbrauchbarException`, beide extends RuntimeException) statt Message-Substring; Wächter-Test mit abweichendem Wortlaut. Keine eigene volle Suite — B und D fahren sie nach Rebase auf main inkl. #94/#95.

**Paket B rebased auf A (`b2b2f86f`), 9 Commits:** `c9764d31` Kompositum · `c656893a` max_chars_per_doc · `73b1633a` Query-Hygiene · `d103fe53` Budget nach Rang · `4f8adc5c` aroma_kueche/diaet_hart · `c91e6940` Golden-Test · `1e056c41` kanon_files · `ba4313a0` verworfen getrennt · `abe306c7` Rebase-Kollisionsfix. Add/Add-Konflikt `RecipeRetrievalQualityTest.php` (Pauls Datei aus dem Codex-Diff) → Lisas Tests nach `WissenGoldenTomatensuppeTest.php`; Pauls Ranking-Test 9/9 weiter grün. **Stiller Rebase-Fehler gefunden:** Git mergte Pauls flache `wissen_verworfen`-Zeile ohne Marker als Duplikat-Key HINTER die strukturierte Zuweisung — zur Laufzeit gewann die flache Liste; ein roter Test (`WissenSichtStepZeileTest`) zeigte es, Fix `abe306c7`. Testgate nach Rebase 109/109. Volle Suite freigegeben (parallel zu C, eigene Sandbox).

**Paket B volle Suite (sandbox-wissen-retrieval, 3 Prozesse parallel zu C, 60,5 Min): 4.470 Tests, 4.464 passed, 6 skipped, 0 failed, 22.838 Assertions** (= 4.452 Basis A + 18 neue). Push + PR gegen main folgt.

**Paket D rebased auf C (`3a7834cc`, konfliktfrei, Auto-Merge-Zonen manuell geprüft: je 1× Script-Tag/`?tab=`/`modal.open`/`$pollAktiv`/`$kiStatus`). Volle Suite (sandbox-voice, 3 Prozesse, ~49 Min) auf dem Stapel A+C+D: 4.503 Tests, 4.497 passed, 6 skipped, 0 failed.** PR #98 (eigene Commits `8314ba4d`…`5870d4c2`). Offen: Live-Browser-Abnahme Chrome × Safari (Dominique nach Deploy); `kontext{type,id}` in einzelnen Rezept-Seiten verdrahten (Fallback `?rezept=` deckt den Hauptfall).

## Stand 2026-09-17 abends — alle vier Pakete grün, Merge ausstehend

| Paket | PR | Suite | Basis |
|---|---|---|---|
| A Basisrezept (Paul) | #94 + #95 (Re-Roll-Zähler) | 4.452 / 0 rot | main `727abe19` |
| B Wissen (Lisa) | #97 | 4.470 / 0 rot | A |
| C Feedback/Phasen (Peter) | #96 | 4.472 / 0 rot | A |
| D Sprachbefehl (Oskar) | #98 | 4.503 / 0 rot | A + C |

Merge-Reihe: #94 → #95 → #97 → #96 → #98 (C und D integrieren `main` per Merge-Commit, kein Rebase — hält Hashes für den jeweils darauf gestapelten Branch stabil). `gh pr merge` aus der Orchestrierungs-Session ist im Auto-Modus blockiert („Merge Without Review"); Merge durch Dominique oder nach Permission-Freigabe. Danach ein Deploy demo (Checkliste `00_INBOX/_Spec53_Leitstelle/E_Abnahme_Protokoll.md`), dann Paket E.

## Merges 2026-09-17 (Freigabe Dominique 19:55 „du kannst mergen")

| PR | Merge-Commit | Zeit (UTC) |
|---|---|---|
| #94 A Basisrezept | `af4fec9b` | 17:56 |
| #95 Re-Roll-Zähler (Base auf main retargetet) | `ec9238a9` | 17:58 |
| #97 B Wissen | `5fb8c955` | 17:59 |
| #96 C Feedback (main per Merge-Commit `955e2191` integriert, 307/307 gezielt) | `e840e5b6` | 18:12 |
| #98 D Sprachbefehl (main per Merge-Commit `46c2c75d` integriert, 228/228 gezielt) | `d104db08` | 18:25 |

Deploy-Entscheid (Orchestrierung, von Dominique freigestellt): ein Deploy nach Merge aller fünf PRs — die Branches sind gegen den vollen Stapel getestet, ein Teil-Deploy brächte keinen Vorteil.

**Deploy demo 2026-09-17 ~18:30 UTC:** Lock-Pin `727abe19` → `d104db08` (3 Felder, nur FA), Commit im demo-Repo, Push → Forge. **Verifiziert 18:40 UTC:** Pin `d104db08` am Host (demo-main `192c210`), Vendor-Code neu (`setzePhase`, `produktForm`, Voice-Recorder-Bundle), Spalten `phase`/`phase_at` vorhanden (Forge migrierte selbst; FA-scoped `migrate` danach: Nothing to migrate), `view:clear` + `config:cache`, `queue:restart` + supervisorctl-Neustart aller 6 FA-Worker; `ps`-Beleg: 2× default + fa-anreichern/fa-gerichte/fa-kaskade/fa-rezepte je 1 + attachments.

## Paket E — Messungen nach Deploy (2026-09-17)

### B: Wissensauswahl „nachher" (Lisa, `knowledge.PREVIEW`, demo Team 6, Code `d104db08`, Budget 64.000, exakt der Vorher-Brief)

| Lauf | Top-Retrieval (via hybrid, lex/sem-Rang) | Zahlen |
|---|---|---|
| 1 ohne Leitplanken | `suppen_systematik--eintoepfe-volumen-suppen` (14/1) · `suppen_systematik--suppen-hierarchie` (20/5) · `suppen_systematik--gebundene-suppen-verdickungs-mechanismen` (16/10) · `suppen_systematik--service-logik` (19/21) · 3× `referenz-rezept-*-tomate-*` (lex 1/3/4) | Pflicht 46.605 · verworfen 13.717 · gesendet 63.539 |
| 2 mit Leitplanken (gehoben, business_catering, catering, herbst, klassisch) | **byte-identisch zu Lauf 1** | identisch |
| 3 + `aroma_kueche=thai`, `diaet_hart=vegan` | wie 1, plus `pflanzlich_konfieren_kennwerte--festigkeits-prinzip` (9/21, vegan-relevant); `kasealternativen-vegan` gefunden (lex 3), aber **budget-gedroppt** | Pflicht 46.605 · verworfen 13.424 · gesendet 62.351 |

Vorher: kein Tomaten-/Suppen-Dossier in der Top-5 (lex-Ränge 22–51), mit Leitplanken komplett von `niveau.*`/`event_playbook` verdrängt. **Nachher:** Decompounding wirkt lexikalisch (Tomate lex 1/3/4, Suppe 14–23, alle hybrid), Query-Hygiene wirkt (Leitplanken ohne Einfluss), Diät-Ausnahme wirkt (vegan-Signal gefunden). `cross_cutting`/`regelwerk` weiterhin 0 im Retrieval — jetzt ein sauberer Befund: für zutatenfokussierte Briefs strukturell selten die beste Quelle; Stichprobe mit verfahrenslastigem Brief („Sauce binden ohne Ei") vor einer Routing-Änderung. Kanon 13 Dossiers / 46.605 Zeichen in allen Läufen unverändert.

**Konsequenz (Dominique 17.09.: „Menge für Discovery erhöhen"):** `knowledge_budget.PUT recipe.generator 64.000 → 80.000` — Discovery bekommt ~33.000 statt ~17.000 Zeichen; Erwartung: `kasealternativen-vegan` und `rezept_aufbau_prozessstufen` kommen in Lauf 3/1 hinein. Nachmessung folgt.

**Nachmessung @ 80.000 (Lisa):** Lauf 1: verworfen 0, gesendet 77.256 (vorher 63.539) — zusätzlich `rezept_aufbau_prozessstufen` (3.714), `fruchtgemuse-sorten-ubersicht` (3.643), `fonds_jus_consomme_kennwerte--5-jus` (2.944), `pflanzlich_konfieren_kennwerte` (3.108); 11 Retrieval-Dossiers, alle drin. Lauf 3: verworfen 0, gesendet 75.775 — zusätzlich `kasealternativen-vegan` (2.955, lex 3 — der Gewinn) und `allergen_patterns--ramen` (2.294, lex 27/sem 20 — **Ausreißer**: Ramen-Allergenmuster hat mit Tomatensuppe nichts zu tun, wirkt wie generisches Diät-/Allergen-Rauschen). Einschätzung: Auswahl bei 80k überwiegend fokussiert; Beobachtungspunkt: bei `diaet_hart`-Kombinationen prüfen, ob `allergen_patterns--*` systematisch mitkommt. Budget bleibt 80.000.

### Live-Lauf 1 — Basisrezept Tomatensuppe (Session 125, Lauf 69, Step 474 → Rezept 3753 „Suppe: Tomate-Basilikum", Budget 80.000, `complete_coverage=false`, `ki_bilder=false`)

| Messpunkt | Ergebnis |
|---|---|
| 1 Phasen (DB `phase`, 2-s-Poll) | 20:36:21 „KI schreibt das Rezept …" → 20:39:18 „Kohärenz-Gate: Fremdkörper werden geprüft …" → 20:39:26 `done` + „Konformität wird geprüft …" → 20:40:46 Phase gelöscht. Phase im MCP-Status sichtbar (`schritte[].phase`). „Kontext & Wissen"/„Zutaten zugeordnet" zu kurz für 2-s-Poll (7,4 s / 2,3 s) |
| 2 `timings` | context 7.416 · generation 169.265 · matching_and_save 2.256 · checks 13.091 · generator 192.386 ms — **Generierung = 88 %** |
| 3 Call-Log | `recipe.generator` in 29.648 / **cached 29.440 (99 %)** / out 2.767, 169 s, 91.390 Prompt-Zeichen, `versuche=1` (kein Re-Roll) · `recipe.review` (Kohärenz-Kritiker, wegen verdrahtetem Sub) 5.272/1.792/650, 12,6 s · `conformance.check` 14.818/14.080/1.090, 80,5 s (`deferred.conformance.ms=80563`) |
| 4 Wissen | Kanon 13, Retrieval 11 (Suppen-Systematik ×4, Rezeptaufbau, Fruchtgemüse, Fonds, pflanzlich konfieren, 3 Tomaten-Referenzrezepte), verworfen {kanon: [], retrieval: []} — Anzeige ehrlich |
| 5 Zutaten (13, **0 offen**) | „Stückige Tomaten aus der Dose" → `Tomaten: konserviert, gewuerfelt / Polpa` ✓ · Staudensellerie/Sahne/Olivenöl/Basilikum alle geerdet ✓ (späte Zutaten) · Pfeffer → `trocken, gemahlen` ✓ · Tomatenmark konserviert ✓ · Salz/Zucker trocken ✓. GP-Wahl-Schönheitsfehler: `Karotten: frisch, mini, gemischt` und `Zwiebeln: frisch, geachtelt` (Form passt nicht zum Suppenansatz — Ranking-Bonus für Form greift hier nicht sichtbar) |
| 6 Sub-Zerlegung | `Fond: Helle Gemüsebrühe` (#1452) übernommen, korrekt als **unreif** markiert („keine Schritte"); Run-Kopf `uebernommen=1, uebernommen_unreif=1` |

EK 7,74 € / 1,96 kg (3,95 €/kg). Steps 0 (Anreicherung läuft erst nach Freigabe). Vergleich Screenshot vorher: 11 von 13 bepreist, Dosentomaten offen → jetzt 13/13 verknüpft.

### Live-Lauf 2 — Gericht Rinderfilet/Kartoffelpüree/Jus (Session 126, Lauf 70, Step 476 → VK-Rezept 3754, Leitplanken level=gehoben, sektor=catering, saison=herbst; `vk.generator`-Budget 49.000 unverändert)

| Messpunkt | Ergebnis |
|---|---|
| 1 Phasen | 20:42:53 „KI schreibt das Rezept …" → 20:43:35 „Zutaten werden zugeordnet …" → 20:43:39 `done` → anschließend „Konformität wird geprüft …" (im MCP-Status sichtbar) |
| 2 `timings` | context 10.044 · generation 48.953 · matching_and_save 3.153 · checks 178 · generator 62.717 ms |
| 3 Call-Log | `vk.generator` in 23.418 / cached **0** (erster Aufruf mit diesen Leitplanken) / out 2.866, 48,9 s, `versuche=1` · `conformance.check` 13.200/12.032/1.784, 30 s · `vk.ueberarbeiten` (Selbstheilung Schicht 3) 13.350/12.032/710, 9,8 s |
| 4 Wissen | Kanon 12, Retrieval 8 (`event_playbook_business_lunch`, `segment.event_bankett_catering`, 3× `niveau.*`, 2 Referenzgerichte Elverfeld, `referenzgericht.niveau2_plant_forward_sellerie`); **verworfen (Retrieval) 3**: `fonds_jus_consomme_kennwerte--5-jus`, 2× `niveau.*-kernaussage` — jetzt sichtbar im Status (`wissen_verworfen`) |
| 5 Ergebnis | „[HG] Rinderfilet · Malz-Rinderjus · Kartoffelpüree · Herbsttrompeten · Hokkaidokürbis", Beschreibung business-catering-tauglich; VK/Speisen-Klasse leer (Anreicherung erst nach Freigabe) |
| 6 Sub-Zerlegung | 6 Kinder: übernommen `Sauce: Malz-Rinderjus` (#3728, **unreif**: keine Schritte), `Proteine: Rinderfilet sous-vide` (#3666, reif), `Gemüsebeilage: Gerösteter Hokkaido-Kürbis` (#3511, **unreif**), `Crunch: Haselnuss-Ciabatta` (#3534, reif); geplant `Kartoffelpüree`, `Beilage: Herbsttrompeten gebraten`. Run-Kopf `uebernommen=4, uebernommen_unreif=2` ✓ |

Beobachtung: die Fonds/Jus-Kennwerte wurden bei einem Jus-Gericht budget-verworfen — Kandidat für eine Budget-Anhebung auch bei `vk.generator` (heute 49.000), Entscheidung Dominique (Gerichte laufen in Kaskaden vielfach → Kosten).

### Live-Lauf 3 — Speisekarte-aus-Brief (Session 127, Lauf 71, Speisekarte 5, 2 Rubriken × 2 Gerichte) und Live-Lauf 4 — Foodbook-aus-Brief (Session 128, Lauf 72, Foodbook 24, gestuft)

Phasen-Trace (2-s-Poll über alle Steps):
- **Gericht-Steps der Speisekarte (Tiefe 0, `MaterializeSpeisekartePositionJob`) zeigen KEINE Phase** („running | -" bis `done`) — 483→3755 (28 s), 484→3756, 485→3758, 486→3760; alle 4 Gerichte in 3 Min. **Lücke Paket C:** `MaterializeSpeisekartePositionJob`/`MaterializeSpeiseplanCellJob`/`GenerateDishProposalJob` rufen `fortschritt()`/`setzePhase()` nicht.
- **Concept-Step des Foodbooks (Tiefe 0, `GenerateConceptJob`) ebenfalls ohne Phase** — 505 → Concept #146 in 28 s. Gleiche Lücke.
- **Sub-Rezept-Steps (Tiefe 1/2, `GenerateRecipeJob`) zeigen die volle Kette:** z. B. #489: 20:46:38 Kontext & Wissen → 20:46:40 KI schreibt → 20:47:40 Zutaten → 20:47:42 Kohärenz-Gate → 20:48:22 done + Konformität → 20:49:59 Phase frei. Reuse-Kinder (`skipped`) und `geplant` erscheinen sofort beim Andocken.
- **Durchsatz:** Sub-Rezepte laufen auf `fa-rezepte` mit EINEM Worker seriell (~1,5–2 Min je Basisrezept inkl. Konformität) — eine Speisekarte mit 4 Gerichten zieht ~12 Sub-Rezepte ⇒ 20–25 Min bis alles durch ist. Für Speiseplan (30 Zellen) skaliert das nicht; Kandidat: 2–3 Prozesse auf `fa-rezepte` (Forge) oder Konformität von der Kette entkoppeln.

**Ergebnisse Lauf 3 (Speisekarte 5):** 4 Gerichte in 3 Min — „[SUP] Hokkaidokürbissuppe · Apfelgel · Kürbiswürfel · Haselnusskrokant · Salbeiöl", „[VOR] Radicchio-Rucola-Salat · Perlhuhn · Apfel · Tomaten-Butter-Dressing", „[HG] Rinder-Sauerbraten · Saishikomi-Jus · Freekeh · Hokkaido-Kürbispüree · Gruyère-Haselnuss-Crunch", „[HG] Hokkaidokürbis · Pilzjus · Selleriepüree · Kartoffelrösti · Birnen-Chutney". Sub-Rezepte: 9 übernommen (Namen exakt getroffen: Gel: Apfel #1457, Krokant: Haselnuss-Hippe #868, Gemüse-Püree: Kürbis #384, Jus: Braten Standard #1571 …), **8 davon unreif** (Bestand in `review` ohne Schritte — Datenqualität Bestand, sichtbar markiert); 5 neue Basisrezepte (Crème-Suppe Hokkaido, Aromaöl Salbei, Dressing Tomate-Butter, Garmethode Perlhuhn, Jus Saishikomi) mit **0 offenen Zutaten**. **Befund:** in den Gerichten selbst 6 Zeilen `unmatched` (Kürbiswürfel geröstet, Rinder-Sauerbraten aus der Rolle [GP mit Score 1,0 existiert!], Freekeh gekocht, Gruyère-Haselnuss-Crunch, Vegetarische Pilzjus, Kartoffelrösti) — weder GP noch geplantes Sub-Rezept ⇒ EK-Lücke am Gericht. → Analyse Paul.

**Ergebnis Lauf 4 (Foodbook 24):** Kapitel-Concept „Business-Lunch-Buffet" (#146) in 28 s, 5 Stationen (Kalte Vorspeisen/Salate · Warme Hauptkomponente · Sättigungsbeilagen · Dessert · Getränke) mit je einem leeren Gericht-Slot. Nach FREIGABE: Lauf `done`, **„5 von 5 Positionen ohne Skizze — die KI hat keine geliefert"**, keine Gericht-Steps. → Analyse Peter.

**Call-Log 20:45–20:56 (Läufe 71+72): 29 Aufrufe, 437.711 Tokens in (312.320 = 71 % cached), 49.708 out, 16,1 Min Modellzeit, 0 Re-Rolls.** Ø je Feature: recipe.generator 67,6 s (n=5) · vk.generator 41,5 s (n=4) · recipe.review 42,1 s (n=2, Kohärenz-Kritiker — teuer) · conformance.check 28,2 s (n=9) · recipe.ueberarbeiten 13,2 s (n=4) · concept.brief_geruest 17,6 s · concept.plan 14,5 s.

**Voice-Tool live (MCP `planung_vorschlag.POST`, „Erstelle ein Gericht mit Rinderfilet, Kartoffelpüree und Jus, gehoben, fürs Business-Catering im Herbst"):** Leitplanken {level: gehoben, sektor: catering, saison: Herbst}, 5 Rückfragen (Occasion, Serviceform, Pax, Budget, Bio), Konfidenz 0,93, nichts angelegt ✓.
