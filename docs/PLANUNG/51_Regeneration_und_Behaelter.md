# Spec 51 — Regeneration & Behälter

> **Tracking:** Office Dev-Package 23, Features-Board. Architektur-Spec mit Migration.
> **Revidiert bewusst** [Regelwerk_Verkaufsgerichte §3.2/§3.4](../../../../07_WISSEN/07.01_Lebensmittel_und_Gastronomie/Regelwerke/Regelwerk_Verkaufsgerichte.md)
> (beschlossen 2026-09-04): Regeneration wandert vom Gericht an die Komponente, der Behälter wird
> gerechnet statt getippt, und es gibt Behälter **je Zweck** statt zwei Skalar-Spalten.

**Status:** `gebaut` (2026-09-04) auf `feat/spec51-regeneration-behaelter` — Etappen A–H umgesetzt
und getestet, Regelwerke nachgezogen (VK v1.8 §3.2a/§3.4/§3.4e, Basisrezepte v1.8 §14, neu
`Cross_Cutting/Behaelter_und_Gastronorm.md`).
**Offen:** der Entscheid aus §5 Nr. 1 (Drop der `recipe_presentations.regeneration_*`-Spalten) —
gelesen werden sie bereits nicht mehr, die Spalten stehen unangetastet. Volle Suite und
Browser-Abnahme stehen aus; nicht deployt.
Statuswerte (aus [README](../README.md)): `gebaut` · `getestet` (Sandbox) · `demo-geprüft` · `abgenommen`.

Alle Codepfade relativ zu `platforms-foodalchemist/` (canonical Clone).
**Zeilennummern sind Wegweiser** — die Dateien bewegen sich täglich; im Zweifel über den Symbolnamen
suchen, nicht über die Zeile.

---

## Anlass

Am 2026-09-04 wurde die Drei-Ebenen-Logik am Gericht eingeführt: **Regeneration · Fertigstellen ·
Anrichten**. Die Regeneration liegt strukturiert in `foodalchemist_recipe_regenerations` — eine Zeile
je Komponente, am **Gericht**. Beim ersten realen Befüllen (Dominique, Gericht mit fünf Komponenten)
fallen drei Strukturfehler auf. Alle drei sind im Code belegt.

### Fehler 1 — Die Regeneration steht an der falschen Ebene

Wie eine Komponente regeneriert wird, ist eine Eigenschaft *der Komponente*, nicht des Tellers.
„Pickles: Buchenpilze Süß-Sauer" wird in jedem Gericht kalt serviert. Heute muss die Zeile in jedem
Gericht neu getippt werden — n-fache Pflege, garantierte Drift. Genau das Anti-Pattern, das §3 an
anderer Stelle selbst benennt: „steht dieselbe Angabe zweimal im System … und beide driften."

### Fehler 2 — Der Behälter ist ein Etikett statt einer Rechnung

`container_warm_count` ist ein handgetipptes Integer am Gericht. Es kennt die Menge nicht. Die Zahl,
die es bräuchte, entsteht eine Ebene tiefer: `PlanungsblattService::explodiere()` erzeugt eine Zeile
je Rezept mit `produzierte_menge_kg`.

> **Beweis der Naht:** Die Masse entsteht an der Rezept-Zeile des Produktionsauftrags, die
> Behälter-Angabe hängt am Gericht. Diese beiden Objekte treffen sich nie — deshalb *kann* das
> System heute keinen Behälterbedarf ausrechnen, egal wie gut jemand die Felder pflegt.

`foodalchemist_vocab_containers.kapazitaet_kg` existiert seit Juni: 17 Fundstellen im Repo, **0** in
`src/Services`, `src/Tools`, `config`. Reines Anzeigedatum.

### Fehler 3 — Drei Ablagen für dieselbe Sache

| Ablage | Felder |
|---|---|
| `foodalchemist_recipes` | `container_warm_vocab_id/_count`, `container_cold_*`, `serving_vehicle_vocab_id` + tote Legacy-`regeneration_*` |
| `foodalchemist_recipe_presentations` | dieselben Behälter-FKs (**ohne** `_count`) **plus** `regeneration_temp_c/_duration_min/_core_temp_c/_device_vocab_id` |
| `foodalchemist_recipe_regenerations` | die V-19-Zeilenliste je Komponente |

`SalesRecipeService::syncStandardDarreichung()` spiegelt sechs Felder, die `regeneration_*` **nicht**.
Deshalb kam `regen_snapshot` an der Auftragszeile als *dritter* Weg dazu — und
`PlanungsblattService::darreichungsInfo()` liest gleichzeitig weiter die Darreichungs-Skalare. Am
Wandmonitor stehen damit zwei Regenerationsquellen, die sich bei WaWi-Import-Gerichten widersprechen
können.

---

## Was der Befund am Aufwand ändert

Das Schema steht der Zielarchitektur nicht im Weg. Die **Regenerations-Kaskade braucht keine
Migration**; nur der Behälter-Teil braucht eine.

| Befund | Konsequenz |
|---|---|
| `recipe_regenerations.recipe_id` → `foodalchemist_recipes`, **kein** `is_sales_recipe`-Constraint | Basisrezepte dürfen schon Regenerationszeilen haben |
| Guard sitzt allein in PHP: `SalesRecipeService::upsertRegeneration/deleteRegeneration/reorderRegenerations` + `updateVk` mit `->verkauf()`; MCP über `FoodAlchemistTool::guardVkRecipe()` | Ein Scope-Wechsel plus ein Tab im `RecipeModal` genügt |
| **Lesepfad ist bereits ungegated:** `PlanungsblattService::regenerationenFuer()` ohne `$istVk`-Gate; `report-recipe-node.blade.php` rendert rekursiv je Knoten; `regen_snapshot` je Auftragszeile | Druck und Auftrag ziehen Komponenten-Regeneration heute schon ab — sie ist nur nie befüllt |
| `recipe_regenerations.ingredient_id` (FK auf `recipe_ingredients`) existiert, wird **nirgends gelesen oder geschrieben** | Die Kaskade braucht keine neue Spalte — nur die Aktivierung dieser toten FK |
| `container_*_vocab_id` liegen physisch an `foodalchemist_recipes` | Kein Umzug — die Skalare werden durch Zeilen je Zweck abgelöst |

