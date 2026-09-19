# 55 · Sprachbefehl-Agent NUR NOCH in der Planungs-Leitstelle

**Stand 2026-09-18 · Branch `feat/agent-in-planung` off main (enthält #128/Spec 54)**

## Entscheid (Dominique, nach Deploy 14)

Der schwebende Sprachagent auf allen 26 FA-Vollseiten bringt nichts — der Agent lebt künftig
NUR in der Planungs-Leitstelle (`planung/index.blade.php`) und antwortet dort, als
einklappbares Panel statt als globales Modal/schwebendes Element. Der globale Mikro-Knopf in
der Sidebar kommt ebenfalls weg. Vorgabe: nicht das alte Modal umtopfen, sondern die
Interaktion vom Planungs-Ablauf her denken — der Agent soll in der Leitstelle klüger wirken
als vorher, nicht nur woanders stehen.

Die Latenz-Arbeit aus Spec 54 (`docs/PLANUNG/54_...md`) bleibt VOLL gültig — derselbe
`VoiceCommandService`/`AiGatewayService::callWithTools()`-Loop, nur der Katalog und der
Systemprompt sind jetzt planungsspezifisch statt global.

## Vier Punkte, wie der Agent jetzt klüger ist (nicht nur umgezogen)

**(a) Aktiver Brief-Dialog — Lückencheck vor dem Vorschlag.**
`VoiceCommandService::verarbeite()`, `system_zusatz`-Regel „LÜCKENCHECK vor (1)": nennt der
gesprochene Befehl nicht mindestens Sektor, Anlass, Personenzahl, Budget/Ziel-VK und Niveau,
fragt der Agent GEZIELT nach den fehlenden, BEVOR er `planung_vorschlag.POST` aufruft — kein
Rateversuch mit Platzhaltern. **Bekannte Grenze:** die Prüfung basiert auf dem GESPROCHENEN
Befehl, NICHT auf bereits im Formular eingetragenen (aber noch nicht gespeicherten) Regler-
Werten — `Planung\Index::$regler`/`$eingabe` sind reiner Komponenten-State, für eine ANDERE
Livewire-Komponente (VoiceModal) nicht lesbar, ohne eine neue Bridge zu bauen. Folge-Idee,
nicht in diesem Umbau: ein gemeinsamer, server-seitig gespiegelter State für „was steht gerade
unbestätigt im Formular".

**(b) Ergebnisse direkt in die Planungsobjekte, mit Klick-Bestätigung.**
KEIN neues MCP-Tool (Katalog bleibt klein). Additive Protokoll-Erweiterung: das Modell darf im
finalen `{"action":"final",...}`-JSON zusätzlich ein `"struktur"`-Feld mitgeben
(`AiGatewayService::callWithTools()`, Zeile ~609/654/748 — `$finalStruktur`, generisch, kein
Voice-Wissen im Gateway). `VoiceCommandService::verarbeite()` liest `$resultat['struktur']`,
filtert Scope + Leitplanken-Schlüssel gegen eine Whitelist (`sektor`, `occasion`, `pax`,
`ziel_vk`, `level`) und baut einen `komponenten_uebernahme`-Vorschlag. Karte im Panel
(`voice-modal.blade.php`, `komponenten_uebernahme`-Block) → Klick auf „Übernehmen" →
`VoiceModal::komponentenUebernehmen()` dispatcht das Browser-Event
`voice.komponenten-uebernahme` → `Planung\Index::voiceKomponentenUebernehmen()` schreibt
DIREKT in `$this->regler[$scope]`/`$this->eingabe[$scope]['brief']` (nochmalige
Server-Whitelist — ein Browser-Event ist kein vertrauenswürdiger Kanal) und markiert die
Felder in `$reglerVonAgent[$scope]`. GL-07 bleibt: kein Schreiben ohne den Klick. Sichtbares
„Agent"-Badge an allen 4 UI-Feldern (`sektor`/`pax`/`occasion`/`ziel_vk`,
`leitplanken.blade.php`) + am Brief-Feld (`erstellen-tab.blade.php`) — verschwindet bei der
NÄCHSTEN manuellen Änderung desselben Feldes (`Planung\Index::updated()`, gleiches Muster wie
die bestehende `aktiveVorlage`-Markierung; Pill-Klicks laufen über `reglerPill()`, das denselben
Marker direkt löscht — `updated()` sieht reine Server-Writes nie). **Korrektur (Nachtrag,
2026-09-19):** `level`/„Niveau" hat entgegen der ursprünglichen Doku HIER SEHR WOHL eine
UI-Control — über den generischen `RICHTUNGEN`-Pill-Loop am Kopf von `leitplanken.blade.php`
(`reglerPill('scope','level','gehoben')` etc.), nur eben als Pillen statt als `<select>`. Der
Fehler kam von einem zu engen Grep (nur nach `wire:model="regler...level"` gesucht). Das Badge
sitzt jetzt generisch im `RICHTUNGEN`-Loop selbst und deckt `level` korrekt mit ab.

