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

**Timeout live getroffen (Lauf 71, Step #497 „Rinder-Sauerbraten", 20:59:28):** `GenerateRecipeJob` `timeout=300` überschritten (fa-rezepte). Step zeigt **„Zeitüberschreitung nach 300 s"** (Paket A, `f619c332`) statt Laravel-Rohtext ✓. Bewertung: 300 s sind bei 169-s-Generierungen (gpt-5.5, 29k Tokens) + Kohärenz-Kritiker Ø 42 s real erreichbar → Vorschlag `$timeout` 540 s (Worker `--timeout=600`), an Paul. Nebenbefund: dispatchte, noch nicht aufgenommene Steps stehen als `running` mit `phase NULL` (Serialisierung hinter einem Worker) — UI zeigt „eingereiht — wartet auf Worker", MCP-Status unterscheidet nicht.

**Offene Analysen nach Paket E:** (a) 6 `unmatched`-Zeilen in VK-Gerichten trotz GP-Kandidaten → Paul; (b) Foodbook-Fan-out ohne Skizzen nach Concept-Freigabe → Peter; (c) Phasen für Materialize-/Concept-/DishProposal-Jobs → Peter (Branch `feat/job-phasen-anzeige`); (d) `$timeout` 540 → Paul.

**Stand (c) 2026-09-17 22:30 — Review `feat/job-phasen-anzeige` (d2e4a3f7 + dc4eb4f2, lokal, noch kein PR):** Phasen für GenerateConceptJob („Gerüst wird geplant …"), FanoutConceptJob („Skizzen werden erfunden …", finally-Löschung, Härtung bei fehlendem User), GenerateDishProposalJob (+ `failed()`), Materialize-Pfade Speiseplan-Zelle/Speisekarte-Position über den `generiere()`-Callback; `laufStatus()` liefert für queued/running ohne Phase denselben Text „eingereiht — wartet auf Worker" wie das Cockpit. `planung_kaskade.FREIGABE`: `gibStepFrei()` routet jeden `geplant`-Step aufs Erzeugen-Gate und gibt ein Aktions-Tag (`freigegeben|geplant_erzeugt|no_op_status_<status>`), das Tool zeigt `aktion` + `aktion_hinweis` (Anlass Lauf 74, Step 513). Review-Befund (cooking-jarvis-03): Schleifen `gibStufeFrei`/`gibRunFrei` filtern `done`, kein ungewolltes Erzeugen. **Zwei Lücken zurückgegeben:** `materialisiereConceptGericht()` (Z. 1339, Pfad für MaterializeConceptIdeaJob = jedes erfundene Concept-Gericht) ruft `generiere()` ohne Callback; `ReviseDishProposalJob` ohne Phase/`failed()`. Volle Suite vor PR verlangt.

**Root Cause Foodbook-Fan-out ohne Skizzen (Peter, code-belegt):** Trigger-Kette intakt (Freigabe → `starteFolgestufe` → `FanoutConceptJob` → `fanoutConceptInvention`). `IdeenService::kiDivergenzConcept()` baut den Wissensblock aus zwei `contextFor()`-Aufrufen mit `_max_chars` 14.000 + 5.000 (Reduktion vom 2026-09-02) — `propose()` prüft den Retrieval-Block aber gegen `KnowledgeBudget::forKey('foodbook.kapitel_ideen')` = Config-Default **16.200** ⇒ `KnowledgeBudgetExceeded` VOR Call-Log und Provider; `fanoutConceptInvention` fängt mit nacktem `catch (\Throwable) { $ideen = []; }` ohne Log ⇒ Deckel-Hinweis „die KI hat keine geliefert" ist falsch (sie wurde nie gefragt). Geschwister-Pfad `kiDivergenz()` (Kapitel) ohne Override läuft. Zwei Memory-Muster in einem: stiller catch + Deckel prüft falschen Schlüssel. Fix (Peter, Branch `fix/kapitel-ideen-budget`): `_max_chars` aus dem Budget ableiten, Log im catch, ehrlicher Hinweis. **Sofort-Mitigation demo:** `knowledge_budget.PUT foodbook.kapitel_ideen 16.200 → 24.000`; Gegenprobe mit frischem Foodbook-Lauf.

## Hotfix 2026-09-17 ~21:15 — Produktionsfehler nach Deploy (PR #100, `d9d9637c`)

**Symptom (Dominique, demo /planung, Chrome):** `MissingFileUploadsTraitException: Cannot handle file upload without [WithFileUploads] on [foodalchemist.sidebar]` beim Sprachbefehl; Planung nicht mehr bedienbar; Aufnahme-Knopf zunächst leer gerendert.
**Ursache (verifiziert `vendor/livewire/.../Drawer/Utils.php::insertAttributesIntoHtmlRoot`):** Livewire hängt `wire:id` per Regex an das **erste Tag** der Komponenten-HTML. Die rohen `<script>`-Tags des Recorder-Bundles (PR #98) standen vor dem Root von `voice-modal.blade.php` und `planung/index.blade.php` → `wire:id` am Script, das eigentliche Markup gehörte zur Eltern-Komponente (Sidebar) bzw. zerfiel. Die Root-**Zählung** (`SupportMultipleRootElementDetection`) strippt Scripts vorher — das hatte im Review beruhigt; die Attribut-**Injektion** strippt nicht. Zwei Codepfade, eine Falle.
**Fix:** beide Script-Tags in `@assets` (Head-Injektion vor Alpine, Muster pairing-netz); Regressionstest „erstes Tag der VoiceModal-HTML ist `div`". view:cache sauber, 46 Tests grün (Voice ×2 + PlanungBriefingUi). Deploy per Lock-Pin `d104db08 → d9d9637c`.
**Lehre:** „Livewire meckert nicht über mehrere Roots" ≠ „der Root ist das richtige Element". Gotcha von Oskar in `feedback_blade_alpine_livewire_gotchas` als #8 gesichert.
Hotfix-Deploy verifiziert 21:20 UTC (Pin `d9d9637c` am Host, `@assets` in beiden Blades, `view:clear`/`view:cache`). Zusätzlich gemergt: **#99** `GenerateRecipeJob::$timeout` 300 → 540 s (`1f55ebce`, Paul) — geht mit dem nächsten Lock-Pin live.

**Voice-Abnahme Chrome (Dominique, 21:22, nach Hotfix):** „Suche BBQ-Sauce" → „Ich habe 10 BBQ-Rezepte gefunden, z. B. „BBQ-Sauce: Kansas City" (ID 523), „Memphis Style" (ID 525) und „Cola-Ketchup" (ID 528). Welches soll ich öffnen?" — 2 Runden · 1 Tool-Aufruf · 20.813 ms, Provider-Pill „STT: OpenAI" sichtbar ✓. Planung: „Briefing diktieren" → „Diktat übernommen — bitte gegenlesen" ✓ (Text korrekt transkribiert inkl. „Dosentomaten"). Status-Leiste zeigt „2 Fehler (24 h)" (Timeout-Step + früherer Fehler) ✓. Offen: Safari, „Öffne die Planung", „Erstelle ein Gericht …" (Vorschlag + Klick), Fehlerfälle.

**Gegenprobe Fan-out (Lauf 73, Foodbook 25, Concept #147, Budget `foodbook.kapitel_ideen` 24.000):** wieder `done` mit „4 von 4 ohne Skizze", kein Ideen-Call im Log → **Budget-Hypothese widerlegt** (oder nicht allein). Direkte Reproduktion per tinker mit `Auth::login(User 7)`: `kiDivergenzConcept(Team 6, 147, 4)` → **OK nach 31,6 s, 4 Ideen angelegt** (call_log 2193). Der Aufruf funktioniert mit Nutzer-Kontext; im `FanoutConceptJob` (Worker) scheitert er stumm vor dem Provider. Neue Hypothese (an Peter): fehlender Auth-/Team-Kontext im Job (`ConformanceCheckJob` loggt den User explizit ein, Fan-out vermutlich nicht). Unabhängig davon Pflicht: Log im catch, ehrlicher Deckel-Text. Budget 24.000 bleibt vorerst (unschädlich).

**Deploy #3 (21:35 UTC, Pin `d9d9637c → 57fd4235`, demo-main `d850929`):** #93 (Dominique, Bugrunde 17.09., aus anderer Session), #99 Job-Timeout 540 s, #101 Fan-out mit Log im catch + ehrlichem Deckel-Text + Budget-Kappung. Worker neu gestartet. Nächster Schritt: Fan-out wiederholen → Exception-Klasse aus dem Log lesen (Auth-/Team-Kontext-Hypothese prüfen).

**Fan-out nach Deploy #3 funktioniert (Lauf 74, Foodbook 26, Concept #148):** FREIGABE → 6 Gericht-Steps `geplant` (Horiatiki-Getreidesalat · Weiße-Bohnen-Zitronen-Suppe · Zitronen-Hähnchen mit Oregano · Fenchel-Kartoffel-Gemüse · Joghurtcreme mit Honig-Aprikose · Gurke-Minze-Zitronenwasser), 1 `kapitel_ideen`-Call, kein Deckel, kein Log-Warning. **Wirksamer Fix = #101 (a) harte Kappung des zusammengesetzten Ideen-Blocks auf `KnowledgeBudget::forKey('foodbook.kapitel_ideen')`** — die reine Budgeterhöhung (Lauf 73) reichte nicht, weil der Block (2× contextFor + Ursprungs-Trend + Kopfzeilen) auch 24.000 riss. Auth-Hypothese verworfen (tinker-Erfolg = kleinerer Kontext, nicht Login). Struktur-Befund Peter bleibt: `FanoutConceptJob` ist der einzige Job ohne harten Abbruch bei fehlendem User → Konsistenz-Härtung im Phasen-Branch.

**Root Cause 6 unmatched-Zeilen (Paul, PR #102, Commits `9dbea50a`+`b006de45`):** `offene[]['index']` wird VOR `sortiereNachRolle()` gebaut; die §12.2-Rollen-Sortierung (Boden→Haupt→Beilage→Garnitur) verschiebt Zeilen, der Index zeigt danach auf die falsche Position → `planChildren()` findet die Zeile nie → kein Sub-Step, Zutat bleibt `unmatched`. Fix: Sortierung liefert Alt→Neu-Index-Map, `offene` wird nachgezogen. Stash-Probe belegt Vorher-Fehler (2 statt 1), 2 Tests (Kontrakt + End-to-End geplanter Sub-Step), Regression 50 grün. Nebenbefund: Garnitur/Aroma-Treiber ohne Sub-Signal routen bewusst auf LA-Wahl, nicht Basisrezept — separates Routing-Thema, falls gewünscht.

**MCP-Lücke:** `planung_kaskade.FREIGABE` auf einen `geplant`-Gericht-Step (513) im gestuften Foodbook-Lauf ist ein No-op (Step bleibt geplant); die UI hat dafür „jetzt erzeugen" (`erzeugeGeplant`). Headless fehlt das Pendant → Folgepunkt (Peter, MCP im Lockstep).

**Deploy #4 (Pin `57fd4235 → af1e327f`):** #102 Index-Nachzug. Entscheidungspunkt Dominique (aus Pauls Nebenbefund): Garnitur-/Aroma-Treiber-Zeilen ohne Sub-Signal routen heute auf Lieferantenartikel-Wahl (Mensch) statt Basisrezept-Anlage — soll ein VK-Gericht solche Zeilen als Sub-Rezept planen (Regel „Gericht = aus Basisrezepten") oder als Kaufware offen lassen?

**Nachmessung #102 (Lauf 75, Speisekarte 6, derselbe Brief wie Lauf 71):** 4 Gerichte — Kürbiscremesuppe · Radicchio-Rucola-Salat mit Rote Bete/Ziegenkäse · Rinder-Sauerbraten mit Saishikomi-Jus/Selleriepüree/Freekeh · Kartoffelteig mit Kürbisfüllung/Pilzjus/Wirsing. Offene Zeilen in den Gerichten: 7 (Lauf 71: 6) — **davon 0 ohne Kind-Step** (Lauf 71: 5 ohne). Jede offene Zeile (Steinpilzreduktion, Petersilienöl, Beurre-noisette-Dressing, gepickelter Apfel, Rinder-Sauerbraten, Freekeh gekocht, Pilzjus) hat einen laufenden Sub-Rezept-Step; 9 Bestandsübernahmen. Damit ist die EK-Lücke am Gericht strukturell geschlossen — die Zeilen binden nach Abschluss der Sub-Generierung.

**Entscheid Dominique (21:40):** Offene Garnitur-/Aroma-Treiber-Zeilen ohne Halbfabrikat-Signal werden **Basisrezepte** (dort hängen Rüstzeit, Schritte, Kalkulation) — nicht Lieferantenartikel-Wahl; „die alte Idee war ein falscher Gedanke". Auftrag Paul: Branch `fix/garnitur-aroma-als-basisrezept`, Routing offener Zeilen mit role ∈ {komponente, beilage, garnitur, aroma_treiber} → `basisrezept_anlegen`; GP-Treffer bleiben GP. Regelwerk Basisrezepte §4 wird um den Satz ergänzt.
Regelwerk nachgezogen: FA-Dossier `regelwerk-basisrezepte-4-sub-rezept-hierarchie-stubs` v4 (SSOT, per `knowledge.PUT`) + Vault-Spiegel `Regelwerk_Basisrezepte.md` 1.10 mit F4.5 und Changelog. Kurationspunkt: Dossier jetzt 4.002 Zeichen (Deckel 4.000) — bei nächster Gelegenheit §4 in „Stubs/Defaults" und „Rekursion/Versionierung/F4.5" teilen.

**PR #103 (Paul, `68210ac2`):** `$rolleKomponente`-Whitelist {komponente, beilage} → {komponente, beilage, garnitur, aroma_treiber}; GP-Treffer gewinnt weiterhin rollenunabhängig (`!$istGpTreffer`). 3 Tests (Petersilienöl/Steinpilzreduktion → geplant; Petersilienöl mit GP-Treffer → GP), Regression 204 grün. Gemergt, Deploy mit Peters Phasen-PR.

**Editor-Entscheid (Dominique, 21:50, Empfehlung Orchestrierung angenommen):** Grundprodukt-Picker im Gericht-Editor bleibt als bewusste Ausnahme („Kaufware / Garnitur ohne Zubereitung"), Basisrezepte werden Standard-Tab; Konformitätsprüfung erhält Befund „GP-Zeile mit Rolle Komponente/Beilage am Gericht gehört als Basisrezept angelegt". → Folgepaket.

## Folge-Spec 54 (Idee Dominique, 21:52) — Produkt-Agent als schwebender Begleiter

Vision: frei ziehbarer, dauerhaft sichtbarer Sprach-Agent („Kreis"), der die Plattform je nach Kontext steuert. Einordnung: (1) FA-intern machbar — Mount im FA-Layout statt Sidebar, Position gemerkt, heutiges Panel; plattformweit braucht Core-Mount + Tool-Policies je Modul (Martin). (2) Kontext: Seiten melden das geöffnete Objekt (Rezept/Gericht/Speisekarte/Lauf) — Ansatz aus Paket D (`kontext{type,id}`, `herkunftRoute`) ausbauen. (3) Reichweite: lesen frei, schreiben zweistufig — reversible Kleinigkeiten direkt (navigieren, filtern, Entwurf freigeben), Unumkehrbares mit Klick. Grenzen: Latenz 8–20 s → kleines Modell fürs Verstehen + Jobs mit Phasen für lange Aktionen; Dauer-Zuhören → Push-to-talk/Hotkey statt Wake-Word (Kosten, Datenschutz, Fehlauslöser). Aufwand FA-intern 1–2 Sessions; plattformweit Core-Anfrage.

## Paket F (21:58, Dominique) — Agenten-Modus für den Sprachbefehl (Oskar, Branch `feat/voice-agent-modus`)

Vorbild: Mode-Switcher in Claude Code (Manual / Auto / Plan). Team-Setting `voice_agent_mode` mit drei Modi: `fragen` (Default = heute: lesen frei, jede Schreibaktion nur als Vorschlag mit Klick), `auto_sicher` (reversible Schreibaktionen — Planungs-Session anlegen, Anreicherung starten, Speisen-Klasse übernehmen — laufen direkt und werden als „ausgeführt" gemeldet; Unumkehrbares — löschen, veröffentlichen, bestellen, approved setzen, confirm/force-Flags — bleibt Vorschlag mit Klick; Kriterium `risk_level` + explizite Liste, keine Namensmuster), `nur_lesen` (keine Vorschläge). UI in den FA-Einstellungen (Radio + je ein Satz), Modus-Pill im Modal mit Sprung in die Einstellungen, Systemprompt kennt den Modus, MCP `team_settings.PUT` validiert den Key (Lockstep). Tests je Modus.

**Paket F erweitert (22:02–22:04, Dominique: „dauerhaft aktivieren, bei Bedarf ausschalten … so wie Claude-Audio-Modus"):** Zielbild Freisprech-Gespräch. Reihenfolge im Branch: (1) Agenten-Modus · (2) Schalter „Agent dauerhaft aktiv" (pro Nutzer), Mount außerhalb des Sidebar-`x-if`, schwebender verschiebbarer Startknopf (Vorgriff Spec 54) · (3) Gesprächsmodus: Stille-Erkennung am Recorder (RMS, ~1,2 s Pause → senden; 20 s Stille → verwerfen ohne STT-Call), gesprochene Antwort (Stufe 3a Browser-`speechSynthesis` de-DE, 3b OpenAI-TTS als Config-Option später), Zustände hört zu/sendet/versteht/spricht/aus, kein Zuhören während er spricht, Escape/Schalter beendet sofort, Push-to-talk bleibt Fallback. Bewusst nicht in F: Barge-in, Wake-Word, Latenz-Fix (Verstehen 8–20 s).
**Stimme (Entscheid Dominique 22:08): OpenAI TTS** (`gpt-4o-mini-tts`, ~0,3 ct je Zwei-Satz-Antwort), Browser-`speechSynthesis` nur Fallback. Bau: `Tts/TtsServiceContract` + `OpenAiTtsService`/Fake/Unkonfiguriert (Binding wie STT, D8 direkter HTTP), kurzlebige signierte Audio-Route (Token, TTL 5 Min), `<audio>` im Modal, Zustand „spricht" (Zuhören pausiert), Text ≤ 350 Zeichen, Call-Log `voice.tts`, Einstellungen Stimme + „Antworten vorlesen". Core-Bitte an Martin: `TranscriptionContract` UND `SpeechContract` im Core (Spec 45 §Bitten, ergänzt).
**Mount-Befund (Oskar, verifiziert):** Kein FA-Mount überlebt das Einklappen der Sidebar — `x-ui-sidebar` (Core) rendert den Modul-Slot in `x-if`, die Hülle ist `platform::layouts.app` (Core); kein FA-eigenes Per-User-Setting (Team-Setting + localStorage-Spiegel). **Entscheid:** Mount auf Seitenebene — gemeinsames Partial `agent-mount` im Root-Element jeder FA-Vollseite (Modal + schwebender Knopf), Sidebar behält nur den Öffnen-Knopf. **Core-Bitte an Martin (Dauerlösung, Spec 45 §Bitten ergänzt):** Mount-Punkt in `platform::layouts.app` außerhalb des Sidebar-Slots oder Modul-Slot ohne `x-if`; dazu `TranscriptionContract`/`SpeechContract`. **Stufe 4 Gesprächsgedächtnis (Dominique 22:12 „braucht Memory/Harness"):** serverseitige `VoiceSession` pro Nutzer+Gerät, TTL 30 Min, letzte 6 Züge + offene Vorschläge + geöffnetes Objekt, gekürzt in den Prompt („ja" bestätigt, „das zweite" referenziert); Harness = benannte Zustandsmaschine hört zu/sendet/versteht/führt aus/spricht. Kein Vektor-Gedächtnis (Spec 54).

**(1b) Allgemeiner Schreibvorschlag (Dominique 22:20: „der kann dann auch Rezepte bearbeiten … alles was MCP-fähig ist?"):** Ist gemessen im Haupt-Clone — `darfNutzen()` lässt FA-Tools nur mit `read_only === true` durch (131 Tool-Klassen) plus 5 `PROPOSAL_TOOLS`; 332 schreibende Tools sind unerreichbar, Rezept-Bearbeitung per Stimme geht nicht. Ein Modus-Schalter für zwei Planungs-Knöpfe wäre zu wenig. **Entscheid:** Der Modus steuert alle schreibenden FA-Tools über ein generisches Proposal `schreibaktion` (Tool, Args, Zielobjekt, Feld-Diff alt/neu aus vorherigem GET); Ausführung erst per Klick im Modal (später per „ja" mit Stufe 4). `auto_sicher` = Allowlist rückholbarer Aktionen direkt (Planung starten, Anreicherung, `recipe_klasse.POST`, `recipes.DUPLICATE/RECOMPUTE/ENRICH`); `*.DELETE`, `recipes.REPLACE` in keinem Modus direkt. Schutzregeln: PUT nur mit den genannten Feldern (kein Rückschreiben ganzer Objekte), Mengen mit Zahl/Einheit werden vor Bestätigung wörtlich zurückgelesen. Reihenfolge Paket F: (1) Modus → (1b) Schreibvorschlag → (2) Mount + Schalter → (3) TTS → (4) Gedächtnis.

**Stand F(1) 2026-09-17 22:40 — Commit `50dcc791` (Oskar, `feat/voice-agent-modus`, lokal):** Team-Setting `voice_agent_mode` (Migration mit hasColumn-Guard, `TeamSettingsService::voiceAgentModus()`, `team_settings.PUT`-Enum), Radio-Gruppe in Einstellungen → KI, Modus-Pill im Modal, `AUTO_ERLAUBT = [planung_start, anreicherung, speisen_klasse]`. **Gefundener Bug (per Test):** eine reine Policy-Sperre sperrt nichts, weil `AiGatewayService::callWithTools` alle Katalog-Tools vorab als erlaubt setzt — in `nur_lesen` müssen Vorschlags-Tools aus dem Katalog raus, Policy bleibt zweite Sicherung. **Review-Befunde (cooking-jarvis-03), Fix vor (1b):** (a) `planung_kaskade.LETZTE` (read_only, Status-Lesewerkzeug) darf in `nur_lesen` nicht mit den Vorschlags-Tools verschwinden → eigene Konstante `SCHREIB_VORSCHLAG_TOOLS`; (b) `VoiceModal::$agentModus` ist öffentliche Livewire-Property und steuert die Direktausführung → `#[Locked]` + Modus zur Laufzeit aus dem Team-Setting lesen. **Beide gefixt in `8b529d16`** (Konstante `SCHREIB_VORSCHLAG_TOOLS`, `#[Locked]` + `agentModusAktuell()`), abgenommen.

**Stand F(1b) 2026-09-17 23:05 — Commit `7094e0bc` (Oskar), abgenommen:** `AiGatewayService::callWithTools` bekommt den additiven Hook `intercept` (Default null, nach `arg_guard`, vor `execute`). Policy in `fragen`/`auto_sicher` = FA-Namespace; der Interceptor fängt jedes Tool ohne `read_only === true` (fail-closed) ab und liefert dem Modell „Vorschlag angelegt" als Erfolg (kein Zweitversuch, SET_ACTIVE-Muster vermieden). Karte `schreibaktion` {tool, arguments, objekt, vorschau}; hochwertige Vorschau (GET-Vorher + Feld-Diff) nur über explizite Alias-Map (`recipes.PUT` recipe_id flach, `gps.PUT` id flach, `verkaufsrezepte.PUT` id + `felder`-Wrapper, `recipes.DELETE`/`gps.DELETE` Objektname Pflicht) — alle anderen Schreib-Tools zeigen rohe Argumente ohne Alt-Wert + Tool-Beschreibung (Befund Oskar: 66 PUT + 68 POST ohne einheitliche Form, naiver Diff würde lügen). `AUTO_SICHER_DIREKT_TOOLS = [recipes.DUPLICATE, RECOMPUTE, ENRICH]`. Ausführung erst in `VoiceModal::schreibaktionAusfuehren()` mit frischem ToolContext; Commit-Flags nur dort wieder `true`; Audit `voice.schreibaktion` im Call-Log (getestet). Erreichbarkeit: vorher 116 Tools, jetzt in fragen/auto_sicher jedes FA-Tool (Schreiben = Karte), `nur_lesen` unverändert read_only + LETZTE.

## Paket G — Sofort-Feedback für KI-Knöpfe in allen Editoren (Paul, 2026-09-17 22:50)

Anlass: Dominique drückt im Rezept-Editor (Stammdaten) „Name putzen/Kategorie/Fertigung/Eigenschaften" — keine Reaktion sichtbar. Paket C löste das nur in der Planung. **Gemessen main 7f886d4c:** ~85 `sparkles`-KI-Knöpfe in 25 Blade-Dateien, 0 nutzen `<x-foodalchemist::ki-action>` außerhalb der Planung; Spitzenreiter `recipes/recipe-modal` 14, `verkauf/vk-modal` 13, `gps/detail-panel` 9, `gps/gp-modal` 6, `verkauf/detail-panel` 6 (0 wire:loading). Auftrag: Inventar je Knopf (sync vs. Job), synchrone Knöpfe auf die Komponente, Job-Knöpfe „eingereiht" + Poll nur solange etwas läuft, Reihenfolge recipe-modal → vk-modal → gps → verkauf → Rest, Root-Elemente der Vollseiten nicht anfassen (Oskar mountet dort). **Nachtrag (Dominique 22:55):** je Knopf Prompt-Key + Wissensversorgung prüfen — Paul ermittelt Keys code-seitig, Lisa prüft live per `knowledge.PREVIEW` (Befund versorgt/unversorgt/falsch versorgt), Steuerdaten-Änderungen nur gebündelt nach Freigabe. Brief: `00_INBOX/_Spec53_Leitstelle/Paul_Paket_G_KI_Knoepfe_Editoren.md`. Merge-Reihenfolge: Peter (`feat/job-phasen-anzeige`) → Paul (`feat/ki-feedback-editoren`) → Oskar (`feat/voice-agent-modus`).

### G.1 Wissens-Audit Rezept-Editor (Lisa, 2026-09-17 23:20, live demo Team 6, nur lesen)

12 Prompt-Keys der Rezept-Editor-Knöpfe per `knowledge_routings.GET` + Kanon + `knowledge.PREVIEW` (Tomatensuppe UND Kontrollbrief Rinderfilet/Püree/Jus) vermessen:

| Key | Befund | Maßnahme (Bündel, Freigabe cooking-jarvis-03) |
|---|---|---|
| `recipe.description` | versorgt (Kanon exakt §8) | — |
| `recipe.ueberarbeiten` | versorgt (13 Kanon-Dossiers) | Dossier `regelwerk-basisrezepte-4-…` über Deckel → Split (276-Builder) |
| `recipe.garverlust` | versorgt (Kanon §6 Mengen/Yield, 4.013 Z., 0 dropped) | — |
| `recipe.name_putzen` | halb: Kanon nur §1, GP-Regelwerk §6 fehlt, 0 Routing | Kanon pflicht + GP §6 |
| `recipe.geschmack` | **unversorgt**: Routing `none`, kein Kanon, im Code KEIN Ersatzkanal (RecipeModal:689 übergibt keine `$wissenOpts`; Pairing-Panel nur Blade) | Code-Fix `$wissenOpts` (Paul) + Kanon wenn_platz Geschmacksbalance/Aromen |
| `recipe.sensorik` | kein Befund: `SensorikService::groundingKontext()` erdet Salzig/Süß/Fettig aus LA-Nährwerten („gemessen schlägt KI") | Routing → `none` (Kosten-Hygiene) |
| `recipe.category` | falsch: Domain-Rauschen zur Hauptzutat; Ziel-Dossier `regelwerk_verkaufsgerichte--2-klassifikation-modell-a` existiert aktiv, ungebunden | Routing `none` + Kanon pflicht |
| `recipe.equipment` | falsch: Domain-Rauschen; 4 `geraete_cookbook--*` aktiv, tauchen nie auf | Routing `none` + Kanon wenn_platz |
| `recipe.production_depth` | falsch: 1/3 relevant (Behälter), Rest Zufall | Routing → `produktion_kapazitat` + Kanon |
| `recipe.eigenschaften` | falsch: `regelwerk`-Routing-Zeile liefert strukturell 0 (Sonderkategorie läuft leer) | Zeile streichen, Kanon wenn_platz Spec-51-Dossier + Behälter-Datenwerk |
| `recipe.dichteklasse` | falsch: bester Treffer (Dichte-Datenwerk, semantic_rank 1–3, lexical null) knapp über Budget 16.200 verworfen — RRF-Deckel 1/61 für Einzel-Verfahren-Treffer (bekannt, Spec 52 Etappe E) | Kanon pflicht Dichte-Datenwerk (entzieht es der Rang-Konkurrenz) |

**Strukturbefund:** 5 von 12 Keys nutzen dieselbe ungefilterte `category=""`-Discovery-Zeile und ziehen austauschbar Domain-Wissen zur Hauptzutat (Suppe → Suppen-Systematik, Rind → Jus/Kerntemperatur/Portionen), egal was der Knopf fachlich fragt. Kein neuer Content nötig — alle Ziel-Dossiers existieren aktiv, es fehlen Kanon-Bindungen. Regel daraus: **Struktur-/Referenz-Keys bekommen Kanon, nicht Discovery; Discovery nur für inhaltsabhängiges Wissen.**

**Bündel angewendet (2026-09-17 23:20–23:45, demo Team 6):** Entscheide cooking-jarvis-03: VK-Klassifikationsdossier für `recipe.category` nur `wenn_platz` (VK-Seite, Bauart-Regel passt, HG-Modell nicht); `cookchill_regenerieren_transport_kennwerte` NICHT gebunden (Vermutung, nicht Erwartung); bei `recipe.eigenschaften` bestehende `produktion_kapazitat`-Zeile behalten. **Zwei Korpus-Lücken:** Dossier „Basisrezept-Kategorisierung (§1 Kategorie-Diskriminator + Bauart-Regel)" und Dossier „Produktionszeit-Regeln (Topf-Deckel, Standzeit, Personenminuten)" existieren nicht → anlegen (POST inaktiv → Dominique aktiviert). **Befund Werkzeug:** die „category=''"-Zeilen sind ART-Zeilen (`art=fachwissen`); Löschen geht nur per `art`, `category:""` → `VALIDATION_ERROR feature und category sind Pflicht`. **Befund Betrieb:** Lisas Claude-Client (Auto-Modus) blockt jeden `knowledge_routings.PUT` mit `delete:true` („Modify Shared Resources"), Anlege-PUTs gehen durch → Löschungen von cooking-jarvis-03 mit Dominiques Freigabe ausgeführt: `art=fachwissen` gelöscht bei production_depth (Duplikat), category, equipment, sensorik, dichteklasse, eigenschaften (je `{deleted:1}`); die leerlaufende `regelwerk`-Zeile bei eigenschaften blieb (auch hier geblockt, harmlos). Kanon gesetzt: category (VK §2 wenn_platz), equipment (3× geraete_cookbook wenn_platz); Rest (dichteklasse pflicht + produktion_kapazitat-Zeile, eigenschaften Behälter ×2, name_putzen GP §6, geschmack ×3) **von Lisa gesetzt, Server-bestätigt (23:50)**.

**Nachmessung PREVIEW (Tomatensuppe / Rinderfilet):** `category` → nur Kanon VK §2 (3.973 Z., brief-unabhängig); `equipment` → nur Kanon 3× geraete_cookbook (6.918 Z.); `production_depth` → Retrieval jetzt `produktion_kapazitat`-Fachdokumente (Kalibrier-Fälle Jus-Kessel/Anrichten 40 P., Arbeitszeit-Personenminuten, Zeitkennwerte) statt Suppen-/Fleisch-Zufall (10.807/9.907 Z.); `eigenschaften` Kanon Füllgrad + Retrieval Produktion (14.858/17.465); `dichteklasse` Kanon Dichte fix + Retrieval Produktion (13.805/13.474); `name_putzen` Kanon §1.0–1.2 + GP §6 (11.082); `sensorik` leer (Nährwert-Erdung); `geschmack` Kanon 3 (10.664). Verworfen: 0 überall.

**Code-Klärung (cooking-jarvis-03, `AiGatewayService::propose`):** der Kanon wird IMMER im Gateway gewählt (`selectKanon(documentsFor('prompt_key', …))`), unabhängig vom Aufrufer; nur Retrieval/Discovery kommt über `$options['knowledge']` (contextFor) vom Caller. ⇒ Kanon-Bindungen wirken für geschmack und alle VK-/GP-Keys sofort; Discovery-Routings an Keys, deren Call kein `$wissenOpts` übergibt (VkModal::ki(), GpModal), sind Etikett ohne Landebahn, bis der Call angeschlossen ist (Paul, PR 2, nur wo Lisa Discovery fachlich für nötig hält). Nebenbefund Anzeige: ein Kanon-Dossier, das Retrieval bereits gesendet hat, wird per Dedup (`files_used`) im Kanon-Block ausgelassen und erscheint in PREVIEW unter `retrieval` — inhaltlich korrekt (einmal gesendet), Kanal-Etikett diskutabel, keine Aktion.

### G.3 Wissens-Audit VK-/GP-/Verkauf-Editor (Lisa, 2026-09-18 00:05, ~14 Keys)

**Versorgt über Kanon (wirkt trotz totem Routing, weil Kanon aus dem Gateway kommt):** `vk.speisen_klasse` (VK §2), `recipe.level` (3× Niveau-Referenz pflicht), `vk.ueberarbeiten` (12 Kanon, Deckel-Befund §4 wie recipe.ueberarbeiten), `vk.wording` (VK §1 + §1.2a), `gp.allergene` (GP §16), `gp.suggest` (§6/§6.1 pflicht, §5+10/§7+8 wenn_platz), `gp.zaehl_einheiten` (GP §7+8).
**Unversorgt (Kanon leer, Caller ohne `knowledge` → Discovery tot):** `vk.rollen` (→ Kanon pflicht `regelwerk-basisrezepte-12-zutaten-komponenten-reihenfolge`, existiert aktiv), `recipe.sektor` (`Verkauf/DetailPanel::kiEignung` :224 ohne knowledge → Code-Anschluss Paul PR 2, Discovery category=segment ist hier fachlich richtig; Bestand der Kategorie `segment` prüfen), `gp.naehrwerte` (Discovery = Rauschen Kerntemperatur/Innereien/Fisch → Zeile löschen; Lücke „Nährwert-Referenz je GP-Klasse"), `component.replacement_suggest` (`ReplacementSuggestionService:43` ohne knowledge; Discovery liefert gute Substitutions-Dossiers → Code-Anschluss Paul PR 2, kein Kanon — ein Diät-Dossier wäre zu schmal, 9 Domain-Substitutionen existieren).
**Offen:** `vk.kohaerenz`/`vk.teller_heber` (Code-Check: Anker-Graph statt Dossier? dann Discovery löschen), `vk.plating`/`vk.servier_vehikel` (Korpus-Suche; Vehikel = DB-Vokabular → vermutlich none), `vk.regeneration` (Lücke Spec 51, Zeile löschen).
**Korpus-Lücken gesamt (POST inaktiv → Dominique aktiviert):** (1) Basisrezept-Kategorisierung §1 + Bauart-Regel, (2) Produktionszeit-Regeln (Topf-Deckel/Standzeit/Personenminuten), (3) Regeneration & Behälter normativ (Spec 51), (4) Nährwert-Referenz je GP-Klasse (Datenwerk).

**Bündel 2 angewendet (2026-09-18 00:20):** Code-Check Lisa widerlegt die Graph-Hypothese für `vk.kohaerenz`/`vk.teller_heber` — `CoherenceService.php:56/:83` ruft `propose()` mit 2 Positionsargumenten, kein `knowledge`; Judge-Score wird laut Docblock NIE mit `PairingService::cohesion` verrechnet → echte Lücke, Kanon wenn_platz (Foodpairing-Prinzip + Geschmacksbalance bzw. `plating_patterns--1/--11`). `vk.plating` → Kanon wenn_platz `plating_patterns--1/--6` + `gericht_kreation_kreativprozess--5-teller-geschirrwahl`. `vk.servier_vehikel` → kein Dossier (Vehikel = DB-Vokabular), nur Zeile löschen. `recipe.sektor`: Kategorie `segment` hat 8+ aktive Dossiers → Code-Anschluss lohnt. `vk.rollen` Kanon pflicht gesetzt (3.566 Z.). Deletes (cooking-jarvis-03, je `{deleted:1}`, alle `art=fachwissen`): gp.naehrwerte, vk.kohaerenz, vk.teller_heber, vk.plating, vk.servier_vehikel, vk.regeneration. Kanon-PUTs (7, Server-bestätigt) + PREVIEW nachher (Lisa, 00:35): `vk.kohaerenz` 7.201 Z. nur Kanon, `vk.teller_heber` 5.666 Z. nur Kanon, `vk.plating` 8.441 Z. alle 3 unter kanon (Etikett-Fall verschwunden, kein Overlap mehr), `gp.naehrwerte`/`vk.servier_vehikel`/`vk.regeneration` 0/0/0 wie entschieden. Die zwei leerlaufenden `regelwerk`-Zeilen (eigenschaften, dichteklasse) einzeln nachgelöscht (je `{deleted:1}`). **Audit-Bilanz Lisa: 26 Keys, 13 Kanon-Bindungen, 11 Routing-Deletes, 3 Code-Anschlüsse offen (Paul PR 2: recipe.sektor, component.replacement_suggest; optional recipe.geschmack-Discovery), 4 Korpus-Lücken.**

### G.4 Korpus-Lücken geschlossen — Import-Lücke statt Schreibauftrag (cooking-jarvis-03, 2026-09-18 01:00)

Prüfung gegen `knowledge.LIST category=regelwerk` (77 Dossiers): Regelwerk Basisrezepte **§14 Regeneration & Behälter** (Vault v1.10) und Regelwerk Verkaufsgerichte **§3 Anleitungs-Ebenen (inkl. §3.2a, §3.4–3.8) + §4 Darreichung** (Vault v1.8) fehlen im Modul komplett — der Vault-Import hat die Spec-51-Nachzüge vom 2026-09-04 nie übernommen. Lücken 2 (Produktionszeit) und 3 (Regeneration/Behälter) sind damit **keine Schreibaufträge**, sondern Import-Lücken; Lücke 1 (Basisrezept-Kategorisierung) deckt das vorhandene Dossier `regelwerk-basisrezepte-10-12-…--1-2-typ-vokabular-kontrolliert` (Hauptgruppe → Typen-Tabelle) → **als Kanon wenn_platz an `recipe.category` gebunden** (ord 20). Lücke 4 (Nährwert-Referenz) bleibt offen (Datenwerk, braucht Quellen).

**8 Dossiers per `knowledge.POST` angelegt — alle `active=false`, `art=regel`, Vault-konsistente Slugs (Reconciliation beim nächsten Import), ≤ 4.000 Z.:**

| Slug | Zeichen |
|---|---|
| `regelwerk-basisrezepte-14-regeneration-behaelter-am-basisrezept` | 2.701 |
| `regelwerk.regelwerk_verkaufsgerichte--3-anleitungs-ebenen-produktion-regeneration-anrichten` | 3.132 |
| `regelwerk.regelwerk_verkaufsgerichte--3-2a-regeneration-gehoert-der-komponente-fuenf-raenge` | 2.422 |
| `regelwerk.regelwerk_verkaufsgerichte--3-4-behaelter-je-zweck-nicht-servier-vehikel` (+ §3.4d) | 2.179 |
| `regelwerk.regelwerk_verkaufsgerichte--3-4a-c-produktions-groessen-pass-nebenkosten` | 2.265 |
| `regelwerk.regelwerk_verkaufsgerichte--3-4e-behaelter-bemessung-menge-waehlt-groesse-und-anzahl` | 3.495 |
| `regelwerk.regelwerk_verkaufsgerichte--3-5-3-8-temperatur-ausgabe-briefing-schritte-ohne-mengen` | 3.301 |
| `regelwerk.regelwerk_verkaufsgerichte--4-darreichung-unbestimmt-ist-ein-zustand` | 871 |

**Nächster Schritt (Dominique):** Freischalten im Wissens-Browser. Danach Kanon-Bindungen: `vk.regeneration` ← §3.2a + §14 (pflicht); `recipe.eigenschaften` ← §14 (wenn_platz); `vk.plating` ← §3 (wenn_platz, Anrichten-Ebene); `recipe.steps`/`vk.steps` ← §3.8 Schritte ohne Mengen (pflicht, sofern Key existiert); Darreichungs-Keys ← §4. Offen: `regelwerk-basisrezepte-4-…` (4.002 Z.) splitten.

### G.2 Umbau-Stand (Paul, 2026-09-17 23:40, `feat/ki-feedback-editoren` HEAD f740b9ae, 6 Commits)

27 Knöpfe umgestellt, neue Komponenten-Variante `ai` (violette `$btnAi`-Optik bleibt Signal „ruft KI"): recipe-modal 9, vk-modal 5, gps/detail-panel 4, gp-modal 4, verkauf/detail-panel 5; Generator-Modal war bereits korrekt (Beleg-Test Poll Ruhe/aktiv ergänzt). Code-Fix `recipe.geschmack` ohne `$wissenOpts` (d8525b28); VkModal::ki()-Dispatcher und alle GpModal-Calls übergeben UNIFORM kein Wissen → offene fachliche Frage (Lisa prüft, ob Kanon im Gateway auch ohne Caller-Options zieht). Restliste 17 Dateien (~30–40 Knöpfe) + Garverluste ×2 (Alpine-Custom-Merge) → PR 2 `feat/ki-feedback-editoren-2`. Belege: `data-ki-action="namePutzen"` + `wire:target="namePutzen"` (KiFeedbackRecipeModalTest), Poll-Regel (KiFeedbackGeneratorPollTest).

**PR #106 GEMERGT (2026-09-18 01:40, main `9e74b149`):** HEAD df55c9c7 nach Merge von #105, volle Suite sandbox-ek-stk 4.556 Tests / 4.550 grün / 6 skipped / 0 rot (23.100 Assertions, ~75 Min bei geteilter CPU). 27 Knöpfe in 5 Editoren + `recipe.geschmack`-Wissensanschluss (eigener `contextFor` je Key, nicht den Eigenschaften-Block mitgeben — „falsch versorgt" wäre schlimmer als „unversorgt"). PR 2 `feat/ki-feedback-editoren-2` off main: Restliste 17 Dateien + Garverluste ×2 + wissenOpts-Anschlüsse recipe.sektor/component.replacement_suggest.

## Deploy 3 — 2026-09-18 01:10 (Serverzeit 23:09 UTC): main `a0ce150f` auf demo

**PR #107 GEMERGT** (Peter, `feat/job-phasen-anzeige` HEAD 2c93f64d, volle Suite FA_TEST_PROCS=2 unter Load bis 115: 4.555 Tests / 4.549 grün / 6 skipped / 0 rot, ~2 h 45): Phasen für GenerateConceptJob, FanoutConceptJob (+ User-Härtung), GenerateDishProposalJob (+ `failed()`), ReviseDishProposalJob (+ `failed()` → zurück auf `geplant`), Materialize-Pfade Speiseplan-Zelle/Speisekarte-Position/**Concept-Gericht** über `generiere()`-Callback; `laufStatus()` Fallback „eingereiht — wartet auf Worker"; `gibStepFrei()` routet `geplant`-Steps aller Kinds aufs Erzeugen-Gate und liefert Aktions-Tag, MCP FREIGABE zeigt `aktion` + `aktion_hinweis`. Zwei Eloquent-Fallen dokumentiert: Dirty-Checking (Phase nie im selben `update()` mitgeben, stale Model sähe sie als unverändert) und Guard-Reihenfolge (`setzePhase(null)` VOR dem Status-Sprung, sonst No-op).

**Deploy:** Lock-Pin af1e327f → a0ce150f (demo-Commit `6fd71fb`), Forge deployt auf Push, `installed.json` = a0ce150f; `migrate --force --path=…foodalchemist/…`: `2026_09_17_100000_add_voice_agent_mode_to_team_settings` 43 ms DONE, `2026_09_17_110000_add_voice_agent_dauerhaft_aktiv_to_team_settings` 24 ms DONE (Phasen-Spalte war schon in Deploy 2); `queue:restart` + `supervisorctl restart` 5 Gruppen → 7 Worker `started`, ps-Startzeiten 23:09:26–23:09:30; Spaltencheck `voice_agent_mode, voice_agent_dauerhaft_aktiv` vorhanden. Enthält #103, #105, #106, #107.

## Deploys 4 + 5 — 2026-09-18 02:20 / 02:45: F(3) Stimme und G(2) Restliste live

**PR #109 (Oskar, `feat/voice-gespraechsmodus`, HEAD 44aeaa62, Suite 4.584/4.590 grün):** Server: `TtsServiceContract` + `OpenAiTtsService` (gpt-4o-mini-tts, Text ≤ 350 Z., HTTP direkt mit Timeout) + Fake/Unkonfiguriert (Binding = STT-Spiegel), signierte Einmal-Route `/sprachbefehl/audio/{token}` (TTL 5 Min, `Cache::pull`), `VoiceModal::sprechen()/sprichWennAktiviert()/sprechenBeendet()`, Vorlesen unterdrückt bei Navigation, TTS-Fehler blockiert nie die Textantwort (Event → speechSynthesis-Fallback), Call-Log `voice.tts`, Settings `voice_tts_vorlesen` (Default AUS) + `voice_tts_stimme` (6 Stimmen). Browser: Autoplay-Entsperrung per deterministischem 60-Byte-Silent-WAV in der Öffnen-Geste auf persistentem `<audio data-voice-tts>`, VAD mit Hysterese (0.08/0.04, Stille ≥ 700 ms NACH Sprache, Min 700 ms / Max 20 s) nur im Konversationsmodus, Zuhören pausiert während `sprichtGerade`, 5 `data-voice-status`-Werte, Event-Namen als Konstanten (Test: kein zweites Literal). Eigener Testfehler gefunden: Navigate-Test mit `route` statt `route_key` → Guard griff nie. Browser-Verhalten (Autoplay/VAD/Safari) NICHT selbst geprüft → 5-Punkte-Testanleitung für Dominique im PR-Body. Deploy 4: Pin 3d8e4b23 (demo `7c287fe`), Migration `2026_09_18_100000_add_voice_tts_settings_to_team_settings` 56 ms DONE, Worker neu 00:22 UTC, Spalten `voice_agent_mode, voice_agent_dauerhaft_aktiv, voice_tts_vorlesen, voice_tts_stimme`.

**PR #110 (Paul, `feat/ki-feedback-editoren-2`, Suite 4.604/4.598 grün, 0 rot):** 14 Dateien konvertiert (step-editor 2, angebote/editor 3, concepter/editor 1 — Rest dort explizit „Ohne KI", review-queue + _signal-row 2, foodbook-kontext-rail + kapitel-rail 2, speisekarte/index 2, recipes/browser 1, foodbooks/index 3, suppliers/item-modal 1, concepts/index 1, Garverluste 2), 3 bestätigt ohne Fund (vk-generator-modal Poll-Trait korrekt; signale/detail-panel Knopf lebt in _signal-row; gps/browser + dashboard + produktion/tagesplan ohne propose()-Call), 2 wissenOpts-Anschlüsse (`recipe.sektor` in `Verkauf/DetailPanel::kiEignung`, `component.replacement_suggest` in `ReplacementSuggestionService`). **Garverluste-Ausnahme dokumentiert:** Knopf dispatcht Fenster-Event in den eingebetteten ingredient-editor (anderer Alpine-Scope, Client-Merge in Zutatenzeilen) → dort try/catch + `garverluste-fertig/-fehler`-Events, Knopf mit eigenem pending/ok/err-Zustand, visuell identisch zur Komponente. **Paket G komplett: 54 Knöpfe in 19 Dateien, 3 Wissens-Anschlüsse.** Deploy 5: Pin 9fb29464 (demo `61b7c96`), keine Migration.

**Offen nach Deploy 5:** Oskar F(4) Gesprächsgedächtnis (`feat/voice-gedaechtnis` off 3d8e4b23); Dominique: Browser-Abnahme (Planung Phasen · Einstellungen→KI Agenten-Modus/Stimme · Rezept-Editor-Knöpfe · Voice Chrome+Safari mit TTS/VAD) und 8 Regelwerk-Dossiers aktivieren; danach Kanon-Bindungen §14/§3/§4 und Nachmessung `recipe.sektor`/`component.replacement_suggest` (Lisa).

## Deploy 6 — 2026-09-18 03:10: F(4) Gesprächsgedächtnis live, Paket F komplett

**PR #112 (Oskar, `feat/voice-gedaechtnis` off 3d8e4b23, HEAD b6eeb543, Suite 4.630/4.636 grün, 0 rot):** `VoiceSessionService` (Cache-Sitzung Team + User + Laravel-Session-ID = pro Browser/Gerät ohne neuen Identifikator, TTL 30 Min, letzte 6 Züge à ≤ 400 Z., offene Vorschläge, zuletzt geöffnetes Objekt; `promptKontext()` als gekürzter Verlauf im nächsten Prompt), `VoiceReferenzResolver` (Zahl/„Nummer 2", Ordinale nur bei Äußerung ≤ 30 Z., Zustimmung „ja/ok/passt/mach das" NUR exakt und NUR bei genau einem offenen Vorschlag — sonst kein Rateversuch, normaler Tool-Loop), Ausführung über dieselben Methoden wie der Bestätigen-Klick (GL-07 unverändert), `VoiceModal::oeffnen()` restauriert offene Vorschläge aus der Sitzung (die eigentliche Lücke seit dem Seiten-Mount: neues Modal pro Seite warf sie weg), „Gespräch vergessen"-Knopf. `VoiceCommandService::verarbeite()` nur additiv um `verlauf` erweitert; Event-/Zustandsmodell aus (3) unangetastet. Test prüft die DB (nur Lachs-GP angelegt, Zander nicht) statt nur das accepted-Flag; Sitzung übersteht simulierte Navigation. Deploy 6: Pin 6217eec6 (demo `252861c`), keine Migration.

**Paket F Bilanz (Stufen 1–4, PRs #105/#109/#112):** Agenten-Modus fragen|auto_sicher|nur_lesen · generischer Schreibvorschlag für 332 Schreib-Tools · Seiten-Mount 26 Vollseiten + „dauerhaft aktiv" · OpenAI-TTS + VAD-Konversationsmodus · Gesprächsgedächtnis 30 Min. Offen: Browser-Abnahme Dominique (Chrome + Safari), Core-Bitten an Martin (Mount außerhalb Sidebar-Slot, TranscriptionContract/SpeechContract) → Spec 45 §Bitten.

## G.5 Regelwerk-Dossiers aktiviert und gebunden (2026-09-18 11:30)

Dominique hat die 8 Dossiers (Basisrezepte §14, VK §3/§3.2a/§3.4/§3.4a–c/§3.4e/§3.5–3.8/§4) im Wissens-Browser freigeschaltet (Version 2). Kanon-Bindungen (cooking-jarvis-03, Server je „Gesetzt — wirkt sofort"):

| Key | Dossier | Modus |
|---|---|---|
| `vk.regeneration` | §3.2a Regeneration gehört der Komponente · §14 Regeneration & Behälter am Basisrezept | pflicht · pflicht |
| `recipe.regeneration` | §14 · §3.2a | pflicht · wenn_platz |
| `recipe.eigenschaften` | §14 | wenn_platz (ord 30, nach Behälter ×2) |
| `vk.plating` | §3 Anleitungs-Ebenen · §3.5–3.8 Schritte ohne absolute Mengen | wenn_platz · pflicht |
| `recipe.steps` | §3.5–3.8 · §3 Anleitungs-Ebenen | pflicht · wenn_platz |
| `signal.serving_form_suggest` | §4 Darreichung `unbestimmt` | wenn_platz |

Deploy 7 (Pin 39a13c46): #114 Kacheltext „KI" in den Einstellungen nennt den Sprachbefehl (Live-Befund Dominique: Einstellungen nicht gefunden). Live-Check Team 6: alle drei Voice-Schalter standen noch auf aus → Modal sah unverändert aus (Default = Ein-Klick-Modus); Befehl „Planung öffnen" lief 16,8 s, 2 Runden, 1 Tool, ohne Fehler.

## Browser-Abnahme Dominique (Chrome) — Befunde und Deploy 8 (2026-09-18 12:00)

Nach Einschalten von auto_sicher + dauerhaft aktiv + Vorlesen (Stimme echo): **TTS kam an.** Drei Befunde, alle im schwebenden Mikrofon, ein Hotfix-PR **#116** (Oskar, `fix/voice-modal-stale-settings`, HEAD 18d328f7):
1. **Stale Werte:** `VoiceModal::mount()` las Modus + Konversation nur beim Seitenaufbau; auf der Einstellungsseite umgestellt → Modal derselben Seite blieb alt („Modus: Fragen", Ein-Klick). Fix: `oeffnen()` liest beides frisch.
2. **Zwei Klicks:** Klick auf den schwebenden Knopf öffnete das Modal im Ein-Klick-Zustand, erst der zweite Klick nahm auf. Fix: Öffnen-Knöpfe senden `autostart: true`; nativer Listener im Recorder startet noch in der Nutzer-Geste (Safari-AudioContext-Regel), wenn `konversationAktiv`; `vad` ist jetzt Property (live nachziehbar über den `$wire.konversationAktiv`-Watcher).
3. **Knopf verschwand in Editoren** (Drag funktionierte): gemessen — alle Editoren UND das Sprachbefehl-Modal teilen `components/modal.blade.php` (z-[100] + globaler `bringToFront`-Zähler = zuletzt geöffnet gewinnt), der Knopf lag fix bei z-[90]. Fix: additive Prop `zFest` (Default false, alle anderen Modale unverändert) pinnt das Sprachbefehl-Modal fest auf z-[190] (reines bringToFront hätte nicht gereicht — nächster Editor überdeckt wieder), Knopf z-[210] über Modal und Speichern-Toast (z-[200]). Tests: 148 gezielt + 902 Breiten-Spot-Check über alle Modal-Konsumenten (Blast-Radius der geteilten Komponente). Deploy 8: Pin bf279202 (demo `8cbcd35`), keine Migration.

**Bruch nach Deploy 8 (12:20) und Deploys 9 + 10:** Das Sprachbefehl-Modal zeigte seinen Alpine-Code als Klartext, der schwebende Knopf war komplett weg. Ursache: JS-Kommentare INNERHALB von `x-data="{…}"` mit doppelten Anführungszeichen in ZWEI Dateien — `voice-modal.blade.php` (4 Zeilen: „wire.konversationAktiv“ ×2, „zwei Klicks statt einem“, „Aufnahme starten“) und `partials/agent-mount.blade.php` (1 Zeile: „Aufnahme starten“). Das Attribut endet beim ersten inneren `"`, Alpine fällt für die Komponente still aus. 148 gezielte + 902 Breiten-Tests waren grün — `Livewire::test()->html()` prüft kein Attribut-Parsing, `npm run build` sieht das Blade nicht. Fix mechanisch durch cooking-jarvis-03 (Backticks/Guillemets), Verifikation per Skript (Attribut endet mit `}`), PRs **#118** (Modal, Deploy 9 Pin ef410901) und **#119** (agent-mount, Deploy 10 Pin 90b2cea0); Skript-Scan über alle Voice-Blades fand keine dritte Stelle. Wächter-Test (jedes `x-data`-Attribut bis zum ersten `"` muss mit `}`/`)` enden, alle FA-Blades) → Oskar, Folge-PR. Memory: [[feedback_attribut_js_anfuehrungszeichen]].

**Zweite Testrunde Konversationsmodus (12:40) — zwei Bruchstellen, PR #120 (Oskar, volle Suite 4.675/4.681 grün), Deploy 11 Pin 9f47e6d1:**
1. **Interceptor fing die Tool-Suche selbst.** `tool_registry.SEARCH` hat kein FA-`read_only`-Metadatum → fail-closed als Schreibvorschlag abgefangen → Karten „SEARCH: tool_registry.SEARCH · Bestätigen", 0 Tool-Aufrufe, „Öffne die Seite mit Gerichten" navigierte nicht. Fix: Modulgrenze `str_starts_with($name, 'foodalchemist.')` als ERSTE Prüfung; ui.NAVIGATE/ui.OPEN gemessen read_only=true. Die „0 Runde(n) · 0 Tool · 0 ms"-Zeile war die Sitzungs-Wiederherstellung (F4) offener Garbage-Proposals, kein eigener Fehler.
2. **Endlos-Schleife, nicht stoppbar.** Antwort → TTS → Auto-Weiterhören → 20 s Stille → Upload → „Alles klar, ich warte …" → TTS → … alle 30 s; Doppelklick nötig, kein Stopp. Sofortmaßnahme: `voice_agent_dauerhaft_aktiv` für Team 6 per MCP aus. Fix: (a) VAD „keine Sprache" → kein Upload, Zustand „wartet", kein Wiedereinstieg; (b) Stopp überall — Klick auf den schwebenden Knopf während hört zu/sendet/spricht = Stopp (globale Brücke `FaVoiceStopAlles`, weil agent-mount den Modal-Scope nicht erreicht), Stopp-Knopf im Modal, ESC, `audio.pause()` + `speechSynthesis.cancel()`; (c) Deckel: 3 automatische Zyklen (jeder, nicht nur stille) → Pause „Zum Weiterhören klicken", Reset nur per Klick; (d) Systemprompt: Rauschen → nur „wartet", wird nicht vorgelesen.
3. **Wächter-Test `BladeXDataAttributeGuardTest`** (modulweit, Symfony-Finder): jedes `x-data`/`x-init` syntaktisch vollständig — fand beim ersten Lauf ein frisches `"` in Oskars eigenen neuen Kommentaren, bevor es committet war. Datei-Kopf-Regel in beiden Blades.

**Dritte Testrunde (13:30) — drei Ursachen gemessen, PR #122 (Oskar, volle Suite 4.686/4.692 grün), Deploy 12 Pin 9f9c81b8:**
1. **TTS scheiterte live bei jedem Aufruf** (Call-Log `voice.tts` „fehler", error NULL, 2,2 s; Stimme kam nur über speechSynthesis). `synthesize()` in tinker ok (64.128 Bytes), Route + signierte URL ok, `Cache::put` mit String ok — **mit Binärdaten `SQLSTATE 1366 Incorrect string value … column 'value'`**: Cache-Treiber auf demo ist `database` (utf8mb4-Textspalte), base64 geht durch. Fix: base64 im Cache, Decode im Controller. Zweiter Fund: `catch (\Throwable)` OHNE Variable — die Meldung war nicht nur ungenutzt, sondern weg; jetzt in der `error`-Spalte (≤ 2.000 Z.).
2. **„Öffne die Seite der Basisrezepte" navigierte nicht**: 3 Runden, 3 Tools, final=false, 29,8 s (Budget 28 s), `freigeschaltet: ui.ROUTES` — Katalog (27 route_keys in `FoodAlchemistTool::uiRouteCatalog()`) stand nicht im Warmstart, der Agent musste SEARCH → ROUTES → NAVIGATE laufen. „Öffne die Planung" hatte nur funktioniert, weil das Modell den Key riet. Fix: 27 Kurzlabel direkt im NAVIGATE-Schema, `ui.ROUTES` als Rückfall im Katalog; Token-Deckel 10.047 → 10.970, Ceiling 11.200.
3. **Konversationsmodus ohne Modal (Spec-54-Vorstufe):** Knopf = Anzeige (pulsierend/Wellen/ruhig/rot), Sprechblase mit Transkript + Antwort (≤ 2 Zeilen, verschwindet nach dem Vorlesen) als drittes Element im agent-mount, Modal nur bei Vorschlagskarten oder Blase-Klick; Ein-Klick-Modus unverändert. Wächter-Test fing beim Bau erneut zwei `"` (schließende Seite von »Guillemets«).

**Vierte Testrunde (14:10) — Knopf rot ohne Fehler, PR #124, Deploy 13 Pin 1506027d:** Server grün (voice.command 2× final=true; `voice.tts` „openai — synthetisiert" 14:05 = erste echte Server-Stimme nach dem base64-Fix). Rot kam aus `_schwebeStatus()`: `keineSpracheErkannt || konversationPausiert || fehler → 'fehler'` — Pause nach 3 Auto-Zyklen und „keine Sprache" sind keine Fehler. Fix: fünfter Zustand `pausiert` (amber, kein Pulsieren), `fehler` nur bei echtem Recorder-/Upload-Fehler, `title`/`aria-label` nennen den Grund; Test prüft, dass beide Bedingungen nicht mehr in einem `if` stehen. Wächter-Test fing zum vierten Mal ein gerades `"` vor dem Commit — Oskar: „der Test ist die verlässlichere Absicherung als meine Aufmerksamkeit".

## Paket H — Zutaten-Dossiers: Dedup + Grounding je Aspekt (Lisa, 2026-09-18 14:30)

**Anlass (Dominique, Analyse aus Parallel-Chat):** die ~2.600 neuen Zutaten-Dossiers (Kategorie `zutat`, Slugs `zutat.<anker>--steckbrief|verwendung|verhalten|cave`) laufen durch Discovery/RRF (k=60): eine Zutat belegt vier Plätze (gemessen `passion_fruit`: alle 4 Aspekte semantisch Rang 1–4), zweite Zutat und Technik-Wissen fallen raus (`frucht-gelees-praxis…` lexikalisch Rang 1, gedroppt wegen semantisch Rang 18). Scores liegen bei k=60 über 18 Plätze nur 12 % auseinander → Schnitt faktisch willkürlich.

**Bewertung:** (1) k senken — NEIN, Spec 52 Etappe E gemessen: Einzel-Verfahren-Treffer verlieren konstruktionsbedingt, Symptomlinderung mit Nebenwirkung auf alle Kanäle. (2) Gruppen-Dedup je Entität (Slug-Präfix vor `--`) — JA, sofort, generisch. (3) Entitätsauflösung vor dem Retrieval — JA, und der Mechanismus existiert: Routing-Modus `grounding` + `KnowledgeContextService::groundingBlock()` (deterministischer Score 1.0, budgetfest) bedient heute nur Pairing-Dokus und bekommt `hauptzutatSlugs` nur von Pairing-Features. Anschluss: Generator-Grounding (GP) → Anker-Slug → genau EIN `zutat.<anker>--<aspekt>` je Zutat, Aspekt je Prompt-Key (generator → verwendung, steps/plating → verhalten, review/conformance → cave, gp.* → steckbrief) + geschützte Kontingente Zutat/Technik/Referenz. Ohne Code (Kategorie je Aspekt) = Umbau von 2.600 Dossiers für drei Codestellen — verworfen.

**Plan:** Lisa — Schritt 1 Golden-Messung PREVIEW („Passionsfrucht-Gelee mit Acerola, 40 Portionen", recipe.generator + recipe.steps, Ist/Soll), Schritt 2 Bauplan (A Dedup → B Grounding je Aspekt → C Kontingente) zur Freigabe, dann Branch `feat/wissen-zutaten-grounding`. Schnittstelle zum Generator (Liste Anker-Slugs) → Paul. Routing `zutat` → `grounding` erst nach Code-Deploy.

**Golden-Messung VORHER (Lisa, 14:45, „Passionsfrucht-Gelee mit Acerola, 40 Portionen"):** `recipe.generator` (Budget 80.000): gesendet `zutat.acerola_14--{steckbrief,verwendung,cave,verhalten}` (alle 4 der NEBENZUTAT) + `zutat.passion_fruit--{steckbrief,verwendung}` + 3 Referenz-Rezepte; gedroppt `passion_fruit--cave` und `frucht-gelees-praxis-dosierungen-…` (lexikalisch Rang 1, semantisch 12), dropped_chars 7.150. `recipe.steps` (Budget 17.200): gesendet NUR `acerola_14--{steckbrief,verwendung}` + 1 generisches Tropenfrüchte-Doc; gedroppt ALLE 4 `passion_fruit--*`, `acerola--cave/--verhalten`, BEIDE Gelee-Technik-Dossiers, 1 Regelwerk-Zeile — dropped_chars 33.104 (> doppelt so viel wie gesendet). **Die namensgebende Hauptzutat ist in den Produktionsschritten nicht vertreten.**

**Architektur-Entscheide (cooking-jarvis-03):** A1 Dedup aspektbewusst — `search()` bekommt `?string $preferredAspect` (roher String, kein Prompt-Key-Wissen in der generischen Suche), Aufrufer löst `ASPEKT_JE_PROMPT_KEY` auf; exakter Aspekt gewinnt unabhängig vom Score, sonst bester + `aspekt_fallback: true`. A = generischer Schutz für jede `--`-Aspekt-Familie, B = primäre Lösung für `zutat` — bewusst zwei Mechanismen mit verschiedenen Rollen. B1 Anker-Auflösung über vorhandenes `PairingService::neighborsForName()` (Wrapper `ankerSlugFuer()`; Slugs mit Zahl-Suffix wie `acerola_14` per Präfix-Lookup). **B4 = Option (b):** leichte Vor-Sondierung über `GenerationContextService::leitTokens()` VOR `contextFor()` statt Reihenfolge-Tausch mit `forGeneration()` (Risiko `$erdungsText`); `contextFor()` wird heute (Z. 100) vor `forGeneration()` (Z. 176) gerufen, die GPs existieren dort noch nicht. **C1 = (i):** Kandidatenzahl je Kanal deckeln, Nachher-Messung, echte Budget-Isolation (ii) nur bei Bedarf als eigener PR. D1 Golden-Test mit exakt diesem Brief. Branch `feat/wissen-zutaten-grounding`.

Frage Dominique „bekommt vk.regeneration trotz Kanon noch Discovery?" → Nein: Routing-Zeile gelöscht (search-only) UND VK-Modal-Caller ohne `knowledge`-Option. Fachliche Option offen: produktabhängige Kennwerte (Kerntemperatur, Cook & Chill) via gezielter Zeile (kueche/produktion_kapazitat, max_docs 2) + Caller-Anschluss durch Paul — nur auf Zuruf.

**Stand F(2) 2026-09-17 23:35 — Commit `57550549` (Oskar):** Partial `partials/agent-mount.blade.php` (Modal + schwebendes, ziehbares Mikrofon bei Team-Setting „dauerhaft aktiv", Position in localStorage) im Root von 26 Vollseiten, Sidebar nur Öffnen-Knopf; Migration `voice_agent_dauerhaft_aktiv`. Review: kein Script als erstes Tag, keine Verschachtelung der 26 (keine Doppel-Modals). **Loch:** auf FA-Seiten ohne Include tut der Sidebar-Knopf nichts mehr (Editor-Vollseiten fehlen vermutlich) → Routen-Abdeckung messen (routes/web.php → Komponente → Include ja/nein), fehlende nachziehen, Test „genau 1× `data-voice-float-mount` je geroutete Vollseite". Dann volle Suite → **PR 1 = F(1)+(1b)+(2)**; (3) TTS/VAD + (4) Gedächtnis auf `feat/voice-gespraechsmodus` off PR-1-HEAD.

**PR #105 GEMERGT (2026-09-18 01:15, main `2a3c5355`):** F(1)+(1b)+(2), HEAD 28a828ae, volle Suite sandbox-voice 4.553/4.559 grün, 6 skipped, 0 rot (23.132 Assertions). Mein vermutetes Loch (Editor-Vollseiten ohne Mount) **gemessen widerlegt**: `Route::getRoutes()` → FA-Livewire-Ziele = exakt 26 Klassen; alle Editoren/Panels/Modals sind `<livewire:>`-genestet in diese 26 (Presentation-Routen sind Controller mit eigenem Layout, bewusst ohne Agent); `KundeDnaPanel`/`LeitstelleRail` toter Code ohne Mount. Neuer Test rendert alle 26 live und zählt `data-voice-float-mount` (Riegel `toHaveCount(26)`). PR-Zahlen: 461 FA-Tools, 129 read_only (nur_lesen), 332 Schreib-Tools (Karte), 5 Alias-Map, 3 AUTO_SICHER_DIREKT_TOOLS, 3 AUTO_ERLAUBT. Merge vor Peter/Paul, weil keine Datei-Überlappung; beide rebasen vor PR. (3) läuft auf `feat/voice-gespraechsmodus` off main.

## Deploys 14 + 15 — 2026-09-18 16:55 / 17:50 (cooking-jarvis-03)

- **Deploy 14, Pin `b1ce9e55` = PR #128 (Oskar, Spec 54 Latenz):** frühes Finale bei `ui.NAVIGATE` (1 LLM-Runde statt 2), `with_context:false` + `tools:false` jetzt auch in `callWithTools()` (Core hängte je Runde Persona + `Zeit:`-Cache-Killer + plattformweite Tools-Übersicht an; FA-eigener Prompt ≈ 4.979 Token), Zeitbudget 28 → 45 s, Sprechblase „Denkt nach …", Config `foodalchemist.ai.voice_model` (env `FOODALCHEMIST_AI_VOICE_MODEL`, Default null; auf demo NICHT gesetzt — Entscheid Dominique: erst ohne Modellwechsel messen; Tier D nicht anfassen, hängt an demo.echo/gp.condition/recipe.category/recipe.name_putzen). Suite 4.697/4.703 (6 skipped) auf dem finalen Commit; ein Vorlauf hatte 2 Stale-Worker-Rote (Datei-Edits während laufender Suite).
- **Entscheid Dominique 17:10:** Schwebender Agent auf allen Seiten „bringt nichts" → `voice_agent_dauerhaft_aktiv` Team 6 = false (per MCP). Agent zieht in die Planung um → **Spec 55** (Oskar, `feat/agent-in-planung`): Rückbau agent-mount (26 Seiten) + Sprachbefehl-Knopf, Panel in der Leitstelle, Brief-Dialog mit Nachfragen, Leitstellen-Zustand als Kontext, Wissen mit Quelle, Übernehmen-Karte schreibt in Brief/Leitplanken der Komponente (kein neues Tool), neuer Setting-Key `voice_agent_panel_planung` (null = an). Spec-54-Nachher-Messung damit obsolet.
- **Deploy 15, Pin `dd774445` = PR #129 (Lisa, Paket H):** Zutaten-Dossiers per Anker-Slug + Aspekt je Prompt-Key (`zutatAspektFuer`: generator/vk.generator → verwendung · steps/plating → verhalten · review/conformance → cave · gp.* → steckbrief), Teil-Dossiers je Aspekt (`--verhalten-hitze`), Regex `^zutat\.<anker>(_\d+)?--<aspekt>(-…)?$`, Gruppen-Dedup NUR für `zutat.`-Slugs (Review-Fix: der erste Stand hätte alle `--`-Familien aller Kategorien kollabiert), `preferredAspect` im Discovery-Pfad (Kategorie-Loop + `art=fachwissen`-Auffangnetz). Suite 4.704/4.710 (6 skipped), Review-Fixes gezielt 74/74.
- **Steuerdaten (ich):** Routing `zutat` → `grounding`, max_docs 8, für recipe.generator / recipe.steps / vk.generator. Budgets: recipe.generator 80.000 → **100.000**, vk.generator 65.000 → **100.000**, recipe.steps 40.000 → **50.000**.
- **Live-Messung PREVIEW „Passionsfrucht-Gelee mit Acerola, 40 Portionen":**

| Key | Zutaten-Dossiers | dropped vorher → nachher | Bemerkung |
|---|---|---|---|
| recipe.generator | `zutat.acerola_14--verwendung`, `zutat.passion_fruit--verwendung` | 3.906 Z. (Fruchtganache) → bei 100k zu prüfen | Aspekt-Wahl greift (via Discovery) |
| recipe.steps | `…--verhalten` ×2 | 9.748 → **0** (Kanon §3 wenn_platz wieder drin) | Budget 50k |
| vk.generator (Business-Lunch Buffet) | `…--verwendung` ×2 + `event_playbook_business_lunch` | 16.036 → **0** (`buffet_display_praesentation_kennwerte` drin) | Kontext-Kanal wirkt |

- **Offener Befund (→ Lisa, `fix/zutat-grounding-fallback`):** `via: zutat_grounding` erscheint nirgends — von allen `contextFor()`-Aufrufern übergibt NUR `RecipeGenerationContextService::build()` Hauptzutat-Slugs; PREVIEW, `RecipeOneShotService` (recipe.steps), `StepEditor`, `DetailPanel`, Review, Conformance geben `[]`. Fix: in `contextFor()` Slugs aus der Beschreibung ableiten (`leitTokens`), wenn der Aufrufer keine liefert und eine `zutat:grounding`-Zeile existiert; Herkunft `hauptzutaten_quelle`.
- **Korpus-Stand demo:** nur 16 `zutat`-Dossiers (8 aktiv: acerola_14 + passion_fruit × 4 Aspekte im 4er-Schema; 8 inaktiv im alten 6er-Schema steckbrief/sensorik/handwerk/cave/wirtschaft/kontext). Die 2.630 Vault-Dossiers (`07_WISSEN/dossiers`) sind NICHT importiert; Pipeline `07_WISSEN/_import/` (Regelwerk Zutaten-Dossier v1.3, `splitter.py` noch im 6er-Schema) läuft in einer anderen Session von Dominique. Live-Effekt von Paket H hängt an diesem Import.
- **Paket I (Paul, `feat/bildstil-dossier-produktfoto`):** Bildstil aus Kanon-Dossier (`recipe.product_photo` / `recipe.step_photos`, `kanonTextFuer()` + `stilBlock()`, Deckel 4.000 Z., Dropdown erweitert) + Knopf „KI-Produktfoto" in Rezept- und VK-Modal (EnrichRecipeJob-Modus nur Produktfoto, Cache-Schlüssel je Team+Rezept als Doppel-Enqueue-Guard, weil die Modals kaskadenfrei sind). Teil A committet (`d36e5dbf`), Teil B im Bau.
