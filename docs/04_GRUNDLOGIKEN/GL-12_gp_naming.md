---
typ: Grundlogik-Spec
gl_id: GL-12
stand: 2026-06-10
status: ausgearbeitet
quellen_normativ: "regelwerke/Regelwerk_Grundprodukte.md §5–§9, §12, §13, §19 (eingefroren 2026-06-10, v3.4)"
quellen_ist: "cooking-jarvis/src-tauri/src/gp_naming.rs + commands.rs (slugify, build_gp_key_from_parts) + recipe_matching.rs (normalize_slug)"
---

# GL-12 — GP-Naming & Slug-Normalisierung

## 1. Zweck & Quellen

Diese Grundlogik definiert, **wie ein Grundprodukt-Name (`gp_name`) deterministisch aus strukturierten Feldern gerendert, validiert und in Slugs/Keys normalisiert wird**. Sie ist die Naming-Wahrheit für GP-Anlage (manuell + KI-Suggest), Dubletten-Erkennung (`gp_key`) und alle Matcher, die über `hauptzutat_slug` arbeiten (GL-04, GL-05, GL-06).

**Normativ** (Regelwerk schlägt Code):
- `Regelwerk_Grundprodukte.md` **§5** Markenregel + 4-Punkt-Tiebreaker, **§6** Benennungsschema (inkl. §6.1 Singular/Lemma, §6.2 Doppelnamen, §6.3 Fremdwörter), **§7** Grammatur (inkl. §7.1 Verpackungs-Verbot, §7.2 10-%-Schwelle), **§8** produktspezifische Pflichtangaben, **§9** Pflicht-Vokabular, **§12** Anti-Patterns, **§13** Entscheidungsbaum, **§19** Beispiele (verbindlich!).

**Ist-Implementierung** (Rust):
- `gp_naming.rs`: `render_gp_name` (Z. 31), `validate_gp_name` (Z. 88), `ZUSTAND_VOCAB` (Z. 14), `VERPACKUNGSWOERTER` (Z. 17) + Unit-Tests (Z. 152 ff.).
- `commands.rs`: `slugify` (Z. 2471), `build_gp_key_from_parts` (Z. 2496, Schema `hauptzutat|verarbeitung|form`), Anlage-Guard in `create_gp` (Z. 2509: gp_key-Eindeutigkeit + Jaccard ≥ 0.92 Hard-Stop).
- `recipe_matching.rs`: `normalize_slug` (Z. 203) — zweite, abweichende Slug-Variante für Matching/Pairing (siehe Abweichung A3).
- `stemming.rs`: `stem_german` — zentrale Singular/Plural-Konvergenz für alle Matcher (§6.1 Match-Semantik).

## 2. Eingaben / Ausgaben / Invarianten

**Ziel-Tabelle:** `foodalchemist_gps` (aus `02_DATENMODELL.md` A.2).

**Eingabe — strukturierte Felder** (Render-Input; Quelle: KI-Suggest oder Editor-Formular):

| Feld | Pflicht | Beispiel |
|---|---|---|
| `hauptzutat` (Display, inkl. ggf. Marke/Sorte) | ja | `Vollmilch`, `Mangopueree Boiron`, `Kartoffel Linda` |
| `zustand` | ja (§9-Vokabular, DB-CHECK) | `frisch` / `TK` / `trocken` / `konserviert` |
| `verarbeitung` | optional (gewinnt vor `form`) | `pasteurisiert`, `Wuerfel 5 mm` |
| `form` | optional | `ganz`, `Filet`, `Pueree`, `Granulat` |
| `portion` | bedingt (§7: Pflicht bei Portion) | `180 g` |
| `pflichtangabe` | bedingt (§8-Matrix) | `3,5 %`, `festkochend`, `Type 405` |
| `bio`, `vegan`, `glutenfrei`, `laktosefrei` | bool | Klammer-Suffixe |

