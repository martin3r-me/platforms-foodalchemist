# 🎛️ Planung-Modul — Roadmap »Mise en Place«

> **Vom Brief zum fertigen Teller — in einem geführten Fluss.**
> Die Planung ist die **KI-Leitstelle** der Food Alchemist: ein Mensch gibt eine Absicht ein, die
> KI erarbeitet Stufe für Stufe das Ganze (Concept → Gerichte → Basisrezepte → Anreicherung), und
> an **jeder Stufe entscheidet der Mensch per Freigabe**. Wie in der Küche: erst *mise en place* —
> alles vorbereitet, geprüft, an seinem Platz — dann geht es live.

- **Zweck:** gesamtheitlicher Plan + abarbeitbare Liste für das Planung-Modul (Leitstelle).
- **Zielgruppe:** Entwicklung + Produkt (Dominique).
- **Stand:** 2026-08-14. Ergänzt den zentralen [Zielbild-Umsetzungsplan](24_Zielbild_2029_Umsetzungsplan.md) um die **modultiefe** Sicht; bei Widerspruch gilt der Umsetzungsplan.
- **Statuswerte** (aus [README](../README.md)): `gebaut` · `getestet` (Sandbox) · `demo-geprüft` (am Demo-Datensatz) · `abgenommen` (Real-LLM/Kunde). „gebaut" ≠ „abgenommen".

---

## 🧭 Nordstern — wann ist das Modul „fertig"?

Ein Nutzer startet mit **einem Satz Absicht** und einem Satz Leitplanken und erhält am Ende ein
**vollständiges, kalkuliertes, geerdetes Angebot** — ohne die Kette manuell zusammenzuklicken:

1. **Ein Einstieg, jede Tiefe:** Basisrezept *oder* Gericht *oder* Concept — je nachdem, wo man startet, kaskadiert es sauber nach unten.
2. **Geführte Freigabe je Stufe:** nichts wird still live; jede Ebene hat Vorschau → Prüfen/Ändern → Freigabe.
3. **Fachlich korrekt gebaut:** ein Gericht besteht aus **Basisrezepten** (Sauce/Jus/Püree = eigenes Sub-Rezept), nicht aus flachen Zutaten — geerdet auf das [Regelwerk Basisrezepte].
4. **Kosten sichtbar mitgeführt:** EK/VK/Marge je Stufe, Zielpreis-Korridor propagiert.
5. **Ehrlicher Durchlauf:** man sieht jederzeit, ob es arbeitet, hängt, fertig ist — und ob die Anreicherung wirklich lief.
6. **Ausgabe-fähig:** das Ergebnis fließt in Foodbook / Speisekarte / Speiseplan.

---

## 🏗️ Zielarchitektur (Whiteboard 2026-08-14)

```
  Foodbook  ┐
  Speiseplan ├─►  Briefing/Planung  ─►  ┌───────────┐
  Speisekarte┘   (oder freier Brief)    │  PLANUNG  │  ← Leitstelle
                                         └─────┬─────┘
                                               ▼  dispatch (kein Web-Request rechnet LLM)
                                         ┌─────────────┐
   Erdung + Bestand (READ-only):        │ Queued Jobs │
   Wissen · Pairing-Graph · DNA         └─────┬───────┘
   Grundprodukte · Lieferantenartikel         ▼
   Basisrezepte · Gerichte  ─────►  LLM Menü ─► LLM Gerichte ─► LLM Basisrezepte
                                          └────── + LLM Foodpairing (Assist) ──────┘
                                               │
                                               ▼
              Ausgänge:  Conceptor (Menü/Pakete) · Gerichte · Basisrezepte
```

**Lesart:**
- **Eingang:** ein freier Brief ODER ein Ausgabe-Frame (Foodbook/Speiseplan/Speisekarte) liefert das Briefing → Planung. Die Ausgabe-Module sind damit **Quelle UND Ziel**.
- **Planung = Leitstelle:** zerlegt die Absicht in **Queued Jobs** — nichts Schweres läuft im Web-Request.
- **Drei LLM-Worker je Ebene, kaskadierend:** LLM Menü (Concept) → LLM Gerichte → LLM Basisrezepte. Jede Ebene: Vorschau → Freigabe → nächste Ebene.
- **LLM Foodpairing = Assist:** erdet Gerichte/Menüs am **Pairing-Graph** (Aroma-Kohärenz), statt frei zu raten.
- **Erdung/Bestand (Read-only):** die Worker reusen vorhandene Grundprodukte/Lieferantenartikel/Basisrezepte/Gerichte und erden an Wissen (Regelwerke/Domänen), Pairing-Graph und Marken-DNA.
- **Ausgänge:** Conceptor (Menü/Pakete), Gerichte, Basisrezepte — fließen in die Ausgabe-Module zurück.

