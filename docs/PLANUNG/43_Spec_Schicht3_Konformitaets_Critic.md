# Spec 43 — Schicht 3: Konformitäts-Critic (Artefakt §-genau gegen die Regelwerke, mit Selbstheilung)

> **Tracking:** Office Dev-Package 23, Features-Board. Neuer Service + Prompt + Async-Job + artefakt-agnostische Findings-Tabelle + Leitstelle-/Signal-Anzeige. **DB-Schema:** eine neue Tabelle (`foodalchemist_conformance_findings`). Komplementär zu **Spec 41** (Planungsmodul-Qualität — liefert die Regelwerke IN den Prompt = *Grounding*) und **Spec 36** (Rezeptqualität — die *Matching*-Achse, welche GP/LA verdrahtet werden). Diese Spec ist die **Konformitäts-Achse**: prüft das ERZEUGTE Artefakt gegen die Regelwerke.

**Status:** ✅ **KOMPLETT umgesetzt + deployt demo** (Slice 1–4, 2026-08-27/28). Gebaut mit Dominique in einer Session; alle Weichen live entschieden.

---

## Anlass

Die Qualitäts-Architektur hatte zwei Schichten, aber keine dritte:

| Schicht | Frage | Wo |
|---|---|---|
| **1 Grounding** | Welches *Wissen* liest die KI beim Schreiben? | `KnowledgeContextService::contextFor` (Spec 41: regelwerkBlock, Dossiers) |
| **2 Matching** | Welche *GP/LA* werden an die Zutat verdrahtet? | `IngredientMatchService` (Spec 36) |
| **3 Konformität** | Hält das ERZEUGTE Artefakt die Regelwerke ein? | **fehlte** → diese Spec |

Ein generierter Artefakt konnte gegen §6-Naming, §8-Pflichtangaben, §3-Warengruppe etc. verstoßen, ohne dass es jemand systematisch prüfte. Schicht 3 schließt die Lücke: ein **generischer Critic** prüft ein Artefakt **§-genau gegen die vollständigen Regelwerk-Dossiers** und **heilt Verstöße autonom** (eine Runde), der Rest wird als Hinweis sichtbar.

## Kern-Entscheidungen (User, live)

- **Ein generischer Pass, kein Pass pro Generator.** `ConformanceService` + ein Prompt (`conformance.check`) + je Artefakt-Typ ein kleiner Adapter (beschreibt das Artefakt + wählt die Regelwerke). Regelwerke kommen als `knowledge`-Option — **ungekappt** (hier zählt Vollständigkeit, nicht Relevanz wie beim Generator-Grounding).
- **Durchsetzung = Selbstheil-Loop, KEIN Hardstop-Block:** generieren → prüfen → (Verstoß) → **1× autonom revidieren** → nachprüfen → Rest = **prominenter Hinweis**. Reibungsarm.
- **Auslösung:** async-auto nach Generierung (Rezept/VK) bzw. beim Minten (GP) **+** on-demand Re-Check.
- **Anzeige zweigleisig:** in der **Leitstelle** (dort wo die Kaskade läuft — Rezept-Konformität + die GP-Konformität der Zutaten-GPs) **und** im **Signale-Cockpit** (system-weit).

## Umsetzung (Slice 1–4, alle deployt demo)

