# 15 — Masterplan: Von der Concepter-Vision zum Vollausbau

> **Quelle der Vision:** [`concepter-konzept.md`](concepter-konzept.md) (Dominique-Konzeptpapier).
> **Verhältnis zu [`14_ROADMAP_PHASE2.md`](14_ROADMAP_PHASE2.md):** Phase 2 ist die *Arbeits*-Roadmap.
> M9 (VK-Editor-Vollparität) ist dort **komplett** (Suite 397/397). Dieser Masterplan
> **präzisiert und ersetzt** die noch unscharfen Abschnitte **M10 (Foodbook)** und
> **M11+ (Domänen-Platzhalter)** von Doc 14 — sie waren bewusst „Zuschnitt mit Dominique
> abstimmen“ / reine Brainstorming-Zeilen. Hier wird daraus eine sequenzierte, baubare
> Landkarte mit Abhängigkeiten, Entscheidungs-Gates und „jetzt vs. später“.
> **Stand:** 2026-06-13 · **Status:** Gates (§5) entschieden · **M10 (Concepter) KOMPLETT
> GEBAUT** (Suite 412/412, lokal committet) · nächster Schritt M11 (Foodbook auf Concept-Basis).

> **Terminologie-Entscheid (2026-06-13):** Der austauschbare Slot-Baustein heißt bei uns
> **„Baustein“** — im Konzeptpapier „Modul“, aber die Plattform reserviert „Modul“ für ganze
> Pakete (`platform-<modul>`), darum kollisionsfrei umbenannt. Code/Tabellen:
> `foodalchemist_bausteine`, `baustein_id`. **Wo das Konzeptpapier „Modul“ sagt, ist hier
> „Baustein“ gemeint.**
> **Sidebar-Entscheid (2026-06-13):** Der **Zielpreis-Konfigurator** ist ein **Modus im
> Concept-Editor** (kein eigener Nav-Eintrag). **„Kalkulation (HK2)“** bekommt einen eigenen
> Sidebar-Eintrag (Übersicht), surft aber zusätzlich in den Cockpits auf.

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
Dessert …), gefüllt mit **einem von zwei** Inhalten — einem **Baustein** (austauschbar,
mehrere Optionen, preisgesteuert) oder einem **fest gesetzten Gericht** (Fixkosten).

**Konsequenz für die Roadmap:** Der Concepter ist nicht „eines von sechs Platzhalter-Themen“,
sondern das **Rückgrat**, an dem Foodbook, Zielpreis-Konfigurator, Produktionsrechner und
Speiseplan **alle hängen**. Er muss **vor** dem Foodbook gebaut werden. Genau das ist die
Umsortierung, die dieser Masterplan vornimmt.

> Das alte D-8-Konstrukt **„Kombination“** (wiederverwendbare Menü-/Buffet-Vorlage) ist der
> nächste Verwandte zum Concept — aber das Konzeptpapier formalisiert es deutlich weiter
> (Slots, Rollen, Baustein-vs-Gericht, Preis-Input-Konfigurator, HK1/HK2, Speiseplan). Der
> Concepter **ist** die ausgebaute Kombination. Wir bauen nicht beides — beim M10-Bau wird
> die `kombination`-Spec aus D-8 / 02_DATENMODELL vom Concept **abgelöst** (Foodbook-Block
> referenziert künftig `concept_ref` statt `kombination_ref`).

---

## 1. Zielbild & die fünf Bausteine der Vision

> **In einem Satz:** Aus dem gepflegten VK-Bestand lassen sich preis- und kostengesteuerte
> **Concepts** bauen, die in zwei Ausgabeformen — **Foodbook** (nach Anlass) und
> **Speiseplan** (über die Zeit) — münden und bis zur **echten Herstellkosten (HK2)**
> durchgerechnet werden.