---

## ⚙️ Das Prinzip (universelles Muster je Stufe)

```
Eingabe (Titel/Brief + Leitplanken)
   → Worker erzeugt VORSCHAU (Draft, noch nicht live)
      → Prüfen / Zutaten tauschen / Neu generieren
         → FREIGABE  ── legt an + reichert KOMPLETT an (als Job)
                     └─ stößt die nächste Stufe an (Fan-out)
```

- **Kaskaden-Regel (Entscheid 2026-08-14):** am Go zählen die Leitplanken des **Start-Tabs** — sie propagieren die ganze Kaskade nach unten (`generation_params` an der Session).
- **Gestuft, nicht eager:** Run-Flag `staged` + Step-`deferred` (Migration `2026_08_14_000001`).

---

## ✅ Ist-Stand (Etappe 0 — Fundament, live auf demo)

FA `main` = `eb85e3c`, demo deployt + Migration `[180] Ran`. Nachweis: `PlanningCascadeTest`/`PlanungLeitstelleTest` grün, Server-`staged` 13×, HTTP 200.

- [x] **Gestufte Kaskade** — Gate pro Ebene, `staged`/`deferred`, `FanoutConceptJob` (`demo-geprüft`)
- [x] **Per-Tab-State** — Basisrezept/Gericht/Concept je eigenes Briefing + eigene Leitplanken (`demo-geprüft`)
- [x] **Worker-Cockpit** — Stufen-Abschnitte, Fortschritts-Header, Stufen-Freigabe, Per-Step „Neu generieren" (`demo-geprüft`)
- [x] **Inline-Conceptor** — Concept „öffnen" mountet den vollen Conceptor-Editor (`demo-geprüft`)
- [x] **Freigabe = Anlage + Voll-Anreicherung als Job** (`EnrichRecipeJob` → `RecipeOneShotService::anreichern`) (`demo-geprüft`)
- [x] **KI-Fotos-Toggle** — Produktfoto + Schritt-Fotos, opt-in (Preisfrage), `RecipeImageService` (`gebaut`, Real-Abnahme offen)
- [x] **Ehrlicher Durchlauf (P0.A)** — Erfolgs-Banner „Kaskade abgeschlossen", Watchdog (done/failed = Job-Beweis, nicht mehr blind nach Freigabe), Anreicherungs-Status je Step (`deferred.enrich`), „neu anreichern", Polling hält bis Anreicherung durch (`demo-geprüft` — live bestätigt: „Kaskade abgeschlossen — 1 freigegeben, Anreicherung läuft …" + „reichert an …"-Badge sichtbar)
- [x] **Zutaten-Review inline** — `IngredientEditor` je Draft (tauschen/entfernen/ergänzen vor Freigabe) (`demo-geprüft`)

> **Offene Abnahme (Etappe 0):** (1) Anreicherung bis „angereichert ✓" abschließen (Screenshot zeigt noch „läuft"). (2) ~~**Beobachtung Dominique:** im Worker liegen die Sub-Rezepte (Consommé/Espuma) nur als 📖-Referenz IN der Zutatenliste — sie sollten als eigene **Basisrezepte-Stufe** zum Abarbeiten/Freigeben erscheinen~~ → gebaut in Etappe 1 (`d018af1`), Real-Abnahme auf demo offen. (3) Gericht-mit-Kindern 2-stufig, Worker-Stopp-Probe.

---

## 🗺️ Die Roadmap (abzuarbeiten)