| Slice | Inhalt |
|---|---|
| **1 Spine** | Prompt `conformance.check` (Tier B) + `ConformanceService::pruefe` + `ConformanceAdapter`-Interface + `RecipeConformanceAdapter` (Basisrezept **und** VK, `is_sales_recipe` wählt die Regelwerke) |
| **2 Ablage + Loop** | Tabelle `foodalchemist_conformance_findings` (artefakt-agnostisch: `artifact_type`+`artifact_id`) + `pruefeUndHeile` (Selbstheil-Runde via `recipe.ueberarbeiten`) + `speichere` (wertfreier Fingerprint § + Feld, `verworfen` bleibt, `verschwunden`) |
| **3 Leitstelle** | `ConformanceCheckJob` (best-effort) auto nach Generierung (`GenerateRecipeJob` + nach der Anreicherung in `EnrichGeneratedRecipeJob`) + on-demand `Planung/Index::konformitaetPruefen` + Anzeige in der step-zeile (hart=rot/weich=gelb) |
| **4a GP/LA** | `GpConformanceAdapter` + `LaConformanceAdapter` + Interface-Flag `unterstuetztHeilung()` (Recipe/VK=true, GP/LA=false → Heil-Runde übersprungen, Verstöße direkt als Hinweis) |
| **4b Trigger** | `LaFirstGpService::mintFromLa` stößt bei NEU geminteten (tentativen) GPs den `ConformanceCheckJob(gp)` an |
| **4c-1 Leitstelle-GP** | `Planung/Index::render` baut je Rezept-Step die GP-Konformität seiner Zutaten-GPs (ein Query, dedupliziert) → step-zeile |
| **4c-2 Signal** | 2 neue `SignalTyp` (`KonformitaetGp`→NAVIGATE/GP-Editor, `KonformitaetLa`→OHNE_WEG/Necta-EINBAHN) + `DataQualityService::gp()`/`la()` zählen offene Findings → Signale-Cockpit |

Damit sind **alle vier Artefakt-Typen** abgedeckt (Basisrezept/VK/GP/LA). Rezept/VK haben KEINEN eigenen Signal-Typ — sie zeigen in der Leitstelle und überlappen mit den bestehenden `rezept_*`-Signalen.

## Betroffene Dateien (Kern)

- `src/Services/ConformanceService.php` · `src/Services/Conformance/{ConformanceAdapter,RecipeConformanceAdapter,GpConformanceAdapter,LaConformanceAdapter}.php`
- `src/Jobs/ConformanceCheckJob.php` · Hooks in `GenerateRecipeJob`/`EnrichGeneratedRecipeJob`/`LaFirstGpService`
- `src/Models/FoodAlchemistConformanceFinding.php` + Migration `2026_08_27_000003_...conformance_findings`
- `config/foodalchemist.php` (`conformance.check`) · `src/Enums/SignalTyp.php` · `src/Support/SignalCockpit.php` · `src/Services/DataQualityService.php`
- `src/Livewire/Planung/Index.php` + `resources/views/livewire/planung/partials/{ergebnis,step-zeile}.blade.php`
- Tests: `ConformanceServiceTest`, `ConformanceHealTest`, `ConformanceLeitstelleTest`, `ConformanceGpLaTest`, `ConformanceSignalTest` (+ `tests/Support/ConformanceHealStub`)

## Offene Folge-Punkte (kein Muss)

- LA-Trigger nach Katalog-Import (Necta-EINBAHN → nur informativ, niedrige Prio).
- Prompt `conformance.check` Tier B→A, falls der §-Recall im Feld zu schwach ist (Auto-Pass = kostenbewusst gestartet).
- GP/LA-**Feld-Revise** bauen → dann `unterstuetztHeilung()=true` (heute No-Op).
- Immediate-Feedback beim on-demand (heute dispatch + Poll).
- **Kosten beobachten:** jede Kaskade feuert Rezept-Check + GP-Check je geminteten GP → mehr LLM-Calls/Lauf (bewusst, Aufbau-Phase).

## Cross-Referenzen

- **Spec 41** (Planungsmodul-Qualität) — liefert die Regelwerke IN den Prompt; Schicht 3 prüft GEGEN sie. Direkte Fortsetzung.
- **Spec 36** (Rezeptqualität/Korrektur-Workflow) — die *Matching*-Achse (Wurzel B); Schicht 3 ist die *Konformitäts*-Achse. Komplementär, kein Überlapp.
- Wissensmodul-SSOT + Dossier-Umbau (Regelwerke als §-Dossiers, Master→Team 6) — die Datengrundlage, gegen die Schicht 3 prüft.
