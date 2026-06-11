---
typ: Domänen-Spec
domaene: D-8
stand: 2026-06-10
status: ausgearbeitet
mvp: Phase 2
---

# D-8 — Foodbook & Chat

> **Services (stateless):** FoodbookService, ChatService
> **Hängt ab von:** D-6 (Verkaufsrezepte, Speisen-Klassen, Preise) — der Chat zusätzlich querschnittlich von ALLEN Domänen (seine Tools rufen deren Services) · **MVP-Status (⚠D5):** Phase 2
> **Kurzbeschreibung:** Foodbook-Editor (Kapitel-Baum + Block-Liste + Live-Cockpit) inkl. PDF-Export, wiederverwendbare Kombinations-Vorlagen, Chat über Plattform-MCP-Tools mit Approval-Flow (**V-14 — der zentrale Rewrite dieser Domäne**). Lebenszyklus der KI-Vorschläge: [GL-07](../04_GRUNDLOGIKEN/GL-07_ki_vorschlags_lebenszyklus.md); Wissenskontext: [GL-13](../04_GRUNDLOGIKEN/GL-13_wissenskontext_beschaffung.md) — nicht duplizieren.

**Phase-2-Pragmatik:** Schemata werden im MVP **mitmigriert** (FK-Ziele, 02_DATENMODELL §B), Editor/Chat-UI folgen in Phase 2. Read-MCP-Tools dürfen laut 01_ARCHITEKTUR §2 schon im MVP entstehen — der Approval-Flow für Schreib-Tools ist Phase 2.

## 1. Scope & Ressourcen

29 Ist-Commands (Inventar-Filter D-8) → drei Blöcke:

| Block | Ressourcen | Ist-Commands |
|---|---|---|
| **Foodbook** | foodbooks, foodbook_tree, foodbook_kapitel (CRUD + move + reorder), foodbook_blocks (CRUD + reorder + variant_group + next_variant_group_id), kapitel_aggregat | `list_foodbooks`, `upsert_foodbook`, `delete_foodbook`, `list_foodbook_tree`, `upsert_foodbook_kapitel`, `delete_foodbook_kapitel`, `move_foodbook_kapitel`, `reorder_foodbook_kapitel`, `list_foodbook_blocks`, `upsert_foodbook_block`, `delete_foodbook_block`, `reorder_foodbook_blocks`, `set_foodbook_blocks_variant_group`, `next_foodbook_variant_group_id`, `foodbook_kapitel_aggregat` |
| **Kombination** (wiederverwendbare Menü-/Buffet-Vorlagen) | kombinationen, kombination_block (CRUD + reorder + variant_group) | `list_kombinationen`, `upsert_kombination`, `delete_kombination`, `list_kombination_blocks`, `upsert_kombination_block`, `delete_kombination_block`, `reorder_kombination_blocks`, `set_kombination_blocks_variant_group`, `next_kombination_variant_group_id` |
| **Chat** | chat_conversations, chat_messages, chat_send | `chat_list_conversations`, `chat_create_conversation`, `chat_delete_conversation`, `chat_list_messages`, `chat_send` (✨ GL-06) |

**Neu im Ziel (kein Ist-Command):** PDF-Export des Foodbooks (Kapitel-Baum → navigierbares Kunden-PDF) — in der Alt-App nie gebaut, hier als Phase-2-Feature eingeplant (§4). Die KI-Menü-Planung `ai_plan_dishes` (FoodbookPlannerModal der Alt-App) ist im Inventar **D-4/MVP** — D-8 konsumiert sie nur (§5).

## 2. Datenmodell-Ausschnitt — das Foodbook-Schema erklärt

Sechs `foodbook*`-Tabellen in der Quelle, davon **zwei Generationen**:

```
foodalchemist_foodbook                         „die Mappe" (z.B. „Foodbook 2027")
 └─ foodalchemist_foodbook_kapitel             Kapitel-BAUM (self-FK parent_id, beliebige Tiefe)
     └─ foodalchemist_foodbook_block           Inhalts-Blöcke eines Kapitels (position-sortiert)
         └─ foodalchemist_foodbook_block_staffel   Preis-Staffeln (min_personen → preis)

foodalchemist_combination                      wiederverwendbare Vorlage (menue/buffet/selektion)
 └─ foodalchemist_combination_block (+_staffel)    gleiche Block-Typen, ohne kombination_ref
        ▲ Live-Referenz: foodbook_block.kombination_id (type='kombination_ref')

LEGACY (1. Generation, beide 0 Zeilen): foodbook_menu + foodbook_menu_block
```

