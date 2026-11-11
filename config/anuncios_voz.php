<?php

/*
|--------------------------------------------------------------------------
| Voz de los anuncios del Modo TV generada en el servidor (11-nov-2026)
|--------------------------------------------------------------------------
|
| POR QUÉ EN EL SERVIDOR. El Modo TV anunciaba con la voz del navegador
| (Web Speech). Los navegadores de Smart TV (Zeus y casi todos) son el
| WebView de Android, que no la trae: la TV de recepción no decía nada. Acá
| se genera el audio de cada frase con Piper (texto a voz, libre, sin
| internet ni cuentas) y la TV solo reproduce un WAV, que puede cualquier
| navegador. Las PCs con Chrome siguen usando su propia voz (ver
| anunciosVoz.ts en el frontend).
|
| INSTALACIÓN: /opt/piper (release 2023.11.14-2 de github.com/rhasspy/piper,
| piper_linux_x86_64.tar.gz) y la voz en /opt/piper/voces (es_MX-claude-high
| de huggingface.co/rhasspy/piper-voices, el .onnx y su .onnx.json).
|
*/
return [

    'piper' => env('ANUNCIOS_VOZ_PIPER', '/opt/piper/piper'),

    'modelo' => env('ANUNCIOS_VOZ_MODELO', '/opt/piper/voces/es_MX-claude-high.onnx'),

    /*
    | Más de 1 = más lento. Se escucha por parlante, en bodega y con ruido:
    | lo que hay que entender sí o sí es el nombre del proveedor. Mismo
    | criterio que VELOCIDAD_VOZ (0.82) de la voz del navegador.
    */
    'lentitud' => (float) env('ANUNCIOS_VOZ_LENTITUD', 1.15),

    /*
    | Cada frase se genera una vez y se guarda: "Proveedor X acaba de
    | arribar" se repite todos los días. Los archivos sin pedir en
    | 'dias_cache' días se borran (routes/console.php).
    */
    'carpeta' => storage_path('app/anuncios-voz'),

    'dias_cache' => 30,

    /* Una frase de anuncio real anda por los 60-90 caracteres. */
    'max_caracteres' => 200,

    'timeout' => 20,
];
