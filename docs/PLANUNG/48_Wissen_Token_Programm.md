# Spec 48 — Wissen/Token-Programm: Regelwerk vollständig in den Prompt, Token runter

> **Tracking:** Office Dev-Package 23, Features-Board (`dev_board_id=53`).
> **Status:** Welle 0 + Klasse-2-Extraktion **deployt**. Welle 1 (Chunking) offen, Welle 2 (Kanon) wartet auf fachliche Abnahme, Welle 3 teilweise (W3-1 deployt, W3-2/W3-4 offen).

## Anlass

Gemessen auf demo über 30 Tage (`foodalchemist_ai_call_log`): **~62.700 Input-Token pro
erzeugtem Rezept/Gericht**, 17,87 M Token gesamt, Cache-Quote **0,35 %**. Ein Gericht mit 5
Sub-Rezepten löst 80–100 Calls aus. Das trägt keine Speisepläne und keine Foodbooks.

Der schlimmere Befund war aber nicht die Menge, sondern **was ankam**: von 9 an
`recipe.generator` gebundenen Regelwerk-Dossiers erreichte den Prompt **keines**.

## Der Fund, der alles erklärt (W0-3b)

`RecipeGenerationContextService` spiegelte die gebundenen Dossiers nach
`contextFor()->files_used`, damit sie im „Verwendetes Wissen"-Chip erscheinen. Aber
`files_used` geht als `knowledge_used` an `propose()` und ist dort der **Dedup-Eingang** von
`selectBoundKnowledge()`: jedes Doc, das schon als „verwendet" gemeldet ist, wird
übersprungen.

