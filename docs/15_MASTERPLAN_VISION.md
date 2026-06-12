# 15 — Masterplan: Von der Concepter-Vision zum Vollausbau

> **Quelle der Vision:** [`concepter-konzept.md`](concepter-konzept.md) (Dominique-Konzeptpapier).
> **Verhältnis zu [`14_ROADMAP_PHASE2.md`](14_ROADMAP_PHASE2.md):** Phase 2 ist die *Arbeits*-Roadmap.
> M9 (VK-Editor-Vollparität) ist dort **komplett** (Suite 397/397). Dieser Masterplan
> **präzisiert und ersetzt** die noch unscharfen Abschnitte **M10 (Foodbook)** und
> **M11+ (Domänen-Platzhalter)** von Doc 14 — sie waren bewusst „Zuschnitt mit Dominique
> abstimmen“ / reine Brainstorming-Zeilen. Hier wird daraus eine sequenzierte, baubare
> Landkarte mit Abhängigkeiten, Entscheidungs-Gates und „jetzt vs. später“.
> **Stand:** 2026-06-13 · **Status:** Entwurf zur Abstimmung mit Dominique.

---

## 0. Die eine Kernerkenntnis

Die heutige Planung springt von **Verkaufsrezept → Foodbook**. Das Konzeptpapier schiebt
dazwischen eine **eigene Ebene** ein, die bisher fehlt — den **Concepter**:

```
HEUTE geplant:    GP → Rezept → Gericht (VK) ───────────────────→ Foodbook
                                                                    (M10 alt)

KONZEPTPAPIER:    GP → Rezept → Gericht (VK) → [ CONCEPT ] ───────→ Foodbook
                                                   ▲                  ▲
                                          die fehlende          setzt sich aus
                                          Kompositions-Ebene    vielen Concepts
                                                                 zusammen
```

Ein **Concept** ist ein **Slot-Gerüst**: Slots definieren Rollen (Vorspeise / Hauptgang /
Dessert …), gefüllt mit **einem von zwei** Inhalten — einem **Modul** (austauschbar,
mehrere Optionen, preisgesteuert) oder einem **fest gesetzten Gericht** (Fixkosten).

**Konsequenz für die Roadmap:** Der Concepter ist nicht „eines von sechs Platzhalter-Themen“,
sondern das **Rückgrat**, an dem Foodbook, Zielpreis-Konfigurator, Produktionsrechner und
Speiseplan **alle hängen**. Er muss **vor** dem Foodbook gebaut werden. Genau das ist die
Umsortierung, die dieser Masterplan vornimmt.

> Das alte D-8-Konstrukt **„Kombination“** (wiederverwendbare Menü-/Buffet-Vorlage) ist der
> nächste Verwandte zum Concept — aber das Konzeptpapier formalisiert es deutlich weiter
> (Slots, Rollen, Modul-vs-Gericht, Preis-Input-Konfigurator, HK1/HK2, Speiseplan). Der
> Concepter **ist** die ausgebaute Kombination. Wir bauen nicht beides.

---

## 1. Zielbild & die fünf Bausteine der Vision

> **In einem Satz:** Aus dem gepflegten VK-Bestand lassen sich preis- und kostengesteuerte
> **Concepts** bauen, die in zwei Ausgabeformen — **Foodbook** (nach Anlass) und
> **Speiseplan** (über die Zeit) — münden und bis zur **echten Herstellkosten (HK2)**
> durchgerechnet werden.

| # | Baustein | Was es liefert | Konzeptpapier |
|---|---|---|---|
| **B1** | **Concepter** (Slots · Rollen · Module · Gerichte) | wiederverwendbare Foodkonzepte; Preis als **Output** live | §Zweck, Kernprinzip 1+3 |
| **B2** | **Produktionsrechner HK1 → HK2** | echte Food-Vollkosten; Nebenkosten **am Modul** (wandern beim Tausch mit) | §Produktionsrechner |
| **B3** | **Zielpreis-Konfigurator** | Preis als **Input** — System tauscht Module derselben Rolle gegen den Zielpreis | Kernprinzip 2 |
| **B4** | **Foodbook / Portfolio** | Anlass-Komposition vieler Concepts; Schreibstil-Veredelung; Versand-Snapshot + PDF | §Bezug, M10 (Doc 14) |
| **B5** | **Speiseplan** | dieselben Bausteine über eine Zeitachse (Tag/Woche/Zyklus) | §Speiseplan |

