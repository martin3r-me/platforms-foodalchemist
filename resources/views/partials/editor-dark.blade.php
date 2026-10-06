{{--
    Spec 28 / E0.3: der dunkle Editor-Grund. Herausgelöst aus `components/modal.blade.php`
    (dort wuchs er mitten im Markup) — eingebunden nur bei `darkCanvas`.

    WARUM rohes, gescopetes CSS und keine `dark:`-Utilities:
    Die Plattform-Shell hat keinen Dark Mode (README §159) und der FA ist seit `977a301`
    bewusst `dark:`-frei — sonst zerschießt OS-Dark den hellen Content. Der Editor ist also
    eine Insel: alles hängt an `.fa-editor-canvas`, helle Kontexte (Settings, kleine Dialoge)
    bleiben unberührt.

    WARTUNGSREGEL (wichtig): das hier ist eine `!important`-Kaskade, die die Hell-Utilities
    überschreibt. Jede NEUE Fläche in einem Editor braucht hier einen Selektor — sonst steht
    graue Schrift auf grauem Grund. Neue Regeln immer MIT Begründung, sonst ist der Block in
    einem halben Jahr nicht mehr anfassbar. Reihenfolge: Grund → Trennlinien → Schrift →
    Flächen → Eingaben → Sonderfälle.
--}}
{{-- fa-pass (Design „Labor und Tageslicht", 2026-10-05): der Editor ist NICHT mehr dunkel.
     Eine Oberfläche für alles — der Editor bekommt nur den hellen Arbeitsgrund, Karten bleiben Flächen.
     Die frühere Dunkel-Kaskade liegt in der Git-Historie (main). --}}
<style>
    .fa-editor-canvas{ background:var(--fa-ground) !important; border-color:var(--fa-line) !important; color:var(--fa-ink); }
    .fa-editor-canvas [data-modal-zone="body"]{ background:transparent !important; }
    .fa-editor-canvas [data-modal-zone="section"]{ background:var(--fa-surface) !important; border-color:var(--fa-line) !important; box-shadow:none !important; }
    .fa-editor-canvas .sticky{ background:var(--fa-surface) !important; }
</style>
