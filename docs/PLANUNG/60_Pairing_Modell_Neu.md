# 60 · Pairing-Backend: Modell

**Stand 2026-10-06 · Branch `feat/pairing-modell-60` (auf Spec 58) · Status: P1–P7c und P8 gebaut und lokal committet; P7-Rest (Netz, Concept-Ranking) offen · nichts gepusht/deployt**

> **Ziel (Dominique):**
> - Das Pairing-Backend wird sauber und stabil modelliert.
> - Das System trifft **klare Aussagen** und trägt die **Kombinationslogik bei der Planung einzelner Gerichte**.
> - **Gerichte bestehen aus Basisrezepten.** Darum sind im Gericht-Netz **Basisrezepte die Knoten**, und vorgeschlagen werden andere Basisrezepte. Die Inspire-Anker arbeiten im Hintergrund: Aus ihnen entsteht das Aromenprofil eines Basisrezepts.
> - Zuerst muss die Logik funktionieren. Datenqualität (GP→Anker-Matching, Wissens-Lücken) kommt danach.
>
> Vorgänger: Spec 58. Diese Spec ersetzt die Übergabe vom selben Tag.

---

## 1 · Ausgangslage auf demo (gemessen 2026-10-06, Team 6)

| Bereich | Bestand | Bewertung |
|---|---|---|
| Anker | 2.628, alle Inspire | sauber, aber ohne Inspire-ID (Wiedererkennung nur über den Slug) |
| Harmonie-Kanten | 279.920 (L3 32.850 / L2 247.070, beide Richtungen), nur `type=aroma` | sauber |
| Kontrast-, berechnete und `erprobt`-Kanten | 0 | Der Code fragt sie trotzdem ab |
| Prozess-Anker | 0 Anker, 0 Zuordnungen | Der Code liest bei JEDER Auflösung `recipe_process_anchors` |
| Tabellen auf alten Anker-IDs | `anchor_taste_axis` 5.733, `book_pairings` 9.400 | 0 Treffer auf Inspire-Anker |
| Chemie/Moleküle | 22 Tabellen, rund 150 MB | Entscheidung: raus |
| Zubereitete Inspire-Varianten | ~690 Anker (gebraten 171, gekocht 155, gebacken 65, geröstet 62 …) | Zubereitung ist bei Inspire schon gemessen |
| Zutaten-Dossiers | 11.975, alle mit `anker_id`. „Braucht“ steht in 4.137 Docs, „Zerstört“ in 1.831, „Verträgt“ in 1.786 | Quelle für das Anker-Wissen |
| Gerichte (1.048) | nur Basisrezepte 251 · gemischt 483 · nur GP 312. 1.145 Basisrezepte werden in Gerichten genutzt | Regelfall: Gericht = Basisrezepte |
| GP → Anker | 4.708 Zuordnungen (3.470 aus der Brücke alt→neu, 500 aus der Wort-Heuristik) | später |

**Die Inspire-Matrix ist vollständig gemessen.** Fehlt eine Kante, ist das Stufe 1 („kein nennenswerter Bezug“, 3,31 Mio. Paare) und keine Lücke. Ausnahme sind 2 Zutaten ohne Daten, die auch nicht importiert sind.

**Gesichert:** alle 30 betroffenen Tabellen als JSONL mit DDL in `COOKING JARVIS/12_DATA/fa_demo_archiv_pairing_2026-10-06/`.

---

## 2 · Grundsätze

1. **Eine Wahrheit je Aussage-Art und genau eine Lesestelle dafür.** Heute rechnen Kohäsion und Generator L2 mit 0,9 und damit fast wie L3. Das gibt es künftig nicht mehr.
2. **Jede Aussage nennt ihre Grundlage:** gemessen, belegt, Dossier-Entwurf oder Dossier geprüft.
3. **„Unbekannt“ ist eine Aussage.** Es wird weder über Namen geraten noch auf Ersatz-Heuristiken ausgewichen.
4. **Feste Vokabulare stehen als Enums im Code:** Stufe, Achse, Verfahren, Kantenart, Aussage-Typ, Grundlage, Status.
5. **Die Identität eines Ankers ist die Inspire-ID.** Ein Re-Import ist idempotent.
6. **Abgeleitetes wird abgeleitet und nie von Hand gepflegt.** Kontrast-Kanten und Rezept-Profile baut ein Befehl aus ihren Quellen. Ein Quell-Hash zeigt, wenn ein Eintrag veraltet ist.
7. **Vor und nach dem Generieren rechnet dasselbe Werk:** die Planungsvorgabe für den Generator genauso wie die Prüfung des fertigen Gerichts.

