# Spec 48 — Wissen/Token-Programm: Regelwerk vollständig in den Prompt, Token runter

> **Tracking:** Office Dev-Package 23, Features-Board (`dev_board_id=53`).
> **Status:** Welle 0 + Klasse-2-Extraktion **deployt**. Welle 1 (Chunking) offen, Welle 2 (Kanon) wartet auf fachliche Abnahme, Welle 3 teilweise (W3-1 deployt, W3-2/W3-4 offen).

## Anlass

Gemessen auf demo über 30 Tage (`foodalchemist_ai_call_log`): **~62.700 Input-Token pro
erzeugtem Rezept/Gericht**, 17,87 M Token gesamt, Cache-Quote **0,35 %**. Ein Gericht mit 5
Sub-Rezepten löst 80–100 Calls aus. Das trägt keine Speisepläne und keine Foodbooks.

Der schlimmere Befund war aber nicht die Menge, sondern **was ankam**: von 9 an
`recipe.generator` gebundenen Regelwerk-Dossiers erreichte den Prompt **keines**.

## Der Fund, der alles erklärt (W0-3b)

`RecipeGenerationContextService` spiegelte die gebundenen Dossiers nach
`contextFor()->files_used`, damit sie im „Verwendetes Wissen"-Chip erscheinen. Aber
`files_used` geht als `knowledge_used` an `propose()` und ist dort der **Dedup-Eingang** von
`selectBoundKnowledge()`: jedes Doc, das schon als „verwendet" gemeldet ist, wird
übersprungen.

