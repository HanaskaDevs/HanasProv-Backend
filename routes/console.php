<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\SincronizarPedidosDiario;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\CerrarPedidosVencidosCommand;
use App\Console\Commands\ActualizarCantidadesRecibidasCommand;
use App\Console\Commands\ReconciliarEstadosProveedoresCommand;
use App\Console\Commands\AvisarRecepcionesDelDiaCommand;
use App\Console\Commands\AvisarVencimientoDocumentosCommand;
use App\Console\Commands\SuspenderProveedoresDocumentacionVencidaCommand;

Schedule::command(SincronizarPedidosDiario::class)->dailyAt('08:00');
Schedule::command(CerrarPedidosVencidosCommand::class)->daily();
Schedule::command(ActualizarCantidadesRecibidasCommand::class)->everyThirtyMinutes();
Schedule::command(ReconciliarEstadosProveedoresCommand::class)->everyThirtyMinutes();
// Temprano y una sola vez al día: el aviso es "estos proveedores se
// auditan HOY", así que tiene que llegar antes de que empiecen a entrar
// las recepciones.
Schedule::command(AvisarRecepcionesDelDiaCommand::class)->dailyAt('07:00');
// Vencimiento de documentos: primero se avisa, y un rato después se
// evalúa a quién corresponde suspender -> en ese orden, para que el
// proveedor que hoy cumple 15 días de atraso reciba su último aviso antes
// de quedar suspendido, no después.
Schedule::command(AvisarVencimientoDocumentosCommand::class)->dailyAt('07:30');
Schedule::command(SuspenderProveedoresDocumentacionVencidaCommand::class)->dailyAt('08:30');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');