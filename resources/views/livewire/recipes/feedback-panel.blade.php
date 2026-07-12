{{-- R2.6 — Feedback-Tab (geteilt: Gericht + Basisrezept). Praxis-Feedback
     Küche/Kunde/Event, Ø-Aggregat, Neu-Eintrag, „Weiterentwickeln"-Brücke. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($quelleLabels = ['kueche' => 'Küche', 'kunde' => 'Kunde', 'event' => 'Event'])
@php($quellePill = ['kueche' => $variantPill['primary'], 'kunde' => $variantPill['info'], 'event' => $variantPill['secondary']])

<div class="space-y-4" wire:key="feedback-panel-{{ $recipeId }}">

    {{-- Aggregat-Kopf --}}
    <div class="flex items-center gap-3 flex-wrap">
        <div class="{{ $kpiTile }} min-w-[7rem]">
            <div class="{{ $kpiTileAccent }}"></div>
            <div class="{{ $label }}">Ø-Score</div>
            <div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                {{ $aggregat['avg'] !== null ? number_format((float) $aggregat['avg'], 1, ',', '.') : '—' }}
                <span class="text-[11px] font-normal text-gray-400">/ 5</span>
            </div>
        </div>
        <div class="text-xs text-gray-500 dark:text-gray-400">
            {{ $aggregat['count'] }} {{ $aggregat['count'] === 1 ? 'Eintrag' : 'Einträge' }}
            @foreach($aggregat['per_source'] as $q => $n)
                <span class="{{ $pill }} {{ $quellePill[$q] ?? $variantPill['secondary'] }} ml-1">{{ $quelleLabels[$q] ?? $q }}: {{ $n }}</span>
            @endforeach
        </div>
    </div>

    @if(session('fa_feedback_hinweis'))
        <div class="text-[11px] {{ $pill }} {{ $variantPill['success'] }} px-2 py-1">{{ session('fa_feedback_hinweis') }}</div>
    @endif

    {{-- Liste bestehender Einträge --}}
    <div class="space-y-2">
        @forelse($eintraege as $f)
            <div class="{{ $sectionCard }} !p-3" wire:key="fb-{{ $f->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="{{ $pill }} {{ $quellePill[$f->quelle->value] ?? $variantPill['secondary'] }}">{{ $f->quelle->label() }}</span>
                        @if($f->score !== null)<span class="text-xs font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $f->score }}/5</span>@endif
                        @if($f->created_at)<span class="text-[11px] text-gray-400">{{ $f->created_at->format('d.m.Y') }}</span>@endif
                        @if($f->created_via === 'mcp')<span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="via KI/MCP angelegt">KI</span>@endif
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="weiterentwickeln({{ $f->id }})" class="{{ $btnGhostXs }}" title="Aus diesem Feedback eine Draft-Rezept-Iteration erzeugen">↗ Weiterentwickeln</button>
                        @if($ownTeamId !== null && (int) $f->team_id === (int) $ownTeamId)
                            <button type="button" wire:click="loeschen({{ $f->id }})" wire:confirm="Diesen Feedback-Eintrag löschen?" class="{{ $btnGhostXs }} !text-red-500" title="Löschen">✕</button>
                        @endif
                    </div>
                </div>
                @if($f->quelle->hatAchsen() && ($f->machbarkeit || $f->aufwand || $f->geschmack || $f->gaeste_reaktion))
                    <div class="flex flex-wrap gap-1 mt-1.5">
                        @if($f->machbarkeit)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Machbarkeit {{ $f->machbarkeit }}</span>@endif
                        @if($f->aufwand)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Aufwand {{ $f->aufwand }}</span>@endif
                        @if($f->geschmack)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Geschmack {{ $f->geschmack }}</span>@endif
                        @if($f->gaeste_reaktion)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Gäste {{ $f->gaeste_reaktion }}</span>@endif
                    </div>
                @endif
                @if($f->comment)<p class="text-xs text-gray-600 dark:text-gray-300 mt-1.5">{{ $f->comment }}</p>@endif
                @if($f->kontext_label)<p class="text-[11px] text-gray-400 mt-0.5">Kontext: {{ $f->kontext_label }}</p>@endif
                @if($f->spawned_recipe_id)<p class="text-[11px] text-violet-500 mt-0.5">→ Draft-Iteration #{{ $f->spawned_recipe_id }} abgeleitet</p>@endif
            </div>
        @empty
            <p class="text-xs text-gray-400 {{ $sectionCard }} !p-3">Noch kein Feedback. Der erste Eintrag unten — die Küche, die es kocht, ist die ehrlichste Quelle.</p>
        @endforelse
    </div>

    {{-- Neu-Eintrag --}}
    <div class="{{ $sectionCard }}">
        <div class="{{ $label }} mb-2">Neues Feedback</div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-2 items-end">
            <div>
                <label class="{{ $label }} block mb-1">Quelle</label>
                <select wire:model.live="quelle" class="{{ $input }}">
                    <option value="kueche">Küche</option>
                    <option value="kunde">Kunde</option>
                    <option value="event">Event</option>
                </select>
            </div>
            <div>
                <label class="{{ $label }} block mb-1">Score (1–5)</label>
                <input type="number" min="1" max="5" wire:model="score" class="{{ $input }} tabular-nums" placeholder="—" />
            </div>
            <div class="md:col-span-2">
                <label class="{{ $label }} block mb-1">Kontext (Event/Konzept, optional)</label>
                <input type="text" wire:model="kontext_label" class="{{ $input }}" placeholder="z. B. Sommerfest Zentrag" />
            </div>
        </div>

        @if($quelle === 'kueche')
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-2">
                <div><label class="{{ $label }} block mb-1">Machbarkeit</label><input type="number" min="1" max="5" wire:model="machbarkeit" class="{{ $input }} tabular-nums" /></div>
                <div><label class="{{ $label }} block mb-1">Aufwand</label><input type="number" min="1" max="5" wire:model="aufwand" class="{{ $input }} tabular-nums" /></div>
                <div><label class="{{ $label }} block mb-1">Geschmack</label><input type="number" min="1" max="5" wire:model="geschmack" class="{{ $input }} tabular-nums" /></div>
                <div><label class="{{ $label }} block mb-1">Gäste-Reaktion</label><input type="number" min="1" max="5" wire:model="gaeste_reaktion" class="{{ $input }} tabular-nums" /></div>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">Küchen-Feedback = Entwicklungs-Motor. Kein Gesamt-Score gesetzt? → Mittel aus Machbarkeit/Geschmack/Gäste-Reaktion.</p>
        @endif

        <div class="mt-2">
            <label class="{{ $label }} block mb-1">Kommentar</label>
            <textarea wire:model="comment" rows="2" class="{{ $input }}" placeholder="Praxis-Notiz: was lief, was fehlte, Weiterentwicklungs-Idee …"></textarea>
        </div>

        @error('quelle') <p class="text-[11px] text-red-500 mt-1">{{ $message }}</p> @enderror
        @error('comment') <p class="text-[11px] text-red-500 mt-1">{{ $message }}</p> @enderror

        <div class="flex justify-end mt-2">
            <button type="button" wire:click="speichern" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
                <span wire:loading.remove wire:target="speichern">Feedback speichern</span>
                <span wire:loading wire:target="speichern">Speichere…</span>
            </button>
        </div>
    </div>
</div>
