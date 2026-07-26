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

> **Stand 2026-07-26 · E2+E3 gebaut** (Etappe S2b, `foodalchemist_signal_policies` + `SignalPolicyService` + Detektor-Schritt `qualitaetsDrift()` + MCP `signal_policies.GET`/`signal_policy.PUT` + Zustands-Zeilen auf der Signale-Seite). Die tragende Auslegung: **der Guard dämpft die Darstellung, nie den Befund** — die Einzel-Signale bleiben vollständig in der Tabelle und sind über den Typ-Filter aufklappbar (`SignalService::paginate` blendet aggregierte Typen nur in der ungefilterten Ansicht aus). Ein Guard, der Signale gar nicht erst entstehen lässt, hätte die Zahl geschönt und dem Detail-Panel (S3, Punkte 1/2/7) die Objekte weggenommen. Die drei Regler sind getrennt, weil sie drei Aussagen sind: `threshold` = Darstellung, `accepted_until` = bewusste Akzeptanz mit Ablauf (danach automatisch wieder Alarm, ohne dass jemand die Policy anfasst), `muted` = „interessiert nicht" und **als einziger** auch drift-wirksam. Damit gilt: eine akzeptierte Lage darf sich nicht heimlich vergrößern. E3 alarmiert nur bei ≥ 20 % **und** ≥ 5 absolut (sonst wäre 1 → 2 ein „+100 %"-Alarm); Ausnahme ist das **Neuauftreten** 0 → n, das unabhängig von der Menge zählt (ein behobener Befund, der zurückkommt, ist die stärkste Aussage der Reihe). Wird eine Lage doppelt gemessen (Ampel-Metrik *und* offene Signale desselben Typs), gewinnt die Ampel-Seite — sonst zwei Drift-Signale für einen Sachverhalt. Das Drift-Signal trägt bewusst **kein** `metrik` im Payload und damit keinen Fix-Knopf: repariert wird am zugrundeliegenden Befund, nicht an der Trend-Zeile.
>
> **Stand 2026-07-26 · E1 gebaut** (Etappe S2a, `SignalTrendService` + `foodalchemist_signal_snapshots` + MCP `signal_trend.GET`). Zwei Abweichungen von der Spalten-Skizze oben, beide im Code begründet: die Tabelle führt **`metric_key` + `source` + `signal_type`** statt „`type`/`gap_key`" (derselbe Schlüsselraum trägt zwei Bedeutungen — SignalTyp-Wert vs. Ampel-Metrik-Key — und ein Trend-Reader darf das nicht raten müssen), und `severity_counts` wird nur für die Signal-Seite gefüllt (eine Ampel-Metrik hat genau *eine* Severity). Zwei Eigenschaften sind **Voraussetzung für E3** und dürfen dort nicht wegoptimiert werden: die Reihe ist **dicht** (auch Nullen → „behoben" ≠ „nicht gemessen", `previous=null` bei neuen Checks alarmiert nicht) und **ein Lauf = ein `measured_at`** (Basis des Vorlauf-Vergleichs). Geschrieben wird im Detektor-Lauf (`SignalDetektorService::laufen()`), weil der Scheduler diesen Command fährt. E2+E3 folgen zusammen als S2b — die Policy entscheidet, ob ein Drift überhaupt alarmiert (`muted`/`threshold`), getrennt gebaut würde Drift zweimal geschrieben.

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