| # | Säule | Was es liefert | Konzeptpapier |
|---|---|---|---|
| **S1** | **Concepter** (Slots · Rollen · Bausteine · Gerichte) | wiederverwendbare Foodkonzepte; Preis als **Output** live | §Zweck, Kernprinzip 1+3 |
| **S2** | **Produktionsrechner HK1 → HK2** | echte Food-Vollkosten; Nebenkosten **am Baustein** (wandern beim Tausch mit) | §Produktionsrechner |
| **S3** | **Zielpreis-Konfigurator** | Preis als **Input** — System tauscht Bausteine derselben Rolle gegen den Zielpreis | Kernprinzip 2 |
| **S4** | **Foodbook / Portfolio** | Anlass-Komposition vieler Concepts; Schreibstil-Veredelung; Versand-Snapshot + PDF | §Bezug, M10 (Doc 14) |
| **S5** | **Speiseplan** | dieselben Bausteine über eine Zeitachse (Tag/Woche/Zyklus) | §Speiseplan |

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
                                │  Slots · Rollen · Bausteine · │
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
 │ composes Concepts │            │ Modus im Editor      │        │ Zeit-Slots · Zyklen │
 │ Snapshot·PDF·Stil │            │ swap-by-Rolle→Ziel   │        │ Wochenbilanz        │
 └──────────────────┘            └──────────┬───────────┘        └─────────┬──────────┘
                                            │                              │
   ┌─────────────────────────┐             │ braucht Kosten-Wahrheit       │ braucht Kosten
   │ M12 PRODUKTIONSRECHNER   │◀────────────┴──────────────────────────────┘
   │ HK1 (Wareneinsatz, ver-  │   (Nebenkosten am Rezept/Baustein → wandern beim Tausch mit)
   │ lustkorr.) → HK2 (grob % │
   │ → fein nach Garmethode)  │
   └────────────┬─────────────┘
                ▼
   ┌──────────────────────────────────────────────────────────────────────┐
   │ M16+  OPERATIVE DOMÄNEN (downstream): Produktionsplanung → Einkauf →   │
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
| 4 | **M13 Zielpreis-Konfigurator** | das Konzeptpapier nennt es ausdrücklich **Ausbaustufe**; geliefert als **Modus im Concept-Editor** | „Preis als Input (Konfigurator, Ausbaustufe)“ |
| 5 | **M14 Speiseplan** | zweite Ausgabeform; wiederverwendet M10-Mechanik + M12-Kosten | „zweite Ausgabeform neben dem Foodbook“ |
| 6 | **M15 HK2-Verfeinerung** | Energie nach Garmethode/Prozess | „Später, Verfeinerung … Optional max. Genauigkeit“ |
| 7 | **M16+ Operative Domänen** | downstream von allem | Doc-14-Platzhalter, jetzt mit klaren Hooks |

> **Parallelisierbar in der Praxis:** Ein Dev kann M11 (Foodbook-Schema/Editor) und M12 (HK1
> auf Rezept-Ebene) gleichzeitig vorantreiben, weil sie sich erst im Cockpit treffen. M13/M14
> setzen jeweils ein **fertiges M10** voraus.

---

## 4. Milestones im Detail

### M10 — Concepter-Fundament  *(Rückgrat · keine externen Blocker außer Entscheid-Gates)*

> **Status M10 (2026-06-13): KOMPLETT GEBAUT.** Schema (5 Tabellen: concepts ·
> concept_slots · bausteine · baustein_gerichte · vocab_rollen) + 5 Models + Services
> (BausteinService, ConceptService) + Livewire-UI (Bausteine\Index, Concepts\Index) +
> Routes /bausteine /concepts + Sidebar-Gruppe „Concepter" aktiv. Live-Preis = Σ
> gespeicherte Baustein-Preise (Tausch = nur Differenz); Baustein-Preis manuell oder auto
> (MargeService); Vorlage-Fork + „als Vorlage speichern". **Tests:** ConcepterSchemaTest (4),
> ConcepterServiceTest (8), ConcepterUiTest (3) — Suite **412/412**. Live (headless):
> /concepts + /bausteine = 200, kein 500. Commits 9b69e8b + efe845d (lokal, nicht gepusht).
> *Offen als Folge-Politur:* Jarvis-Dichte/3-Spalten (R-Runde), ▲▼-Slot-Reorder im UI,
> markStaleForRecipe in die RecipeRecompute-Pipeline einhängen.

**Ziel:** Wiederverwendbare Foodkonzepte aus Bausteinen & Gerichten bauen; Preis live als
Output; Vorlage und Freiform als **eine** Mechanik.

**Hängt ab von:** M9 (VK-Welt fertig). **Entscheidungs-Gates vorab:** D-CON-1, -2, -3, -5, -7.

**Datenmodell-Kern (neu, alle `team_id` + UuidV7 + SoftDeletes + LogsActivity):**

