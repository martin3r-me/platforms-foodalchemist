# Spec 82 · Anlage zu §13 I — Grundeinstellungen: Entscheidungstabelle

Stand: 13.10.2026 · Vorbereitung, **noch kein Bau** · Quelle: Code (Migrationen in main) + demo **nur lesend** (Zeilen je Team, `team_id`-Nullbarkeit, Fremdschlüssel).
**Entschieden 13.10. (Dominique): Grundsatz (a)** — alles, worauf Master-Rezepte, Stamm-GPs, Regelwerk oder Rechnen zeigen, ist **Basis**. Die Spalte „Vorschlag“ gilt, soweit Dominique die Zeile nicht ändert; Zeilen ohne Kreuz sind noch in Durchsicht bei Dominique. Gebaut wird nach dieser Tabelle (§13 I).

## Die drei Ebenen (aus §13 I)

| Ebene | Heißt | Wer pflegt | Wie kommt es zum Team |
|---|---|---|---|
| **Basis** | global (`team_id` NULL) | nur der Master | live, jedes Team nutzt es; **Team darf eigene Zeilen ergänzen** |
| **Paket „Grundeinstellungen“** | Vorlage des Masters | Master pflegt die Vorlage | **Kopie** beim Buchen, danach gehört sie dem Team (Unter-Teams erben) |
| **Team selbst** | Zeile mit `team_id` des Teams | das Team | jedes Team baut selbst auf |

**Bereits entschieden (nicht mehr abzuhaken):** Behälter-Katalog (`vocab_containers`), Regenerationsgeräte (`vocab_regeneration_devices`), Servier-Vehikel (`vocab_serving_vehicles`), Koch-Equipment = **Basis live** · Regeln, Wissen, KI = **Master**.

> **Korrektur 13.10. (nach Gegenprüfung):** Die erste Fassung nannte `recipe_containers`/`recipe_regenerations` als Behälter-/Geräte-Kataloge. Das sind **Verknüpfungstabellen am Rezept**. Die Kataloge sind `vocab_containers`, `vocab_regeneration_devices` und `vocab_serving_vehicles` (alle schon nullable). `serving_forms` sind die **Servierformen** (Darreichung), ein eigener Katalog, `team_id` NOT NULL.

## Messung auf demo (lesend)

- **Alles liegt in Team 6.** Global (`team_id` NULL) gibt es nur: `rules` (34), `terminology_*` (134 + 11), `brief_templates` (6), `knowledge_categories` (8 von 23). Ein neues Haupt-Team sieht daher fast nichts (Befund aus §13 I bestätigt).
- **`NOT NULL` verhindert „global" ohne Migration:** `serving_forms`, `kitchen_roles`, `production_stations`, `menu_plan_chips`, `target_groups`, `component_equivalents`, `trends` u. a. Für Basis-live muss `team_id` erst nullable werden.
- demo ist **hinter main**: `trend_taxonomy`, `trend_meta`, `team_profiles*`, `canvas_templates`, `taste_axes` existieren dort (noch) nicht; sie sind unten aus den Migrationen in main eingetragen.

## Die eine Frage, die den Rest bestimmt: Kopie bricht Fremdschlüssel

Master-Rezepte (Abo) und Stamm-GPs **zeigen auf IDs** dieser Tabellen. Liegt eine Tabelle als **Paket-Kopie** (neue IDs) im Team, zeigen die Abo-Rezepte auf Zeilen, die das Team **nicht hat**:

| Referenziert von … | … auf | Folge bei „Paket = Kopie" |
|---|---|---|
| `recipes.category_id`, `recipes.dish_class_id`, `recipes.markup_class_id`, `recipes.sales_unit_vocab_id` | Kategorien, Klassen, Aufschlagsklassen, Einheiten | Abo-Rezept zeigt auf Master-Kategorie, die das Team nicht sieht → Anzeige leer / Filter greift nicht |
| `recipe_ingredients.unit_vocab_id`, `gps.preferred_count_unit_id` | Einheiten | Rechnen und Anzeige brechen |
| `gps.warengruppe` (Regelwerk §3) | Warengruppen | Stamm-GPs mit Warengruppe, die das Team nicht kennt |
| `recipe_equipment.equipment_id`, Teamprofil (`team_profile_equipment`) | Koch-Equipment | Machbarkeit zeigt ins Leere |
| `concepts/formats/recipe_presentations.serving_form_id`, `recipes.serving_vehicle_vocab_id` | Servierformen, Servier-Vehikel | Konzept/Format/Rezept zeigt auf unsichtbare Zeile |

