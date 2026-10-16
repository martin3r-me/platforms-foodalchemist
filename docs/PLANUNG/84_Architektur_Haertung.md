# Spec 84 · Architektur-Härtung: Team-Kontext, Mandanten-Sicht, große PHP-Dateien

Stand 2026-10-16 · Status: **Entwurf, Sparring mit Session „Vanessa“ läuft** · Branch `docs/spec84-architektur-haertung`
Bezug: Spec 77 (77b–d Bereiche/Freigabe), Spec 82 (Team-Architektur, Master/Abo), #452 (KI-Abrechnung je Mandant)
Messbasis: Modul `platforms-foodalchemist` **86de1f92**, Core `platforms-core` **4a61b3ad**. Beide sind in `food-alchemist.de/composer.server.lock` gepinnt, also genau der Stand auf **foodalchemist.io**. Laravel 13.36, Livewire 4.4.

> **Einstieg für die bauende Session:**
> 1. Zuerst die Entscheidungen in §9 mit Dominique klären (vor allem E1 demo-Master und E2 Fehler statt leer).
> 2. Mit **§3 Teil A** beginnen (Team-Kontext). Das ist das Geld-Thema seit #452, klein und unabhängig.
> 3. **§4 B0** (Zwei-Mandanten-Test) parallel dazu bauen. Er berührt keinen Produktivcode und muss vor dem ersten echten Abo auf io stehen.
> 4. **§5 Teil C** wird nicht als Großumbau gebaut. Es sind Schnittpläne für den nächsten fachlichen Eingriff in die jeweilige Datei (Pfadfinder-Regel §5.0).
> 5. Jeder Schritt ist ein eigener PR ohne fachliche Änderung. Push/PR läuft über den Kurator ([[feedback_push_pr_je_aktion_freigabe_last]]).

## §0 · Anlass

Ein Entwickler meldete: „viele PHP-Seiten sind einfach zu groß“. Die Messung bestätigt das, zeigt aber zwei schwerere Themen darunter:

1. **Team-Kontext:** Wer „das aktuelle Team“ ist, wird auf vier Wegen bestimmt. KI-Budget, Kill-Switch, Call-Log und seit #452 die Abrechnung können dadurch auf das falsche Team laufen.
2. **Mandanten-Sicht:** Die Sichtbarkeitsregel ist gut gebaut, muss aber bei jeder Abfrage von Hand eingeschaltet werden. Kurz vor dem ersten echten Abo ist das ein Risiko.

Größe ist ein Symptom, kein Fehler an sich. Deshalb hat Teil C (Dateien) niedrigere Priorität als A und B.

## §1 · Was gut ist und bleibt

- **Eine Sichtbarkeitsregel an einer Stelle:** `BelongsToTeamHierarchy::scopeVisibleToTeam()` (148 Modelle) und `TeamScope::applyVisible()` (rohe Queries) lesen dieselbe Regel. Sie ist geschichtet: globaler Seed → Team-Kette → 77d-Freigabe-Hülle → Spec-82-Abo-Zweig (`AboSicht`, nur Fassungen) → Stamm-Paket-Opt-in.
- **Schreibrecht zentral:** `isOwnedBy()`, `TeamScope::owns()`, `mayWrite()`. Stichproben (Speiseplan-Chips, Produktionszeilen, Angebots-Kapitel) zeigen: Livewire lädt teils ungefiltert per `find()`, der Service prüft aber vor dem Schreiben mit `visibleToTeam` + `isOwnedBy`.
- **MCP-Tools sind dünn:** 606 Tools, nur 2 über 300 Zeilen, 7 direkte Schreibzugriffe. Team kommt richtig aus `ToolContext->team` (`FoodAlchemistTool::team()`).
- **Gesperrte Mandanten** (`TeamSperre`, `InhaltsSperre`) werden in Livewire (`\Livewire\on('call')`) und MCP (`FaRechteToolHuelle`) geprüft.
- **io-Stand:** Master = Team 1 (BHG.DIGITAL) gesetzt. Mandanten Broich (3), Foodpol (2), Sandbox (4) sind Hauptteams ohne Elternteam, alle `autark`, **noch kein Abo**.

## §2 · Begriffe

| Begriff | Bedeutung |
|---|---|
| **Betrachtendes Team** | Das Team, aus dessen Sicht gerade gelesen, gerechnet oder abgerechnet wird. |
| **Auftragsteam** | Das Team, für das ein Job versandt wurde. Gilt im Job, egal was der Nutzer inzwischen in der UI eingestellt hat. |
| **Team-Kontext** | Die eine Stelle, die das betrachtende Team liefert: `FaTeam::aktuell()` (§3.3). |
| **Sicht** | Was ein Team lesen darf (`scopeVisibleToTeam`). |
| **Ausnahme** | Bewusst teamübergreifendes Lesen (Master-Kuration, Import, Recompute), nur über `ohneTeamSicht('grund')`. |

## §3 · Teil A · Ein Team-Kontext

### §3.1 Was der Core schon hat

| Core-Baustein | Verhalten |
|---|---|
| `User::currentTeam` (Attribut) | `current_team_id`; bei Modulen mit `scope_type = parent` das Root-Team. **Beachtet den MCP-Override** (`TeamContext::hasOverride()`). FA ist `single` (Core-Standard). |
| `User::currentTeamRelation` | Rohe Relation auf `current_team_id`, **ohne** Override. |
| `Services\TeamContext` (statisch) | `setTeam()`/`clear()`. Gesetzt von `ToolContractAdapter` (MCP, mit `finally clear`), `SimpleToolController` (Playground), `ClaudeToolLoopRunner`, DAV. |
| `ToolContext->team` | Team des MCP-Aufrufs. |
| Global Scopes | Nur `StalenessScope`. Für Teams gibt der Core nichts vor. |

Der Core wird **nicht** geändert ([[feedback_keine_fremdmodul_aenderungen]]). FA nutzt seine APIs.

### §3.2 Befunde

