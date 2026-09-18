# 54 · Schwebender Begleiter (Stufe 1 gebaut) + Sprachbefehl-Latenz (alle 4 Hebel bearbeitet)

**Stand 2026-09-18 · Latenz-Runde umgesetzt (Branch `feat/voice-latenz`)**

> **Nachtrag (Spec 55, 2026-09-18):** der „schwebende Begleiter" (Stufe 1 hier) ist WIEDER
> ENTFERNT — Dominique-Entscheid nach Deploy 14: der globale Agent bringt nichts, er lebt jetzt
> NUR noch als Panel in der Planungs-Leitstelle. Die Latenz-Arbeit unten (alle 4 Hebel) bleibt
> voll gültig, derselbe Loop läuft nur mit planungsspezifischem Katalog/Systemprompt weiter.
> Siehe `docs/PLANUNG/55_Agent_in_der_Planung.md`.

Stufe 1 des „schwebenden Begleiters" ist Teil von PR #122 (`fix/voice-tts-cache-navigate-schwebekopf`):
der schwebende Knopf zeigt seinen Zustand selbst (fünf Werte seit PR #124: hört zu · sendet ·
spricht · pausiert · fehler), eine Sprechblase zeigt Transkript + Antwort, das grosse Modal
öffnet nur noch bei echten Vorschlägen.

## Latenz-Befund (Dominique, gemessen aus dem Call-Log der letzten 2h, 2026-09-18)

`voice.command` läuft auf `gpt-5.5` (Tier D = Plattform-Default), ~10.000 Token Input pro
Runde, 100–130 Token Output:

| Runden | Zeit | Ergebnis |
|---|---|---|
| 2 | 16–20 s | final erreicht |
| 3 | 36 s | `final=false` — Zeitbudget (28 s) überschritten |

**Das ist das „hängt"/„findet nichts" — nicht ein Erkennungsproblem, sondern Latenz je
LLM-Runde (~8–10 s) mal Rundenzahl.**

## Hebel — was tatsächlich umgesetzt wurde

### (2) Zweite LLM-Runde sparen — GEBAUT

`AiGatewayService::callWithTools()` bekommt eine neue additive Option `fruehes_finale`
(callable, Default `null` — kein anderer Aufrufer betroffen). Liefert der Callback nach einem
erfolgreichen Tool-Aufruf einen String, endet der Loop SOFORT mit diesem Text als `finalText`,
ohne die Tool-Ergebnis-Nachricht anzuhängen oder eine zweite Modell-Runde zu starten.

`VoiceCommandService::fruehesFinale()` nutzt das NUR für `ui.NAVIGATE` (bewusst NICHT
`ui.OPEN` — dessen Tool-Ergebnis trägt kein Label, nur `type`+`id`, ein freundlicher Satz
bräuchte den echten Datensatznamen, den es dort nicht gibt). `ui.NAVIGATE` hat mit `label`
(aus dem Katalog) bereits alles, was ein kurzer, korrekter Satz braucht, und ist als reine
Seiten-Navigation immer eine abgeschlossene Aktion ohne offene Frage.

