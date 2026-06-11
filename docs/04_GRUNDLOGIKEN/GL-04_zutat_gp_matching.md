---
typ: Grundlogik-Spec
gl_id: GL-04
stand: 2026-06-10
status: ausgearbeitet
---

# GL-04 — Zutat→GP/Sub-Rezept-Matching (Token-F1-Engine)

> **Normative Quellen:** `regelwerke/Matching_Logik.md` (eingefrorene Fach-Doku, 2026-05-27) · `regelwerke/Regelwerk_Basisrezepte.md` §2 (Verarbeitungs-Reduktion), §4 (Default-Sub-Rezepte), §5 (Default-GPs)
> **Implementierungs-Quelle (Ist, primäre Wahrheit):** `src-tauri/src/recipe_matching.rs` (2.659 Zeilen; **84 `#[test]`-Funktionen ab Z. 1491**) + `src-tauri/src/stemming.rs` (zentraler Stemmer, 7 Tests) + `src-tauri/src/commands.rs` (Aufrufer + Hook-Mapping)
> **Verbesserungen:** V-04 (Reuse-at-Generation/RAG), V-05 (Decompounding) — siehe §6
>
> **Verbindlichkeits-Hierarchie bei Widerspruch: Golden-Testfall (§5) > Entscheidungstabelle (§4) > Pseudocode (§3).** Bei Konflikt Rust ↔ Regelwerk-Doku gilt der **Code** (Matching_Logik.md ist Doku desselben Codes, teils älterer Stand) — alle bekannten Abweichungen sind in §1.3 explizit dokumentiert und entschieden.

---

## 1. Zweck & Quellen

### 1.1 Worum es geht

Die Matching-Engine bildet einen **Zutat-String** (aus KI-Rezeptgenerierung, Rezept-Import, Inline-Edit oder Speiseplan) deterministisch auf genau eines von drei Zielen ab:

1. ein **Grundprodukt** (GP; Quelle `wawi_gp_v2` → Ziel `foodalchemist_gps`),
2. ein **Sub-Rezept** (Basisrezept; Quelle `recipes` mit `ist_verkaufsrezept=0` → Ziel `foodalchemist_recipes`),
3. **kein Ziel** (`no_match` → Hard-Stop im Generator: „GP anlegen" oder „Basisrezept anlegen").

**Grundsatz (Matching_Logik.md):** Der Matcher ist **mechanisch** (Token-Overlap + Slug-Vergleich), kein semantisches Modell. Absurde Treffer werden über strukturelle Regeln verhindert (Slug-Mismatch-Penalty, Spezifitäts-Guard, F1 statt reinem Containment), nicht über Sprachverständnis. Semantik kommt nur über zwei klar abgegrenzte Zusatz-Layer: deterministische Regelwerk-Aliasse (§4/§5 Basisrezepte) und den Embedding-Retrieval-Pass (V-04, §6.1).

### 1.2 Ist-Implementierung (file:line, Rust — alle Angaben 2026-06-10 gegen den Code verifiziert)

| Baustein | Datei:Zeile | Inhalt |
|---|---|---|
| Modul-Docstring | recipe_matching.rs:1–22 | Schwellen (0.85/0.70/0.50) korrekt; **Score-Modell-Absatz VERALTET** → §1.3 A-9 |
| `MatchStatus::from_score` | recipe_matching.rs:63–73 | Schwellen-Bänder (§4.1) |
| `tokenize` | recipe_matching.rs:181–200 | Normalisierung + Tokenizing |
| `normalize_slug` | recipe_matching.rs:203–217 | Slug-Normalisierung |
| `stem_german` (zentraler Stemmer; `commands::reduce_plural` delegiert daran) | stemming.rs:46–81 | Plural-/Flexions-Konvergenz |
| `stem_slug` | recipe_matching.rs:223–229 | Slug segmentweise stemmen |
| `token_matches` | recipe_matching.rs:240–257 | Token-Äquivalenz (exakt/Stem/Prefix) |
| `is_qualifier_token` + `name_outspecifies_slug` | recipe_matching.rs:265–301 | Spezifitäts-Guard (4.4q) |
| `match_score` | recipe_matching.rs:307–406 | Kern-Score (Slug-Exact, F1, Bonus, Penalty) |
| `head_matches_query` | recipe_matching.rs:423–441 | Name-Containment-Floor (4.4o) |
| `variant_rank_resolved` + Zustand/Bio-Helfer | recipe_matching.rs:493–674 | Tiebreaker-Rangmodell (4.4m/n/r/u) |
| `HALBFABRIKAT_MARKER` / `query_is_halbfabrikat` | recipe_matching.rs:705–720 | Pool-Prioritäts-Gate |
| `ZUBEREITUNG_MARKER` / `is_sub_rezept_kandidat` | recipe_matching.rs:728–759 | Button-Heuristik (P8) |
| `default_sub_alias` / `resolve_sub_by_name` | recipe_matching.rs:775–863 | §4-Default-Sub-Aliasse |
| `default_gp_alias` / `resolve_gp_by_name` | recipe_matching.rs:866–993 | §5-Default-GP-Aliasse |
| `match_ingredient_pref_bio` (Hauptfunktion, 7-Arg) | recipe_matching.rs:1027–1129 | Pipeline-Orchestrierung; 4/6-Arg-Wrapper Z. 1001–1023 |
| `best_gp_match` / `best_subrecipe_match` | recipe_matching.rs:1179–1353 | Pool-Scans |
| `candidates_for` / `substring_overlap` | recipe_matching.rs:1355–1489 | Shortlist für LLM-Disambiguierung (4.4p) |
| **Aufrufer 1:** Generator Hook→Parameter-Mapping | commands.rs:21118–21169, Call Z. 21223 | convenience/frische/bio → Engine-Flags (§4.4) |
| **Aufrufer 2:** `match_single_ingredient` (Inline-Edit) | commands.rs:6578–6625 | 6-Arg-API bio-neutral; `prefer_sub_recipe`-Flag → mode + prefer_raw (§4.4) |
| `strip_klammer` (Vor-Behandlung Match-Input) | commands.rs:20150–20167 | Klammer-Zusätze strippen |
| `build_inventory_bausteine` (V-04-Ist) | commands.rs:20507 ff. | RAG-Reuse-Inventur (§6.1) |
| Persistenz `match_method` | commands.rs:22628–22634 (Engine-Pfad), 8271–8278 (`match_method_for`, Override-Pfad) | Engine-Ergebnis → DB (§2.3) |

### 1.3 Abweichungen Code ↔ Doku/Regelwerk (EXPLIZIT — Entscheid pro Fall)

| # | Punkt | Doku/Regelwerk sagt | Rust tut | Entscheid für PHP-Port |
|---|---|---|---|---|
| A-1 | Pool-Modus-Auslöser | Matching_Logik §3: `SubRecipeFirst` nur bei `convenience_level=from_scratch` | commands.rs:21118: `from_scratch` **UND** `teil_convenience` → `SubRecipeFirst` | **Rust ist neuer** (User-Entscheidung nach dem Einfrieren: Teil-Convenience = eigene Fonds/Saucen bevorzugen, nur Einzelnes zukaufen). PHP = Rust; Matching_Logik.md beim nächsten Einfrieren aktualisieren. |
| A-2 | Halbfabrikat-Marker | Matching_Logik §3 nennt: fond, brühe, reduktion, demi, coulis, pueree, glace, fumet, veloute, espuma | Rust ergänzt: fumét + Muttersaucen bechamel, béchamel, mornay, hollandaise, bearnaise, béarnaise | **Rust-Erweiterung übernehmen** (eindeutige Basisrezept-Komponenten, kein GP-Zukauf). |
| A-3 | §5-GP-Namen | §5-Tabelle (alt): „Olivenoel: konserviert, nativ extra", „Zucker: trocken, Kristall, weiss", „Gelatine: trocken, Blatt", „Sojasauce: konserviert, klassisch dunkel", „Honig: konserviert, neutral/Blütenhonig" | `default_gp_alias` nutzt die **exakten DB-Namen**: „Olivenoel: trocken, hochwertig", „Zucker Raffinade: trocken, weiss", „Gelatine: trocken, kaltloeslich", „Sojasauce: konserviert, glutenfrei", „Honig: konserviert, Imker" | **Kein echter Konflikt:** das §5-Addendum „Matcher-Umsetzung 2026-06-01 (4.4s)" kodifiziert die DB-exakten Namen und ist normativ. PHP = Rust-Namen. |
| A-4 | `geschlagene sahne` | §5-Tabelle listet es als Alias-Trigger | Rust-Guard „generisch = genau 1 Token" schließt das Zwei-Token-Synonym aus | Abweichung **bewusst akzeptiert** (1-Token-Guard schützt vor Kapern spezifischer Zutaten). PHP = Rust. |
| A-5 | `crème fraîche` | §5 mandatiert Default-GP „Crème_fraiche: konserviert, 30%" | **Nicht implementiert** — frz. Akzente (è/î) brechen den Tokenizer (kein Akzent-Folding) | **Regelwerk gewinnt → PHP-Soll:** Akzent-Folding (é/è/ê→e, î→i, à/â→a, ô→o, û→u, ç→c) + crème-fraîche-Alias. ABER erst NACH bestandener Paritäts-Suite als eigener Feature-Schritt (§6.3 W-1), da es Tokenizing-Golden-Tests verändert. |
| A-6 | Petersilie-Alias | nicht in §5-Tabelle | Rust: `petersilie` (1 Token) → „Petersilie glatt: frisch, gehackt" | **Rust-Erweiterung übernehmen** (verhindert, dass die getrocknete Variante als einzige mit bloßem Basisnamen den Exact-Match gewinnt). Ins Regelwerk nachtragen. |
| A-7 | §2-Schnittformen-Liste | §2 nennt zusätzlich: geröstet, blanchiert, gemahlen, püriert | `CUT_FORM_MARKERS` enthält diese bewusst NICHT (geröstet/blanchiert = Garschritte → `ZUBEREITUNG_MARKER`; gemahlen = legitim bei Gewürzen, ist §5-Default; püriert → Sub-Typ-Hint `pueree`); ergänzt dafür: julienne, stifte | **Rust-Aufteilung übernehmen** — präzisere Operationalisierung von §2 (Penalty nur für Messerarbeit, die die Küche selbst leistet). |
| A-8 | Stemmer-Eintrag `soeße→soße` | — | Eintrag in `UMLAUT_PLURAL_SUFFIX` (stemming.rs:34) ist **unerreichbar** (Input ist immer ß→ss-gemappt) | **Nicht portieren** (toter Code). |
| A-9 | Modul-Docstring Score-Modell | Docstring Z. 7–12 beschreibt: `Slug-Prefix-Boost 0.7`, `final = min(1.0, max(containment, jaccard) + slug_boost)` | Implementiert ist: **F1** (harmonisches Mittel aus beidseitigem Containment) + Slug-Prefix-Bonus **+0.15** + Penalty + Floor | **Docstring ist STALE** (Stand vor 4.4-Refactors). Die Schwellen 0.85/0.70/0.50 im Docstring sind korrekt (Code Z. 63–73). PHP dokumentiert das Ist-Modell aus §3.2 — NICHT den Docstring. |
| A-10 | `match_method`-Schreibwert | DB-CHECK: `override_subrecipe` | commands.rs:6226 (Vorschlags-Bindung) schreibt `'override_sub'` → **verletzt den CHECK** (latenter Laufzeit-Bug; der korrekte Writer `match_method_for` Z. 8271–8278 schreibt `'override_subrecipe'`) | **Bug nicht portieren.** PHP schreibt ausschließlich CHECK-konforme Werte (§2.3); Enum-Cast macht Tippfehler unmöglich. |

