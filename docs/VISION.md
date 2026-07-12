# Food Alchemist — Vision & Zielbild

> **Was dieses Dokument ist:** das **Nordstern-Zielbild** von Food Alchemist — wohin das Produkt geht.
> Stand 2026-07-12, auf den heutigen Wissens- und Architektur-Stand gebracht.
>
> **Vision ≠ Architektur.** Die kulinarisch-ökonomische *Theorie* (unten) ist die Richtung und gilt.
> Die *Umsetzung* ist **eine SQL-Wahrheit** (MySQL) — **kein Graph, kein Polyglot/3-DB**. Frühere
> Entwürfe mit Neo4j (Property-Graph) + SPARQL-Triplestore sind **verworfen**; Mehr-Hop/Vererbung
> läuft über MySQL `WITH RECURSIVE`, Aroma-Nähe über vorberechnete Scores (`pairing_computed`).
>
> Umsetzungs-Landkarte + Status: [`ROADMAP.md`](ROADMAP.md) (R-Nummern) · Dev-Board #23 = Tacho.

---

## 1. Das Produkt-Versprechen

Food Alchemist ist das erste **Business-Intelligence-Werkzeug für kreative Küchenchefs**: Es verheiratet
**kreative Freiheit** (Sensorik / Foodpairing) mit **knallharter Ökonomie** (Wareneinsatz / Abfall / Arbeitszeit)
— live, schon beim Schreiben des Rezepts, bevor das erste Gramm verarbeitet wird.

Viele geniale Gerichte scheitern an Kosten oder Food Waste. FA optimiert das am Laptop: aromatisch spannend
**und** hochprofitabel. Verkaufsargument = **Geschwindigkeit + Intelligenz durch KI**, nicht „noch eine WaWi".

---

## 2. Das 5-Ebenen-Modell (die konzeptionelle Hierarchie)

Das kulinarische Modell baut strikt hierarchisch auf. In FA ist das **relational** abgebildet (Rezepte,
Grundprodukte, Zutaten mit `gp_id` XOR `referenced_recipe_id`), Mehr-Ebenen-Aggregation via `WITH RECURSIVE`.

| Ebene | Konzept | Heute in FA |
|---|---|---|
| **1 — Rohstoff** | Nackte Zutat + chemisches Molekülprofil | GP ↔ `molecules`/`ingredient_molecule` (aus FooDB), `ingredient_aroma_vector` |
| **2 — Zustand** | Prozess-Veränderung (Maillard, Fermentation, Püree…) verschiebt das Aroma | Zustands-/Prep-Delta-Pairing (Layer-2), `anchor_*`-Substrate |
| **3 — Werkstatt** | Rezept-Komponenten + Sub-Rezepte; Endaroma & gustatorische Balance | Basisrezepte + Sub-Referenzen (max. 3 Ebenen), `RecipeRecomputeService` (Yield/Allergen/Zusatzstoff/EK, topologisch) |
| **4 — Gericht** | Container aus Komponenten, je mit **Rolle** + **Textur** | VK-Gerichte + Darreichungen (Servierformen); Rolle/Textur-Layer = R6 (St.4) |
| **5 — Event** | Menü / Buffet / Flying Buffet — je eigene Dramaturgie-Logik | Concepter (Konzepte/Events/Facetten), Pakete; Dramaturgie-Scoring = R6 (St.5) |

**Allergene & Zusatzstoffe** vererben sich Ebene 1 → 5 nach oben (ALL-MAXIMAL, „schwächstes Glied" rekursiv,
kein false-confident — Regelwerk §7). **Muttersaucen/Fermentations-Familien** (Sauce Chasseur ⟵ Demi-Glace)
leben heute als **Wissen in der FA-Wissens-DB** (`knowledge_documents`, #469), nicht als Graph-Kanten.

---

## 3. Architektur heute (was wir jetzt wissen)

**EINE SQL = Wahrheit + Laufzeit + Rechenbasis.** Die drei Welten der alten Vision klappen zusammen:

- **Transaktion/WaWi** (Preise, Lieferanten, Rezepte, Verkauf) → relationale Tabellen.
- **Chemie/Fakten** (FooDB: Moleküle, Konzentrationen, Geruchsschwellen) → `molecules`/`molecule_descriptors`/
  `ingredient_molecule` (read-only Nachschlagewerk, aber **in derselben SQL**, kein Triplestore).
- **„Küchen-Gehirn"** (Praxis-Regeln, Rich-Text, Fehlerbehebung, Sensorik-Layer) → **Wissens-DB in FA**
  (`knowledge_documents/aliases/routings`), deterministisch on-demand in ~48 KI-Prompts injiziert.
- **Aroma-Netz** statt Graph: **`pairing_computed`** (≈341k vorberechnete Match-Scores) ist der SQL-Ersatz;
  Mehr-Hop/Ableitungen via `WITH RECURSIVE`. Artefakte aus der Graph-Zeit = reine Historie.

**Mandanten-Modell (Master-Vererbung):** BHG.DIGITAL (Root) = **Master**; globaler Seed (`team_id NULL`) +
Master-Katalog kaskadieren zu den Kind-Teams; jedes Team verwaltet Eigenes, Master/Seed sind read-only.
(Sichtbar = NULL ∪ Ancestry; editierbar = eigenes Team.)

---

## 4. Die Engine — Theorie + realer Bau-Status

### 4.1 Aroma-Harmonie (Chemie) — ✅ gebaut
Der Match-Score über gemeinsame Schlüsselmoleküle, gewichtet nach Wahrnehmbarkeit (OAV):

$$OAV(i,m)=\frac{C(i,m)}{OT(m)}\ (\ge 1);\qquad
Match(i_1,i_2)=\frac{\sum_{m\in M_{shared}}\ln OAV(i_1,m)\,\ln OAV(i_2,m)}{\sqrt{\sum\ln OAV(i_1,\cdot)^2}\,\sqrt{\sum\ln OAV(i_2,\cdot)^2}}\times100$$

Ist als `pairing_computed` vorberechnet. **Gemessene Realität (ehrlich):** Coverage-Deckel ~76 % (FooDB-Datenlimit —
Exoten/Komposita ohne Molekülprofil laufen über den Kanten-/Anker-Graphen), Kalibrierung ρ ≈ 0,54 (struktureller Deckel).

### 4.2 Sensorischer Kontrast (SKF) — 🔵 Vision / R6·St.4
„Birne-Bohne-Speck": Monotonie brechen. Gesamt-Qualität = Aroma-Harmonie **+** Kontrast (Salz-Distanz + Anzahl
distinkter Texturen):

