<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\SincronizarPedidosDiario;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\CerrarPedidosVencidosCommand;
use App\Console\Commands\ActualizarCantidadesRecibidasCommand;
use App\Console\Commands\ReconciliarEstadosProveedoresCommand;
use App\Console\Commands\AvisarRecepcionesDelDiaCommand;
use App\Console\Commands\AvisarRecepcionesSinCalificarCommand;
use App\Console\Commands\AvisarVencimientoDocumentosCommand;
use App\Console\Commands\SuspenderProveedoresDocumentacionVencidaCommand;

Schedule::command(SincronizarPedidosDiario::class)->dailyAt('08:00');
Schedule::command(CerrarPedidosVencidosCommand::class)->daily();
Schedule::command(ActualizarCantidadesRecibidasCommand::class)->everyThirtyMinutes();
Schedule::command(ReconciliarEstadosProveedoresCommand::class)->everyThirtyMinutes();
// 05:00, antes de que empiecen a entrar las recepciones: el aviso dice
// "estos proveedores entregan HOY y todavía deben su calificación del año",
// y Calidad tiene que tenerlo en la bandeja cuando llegue. La primera
// entrega del calendario es a las 05:30, así que 07:00 (como estaba antes)
// llegaba tarde.
Schedule::command(AvisarRecepcionesDelDiaCommand::class)->dailyAt('05:00');

// DESACTIVADO A PEDIDO (26-ago-2026): la alerta funciona y está probada, pero
// no se quieren estos correos por ahora. Se deja el comando registrado y
// ejecutable a mano:
//
//     php artisan auditorias:avisar-recepciones-sin-calificar
//
// Para reactivarlo, descomentar. CADA HORA y no una vez al día porque la
// condición ("entregó y pasó una hora sin que nadie califique") se cumple una
// hora después de cada entrega, y las entregas van desde las 05:30 hasta la
// tarde; el between() evita mandar correos de madrugada.
//
// Schedule::command(AvisarRecepcionesSinCalificarCommand::class)
//     ->hourly()
//     ->between('07:00', '20:00');
// Vencimiento de documentos: primero se avisa, y un rato después se
// evalúa a quién corresponde suspender -> en ese orden, para que el
// proveedor que hoy cumple 15 días de atraso reciba su último aviso antes
// de quedar suspendido, no después.
Schedule::command(AvisarVencimientoDocumentosCommand::class)->dailyAt('07:30');
Schedule::command(SuspenderProveedoresDocumentacionVencidaCommand::class)->dailyAt('08:30');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');