**Der Bound-Kanal war für beide Generatoren komplett tot** — seit dem Spiegel (2026-08-07),
nicht seit Welle 0. Der Workaround vom 2026-08-27 („8 Always-Dossiers binden") hat nie
gewirkt: nicht „3 von 9 kamen an", sondern **0 von 9**. Und der beabsichtigte Nutzen trat
auch nicht ein, weil der Kontext-Inspektor `used_by_category` liest, nicht `files_used`.

> **Lehre, die für den geplanten Kanon (W2) genauso gilt: eine Anzeige darf nie auf dem Feld
> mitschreiben, das eine Auswahl-Logik als Eingang benutzt.**

## Belegte Wirkung (demo, gleiche Anfrage zweimal generiert)

| | vor Fix | nach Fix | Baseline 30 T. |
|---|---|---|---|
| `vk.generator` tokens_in | 7.230 | **16.778** | ⌀ 36.918 |
| `prompt_parts.bound` | **0** | **28.630** | — |
| Bau-§§ im Prompt | 0 von 7 | **7 von 7** | 0 von 7 |

**−55 % gegenüber der Baseline — und erstmals MIT vollständigem Regelwerk statt ohne.** Die
7.230 des ersten Laufs waren kein Gewinn, sondern fehlendes Pflichtwissen.

`recipe.generator` Pflichtmenge **25.282 → 18.521** Zeichen (Deckel 19.000): die Kappung von
6.282 Zeichen bindendem Regelwerk ist weg.

## Architektur-Entscheid: Wissen nach FUNKTION trennen

Der Korpus enthält drei Sorten Wissen, die vorher alle durch denselben Trichter gingen:

1. **Achsen-gebunden → nachschlagen statt suchen** (~90 Docs): `event_playbook` (13 Docs ↔ 13
   Zeilen `foodalchemist_event_types`), `segment`, `niveau`, `weltkueche`, `ernaehrung`. Join,
   100 % präzise, kein Embedding. Das Muster existierte schon (`niveauBlock()`).
2. **Regelwerke → durchsetzen statt in den Prompt legen** (~154k Zeichen): §5 Default-GPs ist
   eine Lookup-Tabelle, §7 ein Aggregat, §12 ein Sortier-Anker. Zwänge gehören in
   Resolver/Validatoren + den Konformitäts-Critic (der hat den Volltext ohnehin und zitiert
   §-genau). Der Generator bekommt eine Checkliste.
3. **Offenes Material → echter Suchfall** (~460 Docs): `domain`, `cross_cutting`, `kueche`,
   `signatur_kuechen`. Nur hier lohnt Chunking. **Welle 1 schrumpft dadurch von 598 auf ~460.**

## Gemessen und dabei WIDERLEGT — drei Plan-Prämissen

| Prämisse | Messung |
|---|---|
| „Nur 52 % des Korpus semantisch findbar" (W1-1: Fenster 2000→8000) | **Zeichen-Rechnung, keine Messung.** Recall-Probe: Kopf 92 %/92 %, jenseits 2000 **72 % → 68 %**. Grösseres Fenster macht es SCHLECHTER (Verdünnung). Zurückgenommen. **Chunking (W1-5) bleibt der einzige Fix — jetzt gemessen begründet.** |
| „§5 entbinden spart Token" | **Spart NICHTS.** Der freie Platz füllt sich bis zum Deckel mit `discovery`-Bindings. Gewonnen wurde Inhaltsqualität. **Wer entbindet, muss den Deckel mitsenken.** |
| „W3-3 spart 39 %" | 17–21 %. Die §-Dossiers enthalten **kein** Changelog, und die 21 % Tabellen sind normativ. |

### Bindung ≠ Durchsetzung — die Zahl, die W2 entscheidet

An `conformance_findings` gemessen: §7/§12 entbunden + code-erzwungen = **0 Befunde**. §2
gebunden = **27**. §1 unbesetzt (weder gebunden noch erzwungen) = **65 (39 %)**. Break-even
für §1+§10 im Prompt liegt bei 10,2 %.

## Was sonst deployt ist

- **Messsonde** `prompt_chars` + `prompt_parts` (kanon/retrieval/bound/task/kontext/huelle/dropped).
  Ohne sie wären alle Prozentzahlen Schätzungen — sie hat W0-3b überhaupt sichtbar gemacht.
- **W0-1** `with_context=false, tools=false`: der Core stellte `'Zeit: '.now()` vor JEDEN Call
  ⇒ Prefix-Cache prinzipiell unmöglich. **Nicht** im agentischen Tier-D-Loop.
- **W3-1 Cache-Prefix**: Message-Layout festgeschrieben (1–3 Hüllen · 4 Regelwerk · 5 task ·
  6 Retrieval · 7 Kontext-JSON). Prefix **byte-stabil bei 24.718 Zeichen ≈ 8.239 Token**,
  belegt. Ob der Provider es monetarisiert, ist offen (`prompt_cache_key` ist nicht
  durchleitbar — Bitte an Martin in Spec 47).
- **Sechs stille Deckel sichtbar gemacht** (Speiseplan-Wochen, Zellen, Speisekarten-Positionen,
  Konzept-Lücken, Sub-Rezept-Tiefe) — mit Meldungen, die sich aufrechnen. Darunter lagen **zwei
  echte Bugs**: ein `params`-Overwrite löschte die Leitplanken, und `MAX_STEPS` begrenzte die
  BREITE statt der Tiefe (Sub-Rezepte verhungerten lautlos).
- **Warteschlangen-Trennung nach Artefakt** → Runbook [[39_Worker_Betrieb_Runbook]] §1a.
- **Drift-Wächter**: `foodalchemist:wissen-steuerdaten-w0 --verify` wöchentlich, meldet als
  Signal `steuerdaten_drift`. Anlass: die Live-Routings wichen unversioniert von den
  Migrationen ab.
- **Routing-Politik verlässt den Import** → `foodalchemist:knowledge-policy-seed`.
  `seedRoutings()` hätte auf frischer DB (Disaster Recovery) den Monolithen-Pfad
  wiederhergestellt.
- **Zwei Matcher-Defekte** (`529567c8`, `97f6a991`): `gpPool` kappte nach **ID** statt Relevanz
  (je mehr Bestand gepflegt wird, desto unsichtbarer das Neue), und der Anti-Marker löschte
  den exakten Treffer der eigenen Suche.

## Offene Entscheide für Dominique

| # | Punkt | Konsequenz |
|---|---|---|
| 1 | **Kanon §1.0–1.2 + §10 als `always`** | Deckel 19.000→24.000 / 27.500→31.000. Kehrt eine dokumentierte Spec-41-Entscheidung um (gepinnt in `RegelwerkKnowledgeRoutingTest:80`). Datenlage: §1 = 39 % aller Befunde. |
| 2 | **§2/Convenience** | `$preferRaw = $convenience !== 'voll_convenience'` — Verarbeitungs-Reduktion code-erzwingen statt binden. |
| 3 | **Leitungswasser** (§11.2) | `Wasser` matcht auf Bio-Flaschenwasser; **62 Rezepte** rechnen falsch. Braucht zwei Sätze in §11 (`requires_la=0` auch für selbst-gestellte/kostenfreie Ware), dann ist die Umsetzung klein. Bestandsrezepte = getrennter Entscheid. |
| 4 | **Der zweite Kurator** | Retrieval liest heute gemeinsam, der Browser ist gescopet. Team-weit (A1) oder Team-Default + persönliche Übersteuerung (A2)? Fällt vor dem zweiten kuratierenden Team. |
| 5 | **Slot-Deckel für Angebot/Format** | Heute weder Gate noch Deckel. |

## Offene Bauschritte

| # | Punkt | Warum offen |
|---|---|---|
| W1-5 | **Chunking + Qdrant** (~460 Docs, Ziel 900 Z., `heading_path` im Vektor) | Der einzige gemessen begründete Retrieval-Fix. Off-peak, ~5.800 serielle Roundtrips, ~$0,10. |
| W3-2 | **Kontext-Wiederverwendung in der Kaskade** + Fan-out-Governor | Nicht gebaut. Der Kaskaden-Status kommt aus DB-Zeilen, nicht aus dem 15-Min-Cache — die TTL-Sorge des Plans ist also **kein** Live-Bug. |
| W3-4 | **Tier/Modell** | **0 von 4 Tier-Variablen auf demo gesetzt** ⇒ alle 72 Prompt-Keys auf dem teuersten Modell. Preisspanne Faktor 25. Cheapster offener Hebel — aber Tier C ist laut Config für Vision reserviert, also Key für Key prüfen, nicht blind per ENV flippen. |
| W1-3 | `$params['_prompt_key']`-Durchstich | Ohne ihn hat `vk.generator` kein eigenes Budget (VK läuft über `contextFor('ai_generate_recipe')`). |
| — | `conformance_findings.paragraph` normalisieren | 5 Varianten für §11 ⇒ Befunde sind nicht sauber aggregierbar. |
| — | `foodalchemist:generator-eval` | Existiert, **nie gelaufen**. Ohne es wird ein Budget-Schnitt als Erfolg abgehakt, während die Rezepte schlechter werden. |
| — | `ai_plan_dishes` | 8 Routing-Zeilen ohne jeden Aufrufer. Reine Konfig-Schuld. |

## Deploy-Mechanik

demo = lokal `update.sh` (macht blind `git add .` → vorher `git status`, nur eigene Dateien
stagen). Vor jedem Push `git branch --show-current`, dann `git push origin HEAD:main`. Bei
Migrationen FA-scoped per SSH:
`php artisan migrate --path=vendor/martin3r/platform-foodalchemist/database/migrations`.

Cross-Refs: [[39_Worker_Betrieb_Runbook]] · [[41_Spec_Planungsmodul_Qualitaet]] ·
[[45_Voice_Produkt_Agent]] · [[46_Kanon_Entscheidungsvorlage]] ·
[[47_Notiz_an_Core_Semantic_Layer]]

## Changelog
- **2026-09-03** — Erstanlage. Programm lief bis hier nur in einer Session-Plandatei; damit war der Repo-Stand blind für 14 Commits. Zahlen sind gemessen (Messsonde `prompt_parts`), nicht geschätzt.
