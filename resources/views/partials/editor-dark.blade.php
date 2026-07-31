{{--
    Spec 28 / E0.3: der dunkle Editor-Grund. Herausgelöst aus `components/modal.blade.php`
    (dort wuchs er mitten im Markup) — eingebunden nur bei `darkCanvas`.

    WARUM rohes, gescopetes CSS und keine `dark:`-Utilities:
    Die Plattform-Shell hat keinen Dark Mode (README §159) und der FA ist seit `977a301`
    bewusst `dark:`-frei — sonst zerschießt OS-Dark den hellen Content. Der Editor ist also
    eine Insel: alles hängt an `.fa-editor-panel`, helle Kontexte (Settings, kleine Dialoge)
    bleiben unberührt.

    WARTUNGSREGEL (wichtig): das hier ist eine `!important`-Kaskade, die die Hell-Utilities
    überschreibt. Jede NEUE Fläche in einem Editor braucht hier einen Selektor — sonst steht
    graue Schrift auf grauem Grund. Neue Regeln immer MIT Begründung, sonst ist der Block in
    einem halben Jahr nicht mehr anfassbar. Reihenfolge: Grund → Trennlinien → Schrift →
    Flächen → Eingaben → Sonderfälle.
--}}
<style>
    .fa-editor-panel{ background:linear-gradient(to bottom,#1e293b,#0f172a) !important; border-color:rgba(255,255,255,.08) !important; color:#e2e8f0; }
    .fa-editor-panel [data-modal-zone="body"]{ background:transparent !important; }
    .fa-editor-panel [data-modal-zone="section"]{ background:rgba(255,255,255,.05) !important; border-color:rgba(255,255,255,.10) !important; box-shadow:none !important; }
    /* Trennlinien */
    .fa-editor-panel .border-b, .fa-editor-panel .border-t, .fa-editor-panel [class*="border-black/5"]{ border-color:rgba(255,255,255,.09) !important; }
    /* Schrift aufhellen */
    .fa-editor-panel .text-gray-900{ color:#f1f5f9 !important; }
    .fa-editor-panel .text-gray-800, .fa-editor-panel .text-gray-700{ color:#e2e8f0 !important; }
    .fa-editor-panel .text-gray-600{ color:#cbd5e1 !important; }
    .fa-editor-panel .text-gray-500, .fa-editor-panel .text-gray-400{ color:#94a3b8 !important; }
    /* Flächen: Ghost-Buttons / KPI-Kacheln / frosted Tiles */
    .fa-editor-panel .bg-white\/60, .fa-editor-panel .bg-white\/70, .fa-editor-panel .bg-white\/90, .fa-editor-panel [data-fa-kpis] > div, .fa-editor-panel [data-editor-kpis] > div, .fa-editor-panel [data-vk-editor-kpis] > div{ background:rgba(255,255,255,.06) !important; border-color:rgba(255,255,255,.10) !important; }
    /* Sticky-Tableiste */
    .fa-editor-panel .sticky{ background:rgba(15,23,42,.92) !important; }
    /* Eingaben */
    .fa-editor-panel input, .fa-editor-panel select, .fa-editor-panel textarea{ background:rgba(255,255,255,.07) !important; color:#f1f5f9 !important; border-color:rgba(255,255,255,.10) !important; }
    .fa-editor-panel input::placeholder, .fa-editor-panel textarea::placeholder{ color:#64748b !important; }
    .fa-editor-panel option{ background:#1e293b; color:#f1f5f9; }
    /* KI-Chips (btnAi): weisse Schrift + sichtbarer violetter Rand statt violett-auf-dunkel */
    .fa-editor-panel .bg-violet-500\/10{ background:rgba(139,92,246,.14) !important; color:#fff !important; }
    .fa-editor-panel .ring-violet-500\/20{ --tw-ring-color:rgba(167,139,250,.65) !important; }
    /* Sensorik-Spinne: die Gitterlinien sind rgb(0 0 0 …)-Attribute (hell-Theme) und auf
       dem dunklen Grund unsichtbar → hier auf sichtbares Slate heben (Attribute + inline-fill). */
    .fa-editor-panel .fa-taste-grid{ stroke:rgba(148,163,184,.28) !important; }
    .fa-editor-panel .fa-taste-grid-strong{ stroke:rgba(148,163,184,.5) !important; }
    .fa-editor-panel .fa-taste-axislabel{ fill:#e2e8f0 !important; }
    .fa-editor-panel .fa-taste-axislabel-dim{ fill:#94a3b8 !important; }
    .fa-editor-panel .fa-taste-ringlabel{ fill:#94a3b8 !important; }
    /* GP-Peek (Lieferantenartikel hinter dem GP): das Panel erzwingt bg-white → auf dem
       dunklen Grund würde die (aufgehellte) graue Schrift auf Weiss verblassen. Panel dunkel-
       konsistent machen, dann liest die helle Schrift wieder. */
    .fa-editor-panel [data-gp-peek-tabelle]{ background:rgba(255,255,255,.05) !important; border-color:rgba(255,255,255,.10) !important; }
</style>