| # | Befund | Beleg | Folge |
|---|---|---|---|
| A1 | Vier Wege zum Team: `currentTeam`, `currentTeamRelation` (**472×**, davon 440 Livewire), `Auth::login($user)` in 15 Jobs, `KiVerursacher::imKontext()`. Dazu ein Notbehelf: `RecipesGenerateTool` klont den Nutzer und biegt `currentTeamRelation` um (`RecipesGenerateTool.php:160–176`). | grep | Vorrang-Fehler, sobald zwei Wege gleichzeitig gesetzt sind |
| A2 | Nach `core.team.switch` arbeitet das Tool im MCP-Team, `AiGatewayService` prüft Kill-Switch, Budget, Call-Log und Modell-Freigabe aber am **UI-Team** (`AiGatewayService.php:61, 627, 848, 1101`). Nur 1 von 16 KI-Tools hat den Klon-Notbehelf. | Code | Falsches Budget, falsche Food-DNA, falscher Call-Log |
| A3 | Jobs tragen `teamId`, melden aber den Nutzer an; der KI-Dienst liest dann `current_team_id` **zur Laufzeit** (`GenerateRecipeJob.php:74–82`). | Code | UI-Teamwechsel zwischen Versand und Lauf verschiebt Budget/DNA/Log |
| A4 | 4 KI-Jobs ohne Login: `BulkEnrichJob`, `BulkEnrichGpJob`, `ClassifyLaJob`, `NonFoodKiBatchJob`. Ohne Team überspringt `AiGatewayService` Kill-Switch und Budget (`if ($team !== null && …)`). `KiVerursacher` deckt nur die Abrechnung ab. | Code | Massenläufe ohne Budget-Riegel (vgl. 16.10. Non-Food 26 $) |
| A5 | Weitere Service-Stellen mit `currentTeamRelation`: `KnowledgeEmbeddingService:740`, `ConceptService:706`, `ConformanceService:218`, `VoiceCommandService:291`, `KiVerursacher:61`, `CrmKunden:93`. | grep | wie A2 |

Kein Sichtbarkeits-Leck, sondern ein **Zuordnungs-Leck**. Seit #452 geldrelevant.

### §3.3 Zielbild

**Eine Lese-Stelle, eine Schreib-Stelle je Eingang.** Grundlage ist Laravels `Context` (ab L11, hier L13): versteckte Werte werden beim Job-Versand in den Payload serialisiert, im Worker wiederhergestellt und zwischen Jobs geleert.

```
Lesen (überall):  FaTeam::aktuell()
                   = Context::getHidden('fa.team_id')            ← gesetzt je Eingang
                     ?? Auth::user()?->currentTeam               ← enthält schon den Core-MCP-Override
                   je Request/Job nach id gemerkt (kein Query je Budget-Check)

Setzen (je Eingang, VOR der Arbeit):
  HTTP/Livewire  → FA-Middleware: Context::addHidden('fa.team_id', $user->currentTeam->id)
  MCP            → FoodAlchemistTool-Basis vor executeInContext(): aus ToolContext->team
  Job            → kommt automatisch aus dem Payload (beim Versand gesetzt)
  Scheduler/CLI/Job-aus-Job über Teamgrenzen
                 → ausdrücklich: FaTeam::imTeam($team, fn () => dispatch(...))
```

- **`currentTeamRelation` bleibt** für Livewire-UI-Code zunächst erlaubt (dort ist das UI-Team richtig), wird aber schrittweise ersetzt, damit Teil B nur eine Quelle kennt.
- **`KiVerursacher::imKontext` und der Nutzer-Klon** in `RecipesGenerateTool` entfallen.
- **Job-`teamId` und Context nicht als zwei Wahrheiten:** Solange Jobs `teamId` tragen, prüft eine Job-Middleware hart `Context-Team == $this->teamId`, sonst eigene Ausnahme `TeamKontextWiderspruch`. Später kann `teamId` aus den Jobs entfallen.
- **Login in Jobs** bleibt nur, wo ein *Nutzer* gebraucht wird (Call-Log „wer“), nicht für „welches Team“.

### §3.4 Bauschritte (je ein PR)

| Schritt | Inhalt | Dateien (Kern) |
|---|---|---|
| A-1 | `Support\FaTeam` (`aktuell()`, `aktuellId()`, `imTeam()`, Merker je Request/Job) + Eingangs-Setzer (Middleware, Tool-Basis). Noch kein Verbraucher umgestellt. | neu `Support/FaTeam.php`, `Http/Middleware/FaTeamKontext.php`, `Tools/FoodAlchemistTool.php`, Provider (Middleware-Registrierung) |
| A-2 | `AiGatewayService` (4 Stellen) + `KnowledgeEmbeddingService` + `KiVerursacher` lesen `FaTeam`. Klon-Notbehelf in `RecipesGenerateTool` raus. | `Services/Ai/*`, `Support/KiVerursacher.php`, `Tools/RecipesGenerateTool.php` |
| A-3 | Job-Middleware `ImTeamKontext` (Widerspruchs-Prüfung) an alle KI-Jobs; die 4 Jobs ohne Login bekommen sie ebenfalls. `Auth::login` als Team-Quelle raus. | `Jobs/*` (19 Dateien) |
| A-4 | Versand ohne Request (Scheduler, Artisan, Master-Recompute je Mandant) auf `FaTeam::imTeam`. | Console, Scheduler-Einträge im Provider |
| A-5 | Restliche Service-Stellen (A5) + schrittweise Livewire. | Pfadfinder |

### §3.5 Tests (Pflicht je Schritt)

1. MCP nach `team.switch` auf Team B: KI-Aufruf → Budget-Prüfung, Call-Log und `abrechnung_team_id` = B (nicht UI-Team A).
2. MCP nach `team.switch` **versendet einen Job**: Der Job rechnet auf B.
3. Job versandt für A, Nutzer wechselt danach in der UI auf B: Der Job bleibt bei A.
4. `BulkEnrichJob` ohne Login: Team gesetzt, Budget-Riegel greift (erschöpftes Budget → `KiBudgetErschoepftException`).
5. Artisan-Befehl verteilt an zwei Teams: Jeder Job rechnet auf sein Team.
6. Worker-Reihenfolge: Job A, danach Job ohne Kontext im selben Prozess → kein Erbe von A (Context geleert).
7. Widerspruch `teamId` ≠ Context → `TeamKontextWiderspruch`.