---

## 2. Eingaben / Ausgaben / Invarianten

### 2.1 Eingaben

| Parameter | Typ | Quelle | Bedeutung |
|---|---|---|---|
| `ingredient_name` | string | KI-Vorschlag / Import / UI. Vor dem Match: Klammer-Zusätze strippen (`strip_klammer`, commands.rs:20150 — „Zucker (für den Sirup)" → „Zucker"; Anzeige-Name bleibt unangetastet) | Zutat-Freitext, z. B. „karamellisierte Walnüsse" |
| `hauptzutat_slug` | string\|null | KI-Vorschlag (kann fehlen oder over-reduziert sein) | z. B. `rotwein`; leer/whitespace → wie null |
| `mode` | enum `gp_first` \| `sub_recipe_first` | Generator-Hook `convenience_level` bzw. Inline-Edit-Flag `prefer_sub_recipe` (§4.4) | Pool-Priorität |
| `variant_pref` | enum `fresh_first` \| `frozen_first` \| `preserved_first` \| `neutral` | Hook `frische_zustand` bzw. Inline-Edit-String (`convenience_first` = Alias für `preserved_first`); Default `fresh_first` | Frische-Tiebreaker |
| `prefer_raw` | bool | Generator: `convenience_level == from_scratch`; Inline-Edit: Approximation `= prefer_sub_recipe` | §2-Roh-Form-Bevorzugung + Ei-Alias-Umschaltung |
| `bio_pref` | enum `bio` \| `conventional` \| `neutral` | Hook `bio_praeferenz`; **Generator-Default = `conventional`** (Bio leakt nie zufällig); Inline-Edit nutzt die bio-neutrale 6-Arg-API | Bio-Tiebreaker |

### 2.2 Kandidaten-Pools (Ziel-Schema → 02_DATENMODELL)

| Pool | Quelle (SQLite) | Ziel (Laravel) | Filter | Match-Felder | Größe (DB-verifiziert 2026-06-10) |
|---|---|---|---|---|---|
| GP-Pool | `wawi_gp_v2` | `foodalchemist_gps` | `status IN ('approved','tentative')` AND `COALESCE(is_platzhalter,0)=0` | `gp_name`, `hauptzutat_slug`, `hauptzutat_display`; Tiebreaker liest zusätzlich `zustand`, `bio` (feld-primär, Namens-Token nur Fallback — 4.4u) | 7.694 aktiv (von 7.774) |
| Sub-Rezept-Pool | `recipes` | `foodalchemist_recipes` | `COALESCE(ist_verkaufsrezept,0)=0` AND `status IN ('stub','draft','review','approved')` | `name`; Sub-Typ-Tag via `sub_rezept_typ_id` → `vocab_sub_rezept_typ.slug` (LEFT JOIN — viele ungetaggt, §6.3 W-5) | 1.402 |

Sub-Rezepte haben **keinen** `hauptzutat_slug` → im Sub-Pool gibt es weder Slug-Exact (1.0) noch Slug-Penalty; es zählen nur F1, Containment-Floor und Sub-Typ-Boost.

### 2.3 Ausgabe (`IngredientMatch`, recipe_matching.rs:27–35) + Persistenz

| Feld | Wertebereich | Anmerkung |
|---|---|---|
| `target` | `gp` \| `sub_recipe` \| `none` | genau eines |
| `status` | `exact` \| `fuzzy_high` \| `fuzzy_low` \| `no_match` | rein aus `score` abgeleitet (§4.1) |
| `gp_id` (`gp_v2_id`) / `gp_name` | FK `foodalchemist_gps.id` | nur bei `target=gp` |
| `recipe_id` / `recipe_name` | FK `foodalchemist_recipes.id` | nur bei `target=sub_recipe` |
| `score` | float [0.0, 1.0] | auch bei `no_match` befüllt (Diagnose) |

**Persistenz** in `foodalchemist_recipe_ingredients` (Quelle `recipe_ingredients`): `gp_v2_id` XOR `referenced_recipe_id` XOR beide NULL, `match_confidence` = score, `match_method` aus dem CHECK-Vokabular (DB-verifiziert 2026-06-10, mit Ist-Verteilung):

| `match_method` | Bedeutung | Ist-Zeilen |
|---|---|---|
| `gp_v2_fk` | bestätigter GP-Treffer (gesetztes `gp_v2_id`; commands.rs:22630) | 154 |
| `recipe_ref` | bestätigte Sub-Rezept-Referenz (`referenced_recipe_id`; commands.rs:22628) | 92 |
| `gemini_proposed` | unbestätigter KI-/Engine-Vorschlag (Review offen) | 5.410 |
| `override_gp` / `override_subrecipe` | User hat den Engine-Vorschlag manuell auf GP bzw. Sub-Rezept umgebogen (`match_method_for`, commands.rs:8271–8278) | 2.569 / 76 |
| `manual` | manuell ohne Engine-Beteiligung zugeordnet (bzw. Zeile ohne FK) | 1.289 |
| `unmatched` | bewusst ungemappt (Engine `no_match`, noch keine Entscheidung) | 0 |
| `ignored` | Zutat von Aggregationen ausgenommen (Parser-Müll; zählt nicht in n_total) | 0 |

> ⚠ **Bug A-10 nicht portieren:** ein Rust-Pfad schreibt `'override_sub'` (commands.rs:6226) — CHECK-Verletzung. PHP: Enum-Cast.
> ⚠ Nicht verwechseln: Das `match_method`-Vokabular aus **Regelwerk_Lieferantenartikel §12** (`artno+supplier`, `ean_packaging`, …) gehört zum LA→GP-Mapping (GL-05) — nicht zu GL-04.

### 2.4 Invarianten

1. **Score-Bereich:** `0.0 ≤ score ≤ 1.0` (Cap via `min(1.0, …)`).
2. **Schwellen sind hart:** `score < 0.50` ⇒ immer `target=none`, egal welcher Pool/Modus (recipe_matching.rs:1125–1127).
3. **Tiebreaker ändern nie den Score** — sie entscheiden nur die Auswahl bei Float-Gleichstand (ε = 0.001, `SCORE_EPS` Z. 677). Der angezeigte Score bleibt der berechnete.
4. **GP gewinnt Score-Gleichstand zwischen den Pools** (`gp.score >= sub.score` → GP; Z. 1103/1118).
5. **Alias-Pfade (§4/§5) sind deterministisch und umgehen das Scoring komplett**; fehlt das Alias-Ziel im Pool → sichere Degradation auf normales Matching (kein Fehler).
6. **Engine ist read-only** — sie liest die Pools, schreibt nichts. Persistenz (`match_method` etc.) ist Aufgabe des Aufrufers.
7. **Determinismus:** gleiche Eingaben + gleicher Pool-Stand ⇒ gleiches Ergebnis. Achtung beim Port: bei Rang-Gleichstand im Tiebreaker gewinnt der **zuerst iterierte** Kandidat (Rust: rowid-Reihenfolge des SQL-Scans, kein ORDER BY!) — PHP MUSS die Kandidaten in stabiler `id ASC`-Reihenfolge iterieren, sonst bricht die Golden-Parität (GT-T62, §6.3 W-4).
8. **Konstanten** (Namen für den Port beibehalten): `MIN_MATCH_SCORE=0.50`, `GP_PRIORITY_THRESHOLD=0.50`, `SUB_PRIORITY_THRESHOLD=0.50`, `SUB_EXACT_OVERRIDE=0.85`, `SUB_ALIAS_SCORE=0.95`, `DEFAULT_GP_ALIAS_SCORE=0.97`, `NAME_CONTAINMENT_FLOOR=0.90`, `SUB_TYP_HINT_BOOST=0.20`, `CUT_FORM_PENALTY=-2`, `SCORE_EPS=0.001`.

---

## 3. Algorithmus (Pseudocode — erklärend; Pipeline: tokenize → stem → score → rank → threshold)

### 3.1 Normalisierung & Tokenizing

```text
funktion tokenize(s) → SET<string>:                          # rs:181–200
    s = lowercase(s)
    pro zeichen:
        ä→"ae", ö→"oe", ü→"ue", ß→"ss"
        '-' '_' ',' '.' '(' ')' ':' ';' '/' '\' → ' '
        alphanumerisch (Unicode) → behalten                  # Akzente wie é bleiben erhalten! (→ A-5)
        sonst → ' '
    tokens = split_whitespace, nur länge ≥ 2
    return als MENGE (dedupe)

funktion normalize_slug(s) → string:                         # rs:203–217
    lowercase; ä/ö/ü/ß wie oben; '-' → '_'
    behalte nur alphanumerisch + '_'; alles andere ERSATZLOS streichen (auch Spaces)

funktion stem_german(t) → string:                            # stemming.rs:46–81; Ziel: KONVERGENZ, nicht Lemma
    wenn länge(t) ≤ 4: return t                              # Ei, oel, Reis, Salz unangetastet
    # 1. Umlaut-Plural-Lookup (ganzes Wort ODER Kompositum-Suffix):
    #    nuesse→nuss, wuerste→wurst, saefte→saft, aepfel→apfel,
    #    koepfe→kopf, boeden→boden, kloesse→kloss              ("walnuesse" → "walnuss")
    # 2. endet auf "ss" → unverändert (Nuss/Fluss sind Stamm, kein Plural-Suffix)
    # 3. ERSTES passendes Suffix strippen aus [innen, nnen, en, er, e, n, s]
    #    (Reihenfolge fix; innen MUSS vor en stehen), nur wenn Reststamm ≥ 3 Zeichen
    # 4. sonst unverändert

funktion stem_slug(slug_n) → string:                         # rs:223–229
    pro '_'-Segment stem_german, mit '_' wieder joinen        # "peanut_butter" segmentweise

funktion token_matches(a, b) → bool:                         # rs:240–257
    a == b                                                    → true
    stem_a = stem_german(a); länge(stem_a) ≥ 4 UND stem_a == stem_german(b) → true
    länge(a) ≥ 5 UND länge(b) ≥ 5 UND |länge(a)−länge(b)| ≤ 2
        UND (a prefix-von b ODER b prefix-von a)              → true   # Plural-Endungen -e/-en/-er/-n/-s
    sonst false       # Längen-Diff ist entscheidend: butter(6) ↛ butternut(9, Diff 3)
```

