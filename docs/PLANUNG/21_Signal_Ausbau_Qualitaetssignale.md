# Spec 21 — Signal-Ausbau: Inhalts-Qualitätssignale + Zeitreihe + Signal-Panel

> **Anlass (Dominique 2026-07-25):** „In Bezug auf Rezeptur-Ebene und Gerichte nochmal neue Signale — oder prüfen ob wir die haben. Rezeptvollständigkeit, Rezept-Qualität, Bezeichnung, ob das Rezept an sich korrekt aussieht. Diese Signale werden benötigt, um das System ständig im Blick zu behalten. Kann man auf Concept und Foodbook ausweiten." + „Überlege was wir ins rechte Panel einbauen könnten, das ist aktuell nicht genutzt."
>
> **Datenverbesserung selbst (Spec 05 Etappe 2) bleibt bewusst zuletzt.** Diese Spec baut die *Messung*, nicht die Heilung — man muss erst sehen, wie schlecht die Basis ist, bevor LLM-Budget ins Heilen geht.

---

## 1. Ist-Stand (Audit an HEAD `a55ced3`, 2026-07-25)

**14 Signal-Typen** (`src/Enums/SignalTyp.php`), gefüttert aus zwei Quellen:
- **`DataQualityService`** (372 Z.) — 14 Ebenen-Gaps: `la_needs_review` · `gp_ohne_lead` · `gp_lead_ohne_preis` · `gp_allergen_konfidenz` · `gp_anker_fehlt` · `gp_tentative_genutzt` · `br_ek_null` · `br_ek_teil` · `br_anker_fehlt` · `vk_ek_null` · `vk_ek_teil` · `vk_anker_fehlt` · `vk_servierform_unbestimmt` · `ri_gemini_unverifiziert`
- **`SignalDetektorService`** (781 Z.) — 10 Detektoren: `preisSprungMargeImpact` · `naehrwertPlausi` · `datenqualitaetGpLa` · `veraltetePreise` · `preisAnomalie` · `margeUnterZiel` · `wareneinsatzUeberZiel` · `vkAnpassungEmpfohlen` · `widerspruchWissenGraph` · `vertragsfristFaellig`
- **Fixer-Verdrahtung** `src/Support/SignalCockpit.php`: `FIX_DET` (deterministisch: `allergen`/`lead_la`/`recipe_anker`/`gp_anker`/`recompute`) + `ASSIST` (LLM-Prompt je Typ) + `PLAN_DET`/`PLAN_ASSIST` (Erklärtext vor dem Klick)

### Die Lücke — präzise
Auf **Rezept-Ebene** prüft das System genau **drei** Dinge: EK-Kette, Flavor-Anker, Servierform. Alles übrige ist Geld oder GP/LA-Integrität.

**Nicht geprüft:** ob eine Zubereitung existiert · ob Mengen plausibel sind · ob der Name dem Regelwerk folgt · ob es überhaupt ein Rezept ist (statt eines GP) · ob Zutaten zum Namen passen.
**Konzept-Ebene: 0 Signale. Foodbook-Ebene: 0 Signale.** (`sortiments_luecke` aus Spec 19 ist ein Einkaufs-, kein Qualitätssignal.)

---

## 2. Tranche A — Rezeptur deterministisch · 0-Egress · je 1 SQL-Check

Alle auf verifizierten Spalten. Ziel-Severity in Klammern.

