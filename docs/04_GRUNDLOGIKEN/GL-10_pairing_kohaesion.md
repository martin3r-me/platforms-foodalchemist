---
typ: Grundlogik-Spec
gl_id: GL-10
stand: 2026-06-10
status: ausgearbeitet
---

# GL-10 — Pairing-Kohäsion & Anker-Graph

> **Normative Quellen:** 07.02-Foodpairing-Doku (kuratiert, Grundlage Ahn et al. 2011 *Flavor Network* + Lahousse/Coucquyt); Kern-Anker-Modell (GP 1–3, Rezept 1–5, `rolle='kern'`).
> **Implementierungs-Quelle (Ist):** `src-tauri/src/commands.rs` (Tauri-App) + CLI-Spiegel `00.04_Scripts/232_query_pairing.py` (identische Logik, gut lesbar — bei Zweifeln am Rust zuerst dort schauen).
> **Domäne:** D-7 Pairing/Flavor-Graph (**Phase 2 ⚠D5**) — die *Daten* (Graph, Mappings) sind aber global/teamlos und werden im Seed mitmigriert (02_DATENMODELL §A.4).

## 1. Zweck & fachliche Quelle

Misst deterministisch (ohne KI-Call), **wie aromatisch zusammenhängend ein Gericht ist** und beantwortet Graph-Fragen: Was passt zu Anker X? Welcher reale GP trägt Aroma X? Was komplettiert ein Gericht? Wie stark ist der Aroma-Übergang zwischen zwei Gängen?

Drei Bausteine:

1. **Kern-Anker-Modell** — jede Entität trägt ihre *Aroma-Identität* als Anker aus dem Vokabular `vocab_pairing_anker` (aktuell 767 Slugs): GPs 1–3 Kern-Anker (Apfel → `apfel`; **niemals Pairing-Partner am GP** — Zimt ist kein Kern eines Apfels), Rezepte 1–5 (komplexere Aromatik, z. B. Gazpacho → tomate+paprika+gurke).
2. **Anker-Graph** — `pairing_anker_edges`: Anker↔Anker-Kanten mit `typ` ∈ {klassisch, modern, kontrast}, geparst aus der kuratierten 07.02-Markdown-Doku (One-Shot-Parser `_oneshot_F_2_parse_anker_edges.py`). Stand 2026-06-10: 23.951 Kanten (12.435 klassisch / 7.902 modern / 3.614 kontrast), **bidirektional gespeichert**.
3. **Abgeleitete Scores/Queries** — Kohäsion, Vorschläge, Bridge, Nachbarn, Reverse-Lookup.

| Funktion (Ist) | Quelle (file:line) | CLI-Spiegel |
|---|---|---|
| `anker_slug_matches` (tolerantes Slug-Matching) | commands.rs:15750 (+ `normalize_slug` :15741) | — |
| `best_identity_anchor` (gerichteter Identitäts-Match) | commands.rs:15772 | — |
| `set_gp_anker` / `list_gp_ankers` / `ai_infer_gp_ankers` (Cap 3) | commands.rs:15826 / :15803 / :15888 | `gp <id|name>` |
| `set_recipe_anker` (Cap 5) / `ai_infer_recipe_ankers` | commands.rs:16139 / :16200 | — |
| `fold_name` / `build_anchor_index` / `resolve_by_name` | commands.rs:16576 / :16639 / :16595 | Z. 75–138 |
| `resolve_recipe_anchors` (geteilte Komponenten-Auflösung) | commands.rs:16694 | Z. 145–227 |
| `recipe_cohesion` (Kohäsions-Score) | commands.rs:16834 | `cohesion` |
| `recipe_component_suggestions` (suggest) | commands.rs:17643 | `suggest` |
| `recipes_sharing_pairings` (verwandte Rezepte) | commands.rs:16457 | — |
| `pairing_anker_neighbors` | commands.rs:24351 | `anker <slug>` |
| `pairing_bridge` | commands.rs:24389 | `bridge A B` |
| Reverse-Lookup Anker→GPs | nur CLI | `anker-gps <slug>` |
| `recipe_culinary_coherence` (Gemini-Judge, **zweite Achse**, gecacht) | commands.rs:17055 ff. | — |