---

## 3 · Datenmodell

```
HINTERGRUND (Zutat-Ebene)                 VORDERGRUND (Küchen-Ebene)
Anker ──Harmonie (Inspire)──┐
  │   ──Kontrast (abgeleitet)┤
  │   ──Kombination (Dossier)┼──►  Basisrezept-Profil ──Beziehungen──► Basisrezept
  │   ──Konflikt (Dossier)───┘     (Anker-Anteile %,     (Harmonie,      im Gericht-Netz
  └─ Bedarf · Eigenschaft          Eigenschaften,         Spannung,       und in Vorschlägen
     Verfahren (Varianten)         offene Bedarfe)        Konflikt)
```

### Ebene 0 · Anker (Inspire)

`foodalchemist_vocab_pairing_anchors`
- **Neu:** `inspire_id` (char 36, unique, nach dem Backfill NOT NULL) und `inspire_ix` (int).
  - Der Backfill liest die versionierte Datei `database/data/inspire_anchor_ids.csv`.
  - 2.622 Anker lassen sich eindeutig über den Namen zuordnen, 6 Dubletten über das Slug-Suffix: `apricot_puree`/`_84`, `chinese_cabbage`/`_670`, `gochujang_1103`/`_1104`.
- **Weg:** `knowledge_document_id` (wird nie genutzt), `legacy_id` und `source_path`. Ein Inspire-Anker wird künftig an `inspire_id` erkannt.
- **Bleibt:** `slug`, `display_de`, `display_en`, `category`, `subcategory`, `note`.
- **Neu (P4):** `grund_anchor_id` (nullable) und `verfahren` (Enum) für Varianten, zum Beispiel „Kürbis, geröstet“ → Grund-Anker „Kürbis“ mit `geroestet`.
- **Neu (P4):** `aroma_intensitaet` (Faktor, siehe Ebene 3).
- Dossiers des Ankers: über `knowledge_documents.anchor_id` (schon gebaut).

### Ebene 1 · Anker-Graph (Hintergrund)

**1a · Harmonie (gemessen, unveränderlich).** Tabelle `foodalchemist_anchor_harmonie`:
- Spalten `anchor_a_id`, `anchor_b_id` (FK, cascade, PK (a,b), a < b) und `stufe` (2 oder 3).
- 139.960 Zeilen. **Fehlt eine Zeile, gilt Stufe 1.**
- Befüllt wird sie aus `pairing_anchor_edges`. Danach fällt diese Tabelle weg. `inspire-import` schreibt direkt hierher.

**1b · Beziehungen (Wissen, abgeleitet oder aus Dossier).** Tabelle `foodalchemist_anchor_beziehungen`:

| Spalte | |
|---|---|
| `anchor_a_id`, `anchor_b_id` | FK, cascade. Gerichtet: a hat den Bedarf bzw. das Wissen über b |
| `art` | Enum `Kantenart`: `kontrast` · `kombination` · `konflikt` |
| `achse` | Enum `Achse` (bei Kontrast: welcher Bedarf gedeckt wird) |
| `rang` | tinyint (Kontrast: muss/soll × Stärke der Eigenschaft) |
| `grundlage` | `bedarf_x_eigenschaft` · `dossier` |
| `status` | `entwurf` · `geprueft` · `verworfen` |
| `knowledge_document_id`, `beleg`, `quelle_hash` | Herkunft und Veraltet-Erkennung |