### 3.2 Kern-Score `match_score(Q, qs, C, cs)` (rs:307–406)

```text
# Q/C = Token-MENGEN Query/Kandidat; qs/cs = hauptzutat_slugs (optional)
1. SLUG-EXACT → 1.0:
   wenn qs und cs nicht-leer:
       qn = normalize_slug(qs); cn = normalize_slug(cs)
       slug_eq = (qn == cn) ODER (länge(stem_slug(qn)) ≥ 5 UND stem_slug(qn) == stem_slug(cn))
       wenn slug_eq UND NICHT name_outspecifies_slug(Q, qn): return 1.0
       # Spezifitäts-Guard 4.4q: ein GENERISCHER Slug-Exact ("salz") darf den
       # namensspezifischeren Query ("Meersalz") nicht mit 1.0 kapern → F1-Pfad.

2. wenn Q leer oder C leer: return 0.0

3. F1 ÜBER GESTEMMTE INTERSECTION:
   I  = anzahl q ∈ Q mit ∃ c ∈ C: token_matches(q, c)
   wenn I == 0: return 0.0
   cq = I/|Q|; cc = I/|C|; f1 = 2·cq·cc/(cq+cc)     # bestraft Kandidaten mit vielen Fremd-Tokens

4. SLUG-PREFIX-BONUS (+0.15):
   wenn qn, cn beide ≥ 4 Zeichen UND |länge-Diff| ≤ 3 UND (qn prefix-von cn ODER umgekehrt)
   score = min(1.0, f1 + 0.15)

5. SLUG-MISMATCH-PENALTY (4.4k-A) — Cap auf 0.45 → faktisch no_match:
   wenn qs und cs nicht-leer UND NICHT verwandt(qn, cn): return min(score, 0.45)
   verwandt = gemeinsamer_zeichen_prefix(qn, cn) ≥ 4 ODER qn prefix-von cn ODER cn prefix-von qn
   # rindfleisch↔rindergulasch (Stamm "rind") bleibt; rotwein↔rapshonig (nur "r") wird gekappt.
   return score
```

```text
funktion name_outspecifies_slug(Q, qn) → bool:               # rs:289–301
    wenn länge(qn) < 3: return false                          # "ei"/"oel" → kein verlässliches Signal
    superstring    = ∃ q ∈ Q: länge(q) > länge(qn) UND qn substring-von q   # "meersalz" ⊃ "salz"
    slug_ist_token = ∃ q ∈ Q: q == qn
    extra_content  = ∃ q ∈ Q: q ≠ qn UND länge(q) ≥ 4 UND NICHT is_qualifier_token(q)  # "dijon"
    return superstring ODER (slug_ist_token UND extra_content)

funktion is_qualifier_token(t) → bool:                       # rs:265–277
    t == "tk"
    ODER t beginnt-mit einem aus [frisch, roh, tiefgek, gefror, konserv, getrock, trocken,
        eingelegt, haltbar, mini, baby, gross, klein, fein, grob, ganz, bio, gemischt,
        geschael, gegart, gekocht, verzehrfertig]
    ODER t enthält einen PROCESSED_MARKER ODER einen CUT_FORM_MARKER (§3.4)
```

### 3.3 Name-Containment-Floor (4.4o, pool-agnostisch, rs:413–441)

```text
funktion head_matches_query(kandidat_name, Q) → bool:
    kopf = kandidat_name bis zum ersten ':' ',' '('           # GP-§6-Form + Sub-Klammer-Versionierung
    H = tokenize(kopf)
    return H nicht leer UND |H| == |Q| UND ∀ h ∈ H: ∃ q ∈ Q: token_matches(h, q)
    # MENGEN-GLEICHHEIT, keine Teilmenge: "Eier" hebt NICHT "Eier-Likör Sahne …"

# Anwendung in beiden Pool-Scans, NACH match_score, VOR Sub-Typ-Boost:
wenn score < 0.90 UND head_matches_query(name, Q): score = 0.90   # NAME_CONTAINMENT_FLOOR
# Begründung: Kopf == Query ⇒ Resttokens sind reine §6-Qualifier (Zustand/Form/Grammatur)
# → Treffer sicher, nur der F1 ist gedrückt. 0.90 = exact-Band, aber < SUB_ALIAS (0.95) < Slug-Exact (1.0).
```

### 3.4 Tiebreaker-Rangmodell `variant_rank_resolved` (rs:612–674)

Greift NUR bei Score-Gleichstand (|Δ| ≤ 0.001) im GP-Pool. Vier additive Komponenten:

```text
rang = zustand + form + cut + bio_adj

zustand-klasse (FELD-PRIMÄR, 4.4u): Spalte gps.zustand: "frisch"→Fresh, "TK"→Frozen,
    "trocken"|"konserviert"→Preserved; NULL/leer/unbekannt → Namens-Token-Fallback:
        token == "tk" oder enthält "tiefgek"/"gefroren"            → Frozen
        sonst token enthält "frisch" oder == "roh"                 → Fresh
        sonst token enthält konserv|getrocknet|eingelegt|haltbar
              oder einen PROCESSED_MARKER                          → Preserved
        sonst                                                      → Unknown

PROCESSED_MARKERS = [konzentrat, pulver, instant, portionsstick, fertig,
                     vorgegart, vorgekocht, granulat]              # substring; BEWUSST ohne tk/trocken
CUT_FORM_MARKERS  = [brunoise, wuerfel, gehackt, geschnitten, gerieben,
                     gestiftelt, stifte, scheiben, streifen, julienne]   # substring; §2-Messerarbeit
                                                                   # "geschaelt" bewusst NICHT (Putzen = Grenzfall)

cut    = (prefer_raw UND name enthält CUT_FORM_MARKER) ? −2 : 0    # greift AUCH bei pref=neutral
bio    = feld-primär: Spalte gps.bio "bio"→true, "konventionell"→false,
         sonst Token-Fallback (t=="bio"|"oeko" oder beginnt-mit biolog|oekolog — KEIN Substring)
bio_adj: bio_pref=bio → (+2 wenn bio); conventional → (−2 wenn bio); neutral → 0
```

Zustand/Form-Matrix → §4.3. Merksatz: Zustand-Spread ±3 > Bio ±2 = Form ±2 = Cut −2 — **Cut/Bio/Form kippen nie den Zustand** (per Konstruktion + GT-T68/GT-T72).

### 3.5 Sub-Typ-Hint (4.4b, rs:99–178) + Halbfabrikat-/Kandidaten-Heuristik

```text
VERB_TO_SUB_TYP (Reihenfolge = Priorität, erster Pattern-Treffer gewinnt):
  Verbal-Marker:   karamellisier→karamell · marinier→marinade · gebeizt→beize ·
                   reduzier→reduktion · glasier→glasur · purier→pueree · passier→coulis ·
                   kandier→karamell · eingekocht→kompott
  Substantiv:      karamell→karamell · reduktion→reduktion · marinade→marinade ·
                   pesto/tapenade/paste→paste · vinaigrette→vinaigrette · chutney→chutney ·
                   kompott→kompott · kraeuteroel/kraeuter_oel/kraeuteroil→kraeuter_oel ·
                   butter_aromat/aromabutter→butter_aromat · crumble→crumble · streusel→streusel ·
                   hollandaise/mayonnaise/aioli/emulsion→emulsion · coulis→coulis ·
                   puree/pueree→pueree · praline/praliné→praline · sirup→sirup ·
                   fond→fond · bruehe→bruehe · jus→jus

pattern_matches_token(token, pattern):                        # rs:158–164
    länge(pattern) ≤ 5 → token == pattern (EXAKT — "paste" darf nicht "pasteurisiert" treffen)
    sonst → token beginnt-mit pattern ("karamellisier" trifft "karamellisierte")

detect_sub_typ_hint(Q): erster Pattern (Listenreihenfolge!), der irgendein Token trifft → slug; sonst null.
Boost (nur Sub-Pool): Kandidat trägt sub_rezept_typ-Tag == hint → score = min(1.0, score + 0.20).

HALBFABRIKAT_MARKER (Matching-Gate, KONSERVATIV — substring ≥ 4 Zeichen, fängt Komposita):
    fond, bruehe, reduktion, demi, coulis, pueree, glace, fumet, veloute, espuma, fumét,
    bechamel, béchamel, mornay, hollandaise, bearnaise, béarnaise
    # BEWUSST NICHT: "sauce" (Sojasauce=GP), "eis" (Eisbergsalat/Eisbein)

query_is_halbfabrikat(Q) = ∃ token, das einen Marker als Substring enthält.

ZUBEREITUNG_MARKER (NUR Button-Heuristik P8, bewusst breiter — False-Positive harmlos):
    creme, crème, mousse, ganache, crumble, streusel, krokant, sorbet, parfait, sabayon,
    praline, gelee, chutney, kompott, marinade, pesto, tatar, schaum,
    sautiert, gebraten, gebacken, geschmort, gegrillt, frittiert, pochiert, glasiert,
    mariniert, paniert, gratiniert, blanchiert, gedaempft, geduenstet, geraeuchert,
    karamellisiert, flambiert, confiert            # NICHT "gekocht" (gekochter Schinken = Produkt)

is_sub_rezept_kandidat(name):                                 # rs:747–759 — steuert den Hard-Stop-Button
    lowercase(name) enthält "basisrezept"|"sub-rezept"|"sub rezept"   → true
    query_is_halbfabrikat(tokenize(name))                              → true
    ∃ token, das einen ZUBEREITUNG_MARKER als Substring enthält        → true
    sonst false
```

### 3.6 Deterministische Regelwerk-Aliasse (Stufe 1+2, VOR dem Scoring)

