# Plan — Datenqualitäts-Kaskade FoodAlchemist (bottom-up, mit Anker-Erdung + voller Anreicherung)

---

## 📌 SESSION-STAND 2026-07-14 (Handoff) — Etappe 1 gebaut, Rest offen

### ✅ Erledigt: Code (gepusht auf Modul-`main`)
- **P1** `DataQualityService` + Command `foodalchemist:data-quality {--team --json --signals}` (Ampel LA/GP/BR/VK/Quer, read-only; `--signals` → ReviewQueue-Inbox) + 3 Signal-Typen `AnkerFehlt`/`ServierformUnbestimmt`/`EkKetteUnvollstaendig` → Commit `7ec8ad6`
- **P2** `foodalchemist:lead-la-repick` · **P3** `foodalchemist:gp-allergen-backfill` · **P4** `foodalchemist:recompute` → `7ec8ad6`
- **Fold-in:** Ampel als 8. Detektor in `SignalDetektorService::laufen()` → der geplante `signale-detektor`-Scheduler füllt die DQ-Signale automatisch → Commit `00ff706`
- 13 neue Pest-Tests + Voll-Suite grün (692/1 skip/0 fail). ROADMAP R0.3 neu geschnitten (2 WaWi-Ära-Punkte gestrichen). `Composer_Update_FA.md` um „Falle: nie im demo-Checkout updaten" ergänzt.

### ✅ Erledigt: Daten am LOKALEN Master (`foodalchemist_full`, Team 1) — NICHT auf demo
- 90 Lead-LAs gefixt (auflösend 4.900 → 4.990); 405 echte Lücken sauber als „Park" erkannt
- GP-Allergen-Konfidenz **6.947 → 0** (nur Metadaten-Spalten, Wert-Spalten unberührt); 289 Konflikte als Signal
- Recompute 3.218 Rezepte / 0 Zyklen; 12 DQ-Signale in der lokalen Inbox
- Backups: `12_DATA/wawi/fa_mysql_PRE_DQ_CASCADE_20260714_1950.sql.gz` (voll) + `fa_gps_PRE_P3_ALLERGEN_*.sql.gz`

