#!/usr/bin/env python3
"""
extract_inventory.py — Generiert docs/03_FEATURE_INVENTAR.md aus dem Rust-Backend
der Cooking-Jarvis-App (Quelle des Food-Alchemist-Rewrites).

Read-only auf die Rust-Quellen. Idempotent — bei jedem Lauf wird das Inventar
komplett neu geschrieben. 03_FEATURE_INVENTAR.md daher NIE von Hand editieren.

Aufruf:  python3 extract_inventory.py
Quellen: SRC_DIR (commands.rs, chat.rs, …)
Output:  ../03_FEATURE_INVENTAR.md + inventory.csv (daneben, für Tooling)
"""
from __future__ import annotations

import csv
import re
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

SRC_DIR = Path(
    "/Users/dbeutin/COOKING JARVIS/00_SYSTEM/00.07_App/cooking-jarvis/src-tauri/src"
)
DOCS_DIR = Path(__file__).resolve().parent.parent
OUT_MD = DOCS_DIR / "03_FEATURE_INVENTAR.md"
OUT_CSV = Path(__file__).resolve().parent / "inventory.csv"

CMD_MARKER = "#[tauri::command]"
FN_RE = re.compile(r"(?:pub\s+)?(?:async\s+)?fn\s+([a-z_0-9]+)\s*\(", re.S)
RET_RE = re.compile(r"\)\s*->\s*([^{]+?)\s*\{", re.S)
TABLE_RE = re.compile(
    r"\b(?:FROM|JOIN|INTO|UPDATE|DELETE\s+FROM)\s+([a-zA-Z_][a-zA-Z0-9_]*)",
    re.I,
)
WRITE_RE = re.compile(
    r"\b(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE\s+[a-zA-Z_]+\s+SET|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE)\b",
    re.I,
)
SQL_KEYWORD_NOISE = {"select", "where", "values", "set", "on", "as", "and", "or", "not"}

# ---------------------------------------------------------------- Domänen
# Reihenfolge = Prüf-Priorität (spezifisch vor generisch).
DOMAIN_RULES: list[tuple[str, str, re.Pattern, re.Pattern]] = [
    # (ID, Name, Command-Name-Pattern, Tabellen-Pattern)
    ("D-8", "Foodbook & Chat",
     re.compile(r"^(chat_|.*foodbook|.*kombination)"),
     re.compile(r"^(chat_|foodbook|kombination)")),
    ("D-7", "Pairing/Flavor-Graph",
     re.compile(r"(pairing|anker|cohesion|aroma_netz|bridge|flavor)"),
     re.compile(r"(pairing|anker)")),
    ("D-6", "Verkaufsrezepte",
     re.compile(r"(vk_|verkauf|speisen_klasse|speisen_hauptgruppe|marketing|wording|plating|servier|aufschlag|regen|behaelter|schreibstil|customer_name)"),
     re.compile(r"^(speisen_|schreibstile|vocab_serviervehikel|vocab_behaelter)")),
    ("D-2", "Lieferanten & LA",
     re.compile(r"(supplier|lieferant|^la_|_la$|_la_|price|preis|stamm_)"),
     re.compile(r"^(suppliers|supplier_items|supplier_priorities|prices|wawi_la_structured|stamm_lieferant|allergens|nutritional|declarations|v_active_prices|v_supplier)")),
    ("D-3", "Grundprodukte",
     re.compile(r"((^|_)gps?($|_)|grundprodukt|derivat|warengruppe)"),
     re.compile(r"^(wawi_gp_v2|lookup_warengruppe)")),
    ("D-4", "KI-Infrastruktur",
     re.compile(r"(layer|huelle|settings|api_key|gemini|ai_call_log|model)"),
     re.compile(r"^(ai_layer|ai_call_log|app_settings)")),
    ("D-1", "Vokabulare & Lookups",
     re.compile(r"(vocab|lookup|einheit|kategorie$|kategorien|_const$|kuechen_typ)"),
     re.compile(r"^(vocab_|lookup_|recipe_kategorien)")),
    ("D-5", "Basisrezepte",
     re.compile(r"(recipe|rezept|zutat|ingredient|sub_rezept|yield|equipment)"),
     re.compile(r"^(recipes|recipe_ingredients|recipe_equipment|recipe_tags|_staging)")),
]

