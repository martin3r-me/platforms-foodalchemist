{{-- Spec 18 — Produktion: Editor-Modal (Stammdaten / Ziele / Vorschau) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@php($pzRezepte = $vorschau['rezepte'] ?? [])
@php($pzAnsaetze = collect($pzRezepte)->sum('ansaetze'))
@php($pzZeit = collect($pzRezepte)->sum(fn ($r) => (int) ($r['arbeitszeit_min'] ?? 0)))
{{-- Spec 29-Rollout: Produktion-Editor auf Editor-Page-Muster (fullscreen · dark · editor-tabs · KPI). --}}
<x-foodalchemist::modal name="produktion-editor" fullscreen dark-canvas
    :title="$orderId === null ? 'Neuer Produktionsauftrag' : 'Produktionsauftrag bearbeiten'"
    :title-name="$orderId === null ? null : ($name ?: null)">
    <x-slot:actions>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-produktion-speichern>Speichern</button>
        @if($fehler)<span class="text-[12px] text-rose-600 ml-2 self-center" data-produktion-fehler>{{ $fehler }}</span>@endif
    </x-slot:actions>

    {{-- KPI-Kopf: Ziele + Rezepte + Ansätze (Leitwert) + Arbeitszeit aus der Ansätze-Vorschau --}}
    <x-slot:kpiHeader>
        <x-foodalchemist::kpi-tiles marker="produktion-kpis" :tiles="[
            ['kpi' => 'ziele', 'label' => 'Ziele', 'value' => (string) count($targets)],
            ['kpi' => 'rezepte', 'label' => 'Rezepte', 'value' => (string) count($pzRezepte)],
            ['kpi' => 'ansaetze', 'label' => 'Ansätze', 'tone' => 'accent',
             'value' => $pzAnsaetze > 0 ? rtrim(rtrim(number_format((float) $pzAnsaetze, 2, ',', '.'), '0'), ',') : '—'],
            ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $pzZeit > 0 ? $pzZeit . ' min' : '—'],
        ]" />
    </x-slot:kpiHeader>

    <x-foodalchemist::editor-tabs marker="produktion" wire-key="produktion-tabs-{{ $orderId ?? 'neu' }}" :init="'stammdaten'"
        :tabs="['stammdaten' => 'Stammdaten', 'ziele' => 'Ziele', 'vorschau' => 'Vorschau', 'zeilen' => $orderId ? 'Zeilen' : null, 'einkauf' => $orderId ? 'Einkauf & Status' : null]">

    @include('foodalchemist::livewire.produktion.partials.editor-stammdaten')

    @include('foodalchemist::livewire.produktion.partials.editor-ziele')

    @include('foodalchemist::livewire.produktion.partials.editor-vorschau')

    {{-- ═══ Tab: EINKAUF & STATUS (aus DetailPanel gemergt — nur bestehender Auftrag) ═══ --}}
    {{-- ── Tab: ZEILEN (Spec 30 E2) — der Auftrag als Arbeitsdokument ──────────
         Die Vorschau zeigt, was gerechnet WURDE. Hier greift der Mensch ein: Ansätze
         überschreiben, Zeilen streichen, freie Positionen ergänzen. Was hier gesetzt wird,
         überlebt jeden Recompute (Overlay), die berechnete Zahl bleibt als Referenz stehen. --}}
    @include('foodalchemist::livewire.produktion.partials.editor-zeilen')

    @include('foodalchemist::livewire.produktion.partials.editor-einkauf')
    </x-foodalchemist::editor-tabs>
</x-foodalchemist::modal>
