---
typ: Domänen-Spec
domaene: D-6
stand: 2026-06-10
status: ausgearbeitet
mvp: MVP
---

# D-6 — Verkaufsrezepte

> **Services (stateless):** `SalesRecipeService`, `MargeService`, `SpeisenKlassenService`
> **Hängt ab von:** D-5 (geteiltes Rezept-Modell + Editor-Kern) · **MVP-Status (⚠D5):** MVP
> **Kurzbeschreibung:** Verkaufslayer auf dem Rezept-Modell (49 Alt-Commands): Marge-Live-Cockpit (GL-02 §3.6), Speisen-Klassen-Klassifikation (16 HG × Diätform), Marketing/Wording mit Schreibstilen, Pipe-Naming, Container-Welt (Behälter/Regeneration/Servier-Vehikel), Kunden-Verwendungsnachweise. Multi-Komponenten-Regeneration (V-19) als Ziel-Datenmodell.
> **Leit-Prinzip:** In der Alt-App hinkte der VK-Workflow dem Basisrezept-Flow strukturell hinterher und wurde stückweise nachgezogen (Memory 2026-05-27 „VK-Parität, 3 Punkte"). Im Rewrite ist VK von Tag 1 eine **gleichberechtigte Sicht** auf D-5 — kein nachgerüsteter Anbau (§6).

## 1. Scope & Ressourcen

49 Commands aus `03_FEATURE_INVENTAR.md` (Filter D-6), gebündelt in 7 Cluster:

| # | Cluster | Inventar-Stämme | Muster | GL | Ziel |
|---|---|---|---|---|---|
| 1 | **VK-Stammdaten am Rezept** | (VK-Spalten laufen über D-5 `recipe`-CRUD), `vk_wording` (suggest/accept), `marketing` / `marketing_text`, `zubereitung` (`ai_generate_zubereitung`, VK-Modus = Plating) | KI-Lebenszyklus | GL-06, GL-07 | `SalesRecipeService` + `AiProposalService` (D-4) |
| 2 | **Kalkulation** | `aufschlagsklasse(n)` (CRUD + Liste) | CRUD | GL-02 §3.6 | `MargeService` + Vokabular-Pflege |
| 3 | **Speisen-Taxonomie** | `speisen_hauptgruppe(n)` (CRUD), `speisen_klasse(n)` (CRUD + `ai_classify_speisen_klasse` + accept) | CRUD/KI | GL-06, GL-07 | `SpeisenKlassenService` |
| 4 | **Schreibstile** | `schreibstil(e)` (CRUD + inactive) | CRUD | — (Prompt-Material für GL-06/07) | Vokabular-Pflege |
| 5 | **Container-Welt** | `behaelter` (suggest/accept), `regeneration` (suggest/accept), `servier_vehikel` (suggest/accept), `vocab_behaelter` / `vocab_regen_geraet` / `vocab_serviervehikel` (je CRUD + inactive) | CRUD/KI | GL-06, GL-07 | `SalesRecipeService` + Vokabular-Pflege (V-20-Geist: Pflege-UI komplett) |
| 6 | **Verwendungsnachweise** | `add_recipe_customer_name`, `recipe_customer_name(s)` (CRUD), `distinct_customer_names` | CRUD/Spezial | — | `SalesRecipeService` |
| 7 | **Klassifikations-Helfer** | (`ai_verteile_rollen` — D-3-inventarisiert, UI hier: Rollen-Verteilung über das ganze Gericht) | KI | GL-06, GL-07 | D-5 `setIngredientRolle` (V-21) |

**Abgrenzungen:** Zutaten/Recompute/Matching/Generator = D-5 (werden 1:1 mitbenutzt, der Generator hat im VK-Modus zusätzliche Achsen, §4.3). Foodbook-Verwendung der VK-Rezepte = D-8 (Phase 2); `recipe_customer_names` ist der MVP-Vorgriff darauf.

## 2. Datenmodell-Ausschnitt

### 2.1 Geteiltes Modell (Wiederholung der D-5-§2.1-Regel aus VK-Sicht)

`foodalchemist_recipes` ist **ein** Modell für beide Domänen; `ist_verkaufsrezept = true` aktiviert die VK-Sicht. `SalesRecipeService` erzwingt den `Recipe::verkauf()`-Scope in jeder Query. Alle Aggregat-Spalten (Yield, EK, Allergene, Nährwerte, Zusatzstoffe) werden von der **D-5-Recompute-Pipeline** befüllt — D-6 berechnet nichts davon neu. VK-Rezepte können Basisrezepte als Zutat referenzieren; **umgekehrt nie** (VK-Rezepte sind nicht im Sub-Rezept-Pool, GL-04 §2.2).

**VK-Spaltenblock auf `recipes`** (Schema-Herkunft: `200_migration_phase_1b.py`; Befüllung im Ist marginal — der VK-Editor war Tauri-Phase-2, → V-22-Seed-Gate):

| Gruppe | Spalten | Anmerkung |
|---|---|---|
| Klassifikation | `speisen_klasse_id` (FK dish_classes), `aufschlagsklasse_id` (FK markup_classes) | + Lineage (GL-07) für die KI-Klassifikation |
| Preis | `vk_netto`, `vk_brutto`, `mwst_satz` | **User-Hoheit** — Recompute schreibt nie (GL-02 I9); manuell gesetzter `vk_netto` schlägt jeden Vorschlag |
| Portionierung | `vk_einheit_vocab_id`, `vk_anzahl_einheiten`, `vk_menge_pro_einheit_g` | Anzahl ist Primär-Eingabe, g/Einheit abgeleitet |
| Wording | `vk_wording_standard` (kanonischer, stil-neutraler Marketing-Name), `marketing_text` (verkäuferischer Langtext) | je + Lineage; Schreibstile transformieren den Standard erst in D-8 in Brand-Voice-Varianten |
| Service | `behaelter_warm_vocab_id` + `_anzahl`, `behaelter_kalt_vocab_id` + `_anzahl`, `servier_vehikel_vocab_id` | Behälter warm/kalt getrennt |
| Regeneration (Ist) | `regeneration_geraet_vocab_id`, `regeneration_temp_c`, `regeneration_dauer_min`, `regeneration_kerntemp_c` | Ein-Programm-Modell — wird durch V-19 ersetzt (2.3); Spalten beim Port NICHT übernehmen, Daten in die neue Tabelle migrieren |

### 2.2 Stammdaten-Tabellen (⚠D1: global mit `team_id` NULL, Pflege Admin-Team — außer Kunden-Wordings)

| Quelle → Ziel | Inhalt |
|---|---|
| `aufschlagsklassen` → `foodalchemist_markup_classes` | `code`, `bezeichnung`, `rohaufschlag_pct`, `bedienung_pct`, `profit_pct`, `mwst_satz`, `formel_typ` ∈ {`aufschlag`, `deckungsbeitrag`}. Seed real: ALC 420 / BAN 260 / BUF 220 / TAW 180 / EXT 200 / MAV 80 % (GL-02 §3.6). `deckungsbeitrag` ist formelmäßig undefiniert → **W-1, GL-02 §6**: bis zum Entscheid nur `aufschlag` portieren |
| `speisen_hauptgruppen` → `foodalchemist_dish_main_groups` | 16 HG mit `code` (HG/VS/SUP/FIN/BUF/BEI/DES/SNK …) — der Code ist Präfix des Pipe-Namings (§4.4) |
| `speisen_klassen` → `foodalchemist_dish_classes` | HG × Diätform = 49 Klassen (VK-Taxonomie, getrennt von der Produktions-Taxonomie `recipe_kategorien` aus D-1) |
| `schreibstile` → `foodalchemist_writing_styles` | 11 Stile; `sprach_duktus` + `beispiele_md` sind Prompt-Material (GL-06-Feld-Hülle bzw. Prompt-Baustein); `is_inactive` |
| `vocab_behaelter` / `vocab_regen_geraet` / `vocab_serviervehikel` → `foodalchemist_vocab_*` | einheitliches Vokabular-Muster (slug, name, gruppe, sort_order, is_inactive); Delete prüft Referenzen in `recipes` |
| `recipe_customer_names` → `foodalchemist_recipe_customer_names` | **team-eigen** (`team_id` NOT NULL): Kunde × Marketing-Name pro VK-Rezept (Verwendungsnachweis; Foodbook-Anschluss in D-8) |

### 2.3 V-19 — `foodalchemist_recipe_regenerations` (NEUE Tabelle, Ziel-Datenmodell)

Das Ein-Programm-Modell der Alt-App scheitert real an Mehr-Komponenten-Tellern (Schmorgericht 140 °C im Kombidämpfer, Püree im Wasserbad, Garnitur kalt). Ziel (Roadmap-Backlog #51):

```
foodalchemist_recipe_regenerations
  id · uuid · team_id (denormalisiert) · recipe_id (FK, cascade)
  komponente_label        TEXT NOT NULL      -- frei benennbar ("Schmorgericht", "Püree");
                                             -- optional ingredient_id (FK recipe_ingredients, nullable)
                                             -- wenn die Komponente eine konkrete Zutat/Sub-Rezept-Zeile ist
  geraet_vocab_id         FK vocab_regen_geraet (nullable — "kalt servieren" = ohne Gerät)
  temp_c · dauer_min · kerntemp_c   INT nullable
  hinweis                 TEXT nullable      -- z.B. "abgedeckt, nach 8 min schwenken"
  sort_order              INT
  + Lineage-Tripel pro Datensatz (quelle/ai_confidence/ai_begruendung — der KI-Vorschlag ist zeilenbasiert, GL-07 §3)
```

Migration: bestehende `regeneration_*`-Werte (Ist-Befüllung gering) werden als **eine** Zeile `komponente_label='Gesamt'` übernommen. UI → §4.5. Das KI-Feature `ai_suggest_regeneration` schlägt im Ziel eine **Liste** von Programmen vor (eines pro erkannter Komponente), nicht mehr ein Skalar-Set.

## 3. Services & Methoden

### 3.1 `SalesRecipeService` (delegiert Kern-Logik an D-5)

```php
list(SalesRecipeFilter $f): LengthAwarePaginator    // Scope verkauf(); Filter: Speisen-HG → Klasse (kaskadierend),
                                                     // Status, Geschmack, Kunde, Suche (auch über Marketing-Namen)
get(int $id): SalesRecipeDetail                      // D-5-Detail + VK-Block + Marge-Berechnung (3.2)
updateVk(int $id, VkInput $in): Recipe               // VK-Spaltenblock; Zutaten/Status via D-5 RecipeService
// Verwendungsnachweise
addCustomerName(int $recipeId, string $kunde, string $marketingName): RecipeCustomerName
updateCustomerName(int $id, …): …  / deleteCustomerName(int $id): void
distinctCustomerNames(int $teamId): array            // Autocomplete
// Regeneration (V-19)
listRegenerations(int $recipeId): Collection
upsertRegeneration(int $recipeId, RegenInput $in): RecipeRegeneration
deleteRegeneration(int $id): void / reorderRegenerations(int $recipeId, array $ids): void
```

### 3.2 `MargeService` — Single Source of Truth für die VK-Mathematik

Normative Grundlage: **GL-02 §3.6** (inkl. Invariante I9 und Golden-Test GT-8) — hier nicht dupliziert. Der Service ist eine **reine Berechnungs-Klasse** (kein DB-Write):

```php
vkVorschlag(?float $ekTotalEur, MarkupClass $ak, ?float $mwstSatz): ?VkSuggestion
        // formel_typ='aufschlag' gem. GL-02 §3.6; 'deckungsbeitrag' → DomainException bis W-1 entschieden
marge(?float $vkNetto, ?float $ekTotalEur): ?MargeResult        // € + % + Wareneinsatz-% (EK/VK netto)
proEinheit(VkSuggestion|float $netto, int $anzahlEinheiten, float $mwst): PerUnitResult
```

Anzeige-Logik (aus dem Alt-Cockpit übernommen): manueller `vk_netto` **gewinnt** gegen den Klassen-Vorschlag; Marge wird auf Charge-Ebene (Gesamt-EK des Rezepts) berechnet und auf die Verkaufseinheit heruntergebrochen. Hinweis Alt-UI: das Cockpit zeigte für `aufschlag` eine multiplikative Kette `×(1+bedienung%)×(1+profit%)` — da alle Seed-Klassen dort 0 % tragen, ist das ergebnisgleich mit GL-02 §3.6; **GL-02 ist normativ**, die Ketten-Frage gehört in den W-1-Entscheid. Livewire bindet die Methoden als computed properties → „live" ohne JS-Doppel-Implementierung (die Alt-App rechnete clientseitig parallel — Drift-Risiko, entfällt).

### 3.3 `SpeisenKlassenService`

```php
listHauptgruppen(): Collection / CRUD …              // inkl. code (Pipe-Naming-Präfix)
listKlassen(?int $hgId): Collection / CRUD …         // Delete blockt bei referenzierenden Rezepten (V-06)
classify(int $recipeId): KlasseProposal              // ai_classify_speisen_klasse: Kontext = Zutaten + GPs +
        // Taxonomie; Ergebnis {klasse_id|null, confidence, begruendung} — null = ehrlicher Nicht-Treffer
acceptKlasse(int $recipeId, int $klasseId, int $callLogId): void   // GL-07-Accept
```

Vokabular-CRUD für Behälter/Regen-Geräte/Servier-Vehikel + Schreibstile + Aufschlagsklassen folgt dem D-1-`VocabularyService`-Muster (eigene kleine Service-Methoden hier, Pflege-UI §4.6) — vollständig inkl. inactive-Flag (V-20-Geist).

## 4. Livewire-Komponenten & UI-Fluss

Routen: `foodalchemist/verkaufsrezepte` + `…/verkaufsrezepte/{recipe}` (V-17). Editor = **dieselbe** Komponente wie D-5 §4.2 mit VK-Zusatz-Sektionen (Reihenfolge aus der Alt-App übernommen, dort iterativ mit dem User erarbeitet).

### 4.1 `Verkaufsrezepte/Index`

Tabelle: Name · Marketing-Name · Kunde(n) · Speisen-Hauptgruppe/Klasse · Status · Geschmack · **VK netto** · EK · Zutaten · Allergen-Konfidenz. Filter: Speisen-HG → Klasse (kaskadierend, Reset-korrekt), Status, Geschmack, Kunde. Aktionen: Neu, ✨ Generator (VK-Modus), „✨ Alles anreichern" (VK-Orchestrator, 4.7), Klassen-Pflege-Link.

### 4.2 Editor — VK-Stammdaten & Klassifikation

- Section-Header „Stammdaten" trägt im VK-Modus die Aktionen **„✨ Klassifizieren"** (Speisen-Klasse, 3.3) und **„✨ Marketing"** (4.4) statt der Basis-Buttons „Kategorie/Fertigung".
- **Speisen-Klasse-Picker:** zweistufig HG → Klasse (Diätform sichtbar); KI-Vorschlag mit Konfidenz, editierbar vor Übernahme (GL-07-Modal-Pattern).
- **VK-Wording (Standard):** eigenes Feld unter dem Namen — kanonischer Marketing-Name, stil-neutral; „✨ Wording" (optional mit Schreibstil als Einmal-Transformation, persistiert wird der Standard).
- **Verkaufseinheit** (direkt nach der Klassifikation, vor den Zutaten — „wie wird verkauft"): Einheit + Anzahl Einheiten (primär) + abgeleitete g/Einheit.

### 4.3 Zutaten & Generator im VK-Modus

Zutaten-Sektion = D-5 §4.2/3 plus: **Rolle-Spalte** sichtbar (V-21) und Header-Aktion **„🎭 Rollen verteilen"** (`ai_verteile_rollen` — KI verteilt Aroma-Treiber/Komponente/Beilage/Garnitur übers ganze Gericht, danach pro Zeile korrigierbar). Generator (D-5 §4.3) erhält im VK-Modus zwei zusätzliche Achsen: **Anlass** (Frühstück…Late Night) und **Serviceform** (Tellerservice…Boxed); Accept setzt `ist_verkaufsrezept=true` + optional `speisen_klasse_id`/`aufschlagsklasse_id` aus dem Vorschlag.

### 4.4 Marge-Live, Marketing & Pipe-Naming

- **Verkaufs-Block** (direkt bei der Zutaten-Aggregation, über den Allergen-Pills): Aufschlagsklasse-Select, MwSt-Satz, „VK netto manuell" (leer = automatisch aus Klasse).
- **VK-Live-Cockpit** (`MargeService`, read-only Kacheln): EK gesamt · VK netto (Marker „manuell") · **VK brutto** (primär) · Marge €/% · Wareneinsatz-% — plus zweite Reihe „Anzahl {Einheit} · VK netto/Einheit · VK brutto/Einheit" sobald Verkaufseinheit gepflegt. Formel-Hint aus der Klasse; Leer-Zustand erklärt sich selbst („Kein EK berechnet — Zutaten ergänzen oder Lead-LAs setzen").
- **Marketing-Text:** Modal mit Schreibstil-Auswahl (11 Stile, `writing_styles` als Prompt-Material) → `ai_generate_marketing` → editierbarer Vorschlag → Accept (GL-07).
- **Pipe-Naming-Konvention** (VK-Variante von `ai_normalize_recipe_name`, Prompt-verifiziert): `"<HG-Code>: Hauptkomponente | Komponente | …"` — HG-Code aus `dish_main_groups.code` (`HG: `, ohne Code ohne Doppelpunkt), **max. 5 Pipe-Felder**, Title Case, Bindestriche für Komposita, **keine Marketing-Adjektive** in Komponenten-Namen (die gehören in `vk_wording_standard`/`marketing_text`). Verbindliche Beispiele: `HOT DOG: GEFLUEGELWIENER` → `HG: Hot Dog | Geflügelwiener | Brioche-Bun | Gewürzgurke | Röstzwiebeln`; `TORTELLINI AUFLAUF TOMATEN PILZE ERBSEN` → `HG: Auflauf | Tortellini | Tomaten-Sahne | Pilze | Erbsen`; `WIENER SCHNITZEL POMMES` → `HG: Schnitzel | Wiener Art | Pommes | Preiselbeeren`.

### 4.5 Container-Welt (Service-Sektion)

- **Regeneration (V-19-UI):** Tabelle der Komponenten-Programme (Label · Gerät · °C · min · Kerntemp · Hinweis), Zeilen hinzufügen/sortieren/löschen; „✨ Regeneration" schlägt die komplette Programm-Liste vor (zeilenbasierter Accept). Einfachster Fall bleibt einfach: eine Zeile „Gesamt".
- **Behälter:** warm + kalt getrennt (Select aus `vocab_behaelter`, gruppiert nach `gruppe`) + Anzahl; „✨ Behälter" (Vorschlag aus Gesamtgewicht + Speisen-Klasse).
- **Servier-Vehikel:** Single-Select + „✨" (Kontext: Speisen-Klasse + Komposition + Portion).
- Inaktive Vokabeln bleiben sichtbar, solange sie am Rezept hängen (Alt-App-Verhalten übernehmen).

### 4.6 Taxonomie- & Vokabular-Pflege (eigener Bereich „Speisen-Klassen", Sidebar-Gruppe „Rezepte")

CRUD-Views für Speisen-Hauptgruppen (+ `code`), Speisen-Klassen (mit Rezept-Zählern), Aufschlagsklassen, Schreibstile, Behälter-/Regen-Geräte-/Servier-Vehikel-Vokabulare. Lösch-Schutz: referenzierte Einträge nur deaktivierbar (`is_inactive`), nicht löschbar (typisierte Exception, V-06).

### 4.7 „Alles anreichern" — VK-Orchestrator

Pendant zu D-5 §4.4, eigene Schritt-Liste (**6 Schritte, code-verifiziert**): Beschreibung (sachlich) · Marketing-Text (verkäuferisch) · Zubereitung (= **Plating & Service**, nicht Produktion) · Eigenschaften · Geschmacksrichtung · Speisen-Klasse. Gleicher Mechanismus: sequenziell, rate-limited, Modell-Fallback, Review-Liste mit Einzel-/Alle-Übernahme; Ziel als Queue-Job (V-15). Die Zubereitung im VK-Modus erzeugt Teller-Aufbau/Mengenverteilung/Service-Anweisung aus Komponenten + Behälter-/Regen-Kontext.

## 5. KI-Features dieser Domäne

Alle nach GL-07 (Lebenszyklus + Lineage + `ai_call_log`) über GL-06-Hüllen; Tier nach V-01. Details → `06_KI_SPEZIFIKATION.md`.

| Feature (Alt-Command) | Zielfelder | Kontext-Besonderheit | Tier |
|---|---|---|---|
| `ai_generate_marketing` / `accept_marketing_text` | `marketing_text` | Schreibstil-Auswahl injiziert `sprach_duktus` + `beispiele_md`; Degenerations-Schutz V-02 (langer Einzeltext) | **A** |
| `ai_suggest_vk_wording` / `accept_vk_wording` | `vk_wording_standard` | stil-neutral (kanonisch); optional Stil als Transformations-Hinweis | A |
| `ai_generate_zubereitung` (VK-Modus) | `zubereitung` (Plating & Service) | liest Behälter/Regen/Einheiten-Kontext; V-02 | A |
| `ai_classify_speisen_klasse` / `accept_speisen_klasse` | `speisen_klasse_id` | validiert gegen Taxonomie; `null` = ehrlicher Nicht-Treffer (kein Erzwingen) | B |
| `ai_suggest_behaelter` / `accept_behaelter` | `behaelter_warm/kalt_vocab_id` + `_anzahl` | Eingabe: Gesamtgewicht (yield) + Speisen-Klasse; Gap-Surfacing bei fehlender Vokabel | B |
| `ai_suggest_regeneration` / `accept_regeneration` | V-19-Zeilen (`recipe_regenerations`) | **zeilenbasierter** Vorschlag/Accept (GL-07 §3) — eine Zeile pro Komponente | B |
| `ai_suggest_servier_vehikel` / `accept_servier_vehikel` | `servier_vehikel_vocab_id` | Kontext: Klasse + Komposition + Portion | B |
| `ai_normalize_recipe_name` (VK-Pfad) | `name` (Pipe-Syntax §4.4) | HG-Code-Injektion aus `dish_main_groups` | B |
| `ai_verteile_rollen` (D-3-inventarisiert, UI hier) | `recipe_ingredients.rolle` (alle Zeilen) | Gesamt-Gericht-Sicht; danach Einzel-Korrektur (V-21) | B |

### 5.x Foodpairing im VK-Workflow (MVP — User-Entscheid 2026-06-11)

Die Produktvision macht Foodpairing+KI zum Kern der VK-Anlage-Automatisierung — deshalb sind diese Features **MVP**, obwohl die Pairing-Domäne D-7 als Explorations-Welt Phase 2 bleibt (Logik-Spec: GL-10, Service: `PairingService`):

| Feature | Wo im VK-Fluss | Commands (Alt) |
|---|---|---|
| **Pairing-Section** (✨ Vorschläge mit Konfidenz, accept/reject) | eigene Section im Detail/Editor | `ai_suggest_pairings` / `accept_pairings` / `reject_pairings` / `get_recipe_pairings` |
| **Kern-Anker** (Rezept 1–5, KI-Inferenz mit GP-Kern-Kontext + Gap-Surfacing) | Section VOR Pairing (Kern = Identität, dann Partner) | `ai_infer_recipe_ankers` + Lebenszyklus |
| **Aroma-Kohäsions-Score** („hängt der Teller zusammen?", GL-10-Formel) | Badge/Panel im VK-Detail, Recompute nach Zutaten-Änderung | `recipe_cohesion` |
| **Komponenten-Netz** (Knoten = Sub-Rezepte+GPs, Kanten = geteilte Anker — zeigt, welche Komponenten das Gericht tragen) | SVG-Insel im VK-Detail (Muster D-7 §4) | `recipe_graph` |
| **Generator-Grounding** (Anker-Graph als Kompositions-Kontext) | automatisch im VK-Generator (GL-13-Routing) | Teil von `ai_generate_recipe` |

Phase 2 bleibt nur die eigenständige Exploration: `pairing_bridge` (Gang-Übergänge), `recipes_sharing_pairings` (verwandte Rezepte), Anker-Graph-Browser.

## 6. Verbesserungen gegenüber Ist

| ID | Konkretisierung in D-6 |
|---|---|
| **V-19** | Multi-Komponenten-Regeneration als **eigene Tabelle** (`foodalchemist_recipe_regenerations`, Schema §2.3) statt der vier Skalar-Spalten; KI-Vorschlag wird Listen-förmig; Alt-Daten als „Gesamt"-Zeile migriert; die Skalar-Spalten werden nicht portiert. |
| **VK-Parität strukturell** | In der Alt-App wurden Marketing-Text, Speisen-Klassen-KI und der VK-Orchestrator dem Basis-Flow **nachträglich** hinterhergebaut (EnrichAll war monatelang Basis-only, der VK-Editor-Layer monatelang unbefüllt: 2/1.407 `vk_netto`). Im Rewrite gilt: ein Editor, eine Schritt-Konfiguration pro Sicht, jede neue Editor-Fähigkeit wird für beide Sichten entschieden (bewusst aktiv ODER bewusst ausgeblendet) — Paritäts-Drift wird Code-Review-Kriterium statt Backlog-Posten. |
| **Marge ohne Doppel-Implementierung** | Alt: VK-Vorschlag/Marge clientseitig in React berechnet, Backend hatte keine Formel (GL-02 §3.6 „keine Auto-Berechnung im Backend"). Ziel: `MargeService` ist die einzige Implementierung, Livewire rendert sie (computed) — GT-8 testet den Service, nicht das UI. |
| **W-1-Disziplin** | `formel_typ='deckungsbeitrag'` wirft bis zum Entscheid (GL-02 §6 W-1) eine typisierte Exception statt still falsch zu rechnen; das Alt-UI zeigte eine Formel an, die das Backend nie implementierte. |
| **V-07** | `updateVk`, Klassen-Accept und Regen-Listen-Writes in Transaktionen (Mehr-Zeilen-Writes!); Lehren aus dem D-5-Accept-Bug-Katalog (D-5 §6.1) gelten unverändert. |
| **V-12** | Rollen-Matrix-Hebel dieser Domäne: VK-Preise/Aufschlagsklassen pflegt „Kalkulation/Vertrieb", Rezept-Inhalt „Koch" — Policy-Grenze verläuft mitten durchs geteilte Modell (Feld-Gruppen-Gates im Service, nicht nur Route-Permissions). |
| **V-16** | VK-Rezepte + KI-Aufrufe sind Billables-Kandidaten (01_ARCHITEKTUR §2) — `ai_call_log` mit `team_id`/`user_id` macht Marketing-Text-Kosten pro Team auswertbar (V-09). |
| **V-22** | Seed-Gate: VK-Rezepte ohne Speisen-Klasse/Aufschlagsklasse/VK-Einheit werden beim Import geflaggt (Review-Queue) — der Ist-Zustand (4/1.407 klassiert) ist Befüllungs-Rückstand, kein Soll. |

## 7. Akzeptanzkriterien & Golden-Tests

1. **GT-8 (GL-02 §3.6):** VK-Vorschlag-Rechnung exakt (ALC-Beispiel) **und** Invariante I9: ein voller Recompute-Lauf verändert keine persistierten `vk_*`-Werte.
2. **Marge-Cockpit-Vertrag:** `MargeService` gegen Fixtures: manueller `vk_netto` gewinnt; Marge-%/Wareneinsatz-% konsistent (`margePct + wePct = 100` bei gleicher Basis); pro-Einheit-Zerlegung `netto/Anzahl`; ohne EK → alle Kacheln leer mit Hinweis, kein Fehler.
3. **W-1-Guard:** Aufschlagsklasse mit `formel_typ='deckungsbeitrag'` → typisierte Exception im Vorschlags-Pfad; CRUD erlaubt das Anlegen (Stammdaten-Vorrat), UI kennzeichnet „Formel nicht definiert".
4. **Pipe-Naming-Golden-Cases:** die drei §4.4-Beispiele wörtlich; max. 5 Felder; ohne HG-Code kein Doppelpunkt-Präfix; keine Marketing-Adjektive in Komponenten (Lint im Accept-Pfad).
5. **Speisen-Klassen-Lebenszyklus:** classify → Vorschlag mit Konfidenz → accept schreibt Klasse + Lineage + Stempel; reject lässt Fachdaten unberührt; `null`-Klassifikation erzeugt keinen Schreibversuch (GL-07-Suite).
6. **V-19:** CRUD + Sortierung der Regen-Zeilen; KI-Vorschlag liefert ≥ 1 Zeile pro erkannter Komponente; Migrations-Test: Alt-Skalarwerte → genau eine „Gesamt"-Zeile, Werte identisch.
7. **Verwendungsnachweise:** Kunde×Marketing-Name CRUD; `distinctCustomerNames` team-scoped; Löschen eines Rezepts kaskadiert.
8. **Scope-Härte:** `SalesRecipeService.list/get` liefert nie Basisrezepte und umgekehrt; ein VK-Rezept ist nicht als Sub-Rezept verknüpfbar (GL-04-Pool-Filter, Service-Guard + Test).
9. **Vokabular-Schutz:** referenzierter Behälter/Regen-Gerät/Servier-Vehikel/Schreibstil ist nicht löschbar, nur deaktivierbar; inaktive, aber zugewiesene Einträge bleiben im Editor sichtbar.

---

**Querverweise:** GL-02 (§3.6 + I9 + GT-8, W-1), GL-04 (Pool-Filter), GL-06, GL-07 · D-1 (Vokabular-Muster), D-4 (Gateway/Proposal), D-5 (Modell, Editor, Generator, Recompute), D-8 (Foodbook-Anschluss der Wordings, Phase 2) · 08_ENTSCHEIDUNGEN ⚠D1/⚠D5 · 10_VERBESSERUNGS_REGISTER V-07/12/16/19/21/22.