### §3.6 Risiken Teil A

| Risiko | Gegenmittel |
|---|---|
| `currentTeam` macht je Zugriff eine `Module`-Abfrage (`request()->segment(1)`) | `FaTeam` merkt das Ergebnis je Request/Job |
| Ein Eingang setzt den Context nicht → Rückfall auf `Auth::user()` = null | Test je Eingang (§3.5); im MCP-Pfad zusätzlich Log, wenn `ToolContext->team` fehlt |
| `Context` und Core-`TeamContext` laufen auseinander | MCP-Eingang setzt `fa.team_id` aus `ToolContext->team`; das ist derselbe Wert, den der Core in `TeamContext` schreibt. Test 1 deckt das |

## §4 · Teil B · Mandanten-Sicht „sicher per Standard“

### §4.1 Befunde

| # | Befund | Zahl | Gewicht |
|---|---|---|---|
| B-a | Regel greift nur bei Handaufruf, kein Global Scope | 1.118× `visibleToTeam`, 73× `applyVisible`, 552× handgeschriebener `where('team_id')` | hoch |
| B-b | Modell-Abrufe ohne Sicht-/Rechte-Prüfung im Umfeld (Heuristik ±10 Zeilen) | Livewire 94, Tools 25, Services 295, Jobs 29, Console 13 | mittel; viele harmlos, nicht ohne Einzelprüfung erkennbar |
| B-c | Zwei Master-Definitionen: `MasterTeam::istMaster` (streng, 49×) vs. `TeamScope::isMaster` (ohne Konfiguration „elternlos = Master“, 11×: Pairing-Signale, Anker-Wissen-Status, Wissens-Notbremse) | – | io: zu (Master gesetzt). **demo: offen**, `MasterTeam` existiert dort noch nicht, ENV leer, alle Teams elternlos |
| B-d | Kein flächiger Zwei-Mandanten-Test | 61 von 878 Testdateien mit Fremd-Team-Fall | mittel |
| B-e | Policy `view` nutzt `currentTeamRelation` (`FoodAlchemistPolicy.php:19`) | – | klein, mit Teil A erledigt |

### §4.2 Schritte

**B0 · Sicherheitsnetz (parallel zu Teil A, vor dem ersten Abo auf io)**
- **Zwei-Mandanten-Test:** Master + Mandant A (mit Abo) + Mandant B (ohne) + Unter-Team von A mit eingeschränkter 77d-Hülle. Als B: alle lesenden MCP-Tools (GET/LIST) und die Haupt-Livewire-Seiten. Prüft: keine ID von A, keine Nicht-Abo-Zeile des Masters, keine Fassung ohne Abo; als Unter-Team nur die Hülle.
- **Modus ausdrücklich simulieren:** Die Pest-Suite läuft in der Konsole. Der Test muss den HTTP- bzw. MCP-Eingang nachstellen (Middleware + `ToolContractAdapter`-Weg), sonst prüft er das offene Konsolen-Verhalten und ist grün und wertlos.
- **Statischer Wächter** (Pest-Arch-Test): neue `Modell::find/where/query` auf Trait-Modellen in `Livewire/` und `Tools/` ohne Sicht-Aufruf → rot. Bestand auf Ausnahmeliste, die nur schrumpfen darf.
- Bausteine vorhanden: `tests/Support/SeedsTeamHierarchy.php` (`makeUser`, `makeGp`, `makeRecipe`, …).

**B1 · Eine Master-Definition**
- `TeamScope::isMaster` ruft nur `MasterTeam::istMaster`. Rückfall „elternlos = Master“ entfällt.
- Startprüfung: mehr als ein Team und kein Master → Warnung (Log + Einstellungsseite).
- **Vorbedingung (E1):** demo bekommt einen Stand mit `MasterTeam` und einen gesetzten Master, *bevor* B1 dort ankommt. Sonst verlieren Pairing-Signale, Anker-Wissen und Wissens-Notbremse auf demo ihren Kurator.

**B2 · Abfragen zählen, die „unsichtbar“ mit „nicht vorhanden“ verwechseln (vor B3)**
- 56× `firstOrCreate/updateOrCreate/firstOrNew`, 255× `->exists()`, dazu Slug-/Dubletten-Prüfungen und Abo-/Kündigungs-Kopien („gibt es die Kopie schon?“).
- Nach B3 sehen diese Aufrufe fremde Zeilen nicht mehr → Dubletten oder Unique-Index-Absturz. Jede Stelle bekommt eine Entscheidung: bleibt mit Sicht (richtig) oder `ohneTeamSicht('eindeutigkeit')`.

**B3 · Automatischer Filter, Modell für Modell**
- Global Scope `TeamSicht` im Trait. Ist `FaTeam::aktuell()` gesetzt, wendet er `visibleToTeam(Kontext)` an.
- **Rekursion ausschließen:** `scopeVisibleToTeam` fragt für 77d-Hülle, Abo und Stamm-Paket selbst Modelle bzw. Tabellen ab. Diese inneren Abfragen laufen ausdrücklich `withoutGlobalScope(TeamSicht::class)` (heute über `DB::table`, das bleibt so). Test: Abfrage eines Abo-Typs mit gesetztem Kontext endet, liefert erwartete Zeilen.
- **Ohne Kontext:** in HTTP/MCP **eigene Ausnahme** `TeamKontextFehlt` + Log (E2). Eine stille Leerliste sähe für KI-Tools wie „keine Daten“ aus und führte zu Neuanlagen. „Leer“ nur hinter dem Notschalter. In Console/Queue offen, aber geloggt.
- Einzige Umgehung: `->ohneTeamSicht('grund')`, grep-bar.
- Reihenfolge: Abo-Typen (Rezept, Paket, Konzept, Format) → Foodbook, Speiseplan, Speisekarte → Stamm (GP, LA, Lieferant) → Einstellungen/Vokabular. Jede Stufe hinter einem Schalter (Muster `WissensSichtbarkeit::aktiv()`).
- **Vorbedingung:** B2 erledigt, Leistungsvergleich auf demo-Datenstand vor/nach.