```text
default_sub_alias(Q) — Regelwerk_Basisrezepte §4 (rs:775–830), Reihenfolge verbindlich:
    dark    = ∃ token beginnt-mit "dunk" oder "braun"          # Adjektiv-Deklination: dunkle/brauner
    poultry = hat gefluegel|huhn|haehnchen|haehnchenbrust
    enthält "lammjus"  oder (lamm + "jus"-Substring)           → "BRAUNER LAMMFOND"
    enthält "kalbsjus"|"backenschmorjus" oder (kalb + jus)     → "BRAUNER KALBSFOND"
    enthält "gefluegeljus" oder (poultry + jus)                → "DUNKLER GEFLÜGELFOND"
    enthält rinderbrueh|fleischbrueh|rinderfond
        oder (rind + (brueh|fond))                             → dark ? "BRAUNER KALBSFOND" : "HELLER KALBSFOND"
        # §4: es gibt KEINEN Rinderfond-Kanon — Basis für rotes Fleisch ist Kalbsfond
    enthält gefluegelbrueh|huehnerbrueh oder (poultry + brueh) → dark ? "DUNKLER GEFLÜGELFOND" : "HELLER GEFLÜGELFOND"
    enthält gemuesebrueh oder (gemuese + brueh)                → "GEMÜSEBRÜHE"
    enthält brueh (generisch)                                  → dark ? "DUNKLER GEFLÜGELFOND" : "HELLER GEFLÜGELFOND"
    Q == {mayonnaise}  (genau 1 Token)                         → "STANDARD MAYONNAISE"
    enthält balsamico UND dressing                             → "VR HAUSDRESSING"
    sonst null
    → Existenz-Guard resolve_sub_by_name (Token-SET-Gleichheit, case-/umlaut-robust);
      Treffer: target=sub_recipe, score=0.95 (SUB_ALIAS_SCORE), status=exact. Fehlt das Ziel: weiter.

default_gp_alias(Q, prefer_raw) — Regelwerk_Basisrezepte §5 (rs:880–967):
    Guard: „generisch" = GENAU 1 Token (außer Pfeffer-Sonderlogik) — Meersalz/Trüffelhonig fallen durch.
    salz                                       → "Salz / Kochsalz: trocken, unjodiert, Raffinade"
    zucker|feinzucker|kristallzucker|streuzucker|raffinadezucker|haushaltszucker|weisszucker
                                               → "Zucker Raffinade: trocken, weiss"   # NICHT der Bio-`zucker`-Slug
    eigelb     → prefer_raw ? "Eier: frisch, Groesse L, Bodenhaltung" : "Eigelb: fluessig, pasteurisiert"
    eiweiss    → prefer_raw ? "Eier: frisch, Groesse L, Bodenhaltung" : "Huehnereiweiss: fluessig, pasteurisiert"
    ei|eier                                    → "Eier: frisch, Groesse L, Bodenhaltung"
    sahne|schlagsahne                          → "Sahne: konserviert, 30 % Fett"
    milch                                      → "Milch: frisch, 3,5 % Fett"
    mehl                                       → "Weizenmehl: trocken, Type 405"
    gelatine                                   → "Gelatine: trocken, kaltloeslich"
    weisswein                                  → "Weisswein: konserviert, zum Kochen"
    olivenoel                                  → "Olivenoel: trocken, hochwertig"
    honig                                      → "Honig: konserviert, Imker"
    sojasauce                                  → "Sojasauce: konserviert, glutenfrei"
    petersilie                                 → "Petersilie glatt: frisch, gehackt"  # frische Standard-Variante (A-6)
    pfeffer-SONDERLOGIK (Mehr-Token erlaubt):
        hat "pfeffer" UND ∃ token beginnt-mit "weiss"          → "Pfeffer weiss: trocken, gemahlen"
        hat "pfeffer" UND ALLE tokens ∈ {pfeffer, schwarz, schwarzer, ganz, gemahlen}
                                                               → "Pfeffer schwarz: trocken, gemahlen"
    sonst null
    → Existenz-Guard resolve_gp_by_name; Treffer: target=gp, score=0.97 (DEFAULT_GP_ALIAS_SCORE), status=exact.
```

### 3.7 Pool-Scans

```text
best_gp_match(Q, qs, pref, prefer_raw, bio):                  # rs:1179–1279
    SQL-Vorfilter: status/platzhalter-Filter (§2.2) + LIKE-OR-Kette
        über LOWER(gp_name|hauptzutat_slug|hauptzutat_display) mit %token%
        (Tokens ≥ 3 Zeichen; sonst Fallback alle Tokens; plus normalisierter Slug), LIMIT 300
        # bekannte Recall-Grenze: LIKE ohne Stemming/Umlaut-Folding auf DB-Seite → §6.3 W-2
    pro Kandidat:
        C = tokenize(gp_name + " " + hauptzutat_display)
        score = match_score(Q, qs, C, cand_slug)
        Containment-Floor (§3.3) auf gp_name
        übernehmen wenn score > best + 0.001
        ODER (|score − best| ≤ 0.001 UND variant_rank_resolved(neu) > variant_rank_resolved(best))

best_subrecipe_match(Q, qs):                                  # rs:1281–1353
    SQL-Vorfilter auf r.name (Filter §2.2), LIMIT 200; LEFT JOIN vocab_sub_rezept_typ
    pro Kandidat:
        score = match_score(Q, qs, tokenize(name), cand_slug = NULL)
        Containment-Floor (§3.3)
        Sub-Typ-Boost (§3.5): hint == tag → +0.20, Cap 1.0
        übernehmen NUR wenn strikt größer (erster Max gewinnt — kein Varianten-Tiebreaker)
```

### 3.8 Hauptpipeline `match_ingredient_pref_bio` (rs:1027–1129)

```text
funktion match(name, slug, mode, pref, prefer_raw, bio) → IngredientMatch:
    Q = tokenize(name); S = slug wenn nicht leer
    wenn Q leer UND S null: return no_match(0.0)

    # STUFE 1 — §4-Default-Sub-Alias (VORRANG vor allem; verhindert Token-Roulette
    #           "Rinderbrühe (hell)" → fälschlich "HELLER KRUSTENTIERFOND" übers Token "hell")
    wenn alias = default_sub_alias(Q) UND ziel = resolve_sub_by_name(alias):
        return SubRecipe(ziel, score = 0.95, status = exact)

    # STUFE 2 — §5-Default-GP-Alias ("Salz" → unjodiertes Kochsalz, NICHT jodierter salz-Slug-GP)
    wenn alias = default_gp_alias(Q, prefer_raw) UND ziel = resolve_gp_by_name(alias):
        return Gp(ziel, score = 0.97, status = exact)

    # STUFE 3 — Pool-Auswahl
    wenn query_is_halbfabrikat(Q):
        sub = best_subrecipe_match(Q, S);  gp = best_gp_match(Q, S, pref, prefer_raw, bio)
        prefer_sub = (mode == sub_recipe_first UND sub.score ≥ 0.50)            # SUB_PRIORITY_THRESHOLD
                  ODER (mode == gp_first       UND sub.score ≥ 0.85)            # SUB_EXACT_OVERRIDE (4.4l):
                  # exakt benanntes Basisrezept schlägt Convenience-GP IN JEDEM Modus
        final = prefer_sub ? (sub ?? gp) : max_score(gp, sub)   # GP gewinnt Gleichstand
    sonst:  # Grund-Zutat (Rotwein, Knoblauch, …) → GP-First, auch bei sub_recipe_first!
        gp  = best_gp_match(Q, S, pref, prefer_raw, bio)
        sub = (gp.score < 0.50) ? best_subrecipe_match(Q, S) : null             # GP_PRIORITY_THRESHOLD
        final = max_score(gp, sub)                              # GP gewinnt Gleichstand

    wenn final.score < 0.50: return no_match(final.score)       # MIN_MATCH_SCORE — harter Threshold-Entscheid
    return final
```

### 3.9 Shortlist `candidates_for` (4.4p — Grundlage des LLM-Disambiguierungs-Passes, rs:1355–1489)

Bei schwachen Treffern (`no_match`/`fuzzy_low`) liefert die Engine eine Top-K-Shortlist aus BEIDEN Pools; ein LLM wählt NUR daraus (validierbare Referenz-Token `gp:<id>` / `sub:<id>` — kein Erfinden möglich, → GL-06):

```text
pro Kandidat (beide Pools, gleiche SQL-Vorfilter):
    strict = match_score + Containment-Floor (wie normal, OHNE Sub-Typ-Boost/Tiebreaker)
    substring_overlap = anteil der Query-Tokens (≥ 3 Zeichen), die als SUBSTRING im
                        normalisierten Kandidaten-Namen vorkommen     # Recall für Komposita:
                        # "hackfleisch" ⊂ "rinderhackfleisch" — der strikte Matcher verfehlt das
    shortlist_score = max(strict, substring_overlap)
nur score > 0 behalten, absteigend sortieren, auf k kürzen.
```

### 3.10 Nachgelagerte Generator-Gates (NICHT Teil von GL-04 — Abgrenzung)

Der Rezept-Generator legt um die Engine drei Gates, die in der Domänen-Spec D-5 spezifiziert werden (hier nur zur Abgrenzung): **SQL-Exakt-Name-Override** (Komponente heißt EXAKT wie ein Pool-Eintrag → 1.0/exact, commands.rs:21263–21282), **P4 Hauptzutat-Konsistenz-Gate** (exact/fuzzy_high ohne geteilte Hauptzutat → Downgrade auf fuzzy_low + Score-Cap 0.69, commands.rs:21233–21255), **P9 Anti-Collapse-Gate** (Zubereitungs-Name darf nicht still auf Rohstoff-GP kollabieren → gleicher Downgrade, commands.rs:21284 ff.). Der PHP-Port von GL-04 endet an der `IngredientMatch`-Rückgabe.

---

## 4. Entscheidungstabellen (normativ)

### 4.1 Schwellen-Bänder (`MatchStatus::from_score`, rs:63–73 — Werte aus Code verifiziert)

| Score | Status | UI | Folge |
|---|---|---|---|
| ≥ 0.85 | `exact` | ✅ grün | Auto-Übernahme als Vorschlag |
| ≥ 0.70 und < 0.85 | `fuzzy_high` | 🟢 | Vorschlag, sichtbar markiert |
| ≥ 0.50 und < 0.70 | `fuzzy_low` | 🟡 | Review nötig; Kandidat für LLM-Disambiguierung (§3.9) |
| < 0.50 | `no_match` | 🔴 | `target=none`; Hard-Stop-Button: `is_sub_rezept_kandidat` ⇒ „Basisrezept anlegen", sonst „GP anlegen" |