---

## 2. Abhängigkeits-Landkarte

```
                          ┌─────────────────────────────────────────┐
   FERTIG (M0–M9):        │  GP · LA/Preise · Basisrezepte · VK-     │
   Stammdaten + VK-Welt   │  Editor-Vollparität · KI-Hüllen · Review │
                          └───────────────────┬─────────────────────┘
                                               │
                                ┌──────────────▼───────────────┐
                                │  M10  CONCEPTER-FUNDAMENT     │  ◀── das Rückgrat
                                │  Slots · Rollen · Module ·    │
                                │  Gericht-Placement · Editor   │
                                │  (Freiform + Vorlage) ·       │
                                │  Live-Output-Preis            │
                                └──┬─────────┬─────────┬────────┘
                                   │         │         │
          ┌────────────────────────┘         │         └────────────────────┐
          ▼                                   ▼                              ▼
 ┌──────────────────┐            ┌──────────────────────┐        ┌────────────────────┐
 │ M11 FOODBOOK      │            │ M13 ZIELPREIS-       │        │ M14 SPEISEPLAN      │
 │ (committed)       │            │     KONFIGURATOR     │        │ (2. Ausgabeform)    │
 │ composes Concepts │            │ (Ausbaustufe)        │        │ Zeit-Slots · Zyklen │
 │ Snapshot·PDF·Stil │            │ swap-by-Rolle→Ziel   │        │ Wochenbilanz        │
 └──────────────────┘            └──────────┬───────────┘        └─────────┬──────────┘
                                            │                              │
   ┌─────────────────────────┐             │ braucht Kosten-Wahrheit       │ braucht Kosten
   │ M12 PRODUKTIONSRECHNER   │◀────────────┴──────────────────────────────┘
   │ HK1 (Wareneinsatz, ver-  │   (Nebenkosten am Rezept/Modul → wandern beim Tausch mit)
   │ lustkorr.) → HK2 (grob % │
   │ → fein nach Garmethode)  │
   └────────────┬─────────────┘
                ▼
   ┌──────────────────────────────────────────────────────────────────────┐
   │ M15+  OPERATIVE DOMÄNEN (downstream): Produktionsplanung → Einkauf →   │
   │       Lager → Controlling  (verbrauchen Concept/Plan-Mengen + HK2)     │
   └──────────────────────────────────────────────────────────────────────┘
```

**Lesart:** M10 ist sequenziell zwingend zuerst. Danach sind **M11/M12/M13/M14 grundsätzlich
parallelisierbar** — die Pfeile zeigen nur, wer von wem profitiert (z. B. wird der
Foodbook-Cockpit erst mit HK1 zur echten Kostensicht; der Konfigurator tauscht erst mit HK2
*kosten*-bewusst statt nur *preis*-bewusst).

---

## 3. Empfohlene Reihenfolge (mit Begründung)

Das Konzeptpapier sequenziert selbst sehr bewusst („jetzt vs. später“). Diese Reihenfolge
übernimmt das:

| Schritt | Milestone | Warum hier | Konzeptpapier-Sequenz |
|---|---|---|---|
| 1 | **M10 Concepter-Fundament** | Rückgrat — ohne es geht nichts anderes | „Preis als Output (Basis)“, Vorlage=Slot-Gerüst |
| 2 | **M11 Foodbook auf Concept-Basis** | von Dominique bereits zugesagt („kommt definitiv“); braucht nur M10 + die schon vorhandene VK-Preislogik | „Foodbook kommt definitiv“ (Doc 14) |
| 3 | **M12 HK1 + HK2-Struktur (grob)** | Kosten-Wahrheit; läuft **parallel zu M11** (Rezept-Ebene), schärft dessen Cockpit | „HK1 jetzt sauber bauen; HK2 zunächst Pauschal-Aufschlag“ |
| 4 | **M13 Zielpreis-Konfigurator** | das Konzeptpapier nennt es ausdrücklich **Ausbaustufe** | „Preis als Input (Konfigurator, Ausbaustufe)“ |
| 5 | **M14 Speiseplan** | zweite Ausgabeform; wiederverwendet M10-Mechanik + M12-Kosten | „zweite Ausgabeform neben dem Foodbook“ |
| 6 | **M15 HK2-Verfeinerung** | Energie nach Garmethode/Prozess | „Später, Verfeinerung … Optional max. Genauigkeit“ |
| 7 | **M16+ Operative Domänen** | downstream von allem | Doc-14-Platzhalter, jetzt mit klaren Hooks |