**B4 · Schreibschutz im Modell (zweite Linie)**
- `saving`/`deleting`-Hook: Zeile weder eigen noch Master-pflegbar → erst **nur loggen**, nach einigen Tagen demo **werfen** (`FremdeZeileException`).
- **Vorher auflisten:** berechtigte Fremdschreiber im Kontext: Abo-/Kündigungs-Kopien, Recompute/Kosten-Cache auf Master-Zeilen, Eltern-Zeitstempel über `$touches`, Pivot-Syncs, Master-Kuration.

**B5 · Aufräumen**
- Redundante `visibleToTeam($team)` entfernen, wo B3 greift. `applyVisible` bleibt für `DB::table`. Ausnahmeliste des Wächters auf null.

### §4.3 Risiken Teil B

| Risiko | Gegenmittel |
|---|---|
| Rekursion im Global Scope | B3 innere Abfragen ohne Scope + Test |
| Unsichtbar ≠ nicht vorhanden (Dubletten, Unique-Absturz) | B2 vor B3 |
| Job/Import läuft leer | Console/Queue offen + Log; Jobs bringen Kontext aus Teil A mit |
| Master-Kuration sieht weniger | `ohneTeamSicht('master-kuration')`; Test prüft auch die Master-Sicht |
| Abo-/77d-Logik bricht | Regel selbst unverändert; Spec-77d/82-Tests bleiben Maßstab |
| Leistung (Hülle/Abo-Unterabfragen je Abfrage) | vorhandene Caches; Messung vor B3 |
| Test grün, Produktion offen | B0 simuliert HTTP/MCP ausdrücklich |

## §5 · Teil C · Große PHP-Dateien

### §5.0 Regel

- **Kein Großumbau, kein Ziel „Datei < X Zeilen“.** Die Schnittpläne unten werden beim **nächsten fachlichen Eingriff** in genau diese Datei umgesetzt (Pfadfinder).
- **Reines Verschieben:** ein PR je Schnitt, ohne Verhaltensänderung. Route und äußerer Komponentenname bleiben. Events und Event-Namen bleiben. Was in ein Kind wandert, nimmt seine Props und seinen Blade-Teil mit. Der Diff wird so geschnitten, dass er sich mit `git diff --color-moved` als Verschiebung prüfen lässt.
- **Vor jedem Schnitt:** offene Arbeit an der Datei prüfen. Stand 16.10.: **0 offene PRs** im Repo; parallele Sessions können aber lokal daran arbeiten → kurz fragen.
- **Erst entscheiden, dann löschen statt verschieben** (toter Code).

### §5.1 Größenbild (86de1f92)

| | Dateien | Zeilen | > 1.000 | > 2.000 |
|---|---|---|---|---|
| PHP `src/` | 1.927 | 283.805 | 40 | 9 |
| Blade | 307 | 51.509 | 8 | 0 |

Größte PHP-Dateien: `Livewire/Planung/Index` 4.596 · `Services/OrderService` 3.739 · `Services/PlanningCascadeService` 3.555 · `Services/SpeiseplanService` 2.874 · `Services/FoodbookService` 2.768 · `Services/PairingService` 2.598 · `Services/Ai/KnowledgeContextService` 2.171 · `Services/DataQualityService` 2.059 · `Livewire/Angebote/Editor` 1.869 · `Livewire/Concepter/Editor` 1.637 · `Livewire/Foodbooks/Index` 1.619 · `FoodAlchemistServiceProvider` 1.538.

### §5.2 C1 · `Livewire/Planung/Index.php` (4.596 Z., 91 öff. Methoden, 78 öff. Props) + `planung/index.blade.php` (1.365 Z.)

**Ausgangslage:** Traits `WithFileUploads`, `Concerns\FortschrittAnsicht` (339 Z.). `#[Url]` nur `sessionId`; `mount()` liest `open|tab|fb_owner|sk_owner|offer_owner` aus dem Request. Keine `#[Computed]`; die Blade ruft `planVorschau()`, `vorlagenFuer()`, `conceptSpeisen()`, `fortschrittDaten()` direkt. `#[On]`: `kaskade-gestartet` (von `KapitelRail`), `voice.komponenten-uebernahme` und `voice.diktat-transkribiert` (von `VoiceModal`). Tabs laufen rein über Alpine. Zwei Polls: Board `wire:poll.3s`, Lauf `wire:poll.1500ms="pruefeLauf"`. Uploads: `importPdf`, `fotoUploads[stepId]`, `briefAudio`. Vorhandene Kinder haben alle Keys.

**Schnitt in Kind-Komponenten:**

| Kind | Inhalt (Methoden) | ≈ Zeilen | Kopplung zum Parent |
|---|---|---|---|
| `Planung\AusgabeBrief` (eine Klasse, 5 Instanzen je `ownerType`) | `foodbookAusBrief`, `speisekarteAusBrief`, `speiseplanAusBrief`, `angebotAusBrief`, `formatAusBrief`, 5× `updated*OwnerId`, `aktiviereOwnerSession`; die 25 Props `fb*/sk*/sp*/offer*/fmt*` werden zu `titel/brief/meldung/ownerId/panelAuf` | ≈ 250 statt 600 (Zusammenführung) | Event `planung.oeffne(id, tab)` statt `$this->oeffne()` |
| `Planung\RezeptImport` | `importExtrahieren`, `importAnlegen`, `importReset`, `importZutatenRein` | ≈ 200 | `planung.lauf-geaendert`; braucht `WithFileUploads` |
| `Planung\Composer` | `composerAdd/Remove/Focus/Vorbereiten/Uebernehmen/Generiere` + Composer-Block aus `render()` | ≈ 200 | schreibt `eingabe[scope]`, ruft `goKaskade` → Events an `Brief` |
| `Planung\Trendradar` | `schnellTrendradar`, `trendradarUebernehmen` + Trend-Block aus `render()` | ≈ 120 | hoch: setzt `form`, `eingabe`, `sessionId` |
| `Planung\Brief` (je Scope rezept/gericht/concept) | Leitplanken, Vorlagen, Suchbegriffe, Diktat, Voice-Listener, `wissenVorschau`, `kiKopf`, `planFeld*`, `planVorschau`, `conceptSpeisen`, `goKaskade` | ≈ 1.300 → zusätzlich `Support\ReglerParams` auslagern | `planung.lauf-gestartet(laufId)`; Formularstand-Dispatch zieht mit |
| `Planung\KaskadenLauf` | `pruefeLauf` … `fotoUebernehmen`, `konformitaetPruefen`, `ladeLetztenLauf`, Kalkulations-/Konformitäts-/Foto-Blöcke aus `render()` + Trait `FortschrittAnsicht` | ≈ 1.200 | Prop `sessionId`, `:key="'lauf-'.$sessionId"`; Poll zieht mit |
| **Parent** `Planung\Index` | `mount`, Board, `neuePlanung`, `schnell*`, `oeffne`, `waehle`, `planungVerwerfen/Duplizieren`, `ladeForm`, `speichern`, `render` (Board) | ≈ 800 | – |

