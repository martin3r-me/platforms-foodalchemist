{{-- „Rezept in allen Verwendungen tauschen" + „Rezept löschen" — EIN Partial für Detail-Panel
     UND Editor (Pendant zum GP-Verwaltungsblock, 2026-09-04). Bewusst geteilt: der GP-Block
     wurde 2026-08 in den Editor KOPIERT und lief seitdem auseinander (roher Status-String,
     unfindbarer Reiter). Hier gibt es nur eine Quelle.

     Erwartet aus der Komponente: $tauschBilanz · $tauschKandidaten · $tauschReferenzen ·
     $fehlerTausch · $hinweisTausch (Trait TauschtRezept) sowie $tauschSuche.
     Parameter: $rezeptName (für die Rückfragen) · $kompakt (Panel-Typo statt Editor-Typo).
     fa-pass: nur Tokens und x-fa-Bausteine (hell + Werkbank-Modus). --}}
@php
    $tt = $kompakt ? 'text-[length:var(--fa-text-sm)]' : 'text-[length:var(--fa-text-md)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $mehrzahl = fn (int $n, string $eins, string $mehr) => $n . ' ' . ($n === 1 ? $eins : $mehr);
    $statusTon = ['approved' => 'ok', 'review' => 'warn', 'tentative' => 'warn', 'rejected' => 'crit', 'deprecated' => 'crit', 'archived' => 'neutral'];
@endphp

