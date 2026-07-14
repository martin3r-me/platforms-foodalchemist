# Food Alchemist — Wie das Foodpairing-Modell funktioniert

Stand 2026-07-14. Erklärt die Mechanik der Pairing-/Aroma-Engine — wie FA entscheidet:
*„Was passt zusammen? Hält ein Teller zusammen? Was komplettiert ihn? Wie ändert Zubereitung das?"*
Alles relational in EINER SQL (kein Graph). Code: `PairingService.php` + `RecipeRecomputeService`.

---

## 1. Grundidee in einem Satz

Pairing läuft über ein **Vokabular von Ankern** (essbare Einheiten/Charaktere) mit **getypten Kanten**
dazwischen. Reale Produkte (GPs) und Rezepte werden auf dieses Anker-Vokabular **abgebildet**; alle
Aussagen (passt / Kontrast / Kohäsion) sind Nachschläge + Aggregate auf diesem Anker-Netz.
**Kuratiert gewinnt, Berechnetes (computed) füllt nur Lücken.**

## 2. Die Bausteine (Entitäten in der SQL)

| Baustein | Tabelle | Was es ist |
|---|---|---|
| **Anker** (1.000) | `vocab_pairing_anchors` | das Pairing-Vokabular (767 alt + 233 aus dem Buch) |
| **Kanten** (~179k global) | `pairing_anchor_edges` | Paarungen zwischen Ankern, **getypt: aroma · kontrast · erprobt** (Taxonomie 2026-07-12 — klassisch+modern→`erprobt` verschmolzen, Ära ist kein Fit-Kriterium); mit `evidence` + nullable `weight`. Kuratiert (~34k, `weight`=NULL) + **computed** (~145k, Station 2, gradiertes `weight`) |
| **Aroma-Vektor** (14-Typ) | `ingredient_aroma_vector` | chemischer Aroma-Fingerabdruck je Zutat (fruchtig…chemisch), `method=binary\|kc_derived` |
| **Geschmacks-Achsen** | `anchor_taste_vectors` / `anchor_taste_axis` | süß/salz/sauer/bitter/umami/fett/scharf — für Kontrast/Balance |
| **Zubereitungen** | `preparations` + `prep_aroma_delta`/`prep_taste_delta` | wie ein Zustand das Profil verschiebt |
| **Zutat→Anker** | `gp_anchor_mappings` | welcher reale GP welchen Anker „ist" |
| **Rezept→Anker** | `recipe_anchor_mappings` (kern) + `recipe_process_anchors` (Zustand) | die Signatur eines Rezepts |
| **Anker→Substrat** | `anchor_ingredient_map` (748 m. Vektor) | Brücke Anker → chemische Zutat (für die Vektoren) |

## 3. Die Mechanik (wie gerechnet wird)

**(a) Zutat → Anker (Identität).** Eine Rezept-Zutat (GP) löst über `gp_anchor_mappings` auf ihren
`kern`-Anker auf. Das ist ihre Identität im Pairing.

**(b) Zwei Anker paaren.** `edgeBest()`: existiert eine **kuratierte Kante**, gewinnt sie (getypt +
gewichtet, GEWICHTE: **erprobt 1.0 / aroma 0.9 / kontrast 0.5**). Eine **computed** Kante (Station 2)
trägt ihr eigenes **gradiertes Gewicht** (`weight = 0.6 × Molekül-Konfidenz`) und füllt NUR Lücken —
kuratierte Kanten bleiben unangetastet (Regel `weight ?? GEWICHTE[typ]`). **Fehlende Kante =
unbekannt, nie „Clash".**
- *Harmonie* = geteilte Aromachemie (14-Typ-Vektor-Kosinus / Ahn-Jaccard).
- *Kontrast* = kuratiert **oder** Geschmacks-Achsen-Opposition (Buch S.36) — **nie aus Kosinus** (Ähnlichkeit kann keinen Kontrast erzeugen).

**(c) Zustand (Ebene 2).** Eine Zubereitung verschiebt den Aroma-Vektor konstant:
`preparedVector = unit(Basis-14-Typ) ⊕ scale·prep_aroma_delta` → Kosinus neu gegen alle Anker.
→ **gerösteter Mandel paart anders als roher.** Nur aroma-additive Preps (rösten/räuchern/
karamellisieren/jus/schmoren/reduzieren) erzeugen einen Charakter; kochen/trocknen/hacken nicht.
Engine: `statePairingNeighbors()` / `state_pairing.py`.

