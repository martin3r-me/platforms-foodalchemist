# Spec 40 — Leitstelle als Planungs-Spine: Round-Trip-Vertrag, Angebot-Andocken & Rückkopplung

> **Tracking:** Office Dev-Package 23, Features-Board (Board 53). Architektur-/Vertrags-Spec + gestufte Bau-Runden (E0–E5). Erweitert die Zielarchitektur aus [Spec 38 — Roadmap Planung-Leitstelle](38_Roadmap_Planung_Leitstelle.md) (dort bereits als „Ausgabe-Module = Quelle UND Ziel" skizziert) um den **verbindlichen Vertrag**, die **Angebot-Symmetrie** und die heute fehlende **Rückkopplung**.

**Status:** Konzept 2026-08-18 (mit Dominique erarbeitet, Doc-first). **✅ Umsetzung 2026-08-20 (autonom): ganze Liste `getestet` auf Branch `feat/spec40-umsetzung` — Spine (E-P0, E0, E1, E1b, E2, E4, E5) + Werkstrang M (Phase A–E), 11 Commits.** E3 blockiert (RAG-Branch fehlt in main). **Branch gepusht 2026-08-20** (`origin/feat/spec40-umsetzung`, PR offen) — noch NICHT nach main gemergt/deployt (Dominiques Schritt) → Statuswert `getestet`, nicht `demo-geprüft`/`abgenommen`. Finaler Regressions-Gate: volle FA-Suite **2773 Tests, 2725 grün, 17 rot = exakt die vorbestehenden (0 neue Regressionen)**. Statuswerte (aus [README](../README.md)): `gebaut` · `getestet` (Sandbox) · `demo-geprüft` · `abgenommen`. „gebaut" ≠ „abgenommen".

Alle Codepfade relativ zu `platforms-foodalchemist/` (canonical Clone). Zeilennummern = Stand 2026-08-18, als Wegweiser (vor dem Edit verifizieren).

---

## Anlass

Dominiques Leitfrage: *Wo wird was geplant? Plant die Leitstelle den Rahmen des Foodbooks/der Speisekarte — oder wird der Rahmen im Ausgabe-Modul geplant, und die Daten kommen in die Leitstelle, um die einzelnen Kapitel zu erstellen?* Dazu die zweite Absicht: **auch die Rückkopplung wieder rein.**

Auslöser war der Eindruck „vier getrennte Silos" (Foodbook-Cockpit, Speisekarte-Leitstelle, Speiseplan-Editor, Planungs-Leitstelle — jedes mit eigenem KI-Einstieg). Ein Code-Audit (2026-08-18) widerlegt die Prämisse: **ein Motor, drei von vier Ausgabeformen bereits angedockt, ein echter Silo (Angebot), Rückkopplung fast leer.**

## Sparring-Kern

| Frage | Entscheidung |
|---|---|
| Wer plant den Rahmen? | **Das Ausgabe-Modul** (Foodbook/Speisekarte/Speiseplan/Angebot) — dort lebt der `PlanningFrame` + die Struktur. |
| Wer erstellt die Inhalte? | **Die Leitstelle** — geteilte KI-Erstellungs-Maschine, gestufte Freigabe, Kaskade bis GP/LA. |
| Wer baut die Ausgabe zusammen? | **Das Ausgabe-Modul** — Dokument/Karte/Aushang/Angebot. |
| Ist die Leitstelle ein zweiter Planer? | **Nein.** Motor, nicht Planer. |
| Wird der Composer zum Ausgabe-Assembler? | **Nein** — bleibt Foodpairing (graph-only). Würde dem Vertrag widersprechen. |
| Einheits-Vordertür in der Leitstelle? | **Nein** — die Tür bleibt im Modul (dort entsteht der Rahmen). Vereinheitlicht wird das *Cockpit*, nicht der Startknopf. |
| Angebot | **Andocken** (einzige echte Konvergenz-Lücke). |
| Rückkopplung | **Bauen** — höchster Hebel: Ergebnis → Wissen/Trend (kompoundiert mit RAG). |

---

## 1. Kernmodell — der Round-Trip (verbindlicher Vertrag)

Die Arbeit teilt sich in drei Phasen mit fester Zuständigkeit:

| Phase | Zuständig | Was passiert | Artefakt |
|---|---|---|---|
| **A · Rahmen** | Ausgabe-Modul | Struktur planen: Kapitel/Rubriken/Wochen, Zielgruppe, Tonalität, Niveau-/Leitplanken-Defaults | `FoodAlchemistPlanningFrame` (`OWNER_TYPES = foodbook, concept, speisekarte, speiseplan`) + Struktur |
| **B · Erstellung** | **Leitstelle** | Rahmen fließt per `starteKaskade('vollkaskade', owner_type=…)` in die Spine; je Slot ein Konzept/Gericht generiert, gestuft geprüft + freigegeben, Kaskade bis GP/LA | `FoodAlchemistCascadeRun` (`source_owner_type/_id`), `FoodAlchemistConcept`, `FoodAlchemistRecipe` |
| **C · Zusammenbau** | Ausgabe-Modul | Freigegebene Inhalte → Deliverable: Foodbook-PDF, Karte, Aushang, Angebot | Modul-Dokument |

**Der Vertragssatz:** *Das Modul plant den Rahmen und baut die Ausgabe zusammen; die Leitstelle ist die geteilte KI-Erstellungs-Maschine für die Inhalte dazwischen. Die Leitstelle ist Motor — kein zweiter Planer, kein Assembler.*

Damit ist Dominiques zweite Lesart die verbindliche: **Foodbook wird im Foodbook geplant, die Daten fließen in die Leitstelle, um die Kapitel zu erstellen.** Code-Beleg: `PlanningCascadeService` = „EIN Einstieg für alle Flächen" (:28); die Ausgabe-Bindung liegt auf `cascade_runs.source_owner_type/_id`, nicht auf der `PlanningSession` (die ist bewusst owner-los, „erdet nichts").

**Zwei erlaubte Wege je Ausgabeform (explizit, kein Zufall):**
- **KI-Vollkaskade** (Power-Weg) → durch die Leitstelle.
- **Bestands-/Manuell-Pfad** (Alltags-Weg) → im Modul (Slot-Picker + `kapitelFreigeben` / Gericht-Picker / Zell-Zuweisung). Nutzt denselben Rahmen und dieselbe Ausgabe.

---

## 2. IST je Ausgabeform

| Form | Rahmen (A) | Erstellung (B) — Spine? | Zusammenbau (C) | Vertrag |
|---|---|---|---|---|
| **Foodbook** | `Foodbooks/Index` (Gerüst, Kickoff) | ✅ `vollKaskadeStarten` :253 → `owner_type='foodbook'` | Foodbook-Dokument/Präsentation | ✅ voll |
| **Speisekarte** | `Speisekarte/Index` (Rubriken→Frame :442) | ✅ `vollKaskadeStarten` :457 → `owner_type='speisekarte'` | Karten-Dokument | ✅ voll |
| **Speiseplan/GV** | `Speiseplan/Editor` (Wochen/Linien) | ✅ `vollKaskadeStarten` :346 → `owner_type='speiseplan'` (Zell-Fan-out) | Aushang-PDF | ✅ voll (Variante) |
| **Angebot** | `Angebote/Editor` (Brief/Menü) | ❌ **kein Kaskaden-Pfad** — nur Concepter-Bestands-Picker | Angebots-Dokument + `anProduktion` | ❌ **Silo** |