<div class="flex flex-col gap-3" data-rezept-verwaltung>
    @if($fehlerTausch !== null)
        <x-fa::notice tone="crit" data-rezept-tausch-fehler>{{ $fehlerTausch }}</x-fa::notice>
    @endif
    @if($hinweisTausch !== null)
        <x-fa::notice tone="ok" data-rezept-tausch-hinweis>{{ $hinweisTausch }}</x-fa::notice>
    @endif

    {{-- 1. Wo hängt das Rezept? MIT Namen (2026-09-04, Dominique: „es wird nicht angezeigt
         wo es drin ist") — eine Menge ohne Adresse hilft beim Umhängen nicht weiter. --}}
    @if($tauschBilanz !== null && ($tauschBilanz['zeilen'] > 0 || $tauschBilanz['fremd_zeilen'] > 0))
        <div class="flex flex-col gap-1.5">
            <p class="{{ $tt }} text-[var(--fa-ink)]" data-rezept-tausch-bilanz>
                Als Komponente eingesetzt: {{ $mehrzahl((int) $tauschBilanz['zeilen'], 'Zeile', 'Zeilen') }} in {{ $mehrzahl((int) $tauschBilanz['rezepte'], 'eigenem Rezept', 'eigenen Rezepten') }}.
                @if($tauschBilanz['fremd_zeilen'] > 0)<span class="text-[var(--fa-ink-2)]">{{ $mehrzahl((int) $tauschBilanz['fremd_rezepte'], 'geerbtes Rezept bleibt', 'geerbte Rezepte bleiben') }} unberührt, weil sie nur gelesen werden können.</span>@endif
            </p>
            @if(($tauschBilanz['eltern_namen'] ?? []) !== [])
                <ul class="{{ $tt }} flex flex-col gap-1" data-rezept-tausch-eltern>
                    @foreach($tauschBilanz['eltern_namen'] as $e)
                        <li class="flex items-center gap-2 min-w-0" wire:key="rvw-eltern-{{ $e['id'] }}">
                            <x-fa::badge :tone="$e['ist_gericht'] ? 'info' : 'neutral'" class="shrink-0">{{ $e['ist_gericht'] ? 'Gericht' : 'Basisrezept' }}</x-fa::badge>
                            <span class="min-w-0 truncate text-[var(--fa-ink)]">{{ $e['name'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if(($tauschBilanz['fremd_namen'] ?? []) !== [])
                <p class="{{ $leise }}" data-rezept-tausch-fremd>Geerbt und unberührt: {{ implode(' · ', array_column($tauschBilanz['fremd_namen'], 'name')) }}</p>
            @endif
        </div>
    @endif

    {{-- 2. Tauschen — der Ausweg aus einer blockierten Löschung --}}
    @if($tauschBilanz !== null && $tauschBilanz['zeilen'] > 0)
        <div class="flex flex-col gap-1.5" data-rezept-tausch>
            <label for="rezept-tausch-suche-{{ $kompakt ? 'panel' : 'editor' }}" class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                In allen Verwendungen ersetzen durch <span class="font-normal text-[var(--fa-ink-3)]">(Menge und Einheit bleiben)</span>
            </label>
            <x-fa::input type="search" id="rezept-tausch-suche-{{ $kompakt ? 'panel' : 'editor' }}" wire:model.live.debounce.300ms="tauschSuche"
                placeholder="Ersatz-Rezept suchen …" :size="$kompakt ? 'sm' : 'md'" data-rezept-tausch-suche />
            @if($tauschKandidaten->isNotEmpty())
                <div class="flex flex-col gap-0.5">
                    @foreach($tauschKandidaten as $k)
                        <button type="button" wire:key="rvw-tausch-{{ $k->id }}" wire:click="rezeptErsetzen({{ $k->id }})"
                                wire:confirm="„{{ $rezeptName }}“ in {{ $mehrzahl((int) $tauschBilanz['rezepte'], 'Rezept', 'Rezepten') }} durch „{{ $k->name }}“ ersetzen? Menge und Einheit der Zeilen bleiben stehen, die Rezepte werden neu berechnet."
                                class="w-full text-left px-2 py-1.5 rounded-[var(--fa-radius-control)] {{ $tt }} text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] flex items-center gap-2" data-rezept-tausch-kandidat>
                            <x-fa::badge :tone="$statusTon[$k->status->value] ?? 'neutral'" class="shrink-0">{{ $k->status->label() }}</x-fa::badge>
                            @if($k->is_sales_recipe)<x-fa::badge tone="info" class="shrink-0">Gericht</x-fa::badge>@endif
                            <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                            @svg('heroicon-m-arrows-right-left', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                        </button>
                    @endforeach
                </div>
            @elseif(trim($tauschSuche) !== '')
                <p class="{{ $leise }}">Kein passendes Rezept gefunden.</p>
            @endif
        </div>
    @endif

    {{-- 3. Löschen — nur für eigene Basisrezepte; $tauschReferenzen ist sonst null --}}
    @if($tauschReferenzen !== null)
        <div class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-1.5" data-rezept-loeschen-block>
            @if($tauschReferenzen['blocker'] === 0)
                <div>
                    <x-fa::button :size="$kompakt ? 'sm' : 'md'" variant="danger" icon="heroicon-m-trash" wire:click="rezeptLoeschen"
                        wire:confirm="„{{ $rezeptName }}“ löschen? Nichts verweist darauf, das Rezept verschwindet aus den Listen." data-rezept-loeschen>Rezept löschen</x-fa::button>
                </div>
                <p class="{{ $leise }}">Nichts verweist auf dieses Rezept. Gelöschte Rezepte lassen sich wiederherstellen.</p>
            @else
                <p class="{{ $tt }} text-[var(--fa-ink)]" data-rezept-ref-zusammenfassung>
                    <x-fa::signal tone="warn">Löschen nicht möglich</x-fa::signal>
                    Wird verwendet: {{ implode(' · ', $tauschReferenzen['blocker_teile']) }}.@if($tauschBilanz !== null && $tauschBilanz['zeilen'] > 0)<span> Erst oben umhängen, dann löschen.</span>@endif
                </p>
                @if(($tauschReferenzen['eltern_namen'] ?? []) !== [])
                    {{-- Adresse statt Menge: die Eltern-Rezepte, die das Löschen blockieren.
                         Hier stehen ALLE (auch geerbte) — sie blockieren ebenfalls, tauchen aber
                         in der Tausch-Bilanz oben bewusst nur als „unberührt" auf. --}}
                    <p class="{{ $leise }}" data-rezept-ref-eltern>Verwendet in: {{ implode(' · ', array_column($tauschReferenzen['eltern_namen'], 'name')) }}</p>
                @endif
            @endif
            @php
                $refInfo = array_filter([
                    $tauschReferenzen['produktion_historie'] > 0 ? $mehrzahl((int) $tauschReferenzen['produktion_historie'], 'Zeile', 'Zeilen') . ' in abgeschlossenen Produktionsaufträgen' : null,
                    $tauschReferenzen['instanzen'] > 0 ? $mehrzahl((int) $tauschReferenzen['instanzen'], 'daraus abgeleitetes Rezept', 'daraus abgeleitete Rezepte') : null,
                ]);
            @endphp
            @if($refInfo !== [])
                <p class="{{ $leise }}" data-rezept-ref-info>Nur zur Info, blockiert nicht: {{ implode(' · ', $refInfo) }}</p>
            @endif
        </div>
    @endif
</div>