**Abgrenzung:** `recipe_cohesion` misst Aroma-Chemie (geteilte Aromastoffe, deterministisch). Die *kulinarische* Stimmigkeit („passt das als Teller?" — Rind+Kartoffel ist aromatisch leise, aber strukturell stimmig) beurteilt separat ein gecachter Gemini-Judge (`recipe_culinary_coherence`, Hash-Invalidierung bei Komponenten-Änderung). Beide Achsen nebeneinander anzeigen, nie verrechnen.

## 2. Eingaben / Ausgaben / Invarianten

### Tabellen (Quelle → Ziel laut 02_DATENMODELL)

| Quelle | Ziel | Inhalt / Zeilen (2026-06-10) |
|---|---|---|
| `vocab_pairing_anker` | `foodalchemist_vocab_pairing_anker` | 767 Anker: `vocab_id`, `slug` UNIQUE, `display_de`, `file_path` (Vault-Pairing-Doku ⚠D4), `note`. Sonder-Slug `neutral` = „funktional, kein Aroma-Kern" |
| `pairing_anker_edges` | `foodalchemist_pairing_anker_edges` | 23.951: `anker_a_id`, `anker_b_id`, `typ` CHECK(klassisch/modern/kontrast), `evidenz`, `source_slug`; UNIQUE(a,b,typ) |
| `gp_anker_mapping` | `foodalchemist_gp_ankers` | 9.509: `gp_v2_id`, `anker_vocab_id`, `rolle` CHECK('kern'), `quelle` (manual/ai_inferred/auto_slug_match), `ai_confidence`, `ai_begruendung`; UNIQUE(gp,anker) |
| `recipe_anker_mapping` | `foodalchemist_recipe_anker_mapping` | 3.575: analog, pro Rezept |
| `recipe_prozess_anker` | `foodalchemist_recipe_prozess_anker` | 303: Prozess-/Kocharomen (röstaromen/karamell/rauch/ferment) pro Rezept — Volltext-klassifiziert, NICHT aus dem Namen |
| `recipe_pairings` | `foodalchemist_recipe_pairings` | 24.616: kuratierte **Pairing-Partner** pro Rezept; `typ` CHECK(klassisch/kontrast/verbund/trinitas) ≠ Kanten-typ!, `konfidenz` (high/medium/low), `created_via` (gemini/manual/pairing_doc) |
| `recipe_culinary_coherence` | `foodalchemist_recipe_culinary_coherence` | Judge-Cache: `components_hash`, `score` 0–100, `logik`, `begruendung`, `schwachstelle` |

### Invarianten

1. **Cap GP = 1–3 Kern-Anker** (`set_gp_anker`: Fehler „Limit erreicht: max 3 Kern-Anker pro GP." bei Insert über 3; Update bestehender Zeile zählt nicht). **Cap Rezept = 1–5** (analog commands.rs:16157). Als Service-Regel implementieren, nicht nur als DB-Check.
2. **`rolle` kennt nur `'kern'`** (DB-CHECK). Pairing-*Partner* leben getrennt in `recipe_pairings` bzw. im Graph — nie im Anker-Mapping.
3. **Manuell gewinnt:** `set_*_anker` upsertet mit `quelle='manual'` und nullt `ai_confidence`/`ai_begruendung`. KI-Re-Runs löschen nur `quelle IN ('ai_inferred','auto_slug_match')`, nie `manual` (commands.rs:16053, :16412).
4. **Kanten bidirektional gespeichert** (23.776/23.951 haben die Gegenrichtung; Rest = Alt-Datenrest). `recipe_component_suggestions` (Kandidaten-Query nur über `anker_b_id`, Grad-Zählung `GROUP BY anker_a_id`) **setzt Symmetrie voraus**. Rewrite: entweder beim Edge-Insert beide Richtungen schreiben oder alle Queries beidseitig formulieren — eines von beiden, konsequent.
5. **Fehlende Kante = „unbekannt", nicht „Clash".** Der Graph kennt nur Affinitäten, keine Dissonanz. Unbewertete Paare fließen weder in Score noch min_score, sondern in `unrated_pairs`/`coverage`.
6. **Neutral-Anker** (`slug='neutral'`, vocab_id 1044) kommt nie in den Name-Match-Index; explizit gemappt ⇒ Komponente gilt als bewusst kernlos (`via='neutral'`), wird also nie Ausreißer (Gelatine-Fall).
7. Alle Queries read-only auf dem Graph; Schreibpfade nur die `set_/remove_/clear_`-Commands + KI-Accept.
8. Score/fit/coverage sind **gerundete Ganzzahlen 0–100** (`round(100·x)`).

## 3. Algorithmus (Pseudocode)

### 3.1 Komponenten-Auflösung (geteilt von cohesion + suggest)

```
build_anchor_index():
  für jeden Anker (außer 'neutral'):
    terme = [fold(slug) prio 0] + [fold(display) prio 1 falls ≠ slug]
          + [jedes Einzelwort aus beiden, len ≥ 4, prio 2]
    term_index[term] = (vocab_id, prio)   # bestehender Eintrag mit ≤ prio bleibt
  # fold(): lowercase, ä→ae ö→oe ü→ue ß→ss, nicht-alnum→Space, kollabiert, " … " umrandet

resolve_by_name(name):
  folded = fold(name)
  Kandidaten = alle Terme die als SUBSTRING in folded vorkommen
  Gewinner = längster Term; Gleichstand → niedrigere prio (slug > display > Wort)

resolve_recipe_anchors(recipe_id):                # pro Zutaten-Zeile GENAU EIN Kern
  label = COALESCE(sub_rezept.name, gp.gp_name, ri.raw_text)
  wenn Sub-Rezept:  kern = recipe_anker_mapping(rolle='kern',
                       ORDER BY COALESCE(ai_confidence,1.0) DESC, mapping_id LIMIT 1)
                    kern == neutral → kern=NULL, via='neutral'
                    kein Mapping   → resolve_by_name(rezeptname) → via='name_match'
                    prozess[] = recipe_prozess_anker des Sub-Rezepts (ohne kern)
  sonst wenn GP:    analog über gp_anker_mapping, Fallback Name-Match auf gp_name
  sonst:            resolve_by_name(raw_text)
  → ResolvedComp { label, kern|NULL, prozess[], via ∈ {recipe_anker, gp_anker, name_match, neutral, unresolved} }
```

### 3.2 Kohäsions-Score (`recipe_cohesion` — exakt)

```
anker(K)      = [kern] + prozess[]            # 1 Anker für ~alle normalen Komponenten
aufgelöst     = Komponenten mit anker(K) ≠ ∅
edge_best     = beste Kante je ungeordnetem Anker-Paar (max Gewicht über alle typen)

für jedes Paar (i, j) aufgelöster Komponenten:        # total_pairs = n·(n−1)/2
  w(i,j) = max über alle (ka ∈ anker(i), kb ∈ anker(j)):
             ka == kb            → 1.0  (typ "gleich")
             Kante (ka,kb) ex.   → gewicht(typ)        # s. §4 Tabelle 1
             sonst               → ∅
  w ≠ ∅ → rated: all_strengths += w; fit_sum/fit_cnt beider Seiten += w bzw. 1
  w = ∅ → unrated_pairs += (i,j)                       # zählt NICHT in den Score

score        = round(100 · mean(all_strengths))        # 0 wenn keine bewertete Brücke
min_score    = round(100 · min(all_strengths))         # „schwächstes Glied"
coverage_pct = round(100 · rated_pairs / total_pairs)
fit(K)       = round(100 · fit_sum/fit_cnt) | NULL bei 0 bewerteten Links
is_orphan(K) = kern_gesetzt ∧ fit_cnt = 0 ∧ ∃ überhaupt bewertete Paare (any_rated)
weakest_pair = argmin w(i,j) mit Labels + typ          # das „Warum"
```

### 3.3 Komponenten-Vorschläge (`suggest`)

```
dish = Vereinigung aller Anker (kern + prozess) des Gerichts; |dish| < 2 → leeres Ergebnis
Kandidaten = Anker mit ≥ 1 Kante zu dish, selbst ∉ dish
je Kandidat: cover = #getroffene dish-Anker (beste Kante je dish-Anker zählt)
             mean_w = round(100 · Σ best_w / cover)
             degree = Gesamt-Kantenzahl des Kandidaten (Promiskuitäts-Maß)
KLASSIKER:  Filter cover ≥ 2; Sort cover ↓, mean_w ↓, degree ↑, slug ↑; Top 8
SIGNATURE:  Filter cover ≥ 2; spec = (cover · mean_w/100) / √max(degree,1);
            Sort spec ↓, mean_w ↓, slug ↑; Top 8
```

### 3.4 Bridge & verwandte Rezepte (Basis: `recipe_pairings`, nicht Kern-Anker!)

```
pairing_bridge(A, B):
  direkte   = DISTINCT gemeinsame pairing_anker beider Rezepte
  indirekte = Kanten zwischen (Anker von A) × (Anker von B), a ≠ b,
              Sort typ-Priorität (s. §4), LIMIT 30
  bridge_strength = 2·|direkte| + |indirekte|          # max +30 aus indirekt (LIMIT!)

recipes_sharing_pairings(recipe_id, min_shared=2 (min 1), limit=10 (clamp 1–50)):
  Ziel-Rezepte mit ≥ min_shared gemeinsamen pairing_ankern;
  Sort shared ↓, eigene Anker-Gesamtzahl des Ziels ↑ (kleiner = relevanter), recipe_id ↑;
  shared_slugs dedupe + Top 5 je Treffer

pairing_anker_neighbors(slug, typ_filter?, limit=30 (clamp 1–200)):
  alle Kanten des Ankers, Sort typ-Priorität, dann slug

anker_gps(slug, limit=40):                              # Reverse-Lookup „wer trägt Aroma X"
  GPs aus gp_anker_mapping zum Anker; Sort status='approved' zuerst, dann gp_name
```

## 4. Entscheidungstabellen (normativ)

### Tabelle 1 — Kanten-Gewichte & Sortier-Priorität

| Beziehungstyp | Kohäsions-Gewicht (`cohesion_edge_weight`, commands.rs:16563) | Score-Punkte | Sortier-Priorität in Listen |
|---|---|---|---|
| gleicher Anker | 1.00 (implizit, typ „gleich") | 100 | — |
| `klassisch` | 1.00 | 100 | 1 |
| `modern` | 0.75 | 75 | 2 |
| `kontrast` | 0.50 | 50 | 3 |
| unbekannter typ (defensiv) | 0.50 | 50 | 3 |
| keine Kante | — (unrated, zählt nicht) | — | — |

> Achtung Verwechslung: die „3/2/1" aus älteren Notizen ist die **Sortier-Priorität** (klassisch zuerst), nicht das Gewicht. Bei `bridge_strength` gilt zusätzlich direkt = ×2, indirekt = ×1.

### Tabelle 2 — Slug-Toleranz (`anker_slug_matches`, ungerichtet — fürs Pairing-Doku-Grounding)

Normalisierung `normalize_slug`: lowercase; ä→a, ö→o, ü→u, ß→ss; **zusätzlich Digraphen ae→a, oe→o, ue→u** („aepfel"→„apfel", „braeburn"→„braburn" — beidseitig gleich angewandt, daher unschädlich).

| Regel (in Reihenfolge) | Beispiel | Match? |
|---|---|---|
| roh exakt gleich | koriander = koriander | ✅ |
| roh: einer ist `_`-Präfix des anderen (generisch↔spezifisch an `_`-Grenze) | koriander ↔ koriander_blatt | ✅ |
| normalisiert exakt gleich | apfel ↔ aepfel | ✅ |
| normalisiert `_`-Präfix | apfel ↔ aepfel_fuji | ✅ |
| **Geschwister-Sorten** (gemeinsamer Stamm, aber keiner Präfix des anderen) | apfel_braeburn ↔ aepfel_granny_smith | ❌ |
| Teilstring ohne `_`-Grenze | rum ↔ rumpsteak | ❌ |

### Tabelle 3 — Identitäts-Anker (`best_identity_anchor`, **gerichtet**, fürs GP-Auto-Mapping)

Nur Anker, die **gleich oder allgemeiner** als die Hauptzutat sind, kommen als Identität in Frage (sonst griffe der generische GP „Aepfel" fälschlich `apfel_braeburn`). Unter den gültigen gewinnt der **längste** (spezifischste) normalisierte Slug. Treffer wird mit `quelle='auto_slug_match'`, confidence 1.0 gesetzt — deterministisch, ohne KI; Gemini füllt nur Rest-Slots bis Cap 3 (Pasten/Blends ohne 1:1-Anker).

| hauptzutat_slug (GP) | gültige Anker (aus den 767) | Gewinner |
|---|---|---|
| `aepfel_braeburn` | apfel, apfel_braeburn | `apfel_braeburn` |
| `aepfel_fuji` | nur apfel (apfel_fuji existiert nicht im Vokabular) | `apfel` |
| `aepfel` | apfel | `apfel` (NIE eine Sorte — gerichtet!) |

### Tabelle 4 — Auflösungs-Kaskade & `via`-Werte (je Rezept-Komponente)

| Zutat-Zeile ist… | 1. Versuch | Fallback | via |
|---|---|---|---|
| Sub-Rezept (`referenced_recipe_id`) | `recipe_anker_mapping` kern (conf ↓, NULL=1.0; mapping_id ↑; LIMIT 1) | Name-Match Rezeptname | `recipe_anker` / `name_match` |
| GP (`gp_v2_id`) | `gp_anker_mapping` kern (analog) | Name-Match gp_name | `gp_anker` / `name_match` |
| nur `raw_text` | Name-Match raw_text | — | `name_match` / `unresolved` |
| Mapping = neutral | kern=NULL, kein Ausreißer | — | `neutral` |

Prozess-Anker werden **nur bei Sub-Rezepten** zusätzlich geladen (Misoglasur = miso-Kern + ferment); für ~alle normalen Komponenten bleibt es bei genau 1 Anker.

## 5. Golden-Testfälle (verbindliche Wahrheit; Daten-Stand DB 2026-06-10)

> Bei Widerspruch gilt: Testfall > Entscheidungstabelle > Pseudocode. T4–T8 sind reale DB-Resultate, reproduzierbar mit CLI `232_query_pairing.py`.

**T1 — Slug-Toleranz positiv:** `anker_slug_matches("apfel", "aepfel_fuji")` ⇒ **true** (normalisiert „apfel" / „apfel_fuji", `_`-Präfix). Ebenso `("koriander_blatt", "koriander")` ⇒ true (roh-Präfix, generisch↔spezifisch).

**T2 — Geschwister-Sorten negativ:** `anker_slug_matches("apfel_braeburn", "aepfel_granny_smith")` ⇒ **false** (normalisiert „apfel_braburn" vs. „apfel_granny_smith": keiner ist `_`-Präfix des anderen). Sorten-Geschwister dürfen sich nie gegenseitig matchen.

**T3 — Identitäts-Anker gerichtet:** Vokabular enthält `apfel`, `apfel_braeburn`, `apfel_granny_smith`. `best_identity_anchor` für Hauptzutat `aepfel_braeburn` ⇒ `apfel_braeburn`; für `aepfel_fuji` ⇒ `apfel`; für `aepfel` ⇒ `apfel` (nie eine Sorte).

**T4 — Kohäsion durchgerechnet (3 Komponenten, Name-Match):** Eingabe-Labels „Erdbeere, Basilikum, Balsamico" (CLI: `cohesion --components`). Anker via name_match: erdbeere, basilikum, balsamico. Kanten: erdbeere–basilikum **modern** (0.75), erdbeere–balsamico **klassisch** (1.0), basilikum–balsamico **klassisch** (1.0).
⇒ `score = round(100·(0.75+1.0+1.0)/3) =` **92**, `min_score =` **75**, `rated/total = 3/3`, `coverage = 100 %`; fit: Erdbeere **88** (= round(100·1.75/2)), Basilikum **88**, Balsamico **100**; `weakest_pair` = Erdbeere↔Basilikum (75, modern); keine Orphans.

**T5 — Reales Rezept mit Ausreißer:** Rezept 1571 „VS: Ochsenherztomate | Fluessiger Mozzarella | Basilikum-Schaum | Balsamico-Perlen". 6/6 Komponenten aufgelöst (tomate, burrata, basilikum via `recipe_anker`, balsamico, olivenoel-extra-vergine, gruyère). ⇒ `score 100`, `min_score 100`, `rated 9/15`, `coverage 60 %`; **Gruyère: fit NULL, rated_links 0, is_orphan = true**. (Cross-Check zweite Achse: Gemini-Judge `recipe_culinary_coherence` für 1571 = score 75, schwachstelle „Gruyere" — die Achsen stimmen inhaltlich überein, bleiben aber getrennt.)

**T6 — Niedrige Coverage ohne Strafwirkung:** Rezept 174 „Dip: Handkäs": 10/10 Komponenten aufgelöst, nur 7/45 Paare bewertet ⇒ `score 100`, `coverage 16 %`; `Handkaese` und `Brie` sind Orphans (aufgelöst, 0 bewertete Links bei any_rated=true). Lehre: wenige, aber starke Brücken ⇒ hoher Score; dünne Datenlage über Coverage getrennt ausweisen.

**T7 — Bridge:** `pairing_bridge(174, 612)` („Dip: Handkäs" ↔ „Fischfond: Aus Edelfischkarkassen"): direkte gemeinsame Pairing-Anker = [`senf_dijon`] (1 Stück), indirekte Kanten = 30 (LIMIT 30 greift) ⇒ `bridge_strength = 2·1 + 30 =` **32**.

**T8 — Suggest-Ranking:** `recipe_component_suggestions(1571)` (6 Teller-Anker): KLASSIKER-Top = `pfeffer-schwarz` (cover 5, Ø 90, Grad 125) vor `erdbeere` (cover 5, Ø 85, Grad 134) — cover gleich, mean_w entscheidet. SIGNATURE-Top = `peperoni` (cover 4, Ø 88, Grad 15) — die Grad-Normalisierung hebt den spezifischen Kandidaten über die promiskuitiven: spec(pfeffer-schwarz) = 4.5/√125 ≈ 0.40 < spec(peperoni) = 3.52/√15 ≈ 0.91.

**T9 — Neutral & unbewertet:** Komponente mit `neutral`-Mapping (z. B. Gelatine) ⇒ kern=NULL, via=`neutral`, zählt nicht zu total_pairs, **nie** Orphan. Gericht mit 0 bewerteten Paaren ⇒ score=0, min_score=0, any_rated=false ⇒ **niemand** ist Orphan (keine Daten ≠ kein Fit).

## 6. Offene Weichen + Verbesserungen

- **⚠D5 (MVP-Schnitt):** D-7 ist Phase 2 — *aber*: Tabellen + Seed gehören in den MVP (FK-Ziele, globale Daten, 02_DATENMODELL §A.4). Die Service-Logik dieses GL kann komplett nachgezogen werden, ohne Schema-Änderung.
- **⚠D4 (Wissens-Auslieferung):** `vocab_pairing_anker.file_path` zeigt heute auf Vault-Markdown (Pairing-Doku, Grounding der KI-Anker-Inferenz). Im Ziel durch FK auf die Knowledge-Tabelle ersetzen; Kanten-`evidenz`/`source_slug` bleiben als Text-Snapshot erhalten.
- **Graph-Pflege-Pipeline:** Quelle des Aromawissens bleibt kuratiertes Markdown; heute synct ein One-Shot-Parser (`_oneshot_F_2_parse_anker_edges.py`) in die DB. Im Ziel: idempotenter Admin-Import-Job, der **beide Kantenrichtungen** schreibt (Invariante 4); die ~175 asymmetrischen Alt-Kanten beim Seed reparieren.
- **Embedding-Re-Compute-Bezug:** Die Plattform setzt auf Re-Embed statt Embedding-ETL (01_ARCHITEKTUR §Performance). Kohäsion/Suggest sind davon unabhängig (reine Graph-Arithmetik), aber: fließen Anker-Slugs in Embedding-Texte von Rezepten/GPs ein, muss nach Anker-Re-Mapping-Läufen ein Re-Embed getriggert werden — Recompute-Hook analog zur GL-02-Kaskade einplanen.
- **Judge-Cache-Invalidierung:** `recipe_culinary_coherence.components_hash` bei jeder Zutaten-Änderung vergleichen (stale → Re-Judge als Queue-Job, V-15); Aroma-Score und Judge-Score nie mischen.
- **Verbesserungsideen (neu, fürs V-Register prüfen):** (a) `bridge_strength` ist durch das indirekte LIMIT 30 gedeckelt — gewichtete Stärke (Σ Kanten-Gewichte statt Zählung) wäre aussagekräftiger; (b) `recipes_sharing_pairings` könnte zusätzlich Kern-Anker (nicht nur `recipe_pairings`) berücksichtigen; (c) Coverage < ~30 % in der UI als „dünne Datenlage" kennzeichnen (vgl. T6).