- **Kontrast** ist abgeleitet. a hat den Bedarf „Achse X“, b liefert X mit Stufe ≥ 2, und es gibt keinen Konflikt. Ein Harmonie-Filter greift dabei nicht: Kürbis + Reisessig ist aromatisch Stufe 1, aber ein klassischer Kontrast. Der Befehl `foodalchemist:anker-graph-ableiten` baut diese Kanten neu.
- **Kombination** stammt aus „Verträgt“, sobald der Name auf einen Anker aufgelöst ist.
- **Konflikt** stammt aus „Zerstört“, aber nur mit `art=zutat`. Technik- und Zustandsfehler gehen nicht in den Graphen, sondern als Hinweis in die Rezeptprüfung.

**Einzige Lesestelle:** `Pairing\AnkerGraph` mit `stufe(a,b)`, `stufen(ids)`, `partner(a, art, min)` und `beziehungen(ids)`.

### Ebene 2 · Anker-Wissen (Rohstoff für Kontrast und Profile)

Feste Achsen im Enum `Achse` (Vokabular aus dem Pilot):
- **Geschmack:** `saeure suesse salz bitter umami schaerfe fett`
- **Textur:** `knusprig cremig bissfest weich saftig`
- **Bedarf:** `traeger frische roestaroma aromatik kaelte hitze_kurz`

| Tabelle | Inhalt | Kern-Spalten |
|---|---|---|
| `anchor_bedarfe` | was der Anker von außen braucht | anchor_id, achse, staerke `muss`/`soll`, beleg, knowledge_document_id, quelle_hash, status |
| `anchor_eigenschaften` | was der Anker selbst liefert | anchor_id, achse, stufe 0–3, quelle `naehrwert`/`dossier`, beleg, …, status |
| `anchor_komponenten` | Komponenten-Katalog | anchor_id, name, technik, liefert (json Achsen), …, status |

- Die Rohnamen aus „Verträgt“ und „Zerstört“ landen bis zur Auflösung in `anchor_wissen_offen` (anchor_id, art, name, kontext). Daraus entsteht später die Prüfliste.
- Auslesen: `foodalchemist:anker-wissen-auslesen`, LLM über den Core-Contract. Der Prompt ist der Pilot-AUFTRAG, das Ergebnis hat den Status `entwurf`. Als Erstes wird der Pilot mit 50 Ankern importiert.
- Freigabe: anker-weise im Wissens-Browser.

**Verfahren** (Enum `Verfahren`, aus den 34 bestehenden Zubereitungen):
- **Varianten-Zuordnung:** (Grund-Anker, Verfahren) → Inspire-Variante, falls vorhanden.
- **Eigenschafts-Verschiebung:** `vocab_process_sensory_deltas` bleibt und wird um Röstaroma und Textur erweitert.
- Prozess-Anker als eigene Knoten gibt es nicht mehr.

### Ebene 3 · Basisrezept-Profil (Brücke Hintergrund → Vordergrund)

Tabelle `foodalchemist_recipe_aroma_profile`, materialisiert und neu gebaut, sobald sich das Rezept oder seine Quellen ändern:

| Spalte | |
|---|---|
| `recipe_id`, `anchor_id` | PK |
| `anteil` | decimal, Anteil am Aromaprofil in %. Summe 100 je Rezept |
| `verfahren` | Zubereitung dieses Anteils |
| `quelle_hash` | Fingerabdruck über Zutaten, Mengen, Mappings und Anker-Wissen |

**Anteil = Menge × Aroma-Intensität (× Rolle)**, normiert auf 100 %. Anker unter 5 % fallen weg, dann wird neu normiert. Was übrig bleibt, sind die **Kern-Anker**.
- **Menge:** aus `recipe_ingredients` in Gramm. Bei verschachtelten Basisrezepten geht das Unterrezept mit seinem eigenen Profil ein, gewichtet mit seiner Menge. Die Rekursion geht höchstens 3 Ebenen tief, wie im Regelwerk §4.
- **Aroma-Intensität:** die Kraft je Gramm. Ohne sie würde in einem Rosmarin-Kartoffel-Rezept die Kartoffel das Profil bestimmen. Startwerte kommen je Inspire-Kategorie (Gewürze, Kräuter und Würzmittel hoch; Getreide und Stärke niedrig). Verfeinert wird je Anker über die Dossier-Auslese (`entwurf` → `geprueft`). Wasser, Salz und neutrale Stärke haben Intensität 0 und kommen in keinem Profil vor.
- **Rolle:** `aroma_treiber` wird hochgewichtet, `garnitur` nur im Gericht relevant.

