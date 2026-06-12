# Roadmap Phase 2 (M9+) — VK-Vollausbau · Foodbook · neue Domänen

> **Entstehung:** Dominique-Entscheid 2026-06-12 — die Phase-1-Roadmap (`12_ROADMAP.md`,
> M0–M8 + UI-Runden R1–R6) ist ein abgeschlossenes Arbeitsdokument mit Beleg-Notizen
> und bleibt unverändert; Phase 2 bekommt DIESES eigene Dokument.
> **Verbindliche Entscheide aus dem Review:**
> - **Chat-Assistent: KOMPLETT VERWORFEN** (kein Conversation-UI, keine Persistenz;
>   `chat.message` bleibt als Registry-Altlast dokumentiert, wird nicht verdrahtet.
>   Der 🧑‍✈️-Copilot-Button der Ist-App entfällt damit ebenfalls.)
> - **Foodbook/Portfolio: kommt definitiv** — Plan unten (M10), Zuschnitt mit Dominique abstimmen.
> - **Weitere Domänen** (Kalkulation HK2, Produktionsplanung, Speiseplan, Einkauf, Lager,
>   Controlling): Brainstorming im Nachgang — in der Sidebar bereits als «In Planung» sichtbar.
> - Erst die **Basis fertig** (M9), dann Neues.

---

## M9 — VK-Editor-Vollparität + Basis-Politur (sofort, keine externen Abhängigkeiten)

### M9-01 VK-Editor: Haben/Fehlt-Abgleich gegen die Ist-App (4 Screenshots 2026-06-12)

**Schon da (M6-04 + R5/R6):** Stammdaten inkl. `vk_wording_standard`-Feld · Klassifikation
(HG → Klasse) · Verkaufseinheit (Einheit + Stück/Rezept + g/Stück) · Verkaufs-Block mit
Live-Marge (AK/MwSt/VK-manuell → brutto/Marge/Wareneinsatz/Stück-Werte + Formel-Klartext)
· Container & Service (Behälter warm/kalt + Vehikel) · **Regeneration je Komponente (V-19
— mehr als die Ist-App!)** · Verwendungsnachweise (Kunde × Marketing-Name) · Fullscreen.

**Fehlt (= M9-01-Pakete):**

| # | Paket | Inhalt | Quelle |
|---|---|---|---|
| a | **Zutaten inline** | eingebetteter P-8-Editor im VK-Modal (wie Basis-Voll-Editor) statt separatem Komponenten-Modal; **+ Rollen-Spalte** (Dropdown aroma_treiber/komponente/beilage/garnitur, V-21) + 🎭 «Rollen verteilen» + ✨ KI-Überarbeiten im VK-Kontext | Screenshot 1 |
| b | **KPI-Leiste** | Yield · EK gesamt · EK/kg (Highlight) · Mit Preis n/m · Allergen-Konf. unter den Zutaten | Screenshot 1/2 |
| c | **Allergene/Zusatzstoffe-Grids im Editor** | das R6-Deklarations-Partial zusätzlich im VK-Editor (Panel hat es schon) | Screenshot 2 |
| d | **Nährwerte-Sektion** | pro 100 g + pro Stück (Brennwert/Eiweiß/Fett/KH/Salz), Konfidenz-Zeile + Aggregations-Datum — GL-08-Daten liegen am Rezept, nur UI | Screenshot 2 |
| e | **Spezifikation** | Bio-Anteil % (aus GP-`is_organic`-Anteilen zu berechnen — kleine Service-Methode), Regional, Diät-Zeile (Partial vorhanden) | Screenshot 2 |
| f | **Eigenschaften + KI-Beschreibung** | Arbeitszeit/Temperatur/Funktion/Geschmack/Fertigungstiefe + ✨ (vom Basis-Editor übernehmen) | Screenshot 3 |
| g | **Plating & Service** | NEU: `plating_text` (Migration + Lineage-Trio), Markdown-Editor mit ✨ Plating (`vk.plating` ist registriert) | Screenshot 3 |
| h | **Notizen** | §9.1-Insel im VK-Editor | Screenshot 3 |
| i | **✨-Buttons verdrahten** | Wording · Marketing · Behälter · Servier-Vorschlag · Regeneration · Eigenschaften — Prompts sind ALLE in der Registry (M7-04), GL-07-Proposal-UI je Feld | Screenshot 1–3 |
| j | **Markdown-Toolbar** | B / I / H2 / H3 / Listen / `<>` über Plating UND Basis-Zubereitung (Politur-Lücke aus M4-13) | Screenshot 3 |
| k | **VK-Panel-Rest** | Sektor-/Niveau-Eignung mit «+ manuell…» + ✨ Eignung (Basis-Panel zeigt nur read-only); Marketing-Text mit ✨-Button im Panel | Screenshot 4/5 |