> **Parallelisierbar in der Praxis:** Ein Dev kann M11 (Foodbook-Schema/Editor) und M12 (HK1
> auf Rezept-Ebene) gleichzeitig vorantreiben, weil sie sich erst im Cockpit treffen. M13/M14
> setzen jeweils ein **fertiges M10** voraus.

---

## 4. Milestones im Detail

### M10 — Concepter-Fundament  *(Rückgrat · keine externen Blocker außer Entscheid-Gates)*

**Ziel:** Wiederverwendbare Foodkonzepte aus Modulen & Gerichten bauen; Preis live als
Output; Vorlage und Freiform als **eine** Mechanik.

**Hängt ab von:** M9 (VK-Welt fertig). **Entscheidungs-Gates vorab:** D-CON-1, -2, -3, -5, -7.

**Datenmodell-Kern (neu, alle `team_id` + UuidV7 + SoftDeletes + LogsActivity):**

| Tabelle | Zweck |
|---|---|
| `foodalchemist_concepts` | die Mappe: Name, Anlass-Tag, Niveau-Tag, Status, `is_vorlage` (Vorlage = gespeichertes Slot-Gerüst) |
| `foodalchemist_concept_slots` | Rolle/Position je Concept; `rolle_id` (→ Rollen-Vokabular), `position`, `pflicht`/`optional` |
| `foodalchemist_concept_slot_items` | Slot-Inhalt: **entweder** `modul_id` (Referenz) **oder** `vk_recipe_id` (fest gesetztes Gericht) + `menge`/`einheit` |
| `foodalchemist_modules` | austauschbarer Baustein: `rolle_id`, Referenz auf Inhalt (VK-Rezept/Bündel), Preis-/Austauschbarkeits-Metadaten |
| `foodalchemist_vocab_rollen` | Rollen-Vokabular (Grill-Hauptgang, Vorspeise …) — team-erweiterbar |

**Pakete:**

| ID | Paket | Inhalt |
|---|---|---|
| M10-01 | Schema + Rollen-Vokabular | Tabellen oben; Rollen als gepflegtes Vokabular (Vorbild bestehende `vocab_*`); **D-CON-3: keine Concept-in-Concept-Verschachtelung in v1** |
| M10-02 | Module-Browser | Module als eigene, rollen-getaggte, **referenzierte** Bausteine pflegen (Liste + Editor, Jarvis-Dichte wie R13/R14) |
| M10-03 | Concept-Editor (3-Spalten) | Slot-Gerüst links, Slot-Befüllung Mitte (Modul **oder** Gericht je Slot), Live-Cockpit rechts — wiederverwendet das M9/R18-Drei-Spalten-Muster |
| M10-04 | Live-Output-Preis | Slot → Concept aufsummieren über die bestehende D-6/GL-11-Preislogik (keine neue Mathematik) |
| M10-05 | Vorlage = Fork | „Aus Vorlage starten“ kopiert das Slot-Gerüst; Concept lebt danach eigenständig (Vorlage zieht **nicht** durch — D-CON-7); „als Vorlage speichern“ friert ein |

**Bezug zum Bestand:** Live-Summe existiert im Editor (M9) · Niveau-System (haute/gehoben/
klassisch) als Tag wiederverwendbar · die V-21-**Rollen**-Spalte im VK-Editor ist die
Keimzelle für das Rollen-Denken.

**Was jetzt / was später:** *Jetzt:* Modul **als Referenz**, Gericht **als feste Setzung** —
die zwei Wiederverwendungs-Mechaniken sauber trennen (Konzeptpapier §„Zwei getrennte
Mechaniken“). *Später:* GP-Mehrfach-Rollen, datengestützte Slot-Vorschläge.

---

### M11 — Foodbook / Portfolio auf Concept-Basis  *(committed)*

**Ziel:** Anlass-Komposition vieler Concepts zu einem versendbaren Foodbook; Schreibstil-
Veredelung als Kern-Wert; Snapshot + PDF.

**Hängt ab von:** M10 (Foodbook-Blöcke referenzieren Concepts/Module/Gerichte).
**Entscheidungs-Gates:** D-CON-4 (Referenz vs. Kopie im Foodbook).

**Pakete (verfeinert aus Doc-14-M10 + D-8-Spec):**

