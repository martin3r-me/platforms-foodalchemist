# Entwurf Workflow-Dossiers · KI-Aufträge (#1002) — zur Durchsicht, NICHT angelegt

Stand 2026-10-29. Anlegen erst nach Deploy (Kurator), per `knowledge.POST` (inaktiv), Dominique aktiviert. Nachzüge per gezieltem `knowledge.PUT` (nur ersetzte Abschnitte, vorher GET, nachher GET-Gegenprüfung).

## 1 · NEU `workflow.ki_auftrag_beantworten_mcp` (art=ablauf, category=workflow)

```markdown
---
typ: Skill_Workflow
code: fa.ki_auftrag_beantworten
gilt_fuer_vorgang: ki_auftrag_beantworten
thema: KI-Auftrag beantworten
sub_thema: Vom offenen Auftrag zur übernommenen Antwort
zweck: "Ein FA-Tool meldet einen offenen KI-Auftrag — wie wird er beantwortet, ohne am Tool vorbei zu schreiben?"
recherche_datum: 2026-10
zielgruppe: agent
required_tools:
  - foodalchemist.ki_auftraege.GET
  - foodalchemist.ki_auftraege.ANTWORT
trigger_phrases:
  - ki_auftrag_offen
  - KI-Auftrag beantworten
  - offene KI-Aufträge
  - Backfill extern
tags: [skill, workflow, ki, auftrag, mcp, extern]
---

# KI-Auftrag beantworten

> **Führt durch:** Ein Tool oder Lauf meldet `ki_auftrag_offen` — Auftrag holen, bearbeiten, beantworten, weitermachen.

Du bist hier das Modell. Das Tool hat denselben Prompt gebaut wie für die Plattform-KI (Wissen, Kontext, Leitplanken, Antwort-Schema). Die Antwort läuft danach durch dieselbe Prüfung und Nachverarbeitung wie auf der Plattform. **Fachregeln stehen im Auftrag, nicht hier** — wird der Prompt verbessert, wirkt das auf beiden Wegen.

## Schritt 1 — Aufträge holen
`foodalchemist.ki_auftraege.GET` (optional `feature`). Bis zu 50 offene Aufträge mit `auftrag_id`, `feature`, vollem Prompt (`messages`) und Antwort-Umschlag.

## Schritt 2 — Auftrag bearbeiten
Genau den Prompt bearbeiten, nichts umformulieren und nichts dazuerfinden. Antwort als reines JSON im Umschlag, den der Prompt vorgibt (üblich: `werte`, `confidence` 0–1, `reasoning`). Werte nur aus dem mitgegebenen Vokabular. Fehlt die Grundlage: Wert weglassen und niedrige Konfidenz angeben, nicht raten. Braucht ein Auftrag eine Datei oder Websuche, die du nicht hast: nicht beantworten, melden.

## Schritt 3 — Beantworten
`foodalchemist.ki_auftraege.ANTWORT` mit `antworten` (je `auftrag_id` + `werte`) sowie `anbieter` und `modell` — **wahrheitsgemäß**, sie landen im Protokoll. Je Auftrag kommt `ok` oder `abgelehnt` mit Grund (unbekannte, fremde, abgelaufene oder schon beantwortete ID, kein JSON). Abgelehnte nicht blind wiederholen, erst den Grund lesen. Aufträge laufen nach 24 Stunden ab.

## Schritt 4 — Weiter
- **Tool-Aufruf** (meldete `ki_auftrag_offen`): dasselbe Tool mit denselben Argumenten erneut aufrufen. Mehrere KI-Glieder = mehrere Runden, bis das Tool sein normales Ergebnis liefert.
- **Lauf im Hintergrund** (`laeufe_neu_gestartet` in der Antwort): das System startet den Lauf selbst neu. Entsteht ein weiterer Auftrag, zurück zu Schritt 1.
- **Befehl/Backfill** (`--anbieter=extern`): den Befehl erneut ausführen.
Schleife, bis nichts mehr offen ist.

## Abschluss-Kriterien
Das Tool liefert sein normales Ergebnis, der Eintrag im Protokoll zeigt `quelle: mcp` mit Anbieter und Modell. Vorschläge bleiben Vorschläge: Übernahme und Freigabe laufen wie auf dem Plattformweg, `approved` setzt nur ein Mensch.

## Anti-Patterns
- Das Feld selbst per `PUT` setzen, statt den Auftrag zu beantworten.
- Den Prompt umschreiben oder Zusatzfelder liefern.
- `anbieter`/`modell` falsch angeben.
- Fließtext statt JSON.
- Einen Auftrag mehrfach beantworten oder IDs erfinden.
- Mitten im Lauf das Tool mit anderen Argumenten rufen (neuer Prompt = neuer Auftrag, die alte Antwort verfällt).

## Grenzen
Embeddings (semantische Suche) und Bildgenerierung bleiben beim Plattform-Provider.

## Verwandte Abläufe
- `workflow.gp_aus_la_anlegen_mcp` — GP anreichern (`gps.ENRICH`)
- `workflow.gericht_abschluss` — Speisen-Klasse, Rollen, Kohärenz
- `workflow.gericht_weg_b_eigenregie` — was es nur von Hand gibt
```
(≈ 3.300 Zeichen, unter dem 4.000er-Deckel.)