**Geteilt heute:** `starteKaskade` (Motor) · `PlanningSession` (Review-Wurzel) · `generation_params`-Leitplanken-Vererbung · `AiGatewayService`-Prompts · `ConceptGeneratorService`. **Nicht geteilt:** Angebot berührt Session/Cascade/DishIdea nicht; das DishIdea-Divergenz-Board nutzen real nur Foodbook-Kapitel + die Session.

### 2.1 Verifizierte Naht Modul ↔ Leitstelle (Code-geprüft 2026-08-18)

**Der Round-Trip ist für Foodbook/Speisekarte/Speiseplan geschlossen — das Vollkaskaden-Ergebnis landet automatisch im Modul-Inhalt, ohne manuelles Einhängen.** Schreib-Pfad: `GenerateConceptJob::attachToOutput` (:104 / :158–163) ruft je Owner `FoodbookService::addBlock('concept_ref')` bzw. `SpeisekarteService::addPosition('menue_ref')`; Speiseplan über `MaterializeSpeiseplanCellJob` → `SpeiseplanService::addEintrag`.

| Form | Hinweg Modul→Leitstelle | Rückweg Leitstelle→Inhalt | Lücke |
|---|---|---|---|
| Foodbook | ✅ `Foodbooks/Index:238` | ✅ `concept_ref`-Block automatisch | Attach-Härtung (E-P0) |
| Speisekarte | ✅ `Speisekarte/Index:457` | ✅ `menue_ref`-Position automatisch | Attach-Härtung (E-P0) |
| Speiseplan | ✅ `Speiseplan/Editor:346` | ✅ Zellen-Eintrag automatisch | — (bewusst ohne Concept-Zwischenschritt) |
| Angebot | ❌ | ❌ | beide Richtungen fehlen → E2 |

**Drei Naht-Härtungs-Befunde (neue Arbeit):**
1. **Still geschluckter Attach-Fehler** — `attachToOutput` steckt in try/catch (`GenerateConceptJob:165–167`): schlägt das Anhängen fehl, existiert das Konzept frei, aber NICHT im Kapitel/der Rubrik — ohne UI-Signal. Unsichtbarer Daten-„Verlust"-Pfad. **→ E-P0 (höchste Priorität, ganz vorne).**
2. **UX-Trennung „Speisen-Tab wirkt unplanbar"** (= Dominiques Eindruck): Der Speisen/Inhalt-Tab ist eine MANUELLE Kompositionsfläche (Bestands-Picker `+ Concept/Gericht einfügen` + Header/Text/Preis via `FoodbookService::addBlock`). Die KI-Befüllung sitzt im Planung-Tab hinter „Voll-Kaskade" und blendet per `redirect` in den Planung-Editor weg; die KI-„neu"-Slot-Befüllung im Modul ist zudem hart geblockt (`slotFuellen 'neu'`, #512). Der Content-Planungs-Teil im Modul fühlt sich darum tot an, obwohl der Round-Trip funktioniert. Entzerren → E1b; Tiefen-Ausbau → Werkstrang M (§6).
3. **Einbahn-Sprung ohne Kontext/Rückweg** (Code-verifiziert): `vollKaskadeStarten` legt eine owner-lose `PlanningSession` an und springt per `redirect()->route('foodalchemist.planung.index', ['session'=>…, 'open'=>1])` (Foodbook :257, Speisekarte :461, Speiseplan :350). Die Leitstelle zeigt aber NICHT, für welche Ausgabe sie plant, und hat KEINEN Zurück-Link ins Modul — `Planung/Index` liest `source_owner_type/_id` (die auf dem Lauf, nicht der Session, sitzen) gar nicht an. Teleport statt sichtbarer Round-Trip. **→ E1b.**

---

## 3. Etappen (die ausführliche Bau-Liste)

Jede Etappe ist eine eigene, shippbare Bau-Runde mit eigener Verifikation. Dieses Doc ist die Landkarte; die Etappen E2–E5 bekommen bei Baubeginn je einen Detail-Abschnitt. **Reihenfolge: E-P0 zuerst** (latenter Datenverlust-Bug, sofort), danach E0/E1 usw.

### E-P0 — Attach-Fehler nicht mehr still schlucken (SOFORT · latenter Datenverlust) · Status: ✅ gebaut + Sandbox-verifiziert 2026-08-20 (Branch `feat/spec40-umsetzung`, nicht deployt)
**Anlass:** `GenerateConceptJob::attachToOutput` (:104 / :158–163) steckt in try/catch (:165–167) — schlägt das Zurückschreiben ins Kapitel/die Rubrik fehl, existiert das Konzept frei, aber NICHT im Modul-Inhalt, **ohne jedes UI-Signal**. Kann schon heute in der demo zuschlagen (Foodbook + Speisekarte betroffen; Speiseplan nutzt den Job nicht). Der einzige echte Bug der ganzen Analyse — kein Konzept, sondern ein Datenverlust-Pfad.
**Bausteine:**
- Fehler im catch **loggen + als Nutzer-Signal/Meldung** sichtbar machen (nicht schlucken).
- **„Nachträglich einhängen"-Recovery:** ein attach-loses Konzept per Aktion ins Zielkapitel/die Rubrik hängen (reuse `FoodbookService::addBlock` / `SpeisekarteService::addPosition`).
- optional: einmaliger Retry vor dem Aufgeben.
**Verifikation:** simulierter Attach-Fail (z.B. ungültige containerId) erzeugt Log + Signal, das Konzept bleibt auffindbar + einhängbar; Pest-Test für den catch-Pfad; kein stiller Verlust mehr.
**Umsetzung 2026-08-20 (Branch `feat/spec40-umsetzung`):** `GenerateConceptJob::attachToOutput` — 1× Retry, dann `Log::warning` + `PlanningCascadeService::markAttachFailed` statt leerem catch (spiegelt `markFanoutFailed` #124: Konzept-Step bleibt `done`, Fehler + `pending_attach` in `deferred`). Recovery: `PlanningCascadeService::haengeKonzeptNach(Team,stepId)` (team-scoped via `ownedStep`, reuse addBlock/addPosition, löscht das Signal bei Erfolg) + Livewire-Aktion `Planung/Index::haengeKonzeptNach` + Amber-Signal & „nachträglich einhängen"-Button in `step-zeile.blade.php`. Headless: `attach_fehler` in `laufStatus`-Step-Projektion (MCP `planung_kaskade.GET`). Pest: 4 neue Tests (Catch-Pfad, `markAttachFailed`, Recovery-Erfolg, Cross-Tenant) — PlanningCascadeTest 113/113 grün.

### E-K — Kaskaden-Härtung: Zwei-Achsen-Entflechtung + Defekt-Cluster · Status: ✅ abgeschlossen + auf demo (verifiziert 2026-08-20)
> Live-Testfahrt 2026-08-18 fand 5 reale Kaskaden-Defekte (D1–D5), die den Kern-Wert untergraben. **Interaktiv bauen** (Kern-Entscheidungslogik + 84 GL-04-Goldens) — NICHT von der autonomen Routine blind.
>
> **Erledigt (git-verifiziert 2026-08-20):** D1 (`cfafddc`), D5 (`38ff806`), D4 (`b0d6dce`), D3 (`6281fac`) via Merge `80e7604`; D4-Wasser (`742c9fb`); D2c stiller Binde-Fehler (`a88f325`) — **alle in origin/main** (Tip `67f0512`) UND **auf demo** (`3b30f75`, via Kitchen-Wall-Deploy nachgezogen). An E-K ist nichts mehr zu bauen.

**Verbindlicher Zwei-Achsen-Vertrag (ENTSCHEIDUNG Dominique 2026-08-18 — löst D5):**
- **`creative_mode` = Datenbank-Treue (reuse ↔ invent).** Datenbank = bestehende Gerichte/Rezepte/GPs maximal wiederverwenden; Voll kreativ = frei neu erfinden; Hybrid = Bestand bevorzugt, erfindet wo nötig. Steuert `recipe_ref` (bestehendes Rezept referenzieren) vs. `basisrezept_anlegen` (neu). **Steuert NICHT GP-vs-Rezept.**
- **`convenience` = Handwerkstiefe (make ↔ buy).** from_scratch = selbst kochen → Sub-Zubereitung wird Sub-Rezept; voll_convenience = fertig kaufen → Sub-Zubereitung darf (Convenience-)GP sein; teil = dazwischen. **Steuert die Zerlegung Sub-Rezept-vs-GP.**
- **Invariante:** Eine klare Sub-Zubereitung (jus/fond/gel/creme/duxelles/… oder Rolle komponente/beilage im VK-Gericht) ist standardmäßig ein REZEPT. Sie darf NUR bei `convenience=voll_convenience` zum GP werden. **`creative_mode` darf sie NIE zu einem GP flachen** — „Datenbank" heißt „bestehendes Rezept referenzieren", nicht „GP einsetzen". (Genau das war der Bug: „hybrid" flachte Jus/Gel zu GPs.)

