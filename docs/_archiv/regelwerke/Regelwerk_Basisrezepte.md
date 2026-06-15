> **EINGEFRORENE KOPIE (2026-06-10)** — Quelle: Cooking-Jarvis-Vault `07_WISSEN/07.01_Lebensmittel_und_Gastronomie/`. Normative Referenz für den Food-Alchemist-Spec-Korpus. Änderungen NUR in der Vault-Quelle, dann neu einfrieren.

---
typ: Regelwerk
domain: Basisrezepte (03_KUECHE/03.02_Basisrezepte/)
version: 1.3
gestartet: 2026-05-22
finalisiert: 2026-05-22
status: VERBINDLICH — alle 25 Haupt-Fragen + 7 Direct-Overrides + §11 Derivat-GPs + §1 Naming-Syntax + §1.2 Typ-Vokabular (v1.3, Single Source lookup_recipe_typ) beantwortet
pflicht_referenz_bei:
  - Recipe-Migration (alle Skripte 200-208)
  - Recipe-Skills (food_dna_canvas, recipe_creator, komposition_builder, ...)
  - Zukünftige Rezept-Imports / Re-Runs
verwandte_regelwerke:
  - '[[Regelwerk_Grundprodukte]]'
  - '[[Regelwerk_Lieferantenartikel]]'
---

# Regelwerk Basisrezepte

> **Status v1.0 — VERBINDLICH (finalisiert 2026-05-22).** Alle 25 Haupt-Fragen + 7 Direct-Overrides durch 7 Sessions-Runden beantwortet. Pro Sektion sind die verbindlichen Regeln unter **GESETZT** dokumentiert. Bei Konflikt mit Skript-Code oder Memory: dieses Regelwerk gewinnt.
>
> Spätere Änderungen: über neuen Changelog-Eintrag + Bump der `version` im YAML. Pipeline-Skripte (`203_*`, `204-208_*`) müssen gegen das finale Regelwerk geprüft und ggf. angepasst werden.

---

## §1 Naming-Syntax (formalisiert)

> **Status:** Verbindliche Benennungs-Syntax für **neue** Basisrezepte (v1.2, 2026-05-27). Analog zu `Regelwerk_Grundprodukte` §6, aber für Zubereitungen statt Rohstoffe. Die Namens-KI (`ai_normalize_recipe_name`) zieht diese Syntax über die Feld-Hülle `recipes.name`.
>
> **Legacy:** Die 1369 importierten Namen stehen noch in ALL-CAPS ohne Typ-Präfix (`HANDKÄS AUFSTRICH`, `BIRNENSORBET`). Die Migration auf diese Syntax ist ein **eigener, optionaler Sprint** (Backlog) — nicht Blocker für die Syntax-Festlegung. Neue Rezepte folgen ab sofort §1.

### §1.0 Grundprinzip — `Typ: Bezeichnung`

Ein **universelles** Schema, kein Entscheidungsbaum. Der **Typ** (Zubereitungsform) ist das Kopf-Nomen — wie der Produktname beim GP. Die **Bezeichnung** (Leitkomponente ODER kulinarischer Eigenname) folgt nach dem Doppelpunkt.

```text
Birnensorbet            →  Sorbet: Birne
Selleriepüree           →  Püree: Sellerie
Beurre Blanc            →  Schaumsauce: Beurre Blanc
Panna Cotta Classic     →  Crème: Panna Cotta
Butter Chicken nach Paul→  Sous-Vide: Butter Chicken   (Herkunft → YAML)
```

**Vorteile:** sortiert alle Pürees / Sorbets / Fonds zusammen (= Hauptgruppen-Bündelung schon im Namen), maschinell parsebar über `:`, eindeutig erweiterbar. Auch Eigennamen-Gerichte bekommen einen Typ-Präfix, der sie funktional verortet (`Schaumsauce: Beurre Blanc`).

### §1.1 Vollständige Syntax

```text
Typ: Bezeichnung[, Modifikator] [(Variante)]
```

| Slot | Pflicht? | Beschreibung | Beispiel |
|------|----------|-------------|----------|
| **Typ** | Pflicht | Zubereitungsform aus §1.2-Vokabular (Singular) | `Püree`, `Sorbet`, `Schaumsauce`, `Fond` |
| **Bezeichnung** | Pflicht | 1–2 Leitkomponenten **ODER** etablierter kulinarischer Eigenname | `Sellerie`, `Birne`, `Beurre Blanc`, `Panna Cotta` |
| **Modifikator** | Optional (selten) | Nicht-Aroma-Präzisierung wenn nötig | `geeist`, `warm` |
| **Variante** | Bedingt | Diskriminator in Klammern (§4) | `(Grund)`, `(Fein)`, `(Klassisch)` |

**Trennzeichen:**
- Doppelpunkt `:` — trennt Typ von Bezeichnung (Leerzeichen NACH, nicht davor: `Püree: Sellerie`)
- Bindestrich `-` — koppelt zwei Leitaromen, dominantes zuerst (max 2): `Granité: Minze-Shiso`
- Komma `,` — leitet einen Modifikator ein (selten gebraucht)
- Runde Klammern `()` — umschließen die Variante / den Diskriminator (§4)

### §1.2 Typ-Vokabular (kontrolliert)

Der Typ ist der **präziseste etablierte Standard-Begriff**, der noch als Zubereitungs-Kategorie taugt — und muss zur Hauptgruppe des Rezepts passen. Default = Hauptgruppe im Singular; feinere Standard-Typen sind erlaubt wo eindeutig (`Schaumsauce` statt `Sauce`, `Demi-Glace` statt `Fond`, `Vinaigrette` statt `Dressing`).

> **VERBINDLICH ab 2026-06-02 (User-Freigabe).** Single Source of Truth ist die DB-Tabelle **`lookup_recipe_typ`** (Hauptgruppe → erlaubte Typen, Migration 216). Der Normalizer `ai_normalize_recipe_name` (Rust) injiziert für die Hauptgruppe des Rezepts genau diese Liste in den Prompt und erzwingt den Typ-Präfix daraus. Diese Tabelle ist ein lesbarer Spiegel — Änderungen IMMER zuerst in `lookup_recipe_typ` (dann hier nachziehen). Title Case **mit Akzenten/Umlauten** (`Crème`, `Béarnaise`, `Demi-Glace`, `Granité`).

| Hauptgruppe (recipe_hauptgruppen) | Erlaubte Typen |
|---|---|
| Fonds & Reduktionen | `Fond`, `Jus`, `Demi-Glace`, `Glace`, `Sud`, `Reduktion`, `Essenz`, `Consommé`, `Brühe` |
| Saucen | `Sauce`, `Schaumsauce`, `Buttersauce`, `Beurre Blanc`, `Hollandaise`, `Béarnaise`, `Velouté`, `Béchamel`, `Mayonnaise`, `Curry`, `Steakbutter` |
| Dressings | `Dressing`, `Vinaigrette` |
| Süße Saucen | `Coulis`, `Custard`, `Karamellsauce`, `Schokoladensauce`, `Sirup`, `Glasur` |
| Beizen & Marinaden | `Beize`, `Marinade`, `Rub`, `Würzpaste`, `Pickle-Sud`, `Lake` |
| Konservierungen | `Chutney`, `Kompott`, `Relish`, `Confit`, `Konfitüre`, `Pickle`, `Ferment` |
| Pürees & Marken | `Püree`, `Mark`, `Coulis` |
| Geleen & Gele | `Gel`, `Gelee`, `Aspik`, `Sphäre`, `Fruchtkaviar` |
| Mousses & Espumas | `Mousse`, `Espuma`, `Schaum`, `Bavarois`, `Soufflé` |
| Cremes & Cremaux | `Crème`, `Cremaux`, `Curd`, `Ganache`, `Buttercreme`, `Panna Cotta`, `Pudding`, `Flan`, `Brûlée` |
| Aufstriche & Pestos | `Aufstrich`, `Pesto`, `Dip`, `Tapenade`, `Rillette` |
| Suppen | `Suppe`, `Velouté`, `Crème-Suppe`, `Consommé`, `Eintopf`, `Kaltschale` |
| Charcuterie & Wurst | `Wurst`, `Terrine`, `Pastete`, `Rillette` |
| Sous-Vide & Garmethoden | `Sous-Vide`, `Confit`, `Schmorgericht`, `Garmethode` |
| Beilagen | `Beilage`, `Risotto`, `Polenta`, `Gnocchi`, `Knödel` *(+ Eigenname-Gerichte)* |
| Salate | `Salat` |
| Teige & Backwaren | `Teig`, `Biskuit`, `Brot`, `Focaccia`, `Brioche`, `Mürbteig`, `Blätterteig`, `Brandteig`, `Macaron` |
| Knusprige Komponenten | `Crumble`, `Tuile`, `Chip`, `Krokant`, `Streusel`, `Crunch`, `Kruste`, `Baiser` |
| Sorbets, Eis & Granité | `Sorbet`, `Eis`, `Granité`, `Parfait`, `Semifreddo` |
| Pralinen & Petits Fours | `Praline`, `Petit Four`, `Marshmallow`, `Trüffel`, `Bonbon`, `Pâte de Fruits` |
| Getränke | `Getränk`, `Sirup`, `Cordial`, `Shrub`, `Limonade`, `Cocktail`, `Mocktail`, `Smoothie` |
| Aromen & Öle | `Aromaöl`, `Öl`, `Aroma`, `Extrakt`, `Tinktur` |
| Sonstiges | (kein fester Typ — Eigenname zulässig) |

