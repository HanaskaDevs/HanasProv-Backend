<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Asistente (Hana) — API de Claude
    |--------------------------------------------------------------------------
    |
    | Se usa Claude Sonnet 5.5. Antes era Haiku 4.5 (el económico), que
    | alcanzaba mientras Hana solo redactaba datos ya servidos en el
    | contexto; ahora consulta el portal con herramientas y tiene que
    | entender pedidos sueltos ("un Excel de los que no activaron la
    | cuenta"), y para eso hace falta un modelo que razone.
    |
    | PRECIOS por millón de tokens, POR MODELO. Están acá y no incrustados
    | en el código porque son un dato de negocio que cambia sin que cambie
    | la lógica. Y van por modelo para que cambiar ANTHROPIC_MODEL no deje
    | el tope de gasto calculando con la tarifa de otro: si se agrega un
    | modelo nuevo, se agrega su fila acá. Un modelo sin fila se calcula
    | con la tarifa más cara de la tabla (ver AsistentePresupuestoService).
    |
    | Los topes son duros: al alcanzarlos el asistente deja de llamar a la
    | API y responde con su texto de respaldo. Ver
    | AsistentePresupuestoService.
    |
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),

        // Cuánto razona el modelo: low | medium | high. medium es el punto
        // recomendado para conversación con varias consultas encadenadas;
        // low responde más rápido y gasta menos, pero entiende peor los
        // pedidos ambiguos.
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),

        // Tope de tokens de SALIDA por llamada, y el razonamiento cuenta
        // dentro de este tope aunque no se muestre. Con 600 (lo que
        // alcanzaba con Haiku sin razonamiento) el modelo se quedaría sin
        // espacio a mitad de pensar. Es un techo, no lo que se gasta: se
        // cobra solo lo que el modelo usa.
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8000),

        // Escribir en caché cuesta 1.25x la entrada; leer de caché, 0.10x.
        // Se contabilizan aparte para que el costo registrado coincida con
        // la factura real.
        'precios_usd_por_millon' => [
            'claude-sonnet-5-5' => ['entrada' => 2.00, 'salida' => 10.00, 'cache_escritura' => 2.50, 'cache_lectura' => 0.20],
            'claude-haiku-4-5' => ['entrada' => 1.00, 'salida' => 5.00, 'cache_escritura' => 1.25, 'cache_lectura' => 0.10],
        ],

        'limites_usd' => [
            'semanal' => (float) env('ASISTENTE_LIMITE_SEMANAL_USD', 5),
            'mensual' => (float) env('ASISTENTE_LIMITE_MENSUAL_USD', 20),
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | reCAPTCHA — protección del formulario de atención de derechos
    |--------------------------------------------------------------------------
    |
    | OPCIONAL. Si no hay claves, el formulario funciona igual y queda
    | protegido solo por el throttle de la ruta (3 envíos por IP cada 10
    | minutos). Se prefiere eso a dejar sin canal al titular que quiere
    | ejercer un derecho, que es una obligación legal.
    |
    | El umbral aplica a reCAPTCHA v3, que devuelve un score de 0 a 1.
    |
    */
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'score_minimo' => (float) env('RECAPTCHA_SCORE_MINIMO', 0.5),
    ],

];