# Explizite Einzelfall-Zuordnungen (schlagen alle Regeln; Quelle: E4-Domänen-Review)
COMMAND_OVERRIDES = {
    # Zielfeld bestimmt Service-Heimat: schreiben auf recipes → D-5
    "ai_classify_recipe_kategorie": "D-5",
    "accept_fertigungstiefe": "D-5",
    "ai_suggest_fertigungstiefe": "D-5",
    "ai_verteile_rollen": "D-5",
    "fill_template": "D-5",
    "detect_template_candidates": "D-5",
    # stk_default ist GP-Attribut → D-3
    "accept_stk_default_g": "D-3",
    "reject_stk_default_g": "D-3",
    "ai_suggest_stk_default": "D-3",
    # Komposition/Planung → D-8 (Foodbook-Composer-Welt)
    "ai_plan_dishes": "D-8",
    # Kohärenz/Graph → D-7
    "recipe_graph": "D-7",
    "compute_culinary_coherence": "D-7",
    "ai_judge_culinary": "D-7",
    # Lookup-Lister → D-1
    "list_produkttypen": "D-1",
}

# MVP-Ausnahmen (User-Entscheid 2026-06-11, Produktvision): rezept-gebundene Pairing-
# Features sind MVP, obwohl Domäne D-7 als Explorations-Domäne Phase 2 bleibt.
# Foodpairing+KI im Rezept-Workflow = Kern-Differenzierung (VK-Anlage-Automatisierung).
MVP_OVERRIDES = {
    # Rezept-Pairing-Lebenszyklus (Editor-Block + Enrich-Schritt)
    "ai_suggest_pairings", "accept_pairings", "reject_pairings",
    "get_recipe_pairings", "add_recipe_pairing", "remove_recipe_pairing",
    # Rezept-Kern-Anker-Lebenszyklus
    "ai_infer_recipe_ankers", "accept_recipe_ankers", "reject_recipe_ankers",
    "clear_recipe_ankers", "list_recipe_ankers", "set_recipe_anker", "remove_recipe_anker",
    # GP-Kern-Anker (Kontext-Qualität der Rezept-Inferenz hängt daran)
    "ai_infer_gp_ankers", "accept_gp_ankers", "reject_gp_ankers",
    "clear_gp_ankers", "list_gp_ankers", "set_gp_anker", "remove_gp_anker",
    # Kohäsion + Netz im Rezept-/VK-Detail + Grounding-Reads
    "recipe_cohesion", "recipe_graph", "compute_culinary_coherence",
    "list_pairing_anker", "pairing_anker_neighbors", "get_gp_pairing_info",
}

DOMAIN_SERVICE = {
    "D-1": "VocabularyService",
    "D-2": "SupplierService/PriceService/LaGpMatchService",
    "D-3": "GpService/GpAggregationService",
    "D-4": "AiGatewayService/SemanticLayerBridge/AiProposalService",
    "D-5": "RecipeService/RecipeRecomputeService/IngredientMatchService",
    "D-6": "SalesRecipeService/MargeService/SpeisenKlassenService",
    "D-7": "PairingService",
    "D-8": "FoodbookService/ChatService",
}
MVP_DOMAINS = {"D-1", "D-2", "D-3", "D-4", "D-5", "D-6"}  # ⚠D5 Arbeits-Annahme

# ---------------------------------------------------------------- GL-Heuristik
GL_RULES: list[tuple[str, re.Pattern]] = [
    ("GL-01", re.compile(r"allergen")),
    ("GL-02", re.compile(r"(recompute|ek_|cost|yield|kosten)")),
    ("GL-03", re.compile(r"lead_la")),
    ("GL-04", re.compile(r"(match_ingredient|match_single|recipe_matching|f1)")),
    ("GL-05", re.compile(r"(la_match|match_la|bulk_la|auto_match)")),
    ("GL-06", re.compile(r"layers::")),
    ("GL-07", re.compile(r"^(ai_|accept_|reject_|clear_)")),
    ("GL-08", re.compile(r"(nutri|naehrwert)")),
    ("GL-09", re.compile(r"zusatz")),
    ("GL-10", re.compile(r"(pairing|cohesion|anker|bridge)")),
    ("GL-11", re.compile(r"(price|preis)")),
    ("GL-12", re.compile(r"(naming|normalize_name|slug)")),
    ("GL-13", re.compile(r"vault_context::")),
]

# ---------------------------------------------------------------- Muster
KI_PREFIXES = ("ai_suggest_", "ai_classify_", "ai_generate_", "ai_infer_",
               "ai_normalize_", "ai_verteile_", "ai_", "accept_", "reject_", "clear_")
CRUD_PREFIXES = ("list_", "get_", "create_", "update_", "delete_", "set_",
                 "search_", "toggle_")


def stem_of(name: str) -> tuple[str, str]:
    """Liefert (muster, stamm) — KI-Lebenszyklus vor CRUD prüfen."""
    for p in KI_PREFIXES:
        if name.startswith(p):
            return ("KI-Lebenszyklus", name[len(p):] or name)
    for p in CRUD_PREFIXES:
        if name.startswith(p):
            return ("CRUD", name[len(p):] or name)
    return ("Spezial", name)


