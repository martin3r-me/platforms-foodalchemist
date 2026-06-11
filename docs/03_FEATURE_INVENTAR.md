---
typ: Feature-Inventar (GENERIERT)
stand: 2026-06-11
quelle: cooking-jarvis src-tauri/src (Tauri-Commands)
generator: _tools/extract_inventory.py
---

# 03 — Feature-Inventar (generiert)

> ⚠️ **GENERIERTE DATEI — nicht von Hand editieren.** Regenerieren via `python3 docs/_tools/extract_inventory.py`. Manuelle Klassifikations-Korrekturen gehören in die Regeln des Skripts, nicht in diese Tabelle.

## Kennzahlen

- **323 Tauri-Commands** insgesamt (318× commands.rs, 5× chat.rs)
- **224 Ressourcen-Gruppen** (Muster-Kollabierung über Stamm)
- Muster: CRUD = 167, KI-Lebenszyklus = 92, Spezial = 64
- 45 Commands mit direktem Gemini-Call · 40 mit Hüllen-Resolver · 6 mit Vault-Wissenszugriff
- 71 Commands mit `review`-Flag (Domänen-Zuordnung manuell prüfen)

## Verteilung nach Domäne

| Domäne | Commands | MVP (⚠D5) | Ziel-Services |
|---|---|---|---|
| D-1 Vokabulare & Lookups | 29 | MVP | `VocabularyService` |
| D-2 Lieferanten & LA | 46 | MVP | `SupplierService/PriceService/LaGpMatchService` |
| D-3 Grundprodukte | 52 | MVP | `GpService/GpAggregationService` |
| D-4 KI-Infrastruktur | 14 | MVP | `AiGatewayService/SemanticLayerBridge/AiProposalService` |
| D-5 Basisrezepte | 76 | MVP | `RecipeService/RecipeRecomputeService/IngredientMatchService` |
| D-6 Verkaufsrezepte | 49 | MVP | `SalesRecipeService/MargeService/SpeisenKlassenService` |
| D-7 Pairing/Flavor-Graph | 27 | Phase 2 | `PairingService` |
| D-8 Foodbook & Chat | 30 | Phase 2 | `FoodbookService/ChatService` |

## Ressourcen-Gruppen (kollabiert)

Eine Zeile = ein zusammenhängendes Feature-Bündel (CRUD-Tupel, KI-Lebenszyklus-Quadrupel o. Spezial). Spec-Detail folgt in `05_DOMAENEN/`.