### §1.3 Bezeichnung-Slot

- **Eine Leitkomponente:** `Sorbet: Birne`, `Püree: Sellerie`, `Schaum: Karotte`.
- **Zwei Leitaromen:** Bindestrich-gekoppelt, dominantes/erstes Aroma zuerst, **max 2**: `Granité: Minze-Shiso`, `Eis: Weisswein-Ashanti`, `Crème: Passionsfrucht-Banane`. Weitere Nebenaromen NICHT in den Namen — die stehen in Zutaten/Beschreibung.
- **Etablierter Eigenname:** kulinarischer Fachbegriff bleibt in konventioneller Schreibweise stehen: `Schaumsauce: Beurre Blanc`, `Crème: Panna Cotta`, `Sauce: Currywurst`, `Sous-Vide: Pulled Pork`, `Aufstrich: Handkäs`.
- **Title Case** (§1.4). Eigennamen behalten ihre Standard-Schreibung inkl. Akzente (`Beurre Blanc`, `Crème`, `Sous-Vide`).

### §1.4 Großschreibung — Title Case

Neue Rezepte werden in **Title Case** geschrieben (analog GP-Regelwerk), NICHT ALL-CAPS:

| Richtig (neu) | Falsch (Legacy-Stil) |
|---|---|
| `Sorbet: Birne` | `BIRNENSORBET` |
| `Aufstrich: Handkäs` | `HANDKÄS AUFSTRICH` |
| `Schaumsauce: Beurre Blanc` | `BEURRE BLANC` |

Die 1369 Legacy-ALL-CAPS-Namen werden in einem späteren Migrations-Sprint umgestellt (Backlog).

### §1.5 Variante / Diskriminator (Klammer)

Querverweis §4. In Klammern. **Kernprinzip (User 2026-05-27, erweitert 2026-06-02):** Die Klammer trägt **nur, was zwei Rezepte voneinander unterscheidet** — sie ist kein Steckbrief. **Erweiterte Regel (alle beschreibenden Qualifier):** ein Qualifier kommt nur dann in den Namen, wenn er etwas hinzufügt, das **NICHT schon aus Typ + Bezeichnung evident** ist. `Sorbet: Himbeere` ist evident süß → **kein** `(süß)`. `Aioli` ist evident eihaltig → `(vegan)` nur an der pflanzlichen Schwester. Was die App ohnehin berechnet oder als Feld führt (Diät-Tags, Einheit aus Yield), gehört nicht redundant in den Namen.

**Schreibweise:** beschreibende Adjektiv-Qualifier **klein** (`(süß)`, `(würzig)`, `(vegan)`, `(warm)`, `(geeist)`); Versions-/Eigenname-Marker groß (`(Grund)`, `(Klassisch)`, `(Selleriebasis)`).

**Kontrolliertes Klammer-Vokabular:**

| Klammer-Typ | Inhalt | Beispiel | Wann |
|---|---|---|---|
| **Version** | `(Grund)` / `(Fein)` / `(Klassisch)` / `(Modern)` | `Püree: Erbse (Grund)` vs `(Fein)` | Mehrere Ausbaustufen desselben Rezepts |
| **Technik-/Basis** | `(Selleriebasis)` / `(Agar)` / `(Pektin)` / `(Gelatine)` | `Püree: Parmesan (Selleriebasis)` | Gleiche Bezeichnung, andere Technik/Basis |
| **Geschmacksrichtung** | `(süß)` / `(würzig)` | `Espuma: Karotte (würzig)` | **NUR** wenn eine Schwester der anderen Richtung existiert UND die Richtung nicht schon aus Typ/Bezeichnung hervorgeht (NICHT bei `Sorbet`/`Coulis` = evident süß) |
| **Diätform** | `(vegan)` / `(vegetarisch)` | `Mayonnaise: Aioli (vegan)` | **NUR** wenn vegan (Quelle: berechnetes `recipes.spec_is_vegan`, KEIN KI-Raten) UND der Typ normalerweise **tierisch** ist (Mayonnaise, Crème, Mousse, Bavarois, Buttersauce, Hollandaise, Eis auf Sahnebasis, Custard, Ganache, Panna Cotta, Pudding, Wurst, Terrine, Pastete, Rillette). NICHT bei inhärent pflanzlichen Typen (Sorbet, Coulis, Püree, Vinaigrette, Chutney, Kompott, Pesto) |
| **Zustand** | `(warm)` / `(kalt)` / `(geeist)` | `Soufflé: Erdbeere (geeist)` | Gleiches Rezept, anderer Service-Zustand |
| **Dubletten-Zähler** | `(2)`, `(3)` … | `Sorbet: Himbeere (2)` | Letzter Ausweg bei echten Gleichnamen (§1.8) |

> **Abgrenzung:** `(Klassisch)`/`(vegan)` als **Variante/Diskriminator** = bleibt im Namen. Die **berechnete** Diätform ohne Diskriminator-Mehrwert → Pills, nicht Name. **Einheit kg/Stk** → immer Datenfeld. **Herkunft** (`Broich`, `nach Paul`) → Feld (§1.6).
>
> **Divergenz-Schutz:** `(vegan)`/`(süß)` sind **Behauptungen**, die gegen die berechnete Aggregation passen müssen — Name darf nicht „vegan" sagen, während die Zutaten-Aggregation Ei/Milch findet. Quelle für vegan ist deshalb `spec_is_vegan`, nicht die KI.
>
> **Status `spec_is_vegan` (2026-06-02):** aktuell bei 0 Rezepten gesetzt (GP-Vegan-Tags zu dünn → Aggregation liefert überall 0). Die `(vegan)`-Regel ist damit **definiert + verdrahtet, aber dormant** — sie feuert automatisch, sobald die GP-Vegan-Tags + `206`-Recompute das Flag real befüllen. `(süß)`/`(würzig)` greifen sofort.

### §1.6 Herkunft / Quelle / Hausvariante → NICHT im Namen

**GESETZT (User 2026-05-27):** Herkunft, Quelle, Urheber und Hausvarianten gehören **nicht in den Rezeptnamen**, sondern in ein YAML-/DB-Feld:

| Legacy-Name | Neuer Name | Herkunft-Feld |
|---|---|---|
| `CURRYWURST SAUCE BROICH` | `Sauce: Currywurst` | `Broich` |
| `BUTTER CHICKEN NACH PAUL` | `Sous-Vide: Butter Chicken` | `nach Paul` |
| `BBQ RUB NACH ERWIN` | `Rub: BBQ` | `nach Erwin` |
| `FRIKADELLEN NACH OMAS ART` | `Sous-Vide: Frikadellen` | `nach Omas Art` |

> **Abgrenzung zu §1.5:** `(Klassisch)`/`(Grund)`/`(Fein)` = Versions-Variante (bleibt im Namen). `Broich`/`nach Paul` = Herkunft (→ Feld). Im Zweifel: bezeichnet es eine **andere Version desselben Gerichts** → Klammer; bezeichnet es **wer/woher** → Feld.
>
> **Schema-TODO:** Neue Spalte `recipes.herkunft` (TEXT, NULL) — bis dahin Herkunft übergangsweise in `notizen_manual`.