- **`foodbook`**: `code` UNIQUE, `bezeichnung`, `jahr`, `status` aktiv/archiviert. Reine Klammer.
- **`foodbook_kapitel`** = Makro-Struktur MIT Preis: `parent_id` (NULL = Top-Kapitel), `position`, `titel` (intern) vs. `konsumententitel`/`claim`/`beschreibung` (Marketing fürs PDF), `preis_pro_person` + `preis_modus` (manuell/auto), optional `speisen_klasse_id` (Composer-Balancing), `status` draft/sent/archived, `snapshot_at`/`snapshot_json` (eingefrorener Stand beim Versand — im Ist ungenutzt, im Ziel aktivieren, §6).
- **`foodbook_block`** = polymorphe Inhalts-Zeile, diskriminiert über `type` ∈ {`recipe_ref`, `kombination_ref`, `header_neutral`, `header_frei`, `header_frei_preis`, `spacer`, `text`, `image`}. Gemeinsame Felder: `ebene` (0–2 Einrückung), `sichtbar` (Export-Filter), `bezeichnung` (intern), `kundentext`, `interne_bemerkung`, `variant_group_id` (Wahl-Gruppen „A | B | C"). Typ-spezifisch: `vk_recipe_id`+`menge`+`einheit` (recipe_ref), `kombination_id` (kombination_ref, Live-Referenz auf Vorlage), `header_quelle` (KI-Lineage! GL-07 §2), `preis_wert`+`preis_basis` person/pauschal/staffel (header_frei_preis), `hoehe` klein/mittel/gross (spacer), `payload_json` (image u.a.). **Im Ziel:** `type`/`preis_basis`/`hoehe` als PHP-Enums, `payload_json` → jsonb, Typ-Feld-Konsistenz (recipe_ref braucht vk_recipe_id etc.) als Service-Validierung — die Quell-CHECKs/Trigger werden nicht portiert (02_DATENMODELL-Konvention).
- **`foodbook_block_staffel`**: pro Block n Zeilen (`position`, `min_personen`, `preis`) — nur sinnvoll bei `preis_basis='staffel'`.
- **`kombination`(+`_block`,+`_staffel`)**: strukturidentisch zum Foodbook-Block (ohne `kombination_ref` — keine Rekursion!), plus `typ` menue/buffet/selektion, `anzahl_personen_default`. Eine Kombination wird in beliebig vielen Foodbooks per `kombination_ref` **live** referenziert (Änderung an der Vorlage schlägt durch — bewusst, anders als Rezept-Snapshots ⚠D2).
- **`foodbook_menu` / `foodbook_menu_block`**: erste Generation (flaches Menü statt Kapitel-Baum, eigener type-Satz heading/recipe_ref/text/image/price/gliederung), seit dem Kapitel-Redesign leer (je 0 Zeilen). **Empfehlung: NICHT migrieren** — abweichend von 02_DATENMODELL §B, dort nachziehen (→ offener Punkt E5).
- **Chat:** `chat_conversations` (title, timestamps) + `chat_messages` (`role` user/assistant/tool, `content`, `tool_name`, `tool_params_json`, `tool_result_json`). Im Ziel **+ `user_id`** auf beiden (02_DATENMODELL §B), JSON → jsonb, `team_id` NOT NULL.

Scoping: alle D-8-Tabellen sind **team-eigen** (`team_id` NOT NULL) — Foodbooks sind Kundenprojekte, keine Stammdaten. `vk_recipe_id`/`speisen_klasse_id` zeigen auf D-6-Welt.

## 3. Services & Methoden

```php
// FoodbookService — Baum + Blöcke + Cockpit (deckt auch Kombinationen ab; gleiche Block-Logik)
listFoodbooks(): Collection                          // + Kapitel-Zähler
upsertFoodbook(FoodbookData $d): Foodbook
deleteFoodbook(int $id): void                        // SoftDelete; Kaskade über Model-Events
getTree(int $foodbookId): Collection                 // flache Knoten, Baum baut die UI (Ist-Muster)
upsertKapitel / deleteKapitel / moveKapitel(int $kapitelId, ?int $newParentId)   // Zyklus-Check!
reorderKapitel(int $foodbookId, ?int $parentId, array $orderedIds): void
listBlocks(int $kapitelId): Collection               // inkl. Staffeln + aufgelöste recipe-/kombination-Refs
upsertBlock(BlockData $d): Block                     // Block + Staffeln in EINER Transaktion (V-07)
deleteBlock / reorderBlocks(int $kapitelId, array $orderedBlockIds)
setBlocksVariantGroup(array $blockIds, ?int $groupId)
nextVariantGroupId(int $kapitelId): int              // max+1 über den Kontext
kapitelAggregat(int $kapitelId): KapitelAggregat     // EK/VK/Wareneinsatz% REKURSIV über Subtree;
                                                     // summiert nur sichtbare recipe_ref-Blöcke (menge-gewichtet);
                                                     // kombination_ref expandiert die Vorlage; Preislogik aus D-6/GL-11
snapshotKapitel(int $kapitelId): void                // friert Stand in snapshot_json ein (status → sent)
exportPdf(int $foodbookId): PdfExport                // Phase-2-Neubau, §4

// ChatService — Persistenz + Orchestrierung (V-14)
listConversations / createConversation / deleteConversation / listMessages   // team- UND user-scoped
send(int|null $conversationId, string $message): ChatTurn
// send(): speichert User-Message → übergibt an Plattform-LLM mit registrierten MCP-Tools →
// Tool-Loop läuft in der PLATTFORM (nicht hier!) → jede Tool-Ausführung + finale Antwort werden
// als chat_messages persistiert (role='tool' je Call) → ai_call_log via AiGatewayContract (⚠D3)
```

Die **MCP-Tools** (`src/Tools/`, Plattform-Gebot: Tools rufen Services, nie Models) sind KEINE ChatService-Methoden, sondern eigenständige Tool-Klassen über alle Domänen: z.B. `SearchRecipesTool`→`RecipeService`, `RecipeDetailTool`, `ListFoodbooksTool`/`FoodbookTreeTool`→`FoodbookService`, `FindVkRecipesForKlasseTool`→`SpeisenKlassenService`, `PairingCohesionTool`→`PairingService` (D-7) … plus **Schreib-Tools mit Approval** (§6). Tool-Schnitt im Detail: E5/06_KI_SPEZIFIKATION.

## 4. Livewire-Komponenten & UI-Fluss

**Foodbook-Editor — 3-Panel-Layout** (bewährtes Muster der Alt-App `Foodbook.tsx`):

| Panel | Komponente | Inhalt |
|---|---|---|
| links | `Foodbook\KapitelTree` | Foodbook-Auswahl + Kapitel-Baum mit Nummerierung („1.", „1.1."), Drag&Drop für move/reorder, Status-Badges |
| mitte | `Foodbook\BlockEditor` (Vorbild `FoodbookBlockList.tsx`) | EINE gemeinsame sortierbare Liste aller Block-Typen; Typ-Icons; Inline-Edit der Kopf-Felder; Block-Modals (Rezept-Picker aus D-6, Kombinations-Picker, Staffel-Editor, Bild-Upload) IN `<x-ui-page>`; Variant-Gruppen per Mehrfachauswahl |
| rechts | `Foodbook\CockpitPanel` | Live-Aggregat des gewählten Knotens: EK/VK/Wareneinsatz% rekursiv (`kapitelAggregat`), Vergleich `preis_modus` manuell vs. auto |

Drag&Drop (Alt: dnd-kit) im Ziel über Livewire-Sortable/Alpine — Reorder schickt nur die ID-Reihenfolge an `reorderBlocks` (gleiches Wire-Format wie Ist). Jedes Foodbook/Kapitel hat eine **URL** (V-17, `dynamic`-Sidebar nach planner.php-Vorbild, 01_ARCHITEKTUR §2). Kombinationen bekommen denselben Block-Editor als eigene Route (Vorbild `Kombination.tsx`).

**PDF-Export (neu):** „Senden"-Flow = Kapitel-Snapshot (`snapshotKapitel`) → Blade-Render des Subtrees (nur `sichtbar=1`; `konsumententitel`/`claim`/`kundentext` statt interner Felder; Preise je `preis_modus`/`preis_basis`) → Browsershot/DomPDF mit Kapitel-Bookmarks. Pragmatisch: erst druckbares HTML, PDF-Engine als zweiter Schritt.

**Chat-UI** (Vorbild `Chat.tsx`): Conversation-Liste links, Verlauf rechts; Tool-Aufrufe als aufklappbare Chips (Name + Params + Ergebnis aus `chat_messages` role='tool'); **Approval-Karte** bei Schreib-Tools (Diff/Zusammenfassung dessen, was das Tool schreiben will, Buttons Genehmigen/Ablehnen — Plattform-Approval-Mechanismus); optimistisches Anzeigen der User-Message, Streaming sofern die Plattform es hergibt.

## 5. KI-Features dieser Domäne

| Feature | Pattern | Zieltabelle/-felder | Anmerkung |
|---|---|---|---|
| Chat (`ChatService::send`) | freier Dialog, KEIN GL-07-Lebenszyklus | `chat_messages` + `ai_call_log` (feature `chat_message`) | Hülle `chat` via SemanticLayerBridge (GL-06); Schreib-Aktionen NUR über approval-pflichtige Tools (§6); Tier A (V-01) |
| Block-Header-Vorschlag | GL-07, skalares Feld | `foodbook_block.header_quelle` / `combination_block.header_quelle` | im Ist nur als Lineage-Spalte angelegt (GL-07 §2) — Ziel: kleiner Propose/Accept für Gliederungs-Header aus dem Kapitel-Kontext; Tier B |
| Menü-/Foodbook-Planung | konsumiert `ai_plan_dishes` (**D-4/MVP**, GL-13: Cross-Cutting + Domains) | schreibt nichts direkt — Ergebnis wird als Block-Vorschlagsliste ins Kapitel übernommen (User-Accept = upsertBlock-Batch in TX) | Alt-UI: `FoodbookPlannerModal`; „KI-Composer Phase 4.9" der Alt-Roadmap geht hierin auf |

Der Chat schreibt pro Turn genau eine `ai_call_log`-Zeile über den `AiGatewayContract` (Schreibpflicht GL-07 Invariante 1 gilt auch außerhalb des Propose-Patterns); `layers_used` + neu `knowledge_used` (GL-13 §6) werden mitgeloggt.

## 6. Verbesserungen gegenüber Ist — **V-14: Chat-Rewrite über Plattform-MCP-Tools (ZENTRAL)**

**Ist-Zustand (chat.rs, 723 Zeilen):** Eigenbau-Tool-Router mit 7 hartkodierten read-only SQL-Tools (`search_recipes`, `get_recipe_detail`, `list_schreibstile`, `list_foodbooks`, `show_foodbook_menus`, `show_menu_detail`, `find_vk_recipes_for_klasse`). Kein echtes Function-Calling, sondern JSON-Output-Konvention im **2-Call-Pattern**: Call 1 wählt genau EIN Tool („action"+„params"), die App führt SQL direkt aus, Call 2 formuliert die Antwort aus dem Tool-Ergebnis. Schwächen, die der Rewrite behebt:

| Schwäche (Ist) | Ziel (V-14) |
|---|---|
| Max. 1 Tool pro Turn, keine Tool-Ketten („suche Rezept UND zeig seine Kohäsion" = 2 User-Turns) | Plattform-LLM orchestriert echte **Multi-Tool-Loops** über die Tool-Registry — das Modul liefert nur Tool-Klassen |
| Tools = Inline-SQL am Service vorbei (Logik dupliziert, Drift-Risiko) | **Tools rufen Services** (Plattform-Gebot, 01_ARCHITEKTUR §1) — eine Logik, zwei Zugänge (UI + LLM) |
| Read-only by Prompt („ich kann nur lesen") — Schreibwünsche enden in einer Ausrede | **Schreib-Tools mit Approval-Flow**: Tool meldet beabsichtigte Änderung, User genehmigt in der Chat-UI, erst dann führt der Service aus (in TX, V-07; Override-First aus GL-07 gilt im Tool genauso). **Erledigt den Alt-Backlog „Phase 4.8 Schreib-Tools mit Approval-Flow".** |
| Foodbook-Tools (`show_foodbook_menus`, `show_menu_detail`) queryen die **Legacy-Tabellen `foodbook_menu*` (0 Zeilen)** — seit dem Kapitel-Redesign faktisch tot | Tools gegen `FoodbookService::getTree`/`listBlocks` — können nicht mehr vom Schema wegdriften |
| Kein `user_id`, keine Mandanten-Trennung | Conversations/Messages mit `team_id` + `user_id`; Tool-Ausführung läuft unter den **Policies des Users** (V-12) — der Chat kann nie mehr als sein Benutzer |
| Tool-Audit nur als JSON-Spalten an der Assistant-Message | zusätzlich je Tool-Call eine `role='tool'`-Message (Schema kann das schon!) + `ai_call_log` pro LLM-Call (V-09-Kosten) |

**Weitere Verbesserungen:**

| Ref | Verbesserung |
|---|---|
| V-07 | `upsertBlock` (Block + Staffeln), Planner-Übernahme (Block-Batch) und Approval-Schreib-Tools in DB-Transaktionen |
| V-17 | Foodbook/Kapitel/Kombination als URLs — kein Tab-State-Verlust im 3-Panel-Editor |
| V-13 | `LogsActivity` ersetzt das fehlende Änderungs-Audit an Foodbooks (wer hat den Preis im Kapitel geändert?) |
| Snapshot aktivieren (neu — V-Nummer beim Register-Nachtrag vergeben) | `snapshot_json`/`status='sent'` existieren im Schema, werden im Ist nie geschrieben — Ziel: Versand friert Stand ein, PDF rendert aus dem Snapshot (Angebots-Sicherheit) |
| Legacy-Schema kappen (neu — dito) | `foodbook_menu`/`foodbook_menu_block` (0 Zeilen) nicht portieren; 02_DATENMODELL §B entsprechend korrigieren |
| PDF-Export (neu — dito) | Kunden-PDF mit Bookmarks aus dem Kapitel-Baum — in der Alt-App nie umgesetzt |

## 7. Akzeptanzkriterien & Golden-Tests

1. **Baum-Invarianten:** `moveKapitel` verhindert Zyklen (Knoten unter eigenen Nachfahren) und Foodbook-Wechsel; `reorderKapitel`/`reorderBlocks` sind vollständige Permutationen (keine verlorenen IDs); Positionen danach lückenlos 0..n−1.
2. **Aggregat:** `kapitelAggregat` summiert rekursiv über den Subtree, zählt nur `sichtbar=1`-`recipe_ref`-Blöcke (menge-gewichtet), expandiert `kombination_ref` einmal (keine Rekursion möglich — Schema erlaubt keine Kombination-in-Kombination); EK/VK-Werte konsistent mit D-6/GL-11-Preislogik (Golden-Dataset: Ist-Foodbook „2027" mit 8 Kapiteln/7 Blöcken als Seed-Fixture).
3. **Blöcke:** `upsertBlock` mit `preis_basis='staffel'` persistiert Block + Staffeln atomar; Typ-Feld-Validierung (recipe_ref ohne `vk_recipe_id` → typisierte Exception, V-06); `nextVariantGroupId` liefert max+1 im Kontext, Variant-Gruppen-Zuweisung auf fremde Kapitel-Blöcke wird abgelehnt.
4. **Kombination live:** Änderung einer Kombination ist sofort in jedem referenzierenden Foodbook-Aggregat sichtbar (Live-Referenz, bewusster Kontrast zu ⚠D2-Rezept-Snapshots); Löschen einer referenzierten Kombination wird verhindert oder löst Block-Bereinigung aus (Entscheid im Implementierungs-PR dokumentieren).
5. **Chat-Lebenszyklus:** jeder Turn persistiert User-Message, n Tool-Messages (`role='tool'`, mit tool_name/params/result), Assistant-Message und ≥1 `ai_call_log`-Zeile mit `team_id`+`user_id`; Conversations sind strikt team-/user-gescoped.
6. **Approval-Pflicht:** Schreib-Tools führen ohne erteilte Genehmigung NICHTS aus (Test: LLM fordert Schreib-Tool an → DB unverändert, Approval-Request persistiert; nach Genehmigung → Service-Aufruf in TX, GL-07-Override-First respektiert).
7. **Tool=Service-Parität:** Für jedes Read-Tool existiert ein Test, dass Tool-Output und direkter Service-Aufruf identisch sind (kein SQL-Drift wie im Ist).
8. **Snapshot/PDF:** `status='sent'` ohne `snapshot_json` ist unmöglich; PDF/HTML-Export rendert ausschließlich `sichtbar=1` und Konsumenten-Felder (kein `interne_bemerkung`-Leak — Abnahme-Szenario mit internem Kommentar im Fixture).