| Tabelle | Zweck |
|---|---|
| `foodalchemist_concepts` | die Mappe (z. B. „Grill-Buffet"): Name, Anlass-Tag, Niveau-Tag, Status, `is_vorlage` |
| `foodalchemist_concept_slots` | Rolle/Position je Concept; `rolle_id` (→ **freies** Rollen-Vokabular), `position`, `pflicht`/`optional` |
| `foodalchemist_concept_slot_items` | Slot-Inhalt: **entweder** `baustein_id` (Referenz auf ein Bündel) **oder** `vk_recipe_id` (fest gesetztes Einzel-Gericht) |
| `foodalchemist_bausteine` | **Baustein = bepreistes Bündel mehrerer Gerichte** (Baukasten-Einheit): `rolle_id`, `preis_pro_person`, `ek_pro_person`, `wareneinsatz_prozent`, `preis_modus` (auto aus den Gerichten / manuell). **Der Preis ist gespeichert**, damit ein Tausch im Concept nicht die ganze Kaskade neu rechnet |
| `foodalchemist_baustein_gerichte` | die Gerichte IM Baustein: `baustein_id`, `vk_recipe_id`, `position` (z. B. „Salad Wall" = Green Power · Sunny Kick · Fresh Toskana · Crunchy · Topping) |
| `foodalchemist_vocab_rollen` | **freies** Rollen-Vokabular (Vorspeise · Grill-Hauptgang · Dessert …) — team-erweiterbar |

> **Konkretes Beispiel (DOEC-Foodbook-Seite „Connecting Fire & Flavour"):** Das Concept
> **„Grill-Buffet"** = drei rollen-besetzte Slots, jeder mit einem **Baustein**: Vorspeise →
> Baustein **„Salad Wall"** (4,50 €/P · EK 1,41 € · W 31,3 % — Green Power · Sunny Kick · Fresh
> Toskana · Crunchy · Topping) · Hauptgang → Baustein **„Grill-Station"** (34,50 €/P — Smoke/
> Pastrami · Hot&Fire · Grill Garden · Sides · Dips …) · Dessert → Baustein **„Cool Down"**
> (5,50 €/P · EK 1,53 € · W 27,8 %). **Concept-Preis = Σ der gespeicherten Baustein-Preise.**
> Tauscht der Verkäufer „Salad Wall" gegen die Vorspeisen aus Grill-Buffet 2 (eigener Preis),
> ändert sich der Buffet-Preis nur um die **Differenz** — kein Neuberechnen der ganzen Kaskade.
> Das ist der Baukasten-Sinn der Baustein-Ebene (verkäufer-orientiert).

**Pakete:**

| ID | Paket | Inhalt |
|---|---|---|
| M10-01 | Schema + Rollen-Vokabular | Tabellen oben; Rollen als gepflegtes Vokabular (Vorbild bestehende `vocab_*`); **D-CON-3: keine Concept-in-Concept-Verschachtelung in v1** |
| M10-02 | Baustein-Browser | Bausteine bauen = mehrere Gerichte zu einem rollen-getaggten **Bündel mit eigenem Preis** zusammenfassen; Preis auto aus den Gerichten (Wareneinsatz→Marge) oder manuell, danach **gespeichert**. Liste + Editor (Jarvis-Dichte R13/R14) |
| M10-03 | Concept-Editor (3-Spalten) | Slot-Gerüst links, Slot-Befüllung Mitte (Baustein **oder** fest gesetztes Gericht je Slot), Live-Cockpit rechts — wiederverwendet das M9/R18-Drei-Spalten-Muster |
| M10-04 | Live-Output-Preis | Concept-Preis = Σ der **gespeicherten Baustein-Preise** (+ fest gesetzte Gerichte). Tausch = Preis-**Differenz**, kein Kaskaden-Recompute. Der Baustein-Preis selbst kommt aus D-6/GL-11 (Wareneinsatz→Marge), wird beim Pflegen einmal gerechnet & gecacht; eine GP-Preis-Änderung markiert betroffene Bausteine zur Neuberechnung (GL-02-Muster) |
| M10-05 | Vorlage = Fork | „Aus Vorlage starten“ kopiert das Slot-Gerüst; Concept lebt danach eigenständig (Vorlage zieht **nicht** durch — D-CON-7); „als Vorlage speichern“ friert ein |

**Bezug zum Bestand:** Live-Summe existiert im Editor (M9) · Niveau-System (haute/gehoben/
klassisch) als Tag wiederverwendbar · die V-21-**Rollen**-Spalte im VK-Editor ist die
Keimzelle für das Rollen-Denken.

**Was jetzt / was später:** *Jetzt:* Baustein **als Referenz**, Gericht **als feste Setzung** —
die zwei Wiederverwendungs-Mechaniken sauber trennen (Konzeptpapier §„Zwei getrennte
Mechaniken“). *Später:* GP-Mehrfach-Rollen, datengestützte Slot-Vorschläge.

---

### M11 — Foodbook / Portfolio auf Concept-Basis  *(committed)*

**Ziel:** Anlass-Komposition vieler Concepts zu einem versendbaren Foodbook; Schreibstil-
Veredelung als Kern-Wert; Snapshot + PDF.

**Hängt ab von:** M10 (Foodbook-Blöcke referenzieren Concepts/Bausteine/Gerichte).
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
**Modell (D-HK-1, entschieden):** **Zuschlagskalkulation** — auf den Wareneinsatz (HK1) werden
Gemeinkosten-Zuschläge addiert (HK2). HK2 pro Portion; Garverlust Brutto→Netto pro Position;
anfangs **ein** Pauschal-Zuschlagssatz (Team-Setting), später differenzierte Sätze (Energie
nach Garmethode → M15).

**Pakete:**

| ID | Paket | Inhalt | jetzt/später |
|---|---|---|---|
| M12-01 | **HK1 sauber** | Σ(GP-Preis × Menge), bereinigt um Garverlust/Schwund pro Position (Brutto-Einkauf → Netto-Teller) | **jetzt** |
| M12-02 | **HK2-Datenstruktur** | Feld „Energie-/Nebenkosten“ **pro Rezept/Baustein** anlegen (Migration) — anfangs grob geschätzt | **jetzt** |
| M12-03 | **HK2 grob** | HK1 + X % Pauschal-Aufschlag; Aufschlag als Team-Setting | **jetzt, grob** |
| M12-04 | HK1/HK2 im Concept-Cockpit | Aufsummierung der Kaskade abwärts; HK2 wandert **mit dem Baustein** beim Tausch (darum am Baustein, nicht am Concept) | mit M10 verzahnt |

> **Designentscheidung (Konzeptpapier, verbindlich):** Nebenkosten sitzen auf **Rezept-/
> Baustein-Ebene**, nicht erst auf Concept-Ebene — nur so sinkt HK2 automatisch, wenn man im
> Grillbuffet den langen Schmor-HG gegen einen kalt angerichteten tauscht.

**Bezug zum Bestand:** „Garverluste vorschlagen“ + `per_instance`-Mengen existieren bereits;
`arbeitszeit_min` ist je Rezept gepflegt (Brücke zu HK2/Kalkulation). Das ist die
**„Kalkulation (HK2)“**-Sidebar-Kachel (eigener Übersichts-Eintrag, Entscheid 2026-06-13).

---

### M13 — Zielpreis-Konfigurator  *(Ausbaustufe · Modus im Concept-Editor)*

**Ziel:** Preis als **Input** — Zielpreis vorgeben, System schlägt Bausteine vor / tauscht sie.
Greift **nur an Baustein-Slots** an (feste Gerichte = Fixkosten, Bausteine = Stellschrauben).
**Auslieferung:** kein eigener Sidebar-Eintrag, sondern ein Modus *innerhalb* des
Concept-Editors (Zielpreis-Feld → Vorschlag/Tausch im selben Screen).

**Hängt ab von:** M10 (Bausteine mit Rollen + Preis-Metadaten), idealerweise M12 (kostenbewusste
Vorschläge). **Entscheidungs-Gates:** D-CON-6 (Tiefe). **Externer Blocker:** echter LLM-Key
für gute Vorschläge; Embeddings für „ähnliche Bausteine derselben Rolle“.

**Pakete:**

| ID | Paket | Inhalt |
|---|---|---|
| M13-01 | Tausch-Logik (deterministisch) | nur Bausteine **derselben Rolle** sind tauschbar; Concept-Preis rechnet bei Tausch automatisch neu (Stellschraube) |
| M13-02 | Zielpreis-Solver | „komm auf X €/Person“ → schlägt Baustein-Kombination vor (greedy/Heuristik, ohne LLM lauffähig) |
| M13-03 | ✨ KI-Vorschlag | rollen-konforme Alternativen ranken (braucht LLM/Embeddings) — GL-07-Propose/Accept-Muster |

**Bezug zum Bestand:** Voraussetzung „Bausteine mit Preis-Metadaten + Rollen-Tags“ wird in M10
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
| M14-01 | Zeit-Slot-Schema | Belegung von Tag×Mahlzeit mit Gericht/Baustein/**ganzem Concept** |
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

## 5. Entscheidungs-Gates — ENTSCHIEDEN (Dominique, 2026-06-13)

Aus den „Offenen Punkten“ des Konzeptpapiers. **Alle neun Gates sind entschieden** (unten);
zur Doku zusätzlich als Discussions ins Dev-Modul-Package `platforms-food-alchemisten`.

| Gate | Frage | ✅ ENTSCHIEDEN |
|---|---|---|
| **D-CON-1** | Was ist ein **Baustein**? | **Bepreistes Bündel mehrerer Gerichte**, das eine Rolle füllt (z. B. „Salad Wall", „Grill-Station") — Baukasten für den Verkäufer. Eigener Per-Person-Preis (+EK+W%), **gespeichert**, damit ein Tausch im Concept nicht neu rechnet. Tabellen `foodalchemist_bausteine` + `_baustein_gerichte`. **Gericht** = direkt gesetztes Einzel-Gericht im Slot |
| **D-CON-2** | Rollen-Vokabular fix oder frei? | **Frei** (team-erweiterbar) |
| **D-CON-3** | Concept-in-Concept-Verschachtelung? | **Nein** (eine Ebene über Slots) |
| **D-CON-4** | Concept im Foodbook Referenz oder Kopie? | **Beides** (Master-Referenz + Fork pro Foodbook) |
| **D-CON-5** | Kundenbindung wo? | **Am Foodbook** — Concepts & Bausteine sind team-globale Baukasten-Teile; kundenspezifisch wird erst das Foodbook |
| **D-CON-6** | Konfigurator-Tiefe? | **Phasiert** (M10 frei+live · M13-02 Solver · M13-03 KI optional) |
| **D-CON-7** | Vorlagen-Versionierung? | **Vorlage = Fork**, keine Propagation; optional „Diff zur Vorlage" später |
| **D-HK-1** | HK-Modell? | **HK1 = Herstellkosten als Zuschlagskalkulation** (Wareneinsatz + Gemeinkosten-Zuschläge → HK2); HK2 pro Portion, Garverlust Brutto→Netto pro Position, anfangs ein Pauschal-Zuschlag, später differenziert (Energie nach Garmethode, M15) |
| **D-PLAN-1** | Speiseplan: Gerichte **oder** Concepts auf Zeit-Slots? | **Beides** (Concept „Grill-Buffet am Freitag" **und** Einzel-Gericht) |

---

## 6. Mapping auf die Sidebar (Stand 2026-06-13 umgesetzt)

Die alten sechs gleichrangigen „In Planung“-Kacheln sind durch eine **nach Bau-Sequenz
sortierte** Liste ersetzt (alle weiter auf `foodalchemist.demnaechst`, bis gebaut). Der
Concepter führt:

| Sidebar-Eintrag (neu, in dieser Reihenfolge) | Milestone | Status |
|---|---|---|
| **Concepts** | M10 | Platzhalter |
| **Bausteine** | M10 | Platzhalter |
| Foodbook / Portfolio | M11 | Platzhalter |
| Kalkulation (HK2) | M12 / M15 | Platzhalter (eigener Übersichts-Eintrag) |
| Speiseplan | M14 | Platzhalter |
| Produktionsplanung | M16 | Platzhalter |
| Einkauf | M16 | Platzhalter (aus „Einkauf & Lager“ getrennt) |
| Lager | M16 | Platzhalter |
| Controlling | M16 | Platzhalter |

- **Zielpreis-Konfigurator:** *kein* eigener Eintrag — Modus im Concept-Editor (M13).
- **Sobald M10 gebaut ist:** „Concepts“ + „Bausteine“ wandern in eine **eigene aktive Gruppe
  „Concepter“** über „In Planung“; analog die übrigen Domänen beim jeweiligen Bau.

---

## 7. Externe Blocker (übernommen aus Phase 1 / Doc 14)

| Was | Wer | Blockiert in diesem Plan |
|---|---|---|
| Echter LLM-Key in der Sandbox | Martin | M11-03 (Schreibstil-Veredelung), M13-03 (KI-Vorschlag) — Mechanik steht |
| Embeddings/RAG | Martin | M13-03 (rollen-konforme Baustein-Alternativen ranken) |
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