| ID | Paket | Inhalt |
|---|---|---|
| M11-01 | Schema + Browser | `foodbooks` · `foodbook_kapitel` (Baum) · `foodbook_blocks` (Block-Typen: **concept_ref**, recipe_ref, header, text, image, spacer, variant_group) — D-8 §2 als Vorlage; **V-25-Snapshot ab Tag 1** |
| M11-02 | 3-Panel-Editor | Kapitel-Baum · Block-Liste · Live-Cockpit (EK/VK/Wareneinsatz% rekursiv, `kapitelAggregat`) — D-8 §4 |
| M11-03 | Schreibstil-Transformation | `vk_wording_standard` → Brand-Voice je Schreibstil (11 Stile gepflegt; `vk.marketing`/`vk.wording` registriert) — **der Kern-Wert** |
| M11-04 | Versand-Snapshot + PDF | `status='sent'` friert Preise/Wording ein; PDF/HTML rendert nur `sichtbar=1` + Konsumenten-Felder (kein internes Leak) |

**Bezug zum Bestand:** Komplette D-8-Spec (Services, Baum-Invarianten, Aggregat, Akzeptanz-
Golden-Tests) ist **schon geschrieben** — M11 ist „D-8 implementieren, Concept-ref statt
nur Kombination-ref“. Chat-Assistent bleibt **verworfen** (Dominique 2026-06-12).

---

### M12 — Produktionsrechner HK1 → HK2  *(Kosten-Wahrheit · parallel zu M11)*

**Ziel:** Food-seitige Vollkostenrechnung — was kostet das Essen in der Herstellung wirklich
(kein Personal/Service/Logistik/Marge).

**Hängt ab von:** Rezept-Ebene (vorhanden). Rollt in Concept/Foodbook/Speiseplan auf.
**Entscheidungs-Gates:** D-HK-1 (Bezugsgröße, Skalierung, Garverlust-Richtung, HK2-Umfang).

**Pakete:**

| ID | Paket | Inhalt | jetzt/später |
|---|---|---|---|
| M12-01 | **HK1 sauber** | Σ(GP-Preis × Menge), bereinigt um Garverlust/Schwund pro Position (Brutto-Einkauf → Netto-Teller) | **jetzt** |
| M12-02 | **HK2-Datenstruktur** | Feld „Energie-/Nebenkosten“ **pro Rezept/Modul** anlegen (Migration) — anfangs grob geschätzt | **jetzt** |
| M12-03 | **HK2 grob** | HK1 + X % Pauschal-Aufschlag; Aufschlag als Team-Setting | **jetzt, grob** |
| M12-04 | HK1/HK2 im Concept-Cockpit | Aufsummierung der Kaskade abwärts; HK2 wandert **mit dem Modul** beim Tausch (darum am Modul, nicht am Concept) | mit M10 verzahnt |

> **Designentscheidung (Konzeptpapier, verbindlich):** Nebenkosten sitzen auf **Rezept-/
> Modul-Ebene**, nicht erst auf Concept-Ebene — nur so sinkt HK2 automatisch, wenn man im
> Grillbuffet den langen Schmor-HG gegen einen kalt angerichteten tauscht.

**Bezug zum Bestand:** „Garverluste vorschlagen“ + `per_instance`-Mengen existieren bereits;
`arbeitszeit_min` ist je Rezept gepflegt (Brücke zu HK2/Kalkulation). Das ist die
**„Kalkulation (HK2)“**-Sidebar-Kachel.

---

### M13 — Zielpreis-Konfigurator  *(Ausbaustufe)*

**Ziel:** Preis als **Input** — Zielpreis vorgeben, System schlägt Module vor / tauscht sie.
Greift **nur an Modul-Slots** an (feste Gerichte = Fixkosten, Module = Stellschrauben).

**Hängt ab von:** M10 (Module mit Rollen + Preis-Metadaten), idealerweise M12 (kostenbewusste
Vorschläge). **Entscheidungs-Gates:** D-CON-6 (Tiefe). **Externer Blocker:** echter LLM-Key
für gute Vorschläge; Embeddings für „ähnliche Module derselben Rolle“.

**Pakete:**

| ID | Paket | Inhalt |
|---|---|---|
| M13-01 | Tausch-Logik (deterministisch) | nur Module **derselben Rolle** sind tauschbar; Concept-Preis rechnet bei Tausch automatisch neu (Stellschraube) |
| M13-02 | Zielpreis-Solver | „komm auf X €/Person“ → schlägt Modul-Kombination vor (greedy/Heuristik, ohne LLM lauffähig) |
| M13-03 | ✨ KI-Vorschlag | rollen-konforme Alternativen ranken (braucht LLM/Embeddings) — GL-07-Propose/Accept-Muster |

