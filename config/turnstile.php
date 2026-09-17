<?php

/*
|--------------------------------------------------------------------------
| Cloudflare Turnstile (captcha invisible del login)
|--------------------------------------------------------------------------
|
| POR QUÉ TURNSTILE Y NO reCAPTCHA (decisión conversada, 15-sep-2026):
|
|  - NO PENALIZA IPs COMPARTIDAS. Todo el personal interno de Hanaska sale
|    a internet por una sola IP pública; reCAPTCHA v3 le baja el puntaje a
|    ese tipo de tráfico y habría empezado a molestar justamente a los
|    usuarios legítimos.
|  - NO MANDA DATOS DE LOS PROVEEDORES A GOOGLE, algo que importa teniendo
|    publicada una Política de Protección de Datos.
|  - Es pass/fail, sin puntaje que haya que calibrar.
|
| QUÉ PROTEGE DE VERDAD: el login ya frena la fuerza bruta por su cuenta
| (3 fallos bloquean la cuenta, 5 correos inexistentes bloquean la IP, ver
| AuthService). Lo que esto agrega es frenar al bot que INUNDA el endpoint:
| cada intento gasta un Hash::check, que es lento por diseño, así que unas
| pocas peticiones por segundo alcanzaban para dejar al portal sin CPU.
|
*/
return [

    /*
    | INTERRUPTOR DE EMERGENCIA.
    |
    | En false el login funciona exactamente como antes: no se pide token,
    | no se llama a Cloudflare, no se valida nada. Es lo que hay que tocar
    | si el captcha empieza a dejar gente afuera -una variable de entorno y
    | reiniciar, sin desplegar código-.
    |
    | Arranca APAGADO a propósito: así este cambio se puede subir a
    | producción sin activar nada el mismo día.
    */
    'habilitado' => env('TURNSTILE_HABILITADO', false),

    /*
    | La site key es PÚBLICA (viaja en el bundle del frontend, en
    | VITE_TURNSTILE_SITE_KEY). La secret es privada y solo la usa el
    | backend para llamar a siteverify: no debe estar en ninguna variable
    | que empiece con VITE_, porque todas esas terminan dentro del
    | JavaScript que baja el navegador.
    */
    'secret' => env('TURNSTILE_SECRET'),

    'url_verificacion' => env(
        'TURNSTILE_URL_VERIFICACION',
        'https://challenges.cloudflare.com/turnstile/v0/siteverify'
    ),

    /*
    | Segundos de espera de la llamada a Cloudflare.
    |
    | Corto a propósito: esta llamada está en el camino crítico del login.
    | Si Cloudflare tarda más que esto, se prefiere dejar pasar al usuario
    | (ver 'permitir_si_falla') antes que hacerlo esperar.
    */
    'timeout' => (int) env('TURNSTILE_TIMEOUT', 4),

    /*
    | QUÉ HACER SI NO SE PUEDE VERIFICAR (Cloudflare caído, sin salida a
    | internet, timeout).
    |
    | true = se deja entrar y se registra la advertencia en el log.
    |
    | Es la decisión correcta para un portal de proveedores: quedarse sin
    | acceso porque un tercero se cayó es peor que el riesgo que el captcha
    | mitiga -y el bloqueo por fuerza bruta de AuthService sigue activo de
    | todas formas, que es la defensa que de verdad protege las cuentas-.
    |
    | OJO: esto NO aplica a un token ausente o inválido. Ahí sí se rechaza:
    | un token que falta es un cliente que no hizo su parte, y dejarlo
    | pasar convertiría al captcha en decorativo.
    */
    'permitir_si_falla' => env('TURNSTILE_PERMITIR_SI_FALLA', true),

    /*
    | La acción que declara el frontend al pedir el token. Cloudflare la
    | devuelve en la respuesta y acá se comprueba que coincida -sin esto,
    | un token obtenido en otra pantalla del sitio serviría para el login-.
    */
    'accion_login' => env('TURNSTILE_ACCION_LOGIN', 'login'),

    /*
    | ¿Mandar la IP del cliente (remoteip) a siteverify?
    |
    | Es opcional para Cloudflare, y acá se APAGA por defecto (17-sep-2026):
    | el portal está detrás del proxy TLS de redes y todo el personal sale
    | por una IP compartida, así que la IP que ve Laravel no siempre es la
    | misma que vio Cloudflare al emitir el token. Si no coinciden, el token
    | puede ser rechazado aunque la persona sea legítima -y eso encaja con
    | los usuarios que necesitaban 4 o 5 intentos para entrar-. Cloudflare
    | ya evaluó la IP real al momento del desafío; mandarla otra vez no
    | agrega seguridad, solo una forma más de fallar.
    */
    'enviar_ip' => env('TURNSTILE_ENVIAR_IP', false),

];
