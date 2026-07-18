# Planungs- & Kreativ-Ebene — Doppel-Diamant vor der Grounding-Kaskade

> **Anlass (Dominique 2026-07-18):** Auf Konzept- und Foodbook-Ebene fehlt eine **Planungs-/Kreativ-Ebene**, auf der man plant/gestaltet, **bevor die KI die Kaskaden anlegt** (Rezepte/GPs/Preise). Und: der **kreative Teil fehlt** — der Concepter ist heute rein konvergent.
> **Diagnose (roter Faden):** FA ist **konvergenz-stark, divergenz-schwach**. „Keine Erfindungen" ist goldrichtig an der **Erdungs-Kante**, hat aber den **kreativen Vorlauf miterstickt** (nur Umsortieren des Bestands). Derselbe Instinkt wie bei Convenience („nicht versteifen") und „planen bevor die KI committet". Diese Ebene ist die systematische Antwort.

---

## 1. Entscheidungen (Dominique 2026-07-18)

| # | Frage | Entscheidung |
|---|---|---|
| 1 | Ebenen | **Zwei getrennte.** **Foodbook-Ebene bestimmt den Rahmen** (was wird gebraucht: Kapitel, welche/wie viele Konzepte, Scope) — top-down. **Concept-Ebene** plant das **Food** — im Foodbook-Rahmen ODER **standalone** (eigener Rahmen). |
| 2 | KI-Kreativität | **Voll divergent** — Themen/Dramaturgie **UND** neue Gerichte erfinden + mutige Pairings (aus dem Anker-Graph als Inspiration). |
| 3 | Gate | **Ein expliziter „Go"-Knopf** — er kreuzt von Planung → geerdeter Kaskade. |
| 4 | Verhältnis R4 | **R4-Gerüst = Rahmen-Artefakt, das am „Go" ENTSTEHT** (aus dem Foodbook-Plan top-down, bzw. aus dem standalone Concept-Plan) und in den Assembler geht. R4 bleibt Brücke — Output der Planung, nicht manueller Input. |
| 5 | Kaskaden-Prinzip | **Alle Kaskaden greifen ineinander, aber jede läuft auch für sich.** Foodbook ⊃ Concept ⊃ Rezept/GP — verschachtelt UND je einzeln benutzbar (nur ein Konzept, nur ein Rezept). |
| 6 | Wissen | Kreatives Planen braucht **Grounding-Wissen**: (a) **„was ist ein Konzept" / Concepting-Know-how** (Kategorie `concept` existiert, ist aber leer → befüllen) + (b) **dasselbe Food-/Rezept-Wissen, das die Rezept-KI nutzt** (Domain/Pairing/Regelwerk) — der Plan muss es mitberücksichtigen. |

---

## 2. Architektur — Doppel-Diamant

```
DIVERGIEREN (neu, Skizze)         │  GATE          │  KONVERGIEREN (existiert)
                                  │                │
Concept-Kreativ-Ebene (Food):     │  „GO"          │  1. Gerüst (R4) aus dem Plan destillieren
 - Thema/Dramaturgie              │  (Mensch       │  2. deterministischer Assembler (R6.1):
 - Gerichte: Reuse + ERFINDEN     │   drückt)      │     - Reuse-Gerichte: aus Bestand wählen
 - mutige Pairings (Anker-Graph)  │                │     - erfundene Gerichte: Generator (#505)
 - NICHTS committet/geerdet       │  = Erdungs-    │       + LA-First-Mint (07) + Hard-Stop
                                  │    Kante       │  3. Konzept-Draft: grounded, bepreist,
Foodbook-Planungs-Ebene (Kapitel):│                │     allergen-deklariert, margen-geprüft
 - Kapitel + Intent/Thema         │  „keine        │  → (Foodbook) Kapitel + Konzepte verlinkt,
 - welche Konzepte wohin          │   Erfindungen" │     Coverage-Ampel (R4.2)
 - Dramaturgie/Reihenfolge        │   gilt AB HIER │
```

**Leit-Invariante:** Im Skizzen-Raum ist **Erfindung erlaubt und gefahrlos** — nichts wird persistiert/geerdet. Das **Go** ist die Erdungs-Kante: ab hier greift „keine Erfindungen" — jedes erfundene Gericht muss real werden (Bestand / Generator / LA-First-Mint / „zu-bauen"-Backlog). **Die Kreativität speist die Grounding-Kette, statt sie zu umgehen.**