> **Status M9-01 (2026-06-12) — Pakete a–j GEBAUT, k offen:** **(a)** Zutaten inline im VK-Editor
> (geteilter P-8-Kern, `vkKontext` zeigt die **Rollen-Spalte** als Select je Zeile — `rolle` geht durch
> payload→syncIngredients, V-21-getestet) + 🎭 Rollen-Proposal-Box (verteileRollen/acceptRollen,
> Editor re-mountet über Versions-Key). **(b)** KPI-Leiste (Yield · EK · EK/kg · Mit Preis · Allergen-Konf.).
> **(c)** R6-Deklarations-Partial im Editor. **(d)** Nährwerte-Tabelle pro 100 g + pro Stück
> (g/Stück aus dem Cockpit; Konfidenz-Zeile + GL-08-Ehrlichkeits-Hinweis; leer-Zustand wenn nie
> aggregiert). **(e)** Spezifikation: Bio-/Regional-Anteil Gramm-gewichtet über GP-Tags
> (`is_organic`/`is_regional`, detail() lädt sie jetzt mit). **(f)** Eigenschaften + KI-Beschreibung.
> **(g)** `plating_text` (Migration 000038 + Lineage-Trio; manueller Edit stempelt manual via
> updateVk-Schleife). **(h)** Notizen. **(i)** ✨ Wording · Marketing · Eigenschaften · Behälter
> (ID-validiert gegen Vokabular) · Servier-Vorschlag (dito) · Regeneration (Programm-LISTE,
> Übernahme je Zeile) · Plating — alle im «Vorschlag in die Form, Save = Accept»-Muster,
> FakeProvider ⇒ ehrlicher kiFehler. **(j)** Markdown-Toolbar (B/I/H2/H3/Listen/<>) an VK-Plating
> UND Basis-Zubereitung — per Element-ID statt $refs (verschachtelte Alpine-Scopes teilen keine refs).
> **(k) Eignungs-Pflege im Panel: OFFEN.** VK_FELDER-Whitelist +8 Felder. 5 neue Tests.
> Live verifiziert (alle Sektionen + Rollen-Spalte im VK, NICHT im Basis-Editor). Suite: **382/382 (1.594 Assertions)**.

### M9-02 Design: Panel-Kontrast ✅ (erledigt 2026-06-12)
Rechte Detail-Spalte (GP/Basis/VK) leicht grau hinterlegt wie in der Ist-App — nur Modul-Views, Core-Sidebar unangetastet.

### M9-03 Review-Queue (V-10)
Zentrale «Zu prüfen»-Seite: 597 needs_review-LAs · offene KI-Vorschläge (Bulk-Läufe) ·
V-22-Pflegelücken (VK ohne Klasse) · Rezepte im Review-Status. Zähler in der Sidebar.

### M9-04 KI-Kosten-Sichtbarkeit (V-09/V-16)
`billables`-Block in `config/foodalchemist.php` (Vorbild `planner.php`) + €-Auswertung
in den KI-Settings aus `ai_call_log` (Tokens × Tier-Preis).

> **Status M9-03 (2026-06-12):** Review-Queue gebaut — Route  + Sidebar «Zu prüfen»:
> offene **LA→GP-Match-Vorschläge** (Übernehmen/Verwerfen via MatchService, Live: 2 offen),
> offene **Bulk-KI-Vorschläge** (Einzel-Übernahme via BulkEnrichService), **VK ohne Klasse** (V-22),
> **Rezepte im Review** (Live: 1.365) und **ungemappte Zutaten** (F7.1) — Rezept-Klicks öffnen die
> Editoren als Modal. Aktionen laufen über die bestehenden Services (eine Regel-Stelle).

