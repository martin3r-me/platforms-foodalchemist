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
die bestehende `aktiveVorlage`-Markierung). `level` hat aktuell GAR KEINE UI-Control, nur den
Regler-Schlüssel — vorbestehende Lücke, kein Spec-55-Fund, darum auch kein Badge dafür.

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

## Offene Punkte

1. **(a) Formular-Lückencheck vs. gesprochener Befehl**: kein Zugriff auf unbestätigten
   `Planung\Index`-Formularstand aus VoiceModal — siehe oben.
2. **`level`-Leitplanke hat keine UI-Control** in `leitplanken.blade.php` — vorbestehende
   Lücke, betrifft auch den neuen Übernahme-Pfad (Wert landet im Regler-Array, aber nirgends
   sichtbar bis eine Control gebaut wird — auch kein „Agent"-Badge dafür).
3. **Volle Suite**: nur gezielt getestet in diesem Umbau (Absprache cooking-jarvis-03, Last
   durch parallele Sessions) — vor PR/Merge nachholen.

## Wächter-Test bewährt sich weiter

Während des Umbaus erneut (mind. 5. Mal diese Session) ein geradeaus-Anführungszeichen in
einem NEUEN Kommentar direkt im `x-data`-Block von `voice-modal.blade.php` — der
Wächter-Test (`BladeXDataAttributeGuardTest`) fing es sofort ab, vor jedem Commit gefixt.
