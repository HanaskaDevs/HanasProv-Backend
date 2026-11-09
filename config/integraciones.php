<?php

/*
|--------------------------------------------------------------------------
| Integraciones máquina a máquina (sin usuario en el navegador)
|--------------------------------------------------------------------------
|
| POR QUÉ EXISTE: el login del portal exige el captcha de Cloudflare
| (ver config/turnstile.php) y bloquea cuentas e IPs por intentos fallidos.
| Eso está bien para personas, pero deja afuera a un sistema que necesita
| hablar con la API sin navegador, como Business Central. Para esos casos
| hay un TOKEN COMPARTIDO por integración: se configura acá (en el .env)
| y en el otro sistema, y viaja en la cabecera X-Integracion-Token.
|
| Cada integración actúa EN NOMBRE DE UN USUARIO INTERNO del portal
| (Sistemas o Admin): así los permisos y la auditoría (Creado_Por /
| Modificado_Por) siguen siendo los de siempre y no hay un camino aparte
| que se salte las reglas de negocio.
|
| Si el token está vacío la integración está APAGADA: la ruta responde 503
| y no se puede usar ni adivinando el usuario.
|
*/
return [

    'bc' => [

        /*
        | Token largo y aleatorio (p. ej. 48+ caracteres). El mismo valor se
        | guarda en Business Central (Configuración del calendario del portal
        | de proveedores, campo "Token de integración"). Rotarlo = cambiarlo
        | en los dos lados.
        */
        'token' => env('INTEGRACION_BC_TOKEN'),

        /*
        | Correo del usuario interno (Sistemas o Admin en la empresa) en cuyo
        | nombre se cargan los horarios que manda Business Central.
        */
        'usuario' => env('INTEGRACION_BC_USUARIO'),
    ],
];