### §1.7 `recipe_key` (Slug)

`recipe_key` = `slug(name)` (lowercase, Umlaut→ae/oe/ue, ß→ss, Doppelpunkt + Leerzeichen + Sonderzeichen → `_`, Mehrfach-`_` kollabiert). Beispiel: `Sorbet: Birne` → `sorbet_birne`, `Schaumsauce: Beurre Blanc` → `schaumsauce_beurre_blanc`. Der Typ-Präfix bündelt den Key automatisch nach Zubereitungsart.

### §1.8 Dubletten-Diskriminator

**GESETZT (User 2026-05-22):**
- **F1.2 → Kategorie als Diskriminator.** Bei gleichem `slug(name)` über verschiedene Kategorien: `recipe_key` = `slug(name)` + `_` + `slug(kategorie)` (35 Legacy-Fälle). Identische Duplikate (z.B. `HIMBEERSORBET = HIMBEERSORBET`) zusätzlich mit `_2`-Suffix. Beispiele: PFLAUMENSAUCE (KALTE_SAUCE_WUERZIG vs SAUCE_MANUFAKTUR), MAISCREME (PUEREE vs SCHNITTFESTE_CREME).
- Durch den Typ-Präfix (§1.0) sinkt die Dubletten-Rate, weil der Typ schon im Namen steckt — der Kategorie-Diskriminator greift nur noch bei echten Gleichnamen innerhalb desselben Typs.

### §1.9 Splits

**GESETZT (User 2026-05-22 Runde 7):**
- **F1.1 → Splits via Recipe_Corrections.md, zwei separate Rezepte erzeugen.** Beispiel `HELLER GEFLÜGEL- ODER KALBSFOND` wird gesplittet in `Fond: Geflügel (hell)` + `Fond: Kalb (hell)`. **Default-Match-Hierarchie:** referenziert ein Rezept nur „Fond"/„Brühe" (generisch), ist **`Fond: Geflügel (hell)` der Main-Fond / Default**. Excel-Original wird nicht angetastet.

### §1.10 Anti-Patterns (so NICHT)

| Falsch | Richtig | Grund |
|---|---|---|
| `Birne` (nur Komponente) | `Sorbet: Birne` | Typ-Präfix Pflicht (§1.0) |
| `Birnensorbet` (Compound, neu) | `Sorbet: Birne` | Neue Rezepte: `Typ: Bezeichnung` |
| `BIRNENSORBET` (ALL-CAPS, neu) | `Sorbet: Birne` | Title Case (§1.4) |
| `Sauce: Currywurst Broich` | `Sauce: Currywurst` + Herkunft-Feld | Herkunft nicht im Namen (§1.6) |
| `Eis: Weisswein-Ashanti-Vanille-Zimt` | `Eis: Weisswein-Ashanti` | Max 2 Leitaromen (§1.3) |
| `Sorbet: Birne, fruchtig & spritzig` | `Sorbet: Birne` | Keine Marketing-Floskeln |

### §1.11 Beispiele (verbindlich) — Vorher → Nachher

| Hauptgruppe | Legacy | Syntax v1.2 |
|---|---|---|
| Sorbets, Eis & Granité | `BIRNENSORBET` | `Sorbet: Birne` |
| Sorbets, Eis & Granité | `MINZ-SHISO GRANITEE` | `Granité: Minze-Shiso` |
| Sorbets, Eis & Granité | `WEISSWEIN-ASHANTI EIS` | `Eis: Weisswein-Ashanti` |
| Cremes & Cremaux | `LIMETTEN CREMAUX` | `Cremaux: Limette` |
| Cremes & Cremaux | `PANNA COTTA CLASSIC` | `Crème: Panna Cotta` (+ Variante `(Classic)` falls Versions-Marker) |
| Pürees & Marken | `ERBSENPÜREE (GRUND)` | `Püree: Erbse (Grund)` |
| Pürees & Marken | `PARMESANPÜREE (SELLERIEBASIS)` | `Püree: Parmesan (Selleriebasis)` |
| Saucen | `CURRYWURST SAUCE BROICH` | `Sauce: Currywurst` (Herkunft `Broich`) |
| Saucen | `KAROTTENSCHAUM` | `Schaum: Karotte` |
| Fonds & Reduktionen | `SPARGELFOND` | `Fond: Spargel` |
| Fonds & Reduktionen | `BRAUNE DEMIGLACE` | `Demi-Glace: braun` |
| Sous-Vide & Garmethoden | `BUTTER CHICKEN NACH PAUL` | `Sous-Vide: Butter Chicken` (Herkunft `nach Paul`) |
| Sous-Vide & Garmethoden | `KALBSHAXE SOUS-VIDE` | `Sous-Vide: Kalbshaxe` |
| Aufstriche & Pestos | `HANDKÄS AUFSTRICH` | `Aufstrich: Handkäs` |
| Dressings | `CAPRESE CLASSIC MARINADE` | `Marinade: Caprese (Classic)` |

---

## §2 Verarbeitungs-Reduktion (Brunoise → Roh-Form)

**GESETZT (User 2026-05-22):**
- „Chili-Brunoise", „Schalotten gewürfelt", „Karotten geschnitten" etc. → matche auf **rohe Grundform**.
- Die Verarbeitung wandert in `recipe_ingredients.note` (Audit-Trail) oder bleibt im `raw_text`.
- Verarbeitungs-Suffixe: `brunoise`, `würfel/wuerfel`, `gehackt`, `geschnitten`, `gerieben`, `geröstet`, `blanchiert`, `gestiftelt`, `gemahlen`, `püriert`, `in scheiben`, `in streifen`.

**GESETZT (User 2026-05-22):**
- **F2.2 → Tentative GP 'frisch' anlegen.** Wenn für eine Recipe-Zutat nur TK-GP existiert (z.B. Recipe „Karotten", nur „Karotten: TK, Baby" als GP): neuen GP `Karotten: frisch, ganz` tentative anlegen + TK-Variante als Sekundär-Option im Skript markieren. Konsistent zur Restaurant-/Catering-Herkunft (Frischeküche).
- **F2.3 → `Pfeffer schwarz: gemahlen` als Default** für `Pfeffer` ohne Modifikator (213× im Kochbuch, nur 6× „gemahlen" explizit, 42× „Körner" → Default-Annahme ist gemahlen). Bereits in §5 als Direct-Override gesetzt.

**GESETZT (User 2026-05-22 Runde 2):**
- **F2.1 → Convenience-Zukauf-Kriterium.** Spezifischer verarbeiteter GP nur dann, wenn der GP klar als **Convenience-Produkt zugekauft** wird (TK-Brunoise, Dosen-Tomaten-gehackt, vorgegarte Kartoffeln, fertige Sauce-Komponenten). Wenn Verarbeitung normalerweise in der Küche stattfindet (Würfeln, Hacken, Schneiden, Reiben) → Roh-Form. Saubere Trennung Convenience vs. Frischküche.

---

## §3 Pürees / Marks / Coulis

**GESETZT (User 2026-05-22):**
- **Frucht-Pürees** (Mango, Himbeer, Aprikose, Banane, Cassis, Passionsfrucht, Sauerkirsche, Yuzu, Kalamansi, Brombeere, Kokos, Erdbeere, Pfirsich, Birne, Ananas, Limette, Mandarine, Guave, Melone, Rhabarber, Marone, Bergamotte, Blutorange): **Boiron-TK-Variante** (`Fruchtpuerree X: TK`) ist Standard.
- **Gemüse-Pürees** (Lauch, Spinat, Sellerie, Karotten, Rote Bete, Fenchel, Brokkoli, Wirsing, Zucchini, Aubergine, Kresse, Pastinake): **Sub-Rezept-Stub**, selbst gemacht.
- **Wurzel-Pürees** (Kartoffel, Topinambur): existieren als GPs.