**Die eigentliche Kopplung (zuerst lösen):**
- `fehler`/`meldung`: von über 50 Methoden geschrieben, im Modal-Kopf angezeigt → Event `planung.meldung` oder lokale Meldung je Kind.
- `eingabe`/`regler`: geschrieben von `ladeForm`, `rehydriereReglerAusParams`, `trendradarUebernehmen`, `composerVorbereiten`, Voice-Listenern → gehört in `Brief`, die anderen schicken Events.
- `laufId`/`laeuft`: die Tab-Leiste liest `laeuft`; `oeffne` entscheidet per `laufId` über den Worker-Tab → Parent behält eine schreibgeschützte Kopie, `KaskadenLauf` meldet Änderungen.
- `form` und `sessionId`: von fast allen gelesen → Prop + `key` an jedes Kind.

**Toter Code (vorher entscheiden):** `ideeHinzu`, `ideeVerwerfen`, `paketBilden`, `skizzenAusAnalyse`, `skizzeAlsGericht`, `skizzenBatchAlsGerichte`, `skizzenAuswahlLeeren` (I:1566–1813) werden aus der Blade nicht mehr aufgerufen; `render()` berechnet `$skizzen*` (I:4193–4247) trotzdem. `PlanungLeitstelleTest` ruft sie noch. → Dominique entscheidet (E5): löschen oder Funktion zurück in die UI.

**Risiken:** Render-Dispatch `voice.formularstand-aktualisiert` ändert seine Häufigkeit; Kinder müssen lazy rendern (Alpine-Tab ist nicht serverseitig); Uploads nur in Kindern mit `WithFileUploads`.

**Tests:** 31 Dateien, rund 255 `Livewire::test(Index…)`, davon 149 in `PlanungLeitstelleTest`. Jeder Schnitt schreibt die betroffenen Tests auf das Kind um. Reihenfolge der Schnitte nach Testaufwand: `RezeptImport` (6) → `Trendradar` (2) → `Composer` (≈ 4) → `AusgabeBrief` (≈ 25) → `KaskadenLauf` → `Brief`.

**Offene Arbeit an der Datei (lokal möglich):** zuletzt angefasst in `feat/composer-modus`, `feat/spec82-p1-kaskade-begriffe`, `feat/wissen-recall-und-gp-pick` (alle gemergt).

### §5.3 C2 · Bestell-Cluster (`OrderService` 3.739 Z. + `WareneingangService` 730, `LieferantenRechnungService` 578, `OrderMailService` 231, `ProductionOrderService` 1.461)

**Die vier Zyklen:**

| Zyklus | OrderService → | ← zurück | Bruch |
|---|---|---|---|
| A Wareneingang | `updateReceiptLine`, `completeReceipt`, `fillReceiptFromOrder` (in `setStatus(Delivered)`) → `kurzweg*` | `buchen`/`stornieren`/`kurzwegMenge` → `schreibeWareneingangAbleitung`; `nachlieferung`/`abschliessen` → `setStatus(Delivered)` | WE wird Eigentümer der drei Methoden; `schreibeWareneingangAbleitung` + `guardReceiptLine` + `syncQuotaConsumption` + `journalNachziehen` in kleinen `OrderZeilenAbleitung`; Vorbelegen per Event `OrderDelivered` oder in `WE::abschliessen` |
| B Rechnung | `updateInvoiceLine`, `completeInvoiceFromReceipt` → `kurzwegRechnung*` | `bezahlt` → `updatePayment`, `reklamieren` → `updateClaimLine`, `aufBestellungenSchreiben` → `schreibeRechnungsAbleitung`/`updateInvoiceHeader` | analog: zwei Methoden zu `LieferantenRechnungService`, Rückweg nur noch auf `OrderZeilenAbleitung` + Zahlungs-/Freigabe-Service |
| C Mail | `setStatus` → `pruefeVersandbar`, `istServerVersand`, `planen` | `inhalt`, `stornoStandard`, `platzhalterWerte`, `pdf` → `mailtoData`/`dokument` | `OrderDokumentService` (Dokument/Mailto) ohne Mail-Abhängigkeit; Mail hängt nur daran |
| D Produktion | statische `sourceRefFor/Prefix`, `targetsHash`; `verknuepfteOrders` | `setStatus` (Storno) → `withdrawProductionDemand`; `detail` → `productionCancellationMailtoData` | Wertobjekt `ProduktionsQuelleRef`; Event `ProductionOrderCancelled`; Mailto in den Dokument-Service |

**Echte Laufzeit-Schleife (Fehlerquelle, nicht nur Struktur):** `WE::abschliessen` → `setStatus(Delivered)` → `fillReceiptFromOrder` → `WE::kurzwegMenge` → `schreibeWareneingangAbleitung`. Sie wird nur entschärft, weil `nichtErfassteAufNull` vorher alle Zeilen setzt (`WareneingangService.php:381`). Das ist der einzige Zyklus mit Vorrang: Er wird mit dem nächsten Eingriff in Wareneingang gebrochen.