**Bezug zum Bestand:** Voraussetzung „Module mit Preis-Metadaten + Rollen-Tags“ wird in M10
geschaffen. Der Solver ist die einzige echt neue Mathematik des Masterplans.

---

### M14 — Speiseplan  *(zweite Ausgabeform)*

**Ziel:** Dieselben Bausteine über eine **Zeitachse** verteilen (Tag × Mahlzeit, Woche,
Zyklus) — kein neues Datenmodell, andere Anordnung.

**Hängt ab von:** M10 (Slot-Mechanik: Slot = Zeitpunkt, Inhalt = austauschbarer Baustein),
M12 (Kosten pro Tag/Woche). **Entscheidungs-Gates:** D-PLAN-1 (siehe §5).

**Pakete:**

| ID | Paket | Inhalt |
|---|---|---|
| M14-01 | Zeit-Slot-Schema | Belegung von Tag×Mahlzeit mit Gericht/Modul/**ganzem Concept** |
| M14-02 | Zyklen/Rotation | einmaliger Plan vs. rotierender Zyklus (z. B. 4-Wochen-Plan) |
| M14-03 | Wiederholungs-/Abstandsregeln | verhindert, dass dasselbe Gericht zu oft/zu eng wiederkehrt (GV-Anforderung) |
| M14-04 | Wochenbilanz | Nährwert-/Allergen-/Ausgewogenheits-Sicht über die Woche (Aggregate liegen vor); Sektor-Eignung als Filter |

**Bezug zum Bestand:** Nährwert-/Allergen-Aggregate (GL-08/GL-01) und Sektor-/Niveau-Eignung
(M9-01k) liegen vor. Das ist die **„Speiseplan“**-Sidebar-Kachel.

---

### M15 — HK2-Verfeinerung  *(später)*

Energie pro **Garmethoden-Kategorie** (Kochen/Backen/Schmoren/Kalt → Energieklasse) statt
Pauschale (Hybrid, genauer, weil Strom nicht mit dem Warenwert skaliert). *Optional, maximale
Genauigkeit:* Energie pro konkretem Gar-Prozess (Gerät × Temperatur × Dauer → kWh) —
pflegeintensiv, nur wo es sich lohnt. Hängt an M12.

---

### M16+ — Operative Domänen  *(downstream-Horizont)*

| Domäne | Hook aus diesem Masterplan |
|---|---|
| **Produktionsplanung** | Produktionsaufträge aus Concept-/Speiseplan-Mengen → Yield-Skalierung der Basisrezepte (Mathematik existiert) |
| **Einkauf** | Bestellvorschläge aus Produktionsmengen × Lead-LA (V-29-Vorbestellzeiten sind Felder) |
| **Lager** | Bestände je LA/GP, Wareneingang gegen Bestellung, Chargen → Allergen-Rückverfolgung |
| **Controlling** | Soll/Ist-Wareneinsatz, Margen-Trends (`markup_classes`-Historie), HK2 + KI-Kosten (M9-04) als Bausteine |

Diese vier bleiben **bewusst unscharf** bis M10–M14 stehen — sie verbrauchen deren Mengen
und Kosten. Kein Scope vor dem gemeinsamen Brainstorming.

---

## 5. Entscheidungs-Gates (vor Baubeginn klären)

Aus den „Offenen Punkten“ des Konzeptpapiers + Architektur-Bedarf. **Pflege als Discussions
im Dev-Modul-Package** `platforms-food-alchemisten`.

| Gate | Frage | Empfehlung | Blockiert | Owner |
|---|---|---|---|---|
| **D-CON-1** | Was ist ein **Modul** technisch? | **eigene Entität** `foodalchemist_modules` (Rolle + Inhalts-Referenz + Preis-Metadaten); **Gericht** = direkte VK-Setzung im Slot | M10-01 | Dominique/Dev |
| **D-CON-2** | Rollen-Vokabular fix oder frei? | **gepflegtes Vokabular**, team-erweiterbar (Vorbild `vocab_*`) | M10-01, M13-01 | Dominique |
| **D-CON-3** | Concept-in-Concept-**Verschachtelung**? | **Nein in v1** (eine Ebene über Slots — wie D-8 „keine Kombination-in-Kombination“) | M10-01 | Dominique |
| **D-CON-4** | Concept im Foodbook **Referenz oder Kopie**? | **beides:** Master-Referenz (zieht durch) **+** Fork pro Foodbook (Modul=Referenz, Vorlage/Concept-Kopie=Fork) | M11-01 | Dominique |
| **D-CON-5** | **Kundenbindung:** Concept/Modul global oder kundengebunden? | **team-scoped** Stammdaten + Team-Hierarchie (Eltern→Kind-Kataloge, nativ vorhanden) | M10-01 Scoping | Dominique |
| **D-CON-6** | **Konfigurator-Tiefe?** | phasen: M10 = frei+live · M13-02 = Solver · M13-03 = KI-Vorschlag (optional) | M13-Scope | Dominique |
| **D-CON-7** | **Vorlagen-Pflege/Versionierung?** | Vorlage = Fork (keine Propagation per Design) → „veraltet“ unkritisch; optional „Diff zur Vorlage“ später | M10-05 | Dominique |
| **D-HK-1** | HK2: **Bezugsgröße · Skalierung · Garverlust-Richtung · Umfang?** | HK2 pro Portion; Garverlust Brutto→Netto pro Position; Umfang erst nur Energie; Fix-/Sprungmengen später | M12-Detail | Dominique |
| **D-PLAN-1** | Speiseplan: einzelne Gerichte **oder** ganze Concepts auf Zeit-Slots? | **beides** (Concept „Grillbuffet am Freitag“ **und** Einzel-Gericht) | M14-01 | Dominique |

---

## 6. Mapping auf die heutigen Sidebar-Platzhalter

Die sechs „In Planung“-Kacheln (`config/foodalchemist.php`, alle → `foodalchemist.demnaechst`)
lösen sich so auf:

| Sidebar-Kachel (heute) | wird zu |
|---|---|
| Foodbook / Portfolio | **M11** (+ Concepter-Unterbau **M10**) |
| Kalkulation (HK2) | **M12** (HK1→HK2) + **M15** (Verfeinerung) |
| Produktionsplanung | **M16** |
| Speiseplan | **M14** |
| Einkauf & Lager | **M16** |
| Controlling | **M16** |

> **Neu in der Sidebar nötig:** eine Gruppe/Kachel **„Concepter“** (Module + Concepts) — heute
> gibt es sie nicht, weil der Platzhalter-Block die Ebene überspringt. Außerdem später
> „Zielpreis-Konfigurator“ als Einstieg im Concept-Editor.

---

## 7. Externe Blocker (übernommen aus Phase 1 / Doc 14)

| Was | Wer | Blockiert in diesem Plan |
|---|---|---|
| Echter LLM-Key in der Sandbox | Martin | M11-03 (Schreibstil-Veredelung), M13-03 (KI-Vorschlag) — Mechanik steht |
| Embeddings/RAG | Martin | M13-03 (rollen-konforme Modul-Alternativen ranken) |
| PDF-Engine (Browsershot/DomPDF) | Setup | M11-04 (PDF-Export) |
| D6-Deckungsbeitrags-Formel | Dominique | Margen-Sicht in M11/M12-Cockpits |
| Push/Repo-Sichtbarkeit | Dominique/Martin | jeden Push |

---

## 8. Governance (Goldene Regeln + Dev-Modul)

- **Alles im Modul:** Concepter, Foodbook, HK2, Speiseplan sind **eigene Domänen IM**
  `platforms-foodalchemist` (eigene `foodalchemist_*`-Tabellen, eigene Sidebar-Gruppen) —
  **kein** separates Composer-Paket (würde über Modul-Grenzen lesen, Goldene Regel 3). Eine
  spätere Verkaufs-/Kunden-Portal-Ausgabeschicht kann ausgelagert werden.
- **Core/UI tabu** — `x-ui-*` nutzen, nicht ändern.
- **Dev-Modul-Pflege:** Milestones M10–M16 als Issues aufs **Features-Board** des Packages
  `platforms-food-alchemisten`; die Gates **D-CON-1…7 / D-HK-1 / D-PLAN-1** als
  **Discussions**; dieser Masterplan als **Doc-Seite** (architecture/overview).
- **Reihenfolge bleibt:** erst **Basis** (M10), dann Ausgabeformen — wie in Doc 14 verankert.
