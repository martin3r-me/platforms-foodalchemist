# Spec 49 — Allergen-Kaskade: Konfidenz deckelt statt zu löschen, live statt materialisiert

> **Tracking:** Office Dev-Package 23, Features-Board. Bugfix + Regelwerks-Nachzug, **kein DB-Schema**, keine Migration. Zwei Services (`RecipeRecomputeService`, `GpAggregateService`), ein Test. Berührt die Deklarations-Ausgabe aller Rezepte und Gerichte. Verwandt: **Spec 36** (Rezeptqualität — Matching-Achse), **Spec 43** (Konformitäts-Critic).

**Status:** ✅ Code + Tests fertig auf `fix/allergen-kaskade-live-konfidenz`. Regelwerk nachgezogen (Vault v1.7 + Wissensmodul-Dossier v2). **Nicht deployt** — Massen-Recompute bewusst zurückgestellt, siehe *Offen*.

---

## Anlass

Im Gericht `[HG] Rinderfilet | Kartoffel-Baumkuchen | Kürbiscreme | Brokkolini | Malz-Rinderjus` (demo, `recipe_id 3727`) waren im Deklarations-Tab **alle 14 EU-Allergene und alle 18 LMIV-Zusatzstoffe leer** — obwohl vier der sieben Komponenten Gluten, Milch und Eier als `enthalten` führen. Der Recompute war gelaufen (`allergens_aggregated_at` gesetzt); er hat sein Ergebnis verworfen.

## Zwei Ursachen, beide gegen das Regelwerk

**1. Ein dritter Löschgrund, den `Regelwerk_Basisrezepte` §7 nie gesetzt hat.**
`RecipeRecomputeService::allergene()` setzte alle 14 Felder auf `unbekannt`, sobald *irgendein* Sub-Rezept die Konfidenz `low` trug:

```php
if ($this->hatUngemappteRelevante($zutaten)
    || $this->subKonfidenzRang($zutaten, 'allergens_confidence') <= self::KONF_RANG['low']) {
```

§7 kennt genau zwei Löschgründe: **F7.1** (ungemappte Pflicht-Zutat) und **F7.3** (Stub). F7.4 sagt für den analogen GP-Fall ausdrücklich das Gegenteil — bei NULL-Konfidenz „kommen die *Werte* trotzdem aus dem GP". Identisch in `zusatzstoffe()`. Eingeführt mit `d051c449` (2026-07-12) als Beifang der korrekten Konfidenz-Rekursion.

**2. Die Konfidenz kam aus einer Spalte, die niemand füllt.**
`gpKonfidenzRang()` las `gps.allergens_confidence` (`null → low`). Diese Spalte befüllt allein `foodalchemist:gp-allergen-backfill`, der in **keinem Scheduler** steht: **7.924 von 7.956 GPs waren NULL** (Messung 2026-09-04). Damit fiel praktisch jedes Rezept auf `low` und entkernte über (1) jedes Gericht darüber. Regression seit `98aee3f6` (2026-08-01, Umstellung der Konfidenz-Quelle von der Match-Methode auf diese Spalte).

Die **Werte**-Kaskade war die ganze Zeit intakt und live: LA → GP on-read (`GpAggregateService::allergene()`), GP → Rezept → Gericht topologisch mit BFS-Propagation. Nur die Konfidenz brach aus dem Muster aus — obwohl die Live-Variante `allergenKonfidenz()` direkt daneben lag und ungenutzt war.

### Wirkung im Bestand (demo, Team 6, Stand 2026-09-04)

| Kennzahl | Wert |
|---|---|
| Rezepte gesamt | 3.539 |
| davon alle 14 Allergene `unbekannt` **trotz** gesetztem Aggregations-Zeitstempel | **456** |
| Konfidenz-Verteilung | medium 2.602 · low 686 · high 225 · unknown 26 |
| letzte `medium`-Aggregation | **2026-08-26** — alles danach Gerechnete fiel auf `low` |

## Änderung

**`GpAggregateService`** — neue Konstante `ALLERGEN_KONF_RANG = ['high'=>3,'medium'=>2,'low'=>1,'none'=>1]` als Single Source für die Rezept-Ebene. `none` (kein LA-Allergenprofil) bewusst auf `low`: der GP trägt in `allergene()` ohnehin nichts bei (`source = 'keine'`), darf die Konfidenz aber nicht schönen.

**`RecipeRecomputeService`**
- `gpKonfidenzRang()` liest jetzt `$this->gpAggregate->allergenKonfidenz($gp)['confidence']` statt der Spalte. Die Decimal-Schwellen (≥0.85/≥0.50) entfallen — die Tier-Entscheidung trifft `allergenKonfidenz()` bereits nach GL-01 §4.5.
- Der `subKonfidenzRang <= low`-Zweig ist in `allergene()` **und** `zusatzstoffe()` entfernt. `hatUngemappteRelevante()` (F7.1) bleibt unverändert.
- **Unverändert:** `subKonfidenzRang()` selbst, sein Einsatz in `yieldUndZaehler()` (= F7.4 (c), korrekt) und in `spec_confidence()` (andere Achse).

