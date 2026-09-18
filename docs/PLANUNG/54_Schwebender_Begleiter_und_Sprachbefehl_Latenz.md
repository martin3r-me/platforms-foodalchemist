# 54 · Schwebender Begleiter (Stufe 1 gebaut) + Sprachbefehl-Latenz (Backlog, nicht gebaut)

**Stand 2026-09-18 · Sprachbefehl ruht vorerst (Priorität Paket H Wissen) · nur Notiz, kein Bau**

Stufe 1 des „schwebenden Begleiters" ist Teil von PR #122 (`fix/voice-tts-cache-navigate-schwebekopf`):
der schwebende Knopf zeigt seinen Zustand selbst (fünf Werte seit PR #124: hört zu · sendet ·
spricht · pausiert · fehler), eine Sprechblase zeigt Transkript + Antwort, das grosse Modal
öffnet nur noch bei echten Vorschlägen. Diese Datei hält den nächsten, NICHT gebauten Befund fest.

## Latenz-Befund (Dominique, gemessen aus dem Call-Log der letzten 2h, 2026-09-18)

`voice.command` läuft auf `gpt-5.5` (Tier D = Plattform-Default), ~10.000 Token Input pro
Runde, 100–130 Token Output:

| Runden | Zeit | Ergebnis |
|---|---|---|
| 2 | 16–20 s | final erreicht |
| 3 | 36 s | `final=false` — Zeitbudget (28 s) überschritten |

**Das ist das „hängt"/„findet nichts" — nicht ein Erkennungsproblem, sondern Latenz je
LLM-Runde (~8–10 s) mal Rundenzahl.** Jeder zusätzliche Tool-Call (SEARCH-Umweg, zweite
Formulierungs-Runde) kostet nicht nur Tokens, sondern spürbare Sekunden, die sich im
Sprachbefehl — anders als bei einem getippten Chat — direkt als Wartezeit vor der nächsten
Antwort/dem nächsten Zuhör-Zyklus anfühlen.

## Hebel, in Reihenfolge des Nutzens (NICHT gebaut, nur notiert)

1. **Schnelles Modell für den Voice-Loop** (Mini-Klasse, Reasoning aus) über die Tier-
   Zuordnung V-01. Ist Konfiguration, kein Code — aber die Plattform mappt aktuell ALLE
   Tiers auf `gpt-5.5`. Offene Frage: erlaubt Core ein zweites Modell je Tier (Tier D
   spezifisch schneller/leichter als der Plattform-Default)?
2. **Zweite LLM-Runde sparen**: nach einem erfolgreichen `NAVIGATE`/`OPEN` die
   Bestätigungsantwort LOKAL formulieren (z. B. „Öffne die Basisrezepte.") statt das Modell
   noch einmal extra um den Schlusssatz zu bitten. Würde den häufigsten 2-Runden-Fall auf
   effektiv eine LLM-Runde + eine deterministische Antwort senken.
3. **Prompt-Kontext schrumpfen**: ~10.000 Token pro Runde ist viel für „öffne Seite X" —
   nachmessen, wie sich das auf Katalog (siehe Token-Deckel-Test in
   `VoiceGlobalPolicyTest.php`) / Systemprompt / Gesprächsverlauf (Spec 53/F Stufe 4)
   aufteilt und was davon für den konkreten Befehlstyp wirklich nötig ist.
4. **Zeitbudget 28 → 45 s** MIT sichtbarem Zwischenstatus in der Sprechblase (z. B. „sucht
   Werkzeug …") — reine Symptom-Linderung, sollte NACH (1)–(3) bewertet werden, nicht davor
   (ein längeres Budget allein macht die Latenz nicht kürzer, nur weniger sichtbar
   abgeschnitten).

## Entscheidung

Keine — Dominique legt den Sprachbefehl vorerst ruhen. Diese Datei ist der Einstiegspunkt,
wenn das Thema wieder aufgenommen wird.