**GESETZT (User 2026-05-22 Runde 3):**
- **F3.1 → Tentative Boiron-Vorlage anlegen.** Fehlende Frucht-Pürees → neuer GP `Fruchtpueree X: TK` im wawi_gp_v2 als `tentative`. LA-First-Pipeline matched später den passenden Boiron-LA. Konsistent zur §3-Boiron-Default-Regel.
- **F3.3 → Coulis und Püree sind getrennt.** Püree = pur, gegart, fein passiert. Coulis = mit Zucker, Säure oder Bindung (oft Dessert-Sauce). Bestätigt durch 4 eigene COULIS-Rezepte im Kochbuch (Cranberry, Sanddorn, Passionsfrucht, Mirabellen). Eigene Kategorie `COULIS` bleibt erhalten.

**Excel-Validierung der §3-Regel:**
- 35 eigene PÜREE-Rezepte im Kochbuch sind **ausschließlich Gemüse/Hülsenfrüchte/Stärke** (Sellerie, Fenchel, Brokkoli, Quitte, Blumenkohl, Kartoffel, Kichererbsen, Mais, Topinambur, Spinat, Karotten, Petersilien, Paprika, Aubergine, Zucchini, Bohnen, Zwiebel, Kürbis, Steckrübe, Mandel, Kürbiskerne, Parmesan, Reis, Brot, Mais)
- KEINE Frucht-Pürees als Standalone-Rezepte → bestätigt Boiron-TK-Default für Früchte
- Ausnahmen mit Doppelung: Quittenmark (3× Zutat) + QUITTENPÜREE (eigenes Rezept) — beide Pfade legitim

**GESETZT (User 2026-05-22 Runde 7):**
- **F3.2 → Apfelmark bleibt als GP.** Industrieprodukt (gekauft im Glas/Tetra). Im Kochbuch 4× als Zutat referenziert, kein Standalone-Rezept. Konsistent zu „Konfitüren werden gekauft" und Frucht-Pürees=Boiron-Default.

---

## §4 Sub-Rezept-Hierarchie + Stubs

**GESETZT:**
- Sub-Rezept-Stubs werden mit `status='stub'` angelegt — `name`, `kategorie`, Rest NULL.
- User-Stubs aus Recipe_Corrections.md: BRAUNER LAMMFOND, GEMÜSEBRÜHE, GEKLÄRTE BUTTER (Basis Butter 250g).
- Split-Operation Zeile 624: HELLER GEFLÜGELFOND + HELLER KALBSFOND.
- **Default-Sub-Rezept-Regeln:**
  - `geflügelbrühe`/`hühnerbrühe`/`brühe` (generisch) → HELLER GEFLÜGELFOND
  - `rinderbrühe`/`fleischbrühe` → HELLER KALBSFOND
  - `kalbsjus`/`backenschmorjus` → BRAUNER KALBSFOND
  - `lammjus` → BRAUNER LAMMFOND (Stub)
  - `geflügeljus` → DUNKLER GEFLÜGELFOND
  - `gemüsebrühe` → GEMÜSEBRÜHE (Stub)
  - `mayonnaise` (ohne Modifikator) → STANDARD MAYONNAISE
  - `balsamico-dressing` → VR HAUSDRESSING

**GESETZT (User 2026-05-22 Runde 2):**
- **F4.1 → Auto-Stub + status='draft'.** Skript legt fehlende Sub-Rezepte automatisch als Stub an (`status='stub'`, nur name + kategorie). Eltern-Rezept bekommt `status='draft'`. Sammelt sich in User-Review-Queue, kann später befüllt werden.
- **F4.4 → GEKLÄRTE BUTTER ist Sub-Rezept** (aus Butter 250g hergestellt), nicht GP. Gleiche Behandlung wie NUSSBUTTER (kommt 4× im Kochbuch in Komposit-Rezepten vor, hat selbst kein Standalone-Rezept). **Vorschlag:** neue Kategorie `BUTTERZUBEREITUNG` für Klarbutter / Nussbutter / Braune Butter / Butterschmalz als Sub-Rezept-Familie. Final-Bestätigung Kategorie-Name in Runde 3.

**GESETZT (User 2026-05-22 Runde 4):**
- **F4.2 → Versionierung über separate Rezepte mit Klammer-Suffix.** Entspricht bestehender Kochbuch-Praxis: `ERBSENPÜREE (GRUND)` vs `ERBSENPÜREE (FEIN)`, `SPINATCREME` vs `SPINATCREME (FEIN)`, `KICHERERBSENPÜREE` vs `KICHERERBSENPÜREE (KLASSISCH)`. Jeder Suffix = eigenes Rezept mit eigener Allergen-/Kosten-Aggregation. Marker-Vokabular im Kochbuch: `(GRUND)`, `(FEIN)`, `(KLASSISCH)`, `GRUNDREZEPT`, `BASIS`. Echte Leicht/Voll-Versionen kommen im Kochbuch nicht vor.
- **F4.3 → Max. 3 Ebenen Rekursion erlaubt.** Eltern → Sub → Sub-Sub → Roh-GP. Topo-Sort im Aggregator (205) mit Visited-Set für Zyklus-Schutz. Beispiel: KARTOFFEL-NUSSBUTTER-ESPUMA → Nussbutter + Espuma-Basis → Roh.

---

## §5 Default-GPs für generische Zutaten

**GESETZT (User-bestätigt 2026-05-22):**
| Zutat (lowercase) | gp_v2_id | GP-Name | Begründung |
|---|---|---|---|
| `sahne`, `schlagsahne`, `geschlagene sahne` | 3243 | Sahne: konserviert, 30 % Fett | User |
| `ei`, `eier` | 840 | Eier: frisch, Groesse L, Bodenhaltung | M nicht verfügbar, L als Default |
| `eiweiß`, `eiweiss` | 2576 | Huehnereiweiss: fluessig, pasteurisiert | User: „flüssig", kein Bio |
| `weißwein`, `weisswein` | 6277 | Weisswein: konserviert, zum Kochen | User |
| `weißer pfeffer`, `pfeffer weiß` | 4899 | Pfeffer weiss: trocken, gemahlen | User |
| `pfeffer`, `schwarzer pfeffer` | 4895 | Pfeffer schwarz: trocken, gemahlen | Restaurant-Default |
| `salz` | 5470 | Salz / Kochsalz: trocken, unjodiert, Raffinade | User: unjodiert (Profi-Standard) |
| `mehl` | 6267 | Weizenmehl: trocken, Type 405 | Back-Default |
| `milch` | TBD | Milch: frisch, 3,5% | User 2026-05-22 (218× pur) |
| `olivenöl`, `olivenoel` | TBD | Olivenoel: konserviert, nativ extra | User 2026-05-22 (88× pur) |
| `zucker` | TBD | Zucker: trocken, Kristall, weiss | User 2026-05-22 (607× pur, wichtigste Zutat) |
| `gelatine` | TBD | Gelatine: trocken, Blatt | User 2026-05-22 Runde 3 (233× pur, 'Bl'-Einheit dominiert) |
| `crème fraîche`, `creme fraiche` | TBD | Crème_fraiche: konserviert, 30% | User 2026-05-22 Runde 3 (50× pur, Profi-Standard) |
| `sojasauce` | TBD | Sojasauce: konserviert, klassisch dunkel | User 2026-05-22 Runde 3 (46× pur, Kikkoman-Stil) |
| `honig` | TBD | Honig: konserviert, neutral/Blütenhonig | User 2026-05-22 Runde 3 (38× pur) |

> **NICHT als Direct-Override gesetzt** (User 2026-05-22): Butter (zu viele Sub-Typen: geklärt, braun, Nussbutter, Trüffel, Kakao, Butterschmalz → Gemini soll wählen). Knoblauch/Zwiebeln werden über `vocab_stk_default` (§6.3) plus Gemini abgedeckt.

> **TODO Pipeline:** Für die TBD-gp_v2_id-Werte (Milch, Olivenöl, Zucker, Gelatine, Crème fraîche, Sojasauce, Honig) muss ein Lookup-Skript die jeweiligen `gp_v2_id` aus `wawi_gp_v2 WHERE status='approved'` ermitteln und in den Override-File eintragen. Wenn GP fehlt → vor Migration anlegen.

**GENERELL:**
- **Kein Bio als Default** — Standard-Variante wählen, außer Recipe verlangt explizit Bio.
- Restaurant-Default = frisch / unjodiert / Standard, nicht Convenience/Bio/TK.