def classify_domain(name: str, tables: list[str]) -> tuple[str, str, bool]:
    """(domain_id, domain_name, needs_review) — Override > Name-Match > Tabellen-Match."""
    if name in COMMAND_OVERRIDES:
        did = COMMAND_OVERRIDES[name]
        return (did, next(n for d, n, _, _ in DOMAIN_RULES if d == did), False)
    hits: list[str] = []
    for did, dname, name_re, _ in DOMAIN_RULES:
        if name_re.search(name):
            hits.append(did)
    if not hits:
        for did, dname, _, table_re in DOMAIN_RULES:
            if any(table_re.match(t) for t in tables):
                hits.append(did)
    if not hits:
        return ("D-4", "KI-Infrastruktur", True)  # Querschnitt-Default, immer reviewen
    primary = hits[0]
    dname = next(n for d, n, _, _ in DOMAIN_RULES if d == primary)
    # review, wenn mehrere unterschiedliche Domänen plausibel sind
    return (primary, dname, len(set(hits)) > 1)


def parse_file(path: Path) -> list[dict]:
    text = path.read_text(encoding="utf-8")
    rows = []
    idx = [m.start() for m in re.finditer(re.escape(CMD_MARKER), text)]
    for i, start in enumerate(idx):
        end = idx[i + 1] if i + 1 < len(idx) else len(text)
        block = text[start:end]
        fn = FN_RE.search(block)
        if not fn:
            continue
        name = fn.group(1)
        # Parameter-Liste: von fn( bis schließender Klammer auf gleicher Tiefe
        pstart = fn.end()
        depth, j = 1, pstart
        while j < len(block) and depth:
            depth += {"(": 1, ")": -1}.get(block[j], 0)
            j += 1
        params = re.sub(r"\s+", " ", block[pstart:j - 1]).strip()
        params = re.sub(r"state\s*:\s*State<[^>]*>,?\s*", "", params).strip(", ")
        ret = RET_RE.search(block[j - 1:j + 400])
        ret_t = re.sub(r"\s+", " ", ret.group(1)).strip() if ret else "()"
        tables = sorted({
            t.lower() for t in TABLE_RE.findall(block)
            if t.lower() not in SQL_KEYWORD_NOISE and not t.lower().startswith("sqlite_")
        })
        writes = bool(WRITE_RE.search(block))
        ki = "gemini::" in block
        layers = "layers::" in block
        vault = "vault_context::" in block
        muster, stamm = stem_of(name)
        did, dname, review = classify_domain(name, tables)
        gl = sorted({g for g, rx in GL_RULES
                     if rx.search(name) or rx.search(block[:200] if g in ("GL-06", "GL-13") else name)
                     or (g == "GL-06" and layers) or (g == "GL-13" and vault)})
        rows.append({
            "command": name, "file": path.name, "params": params, "ret": ret_t,
            "domain_id": did, "domain": dname, "muster": muster, "stamm": stamm,
            "rw": "W" if writes else "R", "tables": tables,
            "ki": ki, "layers": layers, "vault": vault,
            "gl": gl, "review": review,
            "mvp": "MVP" if (did in MVP_DOMAINS or name in MVP_OVERRIDES) else "Phase 2",
        })
    return rows