**Ausgaben:**
- `gp_name` (Display) — gerendert nach §6-Schema: `<Hauptzutat>: <zustand>, <verarbeitung|form>[, Portion <x> pro Stueck][, <pflichtangabe>][ / (Bio)][ / (Vegan)][ / (Glutenfrei)][ / (Laktosefrei)]`
- `hauptzutat_slug` = `slugify(hauptzutat)`
- `gp_key` = `hauptzutat_slug | slugify(verarbeitung ?? '') | slugify(form ?? '')` — **immer 3 Slots**, auch leere (Tomatenpulver-Dubletten-Bug 2026-05-26: 2-teiliger Key erzeugte Duplikate)
- Validierungs-Ergebnis: `errors[]` (Hard-Reject) + `warnings[]` (informativ)

**Invarianten:**
- **I1:** `gp_key` ist UNIQUE über aktive GPs (approved/tentative) — Anlage-Guard blockt Duplikate (Hard-Stop, `force`-Override nur bewusst durch User).
- **I2:** §7.1 ist ein **Hard-Error**: Verpackungswörter (`Kiste`, `Karton`, `Beutel`, `Pkt`, `Btl`, `Geb`, `Tasse`, `Dose`*, `Glas`*, `Stange`, `Atmospack`, `Vac`, `Bund`, `Gebinde`) dürfen NIE im `gp_name` stehen. (*Wort-Boundary-Match: „Dose" blockt, „Dosentomate" nicht.)
- **I3:** §9-Zustand ist ein **Hard-Error** bei Vokabular-Verstoß (auch als DB-CHECK/Enum).
- **I4:** `gp_name` ist im Ziel **computed/abgeleitet** aus den strukturierten Feldern (Render-First). User-Edit am Namen ist erlaubt, erzeugt aber eine Drift-Warning; Hard-Errors blocken den Insert.
- **I5:** Singular/Lemma-Form (§6.1) — mit exakt zwei Ausnahme-Klassen (Tabelle C). Grenzfälle werden geflaggt und vom Owner entschieden, nie ad-hoc geraten.
- **I6:** Die Slug-Funktion für `gp_key` muss **byte-identisch zur Rust-`slugify`** portiert werden — sonst kollidieren neu errechnete Keys mit den 7.774 Seed-GPs (Dubletten-Erkennung bricht). Kein `Str::slug()` von Laravel verwenden (andere Transliteration!).
- **I7:** Matching ist richtungsneutral plural-tolerant: Vergleiche laufen über den Stemmer (`stem_german`), nie über String-Gleichheit der Display-Namen.

## 3. Pseudocode (erklärend)

```text
function renderGpName(in):                        # gp_naming.rs Z. 31
    parts = []
    if in.zustand:       parts.push(in.zustand)
    mid = in.verarbeitung ?? in.form              # verarbeitung gewinnt (spezifischer)
    if mid:              parts.push(mid)
    if in.portion:       parts.push("Portion " + in.portion + " pro Stueck")
    if in.pflichtangabe: parts.push(in.pflichtangabe)
    base = parts.isEmpty() ? in.hauptzutat : in.hauptzutat + ": " + parts.join(", ")
    suffixes = [bio→"(Bio)", vegan→"(Vegan)", glutenfrei→"(Glutenfrei)", laktosefrei→"(Laktosefrei)"]
    return suffixes.isEmpty() ? base : base + " / " + suffixes.join(" / ")

function validateGpName(name, in):                # gp_naming.rs Z. 88
    errors = []; warnings = []
    if name.trim().isEmpty():                       errors  += "leer"
    for w in VERPACKUNGSWOERTER:                                          # §7.1, I2
        if wordBoundaryContains(name, w):           errors  += "§7.1: " + w
    if in.zustand not in [frisch, TK, trocken, konserviert]:              # §9, I3
                                                    errors  += "§9: Zustand"
    if normalize(name) != normalize(renderGpName(in)):                    # Drift, I4
                                                    warnings += "Drift Render↔Name"
    return {errors, warnings}

function slugify(s):                              # commands.rs Z. 2471 — KANONISCH für gp_key
    # lowercase; ä→a, ö→o, ü→u, ß→s (EIN Zeichen!); alphanumerisch bleibt
    # (Unicode-aware: é/è/ç bleiben erhalten!); alles andere → '_';
    # Mehrfach-'_' kollabieren, führende/folgende '_' weg
    "Wuerfel 5 mm" → "wuerfel_5_mm";  "Grüne Bohnen" → "grune_bohnen"

function buildGpKey(hauptzutatSlug, verarbeitung, form):    # commands.rs Z. 2496
    return hauptzutatSlug + "|" + slugify(verarbeitung ?? "") + "|" + slugify(form ?? "")
    # z.B. "apfel|wuerfel_5_mm|"  — leere Slots bleiben als Leerstring im Key

function normalizeSlug(s):                        # recipe_matching.rs Z. 203 — NUR Matcher-Pfad
    # lowercase; ä→ae, ö→oe, ü→ue, ß→ss (ZWEI Zeichen!); '-'→'_';
    # alphanumerisch + '_' bleibt; ALLES andere (auch Space) wird GELÖSCHT
    # ⚠ andere Transliteration als slugify — siehe Abweichung A3

function stemSlug(slug):                          # §6.1 Match-Semantik (richtungsneutral)
    return slug.split("_").map(stemGerman).join("_")
    # "tomate"/"tomaten" → "tomat";  Umlaut-Plurale (Walnüsse↔Walnuss) via kuratierten Lookup
```

