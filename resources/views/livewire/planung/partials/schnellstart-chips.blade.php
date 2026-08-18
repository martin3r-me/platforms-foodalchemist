{{-- Schnellstart-Vorlagen (Brief-Templates) für einen Creation-Tab: Global-kuratierte ∪ team-eigene (★,
     inline löschbar). Ein Klick füllt Brief + Kreativ-Modus + kompletten Leitplanken-Stand.
     Erwartet: $scope. Nutzt die Komponenten-Prop $aktiveVorlage + $this->vorlagenFuer($scope). --}}
@php($vorlagen = $this->vorlagenFuer($scope))
@if(count($vorlagen))
    <div class="mb-3" data-brief-vorlagen>
        <label class="{{ $label ?? 'text-[11px] text-gray-500' }} block mb-1">Schnellstart-Vorlage (optional)</label>
        <div class="flex flex-wrap gap-1.5">
            @foreach($vorlagen as $vid => $v)
                <span @class([
                          'inline-flex items-center rounded-full border transition-colors',
                          'border-violet-400 bg-violet-500/20 text-violet-200 ring-1 ring-violet-400/40' => ($aktiveVorlage[$scope] ?? null) === (string) $vid,
                          'border-white/10 bg-white/5 text-gray-300 hover:bg-violet-500/10 hover:text-violet-200' => ($aktiveVorlage[$scope] ?? null) !== (string) $vid,
                      ]) data-brief-vorlage="{{ $vid }}">
                    <button type="button" wire:click="briefVorlage('{{ $scope }}', '{{ $vid }}')"
                            class="pl-2.5 pr-2 py-1 text-[11px] {{ ($aktiveVorlage[$scope] ?? null) === (string) $vid ? 'font-medium' : '' }}">
                        @unless($v['is_global'])<span class="text-amber-300/80">★</span> @endunless{{ $v['label'] }}
                    </button>
                    @unless($v['is_global'])
                        <button type="button" wire:click="loeschenVorlage('{{ $scope }}', {{ $v['id'] }})"
                                wire:confirm="Vorlage „{{ $v['label'] }}“ löschen?"
                                class="pr-2 pl-0.5 text-[13px] leading-none text-gray-400 hover:text-red-400" title="Eigene Vorlage löschen">&times;</button>
                    @endunless
                </span>
            @endforeach
        </div>
        <p class="text-[11px] text-gray-500 mt-1">Füllt Briefing, Kreativ-Modus und Leitplanken als Startpunkt — alles frei anpassbar. <span class="text-amber-300/70">★</span> = eigene Vorlage.</p>
    </div>
@endif