def main() -> None:
    rows: list[dict] = []
    for f in sorted(SRC_DIR.glob("*.rs")):
        rows.extend(parse_file(f))
    rows.sort(key=lambda r: (r["domain_id"], r["stamm"], r["command"]))

    by_domain = Counter(r["domain_id"] for r in rows)
    by_muster = Counter(r["muster"] for r in rows)
    resources: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for r in rows:
        resources[(r["domain_id"], r["stamm"])].append(r)
    n_review = sum(1 for r in rows if r["review"])
    n_ki = sum(1 for r in rows if r["ki"])

    # ---------------- CSV
    with OUT_CSV.open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["command", "file", "domain_id", "domain", "muster", "stamm",
                    "rw", "tables", "ki", "layers", "vault", "gl_refs", "mvp",
                    "review", "params", "return"])
        for r in rows:
            w.writerow([r["command"], r["file"], r["domain_id"], r["domain"],
                        r["muster"], r["stamm"], r["rw"], ";".join(r["tables"]),
                        int(r["ki"]), int(r["layers"]), int(r["vault"]),
                        ";".join(r["gl"]), r["mvp"], int(r["review"]),
                        r["params"], r["ret"]])

    # ---------------- Markdown
    L: list[str] = []
    L.append("---")
    L.append("typ: Feature-Inventar (GENERIERT)")
    L.append(f"stand: {date.today().isoformat()}")
    L.append("quelle: cooking-jarvis src-tauri/src (Tauri-Commands)")
    L.append("generator: _tools/extract_inventory.py")
    L.append("---")
    L.append("")
    L.append("# 03 — Feature-Inventar (generiert)")
    L.append("")
    L.append("> ⚠️ **GENERIERTE DATEI — nicht von Hand editieren.** Regenerieren via "
             "`python3 docs/_tools/extract_inventory.py`. Manuelle Klassifikations-"
             "Korrekturen gehören in die Regeln des Skripts, nicht in diese Tabelle.")
    L.append("")
    L.append("## Kennzahlen")
    L.append("")
    L.append(f"- **{len(rows)} Tauri-Commands** insgesamt "
             f"({', '.join(f'{c}× {f}' for f, c in Counter(r['file'] for r in rows).most_common())})")
    L.append(f"- **{len(resources)} Ressourcen-Gruppen** (Muster-Kollabierung über Stamm)")
    L.append(f"- Muster: {', '.join(f'{m} = {c}' for m, c in by_muster.most_common())}")
    L.append(f"- {n_ki} Commands mit direktem Gemini-Call · "
             f"{sum(1 for r in rows if r['layers'])} mit Hüllen-Resolver · "
             f"{sum(1 for r in rows if r['vault'])} mit Vault-Wissenszugriff")
    L.append(f"- {n_review} Commands mit `review`-Flag (Domänen-Zuordnung manuell prüfen)")
    L.append("")
    L.append("## Verteilung nach Domäne")
    L.append("")
    L.append("| Domäne | Commands | MVP (⚠D5) | Ziel-Services |")
    L.append("|---|---|---|---|")
    for did in sorted(by_domain):
        dname = next(n for d, n, _, _ in DOMAIN_RULES if d == did)
        mvp = "MVP" if did in MVP_DOMAINS else "Phase 2"
        L.append(f"| {did} {dname} | {by_domain[did]} | {mvp} | `{DOMAIN_SERVICE[did]}` |")
    L.append("")
    L.append("## Ressourcen-Gruppen (kollabiert)")
    L.append("")
    L.append("Eine Zeile = ein zusammenhängendes Feature-Bündel (CRUD-Tupel, "
             "KI-Lebenszyklus-Quadrupel o. Spezial). Spec-Detail folgt in `05_DOMAENEN/`.")
    L.append("")
    L.append("| Domäne | Ressource/Stamm | Commands | Muster | KI | GL-Refs | MVP |")
    L.append("|---|---|---|---|---|---|---|")
    for (did, stamm), group in sorted(resources.items()):
        cmds = ", ".join(f"`{g['command']}`" for g in group)
        muster = "/".join(sorted({g["muster"] for g in group}))
        ki = "✨" if any(g["ki"] for g in group) else ""
        gl = ", ".join(sorted({x for g in group for x in g["gl"]})) or "—"
        mvp = group[0]["mvp"]
        L.append(f"| {did} | **{stamm}** | {cmds} | {muster} | {ki} | {gl} | {mvp} |")
    L.append("")
    L.append("## Voll-Inventar (1 Zeile = 1 Command)")
    L.append("")
    L.append("| Command | Datei | Domäne | Muster | R/W | Tabellen | KI | GL | MVP | Review |")
    L.append("|---|---|---|---|---|---|---|---|---|---|")
    for r in rows:
        tables = ", ".join(r["tables"][:6]) + ("…" if len(r["tables"]) > 6 else "")
        flags = ("✨" if r["ki"] else "") + ("🧅" if r["layers"] else "") + ("📚" if r["vault"] else "")
        L.append(
            f"| `{r['command']}` | {r['file']} | {r['domain_id']} | {r['muster']} "
            f"| {r['rw']} | {tables or '—'} | {flags or '—'} "
            f"| {', '.join(r['gl']) or '—'} | {r['mvp']} | {'⚠' if r['review'] else ''} |"
        )
    L.append("")
    L.append("Legende: ✨ = direkter Gemini-Call · 🧅 = Hüllen-Resolver (`layers::`) · "
             "📚 = Vault-Wissenskontext · ⚠ = Domänen-Zuordnung manuell prüfen")
    L.append("")
    OUT_MD.write_text("\n".join(L), encoding="utf-8")
    print(f"OK: {len(rows)} Commands → {OUT_MD.name} + {OUT_CSV.name}")
    print(f"    Domänen: {dict(sorted(by_domain.items()))}")
    print(f"    Review-Flags: {n_review}")


if __name__ == "__main__":
    main()