> **Status M9-04 (2026-06-12):** KI-Kosten sichtbar —  je Tier (€/1M Tokens
> in/out, env-überschreibbar, Defaults = Anthropic-Listenpreise) + **≈-Kosten-Spalte und
> Gesamt-Summe** in der KI-Settings-Nutzungstabelle (Tokens × Tier-Preis). -Block
> (V-16, Plattform-Abrechnung) als dokumentierter LEERER Block angelegt — WAS abgerechnet wird,
> ist Dominique/Martin-Entscheid.

> **Status R10 (2026-06-12, Dominique-Wunsch «Allergene/Nährwerte über KI, falls kein LA»):**
> Nährwert-Aggregation GEPRÜFT — kein Bug: 4.090/7.774 GPs haben LA-Nährwerte, der Rest sind
> echte BLS-Lücken (Beleg Berliner: Nutritional-Zeile existiert, aber alle Kernwerte NULL).
> **✨-Fallback gebaut:** -Prompt (Registry +1) + Migration 000039
> (5 nutri-Felder + Lineage am GP); GP-Panel zeigt «✨ per KI schätzen» NUR wenn die jeweilige
> Daten-Quelle leer ist; GL-07-Vorschlags-Box → Übernehmen schreibt Allergene in den
> **Override-Layer** (GL-01 Prio 1,  wird nie geschrieben — F7.1) bzw. Nährwerte in
> die **Fallback-Schicht mit ✨-KI-Marker** — die bewusst NUR der Panel-Anzeige dient
> () und NIE in die GL-08-Rezept-Aggregation fließt.
> 3 neue Tests. Live verifiziert (Button-Logik korrekt kontextabhängig). Suite: **388/388 (1.628 Assertions)**.

### M9-05 Verwendungssuche (Dominique-Wunsch «Fehlt Verwendungssuche»)
Durchgängige «Wo verwendet?»-Navigation: GP → Rezepte (Liste statt nur Zähler),
Basisrezept → Eltern (existiert) → VK-Rezepte, LA → GPs. Als Panel-Sektion + globale Suche.

> **Status R9/GP-Welt (2026-06-12, Dominique: «beim Grundprodukt läuft gar nichts»):**
> **(1) GP-Panel NEU (Jarvis-Stil):** die M3-Lazy-Klapp-Sektionen versteckten alle Inhalte und
> Aktionen — jetzt ALLES direkt sichtbar: sauberes Stammdaten-Raster, Eigenschafts-Tags
> (ja/nein/unbewertet), 14-Allergene-Grid (rot/amber/grau, Override-✎/Mutter-↑-Marker),
> Zusatzstoffe, Nährwerte, LA-Liste mit **★-Direktklick = Lead setzen** (Lead-Zeile orange)
> + Hover-Aktionen + «+ LA verknüpfen»-Suche. GpBrowserTest auf das neue Soll umgestellt.
> **(2) M9-05 GP-Blickwinkel:** Sektion «Verwendet in Rezepten (n)» — klickbare Liste
> (📖 Basis → recipe-modal, 💶 VK → vk-modal; beide Modals im GP-Browser eingebunden).
> **(3) LA-Modal GP-MAPPING (Jarvis):** aktuelles Mapping mit ✕-Lösen, **✨ KI-Vorschlag**
> (MatchService v1: EAN/Art.-Nr-Dubletten + GL-04-Fuzzy, Live-Beleg: 90 % fuzzy_name auf
> Pflaumenmarmelade) und manuelle GP-Suche — Zuweisung über LeadLaService::verknuepfen
> (GL-05-Guard: fremd-zugeordnet blockt). **Bug-Lehre:** abgespeckte Eager-Loads
> () tragen kein team_id — Curate-Gates brauchen das VOLL geladene
> Modell. **(4) GP-Editor:** live geprüft — öffnen/speichern/schließen ok (kein Defekt
> reproduzierbar; vermutlich war der alte Browser-Stand das Problem).
> 3 neue Tests. Live verifiziert. Suite: **385/385 (1.607 Assertions)**.

