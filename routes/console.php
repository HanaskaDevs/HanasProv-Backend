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
use App\Console\Commands\SincronizarEstadosSighCommand;

/*
|--------------------------------------------------------------------------
| Worker de la cola
|--------------------------------------------------------------------------
|
| Desde el 28-ago-2026 TODOS los correos del portal salen encolados (los
| Mailables y Notifications implementan ShouldQueue), porque enviarlos
| dentro de la petición dejaba al servidor bloqueado 4 segundos por correo
| y alcanzaba para tumbar el portal desde /auth/olvide-password.
|
| Encolar sin un worker que procese la cola es peor que no encolar: los
| correos se quedarían para siempre en la tabla `jobs` sin que nadie avise.
| Este comando es la red de seguridad -> como el cron ya corre
| `schedule:run` cada minuto, la cola se vacía como mucho un minuto después.
|
| --stop-when-empty: procesa lo pendiente y termina, no queda un proceso
| vivo. withoutOverlapping: si un envío se demora, la corrida siguiente no
| se le monta encima.
|
| EN PRODUCCIÓN conviene además un worker permanente con supervisor/systemd
| (`php artisan queue:work --tries=3`), que manda el correo en segundos en
| vez de esperar al minuto. Este seguiría igual, sin estorbar.
*/
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

/*
| Sanctum ya no emite tokens eternos (config/sanctum.php -> 24 h), pero los
| vencidos quedan en la tabla igual. Esto los limpia: sin ella la tabla
| crece sin techo y cada autenticación busca sobre más filas de las que
| hacen falta.
*/
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command(SincronizarPedidosDiario::class)->dailyAt('08:00');
Schedule::command(CerrarPedidosVencidosCommand::class)->daily();
Schedule::command(ActualizarCantidadesRecibidasCommand::class)->everyThirtyMinutes();
Schedule::command(ReconciliarEstadosProveedoresCommand::class)->everyThirtyMinutes();

// Calendario de horarios de entrega: refleja En_Recepcion/Recibido leyendo
// SIGH (192.168.1.135, solo lectura) -> cada minuto porque el Guardia y
// Calidad ven esto "como un tablero de aeropuerto" en vivo (ver
// HorarioEntregaService y SincronizarEstadosSighCommand). withoutOverlapping
// por si una corrida se demora más de un minuto (SIGH lento, etc.), no se
// amontonan corridas encima.
Schedule::command(SincronizarEstadosSighCommand::class)->everyMinute()->withoutOverlapping();
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