## 4. Entscheidungstabellen (normativ)

### Tabelle A — §6-Benennungsschema (Feld-Reihenfolge)

`Produktname [Marke]: Eigenschaft(=Zustand), [Herkunft], Zustand/Zuschnitt(=Verarbeitung/Form), [Grammatur] / ([Dosierung], [Zusatz], [Bio])`

Trennzeichen: `:` nach Produktname · `,` zwischen Eigenschaften · `/` leitet Klammer-Block ein · `()` umschließt Zusätze. Kein Untergruppen-Präfix im Namen (Warengruppe ist Feld, nicht Namensbestandteil).

### Tabelle B — §8 Pflichtangaben-Matrix (fehlende Pflichtangabe ⇒ `needs_review`, vgl. GL-05 Tabelle E)

| § | Kategorie | Pflichtangabe |
|---|---|---|
| 8.1 | Milch & Milchprodukte | Fettstufe (`Milch 3,5 %`, `Sahne 30 %`) |
| 8.2 | Eier | Größe (`M`, `L`, `XL`) |
| 8.3 | Fleisch | Grammatur bei Portion + Zuschnitt-Maß |
| 8.4 | Fisch | Grammatur bei Filet/Portion + Garnelen-Kaliber (`16/20`, `21/25`) |
| 8.5 | Backwaren | Stückgewicht (`Broetchen: 70 g pro Stueck`) |
| 8.6 | Getränke | Gebinde + Liter; **Vol-%** bei Spirituosen; **Rebsorte/Region/Jahrgang** bei Wein; **Röstgrad + Sorte (Arabica/Robusta)** bei Kaffee |
| 8.7 | Allgemein | produktspezifische Pflichtangaben überschreiben generische Regeln |
| 8.8 | Käse | Reifegrad (jung/gereift/lange gereift) + Festigkeit (Hart/Schnitt/Halbfest/Weich/Frisch) + Pasteurisierung (Rohmilch/pasteurisiert) |
| 8.9 | Reis | Sorte (Basmati/Jasmin/Arborio/Sushi/Wildreis/Vollkorn) |
| 8.10 | Schokolade | Kakaoanteil + Couverture-Status (Couverture/Tafel/Kakaomasse/Kakaobutter) |
| 8.11 | Mehl | Type (405/550/1050/00/Vollkorn) |
| 8.12 | Kartoffeln | Kochtyp Pflicht: mehlig / vorwiegend festkochend / festkochend (NICHT bei Süßkartoffel/Topinambur — andere Pflanzenart). Sorte wenn bekannt (Linda, Belana, Agria, …). Kochtyp nicht ableitbar ⇒ `needs_review`, Grund `kochtyp_pflicht` |

### Tabelle C — §6.1 Singular-Pflicht + die 2 Plural-Ausnahme-Klassen