### 4.2 Pool-Priorität (Halbfabrikat-Gate × Modus)

| Query ist Halbfabrikat? | Modus | Regel |
|---|---|---|
| nein | egal (auch `sub_recipe_first`!) | GP-First. Sub-Pool wird NUR gescannt, wenn `gp.score < 0.50`; dann gewinnt der höhere Score (GP bei Gleichstand). |
| ja | `sub_recipe_first` | Sub gewinnt, wenn `sub.score ≥ 0.50`; sonst höherer Score (GP bei Gleichstand). |
| ja | `gp_first` | Sub gewinnt NUR bei `sub.score ≥ 0.85` (Exact-Sub-Override 4.4l — User-Entscheidung); sonst höherer Score (GP bei Gleichstand). |

### 4.3 Varianten-Tiebreaker: Zustand × Präferenz (nur bei Score-Gleichstand |Δ| ≤ 0.001, nur GP-Pool)

| `variant_pref` ↓ \ Zustand → | Fresh | Frozen | Preserved | Unknown |
|---|---|---|---|---|
| `fresh_first` | **+3** | −1 | −2 | 0 |
| `frozen_first` | +1 (Roh-Fallback) | **+3** | −2 | 0 |
| `preserved_first` | −2 | +1 (Convenience-Fallback) | **+3** | 0 |
| `neutral` | 0 | 0 | 0 | 0 (Legacy: erster Max gewinnt) |

Additiv dazu:

| Komponente | Bedingung | Wert |
|---|---|---|
| Form (Service-/Verarbeitungs-Form) | Name enthält PROCESSED_MARKER; pref = fresh/frozen_first | −2 |
| Form | dito; pref = preserved_first | +1 |
| Form | pref = neutral | 0 |
| Cut (§2 Roh-Form) | `prefer_raw` UND Name enthält CUT_FORM_MARKER (greift auch bei pref=neutral) | −2 |
| Bio | `bio_pref=bio` und Variante ist bio | +2 |
| Bio | `bio_pref=conventional` (Generator-DEFAULT) und Variante ist bio | −2 |
| Bio | `bio_pref=neutral` | 0 |

Invariante: ±2-Komponenten kippen nie den ±3-Zustand-Spread (GT-T68, GT-T72). Zustand/Bio werden **feld-primär** gelesen (`gps.zustand`/`gps.bio`), Namens-Token nur als Fallback (4.4u, GT-T74–T76).

### 4.4 Aufrufer→Engine-Parameter (Produktiv-Mapping)

**Aufrufer 1 — Rezept-Generator** (commands.rs:21118–21169):

| Kontext-Hook | Wert | Engine-Parameter |
|---|---|---|
| `convenience_level` | `from_scratch`, `teil_convenience` | `mode = sub_recipe_first` (⚠ Abweichung A-1) |
| `convenience_level` | `voll_convenience`, unset, sonstiges | `mode = gp_first` |
| `convenience_level` | `from_scratch` | zusätzlich `prefer_raw = true` (NUR from_scratch — teil_convenience erlaubt vorbereitete Stufen) |
| `frische_zustand` | `frisch` / `tk` / `konserve` | `variant_pref = fresh_first / frozen_first / preserved_first` |
| `frische_zustand` | unset | Fallback: `voll_convenience` → `preserved_first`, sonst `fresh_first` (Caterer-Default) |
| `bio_praeferenz` | `bio` / `egal` / unset+sonstiges | `bio_pref = bio / neutral / conventional` (**Default conventional!**) |

**Aufrufer 2 — `match_single_ingredient` (Inline-Edit, commands.rs:6578–6625):** `prefer_sub_recipe=true` → `mode=sub_recipe_first` UND `prefer_raw=true` (Approximation — kein voller convenience_level verfügbar); `variant_pref`-String `fresh_first`/`frozen_first`/`preserved_first`/`neutral`, wobei **`convenience_first` als Abwärtskompatibilitäts-Alias für `preserved_first`** akzeptiert wird; Default `fresh_first`; läuft auf der bio-neutralen 6-Arg-API. Liefert zusätzlich `ist_sub_rezept = is_sub_rezept_kandidat(name)` ans Frontend.

### 4.5 Score-Quellen-Rangfolge (welcher Mechanismus liefert welchen Score)

| Rang | Mechanismus | Score | Begründung |
|---|---|---|---|
| 1 | Slug-Exact (inkl. Slug-Stem-Konvergenz ≥ 5) ohne Spezifitäts-Guard-Treffer | 1.00 | stärkstes Signal |
| 2 | §5-Default-GP-Alias | 0.97 | deterministischer Regelwerk-Default, < 1.0 damit Exakt-Treffer theoretisch gewinnen |
| 3 | §4-Default-Sub-Alias | 0.95 | dito (Sub-Seite) |
| 4 | Name-Containment-Floor (Kopf == Query) | 0.90 | sicher, aber kein expliziter Slug-/Alias-Treffer |
| 5 | F1 + Slug-Prefix-Bonus (+0.15) + ggf. Sub-Typ-Boost (+0.20) | variabel, Cap 1.0 | mechanischer Pfad |
| 6 | Slug-Mismatch-Penalty | Cap 0.45 | erzwungenes no_match bei fachfremden Slugs |

---

## 5. Golden-Testfälle (verbindliche Wahrheit — 1:1 in PHPUnit-Datasets)

Extrahiert aus den **84 `#[test]`-Funktionen in recipe_matching.rs:1491–2659 + 7 in stemming.rs:83–151**. Notation: `ts(x)` = `tokenize(x)`; Slugs in Klammern; „→ GP/Sub »Name«" = Integrationsfall gegen den im Quelltest definierten Mini-Pool (Fixture 1:1 nach PHPUnit portieren). **96 Golden-Cases (GT-T01…GT-T94c) — dies ist das Abnahme-Kriterium für den PHP-Port.**

### 5.1 Tokenizing / Stemming / Plural

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T01 | `tokenize("Holländer-Käse, mittelalt")` | enthält `hollaender`, `kaese`, `mittelalt` | tokenize_normalizes_umlauts_and_separators |
| GT-T02 | `token_matches("butter","butternut")` | false (Längen-Diff 3) | stemming_does_not_match_unrelated_prefixes |
| GT-T03 | `token_matches("wasser","wasserlos")` | false | stemming_does_not_match_unrelated_prefixes |
| GT-T04 | `token_matches("gewuerzgurke","gewuerzgurken")` | true (Diff 1) | stemming_does_not_match_unrelated_prefixes |
| GT-T05 | `token_matches("scharf","scharfer")` | true (Diff 2) | stemming_does_not_match_unrelated_prefixes |
| GT-T06 | stem: `tomate`↔`tomaten`, `bohne`↔`bohnen`, `kartoffel`↔`kartoffeln`, `zwiebel`↔`zwiebeln`, `karotte`↔`karotten` | jeweils gleicher Stem | stemming.rs::regular_plural_converges |
| GT-T07 | stem: `amerikanisch` / `amerikanische` / `amerikanischen` | alle gleicher Stem | stemming.rs::adjective_flexion_converges |
| GT-T08 | stem: `nuss`↔`nuesse`, `walnuss`↔`walnuesse`, `apfel`↔`aepfel`, `wurst`↔`wuerste`, `saft`↔`saefte` | jeweils gleicher Stem (Umlaut-Lookup) | stemming.rs::umlaut_plural_lookup_converges |
| GT-T09 | stem: `kartoffel` vs `karotte`; `rotwein` vs `rapshonig`; `tomate` vs `kartoffel`; `zwiebel` vs `knoblauch` | jeweils UNGLEICHE Stems | stemming.rs::no_false_collision |
| GT-T10 | `stem_german` von `ei`, `oel`, `reis`, `salz` | unverändert (≤ 4 Zeichen) | stemming.rs::short_tokens_untouched |
| GT-T11 | `stem_german("ente")` / `stem_german("enten")` | `ente` / `ent` (dokumentierte 4-Zeichen-Grenz-Divergenz) | stemming.rs::min_stem_len_respected |
| GT-T12 | `stem_german(stem_german("tomaten"))` | == `stem_german("tomaten")` (idempotent) | stemming.rs::idempotent_on_singular_stems |