**(c) Leitstelle-Zustand als proaktiver Kontext.**
`VoiceCommandService::planungsKaskadenHinweis()` liest den LETZTEN Kaskaden-Lauf der aktiven
Planungs-Session (`PlanningCascadeService::laufStatus()`, dieselbe Logik wie
`planung_kaskade.LETZTE`/`planung_kaskade.GET`) und hängt einen kompakten Status-Snapshot
(Lauf-Status + jeden `failed`-Schritt mit Label+Fehlertext) an den User-Prompt — der Agent
kann so unaufgefordert sagen „Schritt 3 ist rot (Zeitüberschreitung) — neu anstoßen?", ohne
dass der Mensch fragt (das TOOL `planung_kaskade.LETZTE` bleibt zusätzlich für den reaktiven
Fall „wie weit ist die Generierung?"). Best effort: kein Team/keine Session/kein Lauf/ein
Fehler beim Lesen liefert `null`/leer — der Sprachbefehl bricht nie deswegen ab.

**(d) Wissens-Fundierung mit Quelle.**
Katalog neu: `foodalchemist.formats.SEARCH`, `foodalchemist.zielgruppen.GET`,
`foodalchemist.knowledge.PREVIEW`. Prompt-Regel „WISSENS-FUNDIERUNG": bei Sektor/Anlass-
Empfehlungen ZUERST eines der drei Tools prüfen, im finalen Text die Quelle nennen („laut
Format …", „Event-Playbook …") statt zu raten — passt nichts, das auch so sagen.

## Rückbau

- `resources/views/partials/agent-mount.blade.php` gelöscht.
- `@include('foodalchemist::partials.agent-mount')` aus allen 26 Vollseiten entfernt (sed,
  Guard-Test `Spec 55: agent-mount.blade.php existiert nicht mehr` beweist es negativ+positiv).
- Sidebar-Mikro-Knopf (`data-voice-global`, `voice-modal.oeffnen`-Dispatch) entfernt —
  `sidebar.blade.php` behält nur noch den Betriebs-Wähler.
- `voice-modal.blade.php`: Wrapper von `<x-foodalchemist::modal>` auf ein einklappbares
  `<div x-data="{ aufgeklappt: false }">` umgebaut (Default eingeklappt), `x-init="$wire.oeffnen()"`
  löst dieselbe Initialisierung aus, die früher der Öffnen-Klick auslöste. Tote
  Floating-Button-Brücke entfernt: `window.FaVoiceZustand`/`FaVoiceStopAlles`/
  `FaVoiceKonversationAktiv`, `_schwebeStatus()`/`_schwebeTitel()`, das
  `verarbeitetGerade`-Flag (Spec 54 (4) — die INLINE `wire:loading`-Statuszeile im Panel deckt
  denselben Fall jetzt nativ ab, kein Client-Heuristik-Workaround mehr nötig). Autoplay-
  Entsperrung (`FaVoiceAudioEntsperren`) hängt jetzt am ERSTEN Aufnahme-Klick statt am
  (entfernten) Öffnen-Klick.
- `VoiceModal::oeffnen()` bleibt (Initialisierung: Kontext, Sitzung wiederherstellen),
  verliert aber `$schwebend`-Parameter + den `dispatch('modal.open', ...)`-Aufruf (kein Modal
  mehr, das geöffnet wird) — Signatur-Änderung, betrifft nur diese eine Komponente.
- `konversationAktiv` (VAD-Dauerzuhören) hing bisher an `voice_agent_dauerhaft_aktiv` —
  jetzt an `voice_tts_vorlesen` gekoppelt (ein Team, das Antworten hört, will plausibel auch
  ohne Klick weitersprechen können). Bewusste Design-Entscheidung, nicht explizit vorgegeben —
  bei Bedarf korrigierbar.
- Neuer Team-Settings-Schlüssel `voice_agent_panel_planung` (nullable, `null` = Default AN) —
  NICHT der alte `voice_agent_dauerhaft_aktiv` wiederverwendet: der alte Schlüssel trägt echte
  Team-Entscheidungen zum jetzt entfernten Feature (z. B. demo Team 6 stand bewusst auf
  `false`), ein Backfill hätte die Bedeutung unter der Hand getauscht. Alter Schlüssel bleibt
  als tote Spalte, wird nirgends mehr gelesen (Migration
  `2026_09_18_200000_add_voice_agent_panel_planung_to_team_settings.php`, reiner Zusatz).
  Einstellungs-UI (`settings/ki.blade.php`) zeigt nur noch den neuen Schlüssel.
  `TeamSettingsPutTool` (MCP) nimmt beide Schlüssel an, der alte trägt jetzt einen
  „VERALTET"-Hinweis in seiner Beschreibung.

## Panel

`planung/index.blade.php`, außerhalb der Tab-`@if`-Blöcke (wie `recipe-modal`/`vk-modal`/
`concepter.editor`) — sichtbar auf JEDEM Tab. `@livewire('foodalchemist.voice-modal', [...],
key('voice-panel-' . ($sessionId ?? 'keine')))`: `wire:key` trägt die Session-ID, ein
Session-Wechsel remountet das Panel komplett (frisches Gedächtnis für die neue Session, siehe
(c) unten) — kein `#[Reactive]`-Prop-Update nötig, kein neuer Pattern für dieses Modul.
Sichtbarkeit über `$agentPanelSichtbar` (`Planung\Index::render()`,
`TeamSettingsService::voiceAgentPanelPlanung()`).

## Gedächtnis-Schlüssel

`VoiceModal::sitzungIds()`: Team+User+Planungs-Session (`'planung:' . $planungsSessionId`)
statt Team+User+Browser-Session — ein Gerätewechsel bei offener Planung schneidet das
Gespräch nicht mehr ab. Fallback `'ohne_session'` (EIN geteilter Team+User-Eimer), wenn noch
keine Planungs-Session offen ist (Einstieg „ich brauche ein neues Format").

## Tool-Katalog (VoiceCommandService::TOOLS)

Entfernt: `verkaufsrezepte.SEARCH`, `artikel.SEARCH` (Lieferantenartikel-/Verkaufsrezepte-
Browsing gehört nicht zur Planung), `recipe_klasse.POST` (Speisen-Klassifikation ist keine der
genannten Planungs-Fähigkeiten). Neu: `formats.SEARCH`, `zielgruppen.GET`, `knowledge.PREVIEW`
(Design-Punkt d). `ui.OPEN` bleibt (öffnet Kaskaden-Entwürfe — „Schritt öffnen"),
`ui.NAVIGATE`/`ui.ROUTES` bleiben technisch UNVERÄNDERT (der volle FA-Routenkatalog ist
geteilter Code in `UiNavigateTool`) — die Beschränkung auf planungsinterne Ziele ist eine
Prompt-Regel, kein Katalog-Cut (Token-Deckel-Test bleibt unter 11.200 Zeichen, gemessen nach
dem Umbau, keine Anpassung nötig).

## Tests

- `tests/Feature/VoiceGlobalPolicyTest.php`: Rückbau-Guards (agent-mount weg, Sidebar-Knopf
  weg, Panel genau 1× in der Planung, Panel-Sichtbarkeits-Settings), Katalog-Inhalt,
  Kaskaden-Hinweis (best effort + echter Fehlerfall), `struktur`-Feld → Proposal (inkl.
  Fremdfeld-Filter + unbekannter Scope).
- `tests/Feature/PlanungLeitstelleTest.php`: `voiceKomponentenUebernehmen()` schreibt
  whitelisted in Regler/Brief, unbekannter Scope schreibt nichts.
- `tests/Feature/VoiceInterfaceTest.php`: Gedächtnis-Isolation pro Planungs-Session (fremde
  Session sieht nichts, gleiche Session teilt sich das Gedächtnis); zwei Tests auf
  `voice_agent_dauerhaft_aktiv` umgestellt auf `voice_tts_vorlesen`
  (`konversationAktiv`-Quelle geändert); der komplette Floating-Button-Testblock (agent-mount,
  `window.FaVoiceZustand`, `schwebend`-Parameter, „fünf Zustände" des schwebenden Knopfs)
  ENTFERNT — die Prämisse (ein schwebender Knopf existiert) gilt nicht mehr, keine Anpassung
  sinnvoll möglich.
- `tests/Feature/BladeXDataAttributeGuardTest.php`: unverändert, aber wieder mehrfach
  ausgelöst während des Umbaus (s. u.).

## Offene Punkte (Stand ursprünglicher Merge — siehe „Nachtrag: Agent am Brief" unten für den Fortschritt)

1. ~~**(a) Formular-Lückencheck vs. gesprochener Befehl**~~ — GELÖST im Nachtrag „Agent am
   Brief" (`voice.formularstand-aktualisiert`-Event, `VoiceCommandService::formularstandHinweis()`).
2. ~~**`level`-Leitplanke hat keine UI-Control**~~ — FAKTENFEHLER, korrigiert (siehe oben):
   `level` hat eine UI-Control über den `RICHTUNGEN`-Pill-Loop, nur kein `<select>`.
3. **Volle Suite**: nur gezielt getestet in diesem Umbau (Absprache cooking-jarvis-03, Last
   durch parallele Sessions) — vor PR/Merge nachholen.

## Wächter-Test bewährt sich weiter

Während des Umbaus erneut (mind. 5. Mal diese Session) ein geradeaus-Anführungszeichen in
einem NEUEN Kommentar direkt im `x-data`-Block von `voice-modal.blade.php` — der
Wächter-Test (`BladeXDataAttributeGuardTest`) fing es sofort ab, vor jedem Commit gefixt.

---

## Nachtrag: Agent am Brief (2026-09-19, Branch `feat/agent-am-brief`)

Dominiques Browser-Abnahme von Spec 55: das Panel sass oben rechts auf der Board-Ebene, neben
den Kanban-Spalten — nicht am Ort, wo Brief/Leitplanken entstehen. Zwei Befunde: (1) Panel-Ort
falsch, (2) „Briefing diktieren" ist heute reines STT (Transkript → Feld), Dominique erwartet
einen ECHTEN Agenten-Dialog dahinter.

### Panel-Ort (in Arbeit, abgestimmt mit Peter/cooking-jarvis-7d)

Peter baut parallel Paket K (Karten-Layout, Pilot Tab Basisrezept) in genau denselben Dateien
(`erstellen-tab.blade.php`, `leitplanken.blade.php`). Abstimmung: Peter baut zuerst durch
(inkl. `<div data-planung-agent-slot="{{ $scope }}"></div>` direkt unter dem Diktat-Include,
pro Scope-Tab automatisch vorhanden), pusht + PR; DANACH rebase ich `feat/agent-am-brief` und
hänge `@livewire('foodalchemist.voice-modal', [...], key(...))` in den Slot — bis dahin bleibt
das Panel technisch noch auf der Board-Ebene (Backend ist unabhängig vom Mount-Ort fertig).

### Diktat wird der Agenten-Einstieg

Nicht in diesem Nachtrag-Batch verändert (blockiert auf denselben Dateikonflikt wie oben) —
der Plan: Klick auf „Briefing diktieren" startet die Aufnahme WEITERHIN wie bisher, das
Transkript landet WEITERHIN im Beschreibungsfeld (kein Verlust des bestehenden Verhaltens),
UND zusätzlich geht es an `VoiceModal::verstehen()`-artige Verarbeitung — sobald der Slot
existiert, wird `diktat.blade.php`s Recorder-Callback um einen zweiten Aufruf ergänzt.

### Formularstand als Kontext (GELÖST — schliesst die alte Lücke a)

- `Planung\Index::render()` dispatcht bei JEDEM Render das Event `voice.formularstand-aktualisiert`
  einmal je Creation-Scope (`rezept`/`gericht`/`concept`) — Payload: die auf
  `AGENT_SCHREIBBARE_REGLER` gefilterten Regler-Werte + der Brief. EIN Dispatch-Ort statt an
  jeder Mutations-Stelle (`reglerPill`/`updated`/`leitplankenAusBriefing`/...) einzeln.
- `VoiceModal` bekommt `planungScope` als Mount-Parameter (welcher Scope-Tab „gehört" diesem
  Panel) + `formularRegler`/`formularBrief` initial, hält sie über
  `#[On('voice.formularstand-aktualisiert')]` aktuell — filtert auf den EIGENEN Scope, ein
  Event für einen anderen Tab wird ignoriert.
- `VoiceCommandService::formularstandHinweis()` baut daraus den Prompt-Block: was ist schon
  gesetzt, was fehlt (gegen `Planung\Index::MANDATORY_LEITPLANKEN[$scope]`) — der Lückencheck
  prüft jetzt den ECHTEN Stand, nicht mehr nur den gesprochenen Satz.

### Pflicht-Leitplanken je Scope

`Planung\Index::MANDATORY_LEITPLANKEN`: Basisrezept = `ziel_menge`/`ziel_einheit`
(Halbfabrikat, kein Teller); Gericht = `pax`/`ziel_portion_g`/`occasion`/`serviceform`;
Concept teilt sich `pax`/`occasion`/`serviceform` mit Gericht (keine Portion — Concept ist das
ganze Menü). **„Format" (Sektor/Anlass/Personen) ist bewusst NICHT dabei** — `fmtBrief` ist ein
flaches Diktat-Ziel ohne eigenen Regler-Satz (kein Creation-Scope wie rezept/gericht/concept),
volle Format-Unterstützung wäre ein eigenes, grösseres Vorhaben (Regler-Struktur für Format
bauen) und ist NICHT Teil dieses Nachtrags — offener Punkt.

### Direkt-Set statt Übernehmen-Karte (Dominique-Präzisierung)

Antwortet der Nutzer auf eine Rückfrage des Agenten (oder macht eine eindeutige, unaufgeforderte
Angabe während eine Session offen ist), setzt der Agent SOFORT, ohne Bestätigen-Klick — die
Antwort auf eine konkrete Frage IST die Bestätigung. Die Übernehmen-Karte (GL-07, Klick nötig)
bleibt NUR für unaufgeforderte Vorschläge aus einem längeren, freien Brief-Text.

Zwei Wege, beide gebaut:

1. **Chips** — der Agent stellt im finalen JSON zusätzlich `{"rueckfrage":{"scope":"...",
   "feld":"<EXAKT einer der gemeldeten fehlenden Schlüssel>"}}`. Das VOKABULAR kommt
   AUSSCHLIESSLICH vom Server (`VoiceCommandService::regelVokabular()` — dieselben Konstanten,
   die auch `leitplanken.blade.php` rendert, s. u.), NICHT vom Modell — ein Klick auf einen
   Chip (`VoiceModal::rueckfrageChip()`) setzt sofort, ein Wert ausserhalb des Vokabulars wird
   serverseitig abgelehnt. Zahlenfelder (Pax/Menge/Portion/Ziel-VK, kein festes Vokabular)
   zeigen ein Eingabefeld statt Chips (`rueckfrageZahl()`), geprüft mit `is_numeric`.
2. **Gesprochene/getippte Antwort** — der Agent mappt die Antwort selbst auf die Regler und
   gibt `{"struktur":{"scope":"...","felder":{...},"direkt":true}}` — additive Erweiterung des
   bestehenden `"struktur"`-Protokolls aus dem ursprünglichen Spec-55-Merge (Design-Punkt b).
   JEDER Feldwert wird trotzdem serverseitig gegen `VoiceCommandService::regelWertGueltig()`
   geprüft (Enum-Felder gegen ihr Vokabular, Zahlenfelder gegen `is_numeric`) — ein ungültiger
   Wert wird NICHT übernommen, weder direkt noch als Vorschlag. `direkt` fehlt/ist `false` →
   unverändertes Verhalten (Übernehmen-Karte, Klick nötig) — reine additive Erweiterung, der
   ursprüngliche Spec-55-Pfad bleibt für längere Briefings bestehen.

„Rückgängig" (`VoiceModal::komponentenRueckgaengig()`) setzt die betroffenen Felder auf leer
zurück — bewusst KEIN Wiederherstellen des exakten Vorwerts (dafür müsste der Alt-Wert über
die gesamte Panel-Lebensdauer mitgeführt werden); der Mensch tippt/spricht den richtigen Wert
danach neu ein, das Feld ist als „fehlend" wieder erkennbar.

Vokabular-Konstanten (`Planung\Index::SEKTOR_OPTIONEN`/`OCCASION_OPTIONEN`/
`SERVICEFORM_OPTIONEN`) waren vorher NUR als rohe `<option>`-Listen in `leitplanken.blade.php`
gepflegt — als Konstanten extrahiert, damit Blade UND Agent aus DERSELBEN Quelle lesen
(`MENGE_EINHEITEN`/`RICHTUNGEN` gab es als Konstante schon vorher). `AGENT_SCHREIBBARE_REGLER`
ist die EINE Whitelist-Stelle (vorher an zwei Stellen dieselbe Literal-Liste dupliziert:
`Planung\Index::voiceKomponentenUebernehmen()` und `VoiceCommandService::verarbeite()`).

### „Vom Agenten"-Badge erweitert

Jetzt auch an `serviceform`/`ziel_menge`/`ziel_einheit`/`ziel_portion_g` (die neuen
Pflicht-Leitplanken) sowie generisch im `RICHTUNGEN`-Pill-Loop (`convenience`/`level`/
`bio_praeferenz` — nur `level` wird je vom Agenten gesetzt, die anderen bleiben durch die
Whitelist automatisch ohne Badge). `reglerPill()` (Server-Write ohne `wire:model`-Sync) löscht
den Marker jetzt explizit selbst, `updated()` sieht diesen Schreibweg nie.

### Tests

`tests/Feature/VoiceGlobalPolicyTest.php`: Lückencheck gegen echten Formularstand (gesetzt vs.
fehlend), `regelWertGueltig()` (Enum + Zahl, gültig + ungültig), `struktur.direkt` →
`komponenten_direkt` mit Wert-Filter, `struktur` ohne `direkt` bleibt `komponenten_uebernahme`
(Regression), `rueckfrage` → Server-Vokabular (Enum + Zahlenfeld + unbekanntes Feld verworfen).
`tests/Feature/VoiceInterfaceTest.php`: `rueckfrageChip()`/`rueckfrageZahl()` (gültig + ungültig),
`komponenten_direkt` wendet sich automatisch an (kein Klick), `komponentenRueckgaengig()`,
Formularstand-Event nur für den eigenen Scope, Mount-Parameter. `tests/Feature/PlanungLeitstelleTest.php`:
`render()` dispatcht den Formularstand-Event (Scope-Filterung selbst Ende-zu-Ende in
VoiceInterfaceTest.php geprüft — Livewires `assertDispatched()` matcht bei gleichnamigen
Events nur den ersten Treffer, keine verlässliche Prüfung aller drei Scopes in einem Test).

### Panel-Umzug + Diktat-Kopplung (GELÖST, nach Peters #149)

Peters Paket-K-PR (Karten-Layout + `data-planung-agent-slot` in der Eingabe-Karte) ist gemergt
(Deploy 25, main `d66c440c`). Rebase glatt (Kontext-Matching wie vorhergesagt für gericht/
concept, ein manuell aufgelöster Konflikt in `leitplanken.blade.php` für den neuen
Rezept-Kartenblock — Sektor-Badge dort schon von Peter übernommen, `$sektorLabels` auf
`SEKTOR_OPTIONEN` umgestellt, `ziel_menge`/`ziel_einheit`-Badges ergänzt).

- **Panel-Ort**: `@livewire('foodalchemist.voice-modal', [...])` ersetzt den leeren Slot in
  `erstellen-tab.blade.php` (rezept/gericht) + einen eigenen Block im Concept-Briefing
  (`index.blade.php` — Concept nutzt die geteilte Partial nicht). Der alte Board-Level-Mount
  ist komplett entfernt — EIN Panel je Creation-Scope statt einem globalen auf der Board-Ebene.
- **Diktat→Agent**: `Planung\Index::briefDiktatUebernehmen()` dispatcht nach dem (unveränderten)
  Feld-Update zusätzlich `voice.diktat-transkribiert` (Scope + Text) — NUR für die drei
  Creation-Scopes, nicht die fünf flachen Ausgabeform-Ziele ohne Panel.
  `VoiceModal::diktatTranskribiert()` verarbeitet es über `verarbeiteText()` wie einen normalen
  Sprachbefehl, gefiltert auf den eigenen Scope.

### Offen (Nachtrag)

1. „Format"-Scope hat keine eigene Regler-Struktur — volle Unterstützung der von Dominique
   genannten Format-Pflichtfelder (Sektor/Anlass/Personen) ist ein separates Vorhaben.
2. Volle Suite lief grün vor PR/Merge (Absprache cooking-jarvis-03).
