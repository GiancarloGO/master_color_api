<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recordatorios push de visitas programadas (cita en los próximos 60 min).
Schedule::command('support:visit-reminders')->everyFiveMinutes();