**Fixes (auf dem Vertrag):**
- **D5 (Achsen-Entflechtung, Kern)** — `RecipeGeneratorService::generiere` (:217–299): (a) starke Sub-Marker + Rolle komponente/beilage NICHT mehr durch `! $direktArtikel` unterdrücken; (b) neutral-`convenience`-Zweig (:253) darf eine Sub-Zubereitung nicht zu GP flachen (nur voll_convenience); (c) `creative_mode` in den reuse-vs-invent-Zweig verdrahten (Datenbank→`recipe_ref` bevorzugen, Voll kreativ→`basisrezept_anlegen`) — dazu muss `creative_mode` in die Generator-`$parameter` gereicht werden.
- **D1 (Marker-Lücke)** — „duxelles" (+ farce/salpicon/…) in `MatchHeuristics` (:51/:69/:88) + Prompt-Beispiellisten (`config:565`/`:470`); `?? `-Fallback (:266) so, dass ein fehlendes LLM-Flag die Namens-Heuristik nicht komplett abschaltet.
- **D4 (Basis-Form-Präferenz)** — positiver Rang für frisch+ganz+ohne-Marke in `MatchHeuristics::variantRankResolved` (:286), parameter-unabhängig; `geachtelt/mini/gemischt` in `CUT_FORM_MARKERS` (`TokenEngine:27`); Wasser-Alias/Platzhalter zulassen (`IngredientMatchService:531`).
- **D3 (Auto-Derivat §11.2)** — Mint-Pfad, der bei Nebenprodukten `createGp(is_derivat=1, derivat_von_gp_id, requires_la=0)` setzt (`LaFirstGpService`/`RecipeOneShotService`); LA-loses Roh-Nebenprodukt sichtbar als Sourcing-Lücke statt still unbepreist.
- **D2 (Persistenz)** — Save nicht als Voll-Rollback bei 1 ungültiger Zeile (`RecipeService:610/653`); „neu generieren"/„verwerfen" edit-erhaltend statt Hart-Delete (`PlanningCascadeService:1234/1280`); still geschluckten Binde-Fehler (:1416) sichtbar (mit E-P0 gemeinsam).

**Verifikation:** Sandbox-Pest inkl. der **84 GL-04-Goldens grün** (dürfen NICHT kippen); neue Tests je Achse (jus bleibt Rezept bei from_scratch/standard/Datenbank, wird GP nur bei voll_convenience; Basis-Form gewinnt gegen mini/geachtelt/Bio-Wasser); Browser-Smoke: derselbe Fond erzeugt Sub-Rezepte statt GPs.

### E0 — Analyse→Skizzen-KI-Divergenz (klein, unabhängig) · Status: ✅ gebaut + Sandbox-verifiziert 2026-08-20 (Branch `feat/spec40-umsetzung`, nicht deployt)
> **Umsetzung:** `IdeenService::kiDivergenzSession(Team,sessionId,?analyse,anzahl=5,?creativeMode)` — spiegelt `kiDivergenz`, owner=`planning_session`, Seed=Analyse-Text (`kapitel_beschreibung`), `creative_mode` als JSON-Kontext-Hinweis (Gateway hängt `$context` an den Prompt — kein Registry-Edit), `source_meta.quelle='ki_divergenz_session'`, keine Erdung. `Planung/Index::skizzenAusAnalyse(IdeenService)` (Guard Team+Session, Analyse nicht leer, graceful ohne Provider) + Sparkles-Button im Analyse-Tab (`@click="tab='skizzen'"`). Pest: 4 neue (Anlage, leere Analyse wirft, kein-Provider graceful, Cross-Tenant) — IdeenServiceTest 16/16; Render-Gate PlanungLeitstelleTest 132/132.
**Ziel:** Der Analyse-Tab der Leitstelle löst sein UI-Versprechen ein — aus dem Analyse-Text per KI mehrere Gericht-Skizzen ableiten (Phase-A→B-Übergang in der Leitstelle selbst). Erster konkreter Baustein des Modells.
**Bausteine:**
- `IdeenService::kiDivergenzSession(Team, int $sessionId, ?string $analyse, int $anzahl=5, ?string $creativeMode=null): array` — spiegelt `kiDivergenz` (:266), aber Owner = `planning_session_id`, Seed = Analyse-Text (`kapitel_beschreibung`-Prompt-Slot), `creative_mode` als Kontext-Hinweis (macht den bisher toten Parameter wirksam, ohne die geteilte Registry-Zeile `foodbook.kapitel_ideen` anzufassen), `source_meta.quelle='ki_divergenz_session'`. Kern (`AiGatewayService::propose` + Parse/Insert-Schleife) 1:1 wiederverwendet.
- `Planung/Index::skizzenAusAnalyse(IdeenService $svc)` — Guard (Team+Session), Analyse nicht leer, `try/catch` (Sandbox ohne Provider → graceful), `$this->meldung`.
- Blade: Sparkles-Knopf im Analyse-Tab unter der Textarea (Loading-Swap-Muster `kiEinleitung`), `@click="tab='skizzen'"` nach dem Klick.
- Pest: Fake-Provider → N session-gebundene Skizzen; kein Provider → graceful; Guards; Tenancy.
**Code/Vault/demo:** reiner Code (PR). demo-Smoke off-peak (je Klick 1 OpenAI-Call).
**Bewusst NICHT:** kein RAG-Bestands-Grounding (nur Prompt-Hinweis), kein „Go"-Umbau, `kiDivergenz`/`kiDivergenzConcept` unangetastet.

### E1 — Vertrag festschreiben · Status: ✅ erledigt (dieses Doc, committet) — §4-Grundsatzfragen s.u. (autonom nach Empfehlung entschieden)
**Ziel:** Den Round-Trip als verbindlichen Vertrag dokumentieren, damit künftige Arbeit nicht wieder Silo-artig auseinanderläuft.
**Bausteine:** dieses Spec-Doc; Composer-Rolle klarstellen (bleibt Pairing); die zwei erlaubten Wege (KI vs. Bestand) je Modul explizit machen; Verlinkung mit Spec 38 (Zielarchitektur) + Spec 19 (Foodbook-Leitstelle) + Spec 31 (Speiseplan-GV).
**Verifikation:** Doc geschrieben + verlinkt; §4-Entscheidungen mit Dominique getroffen und hier vermerkt.