### ⚠️ Deploy-Klärung (Code vs. Daten) — WICHTIG
- **Code** → deployt **automatisch** über Forge (Modul-Push → server-seitiger composer, PHP 8.4 + Server-Token). Danach berechnet der demo-Scheduler die DQ-Signale aus **demos eigenen Daten**.
- **Daten-Heilung** (90 Leads / Allergen-Backfill / Recompute) → steckt nur im **lokalen Master**, kommt NICHT mit dem Code-Deploy. Um demo zu heilen: entweder die Commands **auf demo** laufen lassen (`--apply`, server-seitig) ODER Master re-exportieren + `import-master`. = eigener bewusster Daten-Schritt (Martin/Server).
- **NIE** lokalen `composer update` im `demo.bhgdigital.de`-Checkout (kein GitHub-Token → Clone-Bug) — siehe `15_GITHUB/Composer_Update_FA.md`. Lokal nur Sandbox (path-repo).
- Verifikation MCP 2026-07-14 21:xx: demo-Inbox = 241 Signale, noch **alte Typen** (letzter Scheduler-Lauf 21:06 war VOR dem Fold-in). Neue DQ-Typen erscheinen nach Deploy + nächstem Scheduler-Tick (oder „↻ Prüfen" in der Signale-Ansicht auf demo).

### ◻️ Offen — Etappe 1 Rest (deterministisch, kein LLM)
- **✅ P5 Prozessanker-Parser GEBAUT 2026-07-19** (`foodalchemist:process-anchor-ground` Parser-Modus + `ProcessAnchorService` + MCP `process_anchors.GROUND`): deterministisch aus `preparation`, nur bei echten Markern (Röst/Grill/Rauch/Karamell/Ferment → 4 Anker roest/karamell/rauch/ferment; grill=roest+rauch, schmor=roest gespiegelt aus Skript 216), `source='parser'`, idempotent, fremde manual/ki/auto unangetastet, Über-Tagging-Guard. 10 Pest + MySQL-Smoke (Fixture: +19 Anker, kein Über-Tagging, Re-Run 0). **KI-Rest** mehrdeutiger Prep-Texte bleibt Etappe 2 (`--mode=ki`, n/a). **Bulk-Apply auf demo/`foodalchemist_full` = separater Deploy-Schritt (Martin/Server, `php8.4 artisan … --apply`).**
- **P6 Review-/Park-Listen:** 27 tentative-in-Rezept · 12 fb2027-Stubs · 33 beide-null-Zutaten · itemisierte **398 Park-GP-Sourcing-Liste**
- **demo-Daten-Heilung:** Commands auf demo fahren ODER Master-Re-Import

### ◻️ Offen — Etappe 2 (KI via OpenAI, braucht Key in sandbox-`.env`)
- LLM-Setup (`FOODALCHEMIST_AI_PROVIDER=core` + OpenAI-Key) + `AiGatewayService`-Verify
- Zutaten-Anker-Erdung (84 GP + 92 BR + 151 VK) + Prozessanker-KI-Rest
- **VK-Neben-Tabellen klären** (Sensorik/Pairing/Equipment: gespeichert vs. komponenten-abgeleitet — kein Blind-Fill)
- Volle Anreicherung (GP condition/sub_category/tags/nutri-Fallback + Rezept-Sensorik/Sektor)
- Serving-Form-KI (329) · GP-Lücken-Match (398 gegen 264k-Katalog) · gemini_proposed-Verifikation (9.599)

### ◻️ Offen — Sync/Prozess
- Dev-Modul (Package 23 `platforms-food-alchemisten`) mit Etappe-1-Stand aktualisieren
- BulkEnrichService-Accept-Pfade (anker/sensorik/sektor/level/pairing) für „volle Anreicherung"

> Memory: [[project_datenqualitaet_kaskade_2026-07-14]] · [[feedback_fa_composer_update_procedure]]

---

## Context

**Warum:** Dominique will die Datenqualität des FoodAlchemist ganzheitlich sichern — entlang der ganzen Kaskade **Lieferantenartikel (LA) → Grundprodukt (GP) → Basisrezept → VK-Gericht**, und „wenn wir eh drin sind" zusätzlich **die Anker setzen wo sie fehlen** (Flavor-Graph-Erdung) und **alle Felder voll anreichern**. Auslöser: die R0.3-Messung zeigte, dass die „unbepreisten Ketten" oben nur **Symptome** von Lücken weiter unten sind. Bottom-up heilt die Wurzel; top-down flickt Symptome.

**Kernbefund (gemessen 2026-07-14 gegen `foodalchemist_full`, deckungsgleich mit dem demo-Stand):** Das Top-Maß „97,8 % VK bepreist" verdeckt massives **Teil-Pricing** darunter. Wurzel: **495 genutzte approved-GPs lösen nicht auf einen Preis auf** (107 auto-fixbar, 398 echte Lücken); GP-Allergen-Konfidenz praktisch nie persistiert; gezielte Anker- und Anreicherungs-Lücken.

**Ziel-Ergebnis:** Jede Ebene data-quality-vollständig (Regelwerk-konform), Flavor-Anker erdet, alle KI-anreicherbaren Felder gefüllt — plus eine **wiederholbare Datenqualitäts-Ampel** (Routine), die den Zustand laufend misst. KI-Schritte (Etappe 2) über **OpenAI via vorhandenen Core-Provider** (Entscheid Dominique). ⚠️ **Datenabfluss:** OpenAI ist eine Cloud-API — KI-Calls senden Namen/Zutaten/Hauptgruppe an OpenAI-Server (keine Preise/Margen/Kundendaten). **Etappe 1 ist 0-Egress** (rein deterministisch, lokal).

## Gemessene Baseline (Ist, Master `foodalchemist_full`, Team 1)

**Kanon:** `foodalchemist_full` (MySQL) ist auf allen Qualitätskennzahlen identisch zum `fa_master_export_2026-07-13.sqlite` (das nach demo ging). Cleanup am Master ⇒ deckt sich mit demo. Referentielle Integrität sauber (0 Waisen-Refs).

| Ebene | Kennzahl | Ist | Bewertung |
|---|---|---|---|
| **1 LA** | supplier_items gesamt / GP-gemappt / needs_review | 264.516 / 10.142 / 597 | Arbeitsmenge = 10.142 |
| **2 GP** | approved / tentative | 6.962 / 785 | |
| | approved+requires_la ohne Lead-LA | 1.372 | 🔴 |
| | **genutzte** approved-GPs preislos | **495** (107 auto-fixbar / 398 echte Lücke) | 🔴 **Preis-Wurzel** |
| | Allergen-Konfidenz persistiert | 14 / 6.962 | 🔴 (on-read gerechnet, nie in Spalte) |
| | tentative GPs in Rezepten genutzt | 27 | 🟡 Prinzip-Verstoß |
| | **Anker fehlt (genutzte approved)** | **84** | 🟡 Erdung |
| | Feld-Lücken: condition / sub_category / tag_vegan | 164 / 130 / 68 | 🟡 |
| | nutri leer | 6.952 | ⚠️ löst live aus LA auf — KI-Fallback nur wo LA leer |
| **3 Basisrezept** | EK null / teil-unbepreist (von 2.291) | 88 / 789 | 🔴 Folge Ebene 2 |
| | **Anker fehlt** | **92** | 🟡 |
| | Sensorik fehlt / Sektor fehlt | ~897 (alle Rez.) / 160 | 🟡 (Niveau/Textur/Beschreibung/Zubereitung praktisch komplett) |
| **4 Gericht (VK)** | EK null / teil-unbepreist (von 929) | 20 / 219 | 🔴 Folge Ebene 2 |
| | **Anker fehlt** (graph-blind) | **151** | 🟡 |
| | unbestimmt-Servierform (Standard) | 329 (alle mit Hauptgruppe) | 🔴 |
| | fb2027 Rest-Stubs | 12 | 🟡 |
| **Quer** | Zutat gemini_proposed (unverifiziert) | 9.622 | 🟡 Vertrauen dünn |
| | Zutat unmatched / beide-null | 4 / 33 | 🟢 klein |

### Anreicherungs-/Erdungs-Matrix (vollständiger Sweep aller Neben-Tabellen)

| Neben-Tabelle | Abdeckung gesamt (3220) | davon VK (929) | Disposition |
|---|---|---|---|
| recipe_textures | 3217 | 929 | 🟢 komplett |
| recipe_level_suitability | 3209 | 924 | 🟢 quasi komplett |
| recipe_sector_suitability | 3060 | 775 | 🟡 154 VK / ~160 gesamt füllen |
| recipe_anchor_mappings (Zutaten-Anker) | 2977 | 778 | 🟡 84 GP + 92 BR + 151 VK füllen |
| **recipe_process_anchors (Prozessanker)** | **696** | **266** | 🟠 **nur wo die Verarbeitung es hergibt** (Röst/Grill/Rauch/Karamell/Ferment) — NICHT flächendeckend. Kalt-/Assembly-/Dip-Gerichte behalten korrekt 0. 4er-Vokab, 485 kamen aus `raw_text_prep`. Ziel = Korrektheit, nicht Vollabdeckung. |
| recipe_taste_vectors (Sensorik) | 2323 | **37** | ⚠️ BR ~komplett; VK 37/929 → **erst klären: gespeichert vs. komponenten-abgeleitet** (kein Blind-Fill) |
| recipe_equipment | 2147 | **30** | ⚠️ VK dünn → gleiche Frage (abgeleitet vom Basisrezept?) |
| recipe_pairings | 1398 | **5** | ⚠️ VK-Pairings quasi leer → höchstwahrscheinlich komponenten-abgeleitet, NICHT per-VK gespeichert |
| recipe_regenerations | **1** | 1 | ⏭️ eigener Track **R5.3/HACCP** (nicht dieser Plan) |
| gp_anchor_mappings / gp_taste_vectors / gp_textures | 7554 / 7704 / 7499 | — | 🟢 gut abgedeckt (nur 84 genutzte-GP-Anker-Lücken) |

**Zwei Anker-Dimensionen (bisher übersehen):** Zutaten-Anker (`recipe_anchor_mappings`, aus GP-Erdung) UND **Prozessanker** (`recipe_process_anchors`, aus der Verarbeitung — Röst/Grill/Karamell/Ferment). Beide müssen geerdet werden; Prozessanker sind das größere Loch und deterministisch-freundlich.

**Entscheidungen Dominique:** (1) unbestimmt-Servierformen → **KI je Gericht**; (2) 2 WaWi-Ära-Checks (FA↔WaWi-EK-Divergenz, nutri-Sync 235) → **obsolet streichen**; (3) **Anker setzen + volle Anreicherung** mit rein; (4) KI-Schritte (Etappe 2) via **OpenAI über den `core`-Provider — konfiguriert AUF DEMO** (Server-`.env`, Christian/Martin), KI läuft serverseitig auf demo, Review in der demo-UI, **kein Re-Import** (Entscheid 2026-07-15, überschreibt „lokal + Re-Import"); Cloud/extern akzeptiert, minimaler Datensatz; MCP = Trigger-Ebene, Bulk = artisan-Commands auf demo; (5) **deterministische Wurzel zuerst** (Etappe 1 = 0 externer Datenabfluss), KI-Schritte als Etappe 2.

## Korrekturen (aus Code-Verifikation — vor Ausführung beachten)

1. **`gps.allergen_*` sind die OVERRIDE-Schicht, keine Aggregat-Werte.** Sie mit berechneten LA-MAX-Werten zu füllen würde GPs dauerhaft als Override einfrieren, Derivat-LIVE-Vererbung brechen und die „LA fixen → GP heilt"-Kaskade zerstören. → Allergen-Backfill schreibt **nur** `allergens_source/_confidence/_aggregated_at`, **nie** die 14 Wert-Spalten.
2. **Allergen-Backfill ist KEINE Vorbedingung fürs Recompute** — Rezepte lösen Allergene live über die LA-Zeilen auf. Nur der **Lead-LA-Repick** muss vor `recomputeAll` laufen (Recompute liest `gp.lead_la_supplier_item_id` für den Preis).
3. `allergens_confidence` ist `decimal(4,3)`, der Service liefert kategorial → Map (high=1.0/medium=0.66/low=0.33/none=0.0). Kein `needs_allergen_review`-Spalte → Konflikte als **Signal**.
4. **Analog nutri:** GP-nutri löst live aus LA auf (wie Allergene) → nicht blind in die Spalte schreiben; KI-Fallback (`gp.naehrwerte`) nur wo die LA keine Nährwerte hat.
5. **Anreicherung = Lücken füllen, nicht überschreiben.** Bereits gefüllte/`manual`/live-auflösende Felder bleiben unangetastet (KI-DoD). „Volle Anreicherung" = jedes leere KI-Feld bekommt einen Wert, kein Blind-Rewrite.

## Approach

**Grundprinzip:** Fixes über die **vorhandenen Services** (kein Roh-SQL für Mutationen — nur Reports); Fixes propagieren per Recompute nach oben; idempotent, `--dry-run` default / `--apply` / `--verify`, team-scoped (`--team`, lokal 1), Backup vor jedem `--apply`; KI-Writes `status=draft`/Proposal + `created_via` + menschliche Freigabe.

### Zu bauen — thin Commands + eine Service-Erweiterung

| Command | wrappt | Etappe |
|---|---|---|
| `foodalchemist:data-quality {--team}` | neuer `DataQualityService` (reuse `SignalDetektorService`/`CoverageService`) | 1 — **Mess-Ampel**, per-Ebene-Counts → Report + optional Signale, idempotent/schedulebar |
| `foodalchemist:recompute {--all\|--recipe= --propagate --apply}` | `RecipeRecomputeService::recomputeAll()`/`recomputeAndPropagate()` (global, Kahn-topologisch) | 1 |
| `foodalchemist:lead-la-repick {--team --used-only --apply}` | `LeadLaService::applyLeadLa()` | 1 — die 107 |
| `foodalchemist:gp-allergen-backfill {--chunk=500 --apply}` | `GpAggregateService::allergenKonfidenz()`+`allergene()` → **nur Metadaten-Spalten** | 1 — die 6.948 |
| `foodalchemist:enrich-bulk {--recipes\|--gps --steps= --gaps-only --apply}` | `BulkEnrichService::starte`/`starteGp` (+ erweiterte Schritte) | 2 — GP condition/tags/nutri-Fallback; Rezept sensorik/sektor |
| `foodalchemist:anchor-ground {--gps\|--recipes --missing-only --apply}` | `AiGatewayService::propose('gp.anker'\|'recipe.anker')` → staging → accept | 2 — Zutaten-Anker: 84 GP + 243 Rezept |
| `foodalchemist:process-anchor-ground {--missing-only --apply}` | Zubereitungstext-Parser (`raw_text_prep`-Muster, 4er-Vokab) + KI-Rest (neuer Prompt `recipe.prozessanker`) → `recipe_process_anchors` | 1 (Parser) + 2 (KI-Rest) — 2.524 fehlen |
| `foodalchemist:serving-form-classify {--team --apply}` | neuer Prompt `vk.servierform` + Service analog `SpeisenKlassenService::classify` + MCP-Tool analog `RecipeKlassePostTool` | 2 — die 329 |
| `foodalchemist:gp-gap-match {--team --apply}` | `LeadLaService::kandidatenFuerGp`/`MatchService` + KI-Ranking (Muster 216/217) | 2 — die 398 |
| `foodalchemist:ingredient-verify {--min=0.85 --apply}` | `IngredientMatchService::matchIngredient()` | 2 — die 9.622 |

**Service-Erweiterung:** `BulkEnrichService` verdrahtet heute nur `SCHRITTE=[description,category,geschmack]` / `SCHRITTE_GP=[condition,tags,allergene,naehrwerte]`. Für „volle Anreicherung" die Accept-Pfade für `anker`, `sensorik`, `sektor`, `level`, `pairing` ergänzen (Prompts existieren bereits). `SensorikService::bewerteRezept()` für die Sensorik-Achse einhängen.

### Etappe 1 — Deterministische Wurzel (kein LLM, lokal sofort)

Reihenfolge zwingend — GP-Ebene heilen **vor** Recompute:
0. **Backup** (`fa_mysql_PRE_DQ_CASCADE_*.sql.gz`).
1. **Ampel bauen + Baseline-Lauf** → Report fixiert Ist je Ebene.
2. **Lead-LA-Repick** (107). Verify: `lead_loest_auf` ↑.
3. **GP-Allergen-Backfill** (6.948, nur Metadaten). Konflikte → Signal.
4. **tentative-in-Rezept** (27): Review-Liste — approven wenn reif, sonst durch approved ersetzen (`GpService::ersetzeInRezepten`).
5. **Recompute-Propagation** (`recompute --all`): heilt EK/Allergen/Yield der Basisrezepte + Gerichte. Verify: teil-unbepreist ↓, EK-Abdeckung ↑, 0 Zyklen.
6. **Prozessanker deterministisch** (`process-anchor-ground` Parser-Modus): Zubereitungstext parsen (`raw_text_prep`-Muster, 4er-Vokab) → Anker **nur wo ein echter Prozess-Marker steht** (Röst/Grill/Rauch/Karamell/Ferment); kein Marker → korrekt kein Anker (**keine Erfindung**). Nur mehrdeutige Prep-Texte → Etappe 2 (KI).
7. **fb2027-Stubs (12) + beide-null-Zutaten (33)**: Review/Park.
8. **Restlücken-Park-Liste** (398 GPs → betroffene Gerichte) mit Begründung.

### Etappe 2 — KI-gestützt (AUF DEMO, OpenAI via Core-Provider)

> **Architektur-Entscheid 2026-07-15 (überschreibt „lokal + Re-Import"):** Die KI-Schritte laufen **direkt auf demo** — weil die Review/Freigabe-Schleife (Draft/Staging → ReviewQueue/Editor) im **demo-UI** lebt. KI schreibt Draft auf demo → Mensch gibt in der demo-UI frei → **kein Re-Import**. **MCP = Steuer-/Trigger-Ebene, kein eigener Ort:** MCP-Tools rufen die KI serverseitig auf demo über denselben `core`-Provider auf. **Bulk** (329 Serving-Formen, 398 GP-Match, Anker) → artisan-Commands auf demo unter `php8.4`; **einzeln/interaktiv** → MCP-Tool. LLM-Inferenz immer serverseitig auf demo.

8. **LLM-Setup auf DEMO (Christian/Martin):** `FOODALCHEMIST_AI_PROVIDER=core` + OpenAI-Key in die **Server-`.env`** (aktuell `fake`/unbound — blockt auch den R6.1-Blindtest; **ich fasse keine Secrets an**) + Tier-Modelle; `AiGatewayService::propose` gegen echt statt `fake` verifizieren. KI-Prompts **minimal** halten (Namen/Zutaten/Hauptgruppe — keine Preise/Margen/Kundendaten/PII). Kein Core-Change. Danach: KI-Commands auf demo mit `php8.4 artisan …` (siehe `Composer_Update_FA.md`).
9. **Zutaten-Anker-Erdung** (`anchor-ground`): 84 GP + 92 Basisrezept + 151 VK → Vorschlag → Review → accept. **Prozessanker-KI-Rest** (`process-anchor-ground` KI-Modus nur für mehrdeutige Prep-Texte; KI darf/soll „kein Prozessanker" zurückgeben — kein Zwangs-Anker).
9b. **VK-Neben-Tabellen klären** (vor Fill): bestimmen, welche VK-Felder (Sensorik/Pairing/Equipment) gespeichert vs. **aus Komponenten abgeleitet** sind (`SensorikService`-Pfad prüfen) — abgeleitete NICHT per-VK füllen (Korrektur #5). Nur echte Speicher-Lücken in den Fill nehmen.
10. **Volle Anreicherung** (`enrich-bulk`, gaps-only): GP condition (164)/sub_category (130)/tags (68)/nutri-Fallback; Rezept-Sensorik + Sektor (160) für die als „gespeichert" bestätigten Ebenen. Staging → `alleUebernehmen` nach Review.
11. **Serving-Form-KI** (329) → Review → accept.
12. **GP-Lücken-Match** (398) gegen 264k-Katalog → staging → accept → recompute.
13. **gemini_proposed-Verifikation** (9.622) nutzungspriorisiert, **§2-Kontext** (Verarbeitungs-Reduktion ≠ Fehler, Lehre Skript 215). Review-Gate.
14. **Recompute + Voll-Ampel grün**.

### Wiederholbarkeit (Routine)

`foodalchemist:data-quality` + `foodalchemist:signale-detektor` via Scheduler → stehende Datenqualitäts-Wache statt Einmal-Aktion.

### Demo heilen — direkt auf demo, KEIN Re-Import (Entscheid 2026-07-15)

Unified-Modell: die Remediation-Commands laufen **direkt auf demo** (SSH `forge@demo.bhgdigital.de`, im App-Dir, `php8.4 artisan … --apply`) — deterministisch (Etappe 1) wie KI (Etappe 2). Review/Freigabe in der demo-UI. **Kein `import-master`/Re-Import nötig.** Der lokale Master (`foodalchemist_full`) bleibt die **Dev-/Verifikations-Kopie** (Commands dort erst dry-run/verifizieren, dann auf demo scharf).
- **Reihenfolge auf demo (Etappe 1):** Backup (server-seitig) → `lead-la-repick --apply` → `gp-allergen-backfill --apply` → `recompute --all --apply` → `data-quality --signals`. (Aktuell zeigt demo noch den ungeheilten Stand — die lokalen Läufe waren die Generalprobe.)
- **Kein nacktes `composer update` ohne Commit auf dem Server** (siehe `Composer_Update_FA.md`); Deploy des Codes bleibt Christians Skript/Strecke.
- `import-master --fresh` nur noch als Notfall-/Erstbefüllungs-Weg, nicht als regulärer Heilungs-Pfad.

## Safety

- Backup vor jedem `--apply`; alle Commands `--dry-run` default + idempotent + `--verify`; chunked (500) mit Transaktion je Chunk.
- Team-scoped (`--team`, lokal 1; `recomputeAll` ist global).
- KI: Proposal/draft + `created_via` + menschliche Freigabe; nie stiller Global-Edit; nie `manual`/gefüllte Felder überschreiben.
- **Datenabfluss (nur Etappe 2):** OpenAI = Cloud. Prompt-Kontext auf Namen/Zutaten/Hauptgruppe beschränken; **nie** Preise/Margen/Kundendaten/PII an den LLM. Voraussetzung: OpenAI-Key auf **demo (Server-`.env`, via Core-Contract/Martin — Entscheid 2026-07-15/18, ersetzt „sandbox-.env")**. Etappe 1 = 0 Egress.
- **Verzahnung (Nachtrag 2026-07-18):** die **398-Park-GP-Sourcing-Liste** = derselbe Fall wie [07_LA_First_GP_Mint_ueberall.md](07_LA_First_GP_Mint_ueberall.md) M4 (Proposal = Beschaffungs-Wunsch) + [13_Preis_Katalog_Ingest_Q2.md](13_Preis_Katalog_Ingest_Q2.md) (Katalog-Lücken) — EINE Sourcing-Backlog-Mechanik, nicht drei. `ingredient-verify` (9.622 gemini_proposed) profitiert vom #507-Hybrid-Matching ([02](02_RAG_System_FoodAlchemist.md), gebaut).
- Preis-Falle: `vergleichspreis` NULL bei `qty` NULL/0 (GL-03-A-2) — Ampel misst „löst auf Kosten auf", nicht „hat Preiszeile".
- **Korrektur #1 als Test abgesichert:** Allergen-Backfill lässt die 14 Wert-Spalten nachweislich unangetastet.

## Dateien (create/modify)

- **Neu (Services):** `DataQualityService`; ggf. `ServingFormClassifierService`.
- **Neu (Commands):** `DataQualityCommand`, `RecomputeCommand`, `LeadLaRepickCommand`, `GpAllergenBackfillCommand`, `ProcessAnchorGroundCommand` (Parser-Modus in Etappe 1); `EnrichBulkCommand`, `AnchorGroundCommand`, `ServingFormClassifyCommand`, `GpGapMatchCommand`, `IngredientVerifyCommand` (Etappe 2).
- **Ändern:** `BulkEnrichService` (Accept-Pfade anker/sensorik/sektor/level/pairing); `config/foodalchemist.php` (Prompts `vk.servierform`, `recipe.prozessanker`); `DarreichungService` (`ersetzeStandardServierform`); `src/FoodAlchemistServiceProvider.php` (Command- + MCP-Tool-Registrierung, Lockstep); `docs/ROADMAP.md` (R0.3-DoD: WaWi-Punkte streichen, Kaskaden-Ampel + Anker + Prozessanker + Anreicherung aufnehmen); `CLAUDE.md` (neue Commands); Memory je Etappe.
- **Neu (MCP):** `ServingFormPostTool` (Muster `RecipeKlassePostTool`); Lockstep für die neuen Bulk-Läufe.
- **Tests:** Pest je Service/Command; **Guard-Test** Allergen-Backfill (Wert-Spalten unberührt); Recompute-Topologie; Ampel-Idempotenz. Voll-Suite grün.

## Verification (je Ebene end-to-end)

- **Ampel Vor/Nach** ist der Kern-Beweis (per-Ebene Delta).
- **Etappe 1:** Lead-löst-auf +107; GP `allergens_confidence` NULL → ~0 (Wert-Spalten unverändert); VK/Basisrezept teil-unbepreist ↓; EK-Abdeckung (echtes „voll bepreist") ↑; Recompute 0 Zyklen; Live-Heal-Probe an 1 Rezept (EK/Allergen vorher/nachher + Parent-VK propagiert).
- **Etappe 1 (Prozessanker):** Parser setzt Anker **nur bei echten Prozess-Markern**; **Über-Tagging-Guard** (Stichprobe: Kalt-/Assembly-/Dip-Gericht hat korrekt 0 Prozessanker). Ziel = Korrektheit, nicht 100 %-Abdeckung.
- **Etappe 2:** Zutaten-Anker-Fehlend → 0; Prozessanker-KI-Rest → 0; Sektor + bestätigte Sensorik-Lücken → 0; 329 unbestimmt → 0; 398 gematcht/geparkt; gemini_proposed nutzungspriorisiert verifiziert. VK-abgeleitete Felder bewusst NICHT gefüllt (dokumentiert).
- **Regelwerk-Stichproben:** GP-Allergen ALL-MAXIMAL + Konfidenz (§16), Lead-LA-Heuristik (§8), Derivat-LIVE-Vererbung.
- **Tests + MySQL-Smoke** gegen `foodalchemist_full`; Voll-Suite grün (0 Regressionen).
