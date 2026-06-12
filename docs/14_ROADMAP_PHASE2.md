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

### M9-02 Design: Panel-Kontrast ✅ (erledigt 2026-06-12)
Rechte Detail-Spalte (GP/Basis/VK) leicht grau hinterlegt wie in der Ist-App — nur Modul-Views, Core-Sidebar unangetastet.

### M9-03 Review-Queue (V-10)
Zentrale «Zu prüfen»-Seite: 597 needs_review-LAs · offene KI-Vorschläge (Bulk-Läufe) ·
V-22-Pflegelücken (VK ohne Klasse) · Rezepte im Review-Status. Zähler in der Sidebar.

### M9-04 KI-Kosten-Sichtbarkeit (V-09/V-16)
`billables`-Block in `config/foodalchemist.php` (Vorbild `planner.php`) + €-Auswertung
in den KI-Settings aus `ai_call_log` (Tokens × Tier-Preis).

### M9-05 Verwendungssuche (Dominique-Wunsch «Fehlt Verwendungssuche»)
Durchgängige «Wo verwendet?»-Navigation: GP → Rezepte (Liste statt nur Zähler),
Basisrezept → Eltern (existiert) → VK-Rezepte, LA → GPs. Als Panel-Sektion + globale Suche.

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