Grundregel: **immer Singular/Lemma** (`Zwiebel`, `Kartoffel`, `Moehre`, `Tomate`, `Rinderfilet`, `Dattel`, `Garnele`, `Apfel`). Gilt seit v3.3 (ersetzt die frühere Plural-Pflicht — User-Entscheid 2026-05-29).

| Klasse | Regel | Geschlossene Liste |
|---|---|---|
| **(a) Pluralia tantum / Lehnwörter** | kein brauchbarer deutscher Singular | `Spaghetti`, `Penne`, `Tagliatelle`, `Gnocchi`, `Tortellini`, `Antipasti`, `Cornflakes`, `Pommes frites` |
| **(b) Sammelware** (Schüttgut/Kollektiv; §8-Angaben hängen an der Charge, nicht am Stück) — **genau 4 Gruppen**, User-Entscheid 2026-05-29 | **Eier** (alle Eier-GPs) · **Hülsenfrüchte** (`Linsen`, `Kichererbsen`, getrocknete `Bohnen`/`Erbsen`) · **Nüsse & Kerne** (`Mandeln`, `Walnuesse`/`Walnusskerne`, `Haselnuesse`, `Pinienkerne`, `Cashewkerne`, `Pistazien`, `Sonnenblumenkerne`, `Kuerbiskerne`) · **Beeren** (`Erdbeeren`, `Himbeeren`, `Heidelbeeren`, `Brombeeren`, `Johannisbeeren`, `Cranberries`) | s. links |

Echte Stückware bleibt Singular — auch gastro-typisch plural gehandelt (`Garnele`, `Jakobsmuschel`, `Miesmuschel`, `Dattel`, `Aprikose`). Grenzfälle: flaggen + Owner-Entscheid, Liste erweitern — nicht raten.

### Tabelle D — §5 Marken-4-Punkt-Tiebreaker (Marke in den GP-Namen, sobald ≥ 1 zutrifft)

| # | Kriterium | Beispiel |
|---|---|---|
| 1 | **Sensorik** — Geschmacks-/Texturdifferenz für den Endgast spürbar | `Mangopueree Boiron: TK, Pueree` |
| 2 | **Preis** — Preisunterschied zu Alternativen > 20 % | — |
| 3 | **Kundenpräferenz** — vertraglich/als Präferenz festgelegt | `San_Marzano_Tomaten Mutti: Konserve, ganz` |
| 4 | **Lebensmittelchemie** — chemisch/aromatisch unterscheidbar | `Cointreau: fluessig, 40 vol-%` vs. `Triple Sec generisch` |

Trifft keiner ⇒ generisches GP, Marke bleibt Attribut des Lieferantenartikels (§10, §13). Anti-Pattern: `A1_Steak_Sauce` als GP-Name ⇒ generisches GP `Steaksauce`.

### Tabelle E — §9 Pflicht-Vokabular (werden Enums/Lookup)

| Slot | Werte |
|---|---|
| **Zustand** (CHECK) | `frisch`, `tiefgekuehlt` (Kurzform **TK**, bevorzugt), `trocken`, `konserviert` |
| **Verarbeitung** | `roh, gegart, blanchiert, sous-vide, mariniert, fermentiert, geraeuchert, eingelegt, gepoekelt, pasteurisiert, konzentriert, pulverfoermig, fluessig, luftgetrocknet, geschnitten, Wuerfel <mm>, Brunoise <mm>, Streifen <mm>, Scheiben <mm>, geraspelt, gerieben, pueriert, gehackt, filetiert, Mittelstueck, geschaelt, entkernt, halbiert, geviertelt` |
| **Form** | `Ganz, Filet, Portion, Pueree, Pulver, Granulat` — `Portion` NUR bei Stück-Grammatur (§7), nie für Schüttgut; `Granulat` = körnig/kross, `Pulver` = fein/staubfein |
| **Zusatz Diät/Religion** | `vegan, vegetarisch, halal, koscher, glutenfrei, laktosefrei` |
| **Zusatz Nachhaltigkeit/Qualität** | `Bio, Demeter, Bioland, Naturland, MSC, ASC, Dry Aged <Tage>, Sashimi-Qualitaet, ohne Zusatzstoffe, Convenience` |

