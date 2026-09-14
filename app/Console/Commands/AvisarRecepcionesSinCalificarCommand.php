<?php

namespace App\Console\Commands;

use App\Modules\Auditorias\Mail\RecepcionSinCalificarMail;
use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Escala las recepciones que se entregaron y nadie calificó.
 *
 * LA CONDICIÓN, tal como la pidió el negocio: el proveedor ya tiene su
 * entrega del día marcada como ENTREGADA, pasó el plazo (una hora por
 * defecto) y no existe una calificación de recepción registrada para él ese
 * día. Ahí se avisa a las personas de
 * portal.alertas_recepcion_sin_calificar.
 *
 * POR QUÉ SE DISPARA DESDE LA ENTREGA MARCADA y no desde la hora agendada:
 * la hora del calendario es una previsión; "Marcado_Entregado_Por" es un
 * hecho, puesto a mano por Compras cuando la entrega terminó de verdad. Medir
 * el plazo desde ahí es lo que hace que la alerta signifique "hubo una
 * recepción real y quedó sin calificar".
 *
 * CORRE CADA HORA, no una vez al día: la condición se cumple una hora después
 * de cada entrega, y las entregas van de la madrugada a la tarde. Para no
 * repetir el mismo aviso en cada corrida, cada fila que se reporta queda
 * marcada con Alerta_Sin_Calificacion_Enviada.
 *
 * A quién NO se le manda: a Calidad. Calidad ya recibió el aviso de las 5 de
 * la mañana; esto es la escalación de que ese aviso no se atendió.
 */
class AvisarRecepcionesSinCalificarCommand extends Command
{
    protected $signature = 'auditorias:avisar-recepciones-sin-calificar';

    protected $description = 'Avisa cuando un proveedor entregó y pasó el plazo sin que se registre la calificación de esa recepción.';

    public function handle(): int
    {
        $destinatarios = (array) config('portal.alertas_recepcion_sin_calificar');

        if ($destinatarios === []) {
            $this->warn('No hay destinatarios configurados en portal.alertas_recepcion_sin_calificar. No se envía nada.');

            return self::SUCCESS;
        }

        $horas = (int) config('portal.horas_para_alertar_recepcion', 1);
        $hoy = now()->startOfDay();
        $limite = now()->subHours($horas);

        // Entregas de HOY ya marcadas como entregadas, con el plazo cumplido
        // y sin alerta previa.
        $entregas = HorarioEntregaEstadoDiario::query()
            ->whereRaw('CONVERT(date, Fecha) = CONVERT(date, ?)', [$hoy->toDateString()])
            ->whereNotNull('Hora_Entregado_Real')
            ->whereNull('Alerta_Sin_Calificacion_Enviada')
            ->where('Hora_Entregado_Real', '<=', $limite->format('Y-m-d\TH:i:s'))
            ->with('horario.proveedor', 'horario.empresa')
            ->get();

        if ($entregas->isEmpty()) {
            $this->line('Sin entregas cumplidas pendientes de calificar.');

            return self::SUCCESS;
        }

        // Quiénes YA tienen calificación registrada hoy. Se resuelve de una
        // sola vez para no consultar por cada entrega.
        $calificadosHoy = CalificacionRecepcion::whereRaw(
            'CONVERT(date, Fecha_Recepcion) = CONVERT(date, ?)',
            [$hoy->toDateString()]
        )->get(['Id_Proveedor', 'Id_Empresa'])
            ->map(fn ($c) => $c->Id_Empresa.'-'.$c->Id_Proveedor)
            ->all();

        $casos = [];
        $filasAvisadas = [];

        foreach ($entregas as $entrega) {
            $horario = $entrega->horario;
            $proveedor = $horario?->proveedor;

            if (! $horario || ! $proveedor) {
                // Horario borrado después de marcar la entrega: no hay a
                // quién nombrar en el correo, se marca para no reintentar.
                $filasAvisadas[] = $entrega->Id_Horario_Entrega_Estado_Diario;

                continue;
            }

            // Una calificación registrada por el proveedor ese día cubre a
            // TODAS sus entregas del día: el formulario es por recepción del
            // proveedor, no por franja del calendario.
            if (in_array($horario->Id_Empresa.'-'.$proveedor->Id_Proveedor, $calificadosHoy, true)) {
                $filasAvisadas[] = $entrega->Id_Horario_Entrega_Estado_Diario;

                continue;
            }

            $casos[] = [
                'proveedor' => $proveedor->Razon_Social ?: ($proveedor->Nombre_Comercial ?: 'Proveedor sin razón social'),
                'ruc' => $proveedor->Ruc,
                'empresa' => $horario->empresa?->Nombre_Comercial ?? $horario->empresa?->Razon_Social ?? 'Empresa',
                'hora_entrega' => $entrega->Hora_Entregado_Real?->format('H:i') ?? '—',
                'anden' => $horario->Anden_Puerta,
            ];

            $filasAvisadas[] = $entrega->Id_Horario_Entrega_Estado_Diario;
        }

        if ($casos === []) {
            // Todas estaban ya calificadas: solo se marcan para no volver a
            // revisarlas.
            $this->marcarAvisadas($filasAvisadas);
            $this->line('Todas las entregas cumplidas ya tenían su calificación.');

            return self::SUCCESS;
        }

        try {
            Mail::to($destinatarios)->send(new RecepcionSinCalificarMail(
                casos: $casos,
                fecha: now()->translatedFormat('l d \d\e F \d\e Y'),
                horasDePlazo: $horas,
            ));
        } catch (\Throwable $e) {
            // NO se marcan las filas: si el correo no salió, el próximo pase
            // (en una hora) tiene que volver a intentarlo. Marcarlas acá
            // enterraría la alerta para siempre.
            Log::error('No se pudo enviar la alerta de recepciones sin calificar', [
                'error' => $e->getMessage(),
                'casos' => count($casos),
            ]);
            $this->error('Falló el envío. Se reintenta en la próxima corrida.');

            return self::FAILURE;
        }

        $this->marcarAvisadas($filasAvisadas);

        $this->info(count($casos).' recepción(es) sin calificar reportada(s) a '.count($destinatarios).' destinatario(s).');

        return self::SUCCESS;
    }

    /** @param  list<int>  $ids */
    protected function marcarAvisadas(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        HorarioEntregaEstadoDiario::whereIn('Id_Horario_Entrega_Estado_Diario', $ids)
            ->update(['Alerta_Sin_Calificacion_Enviada' => now()->format('Y-m-d\TH:i:s')]);
    }
}
