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
  - [ ] **Rest-Chunk (Teil 2):** geplante Sub-Rezepte VOR der Gericht-Freigabe einzeln bedienen — „jetzt erzeugen" + „brauche ich nicht" (verwerfen) je `geplant`-Zeile. Heute sind sie sichtbar, aber nur als Ganzes über die Stufe darüber auslösbar.
- [ ] **Scope-Treue** — ein „Freies Basisrezept"-Start erzeugte im Test eine „Gerichte"-Stufe (Tomatensuppe); der Start-Tab muss die Ebene korrekt setzen (Basisrezept ≠ Gericht)
- [ ] **LLM-Contract:** `vk.generator` / `recipe.generator` (`config/foodalchemist.php`) bekommen einen Slot für **benannte Sub-Komponenten** (statt flachem `zutaten[]`)
- [ ] **Heuristik erweitern:** `MatchHeuristics` Marker-Listen (`:51-65`) um `sauce/rahmsauce/jus/sud/essenz/dressing/vinaigrette` (Zwischenschritt, bleibt Keyword-Hack)
- [ ] **Entscheidungsstelle:** `RecipeGeneratorService:247-248/408-412` liest künftig das LLM-Komponenten-Flag statt nur die Namens-Heuristik
- [ ] **Wissens-Erdung:** Routing-Zeile `regelwerk` → `ai_generate_recipe` (analog `2026_08_07_000001`) → §2/§3/§4 [Regelwerk Basisrezepte] fließt in den Prompt
- [ ] **LLM-Foodpairing als Assist** (Whiteboard-Baustein) — Gericht-/Menü-Erzeugung erdet am **Pairing-Graph** (Aroma-Kohärenz) statt frei zu raten (↔ Kohärenz-Gate/Anker-Graph)
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
- [ ] **Fan-out-Caps** — defensiver Deckel bei Concept-Erfinden (analog Speiseplan)
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