| Domäne | Ressource/Stamm | Commands | Muster | KI | GL-Refs | MVP |
|---|---|---|---|---|---|---|
| D-1 | **countries** | `list_countries` | CRUD |  | — | MVP |
| D-1 | **distinct_sub_kategorien** | `list_distinct_sub_kategorien` | CRUD |  | — | MVP |
| D-1 | **einheiten** | `list_einheiten` | CRUD |  | — | MVP |
| D-1 | **einheiten_vpe_const** | `list_einheiten_vpe_const` | CRUD |  | — | MVP |
| D-1 | **food_domains** | `list_food_domains` | CRUD |  | — | MVP |
| D-1 | **formen_const** | `list_formen_const` | CRUD |  | — | MVP |
| D-1 | **kuechen_typ** | `get_kuechen_typ`, `set_kuechen_typ` | CRUD |  | — | MVP |
| D-1 | **kuechen_typen** | `list_kuechen_typen` | CRUD |  | — | MVP |
| D-1 | **languages** | `list_languages` | CRUD |  | — | MVP |
| D-1 | **merge_recipe_kategorien** | `merge_recipe_kategorien` | Spezial |  | — | MVP |
| D-1 | **niveaus** | `list_niveaus` | CRUD |  | — | MVP |
| D-1 | **produkttypen** | `list_produkttypen` | CRUD |  | GL-13 | MVP |
| D-1 | **recipe_kategorie** | `accept_recipe_kategorie`, `create_recipe_kategorie`, `delete_recipe_kategorie`, `update_recipe_kategorie` | CRUD/KI-Lebenszyklus |  | GL-07 | MVP |
| D-1 | **recipe_kategorien** | `list_recipe_kategorien` | CRUD |  | — | MVP |
| D-1 | **rename_sub_kategorie** | `rename_sub_kategorie` | Spezial |  | — | MVP |
| D-1 | **sektoren** | `list_sektoren` | CRUD |  | — | MVP |
| D-1 | **sub_kategorie** | `clear_sub_kategorie` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-1 | **sub_kategorien_overview** | `list_sub_kategorien_overview` | CRUD |  | — | MVP |
| D-1 | **verarbeitungen_const** | `list_verarbeitungen_const` | CRUD |  | — | MVP |
| D-1 | **vocab_kochequipment** | `create_vocab_kochequipment`, `delete_vocab_kochequipment`, `list_vocab_kochequipment`, `update_vocab_kochequipment` | CRUD |  | — | MVP |
| D-1 | **vocab_kochequipment_inactive** | `set_vocab_kochequipment_inactive` | CRUD |  | — | MVP |
| D-1 | **zustaende_const** | `list_zustaende_const` | CRUD |  | — | MVP |
| D-2 | **add_stamm_lieferant** | `add_stamm_lieferant` | Spezial |  | — | MVP |
| D-2 | **apply_lead_la** | `apply_lead_la` | Spezial |  | GL-03 | MVP |
| D-2 | **bulk_delete_la** | `bulk_delete_la` | Spezial |  | — | MVP |
| D-2 | **bulk_match_phantoms_via_matrix** | `ai_bulk_match_phantoms_via_matrix` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-2 | **bulk_match_unmapped_las** | `ai_bulk_match_unmapped_las` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-2 | **bulk_set_la_discontinued** | `bulk_set_la_discontinued` | Spezial |  | — | MVP |
| D-2 | **bulk_set_la_gp** | `bulk_set_la_gp` | Spezial |  | — | MVP |
| D-2 | **db_info** | `db_info` | Spezial |  | — | MVP |
| D-2 | **derive_stamm_lieferant_wg** | `derive_stamm_lieferant_wg` | Spezial |  | — | MVP |
| D-2 | **detect_price_anomalies** | `detect_price_anomalies` | Spezial |  | GL-11 | MVP |
| D-2 | **gp_la_suggestions** | `accept_gp_la_suggestions` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-2 | **gp_lead_la** | `set_gp_lead_la` | CRUD |  | GL-03 | MVP |
| D-2 | **la_allergens** | `get_la_allergens`, `set_la_allergens` | CRUD |  | GL-01 | MVP |
| D-2 | **la_declarations** | `get_la_declarations`, `set_la_declarations` | CRUD |  | — | MVP |
| D-2 | **la_gp_mapping** | `set_la_gp_mapping` | CRUD |  | — | MVP |
| D-2 | **la_gp_match** | `accept_la_gp_match`, `reject_la_gp_match` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-2 | **la_price** | `create_la_price`, `delete_la_price`, `update_la_price` | CRUD |  | GL-11 | MVP |
| D-2 | **la_prices** | `get_la_prices` | CRUD |  | GL-11 | MVP |
| D-2 | **match_la_to_gp** | `ai_match_la_to_gp` | KI-Lebenszyklus | ✨ | GL-05, GL-06, GL-07 | MVP |
| D-2 | **plausi_check_price** | `ai_plausi_check_price` | KI-Lebenszyklus | ✨ | GL-06, GL-07, GL-11 | MVP |
| D-2 | **rank_las_for_term** | `ai_rank_las_for_term` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-2 | **recompute_all_lead_las** | `recompute_all_lead_las` | Spezial |  | GL-02, GL-03 | MVP |
| D-2 | **remove_stamm_lieferant** | `remove_stamm_lieferant` | Spezial |  | — | MVP |
| D-2 | **stamm_lieferant_wg** | `set_stamm_lieferant_wg` | CRUD |  | — | MVP |
| D-2 | **stamm_lieferanten** | `list_stamm_lieferanten` | CRUD |  | — | MVP |
| D-2 | **supplier** | `create_supplier`, `get_supplier`, `update_supplier` | CRUD |  | — | MVP |
| D-2 | **supplier_inactive** | `set_supplier_inactive` | CRUD |  | — | MVP |
| D-2 | **supplier_item** | `create_supplier_item`, `delete_supplier_item`, `get_supplier_item`, `update_supplier_item` | CRUD |  | — | MVP |
| D-2 | **supplier_item_discontinued** | `set_supplier_item_discontinued` | CRUD |  | — | MVP |
| D-2 | **supplier_items** | `list_supplier_items` | CRUD |  | — | MVP |
| D-2 | **supplier_items_global** | `search_supplier_items_global` | CRUD |  | — | MVP |
| D-2 | **supplier_stamm** | `get_supplier_stamm` | CRUD |  | — | MVP |
| D-2 | **supplier_types** | `list_supplier_types` | CRUD |  | — | MVP |
| D-2 | **suppliers** | `list_suppliers` | CRUD |  | — | MVP |
| D-2 | **unlink_la_from_gp** | `unlink_la_from_gp` | Spezial |  | — | MVP |
| D-2 | **unmapped_las** | `list_unmapped_las` | CRUD |  | — | MVP |
| D-3 | **derivat_gp** | `create_derivat_gp` | CRUD |  | — | MVP |
| D-3 | **distinct_formen** | `list_distinct_formen` | CRUD |  | — | MVP |
| D-3 | **distinct_verarbeitungen** | `list_distinct_verarbeitungen` | CRUD |  | — | MVP |
| D-3 | **fertigungstiefe** | `ai_infer_fertigungstiefe` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **fill_template** | `ai_fill_template` | KI-Lebenszyklus | ✨ | GL-07 | MVP |
| D-3 | **geschmacksrichtung** | `ai_suggest_geschmacksrichtung` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **gp** | `ai_suggest_gp`, `create_gp`, `delete_gp`, `get_gp`, `update_gp` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **gp_aggregated_allergens** | `get_gp_aggregated_allergens` | CRUD |  | GL-01 | MVP |
| D-3 | **gp_aggregated_nutritional** | `get_gp_aggregated_nutritional` | CRUD |  | GL-08 | MVP |
| D-3 | **gp_aggregated_zusatzstoffe** | `get_gp_aggregated_zusatzstoffe` | CRUD |  | GL-09 | MVP |
| D-3 | **gp_allergens** | `accept_gp_allergens`, `ai_infer_gp_allergens`, `clear_gp_allergens`, `reject_gp_allergens` | KI-Lebenszyklus | ✨ | GL-01, GL-06, GL-07 | MVP |
| D-3 | **gp_count_unit** | `delete_gp_count_unit`, `set_gp_count_unit` | CRUD |  | — | MVP |
| D-3 | **gp_count_units** | `ai_suggest_gp_count_units`, `list_gp_count_units` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **gp_domain** | `accept_gp_domain`, `ai_infer_gp_domain`, `clear_gp_domain`, `reject_gp_domain` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **gp_linked_las** | `get_gp_linked_las` | CRUD |  | — | MVP |
| D-3 | **gp_status** | `set_gp_status` | CRUD |  | — | MVP |
| D-3 | **gp_suggestion** | `accept_gp_suggestion`, `reject_gp_suggestion` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-3 | **gp_tags** | `accept_gp_tags`, `ai_infer_gp_tags`, `clear_gp_tags`, `reject_gp_tags` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **gps** | `list_gps` | CRUD |  | — | MVP |
| D-3 | **gps_for_picker** | `search_gps_for_picker` | CRUD |  | — | MVP |
| D-3 | **instantiate_template** | `instantiate_template` | Spezial |  | — | MVP |
| D-3 | **las_for_gp** | `ai_suggest_las_for_gp` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **las_for_gp_link** | `search_las_for_gp_link` | CRUD |  | — | MVP |
| D-3 | **merge_gps** | `merge_gps` | Spezial |  | — | MVP |
| D-3 | **platzhalter_gp** | `create_platzhalter_gp`, `delete_platzhalter_gp` | CRUD |  | — | MVP |
| D-3 | **rename_platzhalter_gp** | `rename_platzhalter_gp` | Spezial |  | — | MVP |
| D-3 | **stk_default_g** | `accept_stk_default_g`, `ai_suggest_stk_default_g`, `clear_stk_default_g`, `reject_stk_default_g` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-3 | **stk_default_g_manual** | `set_stk_default_g_manual` | CRUD |  | — | MVP |
| D-3 | **suggest_sub_kategorie_normalisations** | `suggest_sub_kategorie_normalisations` | Spezial |  | — | MVP |
| D-3 | **templates** | `list_templates` | CRUD |  | — | MVP |
| D-3 | **warengruppen** | `list_warengruppen` | CRUD |  | — | MVP |
| D-3 | **warengruppen_const** | `list_warengruppen_const` | CRUD |  | — | MVP |
| D-4 | **active_ai_layer_version** | `set_active_ai_layer_version` | CRUD |  | — | MVP |
| D-4 | **ai_layer** | `create_ai_layer`, `delete_ai_layer`, `get_ai_layer` | CRUD |  | — | MVP |
| D-4 | **ai_layer_meta** | `update_ai_layer_meta` | CRUD |  | — | MVP |
| D-4 | **ai_layer_version** | `create_ai_layer_version` | CRUD |  | — | MVP |
| D-4 | **ai_layers** | `list_ai_layers` | CRUD |  | — | MVP |
| D-4 | **call_recency_stats** | `ai_call_recency_stats` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-4 | **dryrun_ai_layer** | `dryrun_ai_layer` | Spezial | ✨ | — | MVP |
| D-4 | **gemini_get_status** | `gemini_get_status` | Spezial | ✨ | — | MVP |
| D-4 | **gemini_set_api_key** | `gemini_set_api_key` | Spezial |  | — | MVP |
| D-4 | **gemini_set_model** | `gemini_set_model` | Spezial |  | — | MVP |
| D-4 | **gemini_test_connection** | `gemini_test_connection` | Spezial | ✨ | — | MVP |
| D-4 | **resolve_ai_layers** | `resolve_ai_layers` | Spezial |  | GL-06 | MVP |
| D-5 | **add_recipe_ingredient** | `add_recipe_ingredient` | Spezial |  | — | MVP |
| D-5 | **apply_recipe_review_change** | `apply_recipe_review_change` | Spezial |  | — | MVP |
| D-5 | **bulk_pending_recipes** | `list_bulk_pending_recipes` | CRUD |  | — | MVP |
| D-5 | **describe_recipe** | `ai_describe_recipe` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **duplicate_recipe** | `duplicate_recipe` | Spezial |  | — | MVP |
| D-5 | **equipment** | `ai_suggest_equipment` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **extract_recipe** | `ai_extract_recipe` | KI-Lebenszyklus | ✨ | GL-06, GL-07, GL-13 | MVP |
| D-5 | **fertigungstiefe** | `accept_fertigungstiefe` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-5 | **generator_stub** | `delete_generator_stub` | CRUD |  | — | MVP |
| D-5 | **ingredient_rolle** | `set_ingredient_rolle` | CRUD |  | — | MVP |
| D-5 | **inspect_subrecipe_link** | `inspect_subrecipe_link` | Spezial |  | — | MVP |
| D-5 | **match_single_ingredient** | `match_single_ingredient` | Spezial |  | GL-04 | MVP |
| D-5 | **recipe** | `ai_generate_recipe`, `create_recipe`, `delete_recipe`, `get_recipe`, `update_recipe` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07, GL-13 | MVP |
| D-5 | **recipe_component_suggestions** | `recipe_component_suggestions` | Spezial |  | — | MVP |
| D-5 | **recipe_culinary_coherence_compute** | `recipe_culinary_coherence_compute` | Spezial | ✨ | — | MVP |
| D-5 | **recipe_culinary_coherence_get** | `recipe_culinary_coherence_get` | Spezial |  | — | MVP |
| D-5 | **recipe_description** | `accept_recipe_description` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-5 | **recipe_eigenschaften** | `accept_recipe_eigenschaften`, `ai_suggest_recipe_eigenschaften` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **recipe_equipment** | `get_recipe_equipment`, `set_recipe_equipment` | CRUD |  | — | MVP |
| D-5 | **recipe_fertigungstiefe** | `get_recipe_fertigungstiefe`, `set_recipe_fertigungstiefe` | CRUD |  | — | MVP |
| D-5 | **recipe_garverlust** | `accept_recipe_garverlust`, `ai_infer_recipe_garverlust`, `reject_recipe_garverlust` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **recipe_hauptgruppe** | `create_recipe_hauptgruppe`, `delete_recipe_hauptgruppe`, `update_recipe_hauptgruppe` | CRUD |  | — | MVP |
| D-5 | **recipe_hauptgruppen** | `list_recipe_hauptgruppen` | CRUD |  | — | MVP |
| D-5 | **recipe_ingredient** | `delete_recipe_ingredient`, `update_recipe_ingredient` | CRUD |  | — | MVP |
| D-5 | **recipe_ingredients** | `get_recipe_ingredients` | CRUD |  | — | MVP |
| D-5 | **recipe_kategorie** | `ai_classify_recipe_kategorie` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **recipe_name** | `accept_recipe_name`, `ai_normalize_recipe_name` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **recipe_niveau** | `accept_recipe_niveau`, `ai_infer_recipe_niveau`, `clear_recipe_niveau`, `list_recipe_niveau`, `reject_recipe_niveau`, `set_recipe_niveau` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **recipe_parents** | `get_recipe_parents` | CRUD |  | — | MVP |
| D-5 | **recipe_plate_suggestion_compute** | `recipe_plate_suggestion_compute` | Spezial | ✨ | — | MVP |
| D-5 | **recipe_plate_suggestion_get** | `recipe_plate_suggestion_get` | Spezial |  | — | MVP |
| D-5 | **recipe_proposal** | `accept_recipe_proposal`, `reject_recipe_proposal` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-5 | **recipe_sektor** | `accept_recipe_sektor`, `ai_infer_recipe_sektor`, `clear_recipe_sektor`, `list_recipe_sektor`, `reject_recipe_sektor`, `set_recipe_sektor` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **recipe_status** | `set_recipe_status` | CRUD |  | — | MVP |
| D-5 | **recipe_subtree_depth** | `get_recipe_subtree_depth` | CRUD |  | — | MVP |
| D-5 | **recipe_template** | `set_recipe_template` | CRUD |  | — | MVP |
| D-5 | **recipe_zubereitung** | `accept_recipe_zubereitung` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-5 | **recipes** | `list_recipes` | CRUD |  | — | MVP |
| D-5 | **recipes_for_picker** | `search_recipes_for_picker` | CRUD |  | — | MVP |
| D-5 | **recompute_all_recipes** | `recompute_all_recipes` | Spezial |  | GL-02 | MVP |
| D-5 | **remove_recipe_niveau** | `remove_recipe_niveau` | Spezial |  | — | MVP |
| D-5 | **remove_recipe_sektor** | `remove_recipe_sektor` | Spezial |  | — | MVP |
| D-5 | **reorder_recipe_ingredients** | `reorder_recipe_ingredients` | Spezial |  | — | MVP |
| D-5 | **review_recipe** | `ai_review_recipe` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **revise_recipe** | `ai_revise_recipe` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-5 | **rollen** | `ai_verteile_rollen` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **sub_recipe_stub** | `create_sub_recipe_stub` | CRUD |  | — | MVP |
| D-5 | **sub_rezept_typ** | `accept_sub_rezept_typ`, `ai_infer_sub_rezept_typ`, `clear_sub_rezept_typ`, `reject_sub_rezept_typ` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-5 | **sub_rezept_typen** | `list_sub_rezept_typen` | CRUD |  | — | MVP |
| D-6 | **add_recipe_customer_name** | `add_recipe_customer_name` | Spezial |  | — | MVP |
| D-6 | **aufschlagsklasse** | `create_aufschlagsklasse`, `delete_aufschlagsklasse`, `update_aufschlagsklasse` | CRUD |  | — | MVP |
| D-6 | **aufschlagsklassen** | `list_aufschlagsklassen` | CRUD |  | — | MVP |
| D-6 | **behaelter** | `accept_behaelter`, `ai_suggest_behaelter` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-6 | **distinct_customer_names** | `list_distinct_customer_names` | CRUD |  | — | MVP |
| D-6 | **marketing** | `ai_generate_marketing` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-6 | **marketing_text** | `accept_marketing_text` | KI-Lebenszyklus |  | GL-07 | MVP |
| D-6 | **recipe_customer_name** | `delete_recipe_customer_name`, `update_recipe_customer_name` | CRUD |  | — | MVP |
| D-6 | **recipe_customer_names** | `list_recipe_customer_names` | CRUD |  | — | MVP |
| D-6 | **regeneration** | `accept_regeneration`, `ai_suggest_regeneration` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-6 | **schreibstil** | `create_schreibstil`, `delete_schreibstil`, `update_schreibstil` | CRUD |  | — | MVP |
| D-6 | **schreibstil_inactive** | `set_schreibstil_inactive` | CRUD |  | — | MVP |
| D-6 | **schreibstile** | `list_schreibstile` | CRUD |  | — | MVP |
| D-6 | **servier_vehikel** | `accept_servier_vehikel`, `ai_suggest_servier_vehikel` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-6 | **speisen_hauptgruppe** | `create_speisen_hauptgruppe`, `delete_speisen_hauptgruppe`, `update_speisen_hauptgruppe` | CRUD |  | — | MVP |
| D-6 | **speisen_hauptgruppen** | `list_speisen_hauptgruppen` | CRUD |  | — | MVP |
| D-6 | **speisen_klasse** | `accept_speisen_klasse`, `ai_classify_speisen_klasse`, `create_speisen_klasse`, `delete_speisen_klasse` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-6 | **speisen_klassen** | `list_speisen_klassen` | CRUD |  | — | MVP |
| D-6 | **vk_wording** | `accept_vk_wording`, `ai_suggest_vk_wording` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-6 | **vocab_behaelter** | `create_vocab_behaelter`, `delete_vocab_behaelter`, `list_vocab_behaelter`, `update_vocab_behaelter` | CRUD |  | — | MVP |
| D-6 | **vocab_behaelter_inactive** | `set_vocab_behaelter_inactive` | CRUD |  | — | MVP |
| D-6 | **vocab_regen_geraet** | `create_vocab_regen_geraet`, `delete_vocab_regen_geraet`, `list_vocab_regen_geraet`, `update_vocab_regen_geraet` | CRUD |  | — | MVP |
| D-6 | **vocab_regen_geraet_inactive** | `set_vocab_regen_geraet_inactive` | CRUD |  | — | MVP |
| D-6 | **vocab_serviervehikel** | `create_vocab_serviervehikel`, `delete_vocab_serviervehikel`, `list_vocab_serviervehikel`, `update_vocab_serviervehikel` | CRUD |  | — | MVP |
| D-6 | **vocab_serviervehikel_inactive** | `set_vocab_serviervehikel_inactive` | CRUD |  | — | MVP |
| D-6 | **zubereitung** | `ai_generate_zubereitung` | KI-Lebenszyklus | ✨ | GL-06, GL-07 | MVP |
| D-7 | **add_recipe_pairing** | `add_recipe_pairing` | Spezial |  | GL-10 | MVP |
| D-7 | **gp_anker** | `set_gp_anker` | CRUD |  | GL-10 | MVP |
| D-7 | **gp_ankers** | `accept_gp_ankers`, `ai_infer_gp_ankers`, `clear_gp_ankers`, `list_gp_ankers`, `reject_gp_ankers` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07, GL-10, GL-13 | MVP |
| D-7 | **gp_pairing_info** | `get_gp_pairing_info` | CRUD |  | GL-10 | MVP |
| D-7 | **pairing_anker** | `list_pairing_anker` | CRUD |  | GL-10 | MVP |
| D-7 | **pairing_anker_neighbors** | `pairing_anker_neighbors` | Spezial |  | GL-10 | MVP |
| D-7 | **pairing_bridge** | `pairing_bridge` | Spezial |  | GL-10 | Phase 2 |
| D-7 | **pairings** | `accept_pairings`, `ai_suggest_pairings`, `reject_pairings` | KI-Lebenszyklus | ✨ | GL-06, GL-07, GL-10, GL-13 | MVP |
| D-7 | **recipe_anker** | `set_recipe_anker` | CRUD |  | GL-10 | MVP |
| D-7 | **recipe_ankers** | `accept_recipe_ankers`, `ai_infer_recipe_ankers`, `clear_recipe_ankers`, `list_recipe_ankers`, `reject_recipe_ankers` | CRUD/KI-Lebenszyklus | ✨ | GL-06, GL-07, GL-10 | MVP |
| D-7 | **recipe_cohesion** | `recipe_cohesion` | Spezial |  | GL-10 | MVP |
| D-7 | **recipe_graph** | `recipe_graph` | Spezial |  | — | MVP |
| D-7 | **recipe_pairings** | `get_recipe_pairings` | CRUD |  | GL-10 | MVP |
| D-7 | **recipes_sharing_pairings** | `recipes_sharing_pairings` | Spezial |  | GL-10 | Phase 2 |
| D-7 | **remove_gp_anker** | `remove_gp_anker` | Spezial |  | GL-10 | MVP |
| D-7 | **remove_recipe_anker** | `remove_recipe_anker` | Spezial |  | GL-10 | MVP |
| D-7 | **remove_recipe_pairing** | `remove_recipe_pairing` | Spezial |  | GL-10 | MVP |
| D-8 | **chat_create_conversation** | `chat_create_conversation` | Spezial |  | — | Phase 2 |
| D-8 | **chat_delete_conversation** | `chat_delete_conversation` | Spezial |  | — | Phase 2 |
| D-8 | **chat_list_conversations** | `chat_list_conversations` | Spezial |  | — | Phase 2 |
| D-8 | **chat_list_messages** | `chat_list_messages` | Spezial |  | — | Phase 2 |
| D-8 | **chat_send** | `chat_send` | Spezial | ✨ | GL-06 | Phase 2 |
| D-8 | **foodbook** | `delete_foodbook` | CRUD |  | — | Phase 2 |
| D-8 | **foodbook_block** | `delete_foodbook_block` | CRUD |  | — | Phase 2 |
| D-8 | **foodbook_blocks** | `list_foodbook_blocks` | CRUD |  | — | Phase 2 |
| D-8 | **foodbook_blocks_variant_group** | `set_foodbook_blocks_variant_group` | CRUD |  | — | Phase 2 |
| D-8 | **foodbook_kapitel** | `delete_foodbook_kapitel` | CRUD |  | — | Phase 2 |
| D-8 | **foodbook_kapitel_aggregat** | `foodbook_kapitel_aggregat` | Spezial |  | — | Phase 2 |
| D-8 | **foodbook_tree** | `list_foodbook_tree` | CRUD |  | — | Phase 2 |
| D-8 | **foodbooks** | `list_foodbooks` | CRUD |  | — | Phase 2 |
| D-8 | **kombination** | `delete_kombination` | CRUD |  | — | Phase 2 |
| D-8 | **kombination_block** | `delete_kombination_block` | CRUD |  | — | Phase 2 |
| D-8 | **kombination_blocks** | `list_kombination_blocks` | CRUD |  | — | Phase 2 |
| D-8 | **kombination_blocks_variant_group** | `set_kombination_blocks_variant_group` | CRUD |  | — | Phase 2 |
| D-8 | **kombinationen** | `list_kombinationen` | CRUD |  | — | Phase 2 |
| D-8 | **move_foodbook_kapitel** | `move_foodbook_kapitel` | Spezial |  | — | Phase 2 |
| D-8 | **next_foodbook_variant_group_id** | `next_foodbook_variant_group_id` | Spezial |  | — | Phase 2 |
| D-8 | **next_kombination_variant_group_id** | `next_kombination_variant_group_id` | Spezial |  | — | Phase 2 |
| D-8 | **plan_dishes** | `ai_plan_dishes` | KI-Lebenszyklus | ✨ | GL-06, GL-07, GL-13 | Phase 2 |
| D-8 | **reorder_foodbook_blocks** | `reorder_foodbook_blocks` | Spezial |  | — | Phase 2 |
| D-8 | **reorder_foodbook_kapitel** | `reorder_foodbook_kapitel` | Spezial |  | — | Phase 2 |
| D-8 | **reorder_kombination_blocks** | `reorder_kombination_blocks` | Spezial |  | — | Phase 2 |
| D-8 | **upsert_foodbook** | `upsert_foodbook` | Spezial |  | — | Phase 2 |
| D-8 | **upsert_foodbook_block** | `upsert_foodbook_block` | Spezial |  | — | Phase 2 |
| D-8 | **upsert_foodbook_kapitel** | `upsert_foodbook_kapitel` | Spezial |  | — | Phase 2 |
| D-8 | **upsert_kombination** | `upsert_kombination` | Spezial |  | — | Phase 2 |
| D-8 | **upsert_kombination_block** | `upsert_kombination_block` | Spezial |  | — | Phase 2 |