Ein unsicheres Sub deckelt danach weiterhin die Konfidenz des Eltern auf `low` — es löscht nur nicht mehr dessen Werte.

## Regelwerks-Nachzug

`Regelwerk_Basisrezepte.md` v1.6 → **v1.7** (Vault) und Wissensmodul-Dossier `regelwerk-basisrezepte-7-allergen-zusatzstoff-vererbung` → **Version 2** (SSOT, war auf dem Stand vor dem 31.07. — ohne die v1.6-Verfeinerung und ohne F7.4, d.h. der Generator groundete auf einer veralteten §7):

- **F7.5** — Eine niedrige Konfidenz verwirft KEINE Allergen-Werte. Löschgründe abschließend F7.1 + F7.3. Gilt identisch für die 18 Zusatzstoffe.
- **F7.6** — Die GP-Allergen-Konfidenz wird LIVE aus dem LA-Profil berechnet, nicht aus `gps.allergens_confidence`. Die Spalte bleibt als optionaler Backfill-Cache für Signale/Reports.

## Verifikation

- **58 Tests grün**: `SubRecipeStubTest` (inkl. neuem Fall *#2b §7 F7.5*), `GpAllergenBackfillTest`, `DataQualityTest`, `RezeptQualitaetSignaleTest`, `ConcepterAggregateTest`, `SpeisekarteKennzeichnungTest`, `SpeiseplanGvKennzeichnungTest`, `EkPreisBasisGoldenTest`.
- **Gegenprobe auf echten Daten:** die 28 GPs der sieben Komponenten von 3727 liefern live **23× `high`, 1× `low`, 4× `none`** — die Konfidenz ist also aussagekräftig, sobald sie live gerechnet wird.
- **Simulation der neuen Logik auf demo** (read-only): 3727 bekäme Gluten/Milch/Eier `enthalten`, Schalenfrüchte `spuren`, Konfidenz `low`.

## Offen — bewusst getrennt

**Der Fix legt die LA-Datenqualität offen.** In der Simulation meldet 3727 **8 von 14** Allergenen als `enthalten`, inklusive Sellerie, Senf, Sesam und Soja. Verursacher sind zwei Artikel:

| LA | am GP | meldet |
|---|---|---|
| `#432166` „37G MUSKATNUSS GEMAHLEN" | Muskatnuss | Gluten, Sellerie, Senf, Sesam, Soja, Milch, Eier — fehlerhafte Quellzeile |
| `#265219` „Butter-Croissant 55g tk" | **Butter: frisch, 250 g** (316 Rezeptzeilen) | Gluten `enthalten` + Sesam/Soja/Nüsse `spuren` — Fehlzuordnung |

Systematisch erfasst: von **2.442** in Rezepten verwendeten GPs liefern **1.797 (74 %) live `high`**; **137** haben einen echten LA-Konflikt (`needs_review`), zusammen 1.080 Rezeptzeilen — davon **13 klare Fehlzuordnungen**. Review-Liste mit Ursachen-Heuristik: `00_INBOX/_GP_Allergen_Konflikte_Review_2026-09-04.md` (Vault).

Diese Konflikte sind **nicht neu** — sie waren durch den Totalreset nur verdeckt.

**Deshalb zurückgestellt:** Deploy auf demo und `foodalchemist:recompute --all --apply` (off-peak). Sinnvolle Reihenfolge: Fix deployen → die Top-Fehlzuordnungen lösen (Butter/Milch decken über 500 Rezeptzeilen) → dann Massen-Recompute.

## Nebenbefunde

- **UI:** `deklaration.blade.php` unterscheidet `unbekannt` und `nicht_enthalten` nur im `title`-Tooltip (beide `text-gray-500`). Der Hinweis, den die GP-Ebene bereits führt (`gps/detail-panel.blade.php:295` — „unbekannt ist NICHT gleich frei von"), fehlt auf Rezeptebene.
- **Latente Fehlaussage:** `deklaration-summary.blade.php:19` schreibt „keine der 14 EU-Allergene enthalten", wenn kein Feld `enthalten` ist — auch bei durchgängig `unbekannt`. Das Partial ist aktuell **nirgends eingebunden**.
- **Stale-Pfad:** `SupplierItemService::setAllergens()` schreibt die LA-Zeile ohne anschließenden Recompute. Der GP heilt on-read, die Rezept-Spalten bleiben stale; ein Wechsel `nicht_enthalten → enthalten` fällt auch durch den Detektor `rezept_allergen_unbelastbar`.