**Ziel-Zuschnitt (Fassade zuerst):** `OrderService` bleibt als dünne Fassade und reicht durch, bis alle Aufrufer umgezogen sind.

| Ziel | Methoden (Auszug) | externe Aufrufe (Livewire/Tools/Services) |
|---|---|---|
| `OrderKern` (gemeinsam) | `ownedOrder`, `ownedDraftLine`, `recomputeLine/Order`, `draftForSupplier`, `addManualLine`, `upsertContribution` | 0 / 0 / 6 |
| Entwurf/Bedarf | `createDraft`, `addNeedFromTarget`, `updateLine`, `removeLine`, `updateHeader`, `deleteEmptyDrafts`, `resourceOrder`, `*Alternativen`, `switchLineArticle`, `previewFromSources`, `lagerReserviert` | ≈ 15 / 9 / 3 |
| Bestellrunde/Versand/Status | `generateDraftsFromSources`, `*Round*`, `send*`, `readyDraftIds`, `cancelSelectedDrafts`, `setStatus` | ≈ 18 / 3 / 2 |
| Produktionsbedarf | `productionDemandsForTeam`, `withdrawProductionDemand` | 1 / 0 / 1 |
| Wareneingang | → `WareneingangService` | 4 / 2 / 4 |
| Rechnung/Zahlung/Freigabe | `updateInvoice*`, `updatePayment`, `updateApproval`, `updateSupplierConfirmation`, Summaries | ≈ 12 / 9 / 3 |
| Reklamation/Kontingent | `updateClaimLine`, `updateLineQuota`, Summaries | 5 / 3 / 1 |
| Herkunft/Auswertung (Read-Model) | `listForTeam`, `detail`, `*Herkunft*` | 7 / 3 / 0 |
| Dokument | `dokument`, `*mailtoData` | 2 / 0 / 5 |
| Ampeln/Warnungen | `moqAmpel`, `orderWarnings`, `sendBlockers`, `logistikInfo` | 2 / 4 / 0 |

Die Summaries (Wareneingang, Rechnung, Reklamation) brauchen `orderWarnings` und `detail` → gemeinsamer Baustein `OrderSummaries`. Hauptkunde ist `Livewire/Orders/Editor.php` (≈ 47 Aufrufe über 29 Methoden).

**Tests:** `OrderServiceTest` 101, `ProductionOrderServiceTest` 39, Wareneingang 11 + Kurzweg 8, TripleMatch 7, Mail 7, Bestellrunde 11. **Dünn:** Reklamation/Kontingent (2), Herkunft (1–2), Mail/Dokument (2) → dort **vor** dem Umzug Charakterisierungstests.

### §5.4 C3 · `FoodAlchemistServiceProvider.php` (1.538 Z.)

**Aufbau:** Scheduler `planeLaeufe()` (Z. 194–300); zwei globale `\Livewire\on('call')`-Hooks (Sperren, Z. 322/360); Observer (Z. 431–451); 4 `bind`s; Queue-Listener (Z. 494–520); Modul/Routen (Z. 571/593); 11 Policies (Z. 671); **605 Tool-Einträge von Hand** (Z. 677–1459); Livewire per Ordner-Scan ohne Cache (Z. 1488).

**Konfliktmagnet:** 54 der Branches vor main haben diese Datei angefasst, fast immer nur durch eine neue Tool-Zeile.

**Sofort-Funde:**
- `SuppliersPutTool` steht **doppelt** in der Liste (Z. 806 und 921).
- Jede Tool-Registrierung steckt in `try { … } catch (\Throwable) {}` **ohne Log** (Z. 1443–1451). Ein kaputtes Tool verschwindet still aus MCP.
- `foodalchemist.orders.editor` wird zusätzlich explizit registriert (Z. 1496), obwohl der Scan ihn findet.
- Der Livewire-Scan läuft bei jedem Request über 144 Dateien.

**Schnitt:**
1. `ToolManifest`: Scan von `src/Tools` (nicht abstrakt, implementiert `ToolContract`), gecacht (Befehl `foodalchemist:tools-cache`, beim Deploy). Hülle `FaRechteToolHuelle` und der Hook `resolved`/`afterResolving` bleiben. Im catch **loggen**. Doppelte Tool-Namen beim Cache-Bau = Fehler.
2. Livewire-Scan genauso cachen.
3. Sub-Provider: `FaSchedulerProvider`, `FaLivewireGuardProvider`, `FaObserverProvider`, `FaQueueLifecycleProvider`, `FaToolProvider`, `FaLivewireProvider`.

**Risiken:** Boot-Reihenfolge `resolved` vs. `afterResolving` (der Core baut die Registry teils vor `booted`, `CoreServiceProvider.php:700`); der Core überschreibt Tool-Namen bei Duplikat still (`ToolRegistry.php:41–58`), FA prüft vorher per `has()`, eine andere Scan-Reihenfolge ändert den Gewinner; ein veralteter Produktions-Cache lässt neue Tools fehlen; die Core-Auto-Discovery (`ToolLoader::loadFromAllModules`, nur bei leerer Registry, falsche Namespace-Ableitung) darf FA nie ohne Hülle laden. Das passiert heute nur zufällig nicht.

**Wert:** hoch bei geringem Fachrisiko. Kandidat für einen frühen eigenen PR (E6).

### §5.5 C4 · Gemeinsame „Komposition“ in Angebote, Foodbooks, Speiseplan

`Livewire/Angebote/Editor` (1.869 Z.) und `Livewire/Foodbooks/Index` (1.619 Z.) sind zu rund 70 % namensgleich: `kapitel*`, `block*`, `katalog*`, `branding*`, `veroeffentlichen`, `vollKaskadeStarten`. `Speiseplan/Editor` (1.446 Z.) teilt Branding/Veröffentlichen.

**Schnitt:** eine Kind-Komponente „Komposition“ (Kapitelbaum, Katalog-Picker, Blöcke) und eine „Branding/Veröffentlichen“, jeweils gegen ein kleines Service-Interface (`OfferCompositionService`, `FoodbookService`, `SpeiseplanService`). Das spart doppelte Pflege und doppelte Fehler.