**Gemessen (Pest, `VoiceGlobalPolicyTest.php`):** „Öffne die Basisrezepte" braucht jetzt
GENAU 1 Runde statt 2 (`runden === 1`, `tool_laeufe` hat genau 1 Eintrag, `text` ist
„Öffne Basisrezepte." — deterministisch, ohne zweiten Modellaufruf).

### (3) Prompt-Kontext schrumpfen — GEMESSEN, Ursache lag NICHT in FA's eigenem Prompt

Ein Spy auf `FakeAiProvider::chat()` hat die tatsächlich gesendete erste System+User-Message
für „Öffne die Seite der Basisrezepte" gemessen:

```
SYSTEM: 14.893 Zeichen · USER: 91 Zeichen · GESAMT: 14.984 Zeichen
  davon Prosa (Regeln/Hinweise): 3.888 Zeichen
  davon Katalog (JSON):          11.005 Zeichen
```

Mit dem im Modul selbst etablierten Verhältnis (≈3,01 Zeichen/Token, aus dem Token-Deckel-
Kommentar „78.348 Zeichen ≈ 26.000 Token") sind 14.984 Zeichen ≈ **4.979 Token** — das liegt
bereits UNTER dem Ziel „≤ 5.000 Token", ohne dass FA's eigener Prompt (Systemprompt + Katalog
+ Gesprächsverlauf) verkürzt werden musste. Jeder Satz in der Prosa trägt eine einzelne,
gegen einen echten Live-Befund gebaute Regel (Partial-Update-Pflicht, Mengen-Rücklese,
Modus-Grenzen, Rauschen-Guard, …) — Kürzen hätte hier reales Verhalten riskiert für eine
Einsparung, die am Ziel schon nichts mehr ändert.

**Der eigentliche Befund liegt eine Ebene tiefer:** `AiGatewayService::propose()` setzt seit
einer früheren Runde `'with_context' => false, 'tools' => false` mit der Begründung, Core
hänge sonst vor JEDE Anfrage eine EIGENE System-Message (`OpenAiService::buildMessagesWithContext()`):
eine mit FA's eigener Persona konkurrierende Standard-Persona, einen Kontext-String MIT
`'Zeit: ' . now()` (sekundengenau wechselnder Prefix macht Prompt-Caching strukturell
unmöglich — die teuerste Nebenwirkung im System, gemessene Cache-Quote 0,35 %), und eine
plattformweite Tools-Übersicht (`buildToolsInfo()`, redundant zum eigenen Katalog).

Der Kommentar bei `propose()` behauptete dazu: „der agentische Tier-D-Loop baut seinen
Katalog in `callWithTools()` selbst und ist hiervon nicht betroffen." **Das stimmte nur für
FA's eigenen Katalog — `with_context` ist ein separater, additiver Core-Default und lief in
`callWithTools()` bis heute ungebremst mit**, weil `chatMitBackoff()` dort schlicht kein
`with_context`/`tools` durchreichte. Für den Voice-Loop bedeutet das: JEDE der bis zu
`MAX_RUNDEN=4` Runden zahlte diesen kompletten Zusatz-Block erneut, ohne Cache-Treffer — das
ist mit hoher Wahrscheinlichkeit der grössere Teil der Lücke zwischen den hier gemessenen
~4.979 Token (FA allein) und den live beobachteten ~10.000 Token/Runde.

**Fix:** dieselbe additive Option, die `propose()` schon hat, jetzt auch in `callWithTools()`s
`chatMitBackoff()`-Aufruf: `'with_context' => false, 'tools' => false`. Kein anderer Aufrufer
betroffen (nur diese eine Stelle). `FakeAiProvider::chat()` ignoriert `$options` komplett, ein
echter Vorher/Nachher-Zeichenvergleich am realen Provider ist darum aus der Sandbox heraus
nicht möglich — ein Spy-Test (`VoiceGlobalPolicyTest.php`) pinnt stattdessen strukturell, dass
`with_context`/`tools` jetzt bei JEDER Runde als `false` ankommen.

**Offen für Dominique:** die reale Wirkung (Token/Runde, ms) nur aus dem Call-Log nach dem
Deploy able­sbar — bitte nach dem Merge dieselben drei Befehle erneut messen, siehe unten.

### (1) Schnelles Modell nur für voice.command — REINE KONFIGURATION, kein Code nötig

`chatMitBackoff()` reicht `$options['model']` unverändert bis zu `OpenAiProvider::chat()`
durch (`$model = $options['model'] ?? self::DEFAULT_MODEL`) — das Modell IST bereits pro Call
übersteuerbar, und `callWithTools()` setzt es schon heute auf `config('foodalchemist.ai.tiers')['D']`.
Diese Tier-D-Einstellung kommt direkt aus `env('FOODALCHEMIST_AI_TIER_D')` — **eine reine
Host-App-Konfiguration, kein Core-Eingriff und kein FA-Code nötig.**

`OpenAiProvider::getAvailableModels()` listet bereits `gpt-4o-mini-2024-07-18` als
unterstütztes Modell. Reasoning wird nur gesendet, wenn `$options['reasoning']` explizit
gesetzt ist (`OpenAiService::chat()`) — `callWithTools()` setzt das nie, „Reasoning aus" ist
also für JEDES Modell hier automatisch der Fall, unabhängig von der Tier-D-Wahl.

**Empfehlung an Dominique:** `FOODALCHEMIST_AI_TIER_D=gpt-4o-mini-2024-07-18` (oder ein
vergleichbares Mini-Modell) in der Host-App-`.env` setzen und mit denselben drei Befehlen
messen, ob Qualität (JSON-Protokoll-Treue, Tool-Wahl) für den Voice-Loop ausreicht — bei
Bedarf zurückstellen. Keine Code-Änderung in diesem PR, weil keine nötig war.

### (4) Zeitbudget 28 → 45 s + Zwischenstatus — TEILWEISE

`VoiceCommandService::ZEITBUDGET_MS` von 28.000 auf 45.000 angehoben (deckt den gemessenen
3-Runden-Fall mit 36 s jetzt ab, statt ihn kurz vor dem `final` abzuschneiden). Die
Nutzertext-Angabe in `voice-modal.blade.php` („kann bis zu … dauern") mitgezogen.

**Echter, rundenweiser Zwischenstatus („sucht Werkzeug …", „navigiert …") wurde NICHT
gebaut** — der bestehende Code-Kommentar direkt über der Status-Zeile in
`voice-modal.blade.php` sagt schon, warum: „der Tool-Loop läuft synchron in DIESEM Request —
echte Rundenzahl ist serverseitig nicht live zeigbar … (asynchrone Job-Variante wie Paket C
ist eine spätere Entscheidung)". `window.FaVoiceZustand` (die „vorhandene Status-Brücke")
spiegelt nur CLIENT-bekannten Zustand (`$wire.*`-Properties) per `$watch` — die aktualisieren
sich erst NACH dem vollen Server-Roundtrip, nicht während der laufenden PHP-Schleife. Ein
echter Zwischenstatus bräuchte Polling/Broadcasting während des laufenden Requests — eine
neue Infrastruktur-Entscheidung, keine Erweiterung der bestehenden Brücke. Bewusst nicht in
diesem Latenz-Hotfix mitgebaut, um keine halbe Lösung zu simulieren.

## Vorher/Nachher — was aus der Sandbox heraus messbar war

Strukturell (Pest, `FakeAiProvider`-Spy, kein echter API-Call möglich):

| Befehl | Runden vorher | Runden nachher | Grund |
|---|---|---|---|
| „Öffne die Basisrezepte" | 2 (NAVIGATE → Formulierung) | **1** | (2) Frühes Finale |
| „Suche BBQ-Sauce" | 2 (SEARCH → Formulierung) | 2 (unverändert) | kein NAVIGATE, (2) greift nicht |
| „Planung für Kürbissuppe anlegen" | 2 (planung_vorschlag.POST → Formulierung) | 2 (unverändert) | kein NAVIGATE, (2) greift nicht |

Token/ms sind aus der Sandbox NICHT ehrlich messbar (`FakeAiProvider` ignoriert `$options`
und liefert ohne echte API-Latenz) — **bitte nach dem Merge dieselben drei Befehle im
Call-Log erneut messen** (Token/Runde sollte durch (3) sinken, ms/Runde durch (1) NUR falls
das Mini-Modell gesetzt wird).

## Entscheidung

(2) und (3) sind Code + Tests, gemergt sobald die volle Suite grün ist. (1) ist eine
Konfigurationsempfehlung an Dominique, kein Code. (4) ist teilweise (Budget) umgesetzt, der
Zwischenstatus-Teil bewusst als eigene, spätere Entscheidung (asynchrone Architektur)
zurückgestellt.