**GESETZT (User 2026-05-22 Runde 7):**
- **F5.2 → Olivenöl bleibt generisch 'nativ extra'.** Lieferanten-/Sorten-Wahl auf LA-Ebene (Profi-Standard-Marke). GP definiert nur die Qualitätsstufe.
- **F5.3 → Sahne einheitlich 30%.** Excel-Daten zeigen 0 Fälle expliziter Fettgehalts-Angabe (alle 304 Vorkommen ohne %). Profi-Default 30%. Dessert-Spezial-35% bei Bedarf als manueller Override pro Rezept.
- **F5.4 → Kein 'frisch gemahlen'-Premium-GP nötig.** Restaurant-Pfeffermühle ist Selbstverständlichkeit, kein separater GP-Eintrag. `Pfeffer schwarz: trocken, gemahlen` deckt alle Fälle ab.

**KEINE OFFENEN FRAGEN MEHR in §5.**

> **Matcher-Umsetzung (2026-06-01, 4.4s):** Der GP-Matcher erzwingt §5 jetzt deterministisch
> via `default_gp_alias()` + `resolve_gp_by_name()` (Spiegel von §4, env-resilient per
> Namensauflösung — kein hardcodiertes gp_v2_id; Existenz-Guard → Fallback wenn GP fehlt).
> **Befüllt (14 Generika, exakte DB-Namen):** salz→unjodiertes Kochsalz · zucker→Raffinade weiss
> (slug `zucker_raffinade`, NICHT der Bio-`zucker`) · sahne/schlagsahne→30 % · milch→3,5 % ·
> mehl→Type 405 · gelatine→kaltlöslich · weisswein→zum Kochen · olivenöl→hochwertig · honig→Imker ·
> sojasauce→glutenfrei · pfeffer→schwarz/weiss gemahlen · ei/eier→Größe L. Greift NUR für die
> generische Einzel-Zutat (1 Token) — „Meersalz"/„Weizenmehl Type 550"/„Trüffelhonig"/„Brauner
> Zucker" unberührt.
> **Eigelb/Eiweiss sind EIGENLEISTUNGS-ABHÄNGIG** (`prefer_raw`/from_scratch): From-Scratch →
> ganzes Ei selbst trennen (`Eier: frisch, Größe L`, Einheit Stk); Standard/Convenience →
> pasteurisierte Flüssigware (`Eigelb: fluessig, pasteurisiert` / `Huehnereiweiss: …`, food-safe
> Roh-Anwendung). Das ist die saubere Roh-Ei-Logik (User-Befund 2026-06-01).
> **NICHT befüllt:** `creme fraiche` — frz. Akzent (è/î) bricht den ASCII-Tokenizer (TODO:
> Tokenizer-Akzent-Mapping ODER Feld-Refactor → `_Naming_Refactor_Spec`). Siehe `recipe_matching.rs`
> `default_gp_alias`.

---

## §6 Mengen, Einheiten, Yield

**GESETZT:**
- Basisrezepte = Produktionsmengen, **`yield_kg` statt Portionen**.
- Vokabular-Tabelle `vocab_einheit` mit Konversionsfaktoren in g/ml (siehe DB-Schema).
- Mengen-Bereiche: `menge` = Untergrenze, `menge_max` = Obergrenze. Optional-Marker: `is_optional`.

**GESETZT (User 2026-05-22 Runde 2+3):**
- **F6.1 → Auto-Berechnung `yield_kg` = Sum(Zutaten in g, inkl. Stk. × vocab_stk_default).** Kein Putz-/Garverlust einrechnen. Schnell und ausreichend für Kalkulationen. Manueller Override bei kritischen Rezepten möglich (Feld `yield_kg_manual` mit Vorrang vor `yield_kg_computed`).
- **F6.3 → `vocab_stk_default`-Lookup-Tabelle.** Zutat (lowercase) → Default-Gewicht in g. Vorbefüllung Top-30 aus Excel-Scan (siehe unten). Wird für `yield_kg`-Berechnung genutzt.

**Vorbefüllung `vocab_stk_default` (Top-30 aus Kochbuch — User-Review nötig):**

| Zutat | Default g/Stk. | Vorkommen |
|---|---|---|
| knoblauchzehe | 5 | 104 |
| schalotte | 30 | 77 |
| eigelb | 20 | 43 |
| ei | 58 | 42 |
| lorbeerblatt | 0.2 | 40 |
| sternanis | 0.5 | 23 |
| champignon | 15 | 22 |
| vanilleschote | 3 | 20 |
| eiweiss | 35 | 19 |
| nelke | 0.1 | 19 |
| zitronengras (stiel) | 15 | 16 |
| pimentkorn | 0.1 | 14 |
| lauch (stange) | 200 | 13 |
| staudensellerie (stange) | 80 | 12 |
| zimtstange | 2 | 11 |
| wacholderbeere | 0.1 | 10 |
| sardellenfilet | 5 | 9 |
| kirschtomate | 15 | 9 |
| apfel | 180 | 8 |
| zitrone | 80 | 8 |
| pfefferkorn | 0.05 | 8 |
| zwiebel (mittel) | 150 | 22 |

**GESETZT (User 2026-05-22 Runde 6):**
- **F6.2 → Putz-/Garverlust aus GP-Tabelle übernehmen.** Yield-Berechnung: `Sum(zutat_menge_g × (1 - gp.putz_verlust_pct - gp.gar_verlust_pct))`. Konsistent zur Stammdaten-Pflege in `wawi_gp_v2`. Optional `recipe_ingredients.verlust_override_pct` für Edge-Cases.

**GESETZT (User 2026-05-22 Runde 7):**
- **F6.4 → Mittelwert für Yield-Berechnung.** Bei „1–2 EL" gilt 1.5 EL als Yield-Beitrag. Felder `menge` (Untergrenze) + `menge_max` (Obergrenze) bleiben für Doku-Zwecke erhalten. Pragmatisch, mittelt sich raus über alle Rezepte.

**KEINE OFFENEN FRAGEN MEHR in §6.**

---

## §7 Allergen + Zusatzstoff-Vererbung

**GESETZT:**
- ALL-MAXIMAL Union (worst case) — konsistent zu LA-Regelwerk §10.
- 4-Wert-Modell: `enthalten | spuren | nicht_enthalten | unbekannt`. Default `unbekannt` (kein false-confident).
- Rekursiv durch Sub-Rezept-Graph (Topo-Sort).
- 18 Zusatzstoffe aus `declarations`-Tabelle: 0/1/NULL.
- Konfidenz: HIGH/MEDIUM/LOW/UNKNOWN.

**GESETZT (User 2026-05-22 Runde 4):**
- **F7.1 → Ungemappte Zutaten = alle 14 EU-Allergene `unbekannt`.** Konservativ: solange auch nur 1 Zutat im Rezept ungemappt ist, gesamtes Allergen-Profil = `unbekannt` mit Konfidenz `LOW`. Verhindert falsche Sicherheit. Sobald alle Zutaten gemappt sind, wird ALL-MAXIMAL aggregiert.
- **F7.2 → Eine LA mit `spuren` reicht** für GP-Aggregation = `spuren`. Worst-case-konsistent zum LA-Regelwerk §10 (ALL-MAXIMAL). Sicherste Aussage für Gäste, kein Restrisiko.

**GESETZT (User 2026-05-22 Runde 5):**
- **F7.3 → Stub-Allergene = alles `unbekannt`.** Konsistent zu F7.1. Solange Zutaten leer sind, keine Allergen-Aussage. Konfidenz `UNKNOWN`. User muss Stub befüllen damit Allergen-Tags greifen.

---

## §8 KI-Beschreibung + Tag-Vergabe (Phase 8)

**GESETZT:**
- Restaurant/Catering-Default-Konzept-Tags: `fine_dining`, `event_catering`.
- Vokabular-Tabellen verbindlich (vocab_aroma_profil, vocab_textur, vocab_position_im_menue, vocab_diaet, vocab_konzept, vocab_saison, vocab_funktion, vocab_temperatur).

**GESETZT (User 2026-05-22 Runde 5):**
- **F8.3 → Nüchtern-sachlich.** 3-5 Sätze: was ist das Rezept, wofür wird es verwendet, dominanter Aroma-Charakter, Textur, typische Einsatz-Anlässe (Restaurant/Catering). Interne Doku-Qualität + Basis für spätere Verkaufstexte. Kein Marketing-Floralismus.

