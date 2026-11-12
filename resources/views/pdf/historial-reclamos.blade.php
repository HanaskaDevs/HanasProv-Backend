{{--
    PDF del historial de reclamos (12-nov-2026).

    Sirve para un solo reclamo y para todos los de un proveedor: la vista
    recibe siempre una LISTA, así no hay dos plantillas que mantener.

    Las fotos llegan como data:URI en base64 (ver
    HistorialReclamosService::datosParaPdf): dompdf no puede leer el
    repositorio de archivos, que en el servidor vive fuera del proyecto.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Historial de reclamos</title>
    <style>
        @page { margin: 28px 32px 42px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #142831; }
        .cabecera { border-bottom: 2px solid #772125; padding-bottom: 8px; margin-bottom: 14px; }
        .titulo { font-size: 16px; font-weight: bold; margin: 0 0 2px 0; }
        .subtitulo { font-size: 10px; color: #6b7c82; margin: 0; }
        .proveedor { background: #f5f7f8; border: 1px solid #e2e8ea; border-radius: 6px; padding: 8px 10px; margin-bottom: 14px; }
        .proveedor strong { font-size: 12px; }
        .reclamo { border: 1px solid #e2e8ea; border-radius: 6px; margin-bottom: 14px; page-break-inside: avoid; }
        .reclamo-cab { background: #eef2f4; padding: 8px 10px; border-bottom: 1px solid #e2e8ea; }
        .reclamo-cab .asunto { font-size: 12px; font-weight: bold; margin: 0 0 3px 0; }
        .etiqueta { display: inline-block; font-size: 9px; padding: 1px 6px; border-radius: 8px; background: #274E61; color: #fff; margin-right: 4px; }
        .etiqueta.cerrado { background: #4b6b58; }
        .meta { color: #6b7c82; font-size: 9px; margin: 2px 0 0 0; }
        .mensajes { padding: 8px 10px; }
        .mensaje { border-left: 2px solid #d7dfe2; padding: 0 0 0 8px; margin: 0 0 10px 0; page-break-inside: avoid; }
        .mensaje .quien { font-weight: bold; font-size: 10px; margin: 0; }
        .mensaje .cuando { color: #9aa7ab; font-size: 9px; margin: 0 0 3px 0; }
        .mensaje .texto { margin: 0; white-space: pre-wrap; line-height: 1.45; }
        .fotos { margin-top: 6px; }
        .fotos img { max-width: 220px; max-height: 190px; border: 1px solid #e2e8ea; border-radius: 4px; margin: 0 6px 6px 0; }
        .sin-fotos { color: #9aa7ab; font-size: 9px; }
        .pie { position: fixed; bottom: -28px; left: 0; right: 0; text-align: center; font-size: 8px; color: #9aa7ab; }
    </style>
</head>
<body>

<div class="cabecera">
    <p class="titulo">Historial de reclamos</p>
    <p class="subtitulo">Portal de Proveedores Hanaska · generado el {{ $fechaGeneracion }} por {{ $generadoPor }}</p>
</div>

<div class="proveedor">
    <strong>{{ $proveedor['razon_social'] ?? 'Proveedor sin razón social' }}</strong><br>
    @if (! empty($proveedor['nombre_comercial'])){{ $proveedor['nombre_comercial'] }} · @endif
    RUC {{ $proveedor['ruc'] ?? 'no registrado' }}
    · {{ count($reclamos) }} {{ count($reclamos) === 1 ? 'reclamo' : 'reclamos' }}
</div>

@foreach ($reclamos as $reclamo)
    <div class="reclamo">
        <div class="reclamo-cab">
            <p class="asunto">#{{ $reclamo['id'] }} · {{ $reclamo['asunto'] }}</p>
            <span class="etiqueta {{ $reclamo['estado'] === 'Abierto' ? '' : 'cerrado' }}">{{ $reclamo['estado'] }}</span>
            @if (! empty($reclamo['tipo']))<span class="etiqueta">{{ $reclamo['tipo'] }}</span>@endif
            @if (! empty($reclamo['impacto']))<span class="etiqueta">Impacto: {{ $reclamo['impacto'] }}</span>@endif
            <p class="meta">
                Creado por {{ $reclamo['creado_por'] ?? 'usuario no registrado' }} el {{ $reclamo['fecha_creacion'] }}
                @if (! empty($reclamo['fecha_cierre'])) · cerrado el {{ $reclamo['fecha_cierre'] }} @endif
            </p>
            @if (! empty($reclamo['destinatarios']))
                <p class="meta">Notificado a: {{ implode(' · ', $reclamo['destinatarios']) }}</p>
            @endif
        </div>

        <div class="mensajes">
            @forelse ($reclamo['mensajes'] as $mensaje)
                <div class="mensaje">
                    <p class="quien">{{ $mensaje['autor'] }}</p>
                    <p class="cuando">{{ $mensaje['fecha'] }}</p>
                    <p class="texto">{{ $mensaje['texto'] }}</p>

                    @if (! empty($mensaje['imagenes']))
                        <div class="fotos">
                            @foreach ($mensaje['imagenes'] as $imagen)
                                <img src="{{ $imagen }}" alt="Imagen del mensaje">
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="sin-fotos">Este reclamo no tiene mensajes registrados.</p>
            @endforelse
        </div>
    </div>
@endforeach

<div class="pie">Documento generado desde el Portal de Proveedores Hanaska · {{ $fechaGeneracion }}</div>

</body>
</html>