$$Q(\text{Gericht})=\alpha\cdot Match_{Aroma}+\beta\,(\Delta\text{Salz}+\#\text{DistinctTexturen})$$

Braucht den **Rolle/Textur-Layer** je Gericht-Komponente (`role`, `texture`) — heute noch nicht erfasst.
**Gemessenes echtes Loch:** Teller-Kohärenz aktuell ~0,2 % — der Graph ist stark, die Kohärenz die eigentliche Baustelle (R6/#411).

### 4.3 Geschmacks-Editoren (Salz · Säure · Zucker · Trigeminal) — 🔵 Vision / R6·St.3
Modifikatoren auf den Kanten: Säure hebt Fruchtester-OAV, Salz unterdrückt Bitter + hebt Umami/Süße,
Capsaicin/Piperin setzen einen globalen Release-Multiplikator. Matrix-Effekte noch nicht implementiert.

### 4.4 Event-Dramaturgie — 🔵 Vision / R6·St.5 (Basis ✅)
Der Matcher wechselt die Logik je Event-Typ:
- **Menü:** zeitlicher Spannungsbogen — Intensitäts-Kurve steigt, Sektoren-Wechselspiel.
- **Buffet:** simultane Harmonie-Matrix — warnt vor „toxischen" Nachbarn auf dem Teller.
- **Flying/Fingerfood:** Autarkie + breite Verteilung über die 5 Geschmacks-Sektoren.

Concepter/Darreichungen (Event, Servierform, Facetten) sind ✅ da; das **Dramaturgie-Scoring** ist Vision.

### 4.5 Kreativ-Ökonomie (GRS) — teils ✅
$$GRS=\gamma\cdot Q(\text{Gericht})+\delta\cdot\text{Wirtschaftlichkeit}$$
- **Achse A — Wareneinsatz:** ✅ live (`RecipeRecomputeService` EK, `MargeService`, Preis-Alarm R2.1, Simulation R2.2).
- **Achse B — Cross-Utilization / Abfall:** 🔵 Strecke da (`component_equivalents`, Derivat-GPs #475), Katalog dünn.
- **Achse C — Arbeitszeit/Komplexität:** 🔵 offen (differenzierte Lohnsätze #387).

**5 Geschmacks-Sektoren** (Marine · Terrestrisch · Floral/Botanisch · Pastoral/Milch · Fruchtig/Sauer) +
**Kontext-Filter** (Fine Dining · Cook&Chill-Malus · Delivery-Transportstabilität · Kita/Care) = Zielbild;
Regeneration/Servierform bilden die Basis, die Score-Anpassung ist Vision.

---

## 5. Der Nordstern-Nutzen (wohin es geht)

1. **Creative Autocomplete:** Vorschläge, die aromatisch passen **und** die Marge optimieren **und** den Textur-Kontrast absichern.
2. **Simulations-Werkstatt:** „Was passiert mit Geschmack + Wareneinsatz, wenn ich Steinbutt durch Kabeljau ersetze?" (R2.2 read-only ✅, Ersatz-Saving 🔵).
3. **Nachhaltigkeit als Rendite:** vollständige Verwertung von Abschnitten (Karkasse → Fond) maximiert die Küchenrendite — schwarz auf weiß.

---

## 6. Bewusst NICHT (Grenzen aus der Realität)

- **Kein Graph, kein Neo4j/Kùzu/SPARQL, keine Polyglot/3-DB** — eine SQL-Wahrheit, Mehr-Hop via `WITH RECURSIVE`.
- **Keine 27 GB MISKG-Bilddaten** — nur strukturelle Substitutions-Textdaten.
- Lizenz/Provenienz bleibt: jede berechnete Aussage trägt ihre Quelle (`my_core` / `book_logic` / `foodb_raw` / `miskg_substitution`); nichts erfinden, Fehlendes = `low_evidence`.

---

*Umsetzung, Sequenz und DoD je Paket → [`ROADMAP.md`](ROADMAP.md). Diese Datei = das „Warum/Wohin", die Roadmap das „Wie/Wann".*
