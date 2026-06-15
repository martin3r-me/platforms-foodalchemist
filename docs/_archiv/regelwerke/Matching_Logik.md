> **EINGEFRORENE KOPIE (2026-06-10)** — Quelle: Cooking-Jarvis-Vault `07_WISSEN/07.01_Lebensmittel_und_Gastronomie/`. Normative Referenz für den Food-Alchemist-Spec-Korpus. Änderungen NUR in der Vault-Quelle, dann neu einfrieren.

---
name: Matching-Logik
description: Wie der Cooking-Jarvis-App-Matcher Zutat-Strings auf Grundprodukte und Sub-Rezepte abbildet — Stemming, Slug-Match, Pool-Priorität, Slug-Penalty
typ: Cross-Cutting-Referenz
betrifft: App-Module recipe_matching.rs + commands.rs (LA→GP, GP-Suggest, Recipe-Generator)
letzte_aktualisierung: 2026-05-27
---

# Matching-Logik (Cooking Jarvis App)

Diese Notiz dokumentiert, wie die App Zutat-Strings (aus KI-Generierung oder LA-Import) auf den GP-Pool (`wawi_gp_v2`) und den Sub-Rezept-Pool (`recipes`) abbildet. Sie ist die Referenz für alle Matcher-abhängigen Features (Recipe-Generator, LA→GP-Wizard, GP-Suggest).

> **Grundsatz:** Der Matcher ist **mechanisch** (Token-Overlap + Slug-Vergleich), kein semantisches Modell. Er „denkt nicht nach". Absurde Treffer werden über strukturelle Regeln (Slug-Penalty) verhindert, nicht über Sprachverständnis. Echtes semantisches Urteil wäre ein KI-Validierungs-Pass (Roadmap-Kandidat, noch nicht gebaut).

## 1. Stemming (Plural-/Flexions-Reduktion)

Zentrale Funktion: `stemming::stem_german(token)`. **Eine** Quelle für die ganze App — `commands::reduce_plural` delegiert daran, `recipe_matching::token_matches` nutzt sie als Konvergenz-Bedingung.

**Ziel ist Konvergenz, nicht Lemmatisierung.** Singular und Plural derselben Zutat müssen denselben Stem ergeben — egal ob der Stem ein echtes Wort ist.

| Eingabe | Stem | Konvergiert mit |
|---|---|---|
| Tomate / Tomaten | tomat | ✓ |
| Bohne / Bohnen | bohn | ✓ |
| Kartoffel / Kartoffeln | kartoffel | ✓ |
| amerikanisch / amerikanische / amerikanischen | amerikanisch | ✓ |
| Walnuss / Walnüsse | walnuss | ✓ (via Umlaut-Lookup) |

**Regeln (in `stemming.rs`):**
1. Tokens ≤ 4 Zeichen unverändert (Ei, Öl, Reis, Salz)
2. Umlaut-Plural-Lookup (gemappt, als Wort-Suffix): `nuesse→nuss`, `wuerste→wurst`, `saefte→saft`, `aepfel→apfel`, `koepfe→kopf`, `boeden→boden`, `kloesse→kloss`
3. `ss`-Endung = Wortstamm → kein Stripping (Nuss, Fluss, Kloß bleiben)
4. Generisches Suffix-Stripping (erste passende Stufe, Mindest-Reststamm 3): `innen`, `nnen`, `en`, `er`, `e`, `n`, `s`

**Eingabe-Annahme:** Tokens sind lowercase + Umlaut-gemappt (ä→ae, ö→oe, ü→ue, ß→ss).

**Bekannte Grenze (Härtefall):** Umlaut-Plurale mit Vokalwechsel, die nicht im Lookup stehen, konvergieren nicht (z.B. seltene Fälle). Bewusste Entscheidung: lieber unbehandelt als falsch geraten. Bei Bedarf den Lookup in `UMLAUT_PLURAL_SUFFIX` erweitern — nur 100%-eindeutige Fälle.

