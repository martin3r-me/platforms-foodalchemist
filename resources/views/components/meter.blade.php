{{--
    FA Mess-Balken — dünner Fortschrittsbalken mit Ampel-Füllung + optionalen
    Schwellen-Ticks. Für Wareneinsatz (Gericht), Food-Cost-% (Paket/GP) etc.
    value/max in derselben Einheit; tone steuert die Füllfarbe (Ampel).
--}}
@props(['value' => 0, 'max' => 100, 'tone' => 'neutral', 'ticks' => []])
@php($pct = max(0.0, min(100.0, ((float) $value / max((float) $max, 0.0001)) * 100)))
@php($fill = ['neutral' => 'bg-gray-400', 'accent' => 'bg-violet-500', 'success' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'danger' => 'bg-rose-500'][$tone] ?? 'bg-gray-400')

<div {{ $attributes->merge(['class' => 'relative h-1.5 rounded-full bg-black/[0.06] overflow-hidden']) }}>
    <div class="absolute inset-y-0 left-0 rounded-full {{ $fill }}" style="width: {{ round($pct, 1) }}%"></div>
    @foreach($ticks as $t)
        <div class="absolute inset-y-0 w-px bg-black/25" style="left: {{ round(min(100.0, ((float) $t / max((float) $max, 0.0001)) * 100), 1) }}%"></div>
    @endforeach
</div>
