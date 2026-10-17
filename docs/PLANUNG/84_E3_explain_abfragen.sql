-- Spec 84 E-3 · EXPLAIN-Belege für den Sichtfilter (B3), VOR dem Bau. Rein lesend (EXPLAIN ohne ANALYZE).
-- Erzeugt aus FoodAlchemist*::visibleToTeam() (Stand e3-Branch, 17.10.), übersetzt nach MySQL.
-- io: Betrachter = Mandant Broich (team_id 3), Kette [3], Master = 1. Auf io gibt es noch keine Unter-Teams und keine Abos;
-- die Zweige „Freigabe-Hülle“ (77d) und „Abo“ (§12 A1) sind deshalb in Q6/Q7 mit Platzhalter-IDs nur formal prüfbar.

-- Q1 Rezepte (Inhalts-Typ, mit Fassungs-Regel)
EXPLAIN SELECT * FROM `foodalchemist_recipes`
WHERE ((`team_id` IS NULL OR `team_id` IN (3)) AND `ist_fassung` = 0)
  AND `deleted_at` IS NULL;

-- Q2 Konzepte
EXPLAIN SELECT * FROM `foodalchemist_concepts`
WHERE ((`team_id` IS NULL OR `team_id` IN (3)) AND `ist_fassung` = 0)
  AND `deleted_at` IS NULL;

-- Q3 Grundprodukte (Stamm-Klasse: global nur 'basis' bzw. mit Paket-Opt-in)
EXPLAIN SELECT * FROM `foodalchemist_gps`
WHERE ((`team_id` IS NULL AND `stamm_class` = 'basis') OR `team_id` IN (3))
  AND `deleted_at` IS NULL;

-- Q4 Lieferantenartikel (größte Stamm-Tabelle, ca. 354k)
EXPLAIN SELECT * FROM `foodalchemist_supplier_items`
WHERE ((`team_id` IS NULL AND `stamm_class` = 'basis') OR `team_id` IN (3))
  AND `deleted_at` IS NULL;

-- Q5 Angebote (seit O1 Inhalts-Typ)
EXPLAIN SELECT * FROM `foodalchemist_offers`
WHERE (`team_id` IS NULL OR `team_id` IN (3))
  AND `deleted_at` IS NULL;

-- Q6 Rezepte aus Sicht eines EINGESCHRÄNKTEN Unter-Teams (77d-Hülle) — Platzhalter: Unter-Team 9001 unter 3
EXPLAIN SELECT * FROM `foodalchemist_recipes`
WHERE ((`team_id` IS NULL OR `team_id` IN (9001)
        OR (`team_id` IN (3) AND `id` IN (SELECT `objekt_id` FROM `foodalchemist_freigabe_objekte` WHERE `team_id` = 9001 AND `typ` = 'recipe')))
       AND `ist_fassung` = 0)
  AND `deleted_at` IS NULL;

-- Q7 Rezepte mit Abo-Zweig (Master → Mandant 3)
EXPLAIN SELECT * FROM `foodalchemist_recipes`
WHERE (((`team_id` IS NULL OR `team_id` IN (3)) AND `ist_fassung` = 0)
       OR `id` IN (SELECT `objekt_id` FROM `foodalchemist_abo_objekte` WHERE `mandant_team_id` = 3 AND `typ` = 'recipe'))
  AND `deleted_at` IS NULL;

-- Q8 Zählung zur Einordnung der Selektivität (nur COUNT)
SELECT 'gps' t, COUNT(*) n, SUM(team_id IS NULL) global_, SUM(team_id = 3) broich FROM foodalchemist_gps WHERE deleted_at IS NULL
UNION ALL SELECT 'supplier_items', COUNT(*), SUM(team_id IS NULL), SUM(team_id = 3) FROM foodalchemist_supplier_items WHERE deleted_at IS NULL
UNION ALL SELECT 'recipes', COUNT(*), SUM(team_id IS NULL), SUM(team_id = 3) FROM foodalchemist_recipes WHERE deleted_at IS NULL;