**GESETZT (User 2026-05-22 Runde 6):**
- **F8.4 → KI-Beschreibung auch für Stubs generieren** auf Basis Name + Kategorie. Markiert als `description_status='tentative'`. Bekommt bessere Beschreibung sobald Zutaten/Zubereitung gefüllt sind. Sinnvoll für Foodbook-Verwendung schon vor Stub-Befüllung.
- **F8.5 → KI muss immer entscheiden** (kein NULL-Default), aber Bias zu `ganzjaehrig` — User-Aussage: „meiste wird ganzjährig sein". Saison-Tag = required Field, Gemini-Prompt enthält explizit den Bias. Saisonale Rezepte (Spargel, Kürbis, Erdbeere etc.) erkennt Gemini aus Hauptzutat.

**GESETZT (User 2026-05-22 Runde 7):**
- **F8.1 → Tabelle `recipe_aromakomponenten` jetzt im Schema anlegen, leer lassen.** Vermeidet Schema-Migration-2 wenn Pairing-Engine kommt. Befüllung später durch Verbindung zum Flavor-Pairing-Skill (07_WISSEN/07.02). Kein Pflichtfeld. Spalten: `aromakomponente` (Pyrazin/Lacton/Terpen/Furan/Schwefel/Phenol/Aldehyd/Ester/Sonstiges), `intensitaet` (1-5), `quelle_zutat_id`.

**GESETZT (User 2026-05-22 Runde 7):**
- **F8.2 → Pairing-Anker adaptive (3-7 pro Rezept).** Gemini entscheidet pro Rezept anhand Komplexität. Komplexe Komposit-Rezepte (Aufstrich mit 10 Zutaten) bekommen mehr Anker, einfache (Vinaigrette) weniger. Min=3, Max=7.

**KEINE OFFENEN FRAGEN MEHR in §8.**

---

## §9 EINBAHN-Sync SQL ↔ MD

**GESETZT:**
- Konsistent zu LA-Regelwerk §9: **Vault-Sync EINBAHN: SQL → MD**.
- Header-Banner in jedem MD: „Generiert aus SQL — manuelle Edits werden überschrieben".
- YAML-Flag `auto_generated: true`.

**GESETZT (User 2026-05-22 Runde 5):**
- **F9.1 → Eigenes SQL-Feld `recipes.notizen_manual`** (TEXT, NULL erlaubt). User editiert in MD-Bereich „Manuelle Notizen", Sync extrahiert das Feld bidirektional. Strukturierter als Marker-Zeile, ermöglicht zukünftige Workflow-Tools (Notizen-Suche, Bulk-Edit).

**GESETZT (User 2026-05-22 Runde 6):**
- **F9.2 → Manuell on-demand.** User triggert `python 207_recipe_md.py` und `208_gp_md.py` wenn er Synchronisation will. Volle Kontrolle, kein Hintergrund-Rauschen während Edits. Konsistent zur LA-First-Pipeline-Praxis. Optional spätere Erweiterung: launchd-Schedule bei Bedarf.

---

## §10 Anti-Patterns

**GESETZT:**
- Parser-Garbage (Temperaturen `65°C`, Adjektive `vakuumiert`, Sub-Section-Header `Boden:`, Multi-Zutat `Vanille, Zitrone, Zimt`) → `match_method='ignored'` in recipe_ingredients.
- Bio-Leakage vermeiden (Standard > Bio außer Recipe verlangt).
- Generisches > Spezifisches vermeiden (z.B. „Brauner Zucker → Rohrzucker: braun" statt generisch „Rohrzucker").

**GESETZT (User 2026-05-22 Runde 5):**
- **F10.3 → status='draft' + User-Review-Queue.** Wie aktuell in Pipeline. Rezept wird angelegt, aber als `draft` markiert. Bleibt aus KI-Beschreibung-Phase (206) ausgeschlossen bis Mapping-Quote ≥50% steigt. Kontinuierlicher Fortschritt möglich, keine Migration-Blockade.

**GESETZT (User 2026-05-22 Runde 7):**
- **F10.1 → Multi-Zutat-Zeilen werden gesplittet, Mengen via Gemini aus Rezept-Kontext geschätzt.** Z.B. „Vanille, Zitrone, Zimt, Rum" → 4 ingredient-Zeilen. Gemini bekommt Hauptzutat-Liste + Original-Zubereitungstext + Excel-Gesamtmenge des Rezepts als Kontext, schätzt plausible Mengen pro Sub-Zutat. `match_method='ki_estimated_split'`, Konfidenz `MEDIUM`. Genauer als Default-ignore-Verhalten.
- **F10.2 → Klammer-Fragmente als Annotation in `recipe_ingredients.note`.** Hauptzutat (z.B. Cognac) wird gematched, Klammer-Text („je nach Ansatz", „oder Whisky") landet in `note`-Feld. Keine Alternativ-Mengen, eine Match-Zeile.

**KEINE OFFENEN FRAGEN MEHR in §10.**

---

## §11 Derivat-GPs (Schalen, Abschnitte, Karkassen, Parüren, Zesten, Säfte, Fett, Knochen, Stiele)

**Hintergrund:** ~500 Vorkommen im Kochbuch (1 alle 2.7 Rezepte). Derivate sind **Nebenprodukte beim Zerlegen/Verarbeiten eines Mutter-GPs** (z.B. Gurkenschale entsteht beim Schälen einer Gurke). Sie haben keinen eigenen Lieferanten-Artikel, aber müssen in Rezepten matchbar sein.

**GESETZT (User 2026-05-22 Runde 8):**

- **F11 → Hybrid-Modell: abhängige GPs mit `derivat_von_gp_id`.** Eigene Einträge in `wawi_gp_v2` mit `is_derivat=1`, Verweis auf Mutter-GP, `requires_la=0`. Allergene werden LIVE vom Mutter-GP vererbt (kein eigener Snapshot). Bestehendes Naming-Schema gilt: `Gurke: frisch, Schale`, `Kalb: frisch, Parüren`, `Zitrone: frisch, Zeste`.
- **F11.1 → Default frisch gepresst = Derivat** für Säfte. Konsistent zur Restaurant-/Catering-Herkunft. Saft-Derivat von Mutter-Frucht (z.B. `Zitrone: frisch, Saft` als Derivat von `Zitrone: frisch, ganz`). Override-Marker im Rezept-Text („konserviert", „Tetra") wechselt zum Industrie-GP. Excel-Daten zeigen keinerlei explizite Industrie-Hinweise → reine Frisch-Default-Welt.
- **F11.2 → Zwei separate `derivat_typ`-Werte: `schale` und `zeste`.** `schale` = ganze Schale inkl. weißem Albedo (oft bitter, fürs Ölaromatisieren). `zeste` = nur oberste aromatische Schicht (= synonym mit `abrieb`, mild, fürs Würzen). Kulinarisch unterschiedlich verwendbar.
- **F11.3 → Default Preis = NULL (Abfall-Verwertung).** Schale, Karkasse, Parüren, Knochen, Stiele = Reststoff bei Verarbeitung. Wareneinsatz schon im Mutter-Preis enthalten, kein doppelter Preis-Eintrag. Bei kommerzieller Großverwertung (z.B. Knochen-Großeinkauf für Fondküche) manueller Override möglich.

### Schema-Erweiterung `wawi_gp_v2`

```sql
ALTER TABLE wawi_gp_v2 ADD COLUMN is_derivat INTEGER DEFAULT 0;
ALTER TABLE wawi_gp_v2 ADD COLUMN derivat_von_gp_id INTEGER REFERENCES wawi_gp_v2(gp_v2_id);
ALTER TABLE wawi_gp_v2 ADD COLUMN derivat_typ TEXT;          -- Vokabular siehe unten
ALTER TABLE wawi_gp_v2 ADD COLUMN derivat_anteil_pct REAL;   -- 0-100, optional (z.B. Schale ~5%, Karkasse ~30%)
ALTER TABLE wawi_gp_v2 ADD COLUMN requires_la INTEGER DEFAULT 1;  -- Derivate: 0
```

### Vokabular `derivat_typ` (kontrolliert)