### 5.2 Slug-Matching / Score-Modell

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T13 | `match_score(ts("Eigelb"), "eigelb", ts("Eigelb fluessig pasteurisiert"), "eigelb")` | ≥ 0.99 (Slug-Exact) | slug_exact_match_wins |
| GT-T14 | `match_score(ts("Eigelb"), ∅, ts("Eigelb fluessig pasteurisiert"), ∅)` | ≥ 0.5 | containment_handles_query_subset |
| GT-T15 | `match_score(ts("Gewürzgurke"), ∅, ts("Gewuerzgurken Cornichons konserviert"), ∅)` | ≥ 0.3 | stemming_handles_plural_singular |
| GT-T16 | `match_score(ts("Roggenbrötchen"), "roggenbroetchen", ts("Roggenbroetchen TK 80 g"), "roggenbroetchen")` | ≥ 0.99 | slug_prefix_boost |
| GT-T17 | `match_score(ts("Butter"), "butter", ts("Eiscreme Peanut Butter TK Ben Jerrys"), "peanut_butter")` | < 0.5 | f1_rejects_single_token_in_long_candidate |
| GT-T18 | `match_score(ts("Honig"), "honig", ts("Lammspiesse frisch mariniert Honig Thymian Portion 35 g"), "lammspiesse")` | < 0.5 | f1_rejects_single_token_in_long_candidate |
| GT-T19 | `match_score(ts("Butter"), "butter", ts("Butter frisch 250 g"), "butter")` | ≥ 0.99 | f1_keeps_clean_match |
| GT-T20 | `match_score(ts("Vanilleeis"), ∅, ts("Salat Romana frisch"), ∅)` | < 0.3 | no_match_low_overlap |
| GT-T21 | `match_score(ts("Rotwein trocken"), "rotwein", ts("Rapshonig trocken"), "rapshonig")` | < 0.5 (Mismatch-Penalty) | slug_mismatch_penalty_rotwein_rapshonig |
| GT-T22 | `match_score(ts("Rindfleisch aus der Keule"), "rindfleisch", ts("Rindergulasch frisch aus der Keule"), "rindergulasch")` | ≥ 0.5 (Stamm „rind" → kein Penalty) | slug_mismatch_penalty_keeps_related_stem |
| GT-T23 | `match_score(ts("Butter"), ∅, ts("Butter frisch 250 g"), ∅)` | ≥ 0.4 (kein Penalty ohne Slugs) | slug_mismatch_no_penalty_without_slugs |
| GT-T24 | `common_prefix_len`: (rind, rindergulasch)=4 · (rotwein, rapshonig)=1 · (tomate, tomatenmark)=6 · (kartoffel, karotte)=3 | exakte Werte | common_prefix_len_basics |
| GT-T25 | `match_score(ts("Rinderbrühe"), "rinderbruehe", ts("Rinderbruehe klar gekocht"), "rinderbruehen")` | ≥ 0.99 (Slug-Stem-Konvergenz) | slug_stem_singular_plural_is_exact |
| GT-T26 | `match_score(ts("Tomate"), "tomate", ts("Tomatenmark dreifach konzentriert"), "tomatenmark")` | < 0.99 (KEIN Slug-Exact) | slug_stem_does_not_collide_unrelated |
| GT-T27 | `name_outspecifies_slug`: (ts("Meersalz"), "salz") · (ts("Glattpetersilie"), "petersilie") · (ts("Dijon-Senf"), "senf") | jeweils true | name_outspecifies_slug_signals |
| GT-T28 | `name_outspecifies_slug`: (ts("Salz"), "salz") · (ts("Rinderhackfleisch"), "rinderhackfleisch") · (ts("Tomaten frisch"), "tomaten") | jeweils false (identisch / Extra = Qualifier) | name_outspecifies_slug_signals |
| GT-T29 | `match_score(ts("Meersalz"), "salz", ts("Salz trocken raffiniert jodiert"), "salz")` | < 0.85 (Guard verhindert 1.0) | specificity_guard_demotes_generic_slug_compound |
| GT-T30 | `match_score(ts("Dijon-Senf"), "senf", ts("Senf trocken extra scharf"), "senf")` | < 0.85 | specificity_guard_demotes_generic_slug_multiword |
| GT-T31 | `match_score(ts("Salz"), "salz", ts("Speisesalz jodiert"), "salz")` | ≥ 0.99 (benigner Fall: Kandidat spezifischer) | specificity_guard_keeps_benign_exact |
| GT-T32 | Integration: Query „Meersalz" (slug `salz`) gegen Pool [„Salz: trocken, raffiniert, jodiert" (salz), „Meersalz: trocken, grob" (meersalz)] | → GP »Meersalz: trocken, grob« | specificity_guard_routes_to_specific_gp |

### 5.3 Schwellen / Status / Containment-Floor

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T33 | `from_score(0.95 / 0.80 / 0.55 / 0.30)` | exact / fuzzy_high / fuzzy_low / no_match | status_thresholds |
| GT-T34 | `head_matches_query`: („Rinderhackfleisch: frisch", ts("Rinderhackfleisch")) · („Karotten: frisch, mini, gemischt", ts("Karotten")) · („Tomatensugo (klassisch)", ts("Tomatensugo")) · („Rote Zwiebel: frisch", ts("Rote Zwiebel")) | jeweils true | head_matches_query_positive_and_negative |
| GT-T35 | `head_matches_query`: („Eier-Likör Sahne Whisky Dessert", ts("Eier")) · („Karotten: frisch", ts("Karotten gewuerfelt")) · („Rapshonig trocken", ts("Rotwein trocken")) | jeweils false | head_matches_query_positive_and_negative |
| GT-T36 | Integration: „Rinderhackfleisch" OHNE Slug gegen Pool [„Rinderhackfleisch: frisch"] | → GP, score ≥ 0.85, status exact (Floor 0.90) | containment_floor_greens_gp_without_slug |
| GT-T37 | Integration Sub-Pool: ts("Tomatensugo") gegen [„Tomatensugo (klassisch)"] | → Sub »Tomatensugo (klassisch)«, score ≥ 0.85 | containment_floor_greens_subrecipe_without_slug |
| GT-T38 | Integration: „Eier" OHNE Slug gegen Pool [„Eierlikör: Sahne, Whisky, Dessert"] | status ≠ exact (Teil-Kompositum wird nicht grün) | containment_floor_does_not_green_partial_compound |

### 5.4 Sub-Typ-Hint / Boost

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T39 | `detect_sub_typ_hint(ts("karamellisierte Walnüsse"))` | `karamell` | sub_typ_hint_detects_karamellisiert |
| GT-T40 | `detect_sub_typ_hint(ts("marinierte Tomaten"))` | `marinade` | sub_typ_hint_detects_mariniert |
| GT-T41 | `detect_sub_typ_hint(ts("Basilikum Pesto Genovese"))` | `paste` | sub_typ_hint_detects_pesto_substantiv |
| GT-T42 | `detect_sub_typ_hint(ts("Limonen-Vinaigrette mit Senf"))` | `vinaigrette` | sub_typ_hint_detects_vinaigrette |
| GT-T43 | `detect_sub_typ_hint(ts("Sauce Hollandaise"))` | `emulsion` | sub_typ_hint_detects_hollandaise_as_emulsion |
| GT-T44 | `detect_sub_typ_hint(ts("Karotten"))` | null | sub_typ_hint_none_for_plain_zutat |
| GT-T45 | `detect_sub_typ_hint(ts("Vollmilch frisch pasteurisiert"))` | null („pasteurisier" kein Marker; „paste" ≤ 5 → nur exakt) | sub_typ_hint_none_for_gp_naming |
| GT-T46 | Sub-Pool [„Walnuss-Karamell" (Tag karamell), „Walnuss Öl" (ohne Tag)], Query ts("karamellisierte Walnuss") | → Sub »Walnuss-Karamell«, score ≥ 0.5 (Boost) | subrecipe_boost_karamell_outranks_untagged |
| GT-T47 | Sub-Pool [„Walnusskaramell" (karamell), „Walnusspaste" (paste)], Query ts("Walnuss") (kein Hint) | irgendein Treffer, score < 0.95 (kein Boost) | subrecipe_no_boost_without_hint |
| GT-T48 | Sub-Pool [„Basilikum Pesto Genovese" (paste), „Basilikum Vinaigrette" (ohne)], Query ts("Basilikum Pesto") | → Sub »Basilikum Pesto Genovese« | subrecipe_boost_pesto_finds_paste_tagged |

### 5.5 Pool-Priorität / Halbfabrikat-Gate / Kandidaten-Heuristik

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T49 | `query_is_halbfabrikat`: „Braune Kalbsbrühe", „Kalbsfond", „Rotweinreduktion", „Himbeercoulis" | jeweils true (Substring in Komposita) | halbfabrikat_gate_detects_komposita |
| GT-T50 | `query_is_halbfabrikat`: „Rotwein trocken", „Knoblauch", „Zwiebeln", „Sojasauce" | jeweils false (kein bare-„sauce"-Marker!) | halbfabrikat_gate_rejects_grundzutaten |
| GT-T51 | Kalbsfond-Fixture (GP „Kalbsfond: konserviert, Konzentrat" + Sub „Heller Kalbsfond"), Query „Kalbsfond" (kalbsfond), `gp_first` | → GP »Kalbsfond: konserviert, Konzentrat« | gp_first_prefers_konzentrat_gp |
| GT-T52 | dito, `sub_recipe_first` | → Sub »Heller Kalbsfond« | subrecipe_first_prefers_basisrezept |
| GT-T53 | GP „Kalbsfond: konserviert, Konzentrat" + Sub EXAKT „Kalbsfond", Query „Kalbsfond", `gp_first` | → Sub »Kalbsfond« (Exact-Sub-Override ≥ 0.85) | gpfirst_exact_sub_overrides_convenience_gp |
| GT-T54 | Kalbsfond-Fixture (Sub nur „Heller Kalbsfond" ≈ 0.67), `gp_first` | → GP (schwacher Sub überschreibt NICHT) | gpfirst_weak_sub_does_not_override_gp |
| GT-T55 | GP „Rotwein: trocken, Spätburgunder" + Sub „Rotwein Vinaigrette", Query „Rotwein" (rotwein), `sub_recipe_first` | → GP (Grund-Zutat bleibt GP-First trotz from_scratch) | subrecipe_first_grundzutat_stays_gp |
| GT-T56 | Nur GP „Zwiebeln: frisch, geschaelt", Query „Zwiebeln" (zwiebeln), `sub_recipe_first` | → GP (Fallback ohne Sub-Rezept) | subrecipe_first_falls_back_to_gp_when_no_subrecipe |
| GT-T57 | `is_sub_rezept_kandidat`: „Schokoladencreme", „Baileysmousse", „Schoko-Ganache", „Kakao-Crumble", „Crème brûlée", „Sautierter gruener Spargel", „Rosa gebratenes Rinderfilet-Medaillon", „Geschmorte Ochsenbacke", „Gegrillte Zucchini" | jeweils true | sub_kandidat_erkennt_zubereitungen |
| GT-T58 | `is_sub_rezept_kandidat`: „Ziegenfrischkäse", „Rote Bete", „Sojasauce", „Eisbergsalat" | jeweils false („eis"-Substring darf nicht triggern) | sub_kandidat_lehnt_rohprodukte_ab |

### 5.6 Zustand-/Bio-/Form-Tiebreaker

Fixture „Varianten-DB": GPs [„Karotten: TK, Baby" (karotten, niedrigste id), „Karotten: frisch, geschaelt" (karotten), „Speisesalz: Portionssticks" (salz), „Speisesalz: jodiert" (salz)]. Fixture „Zustand-DB": GPs [„Tomaten: konserviert, geschaelt", „Tomaten: TK", „Tomaten: frisch"] (alle slug tomaten, Einfüge-Reihenfolge = id-Reihenfolge).

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T59 | `variant_rank`-Signale: („Karotten: frisch, geschaelt", fresh_first) > 0 · („Karotten: TK, Baby", fresh_first) < 0 · („Karotten: TK, Baby", frozen_first) > 0 · („Tomaten: konserviert", frozen_first) < 0 · („Tomaten: konserviert", preserved_first) > 0 · („Karotten: frisch, geschaelt", preserved_first) < 0 · („Karotten: TK, Baby", preserved_first) > 0 · (neutral, prefer_raw=false) immer 0 · („Speisesalz: jodiert", fresh_first) == 0 (trocken/jodiert ≠ Verarbeitungs-Marker) | siehe links | variant_rank_signals |
| GT-T60 | Varianten-DB, „Karotten" (karotten), `fresh_first` | → GP »Karotten: frisch, geschaelt« | tiebreaker_freshfirst_picks_frisch_over_tk |
| GT-T61 | Varianten-DB, „Karotten", `preserved_first` (keine Konserve da) | → GP »Karotten: TK, Baby« (TK = Convenience-Fallback) | tiebreaker_preservedfirst_picks_tk_over_frisch |
| GT-T62 | Varianten-DB, „Karotten", `neutral` (4-Arg-Legacy) | → GP »Karotten: TK, Baby« (erster Max = niedrigste id; → Invariante 7 / W-4) | tiebreaker_neutral_keeps_legacy_first |
| GT-T63 | Varianten-DB, „Karotten", `frozen_first` | → GP »Karotten: TK, Baby« | tiebreaker_frozenfirst_picks_tk_over_frisch |
| GT-T64 | Zustand-DB, „Tomaten": `fresh_first` / `frozen_first` / `preserved_first` | → »Tomaten: frisch« / »Tomaten: TK« / »Tomaten: konserviert, geschaelt« | tiebreaker_freshfirst_over_tk_and_konserve (+2 Folgetests) |
| GT-T65 | Varianten-DB, „Salz" (salz), `fresh_first` | → GP »Speisesalz: jodiert« (Portionssticks-Service-Form abgewertet) | tiebreaker_salz_avoids_portionssticks |
| GT-T66 | `prefer_raw=true`: rank(„Karotten: frisch, ganz") > rank(„Karotten: frisch, Stifte") [fresh_first]; rank(„Sellerie: TK, ganz") > rank(„Sellerie: TK, Wuerfel") [neutral!] | Cut-Penalty wirkt, auch bei neutral | cut_form_penalty_prefers_uncut_under_prefer_raw |
| GT-T67 | `prefer_raw=false`: rank(„Karotten: frisch, ganz") == rank(„Karotten: frisch, Stifte") | kein Penalty | cut_form_penalty_off_without_prefer_raw |
| GT-T68 | `prefer_raw=true`, fresh_first: rank(„Karotten: frisch, Stifte") > rank(„Karotten: TK, ganz") | Cut-Penalty kippt NIE den Zustand | cut_form_penalty_never_flips_zustand |
| GT-T69 | `has_cut_form`: „Karotten: frisch, Brunoise" ✓ · „Zwiebeln: frisch, gewuerfelt" ✓ · „Lauch: frisch, in Streifen" ✓ · „Karotten: frisch, ganz" ✗ · „Zwiebeln: frisch, geschaelt" ✗ | siehe links | has_cut_form_detects_markers |
| GT-T70 | `is_bio_tokens`: „Karotten: frisch, Stifte, Bio" ✓ · „Eigelb: frisch, fluessig, Bio" ✓ · „Apfelsaft: biologisch" ✓ · „Karotten: frisch, mini, gemischt" ✗ · „Olivenoel: trocken" ✗ | siehe links | is_bio_tokens_signals |
| GT-T71 | bio_pref=conventional: rank(„Olivenoel: trocken") > rank(„Olivenoel: trocken, bio"); bio_pref=bio: umgekehrt; bio_pref=neutral: gleich | siehe links | variant_rank_bio_* (3 Tests) |
| GT-T72 | fresh_first + conventional: rank(„Karotten: frisch, Bio") > rank(„Karotten: TK") | Bio (±2) kippt nie Zustand (±3) | bio_does_not_flip_zustand |
| GT-T73 | Pool [„Eigelb: frisch, fluessig, Bio", „Eigelb: frisch, fluessig"], Query „Eigelb" (eigelb): conventional → non-bio; bio → Bio-Variante | siehe links | bio_conventional_picks_non_bio_gp |
| GT-T74 | `zustand_class_resolved`: („Sellerie Spezial", Spalte "TK"/"frisch"/"konserviert"/"trocken") → Frozen/Fresh/Preserved/Preserved; („Karotten: TK, Baby", NULL) → Frozen (Namens-Fallback); („Sellerie Spezial", "") → Unknown | Spalte gewinnt, leere Spalte = Fallback | zustand_class_resolved_prefers_column |
| GT-T75 | `is_bio_resolved`: (ts("Olivenoel xyz"), "bio") ✓ · (ts("Olivenoel: trocken, bio"), "konventionell") ✗ (Spalte überstimmt!) · (ts("Karotten: frisch, Bio"), NULL) ✓ · (ts("Karotten: frisch"), NULL) ✗ | siehe links | is_bio_resolved_prefers_column |
| GT-T76 | `variant_rank_resolved`(„Sellerie Spezial", frozen_first, Spalte TK) > dito(Spalte frisch); conventional: (Spalte bio) < (Spalte NULL) | Spalten steuern Tiebreak ohne Namens-Token | variant_rank_resolved_column_drives_tiebreak |

### 5.7 Default-Aliasse (§4 Sub / §5 GP)

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T77 | `default_gp_alias(ts("Salz"), false)` | »Salz / Kochsalz: trocken, unjodiert, Raffinade« | default_gp_alias_salz_only_generic |
| GT-T78 | `default_gp_alias`: ts("Meersalz") / ts("Fleur de Sel") / ts("Knoblauch") | jeweils null | default_gp_alias_salz_only_generic |
| GT-T79 | `default_gp_alias(ts("Eigelb"), false)` → »Eigelb: fluessig, pasteurisiert«; `(ts("Eiweiß"), false)` → »Huehnereiweiss: fluessig, pasteurisiert«; `(ts("Eigelb"), true)` und `(ts("Eiweiß"), true)` → »Eier: frisch, Groesse L, Bodenhaltung«; `(ts("Eier"), false)` → »Eier: frisch, Groesse L, Bodenhaltung« | prefer_raw schaltet Ei-Produkte um | default_gp_alias_full_set |
| GT-T80 | `default_gp_alias` (alle false): Sahne → »Sahne: konserviert, 30 % Fett« · Milch → »Milch: frisch, 3,5 % Fett« · Mehl → »Weizenmehl: trocken, Type 405« · Zucker → »Zucker Raffinade: trocken, weiss« · Olivenöl → »Olivenoel: trocken, hochwertig« · Petersilie → »Petersilie glatt: frisch, gehackt« | siehe links | default_gp_alias_full_set |
| GT-T81 | `default_gp_alias`: „Pfeffer" → »Pfeffer schwarz: trocken, gemahlen« · „Schwarzer Pfeffer" → dito · „Weißer Pfeffer" → »Pfeffer weiss: trocken, gemahlen« | Pfeffer-Sonderlogik (Mehr-Token) | default_gp_alias_full_set |
| GT-T82 | `default_gp_alias`: „Weizenmehl Type 550", „Trüffelhonig", „Cayennepfeffer", „Bunter Pfeffer", „Sojasauce hell", „Petersilie glatt", „Petersilienwurzel", „Brauner Zucker" | jeweils null (Guards) | default_gp_alias_generic_only_guards |
| GT-T83 | Integration: Pool [„Salz: trocken, raffiniert, jodiert" (salz), „Salz / Kochsalz: trocken, unjodiert, Raffinade" (salz_kochsalz)], Query „Salz" (salz) | → GP »Salz / Kochsalz: …« (Alias schlägt Slug-Exact!) | alias_salz_routes_to_unjodiert_kochsalz |
| GT-T84 | Integration: Pool NUR [„Salz: trocken, raffiniert, jodiert"], Query „Salz" | → GP »Salz: trocken, raffiniert, jodiert« (Existenz-Guard-Fallback) | alias_salz_falls_back_when_kochsalz_missing |
| GT-T85 | `default_sub_alias`: ts("Rinderbrühe hell") / ts("Rinderbrühe") → »HELLER KALBSFOND«; ts("Brauner Rinderfond") / ts("dunkle Fleischbrühe") → »BRAUNER KALBSFOND« | §4: kein Rinderfond-Kanon | default_sub_alias_rinderbruehe_maps_to_kalbsfond |
| GT-T86 | `default_sub_alias`: ts("Geflügelbrühe") / ts("Hühnerbrühe") → »HELLER GEFLÜGELFOND«; ts("dunkle Geflügelbrühe") → »DUNKLER GEFLÜGELFOND«; ts("Gemüsebrühe") → »GEMÜSEBRÜHE« | siehe links | default_sub_alias_gefluegel_and_gemuese |
| GT-T87 | `default_sub_alias`: ts("Lammjus") → »BRAUNER LAMMFOND«; ts("Kalbsjus") → »BRAUNER KALBSFOND«; ts("Geflügeljus") → »DUNKLER GEFLÜGELFOND« | Jus-Regeln VOR Brüh-Regeln | default_sub_alias_jus_specific_before_generic |
| GT-T88 | `default_sub_alias`: ts("Brühe") → »HELLER GEFLÜGELFOND«; ts("dunkle Brühe") → »DUNKLER GEFLÜGELFOND« | generische Brühe = Geflügel-Default | default_sub_alias_generic_bruehe_defaults_to_gefluegelfond |
| GT-T89 | `default_sub_alias`: ts("Mayonnaise") → »STANDARD MAYONNAISE«; ts("Trüffelmayonnaise mit Senf") → null; ts("Balsamico-Dressing") → »VR HAUSDRESSING« | 1-Token-Guard bei Mayo | default_sub_alias_mayonnaise_and_dressing |
| GT-T90 | `default_sub_alias`: ts("Rotwein trocken"), ts("Knoblauch"), ts("Karotten frisch") | jeweils null | default_sub_alias_none_for_plain_zutaten |
| GT-T91 | `resolve_sub_by_name` (Alias-Fixture mit »DUNKLER GEFLÜGELFOND«, »HELLER KALBSFOND«): „DUNKLER GEFLÜGELFOND" findet sich; „heller kalbsfond" (Kleinschreibung!) findet »HELLER KALBSFOND«; „PHANTOMFOND" → null | Token-Set-Gleichheit, case-/umlaut-robust | resolve_sub_by_name_finds_umlaut_robust |
| GT-T92 | End-to-end (Consommé-Bug): Pool [GP „Rinderbrühe: konserviert, Konzentrat" + Subs HELLER/BRAUNER KALBSFOND, HELLER KRUSTENTIERFOND, DUNKLER GEFLÜGELFOND], Query „Rinderbrühe" (rinderbruehe), `gp_first`, fresh_first | → Sub »HELLER KALBSFOND«, score == 0.95 (±0.001) — NICHT Krustentierfond, NICHT GP | alias_rinderbruehe_routes_to_kalbsfond_not_gp |
| GT-T93 | dito, aber Sub-Pool LEER (kein Kalbsfond-Rezept) | → GP (Alias verpufft, sichere Degradation) | alias_falls_back_when_target_missing |

### 5.8 Shortlist `candidates_for`

| ID | Input | Expected | Quelle-Testname |
|---|---|---|---|
| GT-T94a | Varianten-DB + Sub „Karotten Vinaigrette", `candidates_for("Karotten", "karotten", 8)` | ≥ 3 Kandidaten; absteigend sortiert; beide kinds (`gp`+`sub`) vertreten; Referenzen matchen `gp:<id>`/`sub:<id>` | candidates_for_returns_both_pools_ranked |
| GT-T94b | Pool [„Rinderhackfleisch: frisch"], Query „Hackfleisch vom Rind" OHNE Slug: (a) strikter Match, (b) Shortlist | (a) `target=none` (Beleg für V-05-Bedarf!); (b) Shortlist ENTHÄLT »Rinderhackfleisch: frisch« via Substring-Recall | candidates_for_recalls_compound_noun |
| GT-T94c | Varianten-DB, `candidates_for("Drachenfrucht", "drachenfrucht", 8)` | leere Liste (kein Signal → kein Erfinden) | candidates_for_empty_on_no_signal |

> **PHPUnit-Hinweis:** Pure-Function-Tests (5.1–5.4 ohne „Integration", 5.6 rank-Tests, 5.7 alias-Funktionen) als Datasets ohne DB. Integrations-Tests gegen In-Memory-Fixtures (SQLite `:memory:` oder Pest + RefreshDatabase) mit exakt den genannten Pool-Zeilen. Schwellwert-Assertions (≥/<) wörtlich übernehmen — NICHT auf Punktwerte „verschärfen": die konkreten F1-Werte sind kein API-Vertrag, nur die Bänder.

---

## 6. Offene Weichen + Verbesserungen

### 6.1 V-04 — Reuse-at-Generation / RAG (Embedding-Retrieval als ergänzender Layer)

**Register:** 10_VERBESSERUNGS_REGISTER.md V-04 (Prio hoch, D-5/D-6). **Ist-Implementierung als Vorlage:** `build_inventory_bausteine` (commands.rs:20507 ff., Aufruf mit `max=15` Z. 20708) + `semantic_candidates` (commands.rs:20206 ff.) + `load_embedding`/`dot` (commands.rs:20178–20201).

Der mechanische Matcher (oben) ist der **Nach**-Matcher. V-04 setzt davor einen Retrieval-Pass, der der KI VOR der Benennung das vorhandene Inventar zeigt („reuse-first statt blind erfinden") — alle Konstanten code-verifiziert:

1. **Phrasen-Extraktion:** Beschreibung normalisieren — Konnektoren `" mit "`, `" und "`, `" auf "`, `" an "` → Trenner `·`; splitten an `· , ; / | \n ( )`; Phrasen < 3 Zeichen verwerfen.
2. **Lexikalischer Pool:** pro Phrase `candidates_for(phrase, slug=null, k=6)` (§3.9); **Score-Floor 0.40**; Dedupe über `(kind, id)`.
3. **Semantischer Pass:** Query-Embedding der Gesamtbeschreibung (heute: Gemini, 768-dim, L2-normalisiert → Cosinus = Skalarprodukt). Top-N über ALLE gespeicherten Embeddings (GPs + Basisrezepte); **`SEM_FLOOR = 0.55`** („verwandt" — bewusst großzügig, die KI urteilt selbst). Fängt genau das, wofür der lexikalische Pfad blind ist: Umlaute im SQL-LIKE („kuerbis" ↛ „Püree: Kürbis") und Komposita/Wortreihenfolge.
4. **Hybrid-Re-Ranking:** mit Query-Vektor → Cosinus-Score; Bausteine OHNE Embedding → `lexical_score × 0.5` (leichte Abwertung). Ohne Vektor (offline/kein Key) → rein lexikalisch (graceful). Cap: **max 15 Bausteine**.
5. **Convenience-Gate (`prefer_selfmade`):** bei `from_scratch`/`teil_convenience` bzw. Niveau gehoben/haute_cuisine warnt der Prompt-Block explizit davor, zusammengesetzte Komponenten (Mousse/Creme/Espuma/Püree/…) auf Convenience-/Roh-GPs zu kollabieren — Reuse gilt dann v. a. für eigene Basisrezepte + genuin rohe Einzelzutaten.

**Port-Hinweise:** Embeddings werden im Ziel **neu berechnet** (02_DATENMODELL §C: `embedding`-Tabelle wird nicht ETLt; Re-Embed-Job, Modell/Dimension via Plattform-KI-Gateway ⚠D3). Der Floor 0.55 ist modellabhängig kalibriert — bei Modellwechsel mit kleinem Eval-Set (GT-T94b-artige Fälle) nachkalibrieren und als Konfigurationswert ausführen (kein Hardcode). **V-04 ändert NICHTS an den GL-04-Schwellen — es ist ein vorgelagerter, additiver Layer.**

### 6.2 V-05 — Matcher-Decompounding („Kürbispüree" ↔ „Püree: Kürbis") — GEPLANTE ERWEITERUNG

**Register:** V-05 (Prio mittel, D-5). **Status: in Rust NICHT implementiert.** Die bekannte Kern-Schwäche des mechanischen Matchers ist die **Kompositum↔Split-Blindheit**: „Kürbispüree" und „Püree: Kürbis" teilen kein Token (`kuerbispueree` ≠ {pueree, kuerbis}), `token_matches` verbindet sie nicht (kein gemeinsamer Stamm, Prefix-Regel braucht Längen-Diff ≤ 2). GT-T94b dokumentiert die Lücke absichtlich („Hackfleisch vom Rind" ↛ „Rinderhackfleisch").

**Hebel-Historie (Audit_VK_Matching):** Hebel 1 = strikter Matcher (gebaut, dieses GL) · Hebel 2 = Rezept-Namens-Normalisierung auf §1-Syntax `Typ: Bezeichnung` (V-03, Seed-ETL — entschärft die Kandidaten-Seite, weil `:` ein Tokenizer-Trenner ist) · Hebel 3 = RAG/Reuse-at-Generation (V-04, §6.1 — Embeddings sehen durch Komposita hindurch) · Hebel 4 = echtes Decompounding (dieses V-05). In der Tauri-App mildern heute nur `substring_overlap` (Shortlist §3.9) und der V-04-Embedding-Pass.

**Spezifizierter Ansatz für den PHP-Port (Feature-Flag, NACH bestandener Paritäts-Suite):**

1. **Marker-basierter Split (nur Query-Seite):** Endet ein Query-Token auf einen bekannten Kopf-Marker (konservative Liste: Vereinigung HALBFABRIKAT_MARKER + Sub-Typ-Substantive: pueree, fond, bruehe, coulis, mark, sugo, jus, creme, mousse, …) und ist der Rest-Präfix ≥ 3 Zeichen, erzeuge eine **Zusatz-Query-Variante** `{präfix, marker}`: `kuerbispueree` → `{kuerbis, pueree}`. Kandidaten-Seite braucht keinen Split — §1-Syntax-Namen („Püree: Kürbis") tokenisieren ohnehin zu `{pueree, kuerbis}` (V-03 ist daher Vorbedingung für vollen Nutzen).
2. **Scoring:** Variante läuft als zweiter `match_score`-Durchlauf; es zählt `max(score_original, score_variante)`. KEIN neuer Score-Mechanismus, keine Schwellen-Änderung.
3. **Schutzregeln:** Split nie anwenden, wenn das Original bereits ≥ 0.85 erreicht (kein Risiko ohne Not); Geschwister-Sorten-Schutz bleibt durch unverändertes `token_matches` (butter ↛ butternut); „sauce" ist KEIN Split-Marker (Sojasauce-Schutz, wie beim Halbfabrikat-Gate).
4. **Künftige Golden-Tests (bei Aktivierung):** „Kürbispüree" → Sub »Püree: Kürbis« (≥ 0.85) · „Hackfleisch vom Rind" → GP »Rinderhackfleisch: frisch« (≥ 0.70) · Gegenprobe: „Sojasauce" bleibt GP.

### 6.3 Weitere offene Weichen

| # | Weiche | Stand | Anker |
|---|---|---|---|
| W-1 | **Akzent-Folding im Tokenizer** (A-5, crème fraîche): nach Paritäts-Suite als eigener Schritt; betroffene Golden-Tests (GT-T01-Familie, GT-T57 „Crème brûlée") dann mitversionieren | PHP-Soll, Rust-Ist nein | §1.3 A-5; Regelwerk_Basisrezepte §5 |
| W-2 | **Gestemmte/normalisierte Index-Spalte** für die SQL-Vorfilterung (LIKE findet „walnuss" für Query „walnuesse" nicht — Recall-Loch VOR dem Scoring) | Matching_Logik „Offene Punkte"; in Laravel trivial: Spalte `name_normalized` (tokenize+stem, via Observer persistiert) + LIKE darauf | Matching_Logik.md:79 |
| W-3 | **KI-Validierungs-Pass** (semantisches Urteil über fuzzy-Treffer statt Token-Zählen) — Vorstufe existiert als grounded LLM-Disambiguierung über `candidates_for` | Roadmap; Schnittstelle = §3.9-Shortlist | Matching_Logik.md:80; GL-06 |
| W-4 | **Tiebreaker-Iterationsordnung** ist in Rust implizit rowid-basiert (Invariante 7) — beim Port explizit `ORDER BY id ASC` setzen und als eigenen PHPUnit-Test absichern (GT-T62 hängt daran) | Port-Pflicht | §2.4 Invariante 7 |
| W-5 | **`vocab_sub_rezept_typ`-Tagging lückenhaft** (viele Sub-Rezepte ungetaggt → Boost greift nicht) — Daten-Hygiene, kein Logik-Thema | Datenpflege BHG-Team | 02_DATENMODELL A.3 |
| W-6 | **`match_method`-Enum-Cast** verhindert die A-10-Klasse von Bugs strukturell (Tippfehler `override_sub` wäre Compile-/Cast-Fehler statt Laufzeit-CHECK-Verletzung) | Port-Pflicht (V-06-Geist) | §1.3 A-10; §2.3 |

---

## Changelog

- 2026-06-10 — E3-Ausarbeitung, code-verifizierte Fassung: vollständige Extraktion aus recipe_matching.rs (Stand 4.4u; 84 Tests) + stemming.rs (7 Tests) + commands.rs (beide Aufrufer). 96 Golden-Cases; 10 dokumentierte Abweichungen (A-1…A-10, neu: A-9 staler Modul-Docstring, A-10 `override_sub`-CHECK-Bug); Pool-Größen + match_method-Verteilung DB-verifiziert; V-04-Konstanten (k=6, Floor 0.40, SEM_FLOOR 0.55, ×0.5, Cap 15) code-verifiziert; V-05 mit Hebel-Historie spezifiziert.