| Signal-Typ | Prüfung (konkret) | Beleg / Regel |
|---|---|---|
| `rezept_ohne_zubereitung` (kritisch) | `preparation IS NULL OR LENGTH(TRIM(preparation)) < 20`, wo `status != draft`. Fallback-Hinweis wenn `excel_raw_preparation` gefüllt ist (Import nie übernommen) | ohne Zubereitung nicht produzierbar |
| `rezept_mengen_luecke` (kritisch) | Rezepte mit ≥1 `recipe_ingredients.quantity = 0` (Spalte ist NOT NULL → 0 ist der Marker) | Skript 272 hat 239 KI-geschätzte 0-Mengen markiert |
| `rezept_yield_implausibel` (warnung) | `yield_kg IS NULL` bei ≥1 Zutat **oder** `yield_kg > SUM(quantity)` (physikalisch unmöglich) **oder** `yield_kg < 0,3 × SUM(quantity)` (>70 % Verlust = Verdacht) | Regelwerk_Basisrezepte §6 (Auto-Sum + Verlust-Faktor) |
| `rezept_ein_zutat` (warnung) | `n_ingredients_total <= 1`, wo `status != draft` | §10 Anti-Pattern: 1-Zutat-„Rezept" ist meist ein GP oder Fehl-Import |
| `rezept_naming_regelwerk` (warnung) | Deterministisch prüfbare Verstöße: Grammatur/Einheit im Namen, doppelte Leerzeichen, führende/schließende Trenner, Pipe-Konvention bei VK (Modell A), Verpackungsangaben | Regelwerk_Basisrezepte §1 · VK-Taxonomie Modell A |
| `rezept_dublette` (warnung) | normalisierter Name (lower, Satzzeichen/Mehrfach-Space weg) ≥2× je Team/Kategorie | 267er-Skript traf UNIQUE(name,kategorie)-Kollisionen |
| `rezept_kategorie_problem` (warnung) | `kategorie_id IS NULL` **oder** zeigt auf eine `is_inactive`-Hauptgruppe | 269er-Taxonomie-Neutralisierung (APE/SNK/ALC/BVK/ALL stillgelegt) |
| `rezept_allergen_unbelastbar` (kritisch) | `allergens_confidence = 'unknown'` **oder** ≥1 `allergen_* = 'unbekannt'`, wo das Rezept in einem VK-Gericht/Konzept hängt | GL-01 §4.4; heute nur auf **GP**-Ebene geprüft, die Durchreichung aufs Rezept fehlt |
| `rezept_zutaten_ungemappt` (warnung) | `n_ingredients_unmapped > 0` | Spalte existiert, wird aber nicht als Signal geführt (nur „Ungemappte Zutaten" in der Pflege-Liste) |
| `rezept_sub_stub_offen` (warnung) | Rezept ist Auto-Stub (`status=draft` + 0 Zutaten + wird per `recipe_ref` referenziert) | Regelwerk §4 Sub-Rezept-Hierarchie |
| `rezept_verwaist` (info) | `status != draft`, in keinem VK-Gericht/Konzept/Foodbook referenziert, seit >180 d unverändert | Pflege-Kandidat |

**Fixer-Anbindung:** `rezept_zutaten_ungemappt` → bestehender Match-Pfad · `rezept_kategorie_problem` + `rezept_naming_regelwerk` → KI-Assist (Vorschlag, nie Auto-Apply) · Rest = menschliche Entscheidung mit Direktsprung aus dem Panel.

## 3. Tranche B — Rezeptur mit KI-Urteil · Egress · **erst nach L6**

| Signal-Typ | Prüfung |
|---|---|
| `rezept_plausi_ki` | Batch-Lauf des **L6-Copilot** über den Bestand: fehlende Schlüsselkomponenten (Säure/Salz/Fett/Bindung), Mengenverhältnisse grob falsch, **Zutat passt nicht zum Namen**, Zubereitung widerspricht Zutaten. Findings mit `confidence ≥ Schwelle` werden Signale (nicht jedes Finding!) |
| `rezept_gericht_vs_komponente` | Klassifikations-Zweifel nach Bauart-Logik (269er): ist das ein Gericht oder eine Komponente? |

> **Kopplung (wichtig):** Tranche B ist **kein Zweitbau** — sie ist der Batch-Konsument von L6 (`RecipeReviewService`). L6 baut den Findings-Pass fürs Modal; B lässt ihn über den Bestand laufen und schreibt Signale. **Reihenfolge: L6 → B.** Der bekannte Ankerfall ist Pfefferkörner→Pfefferrahm-Sauce (Skript 215: 457 Fehler gefunden, 376 gefixt) — genau diese Fehlerklasse fängt B künftig laufend statt einmalig.

## 4. Tranche C — Konzept-Ebene (heute 0 Signale)

| Signal-Typ | Prüfung | Reuse |
|---|---|---|
| `konzept_slot_luecke` | Slots unbesetzt / `target_count` nicht erreicht | `CoverageService::coverage` liefert die Ampel bereits — Signal ist nur Persistierung |
| `konzept_regel_verletzt` | Diät-Quote / No-Go-Zutat / No-Go-Allergen / Allergen-Linie verletzt | `PlanningFrameService` `RULE_TYPES` |
| `konzept_preisband_verletzt` | Preis pro Person außerhalb `price_min/max_pp` bzw. `target_price_pp` verfehlt | PlanningFrame-Head |
| `konzept_dramaturgie` (info) | gleiche Hauptzutat/Protein in mehreren Gängen; kein Kontrast über den Verlauf | Anker-Graph `cohesion` (CLI 232 hat die Logik) |
| `konzept_ohne_wording` | kundenfähige Bezeichnung/Intro fehlt (WordingResolver-Kette leer) | Wording-Kette |

## 5. Tranche D — Foodbook-Ebene (heute 0 Signale)

| Signal-Typ | Prüfung | Abhängigkeit |
|---|---|---|
| `foodbook_kapitel_leer` | Kapitel ohne Paket-Konzept **und** ohne Einzel-`recipe_ref`-Block | Spec 19 Duality |
| `foodbook_kapitel_ohne_text` | Kapitel ohne Kundentext | **braucht L2** (Kapitel-Text-KI) — sonst Signal ohne Fixer |
| `foodbook_ziel_verfehlt` | Kapitel-Ziele (Ziel-Vererbung n-tief) nicht erfüllt | Spec 19 |
| `foodbook_stale` | Foodbook freigegeben, aber enthaltene Gerichte/Preise haben sich seit dem Snapshot geändert | Stale-Marker-Muster aus Spec 20 · R2.5-Snapshot-Layer |
| `foodbook_skizze_ungeerdet` | `dish_ideas` mit `generation_status='queued'`, die nach dem Kapitel-Go nie materialisiert wurden | Spec 19 E7.4 |

## 6. Tranche E — Zeitreihe + Rausch-Guard · **Pflicht, nicht optional**

> **Begründung (Einwand aus der Session):** Mehr Signal-Typen ohne diese Tranche machen das Cockpit *schlechter*. Heute ist die Ampel eine Momentaufnahme; „ständig im Blick behalten" braucht Trend. Und: 3.138 „Im Review" bzw. 219/788 teil-unbepreist sind kein *Signal*, sondern ein *Zustand* — was chronisch vierstellig zählt, stumpft ab. Mit A+C+D kämen ~900 offene Signale statt 252; ohne Guard schaut man genauso weg.

- **E1 · Snapshot-Zeitreihe:** neue Tabelle `foodalchemist_signal_snapshots` (`team_id`, `type`/`gap_key`, `count`, `severity_counts` json, `measured_at`). Jeder Detektor-Lauf schreibt eine Zeile. → Trend je Typ, Drift-Erkennung („340 → 290 → 252"), Delta seit letztem Lauf.
- **E2 · Rausch-Guard:** Policy je Typ (`foodalchemist_signal_policies`: `type`, `threshold`, `accepted_until`, `note`, `muted`). Ein Typ über Schwelle wird als **aggregierte Zustands-Zeile** geführt („788 Basisrezepte teil-unbepreist — bekannt, akzeptiert bis TT.MM"), nicht als 788 Einzel-Alarme. Neu hinzukommende Fälle bleiben trotzdem sichtbar (Delta-Alarm statt Absolut-Alarm).
- **E3 · Verschlechterungs-Signal:** Meta-Signal `qualitaet_drift` wenn ein Typ gegenüber dem Vorlauf um >X % steigt. Das ist der eigentliche „System im Blick"-Mechanismus — es alarmiert bei *Veränderung*, nicht bei Bestand.

## 7. Tranche P — Signal-Detail-Panel (rechtes Panel, heute ungenutzt)

**Ist-Stand:** 6 Seiten haben ein `DetailPanel` (`Gps`, `Recipes`, `Verkauf`, `Concepter`, `Angebote`, `Produktion`) — **Signale nicht.** Die Signale-Seite (`src/Livewire/ReviewQueue.php`, 5-Tab-Cockpit) nutzt die rechte Fläche gar nicht; Aktionen sind heute „Reinschauen / KI erledigen lassen / Erledigt / Ignorieren" in der Zeile.

Panel-Inhalt (Muster + Design von `Recipes/DetailPanel` + Cockpit/section aus Detail-Panels-v3 übernehmen, Öffnen via `dispatch('signal-selected', id:)`):

1. **Betroffene Objekte mit Direktsprung** — vollständige, sortierbare Liste (heute inline auf 50 begrenzt); Klick öffnet Rezept-/GP-/Konzept-Modal. Der größte Hebel: vom Signal direkt in die Reparatur, ohne Suchen.
2. **Objekt-zentrische Sicht — „was hat dieses Rezept noch?"** Ein Rezept kann gleichzeitig `ek_teil` + `anker_fehlt` + `mengen_luecke` haben. Das Panel zeigt alle offenen Signale **am selben Objekt** → man fixt es **einmal** richtig statt dreimal einzeln. *Dreht das Arbeitsmodell von signal-zentrisch auf objekt-zentrisch — der eigentliche Effizienzgewinn.*
3. **Fix-Vorschau (Dry-Run)** — `PLAN_DET` erklärt heute nur *was* der Fixer tun würde; das Panel zeigt **konkret**: „n Objekte, diese Felder, diese Werte" **vor** dem Klick. Heute drückt man „KI erledigen lassen" blind.
4. **Trend-Sparkline** — Verlauf aus E1 genau dort, wo man hinschaut.
5. **Ursachen-Kette nach unten** — bei `br_ek_teil`: welche GPs unbepreist → welche Lead-LAs fehlen. Bei Regelwerk-Signalen: Deep-Link auf das verletzte §.
6. **Historie/Audit** — erstmals gesehen, wie oft wiedergekehrt, wer wann mit welcher Begründung ignoriert. Verhindert stilles Wieder-Auftauchen.
7. **Teil-Bulk** — Checkboxen auf den betroffenen Objekten: „diese 12 fixen" statt alles-oder-nichts.
8. **Policy-Regler** — Schwelle justieren / „akzeptiert bis" setzen (E2) direkt im Panel.

---

## 8. Etappen

| # | Etappe | Größe | Inhalt |
|---|---|---|---|
| **S0** | Fundament | S | `SignalTyp`-Enum erweitern (Label+Icon je neuem Typ), `DataQualityService` um eine Rezept-Sektion erweitern (Muster der bestehenden `gap()`-Aufrufe), Pest-Fixture-Basis |
| **S1** | Tranche A | M | 11 deterministische Rezept-Checks + Fixer-/Assist-Mapping in `SignalCockpit`; Pest je Check mit Positiv- **und** Negativfall (kein Über-Flaggen) |
| **S2** | Tranche E | M | Snapshot-Tabelle + Policy-Tabelle + Drift-Meta-Signal; Detektor-Lauf schreibt Zeitreihe; Aggregations-Darstellung statt Einzel-Alarm |
| **S3** | Tranche P | M–L | `Signale/DetailPanel` + `signal-selected`-Event; Punkte 1–8 (2 und 3 sind die Kern-Features, nicht optional) |
| **S4** | Tranche C+D | M | Konzept- + Foodbook-Signale; `foodbook_kapitel_ohne_text` **erst nach L2** scharfstellen |
| **S5** | Tranche B | S–M | Batch-Konsument von L6 (`RecipeReviewService`) → Findings über Schwelle werden Signale; **erst nach L6** |

**MCP-Lockstep:** `SignaleListTool` existiert → mit jedem neuen Typ mitziehen; für E einen read-only `signal_trend.GET` (Zeitreihe je Typ) ergänzen; Policy-Setzen als expliziter, menschlich getriggerter Call.

## 9. Bewusste Nicht-Ziele

- **Kein Auto-Fix.** Signale schlagen vor, der Mensch entscheidet (globale DoD). Deterministische Fixer bleiben explizit angestoßen.
- **Keine Heilung in dieser Spec.** Die Massen-Datenverbesserung ist Spec 05 Etappe 2 und bleibt bewusst **zuletzt** — hier wird nur gemessen und sichtbar gemacht.
- **Kein Signal ohne Fixer-Weg oder klare Entscheidung.** Ein Signal, das niemand auflösen kann, ist Rauschen (`foodbook_kapitel_ohne_text` wartet deshalb auf L2).
- **Kein LLM in Tranche A.** Deterministisch bleibt deterministisch (0-Egress-Prinzip aus Spec 05 Etappe 1).

---

*Erstellt 2026-07-25 nach Ist-Audit an HEAD `a55ced3`. Verzahnt: [05](05_Datenqualitaets_Kaskade.md) (Heilung, danach) · [03](03_KI_Erstell_Flaechen_512.md) L2 (Fixer für `foodbook_kapitel_ohne_text`) + L6 (Erzeuger für Tranche B) · [12](12_Wirtschaftlichkeits_Intelligenz_R2-Rest.md) R2.5-Snapshot (für `foodbook_stale`) · [19](19_Foodbook_Leitstelle_A-Z.md) (Kapitel-Ziele, `dish_ideas`). Bauvorrat: [_Fahrplan_Routine_Umsetzung.md](_Fahrplan_Routine_Umsetzung.md).*