> **Status R11/M9-Abschluss (2026-06-12):** **M9-01k:** Sektor-/Niveau-Eignung im VK-Panel
> pflegbar — Chips mit ✕ (Hover), «+ manuell…»-Select aus dem festen Slug-Vokabular,
> **✨ Eignung** (recipe.sektor + recipe.niveau, nur «geeignet»-Urteile werden Vorschlag,
> Übernehmen schreibt quelle ai_inferred); Service-Methoden setzeEignung/entferneEignung
> (Slug-Whitelist, Besitzer-Guard, Reaktivierung statt unique-Crash bei soft-deleted Zeilen).
> **✨ Marketing im Panel** (Override-First: manual blockt). **M9-05-Rest:** Basis-Panel
> «Verwendet in» unterscheidet jetzt 💶-VK-Eltern (öffnen den VK-Editor als Modal) von
> ↑-Basis-Eltern (Panel-Hop). **M9-06:** Zu-prüfen-Hinweis-Banner im Lieferanten-Browser
> (offene Match-Vorschläge → Review-Queue). 3 neue Tests. Live verifiziert (manuelles
> Eignung-Setzen: Chip erscheint, Slug verschwindet aus dem Select). Suite: **391/391 (1.645 Assertions)**.
> **M9 ist damit KOMPLETT** — offen bleibt nur V-03 (Namens-Normalisierung, wartet auf echten LLM).

> **Status R12/Jarvis-Parität (2026-06-12, 4 Screenshot-Punkte von Dominique):**
> **(1) Lieferanten-★:** Artikel-Tabelle hat eine klickbare ★-Spalte (nur bei gemappten
> Artikeln) — Klick setzt den Artikel als globalen Lead/Favorit am GP (`Index::leadSetzen`,
> Curate-Gate, GL-03); ungemappt → Hinweis «erst mappen». **(2) Preise-Block im LA-Modal**
> nach Jarvis-Vorbild: EK-aktuell-Box (grüner Preis + «pro Gebinde» + Vergleichspreis),
> «+ Neuer Preis»-Toggle, Tabelle Gültig von/bis · Kategorie-Pill · Preis (mit «= €/kg»-
> Subzeile) · Notiz · ✎-Inline-Edit (preisBearbeiten/preisUpdate, Komma-Parsing, Curate-Gate).
> **(3) GP-Panel-Redesign:** Slug in grauer Box unterm Namen, Bearbeiten-Zeile darunter,
> Box «Natürliche Einheit & Gewicht» (1 Stück ≈ X g), LAs als BREITE Karten (Lead = orange
> Karte, Gebinde-Preis groß rechts + €/kg klein, «Lieferant · Menge Einheit · Art-Nr»),
> **✨ KI-Vorschlag** für LA-Kandidaten: `LeadLaService::kandidatenFuerGp` — deterministisch
> (Haupttoken des GP-Namens MUSS als ganzes Wort treffen + ≥ 50 % aller Tokens; «burger»
> trifft nicht «Hamburgerblätter»), Klick = verknüpfen. *Grenze:* die restlichen unverknüpften
> Sandbox-LAs sind fast nur Non-Food (Hanos-Gläser/-Boxen) — bessere Vorschläge brauchen
> Embeddings (Blocker Martin). **(4) VK-Panel:** Aktions-Buttons (Bearbeiten · Komponenten ·
> ✨ Klassifizieren · 🎭 Rollen) in eigener Zeile UNTER dem Namen; Komponenten-Liste im
> Jarvis-Format (Menge+Einheit grau vorangestellt, voller Name, text-sm, ohne ↗).
> Nebenbei: zwei ungeschützte `route()`-Aufrufe in suppliers/index abgesichert (Test-Boot
> ohne modules-Tabelle). 3 neue Tests (R12JarvisParitaetTest). Live verifiziert (Screenshots
> GP-Panel + Preise-Block, ✨-Kandidaten-Box, Toggle, Inline-Edit). Suite: **394/394 (1.663 Assertions)**.

