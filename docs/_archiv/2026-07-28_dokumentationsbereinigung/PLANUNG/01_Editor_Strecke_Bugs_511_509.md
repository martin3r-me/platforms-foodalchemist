# Bugfix-Plan #511 + #509 — Rezept-Editor-Strecke: Tausch-Kaskade + Create-Parität (Stand 2026-07-16)

> **Issues:** Dev-Modul office, Board 54 „Bugs" — **#511** „IngredientEditor Zutaten-Tausch: Kaskade/Auto-Sync geht nicht durch — (a) unbepreister GP, (b) Propagation/UI-Refresh" (high) + **#509** „Basisrezept ‚Anlegen' ist verlustbehaftete Hülle — inkonsistent zum Gericht-Create" (high, aufgenommen 2026-07-16 auf Wunsch Dominique — gleiche Baustelle RecipeModal/RecipeService, eine Umsetzungs-Session).
> **User-Repro:** Rezept `getreidesalat_bulgur_mit_berglinsen_und_cashews_2527`, Limette↔Petersilie getauscht + Mengen angepasst → EK partiell (7/8) + Eltern-VK stale bis Reload.
> **Quellen:** Issue #511, ROADMAP-Changelog 2026-07-15, Code-Kartierung 2026-07-16 (Datei:Zeile unten verifiziert).

---

## 0. ✅ UMGESETZT 2026-07-16 (Session-Abschluss)

**Alles gebaut, getestet, gepusht. Commit `0eac4fe` auf `origin/main`** (nur `platforms-foodalchemist`: 7 Src/Doc + 2 Test-Files, kein Fremdmodul).