### E1b — Sprung sichtbar machen: Owner-Kontext + Rückweg + Speisen-Tab (UX §2.1-Befund 2+3) · Status: ✅ gebaut + Sandbox-verifiziert 2026-08-20 (Branch `feat/spec40-umsetzung`, nicht deployt)
> **Umsetzung:** `PlanningCascadeService::ownerKontext(Team,sessionId)` löst über den jüngsten Lauf mit `source_owner_type/_id` den Ausgabe-Owner auf (Foodbook=`label`/Speisekarte=`name`/Speiseplan=`name`) + Rück-Route mit Deep-Link-Param (`fb`/`sk`/`sp`, aus den `#[Url(as:…)]`-Properties der Index-Komponenten); `null` bei freier Cockpit-Planung. `Planung/Index::render` reicht `ownerKontext` durch → **Owner-Banner** oben im Editor („Planung für Foodbook ‚Adler'" + Zurück-Link, beide Richtungen sichtbar). **Speisen-Tab entzerrt:** Foodbook-Inhalt-Empty-State weist auf den KI-Weg (Voll-Kaskade) hin. Pest: 3 neue (ownerKontext Foodbook/kein-Owner/jüngster-Owner-Lauf-gewinnt) — PlanningCascadeTest 116/116, Render-Gate PlanungLeitstelleTest 132/132 + FoodbookUiTest grün.
**Ziel:** Aus dem Einbahn-Teleport einen **sichtbaren Round-Trip** machen — man weiß in der Leitstelle, WOFÜR man plant, und findet zurück. (Attach-Bug ausgelagert → E-P0.)
**Mechanik-Kontext:** `vollKaskadeStarten` legt eine owner-lose `PlanningSession` an und springt per `redirect()->route('foodalchemist.planung.index', ['session'=>…])`. Der Owner steckt NICHT auf der Session, sondern auf dem Lauf (`FoodAlchemistCascadeRun.source_owner_type/_id`, aufgelöst über `planning_session_id`).
**Bausteine:**
- **Owner-Kontext-Banner** oben in der Leitstelle: `Planung/Index` löst über den aktiven Lauf zur Session den Owner auf und zeigt „Planung für Foodbook ‚Adler' · Kapitel 16.3.2" (bzw. Speisekarte/Speiseplan/Angebot). Owner-neutral, da alle Formen `source_owner_type/_id` tragen.
- **Zurück-Link** aufs Ursprungsmodul: Route zurück auf `foodbooks`/`speisekarte`/`speiseplan`(/`angebote`) + Anker aufs Kapitel/die Rubrik. Schließt die Einbahn.
- **Speisen/Inhalt-Tab entzerren:** sichtbar machen, dass KI-Befüllung über „Voll-Kaskade" läuft (Hinweis/Verlinkung statt gefühlter Sackgasse); Redirect abmildern. Tiefen-Ausbau → Werkstrang M (§6).
**Verifikation:** nach dem Sprung zeigt die Leitstelle Owner + Kapitel; der Zurück-Link landet wieder im richtigen Modul-Kontext; Speisen-Tab kommuniziert den KI-Weg.

### E2 — Angebot andocken (Symmetrie) · Status: ✅ gebaut + Sandbox-verifiziert 2026-08-20 (Branch `feat/spec40-umsetzung`, nicht deployt)
> **Umsetzung:** `'offer'` in `PlanningFrame::OWNER_TYPES` + `PlanningFrameService::resolveOwner` (→ `frameFor` generisch) + `starteVollkaskade`-Guard + `vollkaskadeSlots` (Container = die Angebots-ID selbst, kein Zwischen-Container). **Rückweg:** `GenerateConceptJob::attachEinmal` + `haengeKonzeptNach` (E-P0-Recovery) hängen für `owner_type='offer'` via `AngebotService::referenziereConcept` (Pivot `foodalchemist_offer_concept`; das erzeugte Standalone-Konzept `offer_id=NULL` ist referenzierbar). `ownerKontext` (E1b-Banner) unterstützt offer (Route `angebote.index`, Param `sel`). **Editor:** `Angebote/Editor::vollKaskadeStarten` + „Voll-Kaskade (KI)"-Button — da Angebote (noch) KEINE eigene Gerüst-Review-UI haben, wird bei fehlendem Gerüst EINMAL aus dem Angebots-Kopf (Anlass/Gäste) auto-strukturiert (`geruestAusBriefFuerOwner`); Review passiert an den erzeugten Menüs in der Leitstelle (Sammel-Review). Pest: 3 neue (Offer-Vollkaskade → Steps+Job attach=offer, Recovery→Pivot, ownerKontext offer) — PlanningCascadeTest 119/119, AngebotAnProduktionTest + EditorOeffnenVertragTest grün.
> **Nachtrag 2026-08-20:** die Angebots-Gerüst-Review-UI wurde nachgebaut (Editor „Gerüst"-Tab: Slots hinzufügen/löschen + KI-Kickoff); die Auto-Struktur bleibt Fallback, wenn der Mensch kein Gerüst anlegt.
**Ziel:** Das Angebot bekommt denselben Round-Trip wie die anderen drei — der einzige echte Silo verschwindet.
**Bausteine:**
- `'offer'` ergänzen in: `FoodAlchemistCascadeRun::SCOPES` + `source_owner_type`-Semantik (:31), `FoodAlchemistPlanningFrame::OWNER_TYPES` (:22).
- Owner-Zweig in `PlanningCascadeService::starteVollkaskade`/`vollkaskadeSlots` (:271/:335): Angebots-Positionen/Concepts → Slots.
- **Rückweg schließen:** `attachToOutput`-Zweig im `GenerateConceptJob` (~:158) für `owner_type='offer'` — z.B. `AngebotService::referenziereConcept`, damit erzeugte Konzepte automatisch am Angebot landen (spiegelt Foodbook/Speisekarte). Ohne diesen Zweig wäre nur der Hinweg da.
- `Angebote/Editor::vollKaskadeStarten` analog zu Foodbook/Speisekarte/Speiseplan → `starteKaskade($team,'vollkaskade',$session,mode,['owner_type'=>'offer','owner_id'=>$angebotId])`.
- **Ziel-FK existiert schon:** `FoodAlchemistConcept.offer_id` (:58) — erzeugte Konzepte hängen direkt am Angebot. **Kein Model-Umbau am Ziel.**
**Code/Vault/demo:** reiner Code (PR) + MCP-Lockstep prüfen (Angebot-Tools). Pest: Angebots-Vollkaskade legt Session+Run mit `source_owner_type='offer'` an, Konzepte tragen `offer_id`.
**Risiko:** Angebots-Menüsubstanz sind offer-lokale Concepts/Pakete — Slot-Mapping muss die Pivot `foodalchemist_offer_concept` respektieren.

### E3 — Rückkopplung 1: Ergebnis → Wissen/Trend (höchster Hebel) · Status: 🚫 BLOCKIERT (2026-08-20) — RAG-Voraussetzung fehlt
> **Blocker:** setzt den RAG-Branch `feat/rag-autoindex-recall` (Auto-Index + semantisches Finden) in main voraus — der existiert im aktuellen Repo nicht (weder lokal noch origin/main). Bis RAG gelandet ist, NICHT baubar. Danach: Rückkanal freigegebener Konzepte/Gerichte in den Wissens-/Trend-Recall (als Recall/Inspiration, nie finaler Ranker, nie Pairing-Zwang).
**Ziel:** Den Kreis schließen — die KI lernt aus dem, was tatsächlich gebaut + freigegeben wurde, nicht nur aus dem statischen Wissenskorpus.
**IST:** Der einzige echte automatische Loop ist Pool-Embedding-Reuse (`ConceptEmbeddingObserver`, `RecipeEmbeddingObserver` → `GenerationContextService`). Wissen/Trend → Planung ist strikt einbahnig.
**Bausteine (Richtung, Detail bei Baubeginn):** Rückkanal von freigegebenen Konzepten/Gerichten in den Wissens-/Trend-Recall (Brücke, nicht Anker). **Rollen-Invariante wahren:** als Recall/Inspiration, nie als finaler Ranker, nie als Pairing-Zwang. **Kopplung:** baut auf dem RAG-Branch `feat/rag-autoindex-recall` auf (Auto-Index + semantisches Finden) — zuerst RAG landen lassen.
**Verifikation:** ein freigegebenes Konzept taucht bei semantisch verwandtem Folge-Brief als Reuse-/Inspirations-Kandidat auf; Anti-Marker-Schutz greift; die Matcher-Goldens kippen nicht.

### E4 — Rückkopplung 2: Lücken-Signal aus dem Cockpit + Favoriten-Vorschlag · Status: ✅ gebaut + Sandbox-verifiziert 2026-08-20 (Branch `feat/spec40-umsetzung`, nicht deployt)
> **Umsetzung:** `PlanningCascadeService::meldeSourcingLuecken(Team,stepId)` — GPs eines erzeugten Rezept-Steps OHNE beschaffbaren Lead-LA (`FavoriteGpService::verfuegbarkeit`-Bucket `luecke`) je als `SignalTyp::SortimentsLuecke` melden (`meldeLuecke`, idempotent). `favoritKandidatenFuerStep(Team,stepId)` — noch nicht gepinnte GPs des Steps als Vorschlag. Livewire `sourcingLueckenMelden` / `favoritVorschlaegeLaden` (on-demand, kein Render-Query) / `favoritPinnen` (mensch-gated, nur team-eigene GPs) + Buttons/Chips in `step-zeile.blade`. Pest: 3 neue (Lücke→Signal, Favoriten-Filter, Cross-Tenant) — PlanningCascadeTest 122/122, Render-Gate 132/132.
**Ziel:** Sichtbare, schnelle Feedback-Kanäle direkt aus der Leitstelle.
**Bausteine:**
- **Lücken-Signal:** `PairingInspirationService::meldeLuecke` → `SignalTyp::SortimentsLuecke` (:142) wird heute **nur** aus `Foodbooks/Index:309` gerufen. In der Leitstelle eine „Lücke melden"-Aktion verdrahten, wenn die Kaskade eine Sortiments-/Beschaffungslücke trifft (Nordstern „Lücke ist Signal, kein Fehler").
- **Favoriten-Vorschlag:** `FavoriteGpService` rankt schon aus Nutzung, aber Pinnen ist manuell. Freigegebene Läufe schlagen Favoriten-Kandidaten vor (mensch-gated bleibt).
**Verifikation:** Kaskaden-Lücke erzeugt ein Signal im Signale-Cockpit; Favoriten-Vorschlag erscheint nach Freigabe, Pin bleibt manuell.

### E5 — Rückkopplung 3: Skizzen-/Analyse-Lernen (optional) · Status: ✅ gebaut + Sandbox-verifiziert 2026-08-20 (Branch `feat/spec40-umsetzung`, nicht deployt)
> **Umsetzung (leichtgewichtig):** die Skizzen-Karte zeigt jetzt „daraus entstanden: ‹Artefakt-Name›" — `render()` führt je Skizzen-Lauf (`origin_dish_idea_id`) den materialisierten Step-Label mit (ein Batch-Query über die schon gesammelten Läufe, kein zusätzlicher Round-Trip je Karte); `$skizzeLaufBadge` hängt es ans Status-Badge. Über das reine Status-Badge hinaus ein sichtbares Lern-/Rückblick-Signal. Render-Gate PlanungLeitstelleTest grün.
**Ziel:** Die Divergenz-Ebene lernt aus Ergebnissen.
**Bausteine:** `origin_dish_idea_id` über das Status-Badge hinaus als Lern-Signal (aus dieser Skizze wurde X, mit welchem Erfolg); Analyse-Tab-Rückblick „daraus entstanden".
**Verifikation:** Skizzen-Karte zeigt den materialisierten Bezug; Analyse-Rückblick listet erzeugte Konzepte.

### E-M — Modul-seitige Planungs-Tiefe (Top-down) · Status: offen → Detail in §6
Querschnitt-Werkstrang: den Content-Planungs-Teil IM Modul (Phase A/C) zu einem echten Top-down-Fluss ausbauen — zuerst Speisekarte (Detail-Plan §6), dann als Muster auf den Foodbook-Speisen-Tab übertragen (adressiert §2.1-Befund 2). Bewusst unter Foodbook-Niveau (kein Phasen-Gating, keine Kaskaden-Pflicht).

---

## 4. Offene Entscheidungen

**✅ ENTSCHIEDEN (Dominique 2026-08-18) — Zwei-Achsen-Vertrag (löste die D5-Frage):** `creative_mode` = Datenbank-Treue (reuse↔invent, steuert NICHT GP-vs-Rezept); `convenience` = Handwerkstiefe (make↔buy, steuert die Zerlegung); eine Sub-Zubereitung ist immer ein Rezept außer bei `voll_convenience`. Umsetzung → Etappe **E-K**. — Die drei folgenden Fragen bleiben offen: (mit Dominique zu klären, dann hier als Entscheidung vermerken)

1. **Round-Trip als verbindlicher Vertrag?** Leitstelle = Motor, Zusammenbau bleibt im Modul, Composer bleibt Pairing. *(Empfehlung: ja — gebaut + sauber.)*
2. **Verstreute Tür lassen** (Entry im Modul, weil der Rahmen dort lebt) oder Einheits-Vordertür in der Leitstelle? *(Empfehlung: lassen; nur das Cockpit vereinheitlichen.)*
3. **Rückkopplungs-Priorität:** E3 (Ergebnis→Wissen/Trend, Hebel + RAG-Kopplung) zuerst — oder E4-Lücken-Signal (schnellster sichtbarer Nutzen)?

---

## 5. Referenzen
- [Spec 38 — Roadmap Planung-Leitstelle](38_Roadmap_Planung_Leitstelle.md) (Zielarchitektur, Nordstern)
- [Spec 37 — KI-Erstellen Typ/Niveau](37_Spec_KI_Erstellen_Typ_Niveau.md)
- Spec 19 (Foodbook-Leitstelle A–Z, Skizzen-Ebene/Kapitel-Go) + Spec 31 (Speiseplan-GV) — nicht mehr in `docs/PLANUNG/` (Doku-Bereinigung 2026-07-28); Kern lebt in `FoodbookService` bzw. `SpeiseplanService`
- RAG: Branch `feat/rag-autoindex-recall` (Auto-Index + semantisches Finden) — Voraussetzung für E3
- Regelwerke: `07_WISSEN/07.01_…/Regelwerke/Regelwerk_Basisrezepte.md`, `Regelwerk_Grundprodukte.md`
- Code-Spine: `PlanningCascadeService` (`starteKaskade` :69, `starteVollkaskade` :266) · `FoodAlchemistPlanningSession` · `FoodAlchemistCascadeRun` (`source_owner_type`) · `FoodAlchemistPlanningFrame` (`OWNER_TYPES`)
- Naht (§2.1): `GenerateConceptJob::attachToOutput` (:104/:158–163) · `FoodbookService::addBlock` (:1242) · `SpeisekarteService::addPosition` · `MaterializeSpeiseplanCellJob` → `SpeiseplanService::addEintrag`

---

## 6. Werkstrang M — Modul-seitige Planungs-Tiefe (Top-down): Speisekarte-Editor-Ausbau

> **Herkunft:** von Dominique vorformulierter, code-verifizierter Plan (noch nicht umgesetzt), hier eingegliedert, weil er dasselbe Ziel trifft. Die ursprünglich vorgesehene Doc-Nummer „38" ist inzwischen belegt (Roadmap Planung-Leitstelle) — der Plan lebt in dieser Spec.
>
> **Warum hier:** Er adressiert direkt §2.1-Befund 2 („Content-Planung im Modul wirkt tot"). Der Round-Trip (§1) legt Phase A (Rahmen) + C (Zusammenbau) INS Modul — dieser Werkstrang macht genau die Modul-Seite zu einem echten Top-down-Fluss. **Zuerst Speisekarte; das Muster überträgt sich danach auf den Foodbook-Speisen-Tab.**

### Kontext
Der Speisekarte-Editor (`Speisekarte/Index`) ist heute ein flacher, linearer Baukasten: Rubrik anlegen → Gericht/Menü per reiner Namenssuche anhängen → Wording/Preis inline → Branding → 5-Punkt-Checkliste. Verglichen mit dem Foodbook fehlt die Planungsführung; besonders das Gericht-Einfügen ist arm (nur Suchfeld, hartes Limit 15, keine Kategorien/Sortierung).

**Ziel (User):** ein schlanker Top-down-Fluss „vom Groben zum Kleinen" — erst Kontext/Zielgruppe, dann Struktur, dann Gerichte, dann Prüfen — bewusst **unter** Foodbook-Niveau (kein Phasen-Gating, keine Kaskaden-Pflicht, keine KI-Slot-Maschinerie). „Der Standard, den man zum Schreiben einer Speisekarte braucht." Erst Speisen; Getränke/Wein-Detail = separater Folge-Schritt (Phase F).

**Tragende Erkenntnis (code-verifiziert):** ~80 % UI-Surfacing vorhandener Service-/Schema-Fähigkeiten, kaum Neubau. Keine konkurrierende Spec, keine neue Migration (`kundentyp`, `default_niveau`, `default_convenience`, `writing_style_id`, `phase`, `type ∈ …|header|text|spacer|image`, `variant_group_id` liegen alle).

### Leitprinzip & Abgrenzung
- Nicht ins Foodbook mergen — die drei Ausgabeformen bleiben getrennt (Spec 33 §7); gezielt owner-neutrale Services/Traits mitnutzen.
- Fachlogik in Services, Livewire nur validieren+delegieren; MCP-Tools ziehen je neuer Action mit; jede neue Action ≥1 negativer Cross-Tenant-Test.

### Phase A — Kontext-Schritt + Tab-Neuordnung (billig, Surfacing) · Status: ✅ gebaut 2026-08-20 (Branch `feat/spec40-umsetzung`)
> **Umsetzung:** Tabs `kontext → aufbau → branding → leitstelle` (init=kontext); `Speisekarte/Index` Properties `kundentyp/niveau/convenience/writingStyleId` + Hydration in `waehle()` + Persist in `speichern()` (Service-`FELDER` trug die Spalten schon); Kontext/Leitplanken-Karte im neuen Tab (Kundentyp/Schreibstil/Niveau/Convenience), `schreibstile` aus `FoodAlchemistWritingStyle`. MCP: neues `foodalchemist.speisekarten.PUT` (Kopf-Update, schließt Lockstep-Lücke) + registriert. Pest: SpeisekarteUiTest (persist+hydrate) + SpeisekarteMcpTest (PUT + Cross-Tenant), 6/6 grün. **Bewusst offen:** der `struktur|positionen`-Tab-Split — die Positionen sind heute je Rubrik im `rubrik`-Partial eingebettet, ein Split ist Umstrukturierung (kein Surfacing) → späterer Ausbau, `aufbau` bleibt vorerst ein Tab.
Editor-Tabs von `aufbau | stammdaten | branding | leitstelle` auf `kontext → struktur → positionen → branding → leitstelle`. „Aufbau" spaltet in Struktur (Rubrik-Baum) + Positionen (Picker + Liste); „Stammdaten" → Kontext, nach vorn.
- `Index.php`: Properties `kundentyp`, `niveau` (→`default_niveau`), `convenience` (→`default_convenience`), `writingStyleId`; Hydration in `waehle()` (:80), Persistenz in `speichern()` (:111). Kein Service-Change — `SpeisekarteService::FELDER` (:44) trägt die Felder.
- Selects: Niveau `buergerlich|gehoben|fine_dining`, Convenience `from_scratch|teil_convenience|voll_convenience`; Writing-Style aus `FoodAlchemistWritingStyle::visibleToTeam($team)->where('is_inactive',false)`.
- Blade: Tab-Map + `x-show`-Keys; Kontext-Panel um die 4 Felder. Wirkt ohne weitere Verdrahtung als Default nach unten (`kiWordingVorschlag`/`kiKartenText` lesen `default_niveau`/`kundentyp` schon als Leitplanken).
- „Anlass": keine neue Spalte — auf `karten_typ` + `description`/`note` abbilden. `phase`-Spalte optional als Fortschritts-Stempel, kein Gate.
- MCP: neues `speisekarten.PUT` (Kopf-Update) — schließt Lockstep-Lücke (heute nur create).

### Phase B — Reicher Gericht-Picker (billig, Kern des Wunsches) · Status: ✅ gebaut 2026-08-20 (Branch `feat/spec40-umsetzung`)
> **Umsetzung:** `Speisekarte/Index` Facetten-Properties `pickerHauptgruppe`/`pickerDishClass` + `pickerWaehleHg`/`pickerWaehleKlasse` (Toggle); `render()` ruft `gerichtKandidaten($team,$suche,50,$hg,$klasse)` (Limit 15→50) + liefert `pickerHauptgruppen` (`SalesRecipeService::dishMainGroups`) + `pickerUntergruppen` (`FoodAlchemistDishClass` je HG). `gerichtKandidaten` gibt additiv `dish_class_id` + eager `dishClass:diet_form` mit (Diät-Label je Treffer; andere Aufrufer unberührt). `rubrik.blade`: Facetten-Chips (Hauptgruppe → Unterklasse) + Diät-Label je Treffer. „+ bleibt offen": `positionAusGericht`/`positionAusMenue` resetten Suche/Facetten NICHT mehr → mehrere Gerichte hintereinander. Pest: SpeisekarteUiTest Phase-B-Flow (Facette filtert, Picker bleibt offen), 3/3 grün. Statt 2-Spalten-Layout: kompakte Facetten-Chips (passt in den Dropdown-Picker; gleicher Filter-Wert).
Picker ruft `gerichtKandidaten` ohne Facetten (`Index.php:397`); Service kann mehr: `gerichtKandidaten($team,$suche,$limit,$hauptgruppe,$dishClassId)` (`SpeisekarteService.php:465`). Vorbild: Foodbook-Picker `foodbooks/index.blade.php:1061`.
- `Index.php`: `pickerHauptgruppe`, `pickerDishClass`; `pickerWaehleHg`, `pickerWaehleKlasse`. `render()` liefert `pickerHauptgruppen` (`SalesRecipeService::dishMainGroups`) + `pickerUntergruppen` (`FoodAlchemistDishClass…where('dish_main_group_id',$hg)`). Limit 15→50.
- `rubrik.blade.php:81`: zweispaltiges Foodbook-Layout (Klassenspalte + Trefferliste); je Treffer Preis (`$g->sales_net`) + `diet_form`-Label. Allergen-Kürzel NICHT je Treffer (N Live-Aggregationen; stehen ohnehin in Vorschau/Dokument).
- „+ bleibt offen": `positionAusGericht` (:269) `pickerSuche` nicht resetten → mehrere Gerichte hintereinander. Kein Service-/MCP-Change (Lesepfad).

### Phase C — Umsortieren + Verschieben (gemischt: Reorder = Surfacing, Move = Neubau) · Status: ✅ gebaut 2026-08-20 (Branch `feat/spec40-umsetzung`)
> **Umsetzung:** neu `SpeisekarteService::movePosition(Team,posId,newSectionId)` (der „echte Neubau" — `section_id` nicht in POSITION_FELDER; team-scoped, beide Rubriken selbe Karte, `position=max+1` der Ziel-Rubrik, transaktional). Livewire `positionHochRunter`/`rubrikHochRunter` (Swap-mit-Nachbar über `reorderPositionen`/`reorderRubriken`) + `positionInRubrik` (movePosition) + `swapNachbar`-Helper. `rubrik.blade`: ▲▼ je Position + je Rubrik, „In Rubrik verschieben"-Select im Edit-Panel. **Statt D&D** (rekursive Drag-Scopes = Falle) bewusst hoch/runter-Buttons + Move-Select (robust, testbar) — D&D bleibt optionaler UX-Ausbau. MCP: `speisekarte_positionen.MOVE` + `.REORDER` + `speisekarte_rubrik.REORDER` (registriert). Pest: SpeisekarteUiTest (hoch/runter/move/cross-card-guard) 5/5 + SpeisekarteMcpTest (3 Tools + Cross-Tenant) grün.
Reorder-Services fertig ohne UI: `reorderRubriken` (:312), `reorderPositionen` (:404), `moveRubrik` (:295). D&D-Muster `foodbooks/index.blade.php:889` via Trait `ReordersLists`.
- `Index.php` (Trait `ReordersLists`): `rubrikVerschiebenAuf`, `rubrikHochRunter`, `positionVerschiebenAuf`, `positionHochRunter`.
- **Echter Neubau — Position zwischen Rubriken schieben:** `section_id` fehlt in der Positions-Whitelist (`SpeisekarteService.php:330`), `position` würde nicht neu vergeben. → neue Methode `movePosition(Team, int $positionId, int $newSectionId)` (team-scoped, beide zur selben `menu_card_id`, `section_id` setzen + `position = max+1` der Ziel-Rubrik, transaktional); Livewire `positionInRubrik(...)`.
- Fallen (verifiziert): `wire:key` stabil (`rubrik.blade.php:6,18`). Rekursive Rubrik-Einbettung → (a) je Rubrik eigener `x-data`-Drag-Scope, (b) `@drop` muss Rubrik-ID mitgeben (Foodbook nutzt ein einzelnes `selectedKapitelId`, fehlt hier).
- MCP: `speisekarte_positionen.MOVE` + `.REORDER`, `speisekarte_rubrik.REORDER`. Cross-Tenant-Tests für `movePosition`, `reorder*`, `moveRubrik`.

### Phase D — Layout-Blöcke + Wahl-Gruppen (billig im Editor + Druck-Renderer) · Status: ✅ gebaut 2026-08-20 (Branch `feat/spec40-umsetzung`)
> **Renderer-Nachtrag 2026-08-20 (auf User-Entscheidung gebaut):** die „A oder B"-Gruppierung erscheint jetzt AUCH im Druck/Vorschau — `dokumente/speisekarte.blade` + `partials/vorschau.blade` rendern einen „oder"-Trenner zwischen aufeinanderfolgenden Positionen derselben `variant_group_id` (additiv, keine dokumentDaten-Umstrukturierung → SpeisekarteDokumentTest stabil). Pest: 2 neue Renderer-Tests (grouped→„oder", ungrouped→kein Trenner) grün. Edit-Panel-Hinweis auf „benachbart platzieren" umgestellt.
> **Renderer-Entscheidung (§6-Bestätigung, ursprünglich):** Editor-Seite voll + `variant_group_id` daten-fertig in `dokumentDaten` emittiert. **Umsetzung:** `Speisekarte/Index::layoutBlockNeu(rubrikId,type)` (header/text/spacer via addPosition) + `variantGruppeVorschlag` (`nextVariantGroupId`); Edit-Panel erweitert (editLabel für Überschrift, editVariantGroupId + Vorschlag-Button für gericht_ref/menue_ref); ✎ jetzt auch für header/text. `rubrik.blade`: „+ Ü / + Text / + ␣"-Buttons im Rubrik-Kopf, typ-abhängige Edit-Felder, Editor-only-Hinweis. `dokumentDaten` emittiert `variant_group_id`. Pest: SpeisekarteUiTest (Layout-Blöcke + Wahl-Gruppe persist + dokumentDaten-Emit) 6/6.
Schema/Service tragen: `type ∈ header|text|spacer`, `variant_group_id`, `height`; `nextVariantGroupId` (:415).
- `Index.php`: `layoutBlockNeu(int $rubrikId, string $type)` auf `addPosition`; `editVariantGroupId` ins Edit-Panel (Vorschlag via `nextVariantGroupId`). `image` vertagt (Media-Upload je Position).
- `rubrik.blade.php`: Rubrik-Kopf (:11) Buttons `+ Überschrift / + Text / + Abstand`; Wahlgruppen-Feld im Edit-Panel.
- **Ehrlich:** Dokument/Vorschau gruppiert `variant_group_id` heute NICHT (`dokumentDaten`-Render ~:542 fehlt es). Der „A oder B"-Effekt ist ein separater Renderer-Schritt — mitbauen oder als „Editor-only vorerst" kommunizieren (sonst legt der User Wahlgruppen an, die im Druck nicht erscheinen).

### Phase E — Planungshilfe „was fehlt der Karte noch" (billig, read-only, schlank) · Status: ✅ gebaut 2026-08-20 (Branch `feat/spec40-umsetzung`)
> **Umsetzung:** die bestehende Checkliste (`SpeisekarteLeitstelleService::checkliste`, im `LeitstelleRail`) breiter überschrieben („Was fehlt der Karte noch?"); `LeitstelleRail::render` reicht zusätzlich `CoverageService::coverage($team,'speisekarte',$karteId)` durch, das owner-neutrale Partial `planning/partials/coverage-panel` wird **nur bei `hat_geruest=true`** gerendert (kein Frame-Zwang). Bewusst DRAUSSEN: gerankte KI-Vorschläge/Marge-Solver. Pest: SpeisekarteUiTest (Checkliste immer, Coverage nur mit Frame+Slot) 7/7.
- Default: vorhandene Checkliste breiter zeigen — `SpeisekarteLeitstelleService::checkliste()` (:27), Rubriken/Positionen/Preise/Allergene/Branding, braucht keinen PlanningFrame.
- Coverage-Panel nur wenn Frame existiert: `CoverageService::coverage($team,'speisekarte',$karteId)` (:236) ist speisekarte-fähig, Partial owner-neutral (`planning/partials/coverage-panel.blade.php`) — ohne Frame `hat_geruest=false`, darum nur bei vorhandenem Frame zeigen.
- Bewusst DRAUSSEN: gerankte KI-Gericht-Vorschläge je Rubrik (`slotVorschlaege` braucht Frame+Slot), Marge-Solver, Frame-Zwang. „Vorschlag" = der Facetten-Picker (Phase B), vorgefiltert auf `art`/`dish_class` der Rubrik — Vorschlag durch Vorfilterung, ohne KI/Frame.

### MCP-Lockstep, Doku, Tests (querschnittlich)
- MCP (auf dieselben Services): `speisekarten.PUT` (A), `speisekarte_positionen.PUT` (Wording/Preis/variant — heute fehlend!), `.MOVE`/`.REORDER` (C), `speisekarte_rubrik.REORDER` (C). Business-IDs (`SK-01…`) in `26_LLM_MCP_Funktionsmatrix.md:214` nachtragen + Tool-Summenprüfung.
- Tests (Pest `--testsuite=FoodAlchemist`, 1 GB): je neuer Action ≥1 negativer Cross-Tenant-Test (Muster `tests/Feature/Speisekarte*Test.php`); UI-Smoke Tabs + Facetten-Picker.
- Doku: Handbuch `docs/speisekarte.md` um Kontext-Schritt, Facetten-Picker, Reorder/Move, Layout-Blöcke, Wahlgruppen, Coverage/Checkliste; Getränke/Wein als Folge-Schritt vermerken.

### Kritische Dateien
`src/Livewire/Speisekarte/Index.php` · `src/Services/SpeisekarteService.php` (nur neu: `movePosition`) · `resources/views/livewire/speisekarte/partials/rubrik.blade.php` · `resources/views/livewire/speisekarte/index.blade.php` · `src/Livewire/Speisekarte/LeitstelleRail.php` (E) · Referenz (nicht ändern): `Foodbooks/Index.php` + `foodbooks/index.blade.php`.

### Bewusst NICHT (Folge-Schritt „Phase F")
Getränke/Wein-Detail: Darreichungs-Picker (Glas/Flasche/Portion via `presentation_id`) + Wein-Metadaten (`payload_json.wein`). Schema + Preis-Resolver + Vorschau tragen es; nur die Editor-UI fehlt — eigener Ausbau.

### Verifikation (end-to-end)
1. `php -d memory_limit=1G vendor/bin/pest --testsuite=FoodAlchemist` (Sandbox) grün, inkl. neuer Cross-Tenant-Tests.
2. Browser je Phase: Kontext füllen (A), per Facetten-Picker mehrere Gerichte hintereinander (B), Drag&Drop + hoch/runter + Position in andere Rubrik (C), Überschrift/Trenner + Wahlgruppe (D), Leitstelle „was fehlt" (E). Console/Netzwerk prüfen; Screenshot.
3. Dokument/Präsentation: Preise/Kennzeichnung/Wording korrekt; (D) Wahlgruppen-Darstellung.
4. MCP-Gegenprobe (demo-Team): neue Tools Read+Write; Tenancy-Guard bei fremdem Team.
5. demo-Deploy (self-service) nach grüner Sandbox; migrationsfrei → kein Backup-Lauf nötig.

### Vor dem Bau final zu bestätigen (Werkstrang M) — ✅ autonom entschieden 2026-08-20 (nach Spec-Empfehlung)
- `kundentyp` Freitext-String, oder kuratierte Vokabelquelle? → **Freitext-String** (Phase A, „billig"); Vokabel-Härtung späterer Ausbau.
- Phase D: Wahlgruppen-Renderer in Dokument/Vorschau mitbauen oder „Editor-only vorerst"? → zuerst Editor-only; **dann auf User-Entscheidung 2026-08-20 der Druck-Renderer NACHGEBAUT** („oder"-Trenner in Dokument + Vorschau). ✅ komplett.
- Phase E: „Checkliste als Default, Coverage nur bei Frame" bestätigen. → **bestätigt + so gebaut.**
- Zusätzlich offen gewesen (UX-Folgeausbauten): (a) `struktur|positionen`-Tab-Split (Phase A) = Umstrukturierung statt Surfacing → **weiter offen (User-Entscheid: nicht bauen)**; (b) echtes D&D-Reorder → **auf User-Entscheidung NACHGEBAUT 2026-08-20** (additiv zu hoch/runter: gemeinsame Alpine-Drag-Scope am Container statt rekursiver Scopes; `positionAblegen`/`rubrikAblegen` + Position-in-Rubrik-Drop; SpeisekarteUiTest 9/9); (c) Angebot-Gerüst-Review-UI → **auf User-Entscheidung GEBAUT 2026-08-20** (Angebot-Editor „Gerüst"-Tab: Slots hinzufügen/löschen + KI-Kickoff aus Anlass/Gäste, `PlanningFrameService`; Auto-Struktur bleibt Fallback; AngebotAnProduktionTest + EditorOeffnenVertragTest grün).

---

## 7. Lauf-Protokoll (autonome Umsetzung — Routine `spec40-leitstelle-umsetzung`)

> Die Routine arbeitet die Etappen **ein Teilschritt pro Lauf** ab, Reihenfolge **E-P0 → E0 → E1 (=Doc, skip) → E1b → E2 → E3 → E4 → E5 → §6 A–E**. Jeder Lauf trägt zuerst eine „läuft"-Startzeile ein (Überlappungs-Guard: ein neuer Lauf bricht ab, wenn hier ein „läuft" < 60 Min alt ohne Abschluss steht) und schließt sie am Ende ab. Lokal + Push auf `feat/spec40-umsetzung` erlaubt; **kein Deploy** (Dominiques Schritt).

> **Hinweis:** die Umsetzung 2026-08-20 lief NICHT über die autonome Routine, sondern **interaktiv-autonom in einer Session** (User: „ganze Spec-Liste voll autonom") — je Etappe verifiziert→gebaut→Sandbox-Pest→committet. Reihenfolge wie geplant, E1 (Doc, skip), E3 blockiert.

| Datum | Etappe | Status | Commit-SHA |
|---|---|---|---|
| 2026-08-20 | E-P0 Attach-Fehler + Recovery | getestet | `a27c3aa` |
| 2026-08-20 | E0 Analyse→Skizzen-Divergenz | getestet | `d1e24f2` |
| 2026-08-20 | E1b Owner-Banner + Zurück-Link | getestet | `bdb0b2e` |
| 2026-08-20 | E2 Angebot andocken | getestet | `dc40d51` |
| 2026-08-20 | E3 Ergebnis→Wissen/Trend | 🚫 blockiert (RAG) | — |
| 2026-08-20 | E4 Lücken-Signal + Favoriten | getestet | `221a570` |
| 2026-08-20 | E5 Skizzen-Lernen | getestet | `62ec05e` |
| 2026-08-20 | §6 M Phase A Kontext-Tab + PUT | getestet | `b6a76cc` |
| 2026-08-20 | §6 M Phase B Facetten-Picker | getestet | `b8b27a3` |
| 2026-08-20 | §6 M Phase C Reorder + movePosition | getestet | `aabd4ff` |
| 2026-08-20 | §6 M Phase D Layout-Blöcke + Wahl-Gruppen | getestet | `559adbc` |
| 2026-08-20 | §6 M Phase E Coverage-Panel + Checkliste | getestet | `679947a` |

**Bekannte Gates (nicht autonom baubar, bis entschieden — Routine stoppt hier und meldet):**
- **§4-Grundsatzfragen** (Round-Trip verbindlich? · Tür im Modul lassen? · Rückkopplungs-Priorität) — betreffen v.a. E3–E5.
- **E3** setzt den RAG-Branch `feat/rag-autoindex-recall` in main voraus.
- **§6 / Werkstrang M** hat drei „vor dem Bau zu bestätigen"-Fragen (kundentyp-Vokabular · Wahlgruppen-Renderer · Checkliste/Coverage).