Nebenfund: `Foodbooks/Index.php:830` schreibt `$k->update(['fortschritt'])` direkt → nach `FoodbookService::kapitelFortschritt` (Vorbild `OfferCompositionService.php:246`).

### §5.6 C5 · Direkte Schreibzugriffe in Rezept- und GP-Modal

| Datei | Direkte Writes | Schnitt |
|---|---|---|
| `Recipes/RecipeModal.php` (1.440) | 11: `->update([...])` Z. 439, 446, 454, 469, 475, 524, 536, 544, 648, 652 + `DB::table('foodalchemist_recipes')->update` Z. 1093 (**umgeht Observer**, u. a. RecipeEmbedding) | Lineage-Felder (`ai_/accept_/clear_/manual_`) als `RecipeService::setzeFeld(feld, wert, quelle, konfidenz)`; Z. 1093 über Model/Service |
| `Gps/GpModal.php` (1.390) | 7: `$gp->update` Z. 263, 721, 733, 748, 802, 822, 1387 | in `GpService` bzw. ein Lineage-Gegenstück |
| `Verkauf/VkModal.php` (1.558) | 1: Z. 991 | über `SalesRecipeService::updateVk` |

Das ist der „gleiche Schreibweg für UI und MCP“: Browser und MCP verhalten sich sonst verschieden (vgl. frühere Befunde „PUT-Vollsync verwaist Deltas“, „ENRICH überschreibt manuelle Schritte“). **Z. 1093 ist ein echter Fehlerkandidat** und gehört vor alle anderen C-Schnitte (E7).

### §5.7 C6 · Übrige große Editoren (Schnitt beim nächsten Eingriff)

| Datei | Methoden/Props | Writes direkt | Empfehlung |
|---|---|---|---|
| `Concepter/Editor` (1.637) | 69 / 42 | 0 | Slots und Paket-Positionen als Kinder, Picker als Trait |
| `Produktion/Tagesplan` (1.534) | 33 / 18 | 0 | Feedback und Anleitung als Kinder; 29 private Aufbereitungs-Methoden in einen Query-Service |
| `Speiseplan/Editor` (1.446) | 69 / 63 | 0 | Branding/Veröffentlichen aus C4; Eintrag-Detail als Kind |
| `Orders/Editor` (1.397) | 68 / 47 | 0 | Cockpit (≈ 25 Methoden) und Wareneingang/Rechnung als eigene Komponenten, passend zu C2 |
| `Verkauf/VkModal` (1.558) | 47 / 29 | 1 | Darreichung und Regeneration als Kinder |

### §5.8 Nebenfunde Livewire-Traits

- Kein Trait in `Livewire/Concerns/*` nutzt `#[On]` (die Fehlerklasse „Trait verdrängt Handler“ ist gerade nicht aktiv). `MitBearbeitungssperre` verzichtet ausdrücklich darauf.
- 10 Komponenten nutzen `MitBearbeitungssperre`, rufen aber `sperreBeiSchliessen` nicht auf: Gps/DetailPanel, Recipes/IngredientEditor, Formate/DetailPanel, Pakete/Index, Concepter/DetailPanel, Produktion/DetailPanel, Verkauf/DetailPanel, Concepts/Index, Recipes/StepEditor, Recipes/DetailPanel. **Ungeprüft**, ob das Modale sind; falls ja, bleibt die Sperre beim Schließen liegen. Prüfauftrag.
- `Speiseplan/Editor.php:441–443`: zwei `#[On]` auf eine leere Methode `inhaltGespeichert`.

## §6 · Teil D · Leitplanken für neue Arbeit

Kein Umbau-Programm. Diese Regeln gelten für neuen und angefassten Code:

1. **Team nur über `FaTeam::aktuell()`** (nach Teil A). Kein neues `currentTeamRelation` außerhalb von UI-Code.
2. **Livewire schreibt nie direkt:** jede Änderung über den Service, den auch MCP nutzt.
3. **Neue Abfragen auf Trait-Modellen mit Sicht** (bis B3; danach automatisch). Der Wächter aus B0 prüft das.
4. **Fehler als Typ, nicht als Text.** Neue Fehlerfälle bekommen eine eigene Exception-Klasse; kein `str_contains($e->getMessage(), …)`.
5. **`catch` bindet `$e` und loggt** (außer ausdrücklich begründet, mit Kommentar).
6. **Neue Service-Zyklen vermeiden:** Rückwege über Events oder einen kleinen gemeinsamen Baustein.
7. **Mehrstufige Schreibvorgänge in einer Transaktion**, außer sie umschließt einen langen KI-Aufruf (dann Zustände so schreiben, dass ein Abbruch keine halben Daten hinterlässt).
8. **Neue Livewire-Komponenten mit mehr als ~800 Zeilen** werden vor dem Merge in Kinder geteilt.

**D1 · Einzige echte Phase in Teil D: Fehlertypen statt Textvergleich**
7 Stellen entscheiden den Fehlercode per `str_contains($e->getMessage(), …)`:
`KnowledgeSetActiveTool:80`, `KapitelIdeenPutTool:125`, `InventoryCountsPutTool:87`, `ConceptsDeleteTool:78`, `KapitelIdeenPostTool:111`, `KnowledgeUnbindTool:64`, `Livewire/Concerns/ManagesPhase:40`.
Je Stelle eine eigene Exception (`NichtGefunden`, `GeerbtNurLesen`, `BereitsGebucht`, `NochVerwendet`, `FreigabeGateOffen`) im werfenden Service, das Tool fängt den Typ. Klein, billig, und genau die Fehlerklasse, die schon zugeschlagen hat.

Bewusst **nicht** als Ziel: `app(X::class)` (2.495×) ist in Laravel kein Problem an sich; die Testpyramide (14 Unit von 878) ist für dieses Modul keine Zielgröße, die Feature-Tests sind der richtige Schwerpunkt. Die 16 Service-Zyklen werden nur dokumentiert, außer dem Bestell-Cluster (C2).

