{{-- #1003 Handy-Oberfläche: in den <head> jeder FA-Hülle (Modul-Layout und Host food-alchemist.de).
     App auf dem Startbildschirm (PWA Stufe 1: Manifest, Icons, iOS-Vollbild; kein Service Worker) und die
     Handy-Entscheidung VOR dem ersten Zeichnen (<html data-fa-handy>, Support\HandyLesemodus::JS_MODUS),
     damit am Handy nichts kurz im Desktop-Aufbau aufblitzt. --}}
<link rel="manifest" href="{{ route('foodalchemist.handy_app.manifest') }}">
<meta name="theme-color" content="{{ \Platform\FoodAlchemist\Http\Controllers\HandyAppController::THEME_FARBE }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Food Alchemist">
<link rel="apple-touch-icon" href="{{ route('foodalchemist.handy_app.icon', ['name' => 'apple-touch-icon']) }}">
<script>{!! \Platform\FoodAlchemist\Support\HandyLesemodus::JS_MODUS !!}();</script>
