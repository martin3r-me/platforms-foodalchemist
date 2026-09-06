{{-- KI-Kontext der Erstellung (2026-09-06) — Rezept UND Gericht. Zeigt NACH der Erstellung,
     welches Wissen der Generator gelesen hat (Kanäle aus dem Call-Log, Prompt-Größen aus der
     Messsonde). Vorher lebte diese Sicht nur im Generator-Modal, solange es offen war.
     Erwartet: $kiKontext = RecipeKiKontextService::fuerRezept() → null blendet die Sektion aus. --}}
@if(($kiKontext ?? null) !== null)
    @php
        $km = $kiKontext['meta'];
        $kmMeta = ($km['dossiers'] ?? 0) . ' Dossier' . (($km['dossiers'] ?? 0) === 1 ? '' : 's') . ' · Erstellung';
    @endphp
    <x-foodalchemist::section title="KI-Kontext" icon="heroicon-o-cpu-chip" :meta="$kmMeta" data-sektion="ki-kontext">
        @php
            $kmTeile = [$km['feature'] === 'vk.generator' ? 'Gericht-Generator' : 'Basisrezept-Generator'];
            if ($km['erstellt_am']) { $kmTeile[] = \Illuminate\Support\Carbon::parse($km['erstellt_am'])->format('d.m.Y H:i'); }
            if ($km['model']) { $kmTeile[] = $km['model']; }
            if ($km['tokens_in'] > 0) { $kmTeile[] = number_format($km['tokens_in'], 0, ',', '.') . ' / ' . number_format($km['tokens_out'], 0, ',', '.') . ' Token'; }
        @endphp
        <p class="text-[11px] text-gray-500" data-ki-kontext-meta>{{ implode(' · ', $kmTeile) }}</p>
        <x-foodalchemist::kontext-inspektor :kontext="$kiKontext['kontext']" />
    </x-foodalchemist::section>
@endif