Neue Begriffe ⇒ Owner-Entscheid (§18), kein stilles Erweitern.

### Tabelle F — §13 Entscheidungsbaum (GP ja/nein) — Kurzform

| Frage | Antwort | Ergebnis |
|---|---|---|
| Einzelner, kaufbarer Rohstoff? | ja | GP; bei Markenname → Tabelle D prüfen |
| Verarbeitet (karamellisiert, in Sauce mariniert, …)? | ja, UND Säure/Salz/Fermentation verändert den Charakter (§11.1) | eigener GP (Derivat, `is_derivat=0`) |
| | ja, ABER Charakter unverändert | Basisrezept mit GP-Zutaten — KEIN GP |
| Küchen-Nebenprodukt (Schale, Saft, Parüren, Karkasse)? | ja (§11.2) | Derivat-GP `is_derivat=1` + `derivat_von_gp_id`, `requires_la=0` |
| Zubereitungsanweisung („doppelter Espresso", „Saft von 3 Zitronen")? | ja | gehört ins Rezept, NIE GP |

### Tabelle G — Slug-Funktionen (Ist, beide im Ziel nachbauen)

| Funktion | Zweck | Transformation | ä/ö/ü/ß | Space | `-` | Sonstiges |
|---|---|---|---|---|---|---|
| `slugify` (commands.rs:2471) | `gp_key`, `hauptzutat_slug` — **kanonisch, byte-identisch portieren (I6)** | lowercase | →`a`/`o`/`u`/`s` (1 Zeichen) | →`_` | →`_` | non-alphanumerisch →`_`, Mehrfach-`_` kollabiert, Ränder getrimmt; Unicode-Buchstaben (é, è) bleiben |
| `normalize_slug` (recipe_matching.rs:203) | Matcher-/Pairing-Vergleiche | lowercase | →`ae`/`oe`/`ue`/`ss` (2 Zeichen) | **gelöscht** | →`_` | non-alphanumerisch gelöscht |

## 5. Golden-Testfälle (verbindliche Wahrheit; Testfall > Entscheidungstabelle > Pseudocode)

GT-12-01…04 stammen aus den Rust-Unit-Tests (`gp_naming.rs` Z. 152 ff.), GT-12-05…10 aus den verbindlichen §19-Beispielen, GT-12-11…14 sind Negativ-Fälle aus §12-Anti-Patterns.

