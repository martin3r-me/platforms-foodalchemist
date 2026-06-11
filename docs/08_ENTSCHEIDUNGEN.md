---
typ: Decision-Registry
stand: 2026-06-10
status: D1–D5 mit Arbeits-Annahmen — finale Entscheide ausstehend
---

# 08 — Entscheidungs-Registry (offene Weichen)

> Jede Weiche hat eine **Arbeits-Annahme**, mit der alle anderen Korpus-Dokumente geschrieben sind. In den Specs steht am betroffenen Ort nur der Anker `⚠Dx`. Fällt ein Entscheid anders aus als die Annahme, werden die referenzierten Stellen nachgezogen — die Architektur ist bewusst so geschnitten, dass ein D-Entscheid **Seiten ändert, nicht Strukturen**.

## D1 — Tenancy-Scope der geteilten Stammdaten — ✅ ENTSCHIEDEN: ELTERN→KINDER-VERERBUNG (User, 2026-06-11; revidiert die NULL-Global-Annahme)

| | |
|---|---|
| **Entscheid (Dominique)** | Aufbau wie beim echten Caterer/Gastronomen: **Eltern-Team pflegt den Katalog, Kind-Teams erben ihn lesend und ergänzen Eigenes.** Kein NULL-Global-Sonderfall mehr — „zentral" = Daten des Eltern-/Root-Teams. Wörtlich: „wir brauchen eine zentrale Stelle wo alles gepflegt werden kann und das an die Teams raus geht, eigenes Team kann aber auch selber Sachen machen … ein Eltern-Team und unten drunter sind die Kinder". |
| **Umsetzung** | Alle Tabellen: `team_id` NOT NULL (Besitzer). Sichtbarkeit = eigenes Team + Eltern-Kette aufwärts → Concern `BelongsToTeamHierarchy::scopeVisibleToTeam()` (`whereIn team_id ancestryIds`, Kette aus Cores `teams.parent_team_id`, je Request gecacht). Edit nur bei `isOwnedBy($team)` — Katalog-Pflege durch Admin-/Root-Team (zweiter User-Entscheid 2026-06-11). Plattform-nativ: `parent_team_id` + `getRootTeam()` existieren im Core. Import: `--team=<id>` (Katalog-Besitzer). |
| **Kind-Overlay** | Einkaufs-Entscheidungen pro Kind-Team OHNE Datenkopie: `foodalchemist_gp_team_overrides` (gp × team → Lead-LA-Override, gesperrte LAs) = Mechanik für V-27 („LA A will ich nicht, B geht"). Team-eigene Preise/Konditionen auf Eltern-LAs: gleiches Muster, schema-seitig vorgesehen, **gebaut erst bei Bedarf** (V-28). |
| **Abgedeckte Welten** | Einzel-Gastronom = 1 Team ohne Kinder (Kette = nur er selbst); BHG = Root + 8 Caterer-Kinder. Keine Sonderfälle. Rezepte: Eltern-Katalog sichtbar, eigene Anpassung = Snapshot-Kopie ins Kind-Team (D2 unverändert). |
| **Risiko** | Vergessener `visibleToTeam`-Scope leakt Daten zwischen Geschwister-Teams → Concern-Pflicht in allen Services + Pest-Test „Geschwister sieht nichts" pro Sektion. |
| **Anker in** | `02_DATENMODELL.md` (Scope-Spalte), `05_DOMAENEN/D-2…D-3`, `07_MIGRATION_SEED.md`, `src/Models/Concerns/BelongsToTeamHierarchy.php` |

## D2 — Rezept-Vorlagen-Semantik (BHG-Bibliothek → Teams)

| | |
|---|---|
| **Frage** | Wenn BHG-Rezepte als Start-Bibliothek an Caterer-Teams gehen: Live-Referenz oder Kopie? |
| **Optionen** | (a) Snapshot-Kopie beim Übernehmen · (b) Live-Referenz auf globales Rezept · (c) Hybrid (Referenz + Copy-on-Write) |
| **Impact** | (b) macht Kalkulationen fremdbestimmt (BHG ändert Rezept → Marge des Caterers ändert sich ungefragt). (a) ist vorhersagbar, kostet Speicher. |
| **Arbeits-Annahme** | **(a) Snapshot-Kopie.** Globale GP-/LA-Stammdaten bleiben Live-Referenz (das ist deren Job), Rezepte werden kopiert. |
| **Anker in** | `05_DOMAENEN/D-5`, `07_MIGRATION_SEED.md` |

## D3 — KI-Konvention der Plattform — ✅ AUS CORE-CODE BEANTWORTET (2026-06-11)

| | |
|---|---|
| **Befund** | **Beides existiert im Core:** (1) Zentraler LLM-Service `OpenAiService` hinter **`LLMProviderContract`** (provider-abstrahiert; `config/ai.php`, **Default: Anthropic claude-sonnet-4-6** — nicht Gemini!). (2) **`core.semantic_layer`** mit `SemanticLayerResolver::resolveFor(?Team, ?string $module)` — Details + Mapping: GL-06 §6. |
| **Entscheid (Option a, hybrid)** | Der Modul-`AiGatewayService` bleibt als **Fassade** (Feature-Tiering V-01, structural/degeneration-Retry V-02, `foodalchemist_ai_call_log`-Audit, Prompt-Komposition GL-13), **delegiert den Transport aber an den Plattform-`LLMProviderContract`** — kein eigener HTTP-Client, kein eigenes Key-Handling. Voice-Hüllen (global/team) → Core-SemanticLayer; Field-Hüllen + TASK_PROMPTs → modul-eigene Prompt-Registry (GL-06 §6 Hybrid). |
| **Konsequenz Tiering** | Tier-Klassen A/B/C/D bleiben abstrakt; konkrete Modell-Strings sind **Provider-/Deployment-Config** (`config/ai.php`), nicht Spec. Die Ist-Token-Zahlen (06_KI §2) bleiben als Dimensionierungs-Grundlage gültig. |
| **Restfragen (klein, an Martin)** | (1) **Embeddings**: unterstützt der Plattform-Provider Embedding-Calls (Ist: Gemini 768-dim für Matching-RAG + Re-Embed V-24)? Falls nein: Embedding-Provider als Modul-Ergänzung hinter eigenem Contract. (2) Multimodal/Vision-Support des Providers (Track D Rezept-Extraktion). (3) Rate-Limit/Kosten-Steuerung pro Team im Core-Service vorhanden oder Gateway-Aufgabe? |
| **Anker in** | `01_ARCHITEKTUR.md` (D-4), `06_KI_SPEZIFIKATION.md`, GL-06 §6, `02_DATENMODELL.md` (`ai_layer*`-Disposition) |

## D4 — Wissens-Auslieferung (07_WISSEN) — ✅ ENTSCHIEDEN 2026-06-11 (Dominique)

| | |
|---|---|
| **Entscheid** | **(a) Modul-Tabellen** `foodalchemist_knowledge_*` (Schema: GL-13 §4.3) — verfeinert um das **Drei-Klassen-Modell**: |
| **Klasse A (KI-Runtime → DB, MVP)** | 07.01 Cross_Cutting (33) + Domains (36) + 07.02 Pairing-MDs (767) + **Regelwerk-Snippets** (nur prompt-relevante §§, nie das Vollwerk). ~850 Dokumente ≈ 8 MB. Dazu Aliasse (258, heute hartkodiert) + Routings als Daten. |
| **Klasse B (bleibt im Vault)** | 07.04 Literatur, 07.05 Marktstudien, 07.06-PDFs (311+ MB) — Autoren-Fundus, kein Runtime-Wissen. |
| **Klasse C (Phase 2)** | 07.06-Destillate (75, für Claude-Skills gebaut) + 07.03 Trend-Pulse (dynamisch, eigener Feed-Job) — erst mit Composer-/Beratungs-Features. |
| **Sonderfälle** | **Niveau_System → Hüllen** (semantic layer), nicht Knowledge — sind Prompt-Bausteine, heute fälschlich hardcoded (commands.rs:12013ff). Regelwerke behalten Doppel-Rolle: Spec (eingefroren im Korpus) ≠ Runtime-Snippets. |
| **Prozess** | **Vault bleibt Autoren-Umgebung.** EINBAHN Vault→SaaS via wiederholbarem Kommando `foodalchemist:knowledge-import` (Upsert per slug, Versions-Stempel, content_hash). **Pairing-Konsistenz-Kopplung:** Der Import parst beim Einspielen der Pairing-MDs die Kanten und aktualisiert `pairing_anker_edges` + triggert Re-Embed (V-24) — MD und Graph können nie mehr auseinanderlaufen (ersetzt manuellen Parser-Lauf der Alt-Welt). |
| **Offen bleibt** | Authoring-in-der-Plattform (wenn andere Teams Wissen pflegen sollen) = eigenes Phase-2+-Feature; Team-Sichtbarkeit kuratierter Inhalte hängt an ⚠D1. |
| **Anker in** | `04_GRUNDLOGIKEN/GL-13`, `02_DATENMODELL.md` §F, `07_MIGRATION_SEED.md` §4.5 |

## D5 — MVP-Schnitt

| | |
|---|---|
| **Frage** | Welche Domänen gehören in den ersten Go-live? |
| **Arbeits-Annahme (Dominique, 2026-06-10)** | **D-1…D-6 = MVP** (Vokabulare, Lieferanten/LA, Grundprodukte, KI-Infrastruktur, Basisrezepte, Verkaufsrezepte). **D-7 Pairing + D-8 Foodbook/Chat = Phase 2.** |
| **Präzisierung (Dominique, 2026-06-11)** | **Rezept-gebundene Pairing-Features sind MVP** (Pairing-Section, Kern-Anker, Kohäsions-Score, Komponenten-Netz, Generator-Grounding, Pairing-Schritt im Enrich-Orchestrator — 25 Commands, siehe `MVP_OVERRIDES` im Extraktions-Skript + D-6 §5.x). Begründung Produktvision: Foodpairing+KI im Rezept-Workflow = Kern-Differenzierung; größte Pain-Points = Basisrezept-Anreicherung + händische VK-Anlage. D-7 bleibt als Explorations-Domäne (Bridge, verwandte Rezepte, Graph-Browser) Phase 2. |
| **Offen** | Feinschnitt innerhalb der Domänen (z.B. welche KI-Features im MVP) — fällt in E4/E5 pro Domänen-Spec. |
| **Anker in** | `03_FEATURE_INVENTAR.md` (MVP-Spalte, generiert), alle `05_DOMAENEN/*` |

---

## Kleinere fachliche Entscheide (aus E3/E4 aufgelaufen)

> Namens-Konvention: Entscheide heißen `D1…Dn` (ohne Bindestrich), Domänen `D-1…D-8` (mit Bindestrich).

## D6 — Deckungsbeitrags-Formel (VK)

| | |
|---|---|
| **Befund** | Das Alt-UI zeigte eine `deckungsbeitrag`-Anzeige, deren Backend-Formel nie implementiert wurde (GL-02 W-1, D-6-Spec). |
| **Frage** | Definition im Ziel: DB = vk_netto − EK? − anteilige Gemeinkosten? Pro Portion oder pro Charge? |
| **Arbeits-Annahme** | DB₁ = `vk_netto − ek_pro_portion` (einfachste belastbare Definition), Erweiterungen später. |
| **Anker in** | `05_DOMAENEN/D-6` (MargeService), GL-02 §3.6 |

## D7 — Verlust-Formel (Yield)

| | |
|---|---|
| **Befund** | Ist-Code rechnet multiplikativ `(1−putz)×(1−gar)` aus Zutat-Feldern; Regelwerk Basisrezepte F6.2 beschreibt additiv aus GP-Stammdaten; GP-Putzverlust-Spalte existiert nicht (GL-02 A-1). |
| **Frage** | Welche Formel + welche Datenquelle ist Ziel-normativ? |
| **Arbeits-Annahme** | Multiplikativ (Ist-Verhalten, mathematisch sauberer), Verlust-Faktoren auf Zutat-Ebene mit GP-Default-Fallback; Regelwerk F6.2 bei nächster Regelwerk-Revision angleichen. |
| **Anker in** | GL-02 §6, `05_DOMAENEN/D-5` |

## D8 — STT-Weg für Voice-Interface (M7-10) — ✅ ENTSCHIEDEN (Dominique, 2026-06-11)

| | |
|---|---|
| **Befund** | Plattform-Modul **`platforms-whisper`** (public, `martin3r-me/platforms-whisper`) liefert Browser-Recorder (MediaRecorder, Opus mono → Blob-POST) + `AssemblyAiTranscriptionService` (upload→submit→poll, `language_code=de`, Diarization). Pipeline ist **async** (Queue + 3-s-Polling, für Meetings gebaut; Echtzeit-Streaming explizit out-of-scope) — ungeeignet für Befehls-Latenz. Kein STT-Contract im Core. |
| **Optionen** | (a) `platforms-whisper` als Modul-Abhängigkeit · (b) eigener schlanker **sync Kurz-Audio-Pfad** im foodalchemist-Modul nach gleichem Muster · (c) STT-Contract in den Core (analog `LLMProviderContract`) |
| **Entscheid (Dominique)** | **(b).** Keine Fremdmodul-Abhängigkeit (Goldene Regeln), keine Core-Änderung nötig; whisper-Pipeline passt fachlich nicht (Meeting- statt Befehls-Profil). Präzedenz: `platforms-whisper` ruft AssemblyAI selbst direkt via HTTP — die D3-Regel „kein eigener HTTP-Client" betrifft den **LLM**-Transport (`LLMProviderContract`), STT ist dort nicht abgedeckt. |
| **Umsetzung** | Eigener `SttServiceContract` + `AssemblyAiSttService` im Modul: synchroner Kurz-Audio-Call (wenige Sekunden Audio; kurzes Poll-Intervall ≪3 s oder Streaming-API, ohne Diarization, `language_code=de`). **Hinter Interface**, damit ein späterer Core-STT-Contract (Option c) per Binding-Tausch übernehmen kann — gleiche Fassaden-Logik wie D3. Recorder-Frontend nach whisper-Vorbild (MediaRecorder, Opus mono). |
| **Rest (Deployment, Martin)** | `ASSEMBLYAI_API_KEY` auf office/demo vorhanden/teilbar? Blockiert nur den Deploy, nicht den Bau (Sandbox: eigener Key). Courtesy-Heads-up an Martin, dass foodalchemist AssemblyAI direkt anbindet. |
| **Anker in** | `12_ROADMAP.md` (M7-10, Offene Entscheide), Dev-Modul Discussion #1; `06_KI_SPEZIFIKATION.md` ergänzen, sobald M7-10 gebaut wird |

## Entscheidungs-Log

| Datum | Weiche | Entscheid | Von |
|---|---|---|---|
| 2026-06-11 | **Modul-Template** | Template-Vorlage ist GESETZT = verbindlicher Bau-Kern des Moduls (01_ARCHITEKTUR §2 dev-bestätigt) | **Martin (Plattform-Dev)** |
| 2026-06-11 | **D1** | team_id nullable = global — durch Core-Code-Präzedenz belegt (document_templates, obsidian_vaults) | Code-Befund (platforms-core) |
| 2026-06-11 | **D3** | Zentraler LLMProviderContract + core.semantic_layer existieren → Gateway wird Fassade, Hüllen-Hybrid (GL-06 §6); Restfragen: Embeddings/Vision/Team-Rate-Limit | Code-Befund (platforms-core) |
| 2026-06-11 | **D4** | Modul-Tabellen + Drei-Klassen-Modell + Einbahn-Import mit Pairing-Parser-Kopplung; Niveau→Hüllen | Dominique |
| 2026-06-11 | **Lead-LA (Produkt-Anforderung)** | Strategie-Einstellung (Stamm vs. günstigster Preis) + Ausweich-Kette + Sperr-/Pin-Workflow für Einkäufer, team-scoped Overlay → **V-27** | Dominique |
| 2026-06-11 | **D5-Präzisierung** | Rezept-gebundene Pairing-Features = MVP (Produktvision); D-7 nur als Exploration Phase 2 | Dominique |
| 2026-06-11 | **D8** | Voice-STT (M7-10) = eigener sync Kurz-Audio-Pfad im Modul hinter `SttServiceContract` (Option b); kein Fremdmodul-Require, kein Core-Eingriff; Rest: API-Key-Deployment (Martin) | Dominique |
| 2026-06-10 | D5 (Arbeits-Annahme) | MVP = D-1…D-6 | Dominique |
| — | D1–D3, D6, D7 | offen, Arbeits-Annahmen aktiv | — |

## E-2026-06-12 — GL-02 A-1: Verlust-Formel multiplikativ (Empfehlung umgesetzt, Bestätigung offen)

Regelwerk §6 F6.2 formuliert additiv `(1 − putz − gar)`; Ist + GT-1/GT-2 (DB-verifiziert)
rechnen **multiplikativ** `(1−putz)×(1−gar)` aus den Zutat-Feldern. Umgesetzt: multiplikativ
(robust, nie < 0) — GL-02-Spec-Empfehlung. GT-5 entsprechend fixiert (1000 g · 20 %/10 % ⇒ 720 g).
**Offen:** Bestätigung Fachseite (Dominique/Martin); bei Veto: eine Zeile im Service + GT-5 drehen.
Verlust-Quelle bleibt Zutat-Feld (GP-Default-Override = GL-02-Folgearbeit, V-Kandidat).