> **Status R13/Listen-Dichte (2026-06-12, Dominique: «Spalten zu breit … in Cooking Jarvis
> um einiges besser — betrifft jedes Modul»):** ZENTRAL in `Support/Ui::maps()` verdichtet
> (wirkt in allen 30 Views, die die Map nutzen): `th/td` px-5→px-3, td py-1.5→py-1,
> Header `whitespace-nowrap` + 11px, Pills px-1.5/py-px/11px. Zeilenhöhe real 29 px
> (vorher 44–60 px durch umbrechende Warengruppen). In den 4 Browsern (GPs, Lieferanten,
> Basis, VK) zusätzlich: **Name-Spalte als flexible truncate-Spalte** (`w-full max-w-0
> min-w-44 truncate` — nimmt allen Restplatz, bläht die Tabelle NIE über den Container,
> Tooltip trägt den vollen Namen), Warengruppe/Kategorie einzeilig kursiv klein mit
> Breiten-Deckel, Geld-/Zahlen-Spalten rechtsbündig `tabular-nums`, Allergen-Badges max. 3
> + «+n». Haupttabellen in `overflow-x-auto`-Wrapper: schmaler Mittelteil (Panel offen)
> scrollt horizontal statt Spalten hart abzuschneiden. UiMapsTest-Soll auf R13-Dichte
> angepasst. Live verifiziert (Messung: Tabelle 1316→771 px, Scroll-Test ok, Screenshot).
> Suite: **394/394 (1.665 Assertions)**.

> **Status R14/Jarvis-Schriftskala (2026-06-12, Dominique: «Schrift zu groß, kein rundes
> Gefühl — schau bei Jarvis welche Größe»):** Jarvis-Quellcode ausgewertet
> (`COOKING JARVIS/00_SYSTEM/00.07_App/cooking-jarvis/src/App.css`): Basis 13px,
> .data-table **12px**/th 11px, Sidebar-Listen 12px (Counts 10px), Detail-Panel h2 **15px** /
> Sektions-Label **10px uppercase** / Werte 12px / Meta 11px, Inputs+Buttons 12px, btn-sm 11px.
> Exakt übernommen: zentral (`Ui::maps()`) table→12px, input/btnPrimary/btnGhost→12px,
> btnGhostXs→11px, label+dt→10px-uppercase (Jarvis .detail h3); Sweep über ALLE 38 Modul-Views
> (Reihenfolge: text-xs→11px, dann text-sm→12px — Meta-Zeilen rutschen mit auf Jarvis-Maß);
> Panel-Titel (GP/Basis/VK) auf 15px. Core-Navigation links außen UNANGETASTET (tabu).
> Live nachgemessen: Tabelle 12px/Zeile 26px, Panel-Titel 15px, Label 10px, Werte 12px,
> WG-Sidebar-Buttons 12px. Suite: **394/394 (1.668 Assertions)**.

> **Status R15/Zutaten-Editor (2026-06-12, Dominique: «Zeilen zu weit auseinander,
> Verschieben/DnD geht nicht»):** Ein Fix im geteilten P-8-Kern wirkt in BEIDEN Kontexten
> (Basis- + VK-Editor). **Dichte:** Zeilen-tds und Inline-Inputs py-1→py-0.5, Editor-Inputs
> 11px (Jarvis .ingredient-table), `border-collapse` auf der Editor-Tabelle (Browser-Default
> «separate + 2px spacing» zog die Zeilen zusätzlich auseinander) — Zeilenhöhe real
> **24 px** (vorher ~40). **Sortieren:** Jarvis macht es per moveUpDown-Buttons, nicht
> (nur) DnD — übernommen: **▲/▼ je Zeile** (data-zeile-hoch/-runter, disabled an den
> Enden) als browser-unabhängige Lösung; HTML5-DnD zusätzlich für Safari gehärtet
> (Handle `inline-block` — Safari startet Drags auf inline-Elementen nicht zuverlässig —
> + `@dragenter.prevent` auf der Drop-Zone). Live verifiziert: ▲-Klick und Drag&Drop
> ändern beide die Reihenfolge korrekt, Zeilenhöhe 24 px. Suite: **394/394 (1.668 Assertions)**.

### M9-06 Politur-Rest aus den Abnahmen
needs_review-Zähler im Lieferanten-Browser · V-03-Namens-Normalisierung (wartet auf echten
LLM — Werkzeug steht) · ~~/test-Route~~ ✅ (R6).

