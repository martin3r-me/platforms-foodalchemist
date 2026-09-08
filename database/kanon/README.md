# Kanon-Sicherungen

Der **Kanon** (`foodalchemist_knowledge_canon`) sagt, welche Wissens-Dossiers verbindlich in
welchen Prompt gehören. Er entsteht ausschliesslich über `knowledge_canon.PUT` (MCP) und lebt
danach **nur als Zeilen in der Live-Datenbank** — es gibt keinen Seeder und keine Migration,
die ihn füllt (Spec 52, Befund `H7`).

Für eine frische Umgebung heisst das: **kein Kanon**. Und weil `hasCanon()` dann `false`
liefert, schaltet `AiGatewayService` die alten Bindungen wieder scharf, die dort ebenfalls
nicht existieren. Die Generatoren laufen ohne Regelwerk — ohne Fehlermeldung, nur mit
schlechteren Rezepten. Diese Dateien sind der Rückweg.

## Bedienung

```bash
# Live-Kanon sichern (schreibt/aktualisiert kanon-team-<id>.json)
php artisan foodalchemist:wissen-kanon-sicherung export --team=6

# Läuft die Sicherung gegen den Live-Stand auseinander? (Exit ≠ 0 bei Drift)
php artisan foodalchemist:wissen-kanon-sicherung pruefen --team=6

# In eine frische Umgebung zurückspielen (ohne --apply nur Vorschau)
php artisan foodalchemist:wissen-kanon-sicherung import --team=6 --apply
```

Auf **demo gibt es keine Shell.** Dort beantwortet `foodalchemist.knowledge_kanon_sicherung.GET`
dieselbe Frage per MCP — read-only, weil eine über MCP geschriebene Datei auf dem Server
landete statt im Repo.

## Was drin steht — und was nicht

Gesichert wird die **Kuration**, nicht ihr heutiges Ergebnis: auch stillgelegte Zeilen
(`active: false`) sind Teil der Entscheidung und stehen mit drin. `active` und `global`
kommen beim Import unverändert zurück.

Gesichert wird **nur die Verdrahtung, nie der Inhalt**. Die Dossiers selbst kommen aus dem
Wissens-Import (`foodalchemist:knowledge-import`) bzw. dem Vault-Spiegel. Nennt eine Sicherung
einen Slug, den es in der Zielumgebung nicht gibt, meldet der Import das als Befund und spielt
den Rest trotzdem ein — nach einem Korpus-Neuschnitt ist das der Normalfall. Den Nachfolger
benennt dann `knowledge_links.SET` mit `art=ersetzt`.

## Aktualität

Die Datei ist nur so gut wie ihr letzter Export. `pruefen` gehört deshalb zu jeder Runde, in
der am Kanon gearbeitet wurde — sonst sichert das Repo einen Stand, den niemand mehr meint.

| Datei | Umgebung | Stand |
|---|---|---|
| `kanon-team-6.json` | demo, Team 6 (Demo) | 2026-09-08 · 28 Zeilen (`recipe.generator` 13, `vk.generator` 12, `concept.brief_geruest` 3) |