Zusätzlich werden je Basisrezept materialisiert:
- **Eigenschaften:** belegt aus den Nährwerten (bestehende `recipe_taste_vectors`), dazu die Eigenschaften der Anker samt Verschiebung durch das Verfahren.
- **Offene Bedarfe:** Bedarfe der Kern-Anker, die **innerhalb** des Rezepts nicht gedeckt sind. Rosmarinkartoffeln brauchen Säure, die Säure ist offen. Chimichurri enthält Essig, ihr Säure-Bedarf ist gedeckt.

### Ebene 4 · Basisrezept-Beziehungen (Vordergrund)

Berechnet aus zwei Profilen P und Q. Die Lesestelle ist `Pairing\RezeptGraph`:

| Beziehung | Formel | Aussage |
|---|---|---|
| **Harmonie** | Σ p_i · q_j · h(Stufe(a_i, b_j)) mit h(3)=1, h(gleicher Anker)=1, sonst 0 | „x % des Aromas harmoniert“ → Stufe harmoniert / passt / neutral nach Schwellen |
| **Spannung** | offener Bedarf von P wird von einer Eigenschaft von Q gedeckt (oder umgekehrt) | „Säure der Chimichurri deckt den Säurebedarf der Rosmarinkartoffeln“ |
| **Kombination** | geprüfte Kombinations-Kante zwischen Kern-Ankern | „Klassiker: …“ |
| **Konflikt** | Konflikt-Kante zwischen Kern-Ankern | Warnung |

- 2 Sterne zählen nicht (Entscheidung Dominique). In der Detailansicht stehen sie leise als „passt“.
- **Top-Beziehungen je Basisrezept** liegen in `foodalchemist_recipe_beziehungen` (recipe_a, recipe_b, art, wert, quelle_hash). Sie werden per Befehl gebaut und sind die Grundlage für schnelle Vorschläge.

### Ebene 5 · Gericht

- **Knoten im Gericht-Netz sind Basisrezepte.** Wird ein GP direkt eingesetzt, gilt er als Einzel-Profil mit 100 % seines Ankers. Das ist dieselbe Logik, nur ohne Sonderfall.
- **Aussagen zum Gericht:** Harmonie zwischen den Komponenten, gedeckte Bedarfe (Spannung), offene Bedarfe, Konflikte und unbekannte Komponenten (ohne Profil).
- **Vorschlag „Was fehlt dem Teller“:** Basisrezepte aus dem Bestand, die
  1. einen offenen Bedarf des Gerichts decken,
  2. mit mindestens einer Komponente harmonieren oder eine geprüfte Kombination haben,
  3. keinen Konflikt auslösen,
  4. zur Ernährungsform passen.

  Vorher prüft die Logik, ob eine **andere Zubereitung einer vorhandenen Komponente** den Bedarf deckt („Kürbis rösten“). Sie kommt als Hinweis zuerst.

### Schnittstelle GP → Anker (später, Datenqualität)

`gp_anchor_mappings` bleibt unverändert. Eine Zutat ohne Zuordnung macht ihr Rezept-Profil unvollständig. Das Profil zeigt seine Abdeckung (Anteil der Rezeptmasse mit Anker) und ist unter einer Schwelle „unbekannt“. Besseres Matching verbessert die Abdeckung automatisch, ohne dass sich an der Logik etwas ändert.

---

## 4 · Konsumenten