| derivat_typ | Beschreibung | Beispiele |
|---|---|---|
| `schale` | Ganze Schale inkl. Albedo | Gurkenschale, Orangenschale (für Öl), Kürbisschale |
| `zeste` | Oberste aromatische Schicht (= abrieb) | Zitronenzeste, Yuzu-Zeste, Mandarinenabrieb |
| `karkasse` | Ganzes Skelett mit Restfleisch | Geflügel-, Fisch-, Krustentier-, Hummer-Karkasse |
| `knochen` | Reine Knochen | Kalbsknochen, Wildknochen, Markknochen |
| `paruere` | Fleisch-Putzabschnitte (Sehnen, Fett, Knorpel) | Kalbsparüren, Rinderparüren, Lammparüren |
| `abschnitt` | Allgemeine Verarbeitungsreste | Gemüseabschnitte, Fleischabschnitte, Leberabschnitte |
| `stiel` | Kraut-/Pflanzenstiele | Petersilien-, Basilikum-, Brunnenkresse-, Majoranstiele |
| `strunk` | Pflanzen-Strunk | Blumenkohlstrunk |
| `gruen` | Kraut-Grün | Karottengrün, Selleriegrün |
| `saft` | Selbstgepresst (Default-Frisch) | Zitronensaft, Orangensaft, Mangosaft |
| `fett` | Ausgelassen aus Tier/Produkt | Hühnerfett, Speckfett, Schweinefett, Parmesanfett |
| `haut` | Tierische Haut | Hühnerhaut, Fischhaut |
| `innerer_kern` | Innenliegender Pflanzenteil | Zitronengras-Innerer Kern |

### Match-Logik (Pipeline 203/203b/203c)

1. **Derivat-Marker-Erkennung** im Recipe-Zutat-Text: regex-Patterns für `schale`, `karkasse`, `paruere`, `abschnitt`, `zeste`/`abrieb`, `stiel`, `saft`, `fett`, `knochen`, `haut`, `gruen`, `strunk`, `innerer kern`.
2. **Mutter-GP-Suche:** Hauptzutat (Wort vor Derivat-Marker) gegen `wawi_gp_v2` matchen — z.B. „Gurkenschale" → Mutter = `Gurke: frisch, ganz`.
3. **Derivat-GP-Lookup:** existiert `<Mutter>: frisch, Schale` mit `is_derivat=1`?
   - **Ja:** matchen mit `match_method='derivat_direct'`.
   - **Nein:** Mutter gefunden → neuen Derivat-GP `tentative` anlegen mit `derivat_von_gp_id=mutter_id`, `derivat_typ='schale'`, `requires_la=0`. Markieren in Review-Queue.
4. **Allergen-Aggregation (205):** Derivat-GPs werden ÜBERSPRUNGEN bei der LA-Aggregation. Allergen-Status wird ON-THE-FLY vom Mutter-GP gelesen (LIVE-Vererbung). Konsistenz: wenn Mutter-Allergene sich ändern, ändern sich Derivat-Allergene automatisch.
5. **LA-First-Pipeline:** Derivat-GPs sind `requires_la=0` → werden ignoriert bei Lieferanten-Matching. Saubere Trennung.

### Spezialregeln

- **Saft-Override:** Wenn Rezept explizit „Zitronensaft konserviert" / „Tetra" / „aus Glas" sagt → Industrie-GP (eigener Stamm-GP, kein Derivat). Default ohne Hinweis = frisch gepresst.
- **Fett-Disambiguierung:** „Hühnerfett" im Rezept = Default-Derivat (selbst ausgelassen). „Hühnerschmalz im Glas" → Industrie-GP. Marker-Heuristik in Pipeline.
- **Anti-Pattern:** Pinienkerne, Walnusskerne, Kürbiskerne = KEIN Derivat (eigener Stamm-GP, gekauft als Nüsse/Kerne). „Kerne" im Sinne von Frucht-Kernen (Apfelkerne, Kirschkerne) wäre Derivat, kommt aber im Kochbuch nicht vor.
- **Grün-Disambiguierung:** „Karottengrün" = Derivat von Karotte. „Grüner Spargel" = eigene Sorte (eigener GP). „Grüne Chili" = eigene Variante.

### Vorgehen Migration

1. Schema-ALTER laufen lassen (5 neue Spalten in `wawi_gp_v2`)
2. Vorschlags-Skript: scannt Kochbuch nach Derivat-Markern, generiert Vorschlagliste von ~50-80 neuen Derivat-GPs
3. User-Review: bestätigt oder verwirft die Vorschläge (Disambiguierung Mutter-GP)
4. Migration: angenommene Derivat-GPs werden als `tentative` angelegt mit `is_derivat=1`
5. Re-Run der Pipeline 203c mit Derivat-Match-Logik

> **Konsequenz für `Regelwerk_Grundprodukte.md`:** Die Derivat-Konzeption ist eine Erweiterung des GP-Regelwerks. Muss in `Regelwerk_Grundprodukte.md v3.2` als neuer §18 „Derivat-GPs" gespiegelt werden (Querverweis hier).

---

## Roadmap / Offene Ideen

### §12 (Idee 2026-06-02) — Neue Kategorie „Grundrezepte" (Technik-Basis / Standard-Ratios)

**User-Wunsch:** Eine eigene Kategorie für **Grundrezepte** = standardisierte kulinarische Basis-Verhältnisse/Techniken, nicht fertige Gerichte. Beispiele:
- **Gel (Standard):** 1,6 g Agar / 100 ml Flüssigkeit
- weitere: Gelatine-Ansatz (x Blatt / Flüssigkeit), Standard-Lake/Brine (z.B. 3 % Salz), Schaum-/Espuma-Basis, Vinaigrette-Ratio (3:1 Öl:Säure), Pâte-à-choux-Ratio, Beurre-blanc-Basis usw.

Charakteristik: **Ratio-basiert** (pro 100 ml/100 g), **wiederverwendbar** als Baustein in Kompositionen, eher *Technik-Template* als Portions-Gericht.

**Integrations-Ideen (zu klären):**
- **Eigener Recipe-Typ/Kategorie „Grundrezept (Technik-Basis)"** in der Recipe-Taxonomie (neben den 23 WaWi-Hauptgruppen / 139 Sub aus Skript 204). Andockpunkt existiert bereits: §1.5 nennt **„Technik-Basis"** als Klammer-Vokabular.
- **Ratio-Semantik:** als „pro 100 ml/100 g" definieren → skaliert über den bestehenden Sub-Rezept-Mechanismus (eine Komposition referenziert „Agar-Gel: Standard" + skaliert auf ihre Flüssigkeitsmenge). Offene Frage: Ratio-Skalierung vs. fixe Portion — braucht's ein neues `recipes.ist_ratio`/`basis_menge`-Feld?
- **Yield/EK:** tragen Grundrezepte EK/Yield (über die Zutaten-GPs) oder sind sie reine Referenz? Vermutlich beides nutzbar (Agar-GP hat Preis → Gel-EK rechenbar).
- **Abgrenzung** zu Domain-Wissen (07_WISSEN): Grundrezepte sind ausführbare Basisrezepte (03.02), nicht nur Doku.

**Status:** Idee/Backlog — eigener Build (Recipe-Kategorie + ggf. Ratio-Feld + Skalierungs-Semantik). Erst Konzept klären, dann umsetzen.

---

## Workflow für Beantwortung

In neuer Session:
1. Lies dieses Regelwerk + den aktuellen Stand in [project_recipe_db_migration.md](../../../13_MEMORY/project_recipe_db_migration.md).
2. Beantworte gezielt 5-10 Fragen pro Session (nicht alle auf einmal).
3. Antworten direkt unter die Frage als `**Antwort User <Datum>:** ...` eintragen.
4. Bei Antwort die GESETZT-Sektion entsprechend ergänzen.
5. Skript-Updates dokumentieren in Changelog.

---

## Changelog

