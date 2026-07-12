---
titel: Food Alchemist — FoodBrain Memory / Status
stand: 2026-07-11
zweck: Repo-Handoff für das Pairing/Chemie/Graph-Fundament ("FoodBrain") des Food Alchemist
---

# FoodBrain — Memory & Status (2026-07-11)

Kompakter Wiedereinstieg für das Pairing-/Chemie-/Graph-Fundament des Food Alchemist.
Volles Detail + Historie: Claude-Memory `project_fa_3db_datamodell.md` +
`project_fa_klarschiff_cleanup.md`. Live-Arbeitsdocs (Pairing) liegen im **Vault**
(`07_WISSEN/07.02_Flavor_Pairing/Datenbank Foodalchemist/`), NICHT in `_FoodBrain_Docs/`
(das ist nur ein älterer Snapshot — Vault ist die Quelle).

## Architektur in einem Satz

**Modul = Code-Wahrheit (git) · EINE SQL-DB = Daten-Wahrheit + Laufzeit + Rechenbasis.**
**GRAPH KOMPLETT RAUS (Entscheid 2026-07-11):** kein Kùzu/Neo4j/SPARQL — weder Runtime noch Linse
noch Autoren-Schicht. Alles relational; Mehr-Hop/Bridging via MySQL-8.4-`WITH RECURSIVE`. Die
KùzuDB/Neo4j-Artefakte auf Platte sind reine Historie, nicht Teil des Modells.

> **ARCHITEKTUR-ENTSCHEIDUNG 2026-07-11:** Die Daten-Wahrheit wandert von der **Sandbox-SQLite**
> auf ein **lokales MySQL** (= künftiger Kanon). Sandbox-SQLite wird abgelöst; demo/Forge wird aus
> dem lokalen MySQL per `foodalchemist:import-master` gefüttert (Deploy, nicht Dev). Bulk-Skripte
> werden von `sqlite3` auf MySQL portiert. **Übergang läuft** — bis abgeschlossen ist die
> Sandbox-SQLite noch der faktische Daten-Ort.

| System | Rolle | Ort |
|---|---|---|
| **App-Code** | Modul (Code-Wahrheit, git) | `platform/modules/platforms-foodalchemist/` |
| **SQL-DB** | Daten-Wahrheit + App-Laufzeit + Rechenbasis | Übergang: `sandbox-food-alchemist/database/database.sqlite` → **lokales MySQL** |
| **chemie_db** (SQLite) | Autoren-Labor / Zweitkopie | `.../Datenbank Foodalchemist/chemie_db.sqlite` (Vault, 100 MB) |

> ~~Graph (Kùzu/Neo4j)~~ = **RAUS** (2026-07-11). Frühere Rebuild-Kette (`build_knowledge_graph.py`
> → `load_neo4j.py`) + Neo4j-Install `08_INSTALL/neo4j/` = Historie, nicht mehr Teil des Modells.

## Datenmodell-Ebenen (Spec: `Datenmodell Food.Alchemist.md`, 5 Ebenen)

- **Ebene 1 Rohstoff** — Anker + Moleküle + Chemie. ✅ FERTIG.
- **Ebene 2 Zustände/Zubereitung** — Prep-Delta + zustands-abhängiges Pairing. ✅ FERTIG (Layer-2, 2026-07-11).
- **Ebene 3 Rezept-Werkstatt (Basisrezepte)** — Signatur-Netz (kern-Anker) + Zustands-Charakter im Netz. ✅ Kern verdrahtet (2026-07-11); Rezept-als-eigener-Anker + emergente Kanten offen.
- **Ebene 4 Gericht** / **Ebene 5 Event** — später.

## Layer-2-Abschluss 2026-07-11 (Zustands-Pairing → Rezept)

- **Zustand ins Rezept-Netz verdrahtet** (Weg 1 „Signatur-Netz", NICHT computed-Aggregat — der ist gemessen matschig): `recipe_process_anchors` aus `raw_text`-Prep (788 Stück: roestaromen/rauch/karamell), 2 PHP-Edits in `PairingService.php` (resolveRecipeAnchors + panelRecipe ziehen den eigenen Zustands-Charakter). Lokal, lint-grün, tinker-verifiziert, **nicht gepusht** (Martin-Gate).
- **Substrat-Mapping** (#468): 153 Links → scorebare Anker **605 → 748**. Skripte `layer2_process_anchors.py` + `layer2_substrate_match.py` (Vault). Staging-Rest (67 fuzzy) offen.

## Pairing-Logik (`src/Services/PairingService.php`)

- **Kanten-Relationen** klassisch/aroma/modern/kontrast mit GEWICHTE (aroma=0.9). Panels liefern `aroma`-Block + `geschmack`-Profil + (neu) Zustands-Komponente.
- **Zustands-Pairing** `statePairingNeighbors(anchorId, prepSlug)`: 14-Typ-Vektor ⊕ Prep-Delta, Kosinus neu. Abdeckung **748/1000 Anker** (kc_derived + Substrat-Links). Engine-Spiegel: `state_pairing.py`.
- **Kohäsion:** deterministisch live (`cohesionFor`, 66% der Rezepte bewertbar, Coverage-Loch 44%). KI-Judge-Achse `recipe_culinary_coherence` LEER — braucht echten Provider (Sandbox=fake), ~3.218 Calls, geparkt.

## Klarschiff 2026-07-11 (Aufräumen)

- **~6 GB frei:** Pairing-Ordner 5,9 G → 949 M, Sandbox-DB-Ordner 2,8 G → 1,8 G (redundante Backups + re-downloadbare Rohquellen weg). Skript-Ordner 36 → 26 aktiv + 10 archiviert (`_oneshots_erledigt/` + `_SKRIPTE_INDEX.md`).
- CAVEAT: chemie_db nicht mehr aus `_data/foodb` rebuildbar ohne Re-Download (chemie_db selbst intakt).

## Offen (nächste Züge)

1. **SQL komplett nach lokalem MySQL migrieren** (heute Schritt 2): MySQL lokal installieren → sauberen Stand migrieren → Bulk-Skripte porten → demo-Feed (Martin).
2. **UI-Rendering** aroma/geschmack/Zustand (#468, Board 53).
3. **Front C — Daten VERBESSERN (nicht löschen):** unfertige Rezepte vervollständigen, fehlende Lieferantendaten ergänzen, GPs korrekt verknüpfen. Eigene Session.
4. PHP-Edits committen/pushen; 67 fuzzy Substrate; KI-Judge auf demo.

## Prinzipien (nicht verletzen)

- Keine Erfindungen: jeder Wert auf reale Quelle (Ahn CC-BY / Buch / FooDB) + Provenienz; fehlend = low_evidence.
- EINE SQL = Wahrheit + Laufzeit + Rechenbasis. Kein Graph (raus 2026-07-11).
- FooDB-Rohkonzentration NICHT für Aroma/Geschmack (2× gemessen unbrauchbar).
- Daten-Cleanup = VERBESSERN, nicht löschen (Dominique 2026-07-11).
