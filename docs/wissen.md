---
title: Wissen
order: 9
---

# 📚 Wissen

Das Wissens-Modul ist der **Pflege-Ort für das operative Prosa-Wissen**, mit dem die KI im Food Alchemist arbeitet: Regelwerke, Domänen-Wissen (Fleisch, Fisch, Eier …), Cross-Cutting-Regeln, food-basierte Trends und das Niveau-System. Statt dass dieses Wissen in Vault-Dateien liegt und irgendwo im Prompt hart verdrahtet ist, wird es hier **im System gepflegt, direkt eingebunden und nachvollziehbar** — „das Modul gewinnt".

Route: `/foodalchemist/wissen` · Kategorien- und Einsatzort-Pflege unter **Einstellungen**.

---

## Drei Arten von Wissen — nur eine gehört hierher

| Art | Beispiel | Wo |
|-----|----------|-----|
| **Strukturiertes Wissen** | Pairing-Graph, Substitutionen, Allergene, Nährwerte | eigene Tabellen (kein „Dokument") |
| **Operatives Prosa-Wissen** | Regelwerke, Domänen, Cross-Cutting | **hier im Wissens-Modul** |
| **Referenz-/Lesematerial** | Weiterbildung, Literatur, Marktstudien | bleibt außen vor (Hintergrund für Menschen) |

Das Wissens-Modul kümmert sich bewusst nur um die **mittlere** Art: Regeln und Erklärungen, die die KI *liest*, um bessere Vorschläge zu machen.

---

## Wie ein Wissens-Eintrag wirkt

Jeder Eintrag hat **Titel, Kategorie, Inhalt (Markdown)** und einen Aktiv-Schalter. Damit die KI ihn findet und nutzt, gibt es zwei Ebenen:

### Aliase — damit die KI das Wissen findet
Begriffe, unter denen ein Dokument auffindbar ist (z. B. „sahne", „butter" → das Milch-Dokument). Direkt am Eintrag pflegbar.

### Einbinden — an Einsatzorte binden (grob + fein)
Ein Eintrag wird an **Einsatzorte** gebunden. Es gibt zwei Granularitäten:

- **Bereich (grob):** ganze KI-Sektion — `Grundprodukte`, `Basisrezepte`, `Verkauf`, `Konzepte`, `Preis`, `Chat`.
  Ein `Regelwerk_Grundprodukte` bindet man an den Bereich **Grundprodukte** → gilt für alle GP-Prompts.
- **Prompt (fein):** einzelner KI-Aufruf, z. B. `vk.generator` oder `gp.suggest`.

Zusätzlich läuft eine **grobe Auto-Ebene** über die Kategorie (Feature × Kategorie), sodass ein neues Domänen-Dokument automatisch von den Generatoren genutzt wird, die die Kategorie ziehen.

**Nachvollziehbar:** Am Eintrag sieht man, wo er wirkt; rückwärts kann man fragen „was hängt an Einsatzort X?".

---

## Der zentrale Trick: Injektion im Gateway

Jeder KI-Aufruf läuft durch **einen** Punkt (`AiGatewayService::propose($promptKey)`). Dort wird das gebundene Wissen **automatisch mitgeladen** — für **jeden** der ~48 Prompts, nicht nur für einzelne Features. Match: exakt (Prompt-Key) **oder** dessen Bereich (Präfix vor dem Punkt, z. B. `gp.suggest` → Bereich `gp`). Additiv zum sonstigen Kontext, dedupliziert, mit Audit-Spur (welche Dokumente flossen ein).

---

## Datenmodell

| Tabelle | Zweck |
|---------|-------|
| `knowledge_documents` | die Wissens-Einträge (slug, title, category, content_md, version, content_hash, active) |
| `knowledge_categories` | pflegbares Kategorien-Vokabular (such-/filterbar) |
| `knowledge_layers` | Einsatzorte: Bereiche (grob) + KI-Prompts (fein, aus der Registry) |
| `knowledge_bindings` | Doc → Einsatzort (`binding_type='layer'`, mode, weight, source, Provenienz) |
| `knowledge_aliases` | Begriff → Dokument (Findbarkeit) |
| `knowledge_routings` | grobe Auto-Ebene: Feature × Kategorie |

Import aus dem Vault: `php artisan foodalchemist:knowledge-import --vault=<pfad zu 07_WISSEN>` (Regelwerke, Domänen, Cross-Cutting, Niveau, food-basierte Trends; Tech-Trends/Weiterbildung/Literatur/Marktstudien bewusst ausgeschlossen).

---

## Ausblick

- **Bearbeiten der groben Auto-Ebene (Routings)** aus der UI.
- **MCP-Schreiben:** Wissen „von außen" wachsen lassen (Trends/Know-how per KI anlegen, `source='mcp'`).