---

## M10 — Foodbook / Portfolio (Zuschnitt mit Dominique abstimmen)

**Architektur-Empfehlung (Entscheid offen):** EIGENE Domäne **im** `platforms-foodalchemist`
(eigene Sidebar-Gruppe «Foodbook», eigene Tabellen `foodalchemist_foodbooks…`) — NICHT als
separates Composer-Paket: das Foodbook liest Rezepte/Preise/Wording direkt aus dem geteilten
Datenmodell; ein eigenes Plattform-Modul müsste über Modul-Grenzen zugreifen (Goldene Regel 3).
Wenn später ein Verkaufs-/Kunden-Portal entsteht, kann die Ausgabe-Schicht ausgelagert werden.

| ID | Paket | Inhalt |
|---|---|---|
| M10-01 | Schema + Browser | `foodbooks` · `foodbook_kapitel` · `foodbook_blocks` · `variant_groups` · `kombinationen`; Browser mit Kapitel-Baum, Blöcke = VK-Rezepte/Texte/Varianten. **V-25-Snapshot ab Tag 1** (Versand friert Preise/Wording ein) |
| M10-02 | Schreibstil-Transformation | `vk_wording_standard` → Brand-Voice-Variante je Schreibstil (11 Stile sind gepflegt, `vk.marketing`/`vk.wording` registriert) — der Kern-Wert des Foodbooks |
| M10-03 | PDF-Export (V-26) | headless Rendering einer Foodbook-Ausgabe (Kapitel → Blöcke → Preise aus Snapshot) |
| M10-04 | Menüplanung `ai_plan_dishes` | KI-gestützte Menü-Zusammenstellung aus dem VK-Bestand (Pairing-Kohäsion als Qualitäts-Signal) |

~~Chat-Assistent~~ — **verworfen** (Dominique 2026-06-12).

---

## M11+ — Neue Domänen (Brainstorming-Platzhalter, Sidebar «In Planung»)

Noch KEIN Scope — je 2 Zeilen Startpunkt fürs gemeinsame Brainstorming:

| Domäne | Startpunkt-Idee |
|---|---|
| **Kalkulation (HK2)** | Produktions- und Produkt-Kalkulation auf Basis Herstellkosten 2 (EK + Arbeitszeit×Stundensatz + Gemeinkosten-Zuschläge); `arbeitszeit_min` ist je Rezept schon gepflegt, Stundensätze = neue Team-Settings |
| **Produktionsplanung** | Produktionsaufträge aus VK-Bestellmengen → Skalierung der Basisrezepte (Yield-Mathematik existiert), Tagespläne je Station/Equipment |
| **Speiseplan** | Wochen-/Zyklenpläne aus VK-Rezepten mit Diät-/Allergen-Abdeckung (Aggregate liegen vor), Sektor-Eignung als Filter |
| **Einkauf** | Bestellvorschläge aus Produktionsplan × Lead-LA (V-29-Vorbestellzeiten sind als Felder da!), Liefertermin-Logik |
| **Lager** | Bestände je LA/GP, Wareneingang gegen Bestellung, Chargen → Allergen-Rückverfolgung |
| **Controlling** | Soll/Ist-Wareneinsatz, Margen-Trends aus `markup_classes`-Historie, KI-Kosten (M9-04) als Baustein |

---

## Externe Blocker (unverändert aus Phase 1)

| Was | Wer | Blockiert |
|---|---|---|
| D6-Deckungsbeitrags-Formel (Vorlage liegt in 08_ENTSCHEIDUNGEN, Empfehlung DB₁) | Dominique | `formel_typ='deckungsbeitrag'` rechnet |
| A-1-Bestätigung · V-08-Detailgrad · V-29-Logik-Verortung | Dominique | Detailpunkte |
| Push/Repo-Sichtbarkeit | Dominique/Martin | jeden Push |
| Embeddings/RAG (M7-09) · Vision/Foto-Import · `ASSEMBLYAI_API_KEY` | Martin | semantischer Reuse · Foto→Rezept · Voice-Deploy |
| Echter LLM-Key in der Sandbox | Martin | alle ✨-Inhalte (Mechanik steht überall) |
