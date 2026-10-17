-- Spec 84 B3 Stufe 1 · Abo-Zweig als Menge statt OR — Mess- und Gleichheitsprüfung gegen MySQL (reproduzierbar).
-- NIE gegen die Master-DB: eigene Kopie anlegen (mysqldump foodalchemist | mysql spec84_b3), dann dieses Skript dort.
-- Gemessen 17.10. lokal auf MySQL 8.4.3 (io: 8.4.11, gleiche Hauptversion — der Optimizer entscheidet hier).
--
-- Messaufbau in der Kopie (einmalig, Kurzform): Team 6 = Master, 300 Master-Rezepte ist_fassung = 1 + Abo-Zeilen
-- (foodalchemist_abo_objekte, typ 'recipe') für Mandant 9100; 200 Rezepte auf Team 9100 umgehängt; danach ×20 skaliert
-- (Kopien mit team_id 6000+i bzw. 9200+i, legacy_id NULL) → 35.090 Rezepte. Betrachter = Mandant 9100, Kette [9100].
--
-- Ergebnis (EXPLAIN ANALYZE, ORDER BY name LIMIT 50, je 2 Läufe):
--   A  heute: OR … OR id IN (Abo)                       ≈ 35 ms   (Vollscan, linear mit der Tabelle)
--   B  UNION als Semi-Join                              ≈ 160 ms  (schlechter, verworfen)
--   C  OR EXISTS korreliert                             ≈ 59 ms   (schlechter)
--   E  id IN (SELECT id FROM (… UNION ALL …) m)         ≈ 1,6 ms  (abgeleitete Tabelle wird materialisiert, beide Hälften über Index)
--   –  ohne Abo-Zweig (Referenz)                        ≈ 0,6 ms
-- Gleichheit A = E: 500 Zeilen, COUNT und XOR-Prüfsumme gleich (Q-GLEICH unten).
-- Die Pest-Suite (SQLite) prüft dieselbe Gleichheit je Team und Modell: tests/Feature/Spec84TeamSichtTest.php.

SET @mandant = 9100;

-- Q-A · heutige Form (Schalter aus)
EXPLAIN ANALYZE SELECT id, name FROM foodalchemist_recipes
WHERE (((team_id IS NULL OR team_id IN (9100)) AND ist_fassung = 0)
       OR id IN (SELECT objekt_id FROM foodalchemist_abo_objekte WHERE mandant_team_id = 9100 AND typ = 'recipe'))
  AND deleted_at IS NULL ORDER BY name LIMIT 50;

-- Q-E · neue Form (Schalter an), so wie Laravel sie erzeugt (InhaltsFreigabe::anwenden, Alias ts_innen / ts_menge)
EXPLAIN ANALYZE SELECT id, name FROM foodalchemist_recipes
WHERE (foodalchemist_recipes.id IN (SELECT ts_menge.id FROM (
          (SELECT ts_innen.id FROM foodalchemist_recipes AS ts_innen
            WHERE (ts_innen.team_id IS NULL OR ts_innen.team_id IN (9100)) AND ts_innen.ist_fassung = 0)
          UNION ALL
          (SELECT objekt_id FROM foodalchemist_abo_objekte WHERE mandant_team_id = 9100 AND typ = 'recipe')
        ) AS ts_menge))
  AND deleted_at IS NULL ORDER BY name LIMIT 50;

-- Q-GLEICH · beide Formen liefern dieselben Zeilen (Anzahl + Prüfsumme müssen übereinstimmen)
SELECT 'A' v, COUNT(*) n, BIT_XOR(CRC32(id)) pruef FROM foodalchemist_recipes
WHERE (((team_id IS NULL OR team_id IN (9100)) AND ist_fassung = 0)
       OR id IN (SELECT objekt_id FROM foodalchemist_abo_objekte WHERE mandant_team_id = 9100 AND typ = 'recipe'))
  AND deleted_at IS NULL
UNION ALL
SELECT 'E', COUNT(*), BIT_XOR(CRC32(id)) FROM foodalchemist_recipes
WHERE foodalchemist_recipes.id IN (SELECT ts_menge.id FROM (
          (SELECT ts_innen.id FROM foodalchemist_recipes AS ts_innen
            WHERE (ts_innen.team_id IS NULL OR ts_innen.team_id IN (9100)) AND ts_innen.ist_fassung = 0)
          UNION ALL
          (SELECT objekt_id FROM foodalchemist_abo_objekte WHERE mandant_team_id = 9100 AND typ = 'recipe')
        ) AS ts_menge)
  AND deleted_at IS NULL;

-- Q-UPDATE · MySQL-Falle 1093 („can't specify target table for update“): die Menge ist eine abgeleitete Tabelle und wird
-- materialisiert, ein UPDATE … WHERE id IN (Menge) auf dieselbe Tabelle ist deshalb erlaubt. In der KOPIE prüfen:
-- UPDATE foodalchemist_recipes SET updated_at = updated_at WHERE id IN (SELECT ts_menge.id FROM ((SELECT ts_innen.id FROM foodalchemist_recipes AS ts_innen WHERE ts_innen.team_id IN (9100)) UNION ALL (SELECT objekt_id FROM foodalchemist_abo_objekte WHERE mandant_team_id = 9100)) AS ts_menge);
