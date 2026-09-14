#!/usr/bin/env bash
#
# Worker permanente de la cola de correos.
#
# POR QUÉ HACE FALTA: desde que los correos se encolan (para que enviarlos no
# bloquee la petición, ver routes/console.php), quien los manda de verdad es
# un worker. El programador de tareas ya corre uno cada minuto como red de
# seguridad, pero eso hace que un correo de activación tarde hasta 60
# segundos en salir, y quien acaba de crear la cuenta lo está esperando en la
# bandeja AHORA. Con este proceso vivo, el correo sale en ~1 segundo.
#
# Uso:
#   ./iniciar-worker.sh          arranca el worker
#   ./iniciar-worker.sh estado   dice si está corriendo
#   ./iniciar-worker.sh parar    lo detiene
#
# OJO AL DESPLEGAR: este proceso mantiene el código en memoria. Después de
# actualizar el código hay que reiniciarlo, o seguirá ejecutando el viejo:
#   php artisan queue:restart

cd "$(dirname "$0")" || exit 1

ARCHIVO_PID="storage/logs/worker.pid"

# Se usa archivo de PID y no `pgrep` a propósito: un pgrep por el nombre del
# comando también encuentra al propio script (su línea de comando contiene el
# texto que busca) y da un falso positivo de "ya está corriendo".
esta_vivo() {
    [ -f "$ARCHIVO_PID" ] && kill -0 "$(cat "$ARCHIVO_PID")" 2>/dev/null
}

case "${1:-iniciar}" in
    estado)
        if esta_vivo; then echo "Corriendo (PID $(cat "$ARCHIVO_PID"))"; else echo "Detenido"; fi
        ;;
    parar)
        if esta_vivo; then
            pkill -P "$(cat "$ARCHIVO_PID")" 2>/dev/null
            kill "$(cat "$ARCHIVO_PID")" 2>/dev/null
            rm -f "$ARCHIVO_PID"
            echo "Worker detenido."
        else
            echo "No estaba corriendo."
        fi
        ;;
    *)
        if esta_vivo; then
            echo "El worker ya está corriendo (PID $(cat "$ARCHIVO_PID"))."
            exit 0
        fi
        # El bucle lo vuelve a levantar cuando --max-time lo recicla cada hora
        # (reciclarlo evita que acumule memoria en un proceso de días).
        nohup bash -c 'while true; do php artisan queue:work --sleep=1 --tries=3 --max-time=3600; sleep 2; done' \
            >> storage/logs/worker.log 2>&1 &
        echo $! > "$ARCHIVO_PID"
        echo "Worker iniciado (PID $!). Log en storage/logs/worker.log"
        ;;
esac
