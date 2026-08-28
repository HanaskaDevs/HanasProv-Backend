<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Antes NO existía este archivo, así que Laravel usaba su valor por defecto
| ('allowed_origins' => ['*']) y la API respondía a CUALQUIER sitio web.
| Comprobado enviando un preflight con Origin: https://sitio-malicioso.com
| -> contestaba 204 con Access-Control-Allow-Origin: *.
|
| Ahora la lista es explícita y sale del entorno, porque las URLs cambian
| entre el servidor de pruebas y producción y no deben estar escritas en el
| código.
|
| OJO CON LA APP ANDROID: Capacitor sirve la app desde el origen
| "http://localhost" (ver capacitor.config.ts), así que ese origen TIENE que
| estar en la lista o la app del celular deja de poder llamar a la API.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ORIGENES_PERMITIDOS',
            // Por defecto: el front de desarrollo (Vite), el front servido
            // por el propio servidor, y los dos orígenes de la app nativa.
            'http://localhost:5173,http://127.0.0.1:5173,http://localhost,https://localhost,capacitor://localhost'
        ))
    ))),

    'allowed_origins_patterns' => [],

    // La app manda el token en la cabecera Authorization y la empresa activa
    // en X-Empresa-Activa: las dos tienen que estar permitidas.
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Empresa-Activa'],

    'exposed_headers' => ['Content-Disposition'],

    // 24 h de caché del preflight: sin esto el navegador repite un OPTIONS
    // antes de cada petición y se duplica el tráfico contra la API.
    'max_age' => 86400,

    // false a propósito: la sesión NO va por cookies, va por token Bearer.
    // Ponerlo en true obligaría a listar orígenes exactos y no aporta nada acá.
    'supports_credentials' => false,

];