## 2. Slug-Match + Slug-Mismatch-Penalty

Die KI liefert pro Zutat einen `hauptzutat_slug`. GPs haben `hauptzutat_slug`. Der Match-Score (`match_score` in recipe_matching.rs):

1. **Slug-exact** (`rotwein` == `rotwein`) → Score 1.0
2. Sonst **F1 über Token-Intersection** (gestemmt) + Slug-Prefix-Bonus (max 0.15)
3. **Slug-Mismatch-Penalty (4.4k-A):** wenn beide einen Slug haben und NICHT verwandt → Score auf 0.45 gecappt → no_match

**Verwandtschaft** = gemeinsamer Wortstamm-Prefix ≥ 4 Zeichen ODER einer ist Prefix des anderen:
- `rindfleisch` ↔ `rindergulasch` → Stamm „rind" (4) → verwandt, kein Penalty
- `rotwein` ↔ `rapshonig` → nur „r" gemeinsam → Penalty → no_match

Das verhindert den klassischen Fehlmatch über ein gemeinsames Neben-Token („Rotwein **trocken**" ↔ „Rapshonig **trocken**").

## 3. Pool-Priorität (GP vs. Sub-Rezept)

`match_ingredient(.., mode)`:

- **`GpFirst`** (Default): GP-Pool zuerst, Sub-Rezept nur als Fallback unter Schwelle. Verhindert dass spezifische Sub-Rezept-Namen den generischen GP überlagern.
- **`SubRecipeFirst`** (bei `convenience_level=from_scratch`): Sub-Rezept-Pool zuerst — findet „Heller Kalbsfond" (Basisrezept) statt „Kalbsfond Konzentrat" (GP).

**Halbfabrikat-Gate (4.4k-Fix):** `SubRecipeFirst` dreht die Priorität NUR wenn die Zutat selbst ein Halbfabrikat ist (`query_is_halbfabrikat` — Marker: fond, brühe, reduktion, demi, coulis, pueree, glace, fumet, veloute, espuma als Substring). Grund-Zutaten (Rotwein, Knoblauch) bleiben GP-First, auch bei from-scratch — sonst „Rotwein"→„Rotwein-Vinaigrette"-Sub statt GP.

## 4. Sub-Typ-Hint-Boost (4.4b)

Beim Sub-Rezept-Match bekommt ein Kandidat +0.20 Score-Boost, wenn die Zutat einen Sub-Typ-Marker trägt (`detect_sub_typ_hint` — karamellisiert→karamell, Pesto→paste, Vinaigrette→vinaigrette, …) UND das Sub-Rezept den passenden `sub_rezept_typ`-Tag hat. „Karamellisierte Walnüsse" findet so gezielt „Walnuss-Karamell" (Tag=karamell) statt anderer Walnuss-Rezepte.

## 5. Match-Status-Schwellen

| Score | Status | UI |
|---|---|---|
| ≥ 0.85 | exact | ✅ |
| 0.70–0.85 | fuzzy_high | 🟢 |
| 0.50–0.70 | fuzzy_low | 🟡 |
| < 0.50 | no_match | 🔴 → ✨ GP anlegen (Inline-Flow 4.4j) |

## Offene Punkte / Roadmap

- **SQL-LIKE nutzt kein Stemming:** Die Vorfilterung in `best_gp_match`/`best_subrecipe_match` ist ein exakter Substring-LIKE. Plural-Token finden manchmal die Singular-Form nicht in der DB-Vorfilterung (z.B. „walnuesse" findet „walnuss"-Zeile via LIKE nicht). Sauber wäre eine gestemmte Index-Spalte (`name_stemmed`) — Roadmap 4.4i-Folgeschritt, noch nicht gebaut.
- **KI-Validierungs-Pass:** echtes semantisches Urteil über fragwürdige Matches statt mechanischem Token-Zählen. Roadmap-Kandidat (Tokens/Latenz-Kosten).