**Erledigt:**
- **F1 (#511b):** Repro-Test belegt zuerst die Server-These — Sub-Tausch propagiert den EK server-seitig sauber bis zum Eltern (4→10 €). *Kartierungs-Korrektur:* ein Livewire-`#[On]`-Listener re-rendert AUCH mit leerem Rumpf → die „4 UI-Löcher" waren teils harmlos; der echte Bruch war das **fehlende Kosten-Signal** + fehlende Eltern-IDs. Fix: `recomputeAndPropagate(): array` (neuer Helfer `betroffeneRezepte()`, abwärtskompatibel) + `IngredientEditor::speichern` dispatcht `kosten-aktualisiert` (recipe_id + Eltern-IDs) + `Recipes/DetailPanel` `recipe-gespeichert`-Re-Render-Hook (Kopf frisch auch embedded). Server-Recompute unberührt (I8/I9).
- **F2 (#511a):** amber `⚠︎` je Zeile + Σ-Zeile „n von m bepreist" bei GP/Sub ohne Preis. Greift live bei ⇄/♻.
- **F4:** E2E-Test des Editor-Tauschwegs durch die Livewire-Komponente (Payload wie ⇄/♻ → speichern → Propagate → Events + Eltern-EK frisch).
- **F5 (#509):** `RecipeService::create` = Feld-Parität mit `update` (temperature/function/preparation/notes_manual/yield_pieces + Equipment-Sync); `RecipeModal::speichern` springt nach Anlegen in den Edit-Modus (`ladeRezept($id)`, VkModal-Muster).

**Verifikation:** neue Tests `IngredientSwapPropagationTest` + `RecipeCreateParityTest`; **Voll-Suite 704/705 grün** (1 bekannter skip); **MySQL-Smoke** (Fixture Team 1, rollback): unbepreister GP „Petersilie glatt" (8194) → `preisProGrammPublic`=NULL (activePriceSubquery real auf MySQL), Create-Felder persistiert unter strict mode.

**Board (office, Package 23):** #511 + #509 → **Review** (DoD abgehakt; #511-DoD „unbepreiste GPs heilen" bleibt offen = F3), Sync-Discussion #18. ROADMAP-Changelog liegt im Commit.

**🔲 OFFEN (nicht in dieser Session):**
1. **Deploy auf demo** — läuft noch auf `e2586c4` (vor dem Fix). `php8.4 composer update martin3r/platform-foodalchemist` auf Forge → Dominique prüft/fährt selbst (stehende Regel Live-Deploy = Forge/Martin).
2. **Live-Klickstrecke im Browser** (nach Deploy) → dann #511/#509 von Review → **Done**. (#511-UI ist nicht MCP-testbar, nur Browser.)
3. **F3 = Daten-Heilung** unbepreiste GPs (Petersilie-Klasse, 661/1.417) = **R0.3-Etappe-2** (Sourcing/GP-Lücken-Match) — eigener Track, kein Editor-Fix.
4. *(optional)* Permission-Regel `mcp__claude_ai_office_bhgdigital__execute` in `~/.claude/settings.json` — dann läuft die FA-Board-Sync künftig ohne Nachfrage (musste manuell gesetzt werden, Self-Modification-Guard).

---

## 1. Befund — präzisiert gegen den Code

### Ursache (a) — Tausch auf unbepreisten GP: DATEN + fehlende Warnung

- `IngredientEditor::ekFuerZiel()` (`src/Livewire/Recipes/IngredientEditor.php:289-304`) liefert den Live-Näherungs-EK via `RecipeRecomputeService::preisProGrammPublic()` → `preisProGrammFuer()` (`RecipeRecomputeService.php:875-899`): Lead-LA zuerst, Fallback AVG über alle aktiven kg/l-LAs, **kein bepreister LA → `null`**.
- Im Recompute zählt die Zutat dann als unpriced (`zutatKosten` → `[0.0, false]`, `RecipeRecomputeService.php:727-762`) — Rezept-EK wird trotzdem gesetzt, nur ohne diese Zutat (`ek_n_ingredients_priced < ek_n_ingredients_total`). **Stiller Teil-EK.**
- **UI-Warnung existiert nicht:** `zutaten-kern.blade.php:171` rendert bei `ek_pro_g === null` nur ein graues `—`; `summe()` (`ingredient-editor.blade.php:197-204`) überspringt die Zeile stumm. Der Server *kennt* die Lücke (priced/total-Zähler + Signal `EkKetteUnvollstaendig`), der Editor zeigt sie nicht.
- Daten-Seite: „Petersilie glatt: frisch, ganz" hat **keinen bepreisten LA irgendwo** → `lead-la-repick` kann NICHT heilen (Park-Bucket „echte Lücke → Sourcing", `LeadLaRepickCommand.php:86/98`). Das ist die R0.3-Etappe-2-Klasse (Issue nennt 661 „Lead ohne Preis" / 1.417 „ohne Lead" aus der DQ-Ampel; Repick heilt nur die Teilmenge mit vorhandenem bepreisten Alternativ-LA).

### Ursache (b) — Propagation/UI-Refresh: **Server OK, Bruch ist die Livewire-Event-Kette**

Wichtigste Erkenntnis der Kartierung (ändert die Fix-Richtung gegenüber dem Issue-Text NICHT, macht sie aber konkret):

- **Server-seitig läuft alles:** `RecipeService::syncIngredients()` (`RecipeService.php:419-487`) ruft in Zeile 484 `recomputeAndPropagate()` → BFS über alle transitiven Eltern (Limit 10), **topologisch** (Kinder vor Eltern, diamond-sicher), inkl. `DarreichungService::recomputeFuerRezept()` je Pipeline-Lauf (`RecipeRecomputeService.php:83-120`). Eltern-VK werden mitgerechnet. Fehler werden geloggt, nicht geworfen (I8 — bewusst).
- **Der Bruch ist client-seitig, vier konkrete Löcher:**
  1. `Kalkulation/Index.php:28` hört auf `kosten-aktualisiert` — **der Editor dispatcht dieses Event nie** (`speichern()` dispatcht nur `recipe-gespeichert` + `recipe-selected`, `IngredientEditor.php:65-66`). → Kalkulations-Panel dauerhaft stale nach Zutaten-Save.
  2. `Verkauf/DetailPanel::aktualisiere()` (`Verkauf/DetailPanel.php:34-37`) hat einen **leeren Rumpf** — verlässt sich rein auf Re-Render; gezielter Eltern-Refresh fehlt.
  3. `Recipes/DetailPanel::zeige()` **ignoriert `recipe-selected` bei `embedded === true`** (`DetailPanel.php:51-53`) — der Rezept-Kopf (EK/Yield/Allergene) im Editor-Kontext hängt allein am generischen Re-Render.
  4. Es gibt **kein Eltern-Event**: der Editor kennt nach dem Save die betroffenen Eltern-Rezepte nicht und benachrichtigt sie nicht — ein offenes Eltern-VK erfährt vom Basisrezept-Edit nichts Gezieltes.
- **Nebenbefund Architektur:** Der Editor-Tausch (⇄ Produkt-Tausch + ♻ Äquivalenz-Tausch) ist **komplett client-seitig** (Alpine `rows`, `ingredient-editor.blade.php:91-151`) und persistiert erst beim Speichern. Der Server-Tausch-Weg `ComponentEquivalentService::tauscheZutat()` (inkl. sofortigem Propagate) wird nur vom Concepter genutzt — NICHT vom Editor. Kein Bug, aber wichtig: der Fix gehört in die Save-/Event-Strecke, nicht in den Tausch-Moment.

### Test-Lücke (mitgefunden)

`IngredientEditorTest` deckt `syncIngredients` + Modal-Roundtrip ab, `ErsatzTauschTest` nur den **Service**-Tauschweg. Der **client-seitige ⇄/♻-Tausch → speichern → Eltern-EK aktuell**-Pfad (= das #511-Szenario) ist **nicht** end-to-end getestet — genau deshalb konnte das Loch unbemerkt bleiben.

---

## 2. Fix-Plan (Etappen)

> Empfehlung Reihenfolge: **F1 zuerst** (b ist neue, eigenständige Arbeit + der sichtbarste Schmerz), F2 direkt danach (kleine UI-Arbeit, gleiche Dateien), F3 läuft als Datenarbeit ohnehin im R0.3-Track. Eigene Umsetzungs-Session (braucht laufende Sandbox + MySQL für die Repro).

### F1 — (b) Repro + Event-/Refresh-Kette fixen · Größe M

**Repro zuerst (Beweis vor Fix):** Basisrezept mit Eltern-VK im Dev-MySQL; Zutat tauschen + speichern; DB-seitig belegen, dass `ek_total_eur` von Kind UND Eltern aktualisiert sind (Server-These bestätigen), während UI stale bleibt. Erst dann fixen.

**DoD:**
- [ ] Repro dokumentiert: nach Save sind Kind- + Eltern-EK in der DB frisch (Server-Propagation bewiesen), UI zeigt alt → Root-Cause = Event-Kette bestätigt (oder ehrlich revidiert, falls doch server-seitig etwas hängt — Log-Check auf geschluckte Pipeline-Fehler, I8)
- [ ] `IngredientEditor::speichern()` dispatcht zusätzlich `kosten-aktualisiert` (+ ggf. Payload `recipe_id` + betroffene Eltern-IDs aus dem Propagate-Rückweg — `recomputeAndPropagate` kann die gerechneten IDs zurückgeben, minimal-invasiv)
- [ ] Eltern-Benachrichtigung: Listener in `Verkauf/Browser`/`Verkauf/DetailPanel` reagieren gezielt (DetailPanel lädt neu, wenn sein `recipeId` unter den propagierten IDs ist) — leere `aktualisiere()`-Rümpfe bekommen echten Refresh oder werden bewusst entfernt (toter Code)
- [ ] `Recipes/DetailPanel` embedded-Fall: Rezept-Kopf (EK/Yield/Allergene) aktualisiert nach Save nachweislich — der `embedded`-Guard in `zeige()` darf den Kopf-Refresh nicht mit ausschalten
- [ ] `Kalkulation/Index` aktualisiert nach Zutaten-Save (hört jetzt auf ein Event, das auch gefeuert wird)
- [ ] Pest: Livewire-Test „Zutat-Tausch + speichern → assertDispatched(kosten-aktualisiert) + Eltern-`ek_total_eur` frisch" (Propagation-Assertion = Issue-DoD 5); bestehende `IngredientEditorTest`/`ErsatzTauschTest` grün
- [ ] Kein Verhalten am Server-Recompute geändert (I8 Logging bleibt, I9 `vk_*` wird weiter nie geschrieben)

### F2 — (a) Editor-Warnung „unbepreist" · Größe S

- [ ] Row-Level-Badge in `zutaten-kern.blade.php` (~Z.171): `gp_id` gesetzt + `ek_pro_g === null` → sichtbarer amber-Hinweis „kein Preis — EK unvollständig" statt stilles graues `—` (greift live schon beim Tausch, weil `ekFuerZiel` genau dann null liefert)
- [ ] Σ-Zeile (~Z.228-237): Zusatz „n von m Zutaten bepreist" wenn `priced < total` (Server kennt die Zähler; für den Client reicht das Zählen der null-EK-Zeilen)
- [ ] Gleiches Muster im ♻-Äquivalenz-Tausch-Flow (Faktor-Umrechnung zeigt Warnung, wenn Ziel unbepreist)
- [ ] Pest: Blade-/Livewire-Assertion auf den Warnhinweis bei unbepreistem Ziel
- [ ] ⚠️ Test-Falle: `activePriceSubquery` (`PriceService.php:43`, korrelierte Subquery) ist die wahrscheinlichste SQLite/MySQL-Divergenz für „EK null" — Repro-Test im Zweifel als MySQL-Smoke, SQLite-Grenze dokumentieren (R0.5-Muster)

### F3 — (a) Daten-Heilung · läuft im R0.3-Track (keine neue Arbeit hier)

- [ ] `foodalchemist:lead-la-repick --apply` über den Bestand → heilt die Teilmenge mit vorhandenem bepreisten Alternativ-LA (Etappe-1-Mechanik, schon gebaut + gepusht `7ec8ad6`)
- [ ] Rest = Park-Bucket „kein bepreister LA irgendwo" (u. a. „Petersilie glatt: frisch, ganz") → **Sourcing-Liste** = R0.3-**Etappe 2** (GP-Lücken-Match/LA-Beschaffung, KI-gestützt). Kein Editor-Fix kann das heilen — nur sichtbar machen (F2)
- [ ] Nach Heilung: `foodalchemist:recompute --all --propagate` (bestehender Command), damit geheilte Preise in Rezepte/VK hochlaufen
- [ ] Abschluss-Check am Repro-Rezept: `getreidesalat_..._2527` zeigt 8/8 bepreist, sobald der Petersilien-GP einen bepreisten LA hat

### F4 — Test-Lücke dauerhaft schließen · Größe S (mit F1 zusammen)

- [ ] End-to-End-Test des **Editor**-Tauschwegs (client rows → `speichern` → `syncIngredients` → Propagate → Events) — der heute ungetestete Pfad; verhindert Regression der Event-Kette
- [ ] `ErsatzTauschTest` um Editor-seitigen ♻-Fall ergänzen (bisher nur Service-Weg)

---

### F5 — #509 Create-Parität: Basisrezept-Anlegen nach VkModal-Muster · Größe M

**Befund (aus #509, code-verifiziert 2026-07-15 — Ursache liegt vollständig dokumentiert im Issue):** `RecipeService::create` und `::update` sind auseinandergelaufen — `update` ist Voll-Writer, `create` verwirft still `temperature`/`function`/`preparation`/`notes_manual`/`yield_pieces`/Equipment; Zutaten sind beim Anlegen gar nicht erfassbar (Editor an `recipeId=null`); Deklaration & Co. sind `@if($recipeId !== null)`-Tabs; nach dem Anlegen schließt das Modal → Rezept muss neu gesucht werden. **Referenz-Lösung existiert im selben Modul:** `VkModal::anlegen()` → minimaler Create → `oeffnen($id)` = nahtloser Sprung in den Edit-Modus, `updateVk` = Voll-Writer.

**DoD (= Issue-#509-DoD, hier als Etappe):**
- [ ] `RecipeService::create` auf Feld-Parität mit `::update` (temperature, function, preparation, notes_manual, yield_pieces + Equipment-Sync) — stoppt den stillen Datenverlust
- [ ] `RecipeModal::speichern()` im Create-Fall: Modal NICHT schließen, sondern `ladeRezept($recipe->id)` → Edit-Modus (VkModal-Muster) — Zutaten/Deklaration sofort befüllbar
- [ ] Create-Modus zeigt keine inerten Tabs mehr (Stammdaten-only bis 1. Speichern) ODER Zutaten-Client-Puffer + `create($zutaten)` — Entscheid beim Bau; die Service-Ebene kann One-Shot bereits (MCP `recipes.POST` nimmt Zutaten)
- [ ] Pest + MySQL-Smoke: getipptes preparation/notes/temperature im Anlege-Modal landet in der DB; keine Regression an `update()`/VkModal
- [ ] Synergie mit F1 prüfen: der Create→Edit-Übergang nutzt dieselbe Event-/Refresh-Kette — F1-Fixes gelten mit

## 3. Mapping auf die Issue-DoD (#511 + #509)

| Issue-DoD | Etappe |
|---|---|
| (a) Editor warnt sichtbar bei `ekFuerZiel=null` | F2 |
| (a) unbepreiste GPs geheilt (repick / Lücken-Match) | F3 (R0.3-Track) |
| (b) reproduziert: EK Kind + Eltern-VK ohne Reload aktuell | F1 |
| (b) Root-Cause Event-/Refresh-Kette gefunden + gefixt | F1 (4 konkrete Löcher, s. Befund) |
| Pest: Eltern-EK nach Sub-Rezept-Edit | F1 + F4 |
| #509 alle 5 DoD (Create-Parität, Edit-Übergang, Zutaten im Anlege-Flow, keine inerten Tabs, Pest) | F5 |

## 4. Risiken & Leitplanken

1. **Nicht am Server-Recompute drehen:** Propagation ist topologisch korrekt und golden-getestet — der Fix ist Event-/UI-Arbeit. I8 (Fehler loggen, Edit nie blocken) und I9 (`vk_*` nie schreiben) bleiben unangetastet.
2. **Event-Inflation vermeiden:** ein gezieltes `kosten-aktualisiert` mit propagierten IDs statt Breitband-Re-Render aller Browser (Perf bei großen Listen).
3. **Repro vor Fix (Verify-before-claiming):** erst DB-Beweis Kind+Eltern frisch / UI stale — falls wider Erwarten der Server hängt (geschluckter Pipeline-Fehler im Log), ändert sich die Fix-Richtung.
4. **SQLite-Falle:** Preis-Auflösung (`activePriceSubquery`) im Test ggf. nur als MySQL-Smoke beweisbar — dokumentieren statt grün-lügen.
5. **MCP-Lockstep-Check:** reine UI-/Event-Fixes brauchen kein Tool-Update; falls F1 doch `recomputeAndPropagate`-Rückgabewerte ändert → prüfen, ob ein Tool die Methode konsumiert.
6. **Zahlen-Hygiene:** die Issue-Zahlen (661/1.417) sind DQ-Ampel-Stand 2026-07-15 — vor F3-Lauf frisch messen (`foodalchemist:data-quality`), nicht auf alte Counts fixen.

## 5. Empfehlung Session-Zuschnitt

**Eine Umsetzungs-Session für F1 + F2 + F5** — alle drei leben in RecipeModal/RecipeService/Editor-Blades, brauchen dieselbe laufende Sandbox + Dev-MySQL-Repro. Reihenfolge in der Session: F1 (Repro + Event-Kette, das Debugging-Stück) → F5 (Create-Parität, mechanisch, klare Vorlage VkModal) → F2 (Warnung, kleinste Einheit). F4-Tests entstehen dabei. F3 ist kein eigener Aufwand — hängt am R0.3-Etappe-2-Zug.

---

*Erstellt 2026-07-16 (Planungs-Session). Verwandt: [02_RAG_System_FoodAlchemist.md](02_RAG_System_FoodAlchemist.md) (#507/#505/#508 — unabhängig; #508 teilt nur die syncIngredients-Fundstelle). Code-Referenzen verifiziert gegen Modul-HEAD 2026-07-16.*