---

## Kern-Entscheidungen (User, live 2026-09-04)

| # | Entscheidung | Begründung |
|---|---|---|
| 1 | **Live-Kaskade + Override.** Basisrezept ist **Default**, nicht „Wahrheit". | Änderung an der Komponente muss überall durchwirken; ein Gericht darf trotzdem abweichen (Ratatouille warm als Beilage, kalt als Antipasto). |
| 2 | **Die produzierte Menge wählt Größe *und* Anzahl.** | „Bei 2 kg reicht ein kleiner Einsatz, bei 10 nehme ich zwei flache." Ein fester Typ mit gerechneter Anzahl trifft diesen Fall nicht. |
| 3 | **Behälter je Zweck.** Abfüllen ist nicht Regenerieren. | „Eine Suppe macht man ja nicht im GN warm, sondern im Kipper, Topf — und füllt sie dann um." |
| 4 | **Referenz-Füllung schlägt Dichte.** | „Im Basisrezept können wir festlegen, was in einen Standard reinpasst — und dann runter oder hoch überlegen." Die Zahl kommt aus der Küche und enthält die Dichte implizit. |
| 5 | **Nie automatisch von flach auf tief umschalten.** | Das System zeigt Varianten nebeneinander, die Küche entscheidet. |
| 6 | **Neue Behälter legt der Kunde selbst an**, inkl. wofür sie freigegeben sind. | „Was ist, wenn neue Lagerbehälter, Regenerationsbehälter kommen — dazu muss es in den Einstellungen eine Möglichkeit geben." |
| 7 | GP-Komponenten bekommen **kein** neues Pflegefeld. | Kategorie-Default aus `gps.zustand` statt ~7.000 leerer Spalten. |
| 8 | Behälterbedarf geht **nicht** in den Einkauf. | Erst relevant, wenn es Einweg-Behälter als Artikel gibt. |

---

## 1 · Das Behälter-Modell

### 1.1 Bezeichnungssystematik

Gastronorm ist in **DIN EN 631 / DIN 66075** genormt. Bezeichnung immer **`GN <Format>-<Tiefe mm>`**.
Grundmaß GN 1/1 = 530 × 325 mm; Tiefenstufen 20, 40, 65, 100, 150, 200 mm.

| Format | Grundfläche | 40 | 65 | 100 | 150 | 200 |
|---|---|---|---|---|---|---|
| 2/1 | 650 × 530 | 10,0 l | 18,5 l | 28,5 l | 42,0 l | — |
| **1/1** | **530 × 325** | 5,5 l | 8,8 l | 13,7 l | 20,0 l | 27,8 l |
| 2/3 | 354 × 325 | 3,3 l | 5,4 l | 8,0 l | 11,9 l | 15,5 l |
| 1/2 | 325 × 265 | 2,3 l | 4,0 l | 6,5 l | 9,5 l | 12,5 l |
| 1/3 | 325 × 176 | 1,4 l | 2,5 l | 4,0 l | 5,7 l | 7,5 l |
| 1/4 | 265 × 162 | 1,6 l | 1,8 l | 2,8 l | 4,0 l | 5,5 l |
| 1/6 | 176 × 162 | — | 1,0 l | 1,6 l | 2,4 l | — |
| 1/9 | 176 × 108 | — | 0,6 l | 1,0 l | 1,5 l | — |