| Konsument | heute | neu |
|---|---|---|
| Gericht-Detail („Passt das zusammen?“) | PairingAnalyseService auf Anker-Ebene | Ebene 5: Komponenten-Aussagen + Vorschlag |
| Basisrezept-Detail | dieselbe Analyse | Profil (Kern-Anker %) + Harmonie und Konflikte der Zutaten |
| Netz im Gericht | Anker-Knoten | **Basisrezept-Knoten**, Vorschläge als Basisrezepte |
| Netz im Basisrezept | Anker | Kern-Anker mit Anteil (Hintergrund wird sichtbar) |
| Kohäsion recipe/menu/composer | edgeBest 1,0/0,9 | RezeptGraph-Harmonie + Abdeckung |
| Concept-Generator Ranking | Kanten-Gewinn auf Anker | Beziehungen zwischen den Gerichts-Komponenten |
| Rezept-/VK-Generator (Prompt) | Partnerlisten ●●●/●● | **Kombinationsplan:** Komponenten-Kandidaten (Basisrezepte) für offene Bedarfe, Leit-Palette Stufe 3, Konflikte |
| Nachprüfung eines generierten Gerichts | recipeCohesion als Statistik | Ebene-5-Aussagen am Ergebnis |
| Wissensblock (KnowledgeContextService) | Stil → `erprobt` (bei „klassisch“ leer) | AnkerGraph, nur Stufe 3 |
| MCP pairings.* / composer.* | gemischt | dieselben Aussagen wie die Oberfläche |
| Signale | „fehlt in der Aromadatenbank“ | Wissen zur Prüfung · Widerspruch Wissen↔Messung · Konflikt im Gericht · Wissenslücke nach Nutzung |

---

## 5 · Rückbau

Die Kartierung stammt vom 2026-10-06. Auf keine der Abriss-Tabellen zeigt ein Fremdschlüssel.

**Tabellen droppen** (nach Sicherung):
- **ohne Code-Zugriff:** anchor_taste_axis, book_pairings, molecule_descriptors, molecule_type_map, key_component_molecule, chem_ingredients, miskg_ingredients, ingredient_flavordb_map, flavordb_mol_props, ingredient_taste_axis, aroma_descriptors, flavor_descriptors, prep_taste_delta, taste_axes
- **mit Code-Rückbau:** anchor_taste_vectors, recipe_process_anchors, pairing_computed, molecules, ingredient_molecule, ingredient_key_component, key_components, ingredient_aroma_vector, aroma_types, prep_aroma_delta, preparations, anchor_ingredient_map
- **nach der Umstellung:** pairing_anchor_edges (→ anchor_harmonie), recipe_pairings (838 KI-Chips), recipe_anchor_mappings (161 Beutel). Beide letzten: Entscheidung Dominique, archivieren.

**Code weg:**
- `PairingService`: hypothesizeFor samt Molekül-Helfern, anchorTasteVector, preparedTaste, aggregatedTaste, statePairingNeighbors, anchorAromaVector(s), prozessAnkerBatch, eigenerZustandBatch, TYP_NORMALISIERT, das `computed`-Flag, die Legacy-CASEs, GEWICHTE
- PairingProjectionService und PairingProjectComputedCommand, PairingWipeErprobtCommand, PairingDropLegacyAnchorsCommand, ProcessAnchorGroundCommand, ProcessAnchorService, der OneShot-Schritt `prozessanker` und der Schritt `pairing` (recipe_pairings)
- MCP: `knowledge.HYPOTHESIZE`, `process_anchors.GROUND`, `recipe_pairings.PUT`
- InspireImportService: der Schreibweg nach `anchor_ingredient_map`

**Code zurückbauen:**
- `aromaTrueSubstitutes` ohne Aroma-Vektor
- KnowledgeContextService: Stil-Mapping
- DishReverseService und SurplusToDishService ohne Prozess-Anker
- Signal `WiderspruchWissenGraph` nach Ebene 1b

---

## 6 · Pakete

