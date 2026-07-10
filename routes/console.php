<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\SincronizarPedidosDiario;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SincronizarPedidosDiario::class)->dailyAt('08:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
