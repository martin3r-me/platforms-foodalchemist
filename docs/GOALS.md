# GOALS — Food Alchemist & Plattform-Module

> **Vision:** Eines der mächtigsten Food-Concepter- und Rezeptur-Verwaltungssysteme am Markt —
> das kulinarische Gehirn der Plattform: Rezeptur, Kalkulation, Konzeption, Deklaration, KI.
> (Stand 2026-07-03, Dominique + Claude; Ausführungs-Tracking im Dev-Modul, Package `platforms-food-alchemisten`.)

## Warum wir gewinnen können (Burggraben, existiert bereits)

| Asset | Was es ist |
|---|---|
| **Datenqualitäts-Motor** | Kuratierter GP/LA-Stamm (WaWi-Master, LA-First-Kuration) mit Regelwerken, Preis-Pipeline, Lead-LA-Logik |
| **Deklarationsfeste Aggregation** | 14 EU-Allergene + 18 Zusatzstoffe + Nährwerte/Label, ALL-MAXIMAL mit Konfidenz-Modell und KI/Manual-Lineage |
| **Pairing-Wissenschaft** | Anker-Graph (~24k Kanten, Ahn/Foodpairing-basiert) + 836 Wissens-Docs — als MCP-Tools abrufbar |
| **KI-nativ** | 36 MCP-Tools, LLM legt Rezepte/Konzepte/Foodbooks an (`created_via`-Lineage, draft-only, Mensch gibt frei) |
| **Verkaufs-Modell** | Gericht = Kern + Darreichungen (Formen mit eigenem EK/VK/Komponenten-Delta), Concepter mit Facetten (Servierform · Eventtyp · Einsatzmoment · Saison), Slot-Auflösung bis in den Preis |
| **Multi-Tenant** | Team-Hierarchie: Eltern-Katalog → Kinder-Caterer (D1) |

## Horizont 1 — Wirtschaftlichkeits-Maschine *(macht das System unverzichtbar)*

