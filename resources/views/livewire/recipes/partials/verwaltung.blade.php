{{-- „Rezept in allen Verwendungen tauschen" + „Rezept löschen" — EIN Partial für Detail-Panel
     UND Editor (Pendant zum GP-Verwaltungsblock, 2026-09-04). Bewusst geteilt: der GP-Block
     wurde 2026-08 in den Editor KOPIERT und lief seitdem auseinander (roher Status-String,
     unfindbarer Reiter). Hier gibt es nur eine Quelle.

     Erwartet aus der Komponente: $tauschBilanz · $tauschKandidaten · $tauschReferenzen ·
     $fehlerTausch · $hinweisTausch (Trait TauschtRezept) sowie Ui-Maps im Kontext.
     Parameter: $rezeptName (für die Rückfragen) · $kompakt (Panel-Typo statt Editor-Typo). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($tt = $kompakt ? 'text-[11px]' : 'text-xs')
@php($meldung = $kompakt ? 'text-[11px] mt-1' : 'mb-2 rounded-lg px-2.5 py-1.5 text-[11px] border')

<div data-rezept-verwaltung>
    @if($fehlerTausch !== null)
        <p class="{{ $meldung }} {{ $kompakt ? 'text-rose-500' : 'bg-rose-500/10 border-rose-500/30 text-rose-700' }}" data-rezept-tausch-fehler>{{ $fehlerTausch }}</p>
    @endif
    @if($hinweisTausch !== null)
        <p class="{{ $meldung }} {{ $kompakt ? 'text-emerald-600' : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700' }}" data-rezept-tausch-hinweis>{{ $hinweisTausch }}</p>
    @endif

    {{-- 1. Wo hängt das Rezept? --}}
    @if($tauschBilanz !== null && ($tauschBilanz['zeilen'] > 0 || $tauschBilanz['fremd_zeilen'] > 0))
        <p class="{{ $tt }} text-gray-600" data-rezept-tausch-bilanz>Als Komponente eingesetzt: {{ $tauschBilanz['zeilen'] }} Zeile(n) in {{ $tauschBilanz['rezepte'] }} eigenen Rezept(en) @if($tauschBilanz['fremd_zeilen'] > 0)· {{ $tauschBilanz['fremd_rezepte'] }} geerbte(s) Rezept(e) bleiben unberührt (read-only, D1)@endif</p>
    @endif

    {{-- 2. Tauschen — der Ausweg aus einer blockierten Löschung --}}
    @if($tauschBilanz !== null && $tauschBilanz['zeilen'] > 0)
        <div class="pt-1.5" data-rezept-tausch>
            <p class="{{ $tt }} text-gray-600 mb-1">In allen Verwendungen ersetzen durch … <span class="text-gray-400">(Menge und Einheit bleiben)</span></p>
            <input type="search" wire:model.live.debounce.300ms="tauschSuche" placeholder="Ersatz-Rezept suchen …" class="{{ $input }} {{ $kompakt ? '!py-1' : '' }}" data-rezept-tausch-suche />
            @if($tauschKandidaten->isNotEmpty())
                <div class="mt-1 space-y-0.5">
                    @foreach($tauschKandidaten as $k)
                        <button type="button" wire:key="rvw-tausch-{{ $k->id }}" wire:click="rezeptErsetzen({{ $k->id }})" wire:confirm="„{{ $rezeptName }}“ in {{ $tauschBilanz['rezepte'] }} Rezept(en) durch „{{ $k->name }}“ ersetzen? Menge und Einheit der Zeilen bleiben stehen, die Rezepte werden neu berechnet." class="w-full text-left px-2 py-1 rounded {{ $tt }} text-gray-700 hover:bg-violet-500/10 flex items-center gap-1.5" data-rezept-tausch-kandidat>
                            <span class="{{ $pill }} {{ $statusPill[$k->status->value] ?? $variantPill['secondary'] }} shrink-0">{{ $k->status->label() }}</span>
                            @if($k->is_sales_recipe)<span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">Gericht</span>@endif
                            <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                        </button>
                    @endforeach
                </div>
            @elseif(trim($tauschSuche) !== '')
                <p class="{{ $tt }} text-gray-400 mt-1">Kein passendes Ziel-Rezept.</p>
            @endif
        </div>
    @endif

    {{-- 3. Löschen — nur für eigene Basisrezepte; $tauschReferenzen ist sonst null --}}
    @if($tauschReferenzen !== null)
        <div class="pt-2 mt-2 border-t border-black/5" data-rezept-loeschen-block>
            @if($tauschReferenzen['blocker'] === 0)
                <button type="button" wire:click="rezeptLoeschen" wire:confirm="„{{ $rezeptName }}“ löschen? (Keine Referenzen vorhanden — das Rezept verschwindet aus den Listen.)" class="{{ $btnGhostXs }} text-rose-600" data-rezept-loeschen>Rezept löschen</button>
                <p class="{{ $tt }} text-gray-500 mt-1">Keine Referenzen — Löschen möglich (Soft-Delete, wiederherstellbar).</p>
            @else
                <p class="{{ $tt }} text-gray-600" data-rezept-ref-zusammenfassung>Löschen blockiert — wird referenziert: {{ implode(' · ', $tauschReferenzen['blocker_teile']) }}. @if($tauschBilanz !== null && $tauschBilanz['zeilen'] > 0)Erst oben umhängen, dann löschen.@endif</p>
            @endif
            @php($refInfo = array_filter([$tauschReferenzen['produktion_historie'] > 0 ? $tauschReferenzen['produktion_historie'] . ' Zeile(n) in abgeschlossenen Produktionsaufträgen' : null, $tauschReferenzen['instanzen'] > 0 ? $tauschReferenzen['instanzen'] . ' daraus instanziierte(s) Rezept(e)' : null]))
            @if($refInfo !== [])
                <p class="{{ $tt }} text-gray-400 mt-1" data-rezept-ref-info>Nur zur Info (blockiert nicht): {{ implode(' · ', $refInfo) }}</p>
            @endif
        </div>
    @endif
</div>