| # | Paket | Inhalt |
|---|---|---|
| P1 | Anker-Identität | `inspire_id` + Backfill-CSV, `knowledge_document_id` raus, Import idempotent, Dossier ↔ Anker ins Modul ✅ |
| P3 | Rückbau (vor P2 gezogen) | Chemie, alte IDs, Prozess-Anker, Molekül-Hypothesen, Projektion, Einmal-Befehle, import-slice ohne Pairing, Wissensblock nur Stufe 3 |
| P2 | Harmonie | `anchor_harmonie` + `Pairing\AnkerGraph` (stufe/partner). Alle Leser umstellen, edges droppen, Legacy-Typ-CASEs raus |
| P4 | Anker-Wissen | Enums Achse/Verfahren/Kantenart/Status, Wissens-Tabellen, Beziehungs-Tabelle, Varianten (`grund_anchor_id`, `verfahren`), Intensität, Auslese-Command, Pilot-Import, Ableitung Kontrast |
| P5 | Rezept-Profil | `recipe_aroma_profile` + Eigenschaften + offene Bedarfe, Builder mit Hash, Befehl |
| P6 | RezeptGraph + Gericht | Beziehungen, Aussagen, Vorschlag; Spec-58-Analyse geht darin auf |
| P7 | Konsumenten | Panel, Netz (Basisrezept-Knoten), Kohäsion, Concept-Ranking, Generator-Kombinationsplan + Nachprüfung, MCP. **Danach** `recipe_pairings` + `recipe_anchor_mappings` droppen (ihre ~10 Leser werden hier ersetzt, nicht doppelt umgebaut) |
| P8 | Signale | 4 neue, altes raus (50 offene Meldungen schließen) |

Für jedes Paket gilt: Tests grün, Commit lokal. Push, PR und Deploy nur mit Freigabe.

**Reihenfolge geändert (2026-10-06):** P3 vor P2. Die Kanten-Leser waren eng mit totem Code verflochten
(Projektion, Molekül-Hypothesen, Prozess-Anker); nach dem Rückbau bleiben für P2 nur die echten Leser.
Der Schlüssel `prozess` in der Anker-Auflösung bleibt bis P6 als leere Liste stehen.

## 7 · Entschieden (Dominique 2026-10-06)

- Altes Anker-System und Molekül-Daten werden archiviert (Vault und demo).
- Rezept-Pairing-Chips und KI-Anker am Rezept werden archiviert.
- Der Generator nutzt nur Stufe 3.
- Anker-Wissen wird anker-weise im Wissens-Browser freigegeben.
- Gericht = Basisrezepte. Das Gericht-Netz zeigt Basisrezepte, die Anker liegen im Hintergrund.
- Prozess-Anker entfallen. Zubereitung wird zum Verfahren: Sie wählt die Inspire-Variante und verschiebt die Eigenschaften.
- Datenqualität kommt nach der Logik.

## 8 · Offen (bewusst später)

- Startwerte der Aroma-Intensität je Kategorie und Schwellen für harmoniert/passt/neutral. Sie werden in P5/P6 an echten Gerichten kalibriert und dann vorgelegt.
- GP→Anker-Prüfung (`bridge_alt_neu`, `heur_wort`).
- Verfahren aus den Arbeitsschritten lesen. Bis dahin kommt es nur aus Zutatentext und Rezeptname.

---

## 9 · Umsetzungsstand (2026-10-06)