## 2 · Nachzüge (nur ersetzte Abschnitte) — Paket P1/P1b/Umleitung

| Dossier | Abschnitt | neuer Text (Kern) |
|---|---|---|
| `workflow.gp_aus_la_anlegen_mcp` | „Schritt 4 — Anreichern" | `foodalchemist.gps.ENRICH` ergänzt Zustand, Tags, Aroma-Anker und Geschmacksprofil. Per MCP legt der Lauf **KI-Aufträge** an statt fertige Vorschläge zu liefern (Antwort: `extern: true`): Ablauf `workflow.ki_auftrag_beantworten_mcp`, danach `gps.ENRICH` für dasselbe GP erneut aufrufen, Vorschläge mit `gp_enrich.RESOLVE` übernehmen. `foodalchemist.gp_forms.ESTIMATE` liefert ebenfalls `ki_auftrag_offen` (Aufruf wiederholen). Allergene und Nährwerte bleiben außen vor. |
| `workflow.gericht_abschluss` | „Schritt 1 — Speisen-Klasse" | `recipe_klasse.POST` liefert per MCP zuerst `ki_auftrag_offen` → Auftrag beantworten (`workflow.ki_auftrag_beantworten_mcp`) → denselben Aufruf wiederholen (mit/ohne `accept`). Dasselbe gilt für `recipe_rollen.POST`, `recipe_coherence.POST` und `recipe_sensorik.POST`. |
| `workflow.gericht_weg_b_eigenregie` | Abschnitte mit „Felder selbst per PUT" für Sensorik/Klasse/Rollen/Kohärenz | Diese vier Felder gehen **nicht mehr** per Eigenregie-PUT, sondern über das Tool + Auftragsschleife. Weg B bleibt für das, was es nur von Hand gibt. (Genauer Text nach GET des Dossiers.) |
| `workflow.verkaufsgericht_anlegen_mcp` | Hinweis „Wer füllt die KI-Felder" | eine Zeile: Tools mit Auftragsschleife siehe Pflichtliste Tabelle D; Ablauf `workflow.ki_auftrag_beantworten_mcp`. |
| Praesentations-CSS | (noch kein Workflow-Dossier gefunden) | `presentation_designs.GENERATE_CSS` liefert Auftrag → Schleife; Dossier-Zuordnung offen. |

Noch zu prüfen (nach den weiteren Paketen): `konzept_anlegen_mcp`, `foodbook_anlegen_mcp`, `angebot_erstellen_mcp`, `speiseplan_erstellen_mcp`, `speisekarte_anlegen_mcp`, `trendradar_pflegen_mcp`, `preis_margen_monitoring_mcp`, `basisrezept_*` (Generator/Planung = P2b/c).

## Offene Frage
Der Kurator wünscht „Changelog-Zeile". `knowledge_canon.PUT` lehnt Dossiers mit `## Changelog` im Body ab. Die Workflow-Dossiers sind nicht im Kanon, die bestehenden haben keinen Changelog-Abschnitt. Vorschlag: nur `version` hochzählen (macht `knowledge.PUT` automatisch), die Änderungsnotiz in den Pflichtlisten-Commit statt in den Dossier-Body.