| # | Input | Expected |
|---|---|---|
| GT-12-01 | render: `hauptzutat="Vollmilch"`, alle anderen Felder leer | `"Vollmilch"` (kein Doppelpunkt ohne Attribute) |
| GT-12-02 | render: `Vollmilch` + zustand `frisch` + verarbeitung `pasteurisiert` + pflichtangabe `3,5 %` + bio | `"Vollmilch: frisch, pasteurisiert, 3,5 % / (Bio)"` (§8.1 + Bio-Suffix) |
| GT-12-03 | validate: Name `"Tomaten Kiste: frisch"` | Hard-Error `§7.1` (Verpackungswort `Kiste`) — Insert geblockt |
| GT-12-04 | validate: zustand `"tiefgekuehlt"` (Langform) | Ist-Code: Hard-Error `§9` (Vokabular nur `TK`). **Ziel: Eingangs-Normalisierung `tiefgekuehlt`→`TK`, DANN valid** (Abweichung A2) |
| GT-12-05 | §19: Kartoffel, festkochend, frisch, ganz, Sorte Linda | `"Kartoffel Linda: festkochend, frisch, ganz"` — Sorte ist Namensbestandteil; §8.12 Kochtyp Pflicht; `Suesskartoffel: frisch, ganz` dagegen OHNE Kochtyp |
| GT-12-06 | §19: Eier, frisch, Größe M | `"Eier: frisch, Groesse M"` — Plural-Ausnahme §6.1b (Sammelware Eier); `"Ei"` wäre falsch (§12) |
| GT-12-07 | §19: Spaghetti, trocken, lang | `"Spaghetti: trocken, lang"` — Plural-Ausnahme §6.1a (Lehnwort); `"Garnele / Shrimp: TK, geschaelt, 16/20"` dagegen Singular + Doppelname (§6.2: max. 2 Namen, gängigerer zuerst) + Kaliber (§8.4) |
| GT-12-08 | §19: Mangopüree Boiron, TK, Püree | `"Mangopueree Boiron: TK, Pueree"` — Marke Pflicht (§5 Pkt. 1 Sensorik); generischer Triple Sec bleibt OHNE Marke: `"Triple Sec generisch: fluessig, 30 vol-%"` (§8.6 Vol-%) |
| GT-12-09 | slug/key: `slugify("Wuerfel 5 mm")`; `buildGpKey("apfel", "Wuerfel 5 mm", null)` | `"wuerfel_5_mm"`; `"apfel|wuerfel_5_mm|"` (3 Slots, letzter leer). Realer Seed-Beleg: GP 30 `"Aepfel: frisch, Wuerfel 5 mm, geschaelt"` |
| GT-12-10 | Anlage-Guard: zweites GP mit identischem `gp_key` (z. B. nochmal `tomate|pulverfoermig|`) | Hard-Stop `HARD_STOP_EXISTING_GP` („existiert: X — verwenden/trotzdem anlegen"); nur `force=true` legt trotzdem an (Tomatenpulver-Bug-Regression) |
| GT-12-11 | §12 negativ: `"Zwiebel_fein_gewuerfelt"` | abgelehnt (Zuschnitt im Namen) → korrekt: `"Zwiebel: frisch, Wuerfel 5 mm"` |
| GT-12-12 | §12 negativ: `"Saft_von_3_Zitronen"` | abgelehnt (Verarbeitungsanweisung) → korrekt: `"Zitrone: frisch, Saft"` als Derivat (`is_derivat=1`, `derivat_von_gp_id`→Zitrone, §11.2) |
| GT-12-13 | §12 negativ: `"getr._Aprikosen"` | abgelehnt (Abkürzung + Plural bei Stückware) → korrekt: `"Aprikose: getrocknet, ganz"` |
| GT-12-14 | §12 negativ: `"karamellisierte_Mandeln"` als GP | abgelehnt — Verarbeitung = Basisrezept; GP ist `"Mandeln: trocken, ganz"` (Plural OK: Sammelware §6.1b) |
| GT-12-15 | Match-Semantik: Zutat-String `"Tomaten"` vs. GP-Slug `tomate` | `stemGerman` konvergiert beide auf `tomat` ⇒ Match; ebenso `walnuesse`↔`walnuss` (Umlaut-Lookup). ABER `butter` matcht NICHT `butternut` (Längen-Differenz-Schutz) |

## 6. Offene Weichen + Verbesserungen

**Abweichungen Regelwerk ↔ Ist-Code** (Regelwerk gewinnt):

| # | Abweichung | Ziel-Entscheid |
|---|---|---|
| A1 | `render_gp_name` rendert nur eine **Teilmenge des §6-Schemas**: kein eigener Marke-/Herkunft-/Dosierungs-Slot (Marke/Sorte stecken heute im `hauptzutat`-Display, Herkunft fehlt ganz); Suffix-Reihenfolge fix Bio→Vegan→Glutenfrei→Laktosefrei | Ziel übernimmt die Ist-Slots 1:1 (Seed-Kompatibilität), führt `herkunft` + `dosierung` als optionale Render-Slots nach §6 ein. Marke bleibt im Hauptzutat-Display (kein eigenes Feld) — bewusste Vereinfachung, dokumentiert |
| A2 | §9 erlaubt `tiefgekuehlt` UND `TK`; Ist-Code (`ZUSTAND_VOCAB` + DB-CHECK) akzeptiert NUR `TK` — Langform wird hart abgelehnt (Rust-Test!) | Ziel: Eingangs-Normalisierung `tiefgekuehlt`→`TK` im Service (Regelwerk-konform), gespeichert wird kanonisch `TK` |
| A3 | **Zwei divergierende Slug-Funktionen**: `slugify` (ä→`a`) für `gp_key` vs. `normalize_slug` (ä→`ae`) für Matcher — historisch gewachsen | Ziel behält BEIDE getrennt (Tabelle G): `gp_key`-Slug muss byte-identisch bleiben (I6, Seed!), Matcher-Slug ebenso (Pairing-Daten `gp_anker_mapping` nutzen ihn). Vereinheitlichung = eigenes Refactoring NACH Migration, nie währenddessen |
| A4 | **Seed-Datenbestand verletzt §6.1 teilweise**: GP-Namen wie `"Aepfel: frisch, ganz, geschaelt"`, `"Kartoffeln: …"` stehen noch im Plural (Singular-Backfill seit v3.3 unvollständig) | Matching bleibt davon unberührt (Stemmer, I7). Namens-Backfill = Daten-Hygiene-Task vor/nach Seed (mit V-03-Rezeptnamen bündeln), NICHT Teil dieser GL-Logik |
| A5 | §6.2-Beispiele im Regelwerk stehen noch in Plural-Schreibweise (`"Garnelen / Shrimps"`) — Redaktionsstand vor v3.3; §19 zeigt korrekt `"Garnele / Shrimp"` | §6.1 + §19 gewinnen: Doppelnamen folgen ebenfalls der Singular-Regel |
| A6 | §6.1-Singular-Normalisierung ist im Render bewusst NICHT implementiert (nur Stemmer im Matcher) | bleibt so: Singular ist Eingabe-Disziplin (KI-Prompt + Editor-Validierungswarnung), keine automatische Lemmatisierung im Render (deutsche Lemmatisierung zu fehleranfällig) |
| A7 *(Nachtrag Verifikation 2026-06-11)* | **Zweites, laxeres zustand-Vokabular** im Ist: `commands.rs:1468` (`ZUSTAENDE`, enthält zusätzlich `tiefgekuehlt`) — `validate_gp_input` (Z. 2446) ließe es durch, erst der DB-CHECK blockt. Zwei inkonsistente Validatoren | Ziel: **EIN** kanonisches Vokabular (`ZUSTAND_VOCAB` = §9 + DB-Enum), Eingangs-Normalisierung `tiefgekuehlt`→`TK` (A2) davor — kein zweites Validator-Set |
| A8 *(Nachtrag Verifikation 2026-06-11)* | **Regelwerk-internes Erratum**: §5 führt das Boiron-Beispiel noch als „Mangopueree Boiron: **tiefgekuehlt**, Pueree" (Alt-Form), §19 korrekt als „TK". Außerdem (A3-Ergänzung): im Ist existieren neben den zwei Haupt-Slug-Funktionen noch zwei weitere Varianten (`commands.rs:15741` Pairing-`normalize_slug` ä→a + ae→a; `commands.rs:22709` `slugify_for_key` ä→ae) | §19 gewinnt (verbindlich). Erratum bei Regelwerk-Revision v3.3.3 in der Vault-Quelle fixen, dann Freeze erneuern. Slug-Port: nur die zwei Haupt-Funktionen (Tabelle G) sind Ziel-relevant; die Pairing-Variante wird durch GL-10-Slug-Toleranz ersetzt, `slugify_for_key` konsolidiert auf den Matcher-Slug |

**Offene Weichen** (Anker `08_ENTSCHEIDUNGEN.md` / `10_VERBESSERUNGS_REGISTER.md`):

- **D1:** GP-Welt global vs. team-scoped — bestimmt, ob `gp_key`-UNIQUE global oder pro Team gilt (bei team-scoped: UNIQUE auf `(team_id, gp_key)`).
- **V-20 (Vokabular-CRUD):** §9-Vokabulare als Admin-pflegbare Lookups statt hartem Enum — Owner-Entscheid-Workflow (§18) abbilden.
- **§6.1-Grenzfall-Liste:** geschlossene Sammelware-Liste (Tabelle C) braucht im Ziel einen Pflege-Ort (Lookup-Tabelle + Flag-Workflow im GP-Preview) statt Hardcode.
- **Validator-Schärfe:** Plural-Warnings (§6.1) sind heute nur angekündigt, nicht implementiert — Ziel: Warning (nicht Error) wenn `hauptzutat` nach Stemmer-Reduktion um typische Plural-Endung länger ist als ein existierender Singular-GP-Slug.
