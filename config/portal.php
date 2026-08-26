<?php

/*
|--------------------------------------------------------------------------
| Datos del portal que son de NEGOCIO, no de infraestructura
|--------------------------------------------------------------------------
|
| Van en un config y no incrustados en un service porque son datos que
| cambian por decisión de la empresa (se va una persona, cambia un correo)
| y no debería hacer falta tocar código ni desplegar para eso.
|
| Los consume el asistente (Hana) para poder decirle a un proveedor a quién
| escribirle, y el footer para la información de contacto.
|
*/

return [

    /*
    | A quién derivar según el tipo de problema. Hana usa esto cuando el
    | usuario pide hablar con alguien; sin esta lista el modelo inventaría
    | una dirección de correo, que es exactamente lo que no queremos.
    */
    'contactos' => [
        'administracion' => [
            'etiqueta' => 'Administración',
            'email' => env('PORTAL_CONTACTO_ADMIN', 'mlopez@hanaska.com'),
            'para' => 'dudas sobre la calificación, aprobaciones, reclamos y temas comerciales',
        ],
        'sistemas' => [
            'etiqueta' => 'Sistemas',
            'emails' => array_filter(explode(',', (string) env(
                'PORTAL_CONTACTO_SISTEMAS',
                'iflores@hanaska.com,amanito@hanaska.com'
            ))),
            'para' => 'problemas técnicos: no puede entrar, un archivo no sube, algo del portal falla',
        ],
    ],

    /*
    | Delegado de Protección de Datos Personales. Es el destinatario del
    | formulario de atención de derechos y el contacto que se publica en la
    | política. La LOPDP exige que este canal exista y esté disponible.
    */
    /*
    | Feriados que NO se pueden derivar del calendario: los puentes que el
    | Ejecutivo traslada por decreto cada año (Ley de Optimización de
    | Feriados) y los feriados locales del cantón.
    |
    | Los nacionales fijos y los que dependen de Pascua (Viernes Santo y
    | Carnaval) ya los calcula App\Shared\FeriadosEcuador: acá van SOLO los
    | agregados. Formato Y-m-d.
    */
    'feriados_adicionales' => array_filter(
        explode(',', (string) env('PORTAL_FERIADOS_ADICIONALES', ''))
    ),

    /*
    | A quién se le escala cuando un proveedor entregó y pasó una hora sin
    | que nadie registre la calificación de esa recepción. Ver
    | AvisarRecepcionesSinCalificarCommand.
    |
    | Va en configuración y no en el comando porque es una lista de personas:
    | cambia cuando alguien entra o sale del equipo, y eso no debería
    | necesitar un despliegue.
    */
    'alertas_recepcion_sin_calificar' => array_filter(array_map('trim', explode(',', (string) env(
        'PORTAL_ALERTAS_RECEPCION',
        'apadilla@hanaska.com,vacurio@hanaska.com,amanito@hanaska.com'
    )))),

    /*
    | Cuánto se espera, después de marcada la entrega, antes de escalar.
    */
    'horas_para_alertar_recepcion' => (int) env('PORTAL_HORAS_ALERTA_RECEPCION', 1),

    'proteccion_datos' => [
        'email' => env('PORTAL_EMAIL_PROTECCION_DATOS', 'protecciondedatos@hanaska.com'),
    ],

];