## §7 · Reihenfolge und PR-Schnitt

| # | PR | Teil | Abhängig von | Größe |
|---|---|---|---|---|
| 1 | A-1 `FaTeam` + Eingangs-Setzer | A | – | S |
| 2 | B0 Zwei-Mandanten-Test + Wächter | B | – (parallel zu 1) | M |
| 3 | A-2 KI-Dienste auf `FaTeam` | A | 1 | S |
| 4 | A-3 Job-Middleware, 4 Jobs ohne Login | A | 1 | M |
| 5 | D1 Fehlertypen (7 Stellen) | D | – | S |
| 6 | C5 `RecipeModal` Z. 1093 über Model/Service | C | – | S |
| 7 | C3 Tool-Manifest + Log im catch + Dublette raus | C | – | M |
| 8 | A-4 Versand ohne Request | A | 4 | S |
| 9 | B1 eine Master-Definition | B | E1 (demo-Master) | S |
| 10 | B2 Zählung „unsichtbar ≠ nicht vorhanden“ | B | 2 | M |
| 11 | B3 Global Scope, Stufe 1 (Abo-Typen) | B | 2, 4, 9, 10 + Leistungsmessung | L |
| 12 | B4 Schreibschutz (erst loggen) | B | 11 | M |
| 13 | B3 Stufen 2–4, B5 Aufräumen | B | 12 | L |
| – | C1, C2, C4, C6 | C | Pfadfinder: beim nächsten fachlichen Eingriff | je M–L |

## §8 · Risiken gesamt

| Risiko | Teil | Gegenmittel |
|---|---|---|
| Team-Kontext fehlt an einem Eingang | A | Test je Eingang; Log |
| Fehlender Kontext wird still zur Leerliste | B | Ausnahme statt leer (E2) |
| Global Scope rekursiv | B | innere Abfragen ohne Scope + Test |
| Unsichtbar ≠ nicht vorhanden | B | B2 vor B3 |
| Schreibschutz kippt Abo-Fluss | B | Fremdschreiber vorher auflisten; erst loggen |
| Großer Dateischnitt kollidiert mit paralleler Arbeit | C | Pfadfinder; vorher fragen; reine Verschiebung |
| Tool-Cache veraltet | C | Cache beim Deploy neu bauen; doppelte Namen = Fehler |
| demo verliert Master durch B1 | B | E1 vorher |

## §9 · Entscheidungen (Dominique)

| # | Frage | Empfehlung |
|---|---|---|
| E1 | demo bekommt einen Stand mit `MasterTeam` und einen gesetzten Master **vor** B1. Das heißt demo-Deploy. Wann, und welches Team ist dort Master? | Deploy vor B1; Master = das demo-Team, das kuratiert |
| E2 | Fehlender Team-Kontext in HTTP/MCP: Fehler oder leer? | **Fehler** (eigener Typ + Log); „leer“ nur hinter dem Notschalter |
| E3 | Plattform-Admin weiter **ohne** Ausnahme (Spec 82 §13 G)? | ja |
| E4 | Phase B0 vor dem ersten echten Abo auf io verbindlich? | ja |
| E5 | Toter Skizzen-Code in Planung/Index (7 Methoden): löschen oder zurück in die UI? | löschen, falls nicht geplant |
| E6 | C3 (Tool-Manifest) früh als eigener PR, obwohl Teil C sonst Pfadfinder ist? | ja: hoher Wert, geringes Fachrisiko, beendet den Konfliktmagneten |
| E7 | C5 `RecipeModal` Z. 1093 (Observer umgangen) sofort? | ja, als kleiner Fix |
| E8 | Unvergessene Branch `feat/produktion-warengruppen-deckel` (22.08., Topf-Deckel je Hauptgruppe): noch gewollt? | Dominique entscheidet; sonst Branch löschen |

## §10 · Sparring-Protokoll (Session „Vanessa“, 16.10.)

| Einwand | Entscheidung in dieser Spec |
|---|---|
| Rekursion im Global Scope | übernommen: B3 + Test |
| Unsichtbar ≠ nicht vorhanden (firstOrCreate, exists, Abo-Kopie) | übernommen: eigener Schritt B2 vor B3; gezählt 56 + 255 |
| Jobs bekommen das Team beim Versand, nicht nur zur Laufzeit | übernommen: §3.3 Context beim Versand; Test 2 |
| Statischer Kontext im langlebigen Worker; Context-Facade prüfen; eine Quelle statt drei | übernommen: Laravel `Context` (L13 vorhanden); `KiVerursacher::imKontext` und Klon entfallen |
| Leer statt Fehler | übernommen: Empfehlung umgedreht (E2) |
| Tests laufen in der Konsole | übernommen: B0 simuliert HTTP/MCP |
| Schreibschutz trifft berechtigte Fremdschreiber | übernommen: Liste vorher, erst loggen |
| demo-Master nicht verstecken | übernommen: E1, geprüft per SSH (`MasterTeam` fehlt, ENV leer) |
| Wer setzt `fa.team_id` und wann | übernommen: Setzer je Eingang **vor** der Arbeit (§3.3) |
| Job-`teamId` und Context nicht als zwei Wahrheiten | übernommen: harte Widerspruchs-Prüfung |
| Versand ohne Request-Kontext | übernommen: `FaTeam::imTeam`, A-4, Test 5 |
| `FaTeam::aktuell()` merkt je id | übernommen |
| Teil C/D als Overkill streichen | **teilweise**: Dominique will die PHP-Seiten in der Spec. Kompromiss: Schnittpläne + Pfadfinder-Regel, kein Großumbau; D als Leitplanken, nur D1 als Phase. Ausnahmen mit eigenem PR: C3, C5-Z.1093 (E6/E7) |
| Planung/Index: vorher offene Branches prüfen, reine Verschiebung | übernommen: §5.0, 0 offene PRs (16.10.), Diff mit `--color-moved` prüfbar |

## Changelog

- 2026-10-16 · Entwurf v0.1 (Session „test“), Sparring Vanessa eingearbeitet (Runde 1+2).
