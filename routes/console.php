<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\SincronizarPedidosDiario;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\CerrarPedidosVencidosCommand;
use App\Console\Commands\ActualizarCantidadesRecibidasCommand;

Schedule::command(SincronizarPedidosDiario::class)->dailyAt('08:00');
Schedule::command(CerrarPedidosVencidosCommand::class)->daily();
Schedule::command(ActualizarCantidadesRecibidasCommand::class)->everyThirtyMinutes();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