---

## 3. Die zwei Ebenen im Detail

### 3.1 Foodbook-Planungs-Ebene (bestimmt den Rahmen, top-down)
Die obere Ebene — sie legt fest, **was gebraucht wird**.
- **Inhalt:** Kapitel-Struktur + Intent/Thema je Kapitel + welche/wie viele Konzepte wohin + Reihenfolge/Dramaturgie + Scope (Anlass, Umfang, Preisrahmen).
- **KI-Rolle:** schlägt Kapitel-Bögen + passende Konzept-Anforderungen vor (divergent auf Struktur-Ebene).
- **„Go":** legt Foodbook-Draft an und gibt **je Kapitel den Rahmen** (Anforderung) an die Concept-Ebene weiter; Coverage-Ampel (R4.2) zeigt Lücken.

### 3.2 Concept-Ebene (plant das Food — im Rahmen ODER standalone)
Der eigentliche Food-Denkraum. Läuft **im Foodbook-Rahmen** (Anforderung von oben) **oder ganz allein** (eigener Brief/Rahmen — man kann nur ein Konzept planen).
- **Inhalt:** Gericht-Zeilen als Mischung aus **Reuse** (Bestands-VK-Gerichte, via #507-Recall vorgeschlagen) und **Erfindung** (KI-Ideen, noch ungeerdet) + mutige Pairing-Inspiration (Anker-Graph).
- **KI-Rolle:** Brainstorm-Partner (divergent) — Gerichte/Pairings vorschlagen, darf erfinden. NICHT geerdet/bepreist in dieser Phase.
- **„Go":** verdichtet den Plan zu einem R4-Gerüst (Slots/Regeln/Preisrahmen/Diät-Quoten) → `ConceptGeneratorService` (Konvergenz): Reuse-Gerichte gewählt, erfundene über Generator (#505) + LA-First-Mint (07) real gemacht.

**Kaskaden-Prinzip (verschachtelt + je einzeln):** Foodbook-Plan → (je Kapitel, Rahmen) Concept-Pläne → (je Konzept) grounded Kaskade → (je erfundenes Gericht) Rezept/GP-Kaskade. **Jede Stufe greift in die nächste, funktioniert aber auch für sich** — nur ein Konzept, nur ein Rezept planbar, ohne die Stufe darüber.

## 3a. Wissens-Ebene (Grounding für kreatives Planen)

Kreatives Planen ist nur so gut wie sein Wissen. Die Planungs-Ebene zieht — wie der Rezept-Generator über `KnowledgeContextService` — Grounding-Wissen, aber aus ZWEI Quellen:
- **Concepting-Wissen (neu):** „Was ist ein Konzept / ein gutes Menü?" — Dramaturgie, Gang-Aufbau, Anlass-/Gäste-Fit, Balance. Die Kategorie **`concept` existiert im Schema, ist aber leer** → muss **befüllt** werden (Destillation wie bei Rezept-/Food-Wissen, Skripte 109/110/111). ⚠️ Nit: `concept` UND `konzept` doppelt angelegt → konsolidieren.
- **Food-/Rezept-Wissen (bestehend):** dasselbe Domain-/Pairing-/Regelwerk-Wissen, das die Rezept-KI nutzt — der Konzept-Plan **muss es mitberücksichtigen** (ein geplantes Gericht darf die Food-Regeln nicht verletzen).
- **Verdrahtung:** neue Routings `concept.plan` / `foodbook.plan` in `foodalchemist_knowledge_routings` → Concepting-Wissen + relevantes Food-/Pairing-Wissen. So denkt die KI kreativ, aber auf demselben Fundament wie die Rezept-Erstellung.

---

## 4. Was existiert (Wiederverwendung, kein Neubau)

- **R4 `PlanningFrame`/Rule/Slot** — wird zum **Destillat-Ziel** der Concept-Ebene (Output am Go), statt manueller Input.
- **`ConceptGeneratorService` (R6.1)** — ist bereits die **Konvergenz-Hälfte**; bekommt das destillierte Gerüst wie heute den Brief-/Gerüst-Pfad.
- **`Canvas`** — Kandidat als technische Basis der Skizzen-Fläche (persistenter, freier Entry-Store) statt Neubau.
- **Generator (#505) + LA-First-Mint (07)** — realisieren erfundene Gerichte am Go.
- **#507** — liefert die Reuse-Vorschläge (Recall) im Kreativraum.
- **06 Convenience-Highlights** — ist ein **Divergenz-Input** (opt-in „bevorzugt aus Haus-Convenience").

---

## 5. Etappen

| # | Etappe | Größe |
|---|---|---|
| **P1** | Datenmodell: zwei Planungs-Flächen (Concept-Plan + Foodbook-Plan), Skizzen-Entries, Status `entwurf` (nicht geerdet) | M |
| **P2** | Concept-Kreativ-Ebene: Skizzen-UI (Reuse + Erfinden + Pairing-Inspiration) + KI-Divergenz-Modus (darf erfinden) | L |
| **P3** | „Go"-Gate: Plan → R4-Gerüst destillieren → `ConceptGeneratorService` + erfundene Gerichte via Generator/07 erden | M–L |
| **P4** | Foodbook-Planungs-Ebene: Kapitel-Dramaturgie + Konzept-Zuordnung + „Go" → Draft + Coverage | M |
| **P5** | KI-Divergenz sauber vom Grounding trennen (Prompt „erfinde frei" vs. Assembler „keine Erfindungen") + Anker-Graph als Ideen-Quelle | M |
| **P6** | Wissens-Ebene: Concepting-Wissen befüllen (Kategorie `concept`, Destillation 109/110/111) + `concept`/`konzept`-Dublette konsolidieren + Routings `concept.plan`/`foodbook.plan` (Concepting- + Food-/Pairing-Wissen) | M |

**Globale DoD:** Skizze committet/erdet NICHTS bis „Go"; nach „Go" ist alles geerdet (Bestand/Generator/LA-First/Backlog); kein Auto-Go (Mensch drückt); erfundene Gerichte werden nie ohne Erdung Teil eines echten Konzepts. **MCP-Pflicht (00-Invariante):** beide Planungs-Ebenen sind agentisch nutzbar — Tools für Plan anlegen/lesen/ergänzen (z.B. `concept_plans.POST/GET/PUT`, `foodbook_plans.*`) + divergente KI-Vorschläge; das „Go" bleibt auch via MCP ein expliziter, menschlich getriggerter Call. Tools entstehen MIT den Etappen, nicht retrofitted.

---

## 6. Bewusste Nicht-Ziele

- **Keine** Erfindung NACH dem Go (ab da „keine Erfindungen", geerdet).
- **Kein** Auto-Go — der Mensch entscheidet, wann die Kaskade läuft (spiegelt „propose, never autonomously commit").
- Skizze ≠ Freigabe — Planungs-Draft ist wegwerfbar, erzeugt erst am Go persistente Artefakte.
- Kein Ersatz des deterministischen Assemblers — die Erdung/Auswahl bleibt regelbasiert (R6.1-Prinzip).

---

## 7. Warum das strategisch zählt

Aus „Grounding-Engine" wird ein **kreativer Co-Pilot mit belastbarem Output**: vorne frei denken (Themen, mutige Gerichte, Pairings), hinten garantiert real (bestellbar, bepreist, deklariert). Das ist das fehlende Front-End der Wertschöpfungskette *conceive → plan → generate → ground → price → publish* — und im Investor-Narrativ der Unterschied zwischen „noch ein Rezept-Generator" und „Kreativ-Studio, das direkt in die Kalkulation liefert".

*Erstellt 2026-07-18. Verzahnt: R4 (Gerüst als Rahmen-Artefakt), R6.1 `ConceptGeneratorService` (Konvergenz), Generator #505 + [07_LA_First_GP_Mint_ueberall.md](07_LA_First_GP_Mint_ueberall.md) (Erdung erfundener Gerichte), [06_Convenience_Highlights_GP_Liste.md](06_Convenience_Highlights_GP_Liste.md) (Divergenz-Input), [02_RAG_System_FoodAlchemist.md](02_RAG_System_FoodAlchemist.md) (#507 Reuse-Recall), Canvas. Dev-Issue-Kandidat Board 53.*
