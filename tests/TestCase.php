<?php

namespace Platform\FoodAlchemist\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Basis-TestCase für alle Modul-Tests (M0-05).
 *
 * Bootet die Host-App, in deren vendor/ das Modul installiert ist
 * (lokal: sandbox-food-alchemist, später: office.bhg-Host-App).
 * Laravel ermittelt den App-Pfad selbst über den Composer-Autoloader —
 * deshalb hier bewusst kein createApplication()-Override.
 */
abstract class TestCase extends BaseTestCase
{
    //
}
