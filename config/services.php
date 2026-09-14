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
    | Reemplaza a Groq. Se usa Claude Haiku 4.5 por ser el modelo económico
    | de la familia: para lo que hace Hana (responder sobre el estado real
    | del proveedor, con el dato ya servido en el contexto) no hace falta un
    | modelo de razonamiento.
    |
    | PRECIOS por millón de tokens. Están acá y no incrustados en el código
    | porque son un dato de negocio que cambia sin que cambie la lógica: si
    | Anthropic ajusta la tarifa, se corrige acá y el cálculo de gasto sigue
    | siendo correcto sin tocar el service.
    |
    | Los topes son duros: al alcanzarlos el asistente deja de llamar a la
    | API y responde con su texto de respaldo. Ver
    | AsistentePresupuestoService.
    |
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),

        // Tope de tokens de la RESPUESTA. 600 alcanza para una respuesta
        // conversacional completa; el costo de salida es 5x el de entrada,
        // así que este número es la palanca más directa sobre el gasto.
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 600),

        'precios_usd_por_millon' => [
            'entrada' => 1.00,
            'salida' => 5.00,
            // Escribir en caché cuesta 1.25x la entrada; leer de caché,
            // 0.10x. Se contabilizan aparte para que el costo registrado
            // coincida con la factura real.
            'cache_escritura' => 1.25,
            'cache_lectura' => 0.10,
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