| Paket | Stand | Belege |
|---|---|---|
| P1 Anker-Identität | ✅ | InspireImportTest; Probelauf: Re-Import erkennt alle 2.628 Anker über `inspire_id` |
| P3 Rückbau | ✅ | 26 Tabellen per Migration; Suite P1–P3: 4.841/4.855 grün, 8 rot = bekannte Speiseplan-Fehler (auch auf unverändertem Modul rot) |
| P2 Harmonie | ✅ | `anchor_harmonie` (beide Richtungen, 279.920 Zeilen), `AnkerGraph`; Bewertung bewusst unverändert bis P7 |
| P4 Anker-Wissen | ✅ | Enums Achse/Verfahren/Kantenart/WissensStatus; Pilot 50: 155 Bedarfe, 360 Kombinationen, 801 offen |
| P4b Kategorie-Regeln | ✅ | Lieferseite Träger/Frische/Aromatik/Röstaroma aus Kategorie bzw. Verfahren (1.321 Werte); Startwerte Aroma-Intensität je Kategorie (alle 2.628) |
| P5 Rezept-Profil | ✅ | 3.581 Rezepte (demo-Daten) in 47 s, 3.144 mit Profil; Gramm nach T1-Kaskade (Fix: ml lief als Stück → 25 kg) |
| P6 Kombinationslogik | ✅ | Aussagen + Vorschläge; Schwelle „harmonieren" ab 10 % (obere 20 % von 1.883 Komponenten-Paaren) |
| P7a Eine Logik | ✅ | Panel (`<x-foodalchemist::kombination>`), MCP `foodalchemist.kombination.GET` und Composer lesen dieselbe `Kombinationslogik` |
| P7b Generator | ✅ | `KombinationsPlan` ersetzt die alte Partnerliste: Leit-Aromen nur exakt, Harmonie nur 3★, Bedarfe mit Lieferanten, Konflikte, beim Gericht Basisrezepte als Komponente |
| P7c Eine Bewertungsregel | ✅ | Kohäsion/Ranking/Ersatz: nur 3★ zählt (1,0); zwei gemessene Anker ohne 3★ = neutral 0 (bewertet); ohne Inspire-ID = unbewertet. Rezept-Anker kommen nur noch aus dem Profil (`recipe_profile_anker`). `recipe_pairings` + `recipe_anchor_mappings` per Migration gedroppt, Tools `recipe_anchors.PUT`/`recipe_pairings.PUT`, Prompts `recipe.anker`/`recipe.pairing` und die Handpflege im Panel entfernt; OneShot-Glied `aromaprofil` statt KI-Anker + Pairings |
| P7 Rest | offen | Netz mit Basisrezept-Knoten, Concept-Ranking über `RezeptGraph` |
| P8 Signale | ✅ | `PairingSignale`: `pairing_wissen_pruefen` (Entwurfs-Wissen, Anker Kern in ≥ 3 Rezepten), `pairing_widerspruch_messung` (Dossier-Konflikt gegen 3★-Messung), `pairing_konflikt_im_gericht` (Kombinationslogik, Vorfilter übers Profil), `pairing_wissensluecke` (Kern in ≥ 10 Rezepten, kein Dossier-Wissen). Wissens-Signale nur beim Kurator (`TeamScope::isMaster`) und nur für Anker seiner Rezepte. Werkzeug: MCP `anker_wissen.GET` / `anker_wissen.STATUS` (Freigabe anker-weise oder je Eintrag, danach Kontrast + Profile neu). Altes `widerspruch_wissen_graph`: Detektor entfernt, offene Meldungen per Migration 100008 geschlossen (Freigabe Dominique 06.10.; demo: 385 je Team, 8 Teams). Probe-DB: 36 / 1 / 0 / 102 Signale, alle vier zusammen < 0,5 s |

**Befund P8 (Probe-DB):** Kardamom × Minze — Dossier sagt „stört sich", Foodpairing misst 3★. Größte Wissenslücken nach Nutzung: Sahne (Kern in 441 Rezepten), Butter (286), Kartoffel (232), Milch (224) — Reihenfolge für die Dossier-Auslese.

**Gemessene Abdeckung der Profile (demo-Daten):** ≥ 80 % Masse mit Anker: 1.183 Rezepte · 50–79 %: 878 · 1–49 %: 1.084 · 0: 436.
Das ist die GP→Anker-Datenqualität (Dominique: später).

**Befunde aus den Probeläufen (Datenqualität, nicht Logik):**
- Dossier-Auslese liefert Fehler wie „Rohrzucker liefert knusprig" → Freigabe je Anker nötig.
- Dunkle Schokolade, Lachs u. a. ohne Anker → Profile mit Lücken (ehrlich als Abdeckung sichtbar).
- `recipes.function` nicht überall gepflegt → Vorschläge prüfen zusätzlich die Klasse im Rezeptnamen.

**Regeln, die in P6 dazukamen:** Vorschläge nur tellerfähig (keine Basis/Marinade/Beize/Lake/Sud/Fond),
gleiche Geschmacksrichtung (süß ≠ herzhaft, neutral passt immer), Ernährungsform nur ausdrücklich
(unbekannt ≠ vegan), Formwechsel vor neuer Zutat.