**Zwei saubere Auswege** (gilt für jede „Paket"-Zeile, die von Master-Inhalt referenziert wird): **(a)** die Tabelle wird **Basis** (global, Team ergänzt) — nichts bricht; **(b)** bleibt Paket, dann braucht das **Abo-Kopieren** eine **Abbildung Master-ID → Team-ID** beim Übernehmen eines Rezepts (zusätzlicher Bauaufwand, Fehlerquelle). Vorschlag unten folgt dem Grundsatz „referenziert von Master-Inhalt ⇒ Basis".

## Entscheidungstabelle

Legende: **Heute** = Zeilen auf demo (Team 6 / global) · `NN` = `team_id` NOT NULL (global nur nach Migration), `null` = bereits nullable · **Vorschlag**: B = Basis · P = Paket · T = Team.

### A · Referenziert von Master-Inhalt / Rechnen / Regelwerk (Vorschlag: Basis)

| Tabelle (foodalchemist_…) | Heute | Vorschlag | Dein Kreuz (B/P/T) | Begründung | Referenziert von |
|---|---|---|---|---|---|
| `lookup_commodity_groups` (Warengruppen) | 17 · Team 6 · null | **B** | ☐ ☐ ☐ | Regelwerk §3 definiert sie fest (15 Warengruppen); Stamm-GPs hängen daran | `gps.warengruppe` (Code), `commodity_group_subcategories` |
| `commodity_group_subcategories` | 0 · null | **B** | ☐ ☐ ☐ | folgt den Warengruppen | `lookup_commodity_groups` |
| `vocab_units` (Einheiten) | 51 · Team 6 · null | **B** | ☐ ☐ ☐ | Rechnen und Mengen aller Rezepte; Kopie bräche 9 Verweise | `recipe_ingredients`, `recipes.sales_unit_vocab_id`, `gps.preferred_count_unit_id`, `concept_slots`, `foodbook_blocks`, `offer_blocks`, `recipe_presentations`, `gp_count_unit_defaults` |
| `gp_count_unit_defaults` | 19 · Team 6 · null | **B** | ☐ ☐ ☐ | Stückgewicht-Standards der Stamm-GPs | `vocab_units` |
| `vocab_kitchen_equipment` (Koch-Equipment) | 40 · Team 6 · null | **B** (entschieden) | — | Teamprofil/Machbarkeit + `is_standard`-Flag (Master setzt) | `recipe_equipment`, `team_profile_equipment` |
| `vocab_containers` (Behälter-Katalog) | 59 · Team 6 · null | **B** (entschieden) | — | bereits nullable → nur Scope + Schreibrecht | `recipe_containers.container_vocab_id`, `recipes.container_warm/cold_vocab_id`, `recipe_presentations` |
| `vocab_regeneration_devices` (Regenerationsgeräte) | 13 · Team 6 · null | **B** (entschieden) | — | bereits nullable → nur Scope + Schreibrecht | `recipe_regenerations.device_vocab_id` |
| `vocab_serving_vehicles` (Servier-Vehikel) | 23 · Team 6 · null | **B** (entschieden) | — | bereits nullable → nur Scope + Schreibrecht | `recipes.serving_vehicle_vocab_id`, `recipe_presentations`, `tableware_items.vehicle_vocab_id` |
| `serving_forms` (Servierformen: Darreichungs-Form) | 11 · Team 6 · **NN** | **B** | ☐ ☐ ☐ | **Befund Lisa:** `team_id` NOT NULL → global erst nach Migration; Konzept/Format/Präsentation zeigen darauf | `concepts.serving_form_id`, `formats.serving_form_id`, `recipe_presentations.serving_form_id` |
| `recipe_containers`, `recipe_regenerations` (Verknüpfung Rezept ↔ Behälter/Gerät) | 40 · 41 · Team 6 · NN / null | **keine Einstellung** | — | **Verknüpfungstabellen am Rezept** (Zweck-Zuordnung je Rezept), keine Kataloge; sie folgen dem Rezept, nicht der Grundeinstellung | `recipes`, Katalog oben |
| `recipe_main_groups` + `recipe_categories` (Rezept-Kategorien) | 31 + 189 · Team 6 · null | **B** *(Spec sagt P)* | ☐ ☐ ☐ | **jedes Master-Rezept trägt `category_id`**; Kopie ⇒ Abbildung nötig (siehe oben). Team ergänzt eigene Unterkategorien | `recipes.category_id` |
| `dish_main_groups` + `dish_classes` (Gerichte-Klassen) | 20 + 61 · Team 6 · null | **B** *(Spec sagt P)* | ☐ ☐ ☐ | `recipes.dish_class_id`; Taxonomie „wie gebaut?" (Skript 269) ist FA-Logik, kein Kunden-Wunsch | `recipes.dish_class_id`, Aufschlagsklasse |
| `markup_classes` (Aufschlags-/Preisklassen) | 8 · Team 6 · null | **P** | ☐ ☐ ☐ | Zahlen sind **Kundensache** (Marge), aber Rezepte/Gerichte-Klassen zeigen darauf; Vorschlag: Struktur Basis, **Werte** je Team überschreibbar | `recipes`, `dish_classes.default_markup_class_id`, `recipe_presentations`, `team_settings.default_markup_class_id` |
| `vocab_pairing_anchors` + `anchor_*` (Pairing, 2.628 Anker) | 2.628 · Team 6 · null | **B** | ☐ ☐ ☐ | Pairing-Wahrheit ist global (Spec 60); 2.628 Zeilen liegen heute unter Team 6 | `anchor_*`, `gp_anchor_mappings`, `recipe_profile_anker` |
| `vocab_textures`, `gp_forms`, `substitutions` | 16 · 5.852 · 80.044 · **keine `team_id`** | **B** (ist schon global) | — | keine Team-Spalte = bereits Plattform-Stamm | — |
| `terminology_aliases`, `terminology_anti_markers` | 134 + 11 · **global** | **B** (ist schon global) | — | Matching-Sprache | — |
| `rules` / `rule_versions` (Regeln, Spec 81) | 34 · **global** | **Master** (entschieden) | — | Regeln-als-Daten | — |
| `knowledge_*` (Dokumente, Kanon, Routings, Budgets) | Routings 139 / Budgets 4 ohne `team_id` | **Master** (entschieden, §13 H) | — | siehe §13 H | — |
| `component_equivalents` | 7 · Team 6 · **NN** | **B** | ☐ ☐ ☐ | Komponenten-Äquivalenz für Rechnen; **Migration nötig** | — |
| `brief_templates` | 6 · **global** | **B** (ist schon global) | — | KI-Brief-Vorlagen | — |

### B · Arbeitslisten und Kundensache (Vorschlag: Paket = Kopie, danach gehört es dem Team)

| Tabelle (foodalchemist_…) | Heute | Vorschlag | Dein Kreuz (B/P/T) | Begründung | Referenziert von |
|---|---|---|---|---|---|
| `concept_categories` (Konzept-Kategorien) | 37 · Team 6 · null | **P** | ☐ ☐ ☐ | Konzepte sind Teamarbeit; nur Konzepte verweisen darauf (Team-Daten, nicht Master-Rezept) | `concepts.category_id`, sich selbst (`parent_id`) |
| `target_groups` (Konzept-Merkmale / Zielgruppen) | 32 · **8 Teams × 4** · **NN** | **P** | ☐ ☐ ☐ | **Befund §13 I:** nur per Migration für damals vorhandene Teams angelegt, neue Teams haben leere Liste; Paket löst das | `chapter_/concept_/foodbook_/format_target_groups` |
| `concept_sector_suitability`, `concept_service_moments`, `concept_seasons` | 0 · 6 · 3 | **P** (Startbestand) | ☐ ☐ ☐ | Merkmale für Konzepte | `concepts` |
| `writing_styles` (Schreibstile) | 11 · Team 6 · null | **P** | ☐ ☐ ☐ | Stimme des Kunden; Konzepte/Foodbook/Karten verweisen darauf (alles Teamdaten) | `concepts`, `foodbooks`, `foodbook_chapters`, `menu_cards`, `menu_plans` |
| `kitchen_roles` (Rollen & Stundensätze) | 3 · Team 6 · **NN** | **P** | ☐ ☐ ☐ | Lohnkosten sind kundenspezifisch; Beispielwerte als Startbestand | — |
| `production_stations` (Posten & Kapazität) | 4 · Team 6 · **NN** | **P** | ☐ ☐ ☐ | Küchen-Organisation des Kunden | `production_order_lines.station_id` |
| `menu_plan_chips` (Speiseplan-Chips) | 5 · Team 6 · **NN** | **P** | ☐ ☐ ☐ | Arbeitsliste; Startbestand per Befehl existiert schon | — |
| `fixed_costs` (Zuschlagsschema/Fixkosten) | 16 · Team 6 · null | **P** | ☐ ☐ ☐ | Beispiele als Vorlage, Zahlen sind Kundensache | — |
| `planning_frames` (+ `_slots`, `_rules`) | 50 · Team 6 · null | **P** | ☐ ☐ ☐ | Planungsrahmen (Speiseplan-Struktur) | `planning_frame_slots`, `_rules` |
| `trends`, `trend_taxonomy`, `trend_meta` (Trend-Startbestand) | `trends` 27 · Team 6 · **NN**; Taxonomie fehlt auf demo | Taxonomie **B**, Startbestand **P** | ☐ ☐ ☐ | §13 I: Taxonomie = Master; Startbestand = Paket | — |
| `presentation_designs` | 12 · Team 6 · null | **P** | ☐ ☐ ☐ | Darstellungs-Vorlagen | — |
| `knowledge_categories` | 23 (8 global / 15 Team 6) | **B + Team ergänzt** | ☐ ☐ ☐ | ist schon so gebaut (global + eigene), Sichtbarkeit seit #315/#316 korrekt | — |

### C · Gehört dem Team selbst (Vorschlag: Team)

| Tabelle (foodalchemist_…) | Heute | Vorschlag | Dein Kreuz (B/P/T) | Begründung | Referenziert von |
|---|---|---|---|---|---|
| `team_settings` (u. a. Standard-Aufschlagsklasse) | 1 · Team 6 · NN | **T** | ☐ ☐ ☐ | Kalkulationsvorgaben je Team | `markup_classes` |
| `outlet_settings`, `team_profiles` (+ `_equipment`, `_areas`) | 1 · Team 6 · NN; Teamprofil fehlt auf demo | **T** | ☐ ☐ ☐ | Küchen-/Teamprofil, Machbarkeit | Koch-Equipment (Basis) |
| `signal_policies` | 1 · Team 6 · NN | **T** | ☐ ☐ ☐ | Schwellen/Alarme je Betrieb | — |
| `storage_bins`, `inventory_locations` (Lagerorte) | 0 · NN | **T** | ☐ ☐ ☐ | betriebsspezifisch | Lagerbestand |
| `label_templates`, `printers` (Etiketten/Drucker) | 1 · 0 · NN | **T** | ☐ ☐ ☐ | Hardware/Layout je Betrieb | — |
| `tableware_items`, `tableware_suppliers` (Geschirr-Katalog) | 9 + 1 · Team 6 · null | **T** | ☐ ☐ ☐ | Geschirr des Kunden; Vehikel-Typ (Basis) ist getrennt | `recipe_presentations.tableware_item_id` |
| `preferred_suppliers` (Stamm-Lieferanten-Matrix) | 134 · Team 6 · NN | **T** | ☐ ☐ ☐ | Einkaufs-Vorlieben des Kunden (Stamm-Paket hat keine Preise/Präferenzen) | — |
| `dish_idea_groups` (Ideen-Gruppen) | 0 · NN | **T** | ☐ ☐ ☐ | Arbeitslisten | `dish_ideas` |
| Kalkulationsprofile, Zugriffsrechte, Food DNA, Kunden-DNA, KI ein/aus | eigene Tabellen/Einstellungen | **T** | — | schon team-eigen (§13 I) | — |

## Folgen, je nachdem was Du abhakst

1. **Jede Zeile „B" mit `NN`** braucht eine Migration (`team_id` nullable machen) **plus** einen Ablauf, der die Team-6-Zeilen auf global hebt (zusammen mit dem Stamm-Umzug auf dem eigenen Server, nicht auf demo): `serving_forms`, `component_equivalents` (die Kataloge `vocab_containers`, `vocab_regeneration_devices`, `vocab_serving_vehicles` sind schon nullable).
2. **Jede Zeile „P"** braucht den Kopier-Mechanismus (Buchen per `/verwaltung`, MCP im Lockstep) und — **wenn Master-Rezepte darauf zeigen** (Kategorien, Klassen, Aufschlagsklassen) — die ID-Abbildung beim Abo.
3. **Jede Zeile „B"** braucht in den Lese-Pfaden `applyVisible` (global + eigene Zeilen); das Muster steht (siehe Wissens-Kategorien), der Master-Schreibpfad ebenso (`WissensSichtbarkeit`-Muster für Vokabular).

## Empfehlung in einem Satz

Alles, worauf **Master-Rezepte, Stamm-GPs, Regelwerk oder Rechnen** zeigen, wird **Basis** (global, Team ergänzt); **Paket** bekommt nur, was ausschließlich **Team-Arbeit** referenziert (Konzepte, Schreibstile, Rollen, Posten, Chips, Fixkosten, Planungsrahmen, Trend-Startbestand); **Team** bleibt, was Betrieb und Hardware des Kunden beschreibt. Einzige Abweichung vom Spec-Entwurf: **Rezept-Kategorien und Gerichte-Klassen als Basis statt Paket** — sonst braucht jedes Abo-Rezept eine ID-Abbildung.

## Offen für Dich (kurz)

- Kategorien/Gerichte-Klassen: **Basis (Vorschlag)** oder **Paket mit ID-Abbildung**?
- `markup_classes`: Struktur Basis + Werte je Team überschreibbar (Vorschlag) oder ganz Paket?
- Wer pflegt `trend_taxonomy` im Betrieb: ausschließlich der Master?
