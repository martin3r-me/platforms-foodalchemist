# 59 · Speiseplan: Vorgaben je Woche

**Stand 2026-10-06 · Branch `feat/speiseplan-vorgaben` off main (`16b92b4a`) · Status: umgesetzt, lokal getestet — Abnahme offen**

## Anlass

Dominique (2026-10-06): Die Karte „Abwechslung · Woche“ im Speiseplan-Editor zählte nur. Sie soll
prüfen, ob ein Plan seine **Vorgaben** erfüllt, z. B. „mind. 2× vegan, höchstens 1× Schwein,
mind. 1× Suppe pro Woche“. Nicht jeder Speiseplan ist gleich, also Vorgaben je Plan. Die Chips
selbst werden zentral in den Einstellungen angelegt.

Dabei fiel ein Zählfehler auf: Alles, was nicht vegan oder vegetarisch war, zählte als „mit Fleisch
oder Fisch“ — auch Gerichte ganz ohne Diät-Pflege.

## Entscheidungen

| # | Entscheidung | Begründung |
|---|---|---|
| E1 | **Chip-Katalog in den Einstellungen, je Team** (Sektion „Speiseplan-Chips“). Kind-Teams sehen die Chips des Eltern-Teams lesend und dürfen sie in ihren Plänen nutzen (D1). | Ein Katalog, viele Pläne — gleiche Begriffe über alle Betriebe. |
| E2 | **Vorgaben je Plan** mit mind./höchstens, optional je Mahlzeit. Mahlzeit leer = gilt für jede Mahlzeit einzeln. | Kantine und Abendkarte haben andere Regeln; „alle“ heißt nicht „Summe über alle Mahlzeiten“. |
| E3 | **Kriterien ODER-verknüpft**: Ernährungsform (vegan, vegetarisch, fleisch, fisch, schwein, rind) und/oder Hauptgruppe. | „Fleischlos“ = Vegan ODER Vegetarisch, „Suppe“ = Hauptgruppe Suppen. UND braucht bisher niemand. |
| E4 | **Zählung je Gericht je Woche** (Öffnungstage, gewählte Mahlzeit). Ein Paket/Concept mit 3 Gerichten zählt 3. | Der Gast isst Gerichte, nicht Einträge. |
| E5 | **Unbekannt ≠ Fleisch.** „Fleisch“ nur bei belegtem Fleisch: `spec_is_vegetarian` ausdrücklich `false` (nicht `null`) oder Schwein/Rind. Gerichte ohne Angabe zählen unter „ohne Angabe“ (Warn-Chip). | `null` heißt laut Recompute (`mergeAssure`) „unbekannt“. Ein Fehlalarm „zu viel Fleisch“ wegen fehlender Pflege wäre falsch. |
| E6 | Diät-Zählung bleibt **exklusiv** wie bisher: vegan zählt nicht zusätzlich als vegetarisch. Schwein und Rind zählen zusätzlich als Fleisch. | Konsistent mit den Zellen-Chips. Wer „fleischlos“ prüfen will, baut einen Chip mit beiden Kriterien (E3). |
| E7 | **Lösch-Schutz wie bei Posten (V-06): nur stilllegen.** Ein stillgelegter Chip wird in bestehenden Vorgaben weiter geprüft, ist für neue nicht mehr wählbar. | Pläne referenzieren den Chip per id im JSON (kein FK) — Löschen ließe Vorgaben still verschwinden. |
| E8 | Kein automatisches Seeden. „Standard-Chips anlegen“ (Vegan, Vegetarisch, Fleisch, Fisch, Schwein) nur auf Knopfdruck und nur für ein Team ohne eigene Chips. | Keine erfundenen Stammdaten. |
| E9 | Vorgaben gehören zum Plan: jede Kopie (auch die Betriebs-Kopie einer Vorlage) übernimmt sie. Der Vorlagen-Abgleich gleicht sie nicht nach. | Der Betrieb darf eigene Regeln haben, wie bei Preisen und Mengen. |
| E10 | Hervorheben rein clientseitig: Klick auf einen Chip der Karte markiert die zählenden Einträge in der Wochen-Matrix (Alpine `markiert`), zweiter Klick hebt auf. | Kein Server-Roundtrip für eine Ansichtsfrage. |

## Datenmodell

- `foodalchemist_menu_plan_chips`: `id`, `uuid`, `team_id`, `label`, `kriterien` (json),
  `default_min`, `default_max` (nullable), `sort_order`, `is_active`, Zeitstempel.
  `kriterien` = `[{"art":"diaet","key":"vegan"}, {"art":"hauptgruppe","id":12}]`.
- `foodalchemist_menu_plans.vorgaben` (json, nullable):
  `[{"chip_id":3, "mahlzeit":null|"fruehstueck"|"mittag"|"abend"|"snack", "min":2, "max":null}]`.
- Migration `2026_10_06_000001_create_menu_plan_chips_and_vorgaben.php`, Model
  `FoodAlchemistSpeiseplanChip`.

## Code

- `SpeiseplanVorgabenService`: Katalog (anlegen, ändern, Startsatz), `normVorgaben` (eine
  Validierung für UI und MCP: Chip im Team sichtbar, Mahlzeit bekannt, ganzzahlig ≥ 0, min ≤ max,
  mind. eine Grenze, keine Dublette Chip × Mahlzeit), `setzeVorgaben`, `auswerten`.
- `ConcepterAggregateService::allergenRollupFromGerichte` liefert zusätzlich `fleisch_belegt`;
  `SpeiseplanService::diaetMerkmale` setzt „fleisch“ nur noch damit.
- `SpeiseplanService::wochenAbwechslung` (rückwärtskompatibel): `diaet` mit vegan, vegetarisch,
  fleisch, fisch, schwein, rind, ohne_angabe und weiter `omnivor` (= Fleisch ∪ Fisch);
  `warengruppen` (+ `id`); neu `vorgaben` (je anwendbarer Vorgabe: ist, min, max, Status
  `ok|zu_wenig|zu_viel`, Gericht- und Eintrag-Ids), `treffer`, `diaet_eintraege`, `wg_eintraege`.
- UI: Einstellungen › Speiseplan-Chips (`Settings\SpeiseplanChips`); Editor › Stammdaten ›
  „Vorgaben je Woche“ (`partials/vorgaben`); Karte „Abwechslung · Woche“ (`partials/abwechslung`).

## MCP

- `foodalchemist.speiseplan_chips.GET` — Katalog (inkl. geerbter, `eigen`-Flag).
- `foodalchemist.speiseplan_chips.POST` — Chip anlegen; `standard: true` legt den Startsatz an.
- `foodalchemist.speiseplan_chips.PUT` — Chip ändern; kein DELETE (stilllegen per `is_active`).
- `foodalchemist.speiseplaene.GET` liefert `vorgaben`; `speiseplaene.PUT` nimmt `felder.vorgaben`
  (ersetzt die Liste, gleiche Validierung über `SpeiseplanVorgabenService::normVorgaben`).

## Offen

- Geflügel, Lamm und Wild haben kein eigenes Datenfeld — sie erscheinen als „Fleisch“, sofern belegt.
- Ein Fisch-Gericht ohne gepflegtes Fisch-Allergen, aber mit `spec_is_vegetarian = false`, zählt als Fleisch.
- Vorgaben über einen ganzen Zyklus oder je Tag sind nicht vorgesehen, nur je Woche.
