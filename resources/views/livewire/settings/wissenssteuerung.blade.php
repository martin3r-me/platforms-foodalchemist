{{-- Spec 52 (Grundsatz E): Wissens-Steuerung sichtbar + einstellbar — vorher hatte nur die ALTE Bindungs-Ebene eine UI --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-6" data-settings-wissenssteuerung>
    @if($fehler !== null)<p class="text-xs text-rose-600" data-ws-fehler>{{ $fehler }}</p>@endif
    @if($hinweis !== null)<p class="text-xs text-emerald-600" data-ws-hinweis>{{ $hinweis }}</p>@endif

    {{-- ── Überblick ── --}}
    <div class="flex flex-wrap items-center gap-4" data-ws-zaehler>
        @foreach([
            ['gesteuert', $bericht['gesteuert'], 'text-emerald-600'],
            ['bewusst leer', $bericht['bewusst_leer'], 'text-gray-500'],
            ['ungesteuert', $bericht['ungesteuert'], 'text-amber-600'],
            ['fehlerhaft', $bericht['fehlerhaft'], 'text-rose-600'],
        ] as [$label, $wert, $farbe])
            <div>
                <p class="text-lg font-semibold {{ $farbe }}">{{ $wert }}</p>
                <p class="text-[11px] text-gray-500">{{ $label }}</p>
            </div>
        @endforeach
        <p class="text-[11px] text-gray-500 ml-auto max-w-md">
            <span class="font-medium">ungesteuert</span> heisst: aus dem Wissens-Korpus erreicht diesen Schritt
            nichts. Regeln im Prompt-Text selbst sind davon unberührt.
            <span class="font-medium">fehlerhaft</span> heisst: es ist etwas hinterlegt, es löst nur nicht auf.
        </p>
    </div>

    {{-- ── Filter ── --}}
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="bereich" class="{{ $input }} !py-1 w-48" data-ws-bereich>
            <option value="">alle Bereiche ({{ $bericht['keys'] }})</option>
            @foreach($bereiche as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
        </select>
        <label class="flex items-center gap-1.5 text-[11px] text-gray-600">
            <input type="checkbox" wire:model.live="nurBefunde" data-ws-nur-befunde /> nur mit Befund
        </label>
    </div>

    {{-- ── Profile ── --}}
    <table class="{{ $table }}" data-ws-profile>
        <thead><tr class="text-left">@foreach(['Prompt-Key', 'Zustand', 'Pflicht', 'Suche', 'Σ Pflicht / Budget', 'Fingerabdruck'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($profile as $p)
                @php($blockiert = collect($p['befunde'])->contains(fn ($b) => $b['schwere'] === 'blockiert'))
                <tr class="{{ $tr }} cursor-pointer" wire:key="p-{{ $p['prompt_key'] }}" wire:click="toggleOffen('{{ $p['prompt_key'] }}')">
                    <td class="{{ $td }} font-mono text-[11px] text-gray-900">
                        {{ $p['prompt_key'] }}
                        @if($p['alt_schluessel'])
                            <span class="text-[10px] text-amber-600" title="Routing läuft unter einem Alt-Schlüssel — ein Call, zwei Identitäten">→ {{ $p['routing_key'] }}</span>
                        @endif
                    </td>
                    <td class="{{ $td }} text-[11px] {{ match($p['zustand']) { 'gesteuert' => 'text-emerald-600', 'fehlerhaft' => 'text-rose-600 font-medium', 'ungesteuert' => 'text-amber-600', default => 'text-gray-500' } }}">
                        {{ $p['zustand'] }}
                    </td>
                    <td class="{{ $td }} text-[11px] text-gray-600">{{ count($p['pflicht']) ?: '–' }}</td>
                    <td class="{{ $td }} text-[11px] text-gray-600">{{ count(array_filter($p['routing'], fn ($r) => $r['mode'] !== 'none')) ?: '–' }}</td>
                    <td class="{{ $td }} text-[11px] text-gray-600">
                        {{ number_format($p['pflicht_zeichen'], 0, ',', '.') }} / {{ number_format($p['budget_bound'], 0, ',', '.') }}
                    </td>
                    <td class="{{ $td }} font-mono text-[10px] text-gray-400">{{ $p['fingerabdruck'] }}</td>
                </tr>

                @if($p['befunde'] !== [])
                    <tr wire:key="b-{{ $p['prompt_key'] }}">
                        <td colspan="6" class="{{ $td }} !pt-0">
                            @foreach($p['befunde'] as $b)
                                <p class="text-[11px] {{ $b['schwere'] === 'blockiert' ? 'text-rose-600' : 'text-amber-600' }}">
                                    {{ $b['schwere'] === 'blockiert' ? '✗' : '·' }}
                                    <span class="font-mono text-[10px]">[{{ $b['code'] }}]</span>
                                    @if($b['slug'] !== null)<span class="font-mono">{{ $b['slug'] }}</span> — @endif{{ $b['text'] }}
                                </p>
                            @endforeach
                        </td>
                    </tr>
                @endif

                @if($offen === $p['prompt_key'])
                    <tr wire:key="d-{{ $p['prompt_key'] }}">
                        <td colspan="6" class="{{ $td }} !pt-0">
                            <div class="rounded-lg bg-black/[0.03] px-3 py-2 space-y-2">
                                <div>
                                    <p class="{{ $dt }}">Pflicht (kommt immer vollständig, wird nie gekappt)</p>
                                    @forelse($p['pflicht'] as $d)
                                        <p class="text-[11px] font-mono text-gray-600">{{ $d['slug'] }} <span class="text-gray-400">@v{{ $d['version'] }} · {{ number_format($d['zeichen'], 0, ',', '.') }} Z.</span></p>
                                    @empty
                                        <p class="text-[11px] text-gray-400">—</p>
                                    @endforelse
                                </div>
                                @if($p['wenn_platz'] !== [])
                                    <div>
                                        <p class="{{ $dt }}">wenn Platz (fällt bei Budgetmangel ganz weg, nie angeschnitten)</p>
                                        @foreach($p['wenn_platz'] as $d)
                                            <p class="text-[11px] font-mono text-gray-600">{{ $d['slug'] }} <span class="text-gray-400">@v{{ $d['version'] }}</span></p>
                                        @endforeach
                                    </div>
                                @endif
                                <div>
                                    <p class="{{ $dt }}">Suche — Routing auf «{{ $p['routing_key'] }}» (Budget {{ number_format($p['budget_retrieval'], 0, ',', '.') }} Z.)</p>
                                    @forelse($p['routing'] as $r)
                                        <p class="text-[11px] text-gray-600">
                                            <span class="font-mono">{{ $r['category'] }}</span> → {{ $r['mode'] }}
                                            @if($r['max_docs'])<span class="text-gray-400">({{ $r['max_docs'] }} × {{ number_format($r['max_chars_per_doc'], 0, ',', '.') }} Z.)</span>@endif
                                        </p>
                                    @empty
                                        <p class="text-[11px] text-gray-400">—</p>
                                    @endforelse
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="6" class="{{ $td }} text-[11px] text-gray-400">Keine Zeile.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ── Routing-Editor ── --}}
    <div class="space-y-2" data-ws-routings>
        <div>
            <p class="{{ $dt }}">Routing — welches Feature sucht in welcher Kategorie</p>
            <p class="text-[11px] text-gray-500">
                <span class="font-medium">discovery</span> für wachsende Kategorien (gedeckelt) ·
                <span class="font-medium">always</span> nur für kleine feste Mengen ·
                <span class="font-medium">none</span> = bewusst leer · keine Zeile = search-only.
                Routings sind <span class="font-medium">global</span> und gelten für alle Teams.
            </p>
        </div>

        <table class="{{ $table }}">
            <thead><tr class="text-left">@foreach(['Feature', 'Kategorie', 'Modus', 'max Docs', 'Zeichen/Doc', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach($routings as $r)
                    <tr class="{{ $tr }}" wire:key="r-{{ $r->id }}">
                        @if($editId === $r->id)
                            <td class="{{ $td }} font-mono text-[11px] text-gray-500">{{ $r->feature }}</td>
                            <td class="{{ $td }} font-mono text-[11px] text-gray-500">{{ $r->category }}</td>
                            <td class="{{ $td }}">
                                <select wire:model="form.mode" class="{{ $input }} !py-1 w-32" data-ws-mode>
                                    @foreach(['always', 'discovery', 'grounding', 'none'] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                                </select>
                            </td>
                            <td class="{{ $td }}"><input type="text" wire:model="form.max_docs" class="{{ $input }} !py-1 !w-16 text-right" placeholder="—" /></td>
                            <td class="{{ $td }}"><input type="text" wire:model="form.max_chars_per_doc" class="{{ $input }} !py-1 !w-20 text-right" placeholder="—" /></td>
                            <td class="{{ $td }} whitespace-nowrap">
                                <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-ws-save>Speichern</button>
                                <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                            </td>
                        @else
                            <td class="{{ $td }} font-mono text-[11px] text-gray-900">
                                {{ $r->feature }}
                                @if(in_array($r->feature, $alias, true))
                                    <span class="text-[10px] text-amber-600" title="Alt-Schlüssel: dieses Feature versorgt Prompt-Keys mit anderem Namen">alt</span>
                                @endif
                            </td>
                            <td class="{{ $td }} font-mono text-[11px] text-gray-600">
                                {{ $r->category }}
                                @if(! in_array($r->category, $kategorien, true))
                                    <span class="text-[10px] text-rose-600" title="Diese Kategorie steht nicht im Vokabular — die Zeile steuert nichts">unbekannt</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-[11px] {{ $r->mode === 'none' ? 'text-gray-400' : 'text-gray-700' }}">{{ $r->mode }}</td>
                            <td class="{{ $td }} text-[11px] text-gray-600 text-right">{{ $r->max_docs ?? '—' }}</td>
                            <td class="{{ $td }} text-[11px] text-gray-600 text-right">{{ $r->max_chars_per_doc ? number_format($r->max_chars_per_doc, 0, ',', '.') : '—' }}</td>
                            <td class="{{ $td }} whitespace-nowrap">
                                @if($darfSchreiben)
                                    <button type="button" wire:click="edit({{ $r->id }})" class="{{ $btnGhostXs }}" data-ws-edit>Bearbeiten</button>
                                    <button type="button" wire:click="delete({{ $r->id }})" wire:confirm="Routing {{ $r->feature }} × {{ $r->category }} entfernen? Die Kategorie ist danach search-only." class="{{ $btnGhostXs }} text-red-500">Entfernen</button>
                                @else
                                    <span class="text-[10px] text-gray-400">global — nur Master</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($darfSchreiben)
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 space-y-1.5" data-ws-neu>
                <p class="{{ $dt }}">Neues Routing</p>
                <div class="flex flex-wrap items-start gap-2">
                    <input type="text" wire:model="form.feature" placeholder="Feature (z. B. recipe.geschmack)" class="{{ $input }} !py-1 w-56" data-ws-neu-feature />
                    <select wire:model="form.category" class="{{ $input }} !py-1 w-48" data-ws-neu-kategorie>
                        <option value="">Kategorie …</option>
                        @foreach($kategorien as $k)<option value="{{ $k }}">{{ $k }}</option>@endforeach
                    </select>
                    <select wire:model="form.mode" class="{{ $input }} !py-1 w-32">
                        @foreach(['always', 'discovery', 'grounding', 'none'] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="form.max_docs" placeholder="Docs" class="{{ $input }} !py-1 !w-16 text-right" />
                    <input type="text" wire:model="form.max_chars_per_doc" placeholder="Zeichen" class="{{ $input }} !py-1 !w-24 text-right" />
                    <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-ws-neu-anlegen>+ Setzen</button>
                </div>
            </div>
        @endif
    </div>
</div>