**(d) Rezept (Ebene 3) — WICHTIG: kein Durchschnitt.** Ein Rezept ist **NICHT** ein gemittelter
Aroma-Vektor seiner Zutaten (das ist gemessen matschig — ein 13-Zutaten-Mittel paart mit allem und
verdünnt den Zustand auf null). Stattdessen:
- **Signatur-Netz** = die 1–5 kuratierten `kern`-Anker des Rezepts (`recipe_anchor_mappings`).
- **+ Zustands-Charakter** = `recipe_process_anchors` (aus `raw_text`-Prep, z. B. „Sesam, geröstet" → Röst-Charakter).
- Das GANZE Rezept paart über die **vereinigte Nachbarschaft** dieser Signatur (`ankerNachbarnAggregiert`)
  → daraus fallen die emergenten aroma/kontrast-Partner (Beurre blanc = {butter, sahne, zitrone} →
  buttrig-zitrus-Partner, ohne je einen Rezept-Vektor zu speichern).

**(e) Kohäsion — „hält der Teller zusammen?"** `cohesionFor()`/`recipeCohesion()` schlägt die Kanten
**zwischen allen Komponenten-Ankern** eines Rezepts/Tellers nach und aggregiert: `score`,
`coverage_pct`, `weakest_pair`, Orphans. GL-10-Logik: der Kohäsions-Score wird **nie** mit dem
KI-Kohärenz-Urteil verrechnet (zwei getrennte Achsen).

**(f) Vorschlag — „was komplettiert den Teller?"** `componentSuggestions()`: welche Anker paaren gut
mit der bestehenden Signatur (erprobte + Signature-Kandidaten).

**(g) Wissen — „welche Kochregel greift?"** Beim KI-Anlegen zieht FA **deterministisch** (kein
Embedding) passende Wissens-Dokumente (`knowledge_documents` via `knowledge_routings`:
`ai_generate_recipe` → cross_cutting immer + domain/pairing nach Bedarf). Das ist die Kochwissens-
Ebene neben der Aroma-Rechnung — im selben Modul, für den User unsichtbar.

**(h) Graph-first KI (2026-07-13).** Sowohl der in-App-Rezeptgenerator als auch die MCP-Tools ziehen
ihre Pairing-Partner aus dem **Anker-Graphen** (`neighborsForName`, typ-gefiltert je Stil:
klassisch→erprobt, kreativ→erprobt+aroma, gewagt→aroma+kontrast) — nicht mehr aus Markdown-Volltext.
md-Prosa dient nur noch dem Grounding. Beide KIs „denken" damit im selben Anker-Netz.

## 4. Drei Beziehungstypen — nie vermischen

- **„passt zu"** (Pairing, `pairing_anchor_edges`) — Aroma/Geschmack.
- **„ersetzt"** (Substitution, `substitutions`) — Austauschbarkeit.
- **„abgeleitet von"** (Komposition/Derivat, Rezept-Hierarchie) — Herkunft/Vererbung.

## 5. Provenienz & Prinzipien

- Jede Kante trägt Herkunft: `curated` (Dossier/menschlich) · `book` (Buch-Aroma-Layer) · `computed` (molekular).
  **Konsens mehrerer Quellen = höhere Konfidenz.** Kuratiert gewinnt, computed füllt nur Lücken (holes-only).
- **Keine Erfindungen:** jeder Aroma-/Molekül-Wert auf realer Quelle (Ahn 2011 · Buch S.26–27/31 ·
  FooDB · OpenPOM) + Provenienz; fehlend = `low_evidence`, nicht geraten.

## 6. Ehrliche Grenzen (Stand heute)

- **Kosinus kann keinen Kontrast** — Kontrast kommt aus Kuratierung + Geschmacks-Opposition.
- **Kalibrierung ρ ≈ 0,54** (Ziel 0,60): struktureller Deckel (Molekül-*Anzahl* vs. Intensität) — hebt
  nur echtes OAV (OT-Schwellen, extern blockiert) oder scharfe Buch-Räder-Vektoren.
- **Kohäsions-Coverage ~58 %** — Station 2 (2026-07-12) erledigt: computed-Lückenfüllung hob die
  Coverage am Master von 36,6 → 58,1 % (~145k projizierte Kanten, `weight`-gradiert, holes-only).
  Rest-Lücken = Komponenten-Paare ohne kuratierte **und** computed Kante.
- **Geschmacks-Achsen dünn** bei Süße/Salz (bräuchte USDA) — Kontrast-/Balance-Layer noch grob.
- **KI-Kohärenz-Urteil** (`recipe_culinary_coherence`) braucht echten LLM-Provider — leer bis Deploy
  (der Batch-Lauf über das Portfolio ist der offene Q5-Hebel).

## 7. Wo im Code

`platform/modules/platforms-foodalchemist/src/Services/PairingService.php`:
`edgeBest()` (Kanten-Auswahl, `weight ?? GEWICHTE[typ]`) · `cohesionFor()`/`recipeCohesion()` (Kohäsion) ·
`resolveRecipeAnchors()` (Signatur + Zustand) · `statePairingNeighbors()` (Zustands-Pairing) ·
`componentSuggestions()` (Vorschlag) · `neighborsForName()` (Graph-first-KI) ·
`panelRecipe()`/`panelGp()` (Panels: aroma/kontrast/geschmack).
Engine-Spiegel + Bau: `07_WISSEN/07.02_Flavor_Pairing/Datenbank Foodalchemist/` (`state_pairing.py`,
`_Plan_Datenmodell_Chemie-Pairing-DB.md`, `_SKRIPTE_INDEX.md`). Weg vorwärts: `ROADMAP.md` (🚉 Fahrplan).