**Der Bound-Kanal war für beide Generatoren komplett tot** — seit dem Spiegel (2026-08-07),
nicht seit Welle 0. Der Workaround vom 2026-08-27 („8 Always-Dossiers binden") hat nie
gewirkt: nicht „3 von 9 kamen an", sondern **0 von 9**. Und der beabsichtigte Nutzen trat
auch nicht ein, weil der Kontext-Inspektor `used_by_category` liest, nicht `files_used`.

> **Lehre, die für den geplanten Kanon (W2) genauso gilt: eine Anzeige darf nie auf dem Feld
> mitschreiben, das eine Auswahl-Logik als Eingang benutzt.**

## Belegte Wirkung (demo, gleiche Anfrage zweimal generiert)

| | vor Fix | nach Fix | Baseline 30 T. |
|---|---|---|---|
| `vk.generator` tokens_in | 7.230 | **16.778** | ⌀ 36.918 |
| `prompt_parts.bound` | **0** | **28.630** | — |
| Bau-§§ im Prompt | 0 von 7 | **7 von 7** | 0 von 7 |

**−55 % gegenüber der Baseline — und erstmals MIT vollständigem Regelwerk statt ohne.** Die
7.230 des ersten Laufs waren kein Gewinn, sondern fehlendes Pflichtwissen.

`recipe.generator` Pflichtmenge **25.282 → 18.521** Zeichen (Deckel 19.000): die Kappung von
6.282 Zeichen bindendem Regelwerk ist weg.

## Architektur-Entscheid: Wissen nach FUNKTION trennen

Der Korpus enthält drei Sorten Wissen, die vorher alle durch denselben Trichter gingen:

1. **Achsen-gebunden → nachschlagen statt suchen** (~90 Docs): `event_playbook` (13 Docs ↔ 13
   Zeilen `foodalchemist_event_types`), `segment`, `niveau`, `weltkueche`, `ernaehrung`. Join,
   100 % präzise, kein Embedding. Das Muster existierte schon (`niveauBlock()`).
2. **Regelwerke → durchsetzen statt in den Prompt legen** (~154k Zeichen): §5 Default-GPs ist
   eine Lookup-Tabelle, §7 ein Aggregat, §12 ein Sortier-Anker. Zwänge gehören in
   Resolver/Validatoren + den Konformitäts-Critic (der hat den Volltext ohnehin und zitiert
   §-genau). Der Generator bekommt eine Checkliste.
3. **Offenes Material → echter Suchfall** (~460 Docs): `domain`, `cross_cutting`, `kueche`,
   `signatur_kuechen`. Nur hier lohnt Chunking. **Welle 1 schrumpft dadurch von 598 auf ~460.**

## Gemessen und dabei WIDERLEGT — drei Plan-Prämissen

| Prämisse | Messung |
|---|---|
| „Nur 52 % des Korpus semantisch findbar" (W1-1: Fenster 2000→8000) | **Zeichen-Rechnung, keine Messung.** Recall-Probe: Kopf 92 %/92 %, jenseits 2000 **72 % → 68 %**. Grösseres Fenster macht es SCHLECHTER (Verdünnung). Zurückgenommen. **Chunking (W1-5) bleibt der einzige Fix — jetzt gemessen begründet.** |
| „§5 entbinden spart Token" | **Spart NICHTS.** Der freie Platz füllt sich bis zum Deckel mit `discovery`-Bindings. Gewonnen wurde Inhaltsqualität. **Wer entbindet, muss den Deckel mitsenken.** |
| „W3-3 spart 39 %" | 17–21 %. Die §-Dossiers enthalten **kein** Changelog, und die 21 % Tabellen sind normativ. |

### Bindung ≠ Durchsetzung — die Zahl, die W2 entscheidet

An `conformance_findings` gemessen: §7/§12 entbunden + code-erzwungen = **0 Befunde**. §2
gebunden = **27**. §1 unbesetzt (weder gebunden noch erzwungen) = **65 (39 %)**. Break-even
für §1+§10 im Prompt liegt bei 10,2 %.

## Was sonst deployt ist

- **Messsonde** `prompt_chars` + `prompt_parts` (kanon/retrieval/bound/task/kontext/huelle/dropped).
  Ohne sie wären alle Prozentzahlen Schätzungen — sie hat W0-3b überhaupt sichtbar gemacht.
- **W0-1** `with_context=false, tools=false`: der Core stellte `'Zeit: '.now()` vor JEDEN Call
  ⇒ Prefix-Cache prinzipiell unmöglich. **Nicht** im agentischen Tier-D-Loop.
- **W3-1 Cache-Prefix**: Message-Layout festgeschrieben (1–3 Hüllen · 4 Regelwerk · 5 task ·
  6 Retrieval · 7 Kontext-JSON). Prefix **byte-stabil bei 24.718 Zeichen ≈ 8.239 Token**,
  belegt. Ob der Provider es monetarisiert, ist offen (`prompt_cache_key` ist nicht
  durchleitbar — Bitte an Martin in Spec 47).
- **Sechs stille Deckel sichtbar gemacht** (Speiseplan-Wochen, Zellen, Speisekarten-Positionen,
  Konzept-Lücken, Sub-Rezept-Tiefe) — mit Meldungen, die sich aufrechnen. Darunter lagen **zwei
  echte Bugs**: ein `params`-Overwrite löschte die Leitplanken, und `MAX_STEPS` begrenzte die
  BREITE statt der Tiefe (Sub-Rezepte verhungerten lautlos).
- **Warteschlangen-Trennung nach Artefakt** → Runbook [[39_Worker_Betrieb_Runbook]] §1a.
- **Drift-Wächter**: `foodalchemist:wissen-steuerdaten-w0 --verify` wöchentlich, meldet als
  Signal `steuerdaten_drift`. Anlass: die Live-Routings wichen unversioniert von den
  Migrationen ab.
- **Routing-Politik verlässt den Import** → `foodalchemist:knowledge-policy-seed`.
  `seedRoutings()` hätte auf frischer DB (Disaster Recovery) den Monolithen-Pfad
  wiederhergestellt.
- **Zwei Matcher-Defekte** (`529567c8`, `97f6a991`): `gpPool` kappte nach **ID** statt Relevanz
  (je mehr Bestand gepflegt wird, desto unsichtbarer das Neue), und der Anti-Marker löschte
  den exakten Treffer der eigenen Suche.


## W3-4 ist eine Falle, nicht nur ein Hebel (gemessen 2026-09-03)

Der Plan warnte, Tier C sei laut Config für Vision reserviert, ein blinder ENV-Flip treffe also
keinen Klassifikator. Beim Nachlesen der Registry Key für Key ist es **schlimmer**: die
Tier-Etiketten beschreiben nicht mehr, was auf ihnen liegt.

| Tier | Config-Kommentar sagt | trägt wirklich |
|---|---|---|
| **A** (20) | »Qualität (Generatoren, lange Texte)« | Texte, Review, Wording, Plating — **aber keinen einzigen Generator** |
| **B** (43) | »Mechanik-Labels« | **`recipe.generator`, `vk.generator`, `conformance.check`, `recipe.anker`, `foodbook.kapitel_ideen`, `concept.plan`** — also **alle sieben grössten Token-Verbraucher**. Zusätzlich ist B der **Fallback** (`AiGatewayService:103`: `?? ($prompt['tier'] ?? 'B')`). |
| **C** (5) | »Vision (Wissenskontext leer)« | `recipe.description`, `gp.suggest`, `gp.tags`, `recipe.garverlust`, `recipe.extract` — nur der letzte ist plausibel Vision, `recipe.description` ist **Produkttext** |
| **D** (4 + 1) | »Reasoning/Tools« | `voice.command` (nicht in der Registry, in `callWithTools` **hart** auf `'D'`) plus vier triviale Klassifikatoren (`demo.echo`, `gp.condition`, `recipe.category`, `recipe.name_putzen`) |

**Die Falle:** `FOODALCHEMIST_AI_TIER_B=gpt-5.6-luna` liest sich wie »die mechanischen Labels
aufs billige Modell« und würde in Wahrheit **den Rezept-Generator, den Gericht-Generator und
den Konformitäts-Critic** auf das billigste Modell der Tabelle setzen ($0,20 statt $5,00 je
Mio — Faktor 25, gekauft mit der Kernqualität des Produkts).

**Konsequenz für die Reihenfolge:** W3-4 ist **kein ENV-Schritt**, sondern zuerst eine
Re-Tierung in der Registry (Generatoren nach A, `recipe.description` nach A, die vier
mechanischen D-Keys nach B, D allein für den Tool-Loop) — mit `PromptRegistryTest` als
Wächter. **Erst danach** dürfen Modell-Strings gesetzt werden, und dann Key für Key mit
Golden-Gate, nicht tierweise.

Bei heutigem Volumen sind das ~$4/Monat. Der Wert von W3-4 ist **Kopfraum für die Skalierung
und Latenz**, nicht die heutige Rechnung — das rechtfertigt keinen unbesehenen Flip.

## Entscheide — Stand nach Prüfung 2026-09-03

Die erste Fassung dieser Liste war aus dem Plandokument übernommen und **veraltet**: zwei der
vier Punkte hatte Dominique längst entschieden bzw. waren am selben Tag umgesetzt. Gegen den
Live-Stand geprüft (Bindings auf demo, `MatchHeuristics`, `foodalchemist_gps`):

### ✅ Leitungswasser — ERLEDIGT
Das GP existiert: **id 9359, »Leitungswasser: frisch«, approved, `is_platzhalter=0`**. Der
§5-Alias in `MatchHeuristics:548` zeigte auf »Wasser: Leitung« — **diesen Namen gibt es nicht**;
korrigiert auf den echten. Die Regelwerk-Frage zu §11.2 stellt sich damit nicht: es brauchte
kein neues GP, nur den richtigen Namen im Alias. Rest-Nuance: `requires_la=1`, d. h. das GP
verlangt formal einen Lieferantenartikel und hat keinen — Dominique tauscht das bei Bedarf
über die UI.

### ✅ Zweiter Kurator — ENTSCHIEDEN (Dominique, 2026-09-02)
> „Was wir gerade an Wissen bauen, ist global, damit die Generatoren laufen. Ein neuer User
> bekommt das Wissen auch, aber komplett leer, und kann dort für sich Wissen hinterlegen —
> das kann nur für sein Team und Kind genutzt werden."

Konsequenz war bereits gezogen: der gebaute `team_id`-Filter im **Retrieval** wurde
**vollständig zurückgenommen** (er hätte für jedes andere Team und jeden Console-Lauf 598 Docs
auf 6 gekappt, ohne rote Tests). Gescopet bleibt die **Schreib**seite und der Browser.

### ○ Kanon §1 + §10 in den Prompt — WIRKLICH OFFEN
Live geprüft: an `recipe.generator` hängen sechs `always`-Dossiers (§2, §3, §4, §6,
`mengen_defaults`, `workflow.basisrezept_erstellungs_dossier`), an `vk.generator` dieselben plus
`regelwerk_verkaufsgerichte`. **§1 Naming und §10 Anti-Patterns sind bei keinem von beiden
gebunden** — und auch nicht code-erzwungen. Datenlage: §1 = **65 Befunde = 39 %** aller
Konformitäts-Funde. Kostet Deckel 19.000→24.000 / 27.500→31.000. Kehrt eine dokumentierte
Spec-41-Entscheidung um (gepinnt in `RegelwerkKnowledgeRoutingTest:80`).

### ○ §2 code-erzwingen statt binden — OFFEN, aber anders begründet als gedacht
Die Convenience-**Steuerung** ist längst Code: `from_scratch` verschiebt das Pool-Verhältnis in
`ConceptGeneratorService:1197-1201`. Offen ist nur, ob §2 *Verarbeitungs-Reduktion*
(Brunoise → Roh-Form) aus dem Prompt in einen Resolver wandert. Argument dafür ist **nicht**
Token-Ersparnis, sondern Wirkung: §2 **ist** gebunden und produziert trotzdem **27 Befunde** —
gebundenes Regelwerk verhindert den Verstoss offenbar nicht.

### ○ Slot-Deckel für Angebot/Format — OFFEN
Heute weder Gate noch Deckel.

## Der Apfel-Fall — drei Theorien, zwei davon falsch (2026-09-03)

Das Qualitäts-Gate (Gericht 3689) band `Apfel: TK, Wuerfel`, wo ein frischer, spezifischer
Apfel gehört hätte. Der Weg zur Ursache ist protokollierenswert, weil ich zweimal die falsche
Stelle beschuldigt habe — beide Male, weil ein Etikett log.

**Theorie 1 (falsch): der Frische-Hook ist nur ein Tiebreaker.** Stimmt als Beobachtung
(`variantRankResolved` entscheidet laut Docblock nur bei Score-Gleichstand, während das
Tool-Schema `frische` als »harten Resolver-Hook« bewirbt) — war aber nicht die Ursache.

**Theorie 2 (falsch): der Resolver hat falsch gewählt.** Gegenprobe am echten Matcher:
`candidatesFor("Apfel")` liefert `Apfel Royal Gala: Ganz` als **Platz 1**. Der Resolver hätte
richtig gewählt.

**Tatsächliche Ursache: das MODELL hat die `gp_id` vorgeschlagen, der Resolver lief nie.**
Der Generator verdrahtet eine vom Modell gelieferte `gp_id` direkt (B0-Kern, gepinnt in
`RecipeGeneratorTest:515`). Das Modell nahm, was im Bestands-Kontext stand.

**Und damit ist der eigentliche Befund der gesättigte Score:**

| # | Kandidat für »Apfel« | Score |
|---|---|---|
| 1 | Apfel Royal Gala: Ganz | **1.001** |
| 2–9 | Apfel-Sorbet Granny Smith · Apfel-Sorbet · Apfel: TK, Wuerfel · Apfel: TK, gewuerfelt BIO · Ruehrkuchen mit Apfel · Apfel: konserviert · Kompott: Apfel · Gel: Apfel | **alle 1.001** |

Neun punktgleiche Kandidaten heisst: die Rangfolge ist **beliebig**, und welche davon in den
Modell-Kontext kommen, entscheidet keine Relevanz. Dieselbe Klasse wie der `gpPool`-Fehler
(kappte nach ID statt Relevanz) — dort behoben, hier eine Ebene tiefer.

**Dritter Befund, der jede Reparatur allein zunichte macht:** `Apfel Royal Gala: Ganz` hat
`condition = NULL` (einer von **163** lebenden approved GPs, davon **76 in Rezepten**). Im
Tiebreaker ergibt das Rang 0, während `Ruehrkuchen mit Apfel: frisch` durch das Wort »frisch«
im Namen **+3** bekommt. Ein funktionierender Frische-Hook würde also einen **Rührkuchen** vor
den Apfel setzen.

**Gemessen und deshalb NICHT gemacht:** der Klassifikator `gp.condition` kostet nur ~170 Token
pro GP (163 GPs ≈ 0,14 €) — lieferte im Einzeltest an Gala aber **null Vorschläge**. Ein
Massenlauf gegen eine an 1 von 1 widerlegte Wirksamkeit wäre Geld gegen eine Annahme.
Erst klären, warum der Vorschlag ausbleibt.

**Kriterium für den Fix, von Dominique geschärft:** nicht »Gala muss gewinnen« — *»es gibt auch
andere Äpfel«*. Das Ziel ist die **Klasse** der Antwort: eine spezifische, frische, ganze Sorte
schlägt den TK-Würfel. Welche Sorte, entscheidet das Gericht.

**Erledigt:** §10-Bremse gegen generische Namen im `GpNamingService` (Hard-Error beim Namen,
nicht erst im Critic) · Fallback-Provenienz `manual` → `gp_v2_fk`, damit künftige Diagnosen
lesbar sind.

**Offen und Dominiques Entscheid:** den gesättigten Score reparieren und `frische` tatsächlich
hart machen. Herzstück-Chirurgie mit ~200 Golden-Tests daran — der Sherryessig-Fix hat gezeigt,
dass man dort einzeln und gemessen vorgeht, nicht in einem Zug.

## Offene Bauschritte

| # | Punkt | Warum offen |
|---|---|---|
| W1-5 | **Chunking + Qdrant** (~460 Docs, Ziel 900 Z., `heading_path` im Vektor) | Der einzige gemessen begründete Retrieval-Fix. Off-peak, ~5.800 serielle Roundtrips, ~$0,10. |
| W3-2 | **Kontext-Wiederverwendung in der Kaskade** + Fan-out-Governor | Nicht gebaut. Der Kaskaden-Status kommt aus DB-Zeilen, nicht aus dem 15-Min-Cache — die TTL-Sorge des Plans ist also **kein** Live-Bug. |
| W3-4 | **Tier/Modell** | **0 von 4 Tier-Variablen auf demo gesetzt** ⇒ alle 72 Prompt-Keys auf dem teuersten Modell. **Zuerst Re-Tierung, dann ENV** — siehe Abschnitt »W3-4 ist eine Falle«: Tier B heisst »Mechanik-Labels« und trägt den ganzen Erzeugungs-Kern. |
| W1-3 | `$params['_prompt_key']`-Durchstich | Ohne ihn hat `vk.generator` kein eigenes Budget (VK läuft über `contextFor('ai_generate_recipe')`). |
| — | `conformance_findings.paragraph` normalisieren | 5 Varianten für §11 ⇒ Befunde sind nicht sauber aggregierbar. |
| — | `foodalchemist:generator-eval` | Existiert, **nie gelaufen**. Ohne es wird ein Budget-Schnitt als Erfolg abgehakt, während die Rezepte schlechter werden. |
| — | `ai_plan_dishes` | 8 Routing-Zeilen ohne jeden Aufrufer. Reine Konfig-Schuld. |

## Deploy-Mechanik

demo = lokal `update.sh` (macht blind `git add .` → vorher `git status`, nur eigene Dateien
stagen). Vor jedem Push `git branch --show-current`, dann `git push origin HEAD:main`. Bei
Migrationen FA-scoped per SSH:
`php artisan migrate --path=vendor/martin3r/platform-foodalchemist/database/migrations`.

Cross-Refs: [[39_Worker_Betrieb_Runbook]] · [[41_Spec_Planungsmodul_Qualitaet]] ·
[[45_Voice_Produkt_Agent]] · [[46_Kanon_Entscheidungsvorlage]] ·
[[47_Notiz_an_Core_Semantic_Layer]]

## Changelog
- **2026-09-03** — Erstanlage. Programm lief bis hier nur in einer Session-Plandatei; damit war der Repo-Stand blind für 14 Commits. Zahlen sind gemessen (Messsonde `prompt_parts`), nicht geschätzt.
- **2026-09-03** — W3-4-Analyse ergänzt: Tier-Etiketten stimmen nicht mit ihrem Inhalt überein. Tier B (»Mechanik-Labels«) trägt alle sieben grössten Verbraucher und ist zugleich der Fallback. W3-4 ist damit erst eine Re-Tierung, dann ein ENV-Schritt.
- **2026-09-03 (Korrektur)** — die Entscheide-Liste war aus dem Plandokument übernommen und veraltet. Gegen den Live-Stand geprüft: Leitungswasser ist erledigt (GP 9359 existiert, Alias korrigiert), der zweite Kurator ist entschieden (Retrieval global, Schreibseite gescopet). Offen bleiben Kanon §1+§10, §2-Erzwingung und der Slot-Deckel. Lehre: eine »offen«-Liste gegen den Bestand prüfen, nicht aus dem Plan kopieren.
- **2026-09-03 (Apfel)** — Ursache des TK-Apfels: nicht der Frische-Hook und nicht der Resolver (der wählt richtig), sondern das Modell wählt aus einem Kontext, dessen Kandidaten alle denselben Score 1.001 tragen. Plus 163 GPs ohne `condition`. §10-Namensbremse und ehrliche Fallback-Provenienz erledigt; Score-Reparatur offen.