### 🥇 Etappe 1 — Qualität: **Gericht = Basisrezepte** (das große Finetuning)
*Das Kernversprechen. Heute baut die Generierung flache Zutaten statt Halbfabrikate (Sauce/Jus/Püree) als eigene Sub-Basisrezepte zu zerlegen (Beispiel: Steinpilze + Rinderjus → keine „Steinpilz-Rahmsauce").*

- [x] **★ Worker führt Basisrezepte als eigene Stufe** — die generierten Sub-Rezepte (Consommé/Espuma …) erscheinen als abarbeitbare **Basisrezepte-Stufe** (Vorschau/Freigabe je Stück), nicht nur als 📖-Referenz IN der Gericht-Zutatenliste (Beobachtung Dominique 2026-08-14)
  → `d018af1` (`gebaut`, Sandbox `getestet`): neuer Step-Status **`geplant`** (Sub-Rezept benannt, noch nicht erzeugt, kein Job — wartet auf die Freigabe der Stufe darüber) wird schon beim Aufschieben angelegt; die Freigabe schaltet **denselben** Step scharf (`geplant`→`running`, keine Dublette). Direkt verdrahtete Sub-Rezepte stehen als **`skipped`** = „übernommen" (Reuse-Treffer, ansehbar, nicht freigebbar) in der Stufe. Run-Status: `geplant` → `review` (Mensch am Zug). Cockpit: Zähler „N geplant / N übernommen" + Zustand `geplant`. 6 neue Tests, 57/57 grün.
  - [x] **Rest-Chunk (Teil 2):** geplante Sub-Rezepte VOR der Gericht-Freigabe einzeln bedienen — „jetzt erzeugen" + „brauche ich nicht" (verwerfen) je `geplant`-Zeile. Heute sind sie sichtbar, aber nur als Ganzes über die Stufe darüber auslösbar.
    → `b5275da` (`gebaut`, Sandbox `getestet`): je `geplant`-Zeile zwei Knöpfe — **„jetzt erzeugen"** (`dispatchGeplantesKind`: schaltet EINEN Step scharf `geplant`→`running`, genau ein `GenerateRecipeJob`, nutzt die am Eltern-Step aufgeschobenen Kind-Params; die spätere Stufen-Freigabe sieht ihn nicht mehr als `geplant` → kein Doppel-Job) und **„brauche ich nicht"** (`verwirfGeplantenStep`: Step → `verworfen` als Tombstone, kein Hard-Delete, Dependency entfernt; die Gericht-Freigabe erzeugt ihn über den `dedupe_key`-Treffer bewusst nicht wieder). 3 neue Tests, `PlanningCascadeTest` 50/50 grün. Real-Abnahme auf demo offen.
- [x] **Scope-Treue** — ein „Freies Basisrezept"-Start erzeugte im Test eine „Gerichte"-Stufe (Tomatensuppe); der Start-Tab muss die Ebene korrekt setzen (Basisrezept ≠ Gericht)
  → `39add65` (`gebaut`, Sandbox `getestet`): Root-Cause war der Frei-Start (`schnellErstellen`) — er legte die „Freies Basisrezept"-Session an, öffnete den Editor aber auf dem Default-Tab (`tabInit='analyse'`), sodass die intendierte Ebene am Editor-Rand verloren ging und der nächste Go auf dem Gericht-Tab landen konnte. Fix: `modal.open` darf per `tab:`-Detail einen Start-Tab erzwingen (Fallback `tabInit`); `oeffne($id, ?$startTab)` reicht ihn durch; `schnellErstellen` mappt scope→Tab (`rezept`→`basisrezept`, sonst scope-Key). Das Öffnen aus der Liste (`oeffne` ohne Tab) bleibt auf dem Editor-Default. 3 neue Tests, `PlanungLeitstelleTest`+`PlanningCascadeTest` 62/62 grün. Blade-Kompilat gelintet (0 Fehler). Real-Abnahme auf demo offen.
- [x] **LLM-Contract:** `vk.generator` / `recipe.generator` (`config/foodalchemist.php`) bekommen einen Slot für **benannte Sub-Komponenten** (statt flachem `zutaten[]`)
  → `b36ba00` (`gebaut`, Sandbox `getestet`): per-Zeile-Flag **`sub_rezept`** (bool) im `zutaten[]`-Schema beider Generator-Prompts — markiert eine Zeile als eigenständiges Halbfabrikat/Sub-Basisrezept (Sauce/Jus/Fond/Sud/Essenz/Reduktion/Püree/Creme/Dressing/Vinaigrette/Espuma), das als eigenes Basisrezept anzulegen ist, statt es flach in Rohzutaten aufzulösen (§4). VK-Prompt bekommt zusätzlich die explizite Direktive „Gericht wird aus Basisrezepten gebaut" (kein «Steinpilz-Rahmsauce» aus Steinpilzen + Sahne). Name `sub_rezept` gewählt, um nicht mit dem `role`-Enum-Wert `komponente` zu kollidieren. **Reiner Contract** — das Lesen des Flags bleibt der nächste Chunk (Entscheidungsstelle, `RecipeGeneratorService`). Kein neuer Registry-Key (`PromptRegistryTest` prüft nur Key/Task/Tier). Tests: PromptRegistry/AiRetry/AiCallLog + RecipeGenerator/VkGenerator/KohärenzGate 40/40 grün. Real-Abnahme (LLM emittiert das Flag) auf demo offen.
- [x] **Heuristik erweitern:** `MatchHeuristics` Marker-Listen (`:51-65`) um `sauce/rahmsauce/jus/sud/essenz/dressing/vinaigrette` (Zwischenschritt, bleibt Keyword-Hack)
  → `2b464c5` (`gebaut`, Sandbox `getestet`): neue Konstante **`SUB_SAUCEN_MARKER`** [`sauce`, `rahmsauce`, `jus`, `sud`, `dressing`, `vinaigrette`], im `queryIstHalbfabrikat`-Gate verdrahtet (wirkt damit auch auf `istSubRezeptKandidat`, Pool-Priorität `poolLauf` und den Direktartikel-Override). **Token-EXAKT via `patternMatchesToken`** (≤ 5 Chars exakt, länger als Präfix) **statt Substring-`str_contains`** — nötig, weil der bestehende `≥4`-Substring-Gate (a) `Sojasauce`/`Fischsauce` fälschlich fangen würde (Golden-Test pinnt `Sojasauce=false`) und (b) `jus`/`sud` (3 Chars) ganz aussperrt. Exakt-Match trifft `sauce`/`jus`/`sud` nur als eigenständiges Token (Tokenizer splittet `-`/Space: `Steinpilz-Rahmsauce`→`rahmsauce`✓, `Rotwein Jus`→`jus`✓), verschont aber gekaufte Ein-Wort-Kondimente. **`essenz` bewusst ausgelassen**: mehrdeutig (gemachte Klar-Essenz = Sub vs. gekaufte Frucht-Essenz/Extrakt = GP); die DoD **M4-14** pinnt `Drachenfrucht-Essenz` als GP-Lücke — Disambiguierung gehört ans LLM-Komponenten-Flag (nächster Chunk), nicht an ein blindes Keyword. 2 neue Golden-Tests (gemachte Saucen true + Kondimente-Guard false). Tests: IngredientMatchingHeuristics/Pools + RecipeGenerator/VkGenerator + RecipeReview/Revise 101/101 grün. Real-Abnahme auf demo offen.
- [x] **Entscheidungsstelle:** `RecipeGeneratorService:247-248/408-412` liest künftig das LLM-Komponenten-Flag statt nur die Namens-Heuristik
  → `f0787a3` (`gebaut`, Sandbox `getestet`): an der Auflösung offener Zeilen (231/247) entscheidet jetzt das per-Zeile-Flag **`sub_rezept`** (Contract b36ba00), wenn gesetzt — **authoritativ in beide Richtungen** (§4): `sub_rezept:true` erzwingt Basisrezept-Anlage auch bei heuristik-blinden Fällen (gemachte Klar-Essenz — Heuristik lässt `essenz` bewusst aus), `sub_rezept:false` hält gekaufte Ware trotz Sauce/Jus/Fond-Token im LA-Pfad. Fehlt/undeutbar das Flag → Fallback auf die bisherige Namens-Heuristik (`queryIstHalbfabrikat`/`istSubRezeptKandidat`); **kein blinder `(bool)`-Cast**, damit ein fehlendes Feld die Heuristik nicht still abschaltet. Neuer Helper `llmSubRezeptFlag()` normalisiert tolerant (bool/1-0/"true"/"ja"). Die `primaer`-Zeile flag-authoritativ (LLM-Nein kippt nicht über die Button-Heuristik creme/mousse/pesto zurück zur Sub-Anlage). **408-Site (Kohärenz-Gate-ENTdrahtung) bleibt bewusst heuristisch** — dort ist das Flag nicht persistiert und `$wasSub` dominiert das Signal → eigener Chunk (bräuchte Persistenz der Zeilen-Herkunft). 2 neue Tests (Flag beide Richtungen + Fallback ohne Flag); RecipeGenerator/Hardstop/Review/Heuristik-Goldens 75/75 + McpGenerate/Cascade 63/63 grün. Real-Abnahme (LLM emittiert das Flag) auf demo offen.
- [x] **Wissens-Erdung:** Routing-Zeile `regelwerk` → `ai_generate_recipe` (analog `2026_08_07_000001`) → §2/§3/§4 [Regelwerk Basisrezepte] fließt in den Prompt
  → `85360e8` (`gebaut`, Sandbox `getestet`): Kategorie `regelwerk` war importiert, aber ungeroutet. Neue Routing-Zeile `ai_generate_recipe:regelwerk:always` (Migration `2026_08_14_000010` + `seedRoutings`-Spiegel) + dedizierter `KnowledgeContextService::regelwerkBlock` — wählt den Basisrezepte-Slug gezielt und **extrahiert die §2–§4-Region** (nicht der ganze ~53k-Text; §2 beginnt erst bei ~17k → blinder Head-Truncate würde sie verfehlen), rahmt das Food-Wissen. **Bewusst `always` statt discovery** (Abweichung von der Roadmap-Vorlage `2026_08_07_000001`): Regelwerk ist Handwerk, kein Produkt-Dossier — die generische Beschreibungs-Discovery (Slug-Token gegen die Zutaten) trifft es bei realen Rezept-Briefs nie (kein Overlap »Steinpilz« ↔ »basisrezepte«); `regelwerk` daher in die `$spezial`-Liste, damit der discovery-Loop es dem dedizierten Selektor überlässt. 7 neue Tests (`RegelwerkKnowledgeRoutingTest`), 32/33 grün (Concept/Routing/Golden/RecipeGenerator); einzige Rot ist das pre-existing GT-13-11 (koriander-grounding, unabhängig — s. Backlog). Real-Abnahme (LLM nutzt die Regeln) auf demo offen.
- [x] **LLM-Foodpairing als Assist** (Whiteboard-Baustein) — Gericht-/Menü-Erzeugung erdet am **Pairing-Graph** (Aroma-Kohärenz) statt frei zu raten (↔ Kohärenz-Gate/Anker-Graph)
  → `40cf1d2` (`gebaut`, Sandbox `getestet`): **Menü-Hälfte** geschlossen. Die **Gericht-Erzeugung** war bereits pairing-geerdet (`GenerationContextService::forGeneration` spielt je Hauptzutat Anker-Graph-Partner in den Recipe-/VK-Prompt, rollenabhängig aroma_ausschoepfen/komposition). Die **Menü-Erzeugung** war es NICHT: `IdeenService::kiDivergenzConcept` erfand Gericht-Ideen für leere Concept-Slots aroma-blind zur restlichen Folge (nur Wissen/Trend/Preis). Neu: `PairingService::menueAromaProfil(recipes)` (deterministischer Kern: `resolveRecipeAnchorsMany`→`flacheAnker`→Anker-Labels + gewichtsstärkste Partner-Expansion via `kandidatenFuerAnker`; `null` bei leerer/ungemappter Folge → Prompt byte-identisch ohne Block) fliesst als `menue_kohaesion`-Block (bereits-getragene Anker + harmonische Partner + Hinweis „auf der Aroma-Achse harmonieren, Hauptzutat nicht wiederholen") in die Ideen-Divergenz. 4 neue Tests (Kern Anker+Partner/null · Verdrahtung Block-im-Kontext/leeres-Menü-kein-Block via Provider-Spy); zusammen mit IdeenService/Anker-Batch/PairingNetz/Cascade/McpKreativModus 85/85 grün. Real-Abnahme (LLM nutzt den Block spürbar) auf demo offen.
  - [x] **Rest-Chunk:** Menü-Folge-**Kohärenz-Gate** — der `menuCohesion`-Score der erfundenen Menüfolge als sichtbare Rückkopplung/Warnung (heute erdet die Erfindung am Graphen, aber das Ergebnis wird nicht gegen die Folge-Kohäsion geprüft). Anker-Graph steht (`menuCohesion`/`composerCohesion`).
    → `0a15c26` (`gebaut`, Sandbox `getestet`): neuer reiner Klassifikator **`PairingService::menuKohaesionWarnung(array $kohaesion)`** — macht aus dem rohen `menuCohesion`-Dict eine abgestufte Warnung `{stufe: gut|schwach|kritisch, score, text}`. **Schwellen byte-genau aus dem `kohaesion-panel` gespiegelt (≥60 gut, ≥35 schwach, sonst kritisch)** — Gate und Farb-Band sagen dasselbe, keine zweite Wahrheit. `null`, wenn nichts zu beurteilen ist (zu wenig Gerichte ODER kein bewertetes Gericht-Paar) — fehlende Graph-Daten sind KEIN schlechtes Menü (T9), nur keine Aussage. Verdrahtet in `Concepter\Editor::kohaesionPruefen` (→ `menueKohaesion['warnung']`) + als farbcodiertes Banner im `kohaesion-panel.blade.php`. **Reine Rückkopplung, keine harte Filterung.** 5 neue Tests (Score-Bänder inkl. inklusiver Grenzen · weakest-pair-Hinweis nur bei schwach/kritisch · beide null-Pfade · Gate über echte Menü-Daten · Livewire-Wiring), 58/58 grün (MenueKohaesion/Concepter/Planung-Leitstelle/Pairing/Dramaturgie), Blade-Kompilat gelintet. Real-Abnahme auf demo offen.
    - [x] **Auto-Trigger (Folge-Chunk):** das Gate nach der Fan-out-**Erfindung** (`fanoutConceptInvention` → alle `MaterializeConceptIdeaJob` durch) automatisch feuern, statt auf den manuellen „Kohäsion prüfen"-Klick zu warten — die erfundene Folge wird erst NACH dem Grounding scorebar (Skizzen tragen keine Anker). Braucht einen Fan-out-Abschluss-Haken + Persistenz der Warnung am Run/Concept.
      → `75d263a` (`gebaut`, Sandbox `getestet`): der Fan-out-Abschluss-Haken sitzt am Job-Rückkanal — `markStepDone`/`markStepFailed` rufen `scoreConceptCohesionIfComplete`. Der Handler bestimmt den Concept-Step (**meldender Step selbst** = eager-Pfad, wo die Kinder inline liefen; ODER dessen **Concept-Eltern** = async-Pfad, wo der letzte `MaterializeConceptIdeaJob` abschließt) und scored erst, wenn KEIN erfundenes Gericht mehr offen ist (`geplant`/`queued`/`running`) UND überhaupt eins existiert. `persistConceptCohesion`: `menuCohesion` → `menuKohaesionWarnung`, Ergebnis am Run persistiert — neue json-Spalte **`cohesion_warning`** (Migration `2026_08_14_000011`, additiv/idempotent). **Fail-soft:** nicht-scorebare Folge (Provider-los/ungemappt/zu wenig Gerichte) persistiert `null` und kippt den Rückkanal nie. Freie Gericht-/Rezept-Läufe (kein Concept-Eltern) unberührt. 4 neue Tests (wartet-bis-komplett · eager-Concept-Abschluss · kein-Fan-out=still · freier-Lauf-unberührt); `PlanningCascadeTest` 54/54 + `MenueKohaesion`/`PlanungLeitstelle` 20/20 grün. **UI-Surfacing der persistierten Warnung im Cockpit = Folge-Chunk.** Real-Abnahme (LLM erfindet, Gate feuert sichtbar) auf demo offen.
- [ ] **Auto-Zerlegung:** `RecipeDependencyWorkflowService:49/90`-Gating — soll auch Standalone-Gericht (ohne Kaskade) zerlegen?
- [ ] **Abnahme:** Blindtest mit 3 Briefs + Golden-Eval (0 Fremdkörper, korrekte Sub-Rezept-Bildung)

### 🥈 Etappe 2 — **Concept scharf: Leitplanken nachziehen + Kreativ-Kopf**
*Ein Concept ist ein Menü, kein einzelnes Rezept. Der Concept-Tab hat heute nur Rezept-Achsen (Convenience/Frische/Bio/Aroma) — ihm fehlen die **Menü-Leitplanken**, und die Vorab-Ausarbeitung des Plans (KI-Kopf).*

**2a — Concept-Leitplanken nachziehen** (near-term, „müssen wir noch nachziehen"):
- [ ] **Menü-Achsen** im Concept-Tab: Anzahl Gänge/Positionen, Zielpreis-Korridor (min/ziel/max je Person), Diät-Quoten (vegan/vegetarisch-Anteil), Portfolio-Balance
- [ ] Diese Achsen speisen den **Frame** (`PlanningFrameService`: Slots/Preis/Regeln), nicht nur die Rezept-Generierung
- [ ] Klar trennen: Rezept-Leitplanken (propagieren an Gerichte/Basisrezepte) vs. Menü-Leitplanken (steuern die Zusammenstellung)

**2b — Kreativ-Kopf: Concept-Plan aus Briefing** (der „alte Plan", voll eingebaut):
- [ ] Prompt `concept.plan` (tier B, in `config/foodalchemist.php` + `PromptRegistryTest::REGISTRY_SOLL` + `FOOD_DNA_KEYS`) — füllt die **Canvas** (name_claim/Leitidee/USP/Inszenierung/Geschmackswelten) aus dem Brief. Wissens-Routing `concept.plan` ist schon geseedet.
- [ ] `ConceptGeneratorService::planAusBrief(team,$brief,$extra)` — legt Draft-Concept an + **Frame** (Reuse `geruestAusBriefFuerOwner`: Gänge/Zielpreis/Diät) + **Canvas** (`concept.plan`) + materialisiert die Concept-Slots (Fan-out-Ziele)
- [ ] **„KI-Kopf"-Knopf** neben dem Concept-Briefing → `kiKopf()` → `planAusBrief` → öffnet den inline-Conceptor direkt auf „Konzept & Planung" zur Prüfung/Korrektur (`Concepter\Editor::oeffnen` um optionalen Start-Tab erweitern)
- [ ] **Engine-Option `existing_concept_id`** in `starteKaskade` → Concept-Step referenziert das geprüfte Draft-Concept (done + `deferred.fanout`) → bestehender staged-Pfad; **kein neuer Fan-out-Code**
- [ ] **Beide Pfade behalten:** Schnell (Brief → Go direkt) + Geplant (KI-Kopf → im Conceptor prüfen → Go „aus geprüftem Plan"); fail-soft, damit ein leerer Plan den Draft nicht kippt
- [ ] **Kein Migration** (Prompt = config, `planConceptId` = transiente Prop) → Deploy = reiner Lock-Pin. Risiko: `fuelleBestehendesKonzept` muss leere Slots anlegen — bei Umsetzung verifizieren.

### 🖥️ Etappe 3 — (verschoben) → Hauptseite kommt ZULETZT
*Die Hauptseite / Planung-Landing wird bewusst als **letzter Bau-Schritt** geführt („erst wenn der Editor steht und funktioniert"). Der volle UI-Plan steht **ganz am Ende dieser Roadmap** (finale Etappe). Diese Nummer bleibt als Platzhalter, damit die Etappen 4–10 stabil bleiben — keine offenen Punkte hier.*

### 🥉 Etappe 4 — Eingabe-Reife
- [ ] **Skizzen-Integration** — Ideen/Skizzen als Kaskaden-Eingang (bisher deferred)
- [ ] **Brief-Vorlagen** je Sektor/Anlass (Schnellstart statt Blank Page)
- [ ] **Trend-Anbindung** — Trendradar-Signal → vorbefülltes Briefing
- [ ] **Titel-/Namensvorschlag** aus dem Brief (nüchtern, §-konform)

### 🍽️ Etappe 5 — Ausgabe-Anbindung (Frame-Kaskaden)
*Die Voll-Kaskade braucht einen Ausgabe-Owner (foodbook/speisekarte/speiseplan) — sie wird aus den Ausgabe-Modulen getriggert, nicht frei im Cockpit. Die Ausgabe-Module sind **Quelle** (liefern das Briefing) **und Ziel** (nehmen das Ergebnis auf) — siehe Zielarchitektur.*

- [ ] **Foodbook-Leitstelle** — Voll-Kaskade je Kapitel-Slot (↔ Spec [29](29_Foodbook_Editor_Umbau.md))
- [ ] **Speisekarte** — Rubriken → Gerichte-Kaskade (↔ Spec [35](35_Spec_Tagesplan_Cockpit.md))
- [ ] **Speiseplan** — Zell-Fan-out über die Zeitachse (Cap `SPEISEPLAN_MAX_ZELLEN`)
- [ ] Ausgabe-Voll-Kaskaden bleiben `staged=false` (eager, Sammel-Review) — bewusste Unterscheidung dokumentieren

### 💶 Etappe 6 — Kosten & Marge inline
- [ ] **EK/VK/Marge je Stufe** im Worker-Cockpit sichtbar (nicht erst nach Speichern)
- [ ] **Zielpreis-Korridor** propagiert Concept → Gericht (aus Frame)
- [ ] **Margen-Gate** — Warnung bei Freigabe unter Aufschlagsklasse
- [ ] Unvollständige Bepreisung sichtbar markieren (EK unvollständig)

### 🖼️ Etappe 7 — Bild & Medien
- [ ] KI-Fotos je Stufe opt-in (Produkt + Schritte), Kosten-Transparenz je Call
- [ ] Bild-Status im Cockpit (erzeugt/fehlgeschlagen/„neu erzeugen") — analog Anreicherungs-Badge
- [ ] Foto-Wiederverwendung / manueller Upload als Alternative

### 🛡️ Etappe 8 — Robustheit & Skalierung
- [ ] **Worker-Präsenz** ist mission-critical — Health-Anzeige + Doku (kein `queue:work` = Leitstelle produziert nichts)
- [ ] **Fan-out-Cap Concept** — Concept-Erfinden hat KEINEN harten Deckel (Speiseplan schon: `SPEISEPLAN_MAX_ZELLEN=30`) → Runaway-/Kosten-Risiko bei großem Menü-Brief. Analogen Cap für den Concept-Fan-out (`fanoutConceptInvention`) einziehen.
- [ ] **Save-Race Multi-Editor-Fan-out** (verify) — der Kaskaden-Fall mountet mehrere `IngredientEditor` gleichzeitig; prüfen, ob der MVP-046-Fix (eindeutige Editor-ID) auch bei parallel gemounteten Editoren hält — sonst überschreiben sich Draft-Speicherungen.
- [ ] **Fehler-Transparenz** — jeder geschluckte Job-Fehler wird sichtbar (Anreicherung ✅ erledigt; Fan-out/Images noch prüfen)
- [ ] **Idempotenz/Resume** — abgebrochene Kaskade sauber fortsetzbar
- [ ] **Multi-Tenancy** — Reads `visibleToTeam`, Writes `isOwnedBy` konsequent (Audit)

### 🔌 Etappe 9 — MCP & Automatisierung
- [ ] MCP-Tools für die Kaskade (Start/Freigabe/Status) — headless-fähig, im Lockstep mit dem UI
- [ ] Prompt-/Tool-Inventar in [26_LLM_MCP_Funktionsmatrix](26_LLM_MCP_Funktionsmatrix.md) nachziehen

### 📚 Etappe 10 — Abnahme, Betrieb & Doku
- [ ] **Real-LLM-Abnahme** Etappe 0 (s. o.) — durch Dominique auf demo
- [ ] **Benutzerhandbuch `planung.md`** — das Modul hat noch keinen Handbuch-Eintrag (Lücke)
- [ ] **Business-Case-Funktionen** in [25](25_Business_Case_Funktionsmatrix.md) mit Abnahmeevidenz eintragen
- [ ] demo → Produktiv-Team, Worker-Daemon abgesichert

---

## 🔀 Offene Entscheidungen
- **Zerlegungstiefe:** Wie tief zerlegt ein Gericht (max. Ebenen)? Regelwerk sagt Guideline 3, hart nur Zyklus/Selbstbezug.
- **Bild-Kostenpolitik:** KI-Fotos default aus — pro Stufe, pro Kaskade oder global schaltbar?
- **Standalone-Zerlegung:** Soll auch die Nicht-Kaskaden-Gericht-Erstellung (VkGenerator) automatisch zerlegen, oder bleibt Zerlegung Kaskaden-exklusiv?
- **Concept-Plan-Pflicht:** Ist der KI-Kopf (Etappe 2) optional (beide Pfade) oder der neue Standard?

## ⚠️ Risiken & Abhängigkeiten
- **Deploy:** `update.sh`/`composer update` tot am `platforms-avatar`-404 → Deploy nur via **Lock-Pin** auf FA-`main`-HEAD (main == Feature, sonst setzt `dev-main` zurück). Migrationen laufen NICHT automatisch mit `update.sh` — Forge migriert beim Push, sonst `php8.4 artisan migrate --force`.
- **Blade-Direktiven:** geklebte `@if`/`@endif` sind für Blade unsichtbar → Blade-Änderungen immer durch **Linten des kompilierten PHP** verifizieren.
- **Provider-los in Sandbox:** Generierungs-Qualität nur mit echtem LLM auf demo bewertbar.

## 📎 Verweise
- [Regelwerk Basisrezepte] = `07_WISSEN/.../Regelwerke/Regelwerk_Basisrezepte.md` (Vault, §2 Verarbeitungs-Reduktion · §3 Pürees · §4 Sub-Rezept-Hierarchie)
- Specs: [27 Step-by-Step](27_Spec_Step_by_Step_Zubereitung.md) · [29 Foodbook-Editor](29_Foodbook_Editor_Umbau.md) · [30 Produktion](30_Produktion_Ausbau.md) · [35 Tagesplan-Cockpit](35_Spec_Tagesplan_Cockpit.md) · [37 KI-Erstellen Typ/Niveau](37_Spec_KI_Erstellen_Typ_Niveau.md)
- Steuerung: [24 Umsetzungsplan](24_Zielbild_2029_Umsetzungsplan.md) · [23 MVP-Audit](23_MVP_Audit.md)

---

## 🖥️ FINALE ETAPPE — Hauptseite / Planung-Landing (Arbeitsgrundlage)

*Der LETZTE Bau-Schritt — erst wenn der Editor/Cockpit steht und rund läuft. Die Hauptseite muss Design & Flow des fertigen Editors spiegeln (nicht zwei Welten). Basis = die aktuelle Landing: „Planungen"-Liste links · „Neu erstellen" (Basisrezept/Gericht/Concept) · „KI-Leitstelle"-Intro · „Zuletzt"-Karten · Details-Panel rechts. Steht bewusst ganz am Ende, weil die Roadmap die Arbeitsgrundlage der Routine ist (top-down → Hauptseite zuletzt).*

**Linke Spalte — „Planungen"-Liste**
- [ ] Status-Badge je Eintrag (Entwurf · läuft · prüfen · freigegeben · fertig) statt nur Divergenz/Konvergenz
- [ ] Gruppierung/Filter (Kategorie · Herkunft · Typ Basisrezept/Gericht/Concept) + Suche
- [ ] laufende Planung optisch hervorheben (Puls bei `running`)

**Mitte — „Neu erstellen" + „Zuletzt"**
- [ ] „Neu erstellen"-Leiste im Editor-Look; Trend/Skizze klar als EIN Input dahinter (nicht der Rahmen)
- [ ] „Zuletzt"-Karten mit Status + Kaskaden-Fortschritt (z.B. „Gerichte 1/1 · Basisrezepte 0/3") + Direkt-Aktionen (Öffnen · freigeben · duplizieren · verwerfen)
- [ ] verwaiste Entwürfe (ohne Lauf) sichtbar machen

**Rechte Spalte — Details-Panel**
- [ ] bei Auswahl: Herkunft/Lineage · Status · Kaskaden-Stand je Stufe · Skizzen · „Im Editor öffnen"
- [ ] Kaskaden-Kurzstatus (welche Stufe offen/freigegeben/angereichert) ohne den Editor zu öffnen

**Gesamt**
- [ ] Design-/Flow-Parität zum Editor-Cockpit (gleiche Sektionen/Farben/Abstände)
- [ ] Verifikation: Sandbox-Render + kompiliertes-Blade-Lint; Real-Abnahme auf demo durch Dominique