- **2026-06-02 (v1.3)**: **§1.2 Typ-Vokabular finalisiert + Single-Source-Verankerung.** Das bislang als „Seed — User-Review" markierte Typ-Vokabular ist jetzt verbindlich und liegt als DB-Tabelle **`lookup_recipe_typ`** (Hauptgruppe → erlaubte Typen, 129 Typen über 22 Hauptgruppen, Migration 216) vor — analog zu `lookup_produkttyp` bei den GPs. Der Normalizer `ai_normalize_recipe_name` (commands.rs) injiziert für die Hauptgruppe des Rezepts genau diese Whitelist in `TASK_PROMPT_RECIPE_NAME` (Platzhalter `__TYP_WHITELIST__`, Helper `recipe_typ_whitelist`) statt einer hartkodierten Generik-Liste; VK-Pfad unberührt. §1.2-Tabelle an die DB angeglichen (u.a. Hollandaise/Béarnaise/Velouté/Béchamel bei Saucen, Aspik bei Gele, Bavarois/Soufflé/Schaum bei Mousses, Semifreddo bei Eis, Risotto/Polenta/Gnocchi bei Beilagen, Ferment/Glasur/Sirup ergänzt). Title Case mit Akzenten. Anlass: User „Naming-Syntax noch nicht stabil genug" → Vokabular festklopfen + maschinell erzwingen. Offen/Backlog: §3.3 Legacy-Migration der 1347 ALL-CAPS-Namen → `Typ: Bezeichnung` (eigener Gemini-Sprint).
- **2026-06-02 (v1.3, §1.5-Erweiterung)**: **Klammer-Vokabular um `(süß)`/`(würzig)` + verfeinerte vegan-Regel.** Beide beschreibenden Qualifier folgen jetzt der einheitlichen Regel „**nur als Diskriminator UND nur wenn nicht schon aus Typ/Bezeichnung evident**" (User 2026-06-02). `(süß)`/`(würzig)` (klein) nur wo die Richtung mehrdeutig ist (nicht bei Sorbet/Coulis = evident süß). `(vegan)` nur wenn `recipes.spec_is_vegan`=1 (deterministische Quelle, KEIN KI-Raten) UND der Typ normalerweise tierisch ist (Mayonnaise/Crème/Mousse/Bavarois/Eis/Wurst/…), nicht bei inhärent pflanzlichen Typen. Adjektiv-Qualifier kleingeschrieben, Versions-/Basis-Marker groß. `hell`/`dunkel` bewusst NICHT ins kontrollierte Vokabular (bleibt informell wie in §1.9-Fond-Splits) — User-Entscheidung. Normalizer (`ai_normalize_recipe_name`) bekommt VEGAN-STATUS aus `spec_is_vegan` in den Prompt + die erweiterte Variante-Regel. **`spec_is_vegan` aktuell bei 0 Rezepten gesetzt → vegan-Regel dormant** bis GP-Vegan-Tags + 206-Recompute das Flag befüllen; `(süß)`/`(würzig)` greifen sofort.
- **2026-05-22 (Runde 1)**: Beantwortet F1.2 (Kategorie-Diskriminator), F2.2 (TK→Tentative-frisch), F2.3 (Pfeffer gemahlen). Drei neue Direct-Overrides §5: Milch/Olivenöl/Zucker. F2.1 als Nachfrage offen (User: „kommt auf das Rezept an").
- **2026-05-22 (Runde 2)**: Beantwortet F2.1 (Convenience-Zukauf-Kriterium), F4.1 (Auto-Stub + status='draft'), F4.4 (GEKLÄRTE BUTTER als Sub-Rezept, gleich wie Nussbutter, neue Kat. `BUTTERZUBEREITUNG` Vorschlag), F6.3 (vocab_stk_default-Lookup mit Top-30 Vorbefüllung).
- **2026-05-22 (Runde 3)**: Beantwortet F3.1 (Tentative Boiron-Vorlage), F3.3 (Coulis≠Püree), F5.1-Rest (4 weitere Direct-Overrides: Gelatine, Crème fraîche, Sojasauce, Honig), F6.1 (Auto-Sum yield_kg ohne Verlust-Faktor).
- **2026-05-22 (Runde 4)**: Beantwortet F4.2 (Versionierung via Klammer-Suffix, entspricht bestehender Praxis), F4.3 (max. 3 Ebenen Rekursion), F7.1 (ungemappt → alle unbekannt + LOW), F7.2 (1 LA mit spuren reicht).
- **2026-05-22 (Runde 5)**: Beantwortet F7.3 (Stub = alles unbekannt), F8.3 (nüchtern-sachlich, 3-5 Sätze), F9.1 (eigenes SQL-Feld `recipes.notizen_manual`), F10.3 (status='draft' + Review-Queue).
- **2026-05-22 (Runde 6)**: Beantwortet F6.2 (Verlust aus GP-Tabelle), F8.4 (KI-Beschreibung auch für Stubs als tentative), F8.5 (KI entscheidet immer, Bias ganzjährig), F9.2 (manuell on-demand Sync).
- **2026-05-22 (Runde 7 — FINAL)**: Beantwortet alle verbleibenden Detail-Fragen: F1.1 (Splits via Corrections-MD, Geflügelfond = Default-Main), F3.2 (Apfelmark als GP), F5.2 (Olivenöl generisch nativ extra), F5.3 (Sahne einheitlich 30%), F5.4 (kein Premium-Pfeffer), F6.4 (Mittelwert bei Bereich), F8.1 (Aroma-Tabelle anlegen leer), F8.2 (3-7 Pairing-Anker adaptive), F10.1 (Multi-Zutat splitten + Gemini-Mengenschätzung), F10.2 (Klammer als note). **Status auf v1.0 VERBINDLICH gesetzt.**
- **2026-05-22 (Runde 8 — §11 Derivate)**: User-Frage zu „leeren GPs" wie Fleisch-Abschnitten / Gurkenschale. Excel-Scan zeigt **~500 Derivat-Vorkommen** im Kochbuch (1 alle 2.7 Rezepte). Neue **§11 Derivat-GPs** ergänzt: Hybrid-Modell mit `is_derivat=1` + `derivat_von_gp_id` + `requires_la=0` + LIVE-Allergen-Vererbung vom Mutter. F11.1 (Saft frisch=Derivat-Default), F11.2 (schale ≠ zeste, eigene Vokabular-Werte), F11.3 (Preis=NULL Abfall-Verwertung). Konsequenz: `Regelwerk_Grundprodukte.md` muss als v3.2 erweitert werden um neuen §18.
- **2026-05-27 (v1.2 — §1 Naming-Syntax formalisiert)**: User-Wunsch, §1 von rein deskriptiv (Name = Excel-Spalte A) auf eine **präskriptive Syntax** wie GP §6 zu heben. Kern-Entscheidung (User): **universelles Schema `Typ: Bezeichnung`** — Typ als Kopf-Nomen (≈ Hauptgruppe), Bezeichnung = Leitkomponente ODER kulinarischer Eigenname. Beispiel `Schaumsauce: Beurre Blanc`. Kein Zwei-Pattern-Entscheidungsbaum (User wählte explizit „Typ:Komponente für alles"). Weitere Festlegungen: **Title Case** statt ALL-CAPS für neue Rezepte (§1.4); **Herkunft/Quelle NICHT im Namen → Feld** (§1.6, Schema-TODO `recipes.herkunft`); Abgrenzung Variante-Klammer `(Klassisch)` vs. Herkunft-Feld `Broich`; max 2 Leitaromen bindestrich-gekoppelt (§1.3); Typ-Vokabular als kontrollierte Seed-Liste pro Hauptgruppe (§1.2, User-Review offen). Legacy-1369-ALL-CAPS-Migration = eigener Backlog-Sprint. **Nächster Schritt:** Namens-KI-Prompt `TASK_PROMPT_RECIPE_NAME` (commands.rs) + Feld-Hülle `recipes.name` auf diese Syntax nachziehen.
  - **Verfeinerung gleiche Session:** Sparring zur GP-§6-Parallele. Bestätigt: **Typ-Präfix bleibt im Namen** (Front = kanonischer Singular der Kategorie) — Begründung: Rezeptname „reist allein" in Foodbook/Links/Export/Suche, wo die Kategorie-Spalte fehlt; `Sellerie` allein ist mehrdeutig (Püree/Schaum/Sud?). §1.5 zu kontrolliertem **Klammer-Vokabular** ausgebaut (Version / Technik-Basis / Diätform-Variante / Zustand / Dubletten-Zähler). **Kernprinzip:** Klammer trägt nur, was Rezepte *unterscheidet* — berechnete Diät-Tags + Einheit (kg/Stk) bleiben Felder/Pills, NICHT im Namen (User: „nur bei echter Variante"). Divergenz-Schutz: `(Vegan)`-Klammer gegen Allergen-Aggregation prüfen.