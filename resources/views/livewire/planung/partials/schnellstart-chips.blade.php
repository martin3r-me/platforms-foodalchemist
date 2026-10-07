{{-- Schnellstart-Vorlagen (Brief-Vorlagen) für einen Erstell-Reiter: global gepflegte und team-eigene
     (Stern, inline löschbar). Ein Klick füllt Brief, Kreativ-Modus und den kompletten Stand der Leitplanken.
     Erwartet: $scope. Nutzt die Komponenten-Prop $aktiveVorlage + $this->vorlagenFuer($scope).
     fa-pass: Chips über Tokens (hell + Werkbank), Stern als Heroicon statt Zeichen. --}}
@php($vorlagen = $this->vorlagenFuer($scope))
@if(count($vorlagen))
    <div class="flex flex-col gap-1.5" data-brief-vorlagen>
        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Schnellstart mit Vorlage <span class="font-normal text-[var(--fa-ink-3)]">(optional)</span></p>
        <div class="flex flex-wrap gap-1.5">
            @foreach($vorlagen as $vid => $v)
                @php($istAktiv = ($aktiveVorlage[$scope] ?? null) === (string) $vid)
                <span @class([
                          'inline-flex items-center h-7 rounded-full border transition-colors text-[length:var(--fa-text-sm)]',
                          'border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium' => $istAktiv,
                          'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]' => ! $istAktiv,
                      ]) data-brief-vorlage="{{ $vid }}">
                    <button type="button" wire:click="briefVorlage('{{ $scope }}', '{{ $vid }}')"
                            class="inline-flex items-center gap-1 h-full pl-2.5 {{ $v['is_global'] ? 'pr-2.5' : 'pr-1' }}"
                            @if($istAktiv) aria-pressed="true" @endif>
                        @unless($v['is_global'])@svg('heroicon-m-star', 'w-3.5 h-3.5 text-[var(--fa-warn)]')@endunless{{ $v['label'] }}
                    </button>
                    @unless($v['is_global'])
                        <button type="button" wire:click="loeschenVorlage('{{ $scope }}', {{ $v['id'] }})"
                                wire:confirm="Vorlage „{{ $v['label'] }}“ löschen?"
                                class="inline-flex items-center justify-center w-6 h-full pr-1 text-[var(--fa-ink-3)] hover:text-[var(--fa-crit)]"
                                aria-label="Eigene Vorlage löschen" title="Eigene Vorlage löschen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                    @endunless
                </span>
            @endforeach
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Füllt Briefing, Kreativ-Modus und Leitplanken als Startpunkt, alles bleibt frei änderbar. @svg('heroicon-m-star', 'w-3.5 h-3.5 inline -mt-0.5 text-[var(--fa-warn)]') eigene Vorlage</p>
    </div>
@endif