## Voll-Inventar (1 Zeile = 1 Command)

| Command | Datei | Domäne | Muster | R/W | Tabellen | KI | GL | MVP | Review |
|---|---|---|---|---|---|---|---|---|---|
| `list_countries` | commands.rs | D-1 | CRUD | R | lookup_country | — | — | MVP |  |
| `list_distinct_sub_kategorien` | commands.rs | D-1 | CRUD | R | lookup_produkttyp, wawi_gp_v2 | — | — | MVP |  |
| `list_einheiten` | commands.rs | D-1 | CRUD | W | allergens, calc, ing_base, ing_costs, ing_nutri, ing_resolved… | — | — | MVP |  |
| `list_einheiten_vpe_const` | commands.rs | D-1 | CRUD | R | — | — | — | MVP |  |
| `list_food_domains` | commands.rs | D-1 | CRUD | R | vocab_food_domain | — | — | MVP |  |
| `list_formen_const` | commands.rs | D-1 | CRUD | R | — | — | — | MVP |  |
| `get_kuechen_typ` | commands.rs | D-1 | CRUD | R | — | — | — | MVP |  |
| `set_kuechen_typ` | commands.rs | D-1 | CRUD | R | vocab_kuechen_typ | — | — | MVP |  |
| `list_kuechen_typen` | commands.rs | D-1 | CRUD | R | vocab_kuechen_typ | — | — | MVP |  |
| `list_languages` | commands.rs | D-1 | CRUD | R | lookup_language | — | — | MVP |  |
| `merge_recipe_kategorien` | commands.rs | D-1 | Spezial | W | recipe_kategorien, recipes | — | — | MVP | ⚠ |
| `list_niveaus` | commands.rs | D-1 | CRUD | R | vocab_niveau | — | — | MVP |  |
| `list_produkttypen` | commands.rs | D-1 | CRUD | R | lookup_recipe_typ, pairing_anker_edges, recipe_hauptgruppen, recipe_kategorien, recipes, vocab_pairing_anker | 📚 | GL-13 | MVP |  |
| `accept_recipe_kategorie` | commands.rs | D-1 | KI-Lebenszyklus | W | recipe_hauptgruppen, recipe_kategorien, recipes | — | GL-07 | MVP | ⚠ |
| `create_recipe_kategorie` | commands.rs | D-1 | CRUD | W | recipe_kategorien | — | — | MVP | ⚠ |
| `delete_recipe_kategorie` | commands.rs | D-1 | CRUD | W | recipe_kategorien, recipes | — | — | MVP | ⚠ |
| `update_recipe_kategorie` | commands.rs | D-1 | CRUD | W | recipe_kategorien | — | — | MVP | ⚠ |
| `list_recipe_kategorien` | commands.rs | D-1 | CRUD | R | recipe_hauptgruppen, recipe_kategorien, recipes | — | — | MVP | ⚠ |
| `rename_sub_kategorie` | commands.rs | D-1 | Spezial | R | wawi_gp_v2 | — | — | MVP |  |
| `list_sektoren` | commands.rs | D-1 | CRUD | R | vocab_sektor | — | — | MVP |  |
| `clear_sub_kategorie` | commands.rs | D-1 | KI-Lebenszyklus | R | wawi_gp_v2 | — | GL-07 | MVP |  |
| `list_sub_kategorien_overview` | commands.rs | D-1 | CRUD | R | wawi_gp_v2 | — | — | MVP |  |
| `list_verarbeitungen_const` | commands.rs | D-1 | CRUD | R | — | — | — | MVP |  |
| `create_vocab_kochequipment` | commands.rs | D-1 | CRUD | W | vocab_kochequipment | — | — | MVP | ⚠ |
| `delete_vocab_kochequipment` | commands.rs | D-1 | CRUD | W | recipe_equipment, vocab_kochequipment | — | — | MVP | ⚠ |
| `list_vocab_kochequipment` | commands.rs | D-1 | CRUD | R | vocab_kochequipment | — | — | MVP | ⚠ |
| `update_vocab_kochequipment` | commands.rs | D-1 | CRUD | W | vocab_kochequipment | — | — | MVP | ⚠ |
| `set_vocab_kochequipment_inactive` | commands.rs | D-1 | CRUD | W | vocab_kochequipment | — | — | MVP | ⚠ |
| `list_zustaende_const` | commands.rs | D-1 | CRUD | R | — | — | — | MVP |  |
| `add_stamm_lieferant` | commands.rs | D-2 | Spezial | R | stamm_lieferant | — | — | MVP |  |
| `apply_lead_la` | commands.rs | D-2 | Spezial | R | wawi_gp_v2 | — | GL-03 | MVP |  |
| `bulk_delete_la` | commands.rs | D-2 | Spezial | W | allergens, declarations, nutritional, prices, supplier_items, wawi_la_structured | — | — | MVP |  |
| `ai_bulk_match_phantoms_via_matrix` | commands.rs | D-2 | KI-Lebenszyklus | W | ai_call_log, allergens, lookup_unit, stamm_lieferant_wg, supplier_items, suppliers… | ✨🧅 | GL-06, GL-07 | MVP | ⚠ |
| `ai_bulk_match_unmapped_las` | commands.rs | D-2 | KI-Lebenszyklus | W | ai_call_log, supplier_items, suppliers, wawi_gp_v2, wawi_la_structured | ✨🧅 | GL-06, GL-07 | MVP | ⚠ |
| `bulk_set_la_discontinued` | commands.rs | D-2 | Spezial | W | supplier_items | — | — | MVP |  |
| `bulk_set_la_gp` | commands.rs | D-2 | Spezial | W | n_las_total, wawi_gp_v2, wawi_la_structured | — | — | MVP | ⚠ |
| `db_info` | commands.rs | D-2 | Spezial | R | recipes, suppliers, wawi_gp_v2, wawi_la_structured | — | — | MVP | ⚠ |
| `derive_stamm_lieferant_wg` | commands.rs | D-2 | Spezial | R | stamm_lieferant_wg, supplier_items, wawi_gp_v2, wawi_la_structured | — | — | MVP |  |
| `detect_price_anomalies` | commands.rs | D-2 | Spezial | R | lookup_unit, supplier_items, suppliers, v_active_prices, wawi_gp_v2, wawi_la_structured | — | GL-11 | MVP |  |
| `accept_gp_la_suggestions` | commands.rs | D-2 | KI-Lebenszyklus | W | ai_call_log, wawi_gp_v2, wawi_la_structured | — | GL-07 | MVP | ⚠ |
| `set_gp_lead_la` | commands.rs | D-2 | CRUD | R | stamm_lieferant_wg, supplier_items, suppliers, v_active_prices, wawi_gp_v2, wawi_la_structured | — | GL-03 | MVP | ⚠ |
| `get_la_allergens` | commands.rs | D-2 | CRUD | R | allergens | — | GL-01 | MVP |  |
| `set_la_allergens` | commands.rs | D-2 | CRUD | W | allergens, wawi_la_structured | — | GL-01 | MVP |  |
| `get_la_declarations` | commands.rs | D-2 | CRUD | R | declarations | — | — | MVP |  |
| `set_la_declarations` | commands.rs | D-2 | CRUD | W | declarations | — | — | MVP |  |
| `set_la_gp_mapping` | commands.rs | D-2 | CRUD | W | wawi_gp_v2, wawi_la_structured | — | — | MVP | ⚠ |
| `accept_la_gp_match` | commands.rs | D-2 | KI-Lebenszyklus | W | ai_call_log, wawi_gp_v2, wawi_la_structured | — | GL-07 | MVP | ⚠ |
| `reject_la_gp_match` | commands.rs | D-2 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP | ⚠ |
| `create_la_price` | commands.rs | D-2 | CRUD | W | prices | — | GL-11 | MVP |  |
| `delete_la_price` | commands.rs | D-2 | CRUD | W | prices | — | GL-11 | MVP |  |
| `update_la_price` | commands.rs | D-2 | CRUD | W | prices | — | GL-11 | MVP |  |
| `get_la_prices` | commands.rs | D-2 | CRUD | R | prices | — | GL-11 | MVP |  |
| `ai_match_la_to_gp` | commands.rs | D-2 | KI-Lebenszyklus | R | supplier_items, suppliers | ✨🧅 | GL-05, GL-06, GL-07 | MVP | ⚠ |
| `ai_plausi_check_price` | commands.rs | D-2 | KI-Lebenszyklus | W | embedding, lookup_unit, recipe_niveau_eignung, recipes, scratch, supplier_items… | ✨🧅 | GL-06, GL-07, GL-11 | MVP |  |
| `ai_rank_las_for_term` | commands.rs | D-2 | KI-Lebenszyklus | R | lookup_unit, stamm_lieferant, supplier_items, suppliers, v_active_prices, wawi_la_structured | ✨🧅 | GL-06, GL-07 | MVP | ⚠ |
| `recompute_all_lead_las` | commands.rs | D-2 | Spezial | R | wawi_gp_v2, wawi_la_structured | — | GL-02, GL-03 | MVP | ⚠ |
| `remove_stamm_lieferant` | commands.rs | D-2 | Spezial | W | stamm_lieferant, stamm_lieferant_wg | — | — | MVP |  |
| `set_stamm_lieferant_wg` | commands.rs | D-2 | CRUD | W | stamm_lieferant_wg | — | — | MVP |  |
| `list_stamm_lieferanten` | commands.rs | D-2 | CRUD | R | lookup_supplier_type, stamm_lieferant, stamm_lieferant_wg, supplier_items, suppliers, wawi_la_structured | — | — | MVP |  |
| `create_supplier` | commands.rs | D-2 | CRUD | W | suppliers | — | — | MVP |  |
| `get_supplier` | commands.rs | D-2 | CRUD | R | lookup_country, lookup_supplier_type, suppliers | — | — | MVP |  |
| `update_supplier` | commands.rs | D-2 | CRUD | W | suppliers | — | — | MVP |  |
| `set_supplier_inactive` | commands.rs | D-2 | CRUD | W | suppliers | — | — | MVP |  |
| `create_supplier_item` | commands.rs | D-2 | CRUD | W | supplier_items | — | — | MVP |  |
| `delete_supplier_item` | commands.rs | D-2 | CRUD | W | allergens, declarations, nutritional, prices, supplier_items, wawi_la_structured | — | — | MVP |  |
| `get_supplier_item` | commands.rs | D-2 | CRUD | R | lookup_unit, supplier_items, suppliers, v_active_prices, wawi_gp_v2, wawi_la_structured | — | — | MVP |  |
| `update_supplier_item` | commands.rs | D-2 | CRUD | W | supplier_items | — | — | MVP |  |
| `set_supplier_item_discontinued` | commands.rs | D-2 | CRUD | W | supplier_items | — | — | MVP |  |
| `list_supplier_items` | commands.rs | D-2 | CRUD | R | lookup_unit, supplier_items, v_active_prices, wawi_gp_v2, wawi_la_structured | — | — | MVP |  |
| `search_supplier_items_global` | commands.rs | D-2 | CRUD | R | lookup_unit, stamm_lieferant, supplier_items, suppliers, v_active_prices, wawi_gp_v2… | — | — | MVP |  |
| `get_supplier_stamm` | commands.rs | D-2 | CRUD | R | stamm_lieferant, stamm_lieferant_wg | — | — | MVP |  |
| `list_supplier_types` | commands.rs | D-2 | CRUD | R | lookup_supplier_type | — | — | MVP |  |
| `list_suppliers` | commands.rs | D-2 | CRUD | R | lookup_supplier_type, supplier_items, suppliers, wawi_la_structured | — | — | MVP |  |
| `unlink_la_from_gp` | commands.rs | D-2 | Spezial | W | wawi_gp_v2, wawi_la_structured | — | — | MVP | ⚠ |
| `list_unmapped_las` | commands.rs | D-2 | CRUD | R | supplier_items, suppliers, wawi_la_structured | — | — | MVP |  |
| `create_derivat_gp` | commands.rs | D-3 | CRUD | W | wawi_gp_v2 | — | — | MVP |  |
| `list_distinct_formen` | commands.rs | D-3 | CRUD | R | wawi_gp_v2 | — | — | MVP |  |
| `list_distinct_verarbeitungen` | commands.rs | D-3 | CRUD | R | wawi_gp_v2 | — | — | MVP |  |
| `ai_infer_fertigungstiefe` | commands.rs | D-3 | KI-Lebenszyklus | R | recipe_ingredients, recipes, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP | ⚠ |
| `ai_fill_template` | commands.rs | D-3 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_einheit, wawi_gp_v2 | ✨ | GL-07 | MVP | ⚠ |
| `ai_suggest_geschmacksrichtung` | commands.rs | D-3 | KI-Lebenszyklus | W | recipe_hauptgruppen, recipe_ingredients, recipe_kategorien, recipes, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP | ⚠ |
| `ai_suggest_gp` | commands.rs | D-3 | KI-Lebenszyklus | R | — | ✨🧅 | GL-06, GL-07 | MVP |  |
| `create_gp` | commands.rs | D-3 | CRUD | W | wawi_gp_v2 | — | — | MVP |  |
| `delete_gp` | commands.rs | D-3 | CRUD | W | recipe_ingredients, wawi_gp_v2, wawi_la_structured | — | — | MVP |  |
| `get_gp` | commands.rs | D-3 | CRUD | R | suppliers, vocab_einheit, vocab_food_domain, wawi_gp_secondary_food_domains, wawi_gp_v2 | — | — | MVP |  |
| `update_gp` | commands.rs | D-3 | CRUD | R | recipe_ingredients, wawi_gp_v2 | — | — | MVP |  |
| `get_gp_aggregated_allergens` | commands.rs | D-3 | CRUD | R | allergens, wawi_gp_v2, wawi_la_structured | — | GL-01 | MVP |  |
| `get_gp_aggregated_nutritional` | commands.rs | D-3 | CRUD | R | nutritional, wawi_la_structured | — | GL-08 | MVP |  |
| `get_gp_aggregated_zusatzstoffe` | commands.rs | D-3 | CRUD | R | declarations, wawi_la_structured | — | GL-09 | MVP |  |
| `accept_gp_allergens` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log, recipe_ingredients, wawi_gp_v2 | — | GL-01, GL-07 | MVP |  |
| `ai_infer_gp_allergens` | commands.rs | D-3 | KI-Lebenszyklus | R | allergens, wawi_gp_v2, wawi_la_structured | ✨🧅 | GL-01, GL-06, GL-07 | MVP |  |
| `clear_gp_allergens` | commands.rs | D-3 | KI-Lebenszyklus | R | lookup_country, recipe_ingredients, supplier_items, wawi_gp_v2, wawi_la_structured | — | GL-01, GL-07 | MVP |  |
| `reject_gp_allergens` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log | — | GL-01, GL-07 | MVP |  |
| `delete_gp_count_unit` | commands.rs | D-3 | CRUD | W | wawi_gp_count_unit_defaults | — | — | MVP |  |
| `set_gp_count_unit` | commands.rs | D-3 | CRUD | W | wawi_gp_count_unit_defaults, wawi_gp_v2 | — | — | MVP |  |
| `ai_suggest_gp_count_units` | commands.rs | D-3 | KI-Lebenszyklus | R | vocab_einheit, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `list_gp_count_units` | commands.rs | D-3 | CRUD | R | recipe_ingredients, vocab_einheit, wawi_gp_count_unit_defaults | — | — | MVP |  |
| `accept_gp_domain` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log, vocab_food_domain, wawi_gp_secondary_food_domains, wawi_gp_v2 | — | GL-07 | MVP |  |
| `ai_infer_gp_domain` | commands.rs | D-3 | KI-Lebenszyklus | R | vocab_food_domain, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `clear_gp_domain` | commands.rs | D-3 | KI-Lebenszyklus | W | wawi_gp_secondary_food_domains, wawi_gp_v2 | — | GL-07 | MVP |  |
| `reject_gp_domain` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP |  |
| `get_gp_linked_las` | commands.rs | D-3 | CRUD | R | lookup_unit, supplier_items, suppliers, v_active_prices, wawi_gp_v2, wawi_la_structured | — | — | MVP |  |
| `set_gp_status` | commands.rs | D-3 | CRUD | R | wawi_gp_v2 | — | — | MVP |  |
| `accept_gp_suggestion` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log, wawi_gp_v2, wawi_la_structured | — | GL-07 | MVP |  |
| `reject_gp_suggestion` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log, allergens, supplier_items, wawi_la_structured | — | GL-07 | MVP |  |
| `accept_gp_tags` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log, wawi_gp_v2 | — | GL-07 | MVP |  |
| `ai_infer_gp_tags` | commands.rs | D-3 | KI-Lebenszyklus | R | wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `clear_gp_tags` | commands.rs | D-3 | KI-Lebenszyklus | R | wawi_gp_v2 | — | GL-07 | MVP |  |
| `reject_gp_tags` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP |  |
| `list_gps` | commands.rs | D-3 | CRUD | R | allergens, lookup_unit, recipe_ingredients, supplier_items, v_active_prices, wawi_gp_v2… | — | — | MVP |  |
| `search_gps_for_picker` | commands.rs | D-3 | CRUD | R | wawi_gp_v2 | — | — | MVP |  |
| `instantiate_template` | commands.rs | D-3 | Spezial | W | recipe_ingredients, recipes, wawi_gp_v2 | — | — | MVP | ⚠ |
| `ai_suggest_las_for_gp` | commands.rs | D-3 | KI-Lebenszyklus | R | lookup_unit, stamm_lieferant_wg, supplier_items, suppliers, v_active_prices, wawi_gp_v2… | ✨🧅 | GL-06, GL-07 | MVP |  |
| `search_las_for_gp_link` | commands.rs | D-3 | CRUD | R | lookup_unit, supplier_items, suppliers, v_active_prices, wawi_gp_v2, wawi_la_structured | — | — | MVP |  |
| `merge_gps` | commands.rs | D-3 | Spezial | W | recipe_ingredients, wawi_gp_v2, wawi_la_structured | — | — | MVP |  |
| `create_platzhalter_gp` | commands.rs | D-3 | CRUD | W | wawi_gp_v2 | — | — | MVP |  |
| `delete_platzhalter_gp` | commands.rs | D-3 | CRUD | W | embedding, recipe_ingredients, wawi_gp_v2 | — | — | MVP |  |
| `rename_platzhalter_gp` | commands.rs | D-3 | Spezial | R | wawi_gp_v2 | — | — | MVP |  |
| `accept_stk_default_g` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log, vocab_einheit, wawi_gp_v2 | — | GL-07 | MVP |  |
| `ai_suggest_stk_default_g` | commands.rs | D-3 | KI-Lebenszyklus | R | vocab_einheit, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP | ⚠ |
| `clear_stk_default_g` | commands.rs | D-3 | KI-Lebenszyklus | R | wawi_gp_v2 | — | GL-07 | MVP |  |
| `reject_stk_default_g` | commands.rs | D-3 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP |  |
| `set_stk_default_g_manual` | commands.rs | D-3 | CRUD | R | wawi_gp_v2 | — | — | MVP |  |
| `suggest_sub_kategorie_normalisations` | commands.rs | D-3 | Spezial | R | wawi_gp_v2 | — | — | MVP |  |
| `list_templates` | commands.rs | D-3 | CRUD | R | recipe_ingredients, recipes, vocab_einheit, vocab_sub_rezept_typ, wawi_gp_v2 | — | — | MVP | ⚠ |
| `list_warengruppen` | commands.rs | D-3 | CRUD | R | wawi_gp_v2 | — | — | MVP |  |
| `list_warengruppen_const` | commands.rs | D-3 | CRUD | R | — | — | — | MVP | ⚠ |
| `set_active_ai_layer_version` | commands.rs | D-4 | CRUD | W | ai_layer, ai_layer_version | — | — | MVP |  |
| `create_ai_layer` | commands.rs | D-4 | CRUD | W | ai_layer, ai_layer_version | — | — | MVP |  |
| `delete_ai_layer` | commands.rs | D-4 | CRUD | W | ai_layer | — | — | MVP |  |
| `get_ai_layer` | commands.rs | D-4 | CRUD | R | ai_layer | — | — | MVP |  |
| `update_ai_layer_meta` | commands.rs | D-4 | CRUD | W | ai_layer | — | — | MVP |  |
| `create_ai_layer_version` | commands.rs | D-4 | CRUD | W | ai_layer, ai_layer_version | — | — | MVP |  |
| `list_ai_layers` | commands.rs | D-4 | CRUD | R | ai_layer, ai_layer_version | — | — | MVP |  |
| `ai_call_recency_stats` | commands.rs | D-4 | KI-Lebenszyklus | R | ai_call_log | — | GL-07 | MVP |  |
| `dryrun_ai_layer` | commands.rs | D-4 | Spezial | W | ai_call_log, ai_layer, ai_layer_version, wawi_gp_v2 | ✨ | — | MVP |  |
| `gemini_get_status` | commands.rs | D-4 | Spezial | R | — | ✨ | — | MVP |  |
| `gemini_set_api_key` | commands.rs | D-4 | Spezial | R | — | — | — | MVP |  |
| `gemini_set_model` | commands.rs | D-4 | Spezial | R | — | — | — | MVP |  |
| `gemini_test_connection` | commands.rs | D-4 | Spezial | R | — | ✨ | — | MVP |  |
| `resolve_ai_layers` | commands.rs | D-4 | Spezial | R | — | 🧅 | GL-06 | MVP |  |
| `add_recipe_ingredient` | commands.rs | D-5 | Spezial | W | recipe_ingredients | — | — | MVP |  |
| `apply_recipe_review_change` | commands.rs | D-5 | Spezial | W | recipe_ingredients | — | — | MVP |  |
| `list_bulk_pending_recipes` | commands.rs | D-5 | CRUD | R | recipes | — | — | MVP |  |
| `ai_describe_recipe` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `duplicate_recipe` | commands.rs | D-5 | Spezial | W | recipe_ingredients, recipes | — | — | MVP |  |
| `ai_suggest_equipment` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_einheit, vocab_kochequipment, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `ai_extract_recipe` | commands.rs | D-5 | KI-Lebenszyklus | R | — | ✨🧅📚 | GL-06, GL-07, GL-13 | MVP |  |
| `accept_fertigungstiefe` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipes | — | GL-07 | MVP |  |
| `delete_generator_stub` | commands.rs | D-5 | CRUD | W | recipe_ingredients, recipes | — | — | MVP |  |
| `set_ingredient_rolle` | commands.rs | D-5 | CRUD | W | recipe_ingredients | — | — | MVP |  |
| `inspect_subrecipe_link` | commands.rs | D-5 | Spezial | R | — | — | — | MVP |  |
| `match_single_ingredient` | commands.rs | D-5 | Spezial | R | — | — | GL-04 | MVP |  |
| `ai_generate_recipe` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, wawi_gp_v2 | ✨🧅📚 | GL-06, GL-07, GL-13 | MVP |  |
| `create_recipe` | commands.rs | D-5 | CRUD | W | recipe_kategorien, recipes | — | — | MVP |  |
| `delete_recipe` | commands.rs | D-5 | CRUD | W | recipe_ingredients, recipes | — | — | MVP |  |
| `get_recipe` | commands.rs | D-5 | CRUD | R | aufschlagsklassen, recipe_hauptgruppen, recipe_kategorien, recipes, speisen_klassen, vocab_behaelter… | — | — | MVP |  |
| `update_recipe` | commands.rs | D-5 | CRUD | W | recipes | — | — | MVP |  |
| `recipe_component_suggestions` | commands.rs | D-5 | Spezial | R | pairing_anker_edges | — | — | MVP |  |
| `recipe_culinary_coherence_compute` | commands.rs | D-5 | Spezial | W | recipe_culinary_coherence | ✨ | — | MVP |  |
| `recipe_culinary_coherence_get` | commands.rs | D-5 | Spezial | R | recipe_culinary_coherence | — | — | MVP |  |
| `accept_recipe_description` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipes | — | GL-07 | MVP |  |
| `accept_recipe_eigenschaften` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipes | — | GL-07 | MVP |  |
| `ai_suggest_recipe_eigenschaften` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `get_recipe_equipment` | commands.rs | D-5 | CRUD | R | recipe_equipment, vocab_kochequipment | — | — | MVP |  |
| `set_recipe_equipment` | commands.rs | D-5 | CRUD | W | recipe_equipment | — | — | MVP |  |
| `get_recipe_fertigungstiefe` | commands.rs | D-5 | CRUD | R | recipes | — | — | MVP |  |
| `set_recipe_fertigungstiefe` | commands.rs | D-5 | CRUD | W | recipes | — | — | MVP |  |
| `accept_recipe_garverlust` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipe_ingredients | — | GL-07 | MVP |  |
| `ai_infer_recipe_garverlust` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_einheit, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `reject_recipe_garverlust` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP |  |
| `create_recipe_hauptgruppe` | commands.rs | D-5 | CRUD | W | recipe_hauptgruppen | — | — | MVP |  |
| `delete_recipe_hauptgruppe` | commands.rs | D-5 | CRUD | W | recipe_hauptgruppen, recipe_kategorien | — | — | MVP |  |
| `update_recipe_hauptgruppe` | commands.rs | D-5 | CRUD | W | recipe_hauptgruppen | — | — | MVP |  |
| `list_recipe_hauptgruppen` | commands.rs | D-5 | CRUD | R | recipe_hauptgruppen, recipe_kategorien, recipes | — | — | MVP |  |
| `delete_recipe_ingredient` | commands.rs | D-5 | CRUD | W | recipe_ingredients | — | — | MVP |  |
| `update_recipe_ingredient` | commands.rs | D-5 | CRUD | W | recipe_ingredients | — | — | MVP |  |
| `get_recipe_ingredients` | commands.rs | D-5 | CRUD | R | lookup_unit, prices, recipe_ingredients, recipes, supplier_items, vocab_einheit… | — | — | MVP |  |
| `ai_classify_recipe_kategorie` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_hauptgruppen, recipe_ingredients, recipe_kategorien, recipes, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `accept_recipe_name` | commands.rs | D-5 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `ai_normalize_recipe_name` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_hauptgruppen, recipe_ingredients, recipe_kategorien, recipes, speisen_hauptgruppen, speisen_klassen… | ✨🧅 | GL-06, GL-07 | MVP |  |
| `accept_recipe_niveau` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipe_niveau_eignung | — | GL-07 | MVP |  |
| `ai_infer_recipe_niveau` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_niveau, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `clear_recipe_niveau` | commands.rs | D-5 | KI-Lebenszyklus | W | recipe_niveau_eignung | — | GL-07 | MVP |  |
| `list_recipe_niveau` | commands.rs | D-5 | CRUD | R | recipe_niveau_eignung, vocab_niveau | — | — | MVP |  |
| `reject_recipe_niveau` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP |  |
| `set_recipe_niveau` | commands.rs | D-5 | CRUD | W | recipe_niveau_eignung, vocab_niveau | — | — | MVP |  |
| `get_recipe_parents` | commands.rs | D-5 | CRUD | R | recipe_ingredients, recipes | — | — | MVP |  |
| `recipe_plate_suggestion_compute` | commands.rs | D-5 | Spezial | W | recipe_plate_suggestion | ✨ | — | MVP |  |
| `recipe_plate_suggestion_get` | commands.rs | D-5 | Spezial | R | recipe_plate_suggestion | — | — | MVP |  |
| `accept_recipe_proposal` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipe_ingredients, recipes, vocab_einheit | — | GL-07 | MVP |  |
| `reject_recipe_proposal` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipe_ingredients, recipes | — | GL-07 | MVP |  |
| `accept_recipe_sektor` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipe_sektor_eignung | — | GL-07 | MVP |  |
| `ai_infer_recipe_sektor` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_sektor, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `clear_recipe_sektor` | commands.rs | D-5 | KI-Lebenszyklus | W | recipe_sektor_eignung | — | GL-07 | MVP |  |
| `list_recipe_sektor` | commands.rs | D-5 | CRUD | R | recipe_sektor_eignung, vocab_sektor | — | — | MVP |  |
| `reject_recipe_sektor` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP |  |
| `set_recipe_sektor` | commands.rs | D-5 | CRUD | W | recipe_sektor_eignung, vocab_sektor | — | — | MVP |  |
| `set_recipe_status` | commands.rs | D-5 | CRUD | W | recipes | — | — | MVP |  |
| `get_recipe_subtree_depth` | commands.rs | D-5 | CRUD | R | — | — | — | MVP |  |
| `set_recipe_template` | commands.rs | D-5 | CRUD | W | recipes | — | — | MVP |  |
| `accept_recipe_zubereitung` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipes | — | GL-07 | MVP |  |
| `list_recipes` | commands.rs | D-5 | CRUD | R | recipe_hauptgruppen, recipe_ingredients, recipe_kategorien, recipes, vocab_sub_rezept_typ | — | — | MVP |  |
| `search_recipes_for_picker` | commands.rs | D-5 | CRUD | W | app_settings, recipe_kategorien, recipes | — | — | MVP |  |
| `recompute_all_recipes` | commands.rs | D-5 | Spezial | W | declarations, ing_resolved, recipe_ingredients, recipes, wawi_la_structured | — | GL-02 | MVP |  |
| `remove_recipe_niveau` | commands.rs | D-5 | Spezial | W | recipe_niveau_eignung | — | — | MVP |  |
| `remove_recipe_sektor` | commands.rs | D-5 | Spezial | W | recipe_sektor_eignung | — | — | MVP |  |
| `reorder_recipe_ingredients` | commands.rs | D-5 | Spezial | W | recipe_ingredients, recipes | — | — | MVP |  |
| `ai_review_recipe` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, speisen_klassen, vocab_behaelter, vocab_einheit, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `ai_revise_recipe` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_einheit, wawi_gp_v2 | — | GL-07 | MVP |  |
| `ai_verteile_rollen` | commands.rs | D-5 | KI-Lebenszyklus | W | recipe_ingredients, recipes, vocab_einheit, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `create_sub_recipe_stub` | commands.rs | D-5 | CRUD | W | recipes, vocab_sub_rezept_typ | — | — | MVP |  |
| `accept_sub_rezept_typ` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log, recipes, vocab_sub_rezept_typ | — | GL-07 | MVP |  |
| `ai_infer_sub_rezept_typ` | commands.rs | D-5 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_sub_rezept_typ, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `clear_sub_rezept_typ` | commands.rs | D-5 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `reject_sub_rezept_typ` | commands.rs | D-5 | KI-Lebenszyklus | W | ai_call_log | — | GL-07 | MVP |  |
| `list_sub_rezept_typen` | commands.rs | D-5 | CRUD | R | vocab_sub_rezept_typ | — | — | MVP |  |
| `add_recipe_customer_name` | commands.rs | D-6 | Spezial | W | recipe_customer_names | — | — | MVP | ⚠ |
| `create_aufschlagsklasse` | commands.rs | D-6 | CRUD | W | aufschlagsklassen | — | — | MVP |  |
| `delete_aufschlagsklasse` | commands.rs | D-6 | CRUD | W | aufschlagsklassen | — | — | MVP |  |
| `update_aufschlagsklasse` | commands.rs | D-6 | CRUD | W | aufschlagsklassen | — | — | MVP |  |
| `list_aufschlagsklassen` | commands.rs | D-6 | CRUD | R | aufschlagsklassen | — | — | MVP |  |
| `accept_behaelter` | commands.rs | D-6 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `ai_suggest_behaelter` | commands.rs | D-6 | KI-Lebenszyklus | R | recipe_ingredients, recipes, speisen_klassen, vocab_behaelter, vocab_einheit, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `list_distinct_customer_names` | commands.rs | D-6 | CRUD | R | recipe_customer_names | — | — | MVP |  |
| `ai_generate_marketing` | commands.rs | D-6 | KI-Lebenszyklus | R | recipe_ingredients, recipes, schreibstile, speisen_klassen, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `accept_marketing_text` | commands.rs | D-6 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `delete_recipe_customer_name` | commands.rs | D-6 | CRUD | W | recipe_customer_names | — | — | MVP | ⚠ |
| `update_recipe_customer_name` | commands.rs | D-6 | CRUD | W | recipe_customer_names | — | — | MVP | ⚠ |
| `list_recipe_customer_names` | commands.rs | D-6 | CRUD | R | recipe_customer_names | — | — | MVP | ⚠ |
| `accept_regeneration` | commands.rs | D-6 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `ai_suggest_regeneration` | commands.rs | D-6 | KI-Lebenszyklus | R | recipe_ingredients, recipes, speisen_klassen, vocab_einheit, vocab_regen_geraet, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `create_schreibstil` | commands.rs | D-6 | CRUD | W | schreibstile | — | — | MVP |  |
| `delete_schreibstil` | commands.rs | D-6 | CRUD | W | schreibstile | — | — | MVP |  |
| `update_schreibstil` | commands.rs | D-6 | CRUD | W | schreibstile | — | — | MVP |  |
| `set_schreibstil_inactive` | commands.rs | D-6 | CRUD | W | schreibstile | — | — | MVP |  |
| `list_schreibstile` | commands.rs | D-6 | CRUD | R | schreibstile | — | — | MVP |  |
| `accept_servier_vehikel` | commands.rs | D-6 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `ai_suggest_servier_vehikel` | commands.rs | D-6 | KI-Lebenszyklus | R | recipe_ingredients, recipes, speisen_klassen, vocab_serviervehikel, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `create_speisen_hauptgruppe` | commands.rs | D-6 | CRUD | W | speisen_hauptgruppen | — | — | MVP |  |
| `delete_speisen_hauptgruppe` | commands.rs | D-6 | CRUD | W | speisen_hauptgruppen | — | — | MVP |  |
| `update_speisen_hauptgruppe` | commands.rs | D-6 | CRUD | W | speisen_hauptgruppen | — | — | MVP |  |
| `list_speisen_hauptgruppen` | commands.rs | D-6 | CRUD | R | speisen_hauptgruppen, speisen_klassen | — | — | MVP |  |
| `accept_speisen_klasse` | commands.rs | D-6 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `ai_classify_speisen_klasse` | commands.rs | D-6 | KI-Lebenszyklus | R | recipe_ingredients, recipes, speisen_hauptgruppen, speisen_klassen, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `create_speisen_klasse` | commands.rs | D-6 | CRUD | W | speisen_klassen | — | — | MVP |  |
| `delete_speisen_klasse` | commands.rs | D-6 | CRUD | W | speisen_klassen | — | — | MVP |  |
| `list_speisen_klassen` | commands.rs | D-6 | CRUD | R | recipes, speisen_hauptgruppen, speisen_klassen | — | — | MVP |  |
| `accept_vk_wording` | commands.rs | D-6 | KI-Lebenszyklus | W | recipes | — | GL-07 | MVP |  |
| `ai_suggest_vk_wording` | commands.rs | D-6 | KI-Lebenszyklus | R | recipe_ingredients, recipes, schreibstile, speisen_klassen, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP |  |
| `create_vocab_behaelter` | commands.rs | D-6 | CRUD | W | vocab_behaelter | — | — | MVP | ⚠ |
| `delete_vocab_behaelter` | commands.rs | D-6 | CRUD | W | recipes, vocab_behaelter | — | — | MVP | ⚠ |
| `list_vocab_behaelter` | commands.rs | D-6 | CRUD | R | vocab_behaelter | — | — | MVP | ⚠ |
| `update_vocab_behaelter` | commands.rs | D-6 | CRUD | W | vocab_behaelter | — | — | MVP | ⚠ |
| `set_vocab_behaelter_inactive` | commands.rs | D-6 | CRUD | W | vocab_behaelter | — | — | MVP | ⚠ |
| `create_vocab_regen_geraet` | commands.rs | D-6 | CRUD | W | vocab_regen_geraet | — | — | MVP | ⚠ |
| `delete_vocab_regen_geraet` | commands.rs | D-6 | CRUD | W | recipes, vocab_regen_geraet | — | — | MVP | ⚠ |
| `list_vocab_regen_geraet` | commands.rs | D-6 | CRUD | R | vocab_regen_geraet | — | — | MVP | ⚠ |
| `update_vocab_regen_geraet` | commands.rs | D-6 | CRUD | W | vocab_regen_geraet | — | — | MVP | ⚠ |
| `set_vocab_regen_geraet_inactive` | commands.rs | D-6 | CRUD | W | vocab_regen_geraet | — | — | MVP | ⚠ |
| `create_vocab_serviervehikel` | commands.rs | D-6 | CRUD | W | vocab_serviervehikel | — | — | MVP | ⚠ |
| `delete_vocab_serviervehikel` | commands.rs | D-6 | CRUD | W | recipes, vocab_serviervehikel | — | — | MVP | ⚠ |
| `list_vocab_serviervehikel` | commands.rs | D-6 | CRUD | R | vocab_serviervehikel | — | — | MVP | ⚠ |
| `update_vocab_serviervehikel` | commands.rs | D-6 | CRUD | W | vocab_serviervehikel | — | — | MVP | ⚠ |
| `set_vocab_serviervehikel_inactive` | commands.rs | D-6 | CRUD | W | vocab_serviervehikel | — | — | MVP | ⚠ |
| `ai_generate_zubereitung` | commands.rs | D-6 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_behaelter, vocab_einheit, vocab_regen_geraet, wawi_gp_v2 | ✨🧅 | GL-06, GL-07 | MVP | ⚠ |
| `add_recipe_pairing` | commands.rs | D-7 | Spezial | R | recipe_pairings | — | GL-10 | MVP | ⚠ |
| `set_gp_anker` | commands.rs | D-7 | CRUD | W | einer, gp_anker_mapping | — | GL-10 | MVP | ⚠ |
| `accept_gp_ankers` | commands.rs | D-7 | KI-Lebenszyklus | W | ai_call_log, gp_anker_mapping | — | GL-07, GL-10 | MVP | ⚠ |
| `ai_infer_gp_ankers` | commands.rs | D-7 | KI-Lebenszyklus | R | vocab_pairing_anker, wawi_gp_v2 | ✨🧅📚 | GL-06, GL-07, GL-10, GL-13 | MVP | ⚠ |
| `clear_gp_ankers` | commands.rs | D-7 | KI-Lebenszyklus | W | gp_anker_mapping | — | GL-07, GL-10 | MVP | ⚠ |
| `list_gp_ankers` | commands.rs | D-7 | CRUD | R | gp_anker_mapping, vocab_pairing_anker | — | GL-10 | MVP | ⚠ |
| `reject_gp_ankers` | commands.rs | D-7 | KI-Lebenszyklus | W | ai_call_log | — | GL-07, GL-10 | MVP | ⚠ |
| `get_gp_pairing_info` | commands.rs | D-7 | CRUD | R | vocab_pairing_anker, wawi_gp_v2 | — | GL-10 | MVP | ⚠ |
| `list_pairing_anker` | commands.rs | D-7 | CRUD | R | vocab_pairing_anker | — | GL-10 | MVP |  |
| `pairing_anker_neighbors` | commands.rs | D-7 | Spezial | R | pairing_anker_edges, vocab_pairing_anker | — | GL-10 | MVP |  |
| `pairing_bridge` | commands.rs | D-7 | Spezial | R | pairing_anker_edges, recipe_pairings, vocab_pairing_anker | — | GL-10 | Phase 2 |  |
| `accept_pairings` | commands.rs | D-7 | KI-Lebenszyklus | W | ai_call_log, recipe_pairings | — | GL-07, GL-10 | MVP |  |
| `ai_suggest_pairings` | commands.rs | D-7 | KI-Lebenszyklus | R | recipe_ingredients, recipes, vocab_pairing_anker, wawi_gp_v2 | ✨🧅📚 | GL-06, GL-07, GL-10, GL-13 | MVP |  |
| `reject_pairings` | commands.rs | D-7 | KI-Lebenszyklus | W | ai_call_log | — | GL-07, GL-10 | MVP |  |
| `set_recipe_anker` | commands.rs | D-7 | CRUD | W | recipe_anker_mapping, z | — | GL-10 | MVP | ⚠ |
| `accept_recipe_ankers` | commands.rs | D-7 | KI-Lebenszyklus | W | ai_call_log, recipe_anker_mapping | — | GL-07, GL-10 | MVP | ⚠ |
| `ai_infer_recipe_ankers` | commands.rs | D-7 | KI-Lebenszyklus | R | gp_anker_mapping, recipe_anker_mapping, recipe_ingredients, recipes, vocab_pairing_anker | ✨🧅 | GL-06, GL-07, GL-10 | MVP | ⚠ |
| `clear_recipe_ankers` | commands.rs | D-7 | KI-Lebenszyklus | W | recipe_anker_mapping | — | GL-07, GL-10 | MVP | ⚠ |
| `list_recipe_ankers` | commands.rs | D-7 | CRUD | R | recipe_anker_mapping, vocab_pairing_anker | — | GL-10 | MVP | ⚠ |
| `reject_recipe_ankers` | commands.rs | D-7 | KI-Lebenszyklus | W | ai_call_log | — | GL-07, GL-10 | MVP | ⚠ |
| `recipe_cohesion` | commands.rs | D-7 | Spezial | R | pairing_anker_edges, recipe_ingredients, recipes, wawi_gp_v2 | — | GL-10 | MVP | ⚠ |
| `recipe_graph` | commands.rs | D-7 | Spezial | R | pairing_anker_edges, recipe_pairings, recipes, src, vocab_pairing_anker | — | — | MVP |  |
| `get_recipe_pairings` | commands.rs | D-7 | CRUD | R | recipe_pairings, vocab_pairing_anker | — | GL-10 | MVP | ⚠ |
| `recipes_sharing_pairings` | commands.rs | D-7 | Spezial | R | gp_anker_mapping, recipe_anker_mapping, recipe_ingredients, recipe_pairings, recipe_prozess_anker, recipes… | — | GL-10 | Phase 2 | ⚠ |
| `remove_gp_anker` | commands.rs | D-7 | Spezial | W | gp_anker_mapping | — | GL-10 | MVP | ⚠ |
| `remove_recipe_anker` | commands.rs | D-7 | Spezial | W | recipe_anker_mapping | — | GL-10 | MVP | ⚠ |
| `remove_recipe_pairing` | commands.rs | D-7 | Spezial | W | recipe_pairings | — | GL-10 | MVP | ⚠ |
| `chat_create_conversation` | chat.rs | D-8 | Spezial | W | chat_conversations | — | — | Phase 2 |  |
| `chat_delete_conversation` | chat.rs | D-8 | Spezial | W | chat_conversations | — | — | Phase 2 |  |
| `chat_list_conversations` | chat.rs | D-8 | Spezial | R | chat_conversations, chat_messages | — | — | Phase 2 |  |
| `chat_list_messages` | chat.rs | D-8 | Spezial | R | chat_messages | — | — | Phase 2 |  |
| `chat_send` | chat.rs | D-8 | Spezial | W | chat_conversations, chat_messages | ✨🧅 | GL-06 | Phase 2 |  |
| `delete_foodbook` | commands.rs | D-8 | CRUD | W | foodbook | — | — | Phase 2 |  |
| `delete_foodbook_block` | commands.rs | D-8 | CRUD | W | foodbook_block | — | — | Phase 2 |  |
| `list_foodbook_blocks` | commands.rs | D-8 | CRUD | R | foodbook_block, foodbook_block_staffel, kombination, recipes | — | — | Phase 2 |  |
| `set_foodbook_blocks_variant_group` | commands.rs | D-8 | CRUD | W | foodbook_block | — | — | Phase 2 |  |
| `delete_foodbook_kapitel` | commands.rs | D-8 | CRUD | W | foodbook_kapitel | — | — | Phase 2 |  |
| `foodbook_kapitel_aggregat` | commands.rs | D-8 | Spezial | R | foodbook_block, foodbook_kapitel, recipes, subtree | — | — | Phase 2 |  |
| `list_foodbook_tree` | commands.rs | D-8 | CRUD | R | foodbook_block, foodbook_kapitel, speisen_klassen | — | — | Phase 2 |  |
| `list_foodbooks` | commands.rs | D-8 | CRUD | R | foodbook, foodbook_kapitel | — | — | Phase 2 |  |
| `delete_kombination` | commands.rs | D-8 | CRUD | W | kombination | — | — | Phase 2 |  |
| `delete_kombination_block` | commands.rs | D-8 | CRUD | W | kombination_block | — | — | Phase 2 |  |
| `list_kombination_blocks` | commands.rs | D-8 | CRUD | R | kombination_block, kombination_block_staffel, recipes | — | — | Phase 2 |  |
| `set_kombination_blocks_variant_group` | commands.rs | D-8 | CRUD | W | kombination_block | — | — | Phase 2 |  |
| `list_kombinationen` | commands.rs | D-8 | CRUD | R | kombination, kombination_block, speisen_klassen | — | — | Phase 2 |  |
| `move_foodbook_kapitel` | commands.rs | D-8 | Spezial | W | foodbook_kapitel | — | — | Phase 2 |  |
| `next_foodbook_variant_group_id` | commands.rs | D-8 | Spezial | R | foodbook_block, kombination_block, recipes | — | — | Phase 2 |  |
| `next_kombination_variant_group_id` | commands.rs | D-8 | Spezial | R | kombination_block | — | — | Phase 2 |  |
| `ai_plan_dishes` | commands.rs | D-8 | KI-Lebenszyklus | R | — | ✨🧅📚 | GL-06, GL-07, GL-13 | Phase 2 |  |
| `reorder_foodbook_blocks` | commands.rs | D-8 | Spezial | W | foodbook_block | — | — | Phase 2 |  |
| `reorder_foodbook_kapitel` | commands.rs | D-8 | Spezial | W | foodbook_kapitel | — | — | Phase 2 |  |
| `reorder_kombination_blocks` | commands.rs | D-8 | Spezial | W | kombination_block | — | — | Phase 2 |  |
| `upsert_foodbook` | commands.rs | D-8 | Spezial | W | foodbook | — | — | Phase 2 |  |
| `upsert_foodbook_block` | commands.rs | D-8 | Spezial | W | foodbook_block, foodbook_block_staffel | — | — | Phase 2 |  |
| `upsert_foodbook_kapitel` | commands.rs | D-8 | Spezial | W | foodbook_kapitel | — | — | Phase 2 |  |
| `upsert_kombination` | commands.rs | D-8 | Spezial | W | kombination | — | — | Phase 2 |  |
| `upsert_kombination_block` | commands.rs | D-8 | Spezial | W | kombination_block, kombination_block_staffel | — | — | Phase 2 |  |

Legende: ✨ = direkter Gemini-Call · 🧅 = Hüllen-Resolver (`layers::`) · 📚 = Vault-Wissenskontext · ⚠ = Domänen-Zuordnung manuell prüfen
