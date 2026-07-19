# Pairing-Offense-Trio — Aroma-treue Substitution · Dish-Reverse-Engineering · Überschuss-zu-Gericht

> **ROADMAP-Bezug:** R6.8 + R6.9 + R6.10 (Track „Alleinstellung"). Ein File, weil alle drei **denselben Hebel offensiv nutzen: den Anker-Pairing-Graph** — nicht beschreibend („was passt"), sondern erzeugend („bau mir was, das trägt").
> **Reifegrad: 🟢 bau-reif** (Code-Kartierung verifiziert 2026-07-19; Etappen + DoD code-verankert). Vorher ⚪ Dossier.

---

## 0. Code-Kartierung (verifiziert 2026-07-19) — was existiert, was fehlt

**Der Graph-Kern ist da.** `src/Services/PairingService.php` liefert alle erzeugenden Primitive:
- `componentSuggestions(recipe, top)` `:361` — rankt Kandidaten-Anker nach `cover`/`mean_w`/`degree`/`spec` (die „was komplettiert den Teller"-Maschine → direkt für **R6.10** nutzbar: Überschuss-Ankerset als „Teller" reingeben).
- `edgesFor(ankerIds)` `:331` / `edgeBest()` `:952` — bester gewichteter Kanten-Wert je Anker-Paar (computed `weight` sonst `GEWICHTE[type]`) → **das „geteilte Aroma-Brücke"-Primitiv**.
- `pairingBridge(recipeA, recipeB)` `:421` — direkte/indirekte Brücken + `bridge_strength` zwischen zwei Gerichten.
- `gpAnkers(gpId)` `:563` — „trägt GP X Anker Y" (join `gp_anchor_mappings`, role=kern).
- `neighborsForName` `:981`, `ankerNeighbors(slug,typ,limit)` `:476`, `recipesSharingPairings(team,recipeId,minShared,limit)` `:442`.
- **Aroma-treue-Basis vorhanden, aber `private`/state-scoped:** `anchorTasteVector` `:775` (7-Achsen), `anchorAromaVector`/`allAnchorAromaVectors` `:851/:870` (14 Aroma-Typen), `vecCos` `:895`, `statePairingNeighbors(anchorId,prepSlug,limit)` `:913` (cosine-gerankte Aroma-Nachbarn der zubereiteten Form). → **GAP: keine öffentliche `aromaTrueSubstitute(gpId)`-Methode.**

**Substitution/Tausch (R6.3-Strecke):** `src/Services/ComponentEquivalentService.php` = **manuell kuratierte Äquivalenz-Tabelle + Namens-Suche** (`sucheZiele` `:92` rankt nur lexikalisch), `tauscheZutat` `:180`, `setSwapLocked` `:212`. **Kein Aroma-/Anker-Scoring.** → R6.8 baut das Aroma-Ranking DARÜBER, ersetzt es nicht.

**Zerlegung fremder Gerichte (R6.9):** `src/Services/IngredientMatchService.php` — `candidatesFor(team,name,slug,k)` `:196` (`{kind:gp|sub,id,name,score,origin}`), `matchIngredient(...)` `:40`, `noMatch()` `:515` (`target='none'` = Mint-Trigger). Hybrid-Semantik (#507) via `SemanticRetrievalService::candidates` `:73`. Unmatched → `LaFirstGpService::mintFromLa(team,text,slug)` `:42` (mint nur bei vorhandener LA, sonst `null` = Beschaffungs-Wunsch).

**Draft-Landung (alle drei):** `RecipeService::create` `:119` (status=draft, `created_via`) + MCP `RecipesPostTool`; `ConceptService::create` `:75` + `ConceptsPostTool`.

**MCP-Lage:** `PairingsGetTool`/`PairingsSuggestTool` sind das read-Muster. **GAP: kein `substitution.*`/`dish.*`/`surplus.*`-Tool; ComponentEquivalentService hat gar keine MCP-Fläche.**

**Datenmodell (Graph):** `pairing_anchor_edges` (`type`, `evidence` text, `source_slug`, `weight` nullable), `gp_anchor_mappings`/`recipe_anchor_mappings` (`source`, `ai_confidence`, `ai_reasoning`), `recipe_process_anchors`, Chem-Labor `anchor_taste_vectors`/`ingredient_aroma_vector`/`pairing_computed` (26 Tabellen, `2026_07_11_000010`).

**Zwei harte Befunde (Scope-relevant):**
1. **Kein unifizierter Evidenz-Tier / Warum-Layer (Q4).** Provenienz ist verstreut: `source` (`manual|ai_inferred`), `ai_confidence`, edge-`evidence`/`weight`, `via` (Laufzeit). → Entscheid unten.
2. **Keine FA-eigene Lagerhaltung/Inventory** (grep `surplus|inventory|CatalogResolver` = 0). „Bestand" im Code = Katalog-Reuse, nicht physischer Bestand. → R6.10 mockt den Input, produktiv erst mit Q1/N-Track.

---

## 1. Festgezurrte Entscheidungen (Planungs-Session 2026-07-19)

| # | Frage | Entscheid | Begründung |
|---|---|---|---|
| E1 | Evidenz-Tier für die Vorschläge? | **v1: bestehende Provenienz je Vorschlag durchreichen** (`source`+`ai_confidence`+edge-`weight`/`type`+`via` → ein `evidenz`-Feld „kuratiert/abgeleitet/ki, conf X"). **Kein** unifizierter Tier-Umbau. | Q4 unified tier ist ein Cross-Cutting-Eigenprojekt; blockiert das Trio unnötig. Die vorhandene Provenienz reicht für „Fakt vs. abgeleitet". Unified-Tier bleibt Q4-Track. |
| E2 | R6.8 Ranking-Formel | **Hybrid: Anker-Kanten-Überlappung (`edgesFor` über beide `gpAnkers`) × Aroma-Vektor-Cosinus (`vecCos`) — Manuell-Äquivalente (`ComponentEquivalentService`) als Boost, R6.3-Kosten als zweite Achse.** | Nutzt beide vorhandenen Basen (Graph + Chem-Vektoren) statt einer neuen Metrik; manuell kuratierte Tausche bleiben oben. |
| E3 | R6.9 Foto-Input | **v1 Text-only** (fremde Karte/Beschreibung als Text). Foto = Ausbaustufe (Multimodal-Provider = Martin). | Textpfad ist voll baubar; Multimodal ist extern geblockt. |
| E4 | R6.10 Bestands-Quelle | **v1 Mock-Input** (`[{gp_id, menge}]`), produktiv über Core-Contract/N-Track. Grenze: FA rechnet/schlägt vor, Bestand+Bestellung = Nachbar-Modul. | Kein FA-Inventory vorhanden/gewollt; erster bidirektionaler Contract-Beweis mit Mock testbar. |
| E5 | Aroma-Basis öffentlich machen | **`statePairingNeighbors`/Vektor-Kern zu einer öffentlichen `aromaTrueSubstitutes(team,gpId,…)` heben** (kein Neubau der Mathematik, nur Fassade + GP-statt-Anker-Einstieg). | Mathematik ist verifiziert vorhanden, nur nicht erreichbar. |

---

## 2. R6.8 — Aroma-treue Substitution · Größe M · Etappe S1 · ✅ GEBAUT 2026-07-19

Ersatz, der den **Geschmack erhält**, nicht nur den Preis senkt.

> **Gebaut 2026-07-19:** `PairingService::aromaTrueSubstitutes()` + private `gpAromaVectorFromMap`/`gpLeadListenEk`/`substCohesionDelta` + MCP `SubstitutionSuggestTool` (registriert) + `AromaSubstitutionTest` (3 Pest, 24 Pairing-Regression grün). **Abweichung zu E2:** graceful `0.6·Kanten + 0.4·Cosinus` statt hartes Produkt (Vektoren zu dünn — hartes Produkt kollabiert das Ranking). **swap_locked** wird gemeldet, aber `tauscheZutat` hat noch keinen harten Guard (R6.3-Altlücke) → Follow-up. Cost = indikativer Lead-LA-Listen-EK (nicht mengennormalisiert).

**Bau (code-verankert):**
- `PairingService::aromaTrueSubstitutes(Team, int $gpId, int $limit=8): array` (neu, öffentlich) — Kandidaten-GPs rankt: `edgesFor`-Überlappung der `gpAnkers` des Quell-GP vs. Kandidat + `vecCos` der Aroma-Vektoren; Ausgabe je Kandidat `{gp_id, name, erhaltene_bruecken[], verlorene_bruecken[], kohaesions_delta, evidenz}`.
- Integration `ComponentEquivalentService`: manuell verknüpfte Äquivalente (`fuer()`) als Boost + Merge; R6.3-Kosten (`ersatzHinweise`) als zweite Achse „billiger UND aroma-treu vs. Trade-off".
- MCP `substitution.SUGGEST` (neu, read-only) — `mode ∈ flavor|cost|both`, `gp_id`/`recipe_ingredient_id`.

**DoD:**
- [x] Ersatz-GP nach Kanten-Überlappung + Aroma-Cosinus gerankt (nicht nur Äquivalenz/Preis).
- [x] Ausgabe: erhaltene vs. verlorene Aroma-Brücken + Kohäsions-Delta fürs Gesamtgericht (`cohesionFor`-Delta).
- [x] Mit R6.3-Kosten kombiniert: „billiger UND aroma-treu" vs. Trade-off sichtbar; `evidenz` je Vorschlag (E1).
- [~] Allergen-Neuberechnung im Vorschlag VOR Tausch ✅; `swap_locked` **gemeldet** (harter `tauscheZutat`-Guard = Follow-up, R6.3-Altlücke).
- [x] MCP `substitution.SUGGEST` (Modi `flavor|cost|both`), read-only bis expliziter Tausch (Tausch bleibt `tauscheZutat`).
- [x] Pest: Klassiker-Tausch (Estragon↔Kerbel) rankt vor aroma-fernem, gleich teurem Ersatz.

## 3. R6.9 — Dish-Reverse-Engineering · Größe L · Etappe S2 · hängt an R1 ✅

Fremdes Gericht → Aroma-Skelett → Nachbau aus **eigenem** Bestand.

**Bau:** neuer `DishReverseService` (orchestriert Vorhandenes):
- Zerlegung Text → GPs: `IngredientMatchService::candidatesFor`/`matchIngredient` (+ #507-Recall); `none` → `mintFromLa` wenn LA da, sonst Review-Queue-Zeile (Beschaffungs-Wunsch, kein Raten).
- Aroma-Skelett: `gpAnkers` je erkanntem GP → `edgesFor` (tragende Anker + Verbund-Kanten).
- Rekonstruktion aus eigenem VK-Portfolio: `recipesSharingPairings` / `componentSuggestions` gegen den Bestand → „nächstes Gericht bei uns" + Lücken („Anker X fehlt im Bestand").
- Zielklick: `RecipeService::create` (Draft) bzw. R6.4 Ideen-Labor.

**DoD:**
- [ ] Input Text/fremde Karte → Zerlegung in GPs (Matching gegen Stamm; Unmatched → Review-Queue, kein Raten; #507-Recall + 07-Mint wo LA existiert).
- [ ] Aroma-Skelett aus dem Pairing-Graph (tragende Anker + Verbund-Kanten).
- [ ] Rekonstruktion aus eigenem VK-Portfolio + Lücken-Report.
- [ ] Ergebnis mündet per Klick in R6.4 / `recipes.POST`-Draft.
- [ ] Foto-Input als Ausbaustufe markiert (Multimodal = Martin); Textpfad zuerst (E3).
- [ ] MCP `dish.REVERSE` (read-only, liefert Zerlegung+Skelett+Kandidaten; Draft-Anlage explizit).
- [ ] Pest: 3 bekannte Gerichte reverse-engineered → Zerlegung plausibilisiert.

## 4. R6.10 — Überschuss-zu-Gericht · Größe M · Etappe S3 · hängt an Q1 + Pairing-Graph

Erster **bidirektionaler** Contract-Fall: Lager meldet Überschuss, FA schlägt Verwertung vor.

**Bau:** `SurplusToDishService` — Input `[{gp_id, menge}]` (Mock/Contract) → `gpAnkers` → Anker-Set als „Teller" in `componentSuggestions`/`edgesFor` → Gerichte/Konzepte, die den GP **tragen** (Anker-Relevanz). MCP `surplus.SUGGEST`.

**DoD:**
- [ ] Input: Überschuss-Bestand als Mock-Liste (produktiv über Core-Contract; NICHT FA-eigene Lagerhaltung).
- [ ] Graph schlägt Gerichte/Konzepte nach Anker-Relevanz (nicht bloß „enthält").
- [ ] Vorschlag mit Verwertungs-Menge + Kohäsions-Begründung; Draft-Konzept per Klick (`ConceptService::create`).
- [ ] Grenze: FA rechnet/schlägt vor, Bestand+Bestellung = Nachbar-Modul (E4).
- [ ] FA-seitig baubar + testbar mit Mock-Bestand; produktiv erst mit Q1/N-Track.
- [ ] MCP `surplus.SUGGEST` (read-only).
- [ ] Pest: Mock-Überschuss rein → sinnvoller Gericht-Vorschlag raus.

---

## 5. Reuse-vs-Neu

| | Reuse (vorhanden) | Neu bauen |
|---|---|---|
| R6.8 | `edgesFor`/`edgeBest`, `gpAnkers`, Aroma-Vektoren+`vecCos`, `ComponentEquivalentService`, `tauscheZutat`/`swap_locked` | `PairingService::aromaTrueSubstitutes` (öffentl.), `substitution.SUGGEST` |
| R6.9 | `candidatesFor`/`matchIngredient`/`noMatch`, `mintFromLa`, `recipesSharingPairings`, `componentSuggestions`, `RecipeService::create` | `DishReverseService`, `dish.REVERSE` |
| R6.10 | `componentSuggestions`, `edgesFor`, `gpAnkers`, `ConceptService::create` | `SurplusToDishService`, `surplus.SUGGEST`, Mock-Input-Contract |

## 6. Reihenfolge + Abhängigkeiten

```
Q5 (Anker-Reichweite + Kohärenz-Score-Lauf) ── halb entblockt ──┐
R6.3 Tausch-Strecke (ComponentEquivalentService) ──► S1 R6.8    │
R1 Portfolio ✅ ──► S2 R6.9                                     ├─► voller Effekt
Q1 Core-Contract + N-Track ──► S3 R6.10 (produktiv; Mock zuerst)─┘
```
**Empfehlung:** S1 R6.8 zuerst (kleinste, baut auf R6.3 + vorhandener Aroma-Mathematik) → S2 R6.9 (Textpfad) → S3 R6.10 (Contract-blockiert, mit Mock vorbereitbar). Alle drei sind fake-provider-/deterministisch testbar; voller Effekt braucht Q5(a) Kohärenz-Score-Lauf (echter Provider).

## 7. Bewusste Nicht-Ziele
- Keine FA-eigene Lagerhaltung (R6.10 = Contract-Konsument).
- Kein Auto-Tausch (immer explizit, Allergen-Check first).
- Kein Foto-Input in v1 (Text zuerst).
- Kein unifizierter Evidenz-Tier-Umbau in v1 (E1 — bestehende Provenienz durchreichen; Q4-Track separat).
- Nichts ohne Evidenz-Kennzeichnung.

*Verzahnt: Q4 (Warum-Layer, separat), Q5 (Graph-Konnektivität), R6.3 (Tausch-Strecke), [07](07_LA_First_GP_Mint_ueberall.md) (GP-Matching im Reverse-Engineering), [08](08_Planungs_und_Kreativ_Ebene.md)/R6.4 (Ideen-Labor als Zielklick), [02](02_RAG_System_FoodAlchemist.md) (#507 Recall). Dossier erstellt 2026-07-18, auf bau-reif gehoben 2026-07-19.*