> **Stand 2026-07-26 · Punkt 5 gebaut — Tranche P damit abgeschlossen** (Etappe S3b-3, `SignalCauseService` + `DataQualityService::namingBefundeFuer()` + Ursachen-Block im aufgeklappten Objekt + MCP `signal_causes.GET`). Die tragende Auslegung: **die Kette gehört dem OBJEKT, nicht dem Signal.** Ein Aggregat wie `br_ek_teil` deckt n Rezepte ab — „welche GPs sind unbepreist" hat nur je Rezept eine Antwort; darum steht die Kette in derselben aufgeklappten Fläche wie die objekt-zentrische Sicht aus S3a (ein Klick = „was hat dieses Objekt noch" **und** „warum"), und zugeklappt wird sie gar nicht erst gerechnet. Zweitens: **welche Zeile unbepreist ist, entscheidet weiter allein `RecipeRecomputeService::zeilenKosten()`** — dieselbe T3-Kaskade, die `ek_n_ingredients_priced` füllt; eine eigene Nachbildung wäre die zweite Wahrheit, an der die Erklärung von der Zahl abdriftet. Dieser Service diagnostiziert nur die **Beschaffungs-Lage** des GP (LAs vorhanden? bepreist? Lead gesetzt?) — eine andere Frage als „was kostet er". Drittens, und beim Bau als Korrektur an der eigenen Annahme aufgefallen: **ein fehlender Lead-LA bricht die EK-Kette NICHT** (die Kaskade mittelt über die aktiven bepreisten LAs), preis-blockierend sind nur „kein LA" und „kein bepreister LA". Die Kette behauptet deshalb nie „kein Lead-LA" als EK-Ursache, sondern hängt die Lead-Frage als Nebenbefund an — ein Test hält das fest, und die Beobachtung dahinter (ein „bepreistes" Rezept kann still auf einem Lieferanten-Durchschnitt stehen) ist als **V-014** notiert. Viertens: der §-Deep-Link ist **echt und geguardet** — die Regelwerke liegen als `regelwerk.*`-Dokumente im Wissens-Modul, die ID wird über den Slug zur Laufzeit aufgelöst (umgebungsabhängig), und ohne Dokument bzw. ohne registrierte Route bleibt der §-Text ohne Link stehen statt ins Leere zu zeigen.
>
> **Stand 2026-07-26 · Punkte 8+4 gebaut** (Etappe S3b-2, `SignalPolicyService::zustandFuer()` + Policy-Formular und Sparkline im Panel). **Punkt 5 (Ursachen-Kette) ist NICHT dabei** und wandert nach S3b-3 — 8 und 4 sind „Service da, Fläche fehlt", 5 ist neue Auflöse-Logik (GP→Lead-LA-Kette + §-Deep-Links) und passt nicht in denselben Tick. Die tragende Auslegung bei Punkt 8: **der Regler gilt für den TYP, nicht für das Signal, aus dem er gestellt wurde** — technisch war das durch `signal_policies.type` vorgegeben, aber eine Fläche am Einzelfall liest sich als „diesen Befund akzeptieren"; deshalb sagt der Formularkopf es ausdrücklich, ein Test hält es fest (Regler an Signal A gestellt, an Signal B desselben Typs geprüft), und beim Signalwechsel schließt das Formular, damit keine Werte aus Typ A auf Typ B geschrieben werden. Zweitens: **aufgeklappt werden die *wirksamen* Werte geladen, nicht die eigenen** — ein Kind-Team sieht die geerbte Eltern-Entscheidung im Formular, sonst würde „Speichern" sie auf leer zurücksetzen; „Regler entfernen" erscheint nur bei eigener Zeile und sagt sonst, dass die geerbte bestehen bleibt. Drittens: **die Sparkline zeichnet erst ab zwei Messpunkten** — eine Waagerechte aus einem Punkt behauptet eine Stabilität, die nie gemessen wurde (bei flacher Reihe läuft die Linie mittig, nicht am Rand). Gelesen wird die **Signal-Seite** der Reihe (`source=signals`, Schlüssel = Signal-Typ), womit **V-010 hier by construction nicht greift**: rohe DQ-Metrik-Keys kommen im Panel nie vor. Offen in S3b-3: Punkt 5.
>
> **Stand 2026-07-26 · Punkte 3+7 gebaut** (Etappe S3b-1, `SignalFixService::execute($team,$sig,$ids)` + `vorschau()` + Checkboxen/Dry-Run im Panel + `object_ids`/`dry_run` am MCP-Tool). Die tragende Auslegung: **die Teilmenge ist eine Einschränkung, keine zweite Autorisierung.** Jede Auswahl wird gegen `betroffene()` geschnitten — eine ID, die das Metrik-Prädikat nicht (mehr) trifft, wird nie angefasst; das Panel bleibt damit ein Filter auf den Detektor-Befund und wird nicht zur freien Schreib-Schnittstelle. Fix und Vorschau ziehen aus demselben `satz()`, sonst würde die Vorschau eine andere Menge beschreiben als der Knopf darunter bearbeitet. Zweitens: **der Dry-Run ist ein Zwilling im selben Service**, nicht eine eigene Klasse — je Fixer eine rein lesende Spiegelung (`apply=false` beim Allergen-Backfill, `pickLeadLa` ohne `setLeadLa`, `resolveRecipeAnchors`/`resolveByName` ohne Mapping-Write, `betroffeneRezepte` statt Recompute); eine Vorschau, die anderswo lebt, driftet vom Fixer weg und lügt dann. Drittens: **`recompute` sagt ehrlich, was es nicht weiß** — Zielwerte einer EK-Kette kennt man ohne Lauf nicht, darum zeigt die Vorschau dort die exakt vorhersagbare Größe (Kaskade: n Rezepte) und benennt den Rest als offen, statt eine Zahl zu erfinden. Viertens: **die Zähler „x würden geändert" gelten nur für die aufgeschlüsselten Objekte** (Kappung `VORSCHAU_LIMIT = 25`) und sagen das auch — jedes Objekt kostet eine Auflöse-Frage, das über tausende Zeilen zu fahren wäre so teuer wie der Fix selbst. Der Panel-Knopf ist bewusst nur der **auf die Auswahl geschnittene** Fix; „alles fixen" bleibt in der Signal-Zeile (anderer Scope, nicht dieselbe Aktion zweimal). Offen in S3b-2: Punkte 8/4/5.
>
> **Stand 2026-07-26 · Punkte 1+2 gebaut** (Etappe S3a, `Signale/DetailPanel` + `SignalObjectService` + `DataQualityService::trifftObjekt()`). Die tragende Auslegung: **Punkt 2 wird rückwärts gefragt.** Nicht „alle Trefferlisten laden und schneiden" (das wären bei den vierstelligen Typen Zehntausende Zeilen für die Frage nach einem Rezept), sondern ein `whereKey(...)->exists()` je Metrik gegen genau dieses Objekt — auf demselben `queryFor`-Prädikat, das Ampel, Liste und Fixer-Lifecycle schon teilen. Zweitens: die Objekt-Liste ist eine **Live-Auflösung** und ihr `total` kommt aus `countFor()`, nicht aus `payload.anzahl` — sonst zeigt das Panel den Stand des letzten Detektor-Laufs, obwohl gerade gefixt wurde. Drittens: die alte 50er-Inline-Liste unter der Signal-Zeile ist **abgelöst** (`toggleDetail`/`betroffeneFuer` aus der `ReviewQueue` entfernt), und das Panel bekommt bewusst **keine** eigenen Lifecycle-/KI-Knöpfe — zwei Sätze derselben Knöpfe wären eine zweite Wahrheit; der KI-Plan erscheint nur als Erklärtext. Offen in S3b: Punkte 3+7 hängen an einem **Teilmengen-Pfad** im Fixer (`SignalFixService`/`SignalFixJob` kennen heute nur den vollen betroffenen Satz) — das ist der eigentliche Kern der Rest-Etappe, nicht die UI. **Punkt 6 (Historie/„wie oft wiedergekehrt") ist mit dem heutigen Schema nicht baubar** (kein `last_seen_at`/`seen_count`, V-009) und bleibt bis zu einer Migrations-Entscheidung draußen.