Quellen: [GN-Profi](https://www.gn-profi.de/gn-masse-und-groessen) ·
[Gastprodo](https://www.gastprodo.de/ratgeber/gn-behaelter-groessen/) ·
[Gastro-Michel](https://www.gastro-michel.de/GN-Masse-einfach-erklaert) ·
[Chromonorm](https://www.chromonorm.de/ratgeber/gastronorm/) ·
[Blanco-Größenübersicht (PDF)](https://www.gastrouniversum.de/media/pdf/04/14/d4/Blanco-Gastronorm-Beh-lter-Gr-ssen-und-Masse-bersicht.pdf) ·
[Gastrodax Bäckernorm 600×400](https://www.gastrodax.de/gastrobedarf/gn-behaelter/baeckernorm-400-x-600) ·
[EuroBox Isolierbehälter](https://www.eurobox-logistiksysteme.com/produktkatalog-eb/)

> **Ehrlichkeitsvorbehalt:** Handelsquellen nennen **Bruttovolumen**. Eine maximale Füllhöhe gibt
> **keine** öffentliche Quelle an — nur „Rand, Radien und Herstellerbauform reduzieren das
> Fassungsvermögen" und die Faustzahl *Nutzfüllmenge ≈ 85 %*. Genau deshalb ist die Referenz-Füllung
> aus der Küche (§1.5) die bessere Basis als jede berechnete Füllhöhe.

### 1.2 Behälter gibt es je Zweck

Vier Zwecke, **ein** Vokabular für Zweck und Eignung:

| Zweck | Wann | Typische Behälter |
|---|---|---|
| `abfuellen` | direkt nach der Produktion | Eimer 5/10 l, Kanne, Vakuumbeutel, GN mit Deckel, Kiste |
| `regenerieren` | am Einsatztag | GN im Konvektomat, Bain-Marie-Einsatz, Blech |
| `ausgabe` | am Pass / Buffet | Chafing-GN, Platte, Schale |
| `transport` | dazwischen | Thermobox, Isolierbehälter, Kühlkiste |

**Kühlfähigkeit ist kein Zweck**, sondern eine Eigenschaft (`kuehlfaehig`) — im Kühlhaus steht der
Abfüllbehälter, es gibt keinen Behälterwechsel. Das **Kochgefäß** (Kipper, Kessel, Topf) ist kein
Behälter, sondern ein Gerät; es wird über `recipes.batch_max_kg` bereits als Topf-Deckel geführt und
limitiert die Ansatzgröße, nicht die Abfüllung.

### 1.3 Zwei Behälter-Sorten

| Sorte | Bemessung | Beispiele |
|---|---|---|
| **Füllbehälter** | Referenz-Füllmenge, skaliert | GN, Eimer, Kanne, Euronorm 600×400, Blech, Schale, Kiste |
| **Träger** | Anzahl Steckplätze | Thermobox, Isolierbehälter, Wagen, Konvektomat-Gestell |

Damit fällt die Transportstufe ab: *„40 l Suppe → 4× Eimer 10 l → 1 Isolierbehälter"*.

> **v1-Vereinfachung (bewusst):** `traeger_plaetze` + `traeger_format` bilden GN-Modularität
> (1/1 = 2× 1/2 = 3× 1/3) und Stapelhöhe (4× GN-65 **oder** 2× GN-150 in derselben Box) nicht ab.
> Als **Hinweis, kein Filter** tragbar. Zielmodell v2: `traeger_nutzhoehe_mm` + `traeger_grundformat`
> → Kapazität = `floor(nutzhöhe / (tiefe + Deckelzuschlag))`.

### 1.4 Wenn Lager- und Regenerationsbehälter derselbe sind

Der häufigste gute Fall: Ragout in **GN 1/1-65 mit Deckel**, kühlt darin ab, lagert darin, geht
direkt so in den Konvektomat. Kein Umfüllen. Der Eimer kann das nicht. Die Logik ist kein Sonderfall,
sondern **ein Katalogfeld und ein Vergleich**:

| Fall | Bedarf | Was das System sagt |
|---|---|---|
| gleicher `container_vocab_id` in beiden Zeilen | **einmal**, `max(n_abfüllen, n_regenerieren)` | „5× GN 1/1-65 · durchgängig, kein Umfüllen" |
| verschiedene | beide | „4× Eimer 10 l → 5× GN 1/1-65 · Umfüllen am Einsatztag" |
| Lagerbehälter ohne `regenerieren`-Eignung, zweite Zeile fehlt | — | **Lücke:** „Eimer 10 l ist nicht regenerationsgeeignet — Regenerationsbehälter fehlt" |

Der dritte Fall ist der eigentliche Gewinn: er fängt den Planungsfehler ab, bei dem am Einsatztag
niemand weiß, worin die Suppe warm wird.

Die Eignung wird **gepflegt, nicht aus dem Material geraten** — eine Regel „Kunststoff → nicht in den
Ofen" liegt bei hitzefestem PP oder Silikon daneben und würde als Wahrheit gelesen.

> **Bekannte Lücke (v2):** Eignung ohne Gerätebezug lässt Polycarbonat-GN im Konvektomat durch, wenn
> jemand die Freigabe falsch setzt. Eine Gerät ↔ Behälter-Matrix ist Folgearbeit (vgl. Spec 30 §7
> „Equipment ↔ Posten mappen").

### 1.5 Referenz-Füllung vor Dichte

Das Rezept trägt je Zweck **eine** Zahl, die jede Köchin aus Erfahrung nennt:

> **„In einen `GN 1/1-65` passen 8 kg von diesem Produkt."**

Diese Zahl **enthält die Schüttdichte bereits**. Sie ist genauer als jede Dichtetabelle und braucht
keine Quelle, die es nicht gibt. (Das System rechnet heute überall mit Dichte 1.0 —
`RecipeRecomputeService::grammFaktor()`, `GebindeRechner`, `PriceService`.)

**Pflege-Regel:** am **größten praktikablen** Behälter angeben, nicht am kleinsten — nach unten
skaliert es sauberer als nach oben.

Weil das niemand für ~1.400 Bestandsrezepte im Voraus pflegt, dahinter eine **Rangfolge**
(Muster: `grammFaktor()`, Allergen-Konfidenz):

| Rang | Quelle | Konfidenz |
|---|---|---|
| 1 | **Referenz-Füllung** am Rezept, je Zweck | hoch |
| 2 | **Dichteklasse** am Rezept (flüssig 1,0 · dicht 0,9 · schüttfähig 0,6 · locker 0,2) × `nutzfaktor` × Nutzvolumen | mittel |
| 3 | **Warengruppen-Default** | niedrig, sichtbar markiert |
| 4 | nichts | kein Vorschlag, **mit Grund** |

Die Konfidenz steht am Vorschlag: *„3× GN 1/1-100 · geschätzt"* gegen *„5× GN 1/1-65 · Referenz"*.

**`dichteklasse` gehört ans Rezept, nicht an die Zweck-Zeile** — Abfüllen und Regenerieren desselben
Produkts haben dieselbe Dichte; zwei Felder würden driften. Je Zweck gehört nur `skalierung`:

| `skalierung` | Bedeutung | Beispiele |
|---|---|---|
| `tiefer_fuellbar` | darf im tieferen Behälter proportional höher stehen | Suppe, Fond, Sauce, Püree |
| `hoehe_gebunden` | nur die Fläche skaliert | Gulasch, Gemüse, Reis, Blattsalat |
| `lagenware` | wird gelegt, nicht geschüttet — Stückpfad | Papadam, Schnitzel, Tartelettes |

### 1.6 Die Rechnung

```
1. Basis        (behaelter_ref, referenz_menge_kg) aus recipe_containers, je Zweck
                fehlt sie → Nutzvolumen(behaelter) × nutzfaktor × dichteklasse   [Rang 2/3]
                fehlt produzierte_menge_kg (yield_kg NULL) → grund, Abbruch
2. Anzahl       n = ceil(menge_kg / kg_je_behaelter)
3. Alternativen Kandidaten = Katalog gefiltert auf eignung ⊇ zweck. Je Kandidat:
                  gleiche Tiefe                     → kg × Flächenverhältnis
                  tiefer + tiefer_fuellbar          → kg × Volumenverhältnis
                  tiefer + hoehe_gebunden           → nur Fläche, Rest bleibt Luft
                  FLACHER (egal welche skalierung)  → kg × Fläche × min(1, tiefe_kand/tiefe_ref)
                                                      + Konfidenz-Abwertung
                dann: kg_je_behaelter = min(kg, max_fuellgewicht_kg)
4. Durchgängig  abfuellen.container == regenerieren.container ⇒ ein Bedarf, n = max(beide)
5. Ausgabe      Basis + 2 Alternativen, flach → tief, je mit Konfidenz-Marke
6. Träger       Plätze gegen traeger_plaetze — Hinweis, kein Filter
```

**Warum Schritt 3 eine Richtung braucht:** „nur Fläche, Rest bleibt Luft" stimmt nur flach → tief.
Tief → flach **kappt**: Referenz `GN 1/1-100` mit 12 kg, Schicht steht bei 80 mm — das passt nicht
proportional in ein `GN 1/1-65`. Ohne gepflegte Füllhöhe kann der Rechner das nicht wissen, also
rechnet er konservativ und wertet die Konfidenz ab. Optional `max_schichthoehe_mm` an der Zeile.

**Warum `max_fuellgewicht_kg`:** `tiefer_fuellbar` × Volumenverhältnis ergibt Suppe in `GN 1/1-200`
≈ 25–28 kg **in einem Behälter**. Rechnerisch korrekt, nicht tragbar. Ohne Deckel steht in der
Varianten-Liste eine Zeile, die nie jemand wählt — und die Liste verliert Vertrauen.

**Und es braucht einen Ort für die Wahl.** „Küche entscheidet" heißt nicht „der Produktionsschein
druckt drei Varianten". An der Auftragszeile steht `gewaehlte_variante` (Default = Basis), im Editor
umschaltbar, überlebt Recompute. Ohne das ist Etappe F nicht druckbar.

---

## 2 · Zielmodell Regeneration

1. **Die Komponente sagt, wie sie behandelt wird** — Gerät, °C, min, Kerntemperatur in
   `recipe_regenerations` am Basisrezept (`ingredient_id = NULL` = „das bin ich"), dazu je Zweck
   eine Zeile in `recipe_containers`.
2. **Das Gericht erbt und bündelt** — die Liste wird **gelesen, nicht gespeichert**. Gespeichert
   wird nur, was jemand am Gericht bewusst übersteuert.
3. **Die produzierte Menge wählt Größe und Anzahl.**

### 2.1 Die Kaskade je Komponente

| Rang | Quelle | Herkunft im UI |
|---|---|---|
| **0** | Zeile am **Gericht** mit `ingredient_id IS NULL` | **Gesamt** — „das Gericht als Ganzes" |
| 1 | Zeile am Gericht mit gesetzter `ingredient_id` | **Override** (markiert, „zurücksetzen") |
| 2 | Eigene Zeile(n) des referenzierten Basisrezepts | **geerbt von &lt;Rezept&gt;** |
| 3 | GP-Komponente → Regel aus `gps.zustand` | **Regel** |
| 4 | nichts | **fehlt** — als Lücke gezählt |

**Rang 0 ist kein Altlast-Rest.** Das Regelwerk sagt: „Der einfachste Fall bleibt eine Zeile
‚Gesamt'" — Lasagne, Auflauf, Wrap werden als Ganzes regeneriert. Die Gesamt-Zeile steht **neben**
der Komponenten-Kaskade, nicht statt ihrer. Am Basisrezept trägt dieselbe Form die Bedeutung
„das bin ich".

**Kaskaden-Tiefe ist 1, nicht 3.** Nur die Direkt-Komponenten des Gerichts werden regeneriert. Der
Fond im Ragout taucht am Gericht **nicht** als eigene Regenerationszeile auf. Der BFS-Rahmen von
`SalesRecipeService::komponentenIds()` passt, sein `maxTiefe = 3` nicht.

**Vertrag „Gerät NULL = kalt servieren"** muss explizit werden — es gibt kein `modus`-Feld. Zeile mit
`device_vocab_id IS NULL` = kalt; **keine** Zeile = fehlt. Rang 3 erzeugt eine virtuelle Zeile, sonst
wäre er von Rang 4 nicht unterscheidbar. Gehört ins Regelwerk **und** in den Service-Docblock.

**Rang 3 braucht einen Ort.** „TK/konserviert → Warengruppen-Vorschlag" — ohne Ort wird es eine
Code-Konstante. Als Config-Array neben `sales_units`, im Regelwerk gespiegelt.

### 2.2 Override-Lebenszyklus

Zwei Wege, auf denen ein Override still falsch wird:

- **Tausch in place:** Spec 49 setzt `UPDATE recipe_ingredients SET referenced_recipe_id = …` auf
  derselben Zeilen-ID (`RecipeService::tauscheZutat` / `ersetzeInVerwendungen`). Ein Override an
  dieser `ingredient_id` überlebt und beschreibt danach eine **andere** Komponente.
  → Beide Methoden löschen die Overrides der betroffenen Zeile oder markieren sie
  („stammt von &lt;alt&gt;, prüfen").
- **Soft-Delete:** `syncIngredients` soft-deletet entfernte Zutaten; `nullOnDelete` greift dabei
  **nicht** → verwaiste Overrides.
  → Die Kaskade joint `recipe_ingredients` mit `whereNull('deleted_at')`; Verwaiste zählen als Lücke
  plus Aufräum-Hinweis, nie stumm.

---

## 3 · Datenmodell

### 3.1 `foodalchemist_recipe_containers` (neu)

```
id, uuid, team_id NOT NULL          (Rezepte sind team-eigen — wie presentations/ingredients)
recipe_id            FK cascade
zweck                abfuellen | regenerieren | ausgabe | transport
container_vocab_id   FK → vocab_containers, restrictOnDelete
referenz_menge_kg    [Rang 1]
skalierung           tiefer_fuellbar | hoehe_gebunden | lagenware
max_schichthoehe_mm  NULL (optional, Präzision für tief→flach)
stueck_je_behaelter  NULL (Etappe H, Lagenware)
note, source, ai_confidence, ai_reasoning     (Lineage-Trio wie recipe_regenerations)
timestamps, softDeletes
UNIQUE(recipe_id, zweck) WHERE deleted_at IS NULL
```

> ⚠ Partielle Indizes gibt es nur in SQLite/PostgreSQL. Unter MySQL läuft die Invariante als
> Service-Guard — Präzedenz `2026_09_04_000001_repair_darreichung_ein_standard_index`.

`dichteklasse` **nicht** hier, sondern als `recipes.dichteklasse`.

### 3.2 `foodalchemist_vocab_containers` (erweitern)

Bestand: `id, uuid, team_id NULL, legacy_id, slug, name, group_name, kapazitaet_kg, sort_order,
is_inactive`, `UNIQUE(team_id, slug)`. Neu:

```
familie            GN | EN600x400 | Eimer | Kanne | Schale | Blech | Kiste | Traeger | frei
format_code        '1/1', '1/2', '600x400', NULL
laenge_mm, breite_mm    NULL bei rund/unregelmäßig
tiefe_mm
volumen_l          Nennvolumen laut Norm/Hersteller
nutzfaktor         DECIMAL(3,2) DEFAULT 0.85 — je Typ überschreibbar (Eimer 90 %+, GN-20 kaum 60 %)
max_fuellgewicht_kg     Handhabungs-Deckel (Vorschlag GN 15, Eimer 10)
eignung            JSON-Set über das 4er-Zweck-Vokabular (Katalog ist klein → PHP-seitig filtern)
kuehlfaehig        BOOL
ist_traeger        BOOL
traeger_plaetze, traeger_format
kapazitaet_kg      bleibt — Fallback und Handpflege
```

**Präzedenz beim Skalieren:** mm-Maße → `volumen_l` → `kapazitaet_kg` → **nicht skalierbar, mit
Grund**. Ein Eimer ohne Grundfläche fällt sauber auf sein Nennvolumen zurück.

**Seeds.** Es gibt keinen Seeder im Repo. Die 17 GN-Standards leben in
`00_SYSTEM/00.04_Scripts/_legacy/oneshots_may2026/_oneshot_tag22_behaelter_vocab.py` (Vault) —
Namen „GN 1/1 65mm", ohne Kapazität. Im Umlauf sind mindestens drei Slug-Schreibweisen
(`gn_11_65`, `gn_14_65mm`, `gn_1_1_65`); `Str::slug` entfernt den Schrägstrich ersatzlos, „1/1" → „11".
→ Der Backfill-Matcher normalisiert über den **Namen**, nicht den Slug. **Name nicht umbenennen**
(Nutzer kennen „GN 1/1 65mm"); die Norm wandert strukturiert in `format_code` + `tiefe_mm`.
Neue Zeilen: **Eimer, Kannen, Euronorm-Kästen, Träger** — im Bestand gar nicht vorhanden.

Seeds mit `team_id NULL` (global) — dann aber `TeamScope::mayWrite()` in `Behaelter.php` verdrahten,
sonst kann niemand globale Zeilen in der UI editieren (`owns(null) = false`).

Dazu das fehlende Model `src/Models/FoodAlchemistVocabContainer.php` mit `$guarded = ['id']`
(Hauskonvention, wie `FoodAlchemistVocabRegenerationDevice`).

### 3.3 `recipe_regenerations`

Kein Umbau: `ingredient_id` reaktivieren, Relation `ingredient()` am Model ergänzen. `team_id` ist
nullable ohne FK (Altlast) — bei Gelegenheit `NOT NULL`.

### 3.4 Presentations und Legacy-Skalare

- `recipe_presentations.regeneration_temp_c/_duration_min/_core_temp_c/_device_vocab_id` →
  **droppen** (Servierform ändert nichts an 140 °C), `darreichungsInfo()` liest sie nicht mehr.
  **Vorher migrieren:** zählen, wie viele Zeilen echte Werte tragen (Quelle ist der WaWi-Import),
  und diese als Regenerationszeile der Standard-Darreichung nach `recipe_regenerations` überführen.
  Sonst ist der Import umsonst gelaufen. → **Entscheid offen, §5 Nr. 1.**
- `recipe_presentations.container_*` → **behalten** als Override für Zweck `ausgabe`. Das ist die
  einzige Achse, auf der die Servierform legitim eingreift (Buffet → Chafing-GN, Teller → keiner).
  Kaskade für `ausgabe`: Basisrezept-Default → Gericht-Override → Darreichungs-Override.
- `syncStandardDarreichung()` spiegelt danach nur noch das Vehikel.
- `recipes.container_warm/cold_*` + Legacy-`regeneration_*` nach Etappe G droppen;
  `ImportSliceCommand` mitziehen.

### 3.5 Snapshots konsolidieren

Drei JSON-Blobs an einer Auftragszeile (`darreichung`, `regen_snapshot`, `plating_snapshot`) mit
überlappender Semantik — das ist genau das Drift-Muster aus Fehler 3. `regen_snapshot` ist heute
schon je Komponente strukturiert und nimmt die Behälter auf:
`behaelter: {basis, varianten[], gewaehlt}`. `darreichung` verliert Regeneration und Behälter,
behält Vehikel und Geschirr. Bei der Gelegenheit: `produktionsauftrag.blade.php` druckt aus
`darreichung` rohe Array-Keys als Labels.

---

## 4 · Etappen

### A — Katalog, Einstellungen, Primitiv

- Migration `vocab_containers` (§3.2) + `recipes.dichteklasse` + `recipe_containers` (§3.1).
  **Eindeutige Migrations-Slots wählen** — `2026_09_04_000001`, `2026_08_29_000001..4` und
  `2026_08_31_000001` sind bereits doppelt belegt.
- **`src/Livewire/Settings/Behaelter.php` ausbauen** (Route `/einstellungen/behaelter`): neue Felder,
  Eignungs-Häkchen, Träger-Block. **Dabei die zwei Live-Defekte fixen — §6.**
- Seeds + Backfill-Matcher (§3.2), Rest in eine Review-Liste. Nichts raten.
- Model `FoodAlchemistVocabContainer`.
- Neu `src/Services/BehaelterRechner.php`, **Zwilling von `GebindeRechner`** (pure, kein DB-Zugriff,
  `ceil($x - 1e-9)`, `berechenbar`/`grund`-Muster):

```php
public function varianten(float $mengeKg, array $basis, array $kandidaten, string $zweck): array
// basis      = ['referenz_menge_kg'|'dichteklasse', 'container', 'skalierung'] -> traegt die Konfidenz
// kandidaten = Katalogzeilen; intern gefiltert auf eignung enthaelt $zweck
// -> [['behaelter' => 'GN 1/1-65', 'anzahl' => 5, 'kg_je_behaelter' => 8.0,
//     'rest_im_letzten_kg' => 1.4, 'konfidenz' => 'hoch'], ...]
// grund-Faelle: 'Ausbeute (yield_kg) fehlt - Behaelter nicht bemessbar'
//               'Weder Referenzmenge noch Dichteklasse hinterlegt'
//               'Eimer 10 l ist nicht fuer regenerieren freigegeben'
```

### B — Kaskaden-Service (lesend, nicht persistiert)

`src/Services/RegenerationCascadeService.php` nach dem Muster von
`SalesRecipeService::komponentenIds()` / `komponentenZeiten()` — Abwärts-BFS über
`recipe_ingredients.referenced_recipe_id`, **Tiefe 1**, Visited-Set, bewusst nicht persistiert
(Docblock dort: „eine Spalte würde beim nächsten Komponententausch driften").
Enthält Rang 0–4 (§2.1), den Soft-Delete-Join, den „Gerät NULL = kalt"-Vertrag und die
`ausgabe`-Kaskade mit Darreichungs-Override.

### C — Schreibpfad am Basisrezept freischalten

- `SalesRecipeService::upsertRegeneration/deleteRegeneration/reorderRegenerations`: `->verkauf()`
  entfernen (`visibleToTeam()` bleibt).
- `FoodAlchemistTool::guardVkRecipe()`: Variante ohne `is_sales_recipe` für die drei
  `recipe_regeneration.*`-Tools.
- `updateVk()`-Whitelist `VK_FELDER`: `container_*` in einen für Basisrezepte nutzbaren Pfad heben.
- **Neuer Tab im Basisrezept-Editor** — `src/Livewire/Recipes/RecipeModal.php`: die Tab-Liste in
  `tabLaden()` kennt heute acht Tabs, keinen `regeneration`; Blade entsprechend erweitern.
  Markup aus `resources/views/livewire/verkauf/vk-modal.blade.php` (Regeneration-Tab).
  Ohne Komponenten-Spalte: eine Regenerationszeile „das bin ich" plus die Behälterzeilen je Zweck —
  **das Basisrezept zeigt damit, welche Behälter für dieses Produkt vorgesehen sind.**
  Die Zeile `regenerieren` bekommt ein Häkchen *„gleicher Behälter wie beim Abfüllen"*, das nur die
  FK kopiert; die Auswahllisten sind je Zweck auf die freigegebenen Typen gefiltert.

### D — Gericht-Tab auf die Kaskade umstellen

`src/Livewire/Verkauf/VkModal.php`: `render()` liest `$regenZeilen` per `DB::table(...)` → ersetzen
durch `RegenerationCascadeService`. Je Zeile ein Herkunfts-Chip; „Edit" an einer geerbten Zeile
schreibt einen Override (`ingredient_id` gesetzt), „zurücksetzen" löscht ihn. Spalte „Behälter" zeigt
die Varianten für die eingestellte Portionszahl.
Dazu die Override-Bereinigung in `RecipeService::tauscheZutat()` / `ersetzeInVerwendungen()` (§2.2).

### E — Rechnung in die Produktion (der Split)

`explodiere()` erzeugt **eine Zeile je Rezept-ID, aggregiert über alle Gerichte** — festgenagelt
durch `UNIQUE(production_order_id, recipe_id)` (`2026_08_01_000003`). Daraus folgt die Trennung:

| Zweck | Wo | Menge |
|---|---|---|
| `abfuellen` | an **jeder** Zeile, auch Sub-Sub-Rezepten | `produzierte_menge_kg` der Zeile |
| `regenerieren` · `ausgabe` | nur an Top-Zeilen **die auch verkauft werden** (`tiefe == 0 && is_sales_recipe`) — je Komponente | Komponenten-Masse = `bruttoMasseG × batches` |

> **Warum nicht `tiefe == 0` allein:** Ein Basisrezept kann Auftrags-Top sein („Brauner Fond, 6 kg").
> Ein Fond wird produziert und gelagert, nicht regeneriert. Die Grenze ist „wird serviert", nicht
> „steht oben".
>
> **Warum nicht an der Basisrezept-Zeile:** Die Sauce, die drei Gerichte teilen, wird an drei Pässen
> je anteilig gewärmt. Die aggregierte Zeile kennt diesen Anteil nicht, die VK-Zeile schon.

Dazu: `gewaehlte_variante` persistieren (§1.6), Snapshot konsolidieren (§3.5), und ein **Rollup je
Auftrag** analog `$allergenRollup` in `src/Livewire/Produktion/Editor.php` — „6× GN 1/1-65,
2× Eimer 10 l, 2 Thermoboxen" neben der Allergen-Kachel in `editor-vorschau.blade.php`.

### F — Ausgabe

Gedruckt wird **ein** Wert (die gewählte Variante) plus die Marke „2 Alternativen", nie drei Zeilen.

| Ort | Datei |
|---|---|
| Auftrags-Zeilen | `resources/views/livewire/produktion/partials/editor-zeilen.blade.php` — Spalte nach „Portionen" |
| Vorschau | `.../partials/editor-vorschau.blade.php` — neben „Portionen/kg" |
| Produktionsschein | `resources/views/dokumente/produktionsauftrag.blade.php` — `meta`-Einzeiler; Toggle `$opt['darreichung']` existiert |
| Wandmonitor | `resources/views/livewire/produktion/tagesplan.blade.php` — Karte „Geschirr & Ausgabe", gefüllt von `Tagesplan::wallGerichtDarreichung()` |

### G — Datenmigration

Kommando `foodalchemist:regeneration-hochziehen` (`--dry-run` / `--apply` / `--verify`):

1. `component_label` ↔ Komponentenname normalisiert matchen → `ingredient_id` setzen.
2. **Nur eindeutige Werte hochziehen** — tragen alle Vorkommen einer Komponente denselben Wert, wird
   er zum Basisrezept-Default. Weichen sie ab, bleiben **alle** am Gericht als Override plus
   Review-Zeile. („Erster Schreiber gewinnt" wäre eine stille, sortierungsabhängige Entscheidung.)
3. **`Gesamt`-Zeilen erkennen und stehen lassen** — das sind Rang-0-Zeilen, keine Fehlschläge.
4. Kein Match (Tippfehler, gelöschte Komponente) → unverändert, Review-Liste.
5. Behälter-Skalare: `recipes.container_warm_vocab_id` → `recipe_containers`-Zeile
   `zweck='regenerieren'`, `container_cold_vocab_id` → `zweck='ausgabe'`.
   **`container_*_count` wird nicht übernommen** — die Zahl war nie an eine Menge gebunden. Sie
   landet im Report, damit auffällt, wo die alte Handeingabe stark abweicht.
6. Presentations-`regeneration_*` migrieren, dann droppen (§3.4) — **wartet auf Entscheid §5 Nr. 1**.

Backup `PRE_REGEN_HOCHZIEHEN`, Report nach `00_INBOX/`, danach Recompute.

### H — Lagenware (nachgelagert)

`recipe_containers.stueck_je_behaelter` statt `referenz_menge_kg`. Weicht der tatsächliche Behälter
vom Bezugs-Behälter ab, rechnet der Service **nicht**, sondern meldet den Grund. Bis dahin liefert
`skalierung = lagenware` genau diese Meldung.

---

## 5 · Offene Entscheide

| # | Frage | Vorschlag |
|---|---|---|
| **1** | `recipe_presentations.regeneration_*` droppen? **Das löscht Spalten mit WaWi-Import-Daten.** | Erst zählen, dann als Standard-Darreichungs-Zeile nach `recipe_regenerations` migrieren, dann droppen. **Braucht Freigabe.** Blockiert nur Etappe G.6. |
| 2 | Zweck-/Eignungs-Vokabular auf 4 Werte vereinheitlichen, `kuehlfaehig` als Eigenschaft | nehmen |
| 3 | `gewaehlte_variante` an der Auftragszeile persistieren, Default = Basis | nehmen — sonst ist der Druck nicht baubar |
| 4 | Träger-Modell v1 vereinfacht (Plätze statt Nutzhöhe) | nehmen, als bekannte Vereinfachung markiert |

**Vor Etappe E messen:** wie viele Bestandsrezepte haben kein `yield_kg`? Ohne Masse hilft auch
Rang 2 nicht, und der Rechner liefert nur einen Grund.

---

## 6 · Zwei Live-Defekte in den Einstellungen (gehören in Etappe A)

**E1 — `referenzen()` ist blind.** `src/Livewire/Settings/Behaelter.php` prüft nur die
`*_legacy_id`-Spalten und gibt `0` zurück, sobald `legacy_id === null`. Jede vom Kunden angelegte
Zeile ist damit **hart löschbar, egal ob genutzt**; geprüft wird die Legacy-Spalte, nie der echte FK
`container_warm_vocab_id`. Presentations und Regenerationen sieht die Funktion gar nicht. Alle
heutigen FKs sind `nullOnDelete` → Löschen nullt still den Behälter an Rezepten, Darreichungen und
Regenerationen. Mit `recipe_containers` käme ein vierter stiller Verlust dazu.

→ Zählung auf die echten FK-Spalten umstellen (`recipes.container_*_vocab_id`, `presentations.*`,
`regenerations.device_vocab_id`, neu `recipe_containers`).
→ **`restrictOnDelete` nur auf den neuen FK.** Die Bestands-FKs bleiben `nullOnDelete`: es kann
Zeilen geben, die auf soft-gelöschte Vokabeln zeigen, und eine nachträgliche `restrict`-Migration
würde daran scheitern. Das Loch schließt die korrigierte UI-Prüfung, ohne Bestandsdaten zu riskieren.

**E2 — Slug-Dublettencheck nicht team-scoped.** Der Check läuft als `where('slug', $slug)` ohne
Team-Filter, während die DB-Unique `(team_id, slug)` ist. Ergebnis: Kind-Teams werden blockiert,
wenn irgendein Team den Slug hat — und die Fehlermeldung leakt dessen Existenz.
→ `TeamScope::applyVisible` in den Check.

> Nebenbefund, nicht Teil dieses Specs: `vocab_kitchen_equipment.slug` und `serving_forms.code` sind
> global unique statt team-scoped — dasselbe Muster.

---

## 7 · Zwei Prompt-Bugs

1. **`vk.regeneration` — inert:** der Prompt gibt `hinweis` aus, `VkModal::kiRegeneration()` liest
   `$z['note']`. Der Hinweis kommt nie an.
2. **`vk.behaelter` — destruktiv:** der Prompt mischt `behaelter_warm_id` (deutsch) mit
   `container_warm_count` (englisch); `uebernehmeBehaelter()` liest `behaelter_{$seite}_anzahl` und
   schreibt das Ergebnis **bedingungslos** ins Formular. **Jede KI-Übernahme nullt damit die von
   Hand getippte Anzahl.** `VkEditorVollausbauTest` füttert den deutschen Key und maskiert es.
   Mit Etappe E verschwindet das Feld als Eingabe — der Fix muss trotzdem **vor** Spec 50 Paket B
   passieren, sonst wird der Bulk-Pfad mit demselben Loch verdrahtet.

---

## 8 · Regelwerk-Änderungen

- **`Regelwerk_Verkaufsgerichte.md` v1.5 → v1.6**
  - **§3.4 neu fassen:** definiert Behälter heute als „Transport und Warmhalten" — das kollidiert
    mit „Behälter je Zweck".
  - §3.2 umschreiben: Regeneration ist Eigenschaft der Komponente; Gericht zeigt Kaskade und trägt
    nur Overrides. Rang 0 (Gesamt) explizit. Vertrag „Gerät NULL = kalt".
  - **neu §3.4e Behälter-Bemessung** (§3.4a–d sind belegt): Füllbehälter vs. Träger, Referenz vor
    Dichte, Varianten statt Automatik, Handhabungs-Deckel.
  - **„Default" statt „Wahrheit"** durchgängig — ein Gericht, das abweicht, ist kein Fehler.
- **`Regelwerk_Basisrezepte.md` v1.7 → v1.8**: neues **§14** „Regeneration & Behälter am
  Basisrezept" (§13 ist der Roadmap-Platzhalter „Grundrezepte" — auflösen oder umnummerieren).
- **Neu `Cross_Cutting/Behaelter_und_Gastronorm.md`**: GN-Normtabelle mit Quellen, Familien,
  Träger-Prinzip. Heute nirgends im Vault.
- `Cross_Cutting/Anlass_Serviceformen.md`: die bestehende Spalte „Behälter-Implikation" als
  Default-Quelle für die Behälter-Familie je Serviceform verdrahten.
- Rang-3-Tabelle (Warengruppe → Regenerationsregel) als Config, im Regelwerk gespiegelt.

> **SSOT:** Regelwerke per MCP im FA-Wissensmodul (Team 6) pflegen, Vault = Spiegel. Große
> Bestands-Docs nicht per Hand-PUT → `knowledge-import`.

---

## 9 · Abstimmung mit Spec 50

Branch `feat/schicht4-vollstaendigkeit` ist heute reine Doku, kein Code-Overlap. Vorschlag für
**Paket B, Baustein B-1** (Etappe 5):

- **`vk.behaelter` streichen.** Ein LLM zu fragen, wie viele GN 14,2 kg ergeben, während die
  Datenbank die 14,2 kg exakt kennt, ist genau das, was
  [46 Kanon-Entscheidungsvorlage](46_Kanon_Entscheidungsvorlage.md) verbietet: *Regelwerke
  durchsetzen statt in den Prompt legen*.
- **Ersatz `recipe.dichteklasse`** als **Basisrezept**-Schritt (`SCHRITTE`, nicht `SCHRITTE_VK`) —
  liefert `dichteklasse` + `skalierung`. Produkteigenschaften, keine Rechnung. Damit ist der Bestand
  vom ersten Tag an bemessbar (Rang 2).
- **`vk.regeneration` → `SCHRITTE`** (Basisrezept). Auf VK-Ebene erzeugt es die Doppelpflege, die
  dieser Spec abschafft.
- **Slug-Fix `hinweis`→`note` vor Paket B.**

**Tests, die dabei brechen:** `PromptRegistryTest` (bidirektional gegen `REGISTRY_SOLL`),
`VkBulkEnrichTest` (harte Zahlen aus `SCHRITTE_VK` / `NUR_GERICHT`). `BulkEnrichService::NUR_GERICHT`
und `ZIELFELDER` mitziehen.

---

## 10 · Verifikation

**Rechner (Pest, pure):** 0 kg · exakt volle Referenzmenge · `yield_kg NULL` → Grund · Behälter ohne
Maße → Volumen-Fallback · nur Dichteklasse → Konfidenz `mittel` · weder noch → `berechenbar: false` ·
`hoehe_gebunden` tief→flach **kappt** · `max_fuellgewicht_kg` schneidet eine Variante ·
Zweck ohne Eignung → Grund · durchgängiger Behälter zählt **einmal** · Träger-Überlauf.
Gegen den echten Vokabular-Bestand prüfen, keine erfundene Fixture.

**Kaskade:** Basisrezept mit Regenerationszeile → in zwei Gerichten → beide `geerbt`; Wert am
Basisrezept ändern → beide folgen; in einem übersteuern → nur dieses weicht ab ·
**Fond-in-Ragout erscheint nicht** als Regenerationszeile im Gericht (Tiefe 1) ·
Gesamt-Zeile (Rang 0) steht neben den Komponenten · Tausch löscht/markiert den Override ·
soft-gelöschte Zutat → Lücke, kein Crash.

**Produktion end-to-end:** Auftrag mit bekanntem `yield_kg`, von Hand nachrechnen; Gegenprobe mit
`buffer_pct` (skaliert **vor** der Explosion) ·
**Sauce in 3 Gerichten → 1 Abfüll-Bedarf, 3 Regenerier-Anteile** ·
gewählte Variante überlebt Recompute ·
Abnahmefälle aus dem Gespräch: 2 kg → kleiner Einsatz · 10 kg → zwei flache statt einer tiefen ·
40 l Suppe → Eimer beim Abfüllen, GN beim Regenerieren.

**Einstellungen:** neuen Typ anlegen („Eimer 10 l", Eignung abfüllen + transport), am Rezept
auswählen, im Auftrag wiederfinden · `regenerieren`-Eignung entziehen → Lücke gemeldet, nicht stumm
weitergerechnet · **Löschen bei Nutzung blockiert** · Kind-Team darf denselben Slug wie ein
Sibling anlegen.

**Wandmonitor:** Regeneration kommt nach §3.4 nur noch aus `regen_snapshot` — kein Doppel.

**Druck:** Produktionsschein und Postenzettel ziehen; Kalkulations-Profil zeigt die Behälterzeile
nicht.

**Migration:** `--dry-run` gegen den Echtbestand, Review-Liste durchsehen, dann `--apply`, `--verify`.

**Suite:** `./fa_test.sh` (~35 Min / ~3.700 Tests, eigene Sandbox, nur ein Lauf gleichzeitig).

**Browser-Abnahme** auf dem Dev-Server — der Test-Harness ist layout-blind: Livewire-Tests werden
grün, während die Seite 500 wirft.
