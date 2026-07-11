{{-- #469 Wissens-Modul v1 — Pflege-Browser (Liste links, Editor + Verdrahtung rechts) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Wissen" icon="heroicon-o-academic-cap" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Wissen'],
        ]">
            <x-slot:end>
                <button type="button" wire:click="neu" class="{{ $btnPrimary }}" data-wissen-neu>+ Neues Wissen</button>
            </x-slot:end>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        @if($fehler !== null)<p class="text-xs text-rose-600 dark:text-rose-400" data-wissen-fehler>{{ $fehler }}</p>@endif

        <div class="flex gap-4 items-start">
            {{-- LINKS: Liste + Filter --}}
            <div class="w-96 shrink-0 {{ $card }} p-3 space-y-3" data-wissen-liste>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Suche (Titel · Slug · Inhalt)…" class="{{ $input }} !py-1 w-full" data-wissen-suche />
                <div class="flex gap-2">
                    <select wire:model.live="filterCategory" class="{{ $input }} !py-1 flex-1 text-xs">
                        <option value="">Alle Kategorien</option>
                        @foreach($kategorien as $kat)
                            <option value="{{ $kat->slug }}">{{ $kat->label }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="filterStatus" class="{{ $input }} !py-1 w-28 text-xs">
                        <option value="all">Alle</option>
                        <option value="active">Aktiv</option>
                        <option value="inactive">Inaktiv</option>
                    </select>
                </div>
                <p class="text-[11px] text-gray-400">{{ $docs->count() }} Dokument(e)</p>

                <div class="space-y-0.5 max-h-[62vh] overflow-y-auto -mx-1 px-1">
                    @forelse($docs as $doc)
                        <button type="button" wire:click="select({{ $doc->id }})" wire:key="doc-{{ $doc->id }}"
                            class="w-full text-left px-2.5 py-1.5 rounded-lg transition-colors {{ $selected && $selected->id === $doc->id
                                ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                                : 'hover:bg-black/[0.03] dark:hover:bg-white/5' }} {{ $doc->active ? '' : 'opacity-50' }}">
                            <span class="block text-xs font-medium text-gray-900 dark:text-gray-100 truncate">{{ $doc->title }}</span>
                            <span class="flex items-center gap-1.5 mt-0.5">
                                <span class="text-[10px] {{ $pill }}">{{ $doc->category }}</span>
                                <span class="text-[10px] text-gray-400">{{ $doc->char_count }} Z.</span>
                                @unless($doc->active)<span class="text-[10px] text-amber-500">inaktiv</span>@endunless
                            </span>
                        </button>
                    @empty
                        <p class="text-xs text-gray-400 px-2 py-4">Keine Treffer.</p>
                    @endforelse
                </div>
            </div>

            {{-- RECHTS: Editor + Verdrahtung --}}
            <div class="flex-1 min-w-0 space-y-4" data-wissen-detail>
                @if($selected || $creating)
                    <div class="{{ $card }} p-4 space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">
                                {{ $creating ? 'Neues Wissen anlegen' : 'Bearbeiten' }}
                            </h3>
                            @if($selected)
                                <span class="text-[10px] font-mono text-gray-400">{{ $selected->slug }} · v{{ $selected->version }}</span>
                            @endif
                        </div>

                        <div class="flex gap-3">
                            <div class="flex-1">
                                <label class="{{ $label }}">Titel</label>
                                <input type="text" wire:model="form.title" class="{{ $input }} w-full" data-wissen-titel />
                            </div>
                            <div class="w-56">
                                <label class="{{ $label }}">Kategorie</label>
                                <select wire:model="form.category" class="{{ $input }} w-full" data-wissen-kategorie>
                                    @foreach($kategorien as $kat)
                                        <option value="{{ $kat->slug }}">{{ $kat->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex items-end pb-1">
                                <label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                    <input type="checkbox" wire:model="form.active" /> aktiv
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="{{ $label }}">Inhalt (Markdown)</label>
                            <textarea wire:model="form.content_md" rows="18" class="{{ $input }} w-full font-mono text-xs leading-relaxed" data-wissen-inhalt></textarea>
                        </div>

                        <div class="flex justify-end">
                            <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-wissen-save>Speichern</button>
                        </div>
                    </div>

                    @if($selected)
                        {{-- Aliases (pflegbar) --}}
                        <div class="{{ $card }} p-4 space-y-2" data-wissen-aliases>
                            <p class="{{ $dt }}">Aliase <span class="text-[10px] text-gray-400">— Begriffe, unter denen die KI dieses Wissen findet</span></p>
                            <div class="flex flex-wrap gap-1.5">
                                @forelse($aliases as $a)
                                    <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }}" wire:key="alias-{{ $a->id }}">
                                        {{ $a->alias_slug }}
                                        <button type="button" wire:click="removeAlias({{ $a->id }})" class="text-gray-400 hover:text-red-500" title="entfernen">&times;</button>
                                    </span>
                                @empty
                                    <span class="text-[11px] text-gray-400">Noch keine Aliase.</span>
                                @endforelse
                            </div>
                            <div class="flex gap-2">
                                <input type="text" wire:model="newAlias" wire:keydown.enter="addAlias" placeholder="neuer Alias…" class="{{ $input }} !py-1 w-52" data-wissen-neu-alias />
                                <button type="button" wire:click="addAlias" class="{{ $btnGhostXs }}">+ hinzufügen</button>
                            </div>
                        </div>

                        {{-- Verdrahtung / nachvollziehbar (read-only v1) --}}
                        <div class="{{ $card }} p-4 space-y-3" data-wissen-verdrahtung>
                            <p class="{{ $dt }}">Verdrahtung <span class="text-[10px] text-gray-400">— wo dieses Wissen wirkt</span></p>

                            <div>
                                <p class="text-[11px] font-medium text-gray-500 mb-1">Grobe Ebene — KI-Features via Kategorie «{{ $selected->category }}» (automatisch)</p>
                                @forelse($routings as $r)
                                    <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }} mr-1.5" wire:key="rt-{{ $r->id }}">
                                        {{ $r->feature }} <span class="text-gray-400">· {{ $r->mode }}</span>
                                    </span>
                                @empty
                                    <span class="text-[11px] text-gray-400">Keine Feature-Routings für diese Kategorie.</span>
                                @endforelse
                            </div>

                            <div>
                                <p class="text-[11px] font-medium text-gray-500 mb-1">Feine Ebene — an Einsatzorte gebunden (direkt einbinden)</p>
                                <div class="flex flex-wrap gap-1.5 mb-2">
                                    @forelse($bindings as $b)
                                        <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }}" wire:key="bd-{{ $b->id }}">
                                            {{ $layerLabels[$b->target_key] ?? $b->target_key }}@if($b->mode) <span class="text-gray-400">· {{ $b->mode }}</span>@endif
                                            <button type="button" wire:click="removeBinding({{ $b->id }})" class="text-gray-400 hover:text-red-500" title="Bindung lösen">&times;</button>
                                        </span>
                                    @empty
                                        <span class="text-[11px] text-gray-400">Noch keine Bindungen.</span>
                                    @endforelse
                                </div>
                                {{-- Bindung hinzufügen: Bereich (grob) oder Einzel-Prompt (fein) --}}
                                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-2.5 py-2">
                                    <select wire:model="newBinding.target_key" class="{{ $input }} !py-1 text-xs w-64" data-bind-target>
                                        <option value="">— Einsatzort wählen —</option>
                                        <optgroup label="Bereiche (grob)">
                                            @foreach($layers->where('kind', 'bereich') as $l)<option value="{{ $l->slug }}">{{ $l->label }}</option>@endforeach
                                        </optgroup>
                                        <optgroup label="Einzel-Prompts (fein)">
                                            @foreach($layers->where('kind', 'prompt') as $l)<option value="{{ $l->slug }}">{{ $l->slug }}</option>@endforeach
                                        </optgroup>
                                    </select>
                                    <select wire:model="newBinding.mode" class="{{ $input }} !py-1 text-xs w-32" title="Injektions-Modus">
                                        @foreach(['always','discovery','grounding','reference'] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                                    </select>
                                    <button type="button" wire:click="addBinding" class="{{ $btnGhostXs }}" data-bind-add>+ einbinden</button>
                                </div>
                            </div>
                        </div>

                        {{-- Rückwärts-Traceability: was hängt an einem Ziel? --}}
                        <div class="{{ $card }} p-4 space-y-2" data-wissen-trace>
                            <p class="{{ $dt }}">Rückwärts nachvollziehen <span class="text-[10px] text-gray-400">— was hängt an einem KI-Layer / einer Warengruppe?</span></p>
                            <div class="flex flex-wrap items-center gap-2">
                                <select wire:model.live="traceTarget" class="{{ $input }} !py-1 text-xs w-64">
                                    <option value="">— Einsatzort wählen —</option>
                                    <optgroup label="Bereiche">
                                        @foreach($layers->where('kind', 'bereich') as $l)<option value="{{ $l->slug }}">{{ $l->label }}</option>@endforeach
                                    </optgroup>
                                    <optgroup label="Einzel-Prompts">
                                        @foreach($layers->where('kind', 'prompt') as $l)<option value="{{ $l->slug }}">{{ $l->slug }}</option>@endforeach
                                    </optgroup>
                                </select>
                            </div>
                            @if($traceTarget !== '')
                                <div class="space-y-0.5">
                                    @forelse($traceResults as $t)
                                        <button type="button" wire:click="select({{ $t->id }})" class="block w-full text-left text-[11px] px-2 py-1 rounded hover:bg-black/[0.03] dark:hover:bg-white/5" wire:key="tr-{{ $t->id }}">
                                            {{ $t->title }} <span class="text-gray-400">· {{ $t->category }}@if($t->mode) · {{ $t->mode }}@endif</span>
                                        </button>
                                    @empty
                                        <p class="text-[11px] text-gray-400">Nichts an diesem Ziel gebunden.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    @endif
                @else
                    <div class="{{ $card }} p-10 text-center text-sm text-gray-400" data-wissen-empty>
                        Links ein Wissens-Dokument wählen oder oben rechts «+ Neues Wissen».
                    </div>
                @endif
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