---

## 8. Etappen

| # | Etappe | Größe | Inhalt |
|---|---|---|---|
| **S0** | Fundament | S | `SignalTyp`-Enum erweitern (Label+Icon je neuem Typ), `DataQualityService` um eine Rezept-Sektion erweitern (Muster der bestehenden `gap()`-Aufrufe), Pest-Fixture-Basis |
| **S1** | Tranche A | M | 11 deterministische Rezept-Checks + Fixer-/Assist-Mapping in `SignalCockpit`; Pest je Check mit Positiv- **und** Negativfall (kein Über-Flaggen) |
| **S2a** | Tranche E · E1 | S–M | ✅ 2026-07-26 — Snapshot-Tabelle + `SignalTrendService` (Serie/Delta/Übersicht) + Fold-in in den Detektor-Lauf + MCP `signal_trend.GET` |
| **S2b** | Tranche E · E2+E3 | M | ✅ 2026-07-26 — Policy-Tabelle (`threshold`/`accepted_until`/`muted`/`note`) + `SignalPolicyService` + aggregierte Zustands-Zeile auf der Signale-Seite statt Einzel-Alarm + Drift-Meta-Signal `qualitaet_drift` im Detektor-Lauf + MCP `signal_policies.GET`/`signal_policy.PUT` |
| **S3a** | Tranche P · Punkte 1+2 | M | ✅ 2026-07-26 — `Signale/DetailPanel` + `signal-selected` + rechte Fläche (`activity_signale`); `SignalObjectService` (beide Richtungen) + `DataQualityService::trifftObjekt()`; volle Liste (300) sortierbar, Inline-50er-Liste abgelöst |
| **S3b-1** | Tranche P · Punkte 3+7 | M | ✅ 2026-07-26 — Teilmengen-Pfad (`execute($team,$sig,$ids)` + `satz()` als eine Stelle für Fix und Vorschau, Auswahl immer gegen das Metrik-Prädikat geschnitten) · Dry-Run `vorschau()` mit lesendem Zwilling je Fixer · Checkboxen + „alle/keine/Vorschau/diese n fixen" im Panel · MCP `signale.FIX` um `object_ids` + `dry_run` erweitert |
| **S3b-2** | Tranche P · Punkte 8+4 | S | ✅ 2026-07-26 — Policy-Regler (Schwelle / „akzeptiert bis" / stumm / Begründung) am Signal, wirkt auf den **Typ**; wirksame (auch geerbte) Werte beim Aufklappen, „Regler entfernen" nur bei eigener Zeile · Sparkline aus `SignalTrendService::serie` (Signal-Seite der Reihe, erst ab 2 Messpunkten) · `SignalPolicyService::zustandFuer()` als schmale Ein-Typ-Sicht über dasselbe `zeile()` |
| **S3b-3** | Tranche P · Punkt 5 | S–M | ✅ 2026-07-26 — `SignalCauseService` (Rezept → unbepreiste Zutat → GP → Beschaffungs-Lage, je Glied `fixbar`) · `DataQualityService::namingBefundeFuer()` gibt den verletzten Fall statt nur ja/nein · §-Deep-Link auf das Regelwerk-Dokument im Wissens-Modul (Slug-Auflösung + Route-Guard) · Ursachen-Block im aufgeklappten Objekt · MCP `signal_causes.GET`. **Punkt 6 (Historie) bleibt draußen** — nicht baubar ohne Migration → V-009 |
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