- [ ] **Preis-Alarm + Marge-Impact:** LA-Preis springt > X % → „betroffen: N Rezepte, M Konzepte, Marge-Delta". Preishistorie + Verknüpfungen existieren — es fehlt nur der Trigger + die Impact-Ansicht.
- [ ] **Was-wäre-wenn-Simulation:** Warengruppen-/Artikel-Preisszenario → Portfolio-Antwort; Ersatzvorschläge direkt aus dem Äquivalenz-Katalog (`component_equivalents`).
- [ ] **Menu-Engineering mit Ist-Zahlen:** Verkaufs-/Bankett-Daten-Import → Stars/Renner/Penner, DB-Ranking, W%-Ampeln übers Portfolio.
- [ ] **Marge-optimale Menü-Assemblierung:** Zielpreis p. P. + Gästezahl + Coverage-Constraints → das System *löst* die DB-maximale Gericht-Kombination übers Portfolio (Operations-Research, kein Raten). Macht die Preisarchitektur (R4) zur aktiven Optimierung.
- [ ] **Saison-Auto-Pricing (intern-vorschlagend):** EK-Schwankung verschiebt Marge aus dem Zielband → Signal „VK-Anpassung empfohlen", nie stiller Kunden-Preissprung. Veröffentlichter VK bleibt ein menschlich freigegebener Snapshot; Verfügbarkeit/Allergene bleiben live.
- [ ] **Kunden-/Event-Bewertung je Gericht:** Bewertungs-Tab am Gericht (Quelle intern-Koch / Kunde / Event, Score + Notiz + Kontext) — liefert die Popularitäts-Achse fürs Menu-Engineering OHNE Produktions-/Verkaufsdaten-Import. (Ersetzt den ursprünglich angedachten „was-wurde-gegessen"-Loop, der ein Produktions-Modul bräuchte.)
- [ ] **Portfolio-Benchmark (BHG-intern):** Über die Caterer im selben System aggregieren statt nur trennen — „deine Allergen-Konfidenz 78 %, Peer-Median 91 %; Ø-W% 4 Punkte unter Gruppe". Externer Benchmark (fremde Caterer) = offene strategische/rechtliche Frage (Vertriebsmodell, Datenschutz), vorerst nicht eingeplant.
- [ ] **Operative Planungs-Blätter (FA-seitig):** Konzept + Gästezahl → skalierte Komponenten-Mengen, Einkaufsliste, Bestellvorschlag je Lieferant (Lead-LA), Produktionsblatt, Arbeitszeiten — als **read-only Ausgaben der bestehenden Kaskade**. Kein neues Modul, kein Contract nötig; macht FA operativ unverzichtbar UND ist die Vorstufe, die den Core-Contract (Q1) de-riskt — der Contract kapselt später genau diese Ausgaben. (Grenze bleibt: berechnete Blätter = FA; operativer Zustand = Nachbar-Modul, s. u.)

## Horizont 2 — Deklaration & Compliance *(Nebenprodukte unserer Datenpflege, hoher Vertriebswert)*

- [ ] **Buffet-Kärtchen & LMIV-Etiketten auf Knopfdruck** (Allergene/Zusatzstoffe sind deklarationsfest).
- [ ] **CO₂e je Gericht/Konzept** + Ausbau Bio-%/Regional-% (spec-Felder existieren) — Ausschreibungs-Anforderung im Event-Catering.
- [ ] **HACCP-Doku generiert** aus Regenerations-/Kerntemperatur-Daten.

## Geführte Planung *(Dominique 2026-07-03: „Canvas existiert, aber da fehlt gefühlt vieles")*

Die standardisierte Rangehensweise existiert als erprobte Skill-Kaskade im Cooking-Jarvis-Vault
(Kunde → Food-DNA → Zielgruppe → **Portfolio-Struktur** → Befüllung → Kalkulation → Abschluss,
mit Statusmaschine) — sie muss als geführter Prozess ins Produkt:

- [ ] **Canvas → Planungs-Gerüst ausbauen:** Zielgruppen/Anlässe, **Mengengerüst** (n Gerichte je
      Kapitel/Gang inkl. Diät-Quoten), Preisarchitektur (Anker + Spannen, Zielpreis p. P.),
      Kunden-Politik (No-Gos, Allergen-Linie), Saison-Abdeckung, Dramaturgie-Vorgaben
      (Gang-Folge / Buffet-Stationen als Slot-Gerüst-Regel).
- [ ] **Soll/Ist-Coverage live:** Beim Befüllen von Foodbook/Konzept prüft das System permanent
      gegen das Gerüst („HG vegan 0/1 ⚠ · Preisspanne DES überschritten · Herbst fehlt").
      Der Plan ist kein Dokument, sondern eine Messlatte.
- [ ] **Phasen-Status je Foodbook/Konzept** (Kontext → Struktur → Befüllung → Kalkulation →
      Freigabe) statt nur draft/aktiv — die Vault-Statusmaschine als Produkt-Feature.
- [ ] Synergie Horizont 3: Das strukturierte Soll-Gerüst IST das Prompt-Material für
      Brief→Konzept — KI füllt gegen dieselbe Messlatte, an der auch Menschen gemessen werden.

## Digitales Foodbook *(vorgezogen — Entscheid Dominique 2026-07-03: interner Use Case zuerst)*

- [ ] **Foodbook als lebendes Web-Dokument statt PDF:** Interne arbeiten direkt mit dem Portfolio — blättern, suchen, filtern nach Facetten (Servierform/Eventtyp/Saison), Diät und Allergenen; Preise/W% intern sichtbar.
- [ ] **Kunden-Ansicht = Sichtbarkeits-Schalter:** dasselbe Foodbook, ohne Interna (EK/W%), im Kunden-CI und Schreibstil — theoretisch teilbar per Link. Später (Horizont 3): Rückkanal (Kunde markiert Favoriten → Angebot).
- [ ] Dynamik statt Redaktions-Stand: Preise/Allergene/Verfügbarkeit kommen live aus dem System — ein Foodbook ist nie veraltet.

## Horizont 3 — Alleinstellung ausspielen *(hat kein Wettbewerber)*

- [ ] **Brief → fertiges Konzept mit Kohäsions-Beweis:** Canvas/Brief → KI baut Konzept aus echten Gerichten, Pairing-Graph prüft die Menüfolge (`cohesion`/`suggest`/`bridge` existieren als Logik).
- [ ] **Angebots-Funnel-Anfang (aus Skill `briefing_parser`):** Kunden-Anfrage (Mail/Formular) → strukturiertes Event-Brief → Konzept-Vorschlag. Das Stück VOR Brief→Konzept.
- [ ] **„Kosten senken"-Assistent (aus Skill `food_cost_optimizer`):** teuerste Komponenten je Gericht/Konzept + Substitutions-Vorschläge MIT Caveats (Substitutions-Wissen + Äquivalenz-Katalog existieren in FA).
- [ ] **Ideen-Labor (aus Skills `flavor_lab` + Trend-Pulse):** Trend-Feed × Pairing-Graph × Portfolio-Lücken → Gericht-/Konzept-Vorschläge („Was fehlt uns zum Sommer-Trend X?").
- [ ] **Kunden-DNA als Steuerungsobjekt (aus Skills `customer_context`/`brand_ci`/`food_dna_canvas`):** Kundenprofil (Vorlieben, No-Gos, CI, Schreibstil) hängt am Konzept/Foodbook und färbt Wording, Vorschläge, Design — Canvas-Typ `food_dna` existiert.
- [ ] **Konzept-Validator-Ausbau (aus Skill `concept_validator`):** `ConcepterBewertungService` erweitert um Machbarkeits-/Zielgruppen-Check gegen die Kunden-DNA.
- [ ] **Sensorik-Radar über die Menüfolge:** Balance-Warnungen (Textur/Geschmack) aus den Sensorik-Daten.
- [ ] **Aroma-treue Substitution (Pairing-Graph offensiv):** nicht „billiger tauschen" (s. `food_cost_optimizer`), sondern Ersatz-GP, der die Pairing-Kanten des Originals *erhält* — der Graph entscheidet, nicht nur der Äquivalenz-Katalog. Nur wir haben den Graph.
- [ ] **Dish-Reverse-Engineering:** Trend-Gericht / fremde Karte → in GPs zerlegen → Aroma-Skelett extrahieren → aus dem *eigenen* VK-Portfolio nachbauen (inkl. Lücken-Hinweis „dieser Anker fehlt uns"). Foto-Input erst mit geklärtem Multimodal-Provider; Start mit Text/Karte.
- [ ] **Überschuss-zu-Gericht:** vorhandener Überschuss-Bestand eines GP → Graph schlägt Gerichte/Konzepte vor, die ihn *geschmacklich sinnvoll* verbrauchen. = erster bidirektionaler Anwendungsfall des Core-Contracts zum künftigen Lager-/Produktions-Modul (FA rechnet/schlägt vor, Nachbar-Modul liefert den Bestand).
- [ ] **Erklärendes Geschmacks-Gehirn (Warum-Layer):** jeder Vorschlag / jedes Gericht trägt eine **belegte Begründung** (Mechanismus: welche Aromakomponenten brücken + Quelle + Evidenz-Stufe) — macht die Pairing-Wissenschaft vom Wächter zum Lehrer. Speist auch Foodbook-/Kundentext („warum dieses Menü funktioniert", im Kunden-Wording). **Nicht verhandelbar: zitiert oder still** — kein Beleg → als Hypothese markiert, nie als Fakt getarnt.
- [ ] **Hypothesen- & Widerspruchs-Modus (R&D):** „was erlaubt die Wissenschaft für Paarung X?" (gerankt mit Mechanismus) + Domain-Doc⇄Graph-Widersprüche als R&D-Agenda. Dünne Evidenz ist kein Defekt, sondern der Forschungs-Backlog.
- [ ] **Küchen-Training auf Portfolio-Basis** *(Ausblick, Academy-Modul konsumiert den Warum-Motor):* Micro-Lessons + Skill-Check aus dem *eigenen* Bestand — personalisiert, zitiert. Reduziert Key-Person-Risiko (tacit chef knowledge → explizit, abfragbar).
- ~~Export-Brücken zu Bankettprofi/Necta~~ — **gestrichen (Dominique 2026-07-03): Necta ist raus.** Bleibt höchstens **Eingangs-Schnittstelle für Preise + Lieferanten-/Katalogdaten** (bestehende Import-Pipeline). Kein VK-Rückweg — FA ist selbst der Master, es gibt keine Insel mehr, zu der eine Brücke nötig wäre.

## Laufender Backlog (konkret, kurzfristig)

- [ ] **Foodbook-2027 Phase 2: 994 VK-Gerichte FA-nativ erstellen** — mit voller Rezeptur (bestehende Basisrezepte + GPs) und Mengen, aus den zwei Foodbook-2027-PDFs (1 Portion + Ansatz), direkt in FA. Kein Import/Sync (WaWi eingefroren). *Größter Hebel, alles Weitere braucht Masse.*
- [ ] **MCP-Darreichungs-Nachzug (M1–M6):** Tools liefern Formen/Facetten/aufgelöste Slot-Preise; `recipes.POST` erzeugt Standard-Darreichung; GL-07-Klassifikator lernt Bauart-Regel (E7).
- [ ] Datenqualität: FA↔WaWi-EK-Divergenz (~9 Rezepte), unbepreiste Ketten, `unbestimmt`-Servierformen kuratieren.
- [ ] Komfort: A3 (Kernrezept geändert → „Varianten prüfen"-Hinweis), A5 (Behälter/Regeneration/Vehikel je Darreichung editierbar).

## Bewusste NICHT-Ziele (Modul-Grenze — Entscheid Dominique 2026-07-03, präzisiert)

**Produktion, Einkauf, Lager, Lieferscheine, Rechnungskontrolle sind NICHT Food Alchemist.**
Dafür ist das **Event-Modul** (bzw. künftige eigene Module) der SaaS vorgesehen — wer Produktion nimmt,
muss Einkauf nehmen, dann Lager, dann Belege: das ist eine eigene ERP-Kette.

Food Alchemist bleibt der **Rechenkern** und liefert über Core-Contracts (Resolver-Interfaces,
nie Model-Zugriff) die Daten, aus denen andere Module Produktion machen:
Konzept + Gästezahl → skalierte Komponenten-Mengen, Lead-LA-Bestellvorschlag je Lieferant,
Arbeitszeiten, Regenerations-Parameter. **Rechnen hier, ausführen dort.**

Präzisierungen (Diskussion 2026-07-03):

1. **Angebot → läuft im Event-Modul.** FA liefert den kulinarischen Teil zu (Konzepte, Preise,
   Staffeln, Cockpit-Werte); die Angebots-Führung Richtung Kunde (inkl. Personal, Logistik,
   Technik) gehört ins Event-Modul. Die heutigen FA-`angebote.*` sind Zuarbeit/Übergang.
2. **Geschirr in FA = Küchen-/Anrichte-Definition** („worauf/worin wird angerichtet", Teller-
   Definition je Darreichung, Leihpreis als Kalkulations-Info). Miet-Logistik (Bestellung,
   Rückgabe, Inventur) = Event-/Beschaffungs-Modul. Grenze: **Bedarf hier, Beschaffung dort.**
3. **Core-Contract nötig:** Das Interface (Konzept + Gästezahl → Mengen/Bestellvorschlag/
   Arbeitszeiten) muss im Core definiert werden → Discussion im Dev-Modul an Martin,
   BEVOR das Event-Modul gebaut wird (sonst Model-Durchgriff und die Grenze ist Makulatur).

## Ausblick: Nachbar-Module (Einkauf · Lager · Produktion · Event)

*Nicht Food Alchemist — aber der SaaS-Nachbar, den FA über Core-Contracts füttert. Hier bewusst als Fernbild skizziert, damit die Contract-Entscheidungen HEUTE richtig fallen.*

Die NICHT-Ziele oben sagen, was FA **nicht** tut. Dieser Abschnitt sagt, **wer es stattdessen tut** und wie FA andockt — ohne seine Grenze zu verletzen.

- **Zwei Zeitpunkte, zwei Eigentümer (Kern-Entscheid 2026-07-04):** Die *berechneten Blätter* (Einkaufsliste, Produktionsblatt, Bestellvorschlag, skalierte Mengen) gehören FA und kommen **zuerst** — reine read-only Kaskaden-Ausgaben (Horizont 1 „Operative Planungs-Blätter", ROADMAP R7). Den *operativen Zustand* (echte Bestände, Bestellungen, Wareneingang, Inventur) besitzt das **Nachbar-Modul, später**. Daten wandern nicht — FA rechnet, das Nachbar-Modul führt aus.
- **Eigenes Modul, nicht in FA:** Produktion/Einkauf/Lager/Event entsteht als separates Plattform-Modul (`platforms-<produktion|event>`) aus dem Modul-Template — Plug-and-Play, eigenes Dev-Modul-Package, eigene Roadmap. Technisch ist die Plattform genau dafür gebaut.
- **Gründungsakt = Core-Contract (Q1):** Das Interface `Konzept + Gästezahl → skalierte Komponenten-Mengen, Lead-LA-Bestellvorschlag je Lieferant, Arbeitszeiten, Regenerations-Parameter` muss VOR der ersten Zeile Modul-Code in `Platform\Core\Contracts` stehen (Discussion an Martin). Ohne das greift das neue Modul in FA-Models → Grenze Makulatur.
- **Erste Scheibe (MVP des Nachbar-Moduls):** der *Konsument* des Contracts — Konzept + Gäste → Produktions-/Bestellvorschlag. Kein eigener kulinarischer Rechenkern, es fragt FA.
- **Bidirektionaler Beweis:** Überschuss-zu-Gericht (Horizont 3) schließt den Kreis — Lager-Modul meldet Überschuss → FA-Graph schlägt Verwertungs-Gericht vor. Erster Fall, in dem der Contract in beide Richtungen läuft.
- **Grenze bleibt:** FA rechnet, das Nachbar-Modul führt aus (Bestellung, Lager, Belege, Miet-Logistik, Angebots-Führung). „Bedarf hier, Beschaffung dort."

## Arbeitsprinzip (KVP)

Live-Testen durch Dominique = Quelle der Wahrheit: jeder Reibungspunkt wird sofort Fix oder
Dev-Modul-Issue. Das System meldet Datenqualität selbst (Signale: EK-Lücken, fehlende Formen,
unbepreiste Ketten) — Ampel statt stiller Drift. **Auch das Wissen meldet seine Lücken:** Evidenz-Ampel über Graph + 836 Docs (Abdeckung/Konfidenz je Anker) — dünne Datenlage wird sichtbar und treibt gezielte Recherche, statt den Warum-Layer erfinden zu lassen; Nutzung verdickt die Evidenz (bestätigte Erklärungen → verifizierte Einträge). Regelwerke schlagen Memory schlagen Code.
