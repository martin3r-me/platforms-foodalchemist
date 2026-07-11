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

## Suche im Browser — Text oder semantisch

Die Dokumentenliste kennt zwei Such-Modi:

- **Textsuche (Default):** klassisches Substring-Matching über Titel, Slug und Inhalt.
- **Semantisch suchen (Schalter):** findet auch **bedeutungsähnliche** Docs, deren Wortlaut nicht wörtlich passt — z. B. „Erdapfel" → das Kartoffel-Dokument, „Topinambur" → Wurzelgemüse. Grundlage sind Embeddings des gesamten indizierten Korpus (alle Kategorien), sortiert nach Relevanz.

Der semantische Modus braucht einen **indizierten Korpus** und einen **Embedding-Provider**. Ist keiner verfügbar (oder der Korpus noch nicht indiziert), fällt die Suche sauber auf die Textsuche zurück und sagt es an. Korpus indizieren:

```
php artisan foodalchemist:knowledge-embed        # alle Kategorien (idempotent, überspringt Unverändertes)
```

> Das ist unabhängig vom KI-Hot-Path-Flag `FOODALCHEMIST_SEMANTIC_SEARCH`: die **manuelle** Browser-Suche wirkt, sobald ein Provider da ist; das Flag steuert nur, ob die KI-Kontext-Injektion zusätzlich semantisch recallt.

---

## Import-Guard — „das Modul gewinnt" wörtlich genommen

Weil das Modul die Wahrheit ist, darf ein wiederholter Vault-Import **nichts überschreiben, was im Browser kuratiert wurde**. Mechanik (App-wins-per-Snapshot):

- Jedes importierte Doc trägt einen `imported_hash` = Inhalts-Snapshot des letzten Imports.
- Der Import überschreibt ein Doc nur, wenn der **Vault-Inhalt sich geändert hat** *und* der **aktuelle Inhalt noch dem Snapshot entspricht** (das Doc wurde seit dem Import nicht in der App editiert).
- Sobald jemand ein Doc im Browser speichert, weicht sein Inhalt vom Snapshot ab → der Import lässt es **unangetastet** und meldet es im Report („🛡 … NICHT überschrieben").
- Bewusstes Überschreiben mit dem Vault-Stand: `--force` (zieht den Vault-Stand nach und setzt den Snapshot neu).

---

## Wissen per MCP wachsen lassen (v3)

Externe KI-Clients können über die Platform-MCP **neues** Wissen anlegen und pflegen:

- **`foodalchemist.knowledge.POST`** — legt ein Dokument an. Immer **inaktiv** (Quarantäne) und `created_via='mcp'`; es wirkt erst im KI-Kontext, wenn ein Mensch es im Browser **aktiviert**. Kategorie muss im Vokabular stehen. Optional gleich Aliase + Einsatzort-Bindungen (`source='mcp'`).
- **`foodalchemist.knowledge.PUT`** — aktualisiert ein per MCP/Browser angelegtes Dokument (Inhalt ⇒ version+1).

**Guard:** Der MCP-Pfad darf **Vault-verwaltete Dokumente nicht anfassen** (`source_path` gesetzt → gesperrt). MCP wächst nur *neues* Wissen bzw. editiert sein eigenes; die kanonischen Regelwerke bleiben Vault/Browser. `created_via` (`import`/`ui`/`mcp`) macht die Herkunft überall auswertbar.

---

## Datenmodell

| Tabelle | Zweck |
|---------|-------|
| `knowledge_documents` | die Wissens-Einträge (slug, title, category, content_md, version, content_hash, **imported_hash** = Import-Guard-Snapshot, **created_via** = Herkunft import/ui/mcp, active) |
| `knowledge_categories` | pflegbares Kategorien-Vokabular (such-/filterbar) |
| `knowledge_layers` | Einsatzorte: Bereiche (grob) + KI-Prompts (fein, aus der Registry) |
| `knowledge_bindings` | Doc → Einsatzort (`binding_type='layer'`, mode, weight, source, Provenienz) |
| `knowledge_aliases` | Begriff → Dokument (Findbarkeit) |
| `knowledge_routings` | grobe Auto-Ebene: Feature × Kategorie |

Import aus dem Vault: `php artisan foodalchemist:knowledge-import --vault=<pfad zu 07_WISSEN>` (Regelwerke, Domänen, Cross-Cutting, Niveau, food-basierte Trends; Tech-Trends/Weiterbildung/Literatur/Marktstudien bewusst ausgeschlossen). Wiederholbar — der **Import-Guard** schützt dabei im Browser editierte Docs (`--force` hebt ihn auf). Danach für die Semantiksuche indizieren: `php artisan foodalchemist:knowledge-embed`.

---

## Ausblick

- **Bearbeiten der groben Auto-Ebene (Routings)** aus der UI.
- **Semantik-Scope:** `searchDocIds` sucht heute nur im globalen Korpus (team_id-Sentinel) — team-eigene Docs erscheinen semantisch noch nicht (lexikalisch schon). Wartet auf nativen Global-∪-Team-Scope im Core.
