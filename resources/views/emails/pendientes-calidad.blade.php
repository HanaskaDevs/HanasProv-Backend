{{--
  Aviso a Calidad cuando Compras deja pasar productos (ver
  AvisoProductosService::avisarACalidad). Trae el panorama completo del
  proveedor en el orden en que se califica: 1. ficha, 2. documentos,
  3. productos. Mismo armazón visual que aviso-producto.blade.php.
--}}
@php
    // Colores de cada estado: los mismos de las insignias del portal.
    $colores = [
        'pendiente' => ['fondo' => '#f8f6c9', 'texto' => '#142831'],
        'incompleta' => ['fondo' => '#eceff1', 'texto' => '#4a5a63'],
        'rechazada' => ['fondo' => '#fdecc8', 'texto' => '#92400e'],
        'aprobada' => ['fondo' => '#d1fae5', 'texto' => '#065f46'],
    ];
    $totalProductos = count($productos['recien_aprobados']) + count($productos['otros_pendientes']);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Portal de Proveedores Hanaska</title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f4; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f4; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(20,40,49,0.08);">

        <tr>
          <td style="height:5px; background-color:#E2DE3D; line-height:5px; font-size:0;">&nbsp;</td>
        </tr>

        <tr>
          <td align="center" style="padding:32px 32px 8px 32px;">
            <img src="{{ $message->embed(public_path('images/logo-hanaska.png')) }}" alt="Hanaska" width="140" style="display:block; max-width:140px; height:auto;">
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:0 32px 24px 32px;">
            <p style="margin:0; font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#274E61; font-weight:bold;">
              Portal de Proveedores Hanaska
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 16px 0; font-size:15px; color:#142831;">Hola,</p>
            <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:#142831;">
              {{ $quienAprobo }} (Compras) aprobó
              {{ count($productos['recien_aprobados']) === 1 ? 'un producto' : count($productos['recien_aprobados']).' productos' }}
              de <strong>{{ $nombreProveedor }}</strong>. Abajo está todo lo que este proveedor tiene esperando a Calidad,
              en el orden en que se califica.
            </p>
          </td>
        </tr>

        {{-- Proveedor --}}
        <tr>
          <td style="padding:0 32px 12px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td style="padding:14px 16px;">
                  <p style="margin:0 0 4px 0; font-size:11px; letter-spacing:.5px; text-transform:uppercase; color:#274E61;">Proveedor</p>
                  <p style="margin:0; font-size:15px; font-weight:bold; color:#142831;">{{ $nombreProveedor }}</p>
                  @if ($rucProveedor)
                  <p style="margin:2px 0 0 0; font-size:13px; color:#5b6b74;">RUC {{ $rucProveedor }}</p>
                  @endif
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- 1 y 2: ficha y documentos, con la misma forma --}}
        @foreach ([['1. Ficha del proveedor', $ficha], ['2. Documentación', $documentos]] as [$titulo, $parte])
        <tr>
          <td style="padding:0 32px 12px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td style="padding:14px 16px;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="font-size:14px; font-weight:bold; color:#142831;">{{ $titulo }}</td>
                      <td align="right">
                        <span style="display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:bold; background-color:{{ $colores[$parte['estado']]['fondo'] }}; color:{{ $colores[$parte['estado']]['texto'] }};">
                          {{ $parte['etiqueta'] }}
                        </span>
                      </td>
                    </tr>
                  </table>
                  @if ($parte['detalle'])
                  <p style="margin:8px 0 0 0; font-size:13px; line-height:1.5; color:#5b6b74;">{{ $parte['detalle'] }}</p>
                  @endif
                  @if (! empty($parte['pendientes']))
                  <ul style="margin:8px 0 0 0; padding-left:18px; font-size:13px; line-height:1.6; color:#142831;">
                    @foreach ($parte['pendientes'] as $nombre)
                    <li>{{ $nombre }}</li>
                    @endforeach
                  </ul>
                  @endif
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endforeach

        {{-- 3: productos --}}
        <tr>
          <td style="padding:0 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td style="padding:14px 16px;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="font-size:14px; font-weight:bold; color:#142831;">3. Productos</td>
                      <td align="right">
                        <span style="display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:bold; background-color:{{ $colores['pendiente']['fondo'] }}; color:{{ $colores['pendiente']['texto'] }};">
                          {{ $totalProductos }} por calificar
                        </span>
                      </td>
                    </tr>
                  </table>

                  <p style="margin:10px 0 2px 0; font-size:11px; letter-spacing:.5px; text-transform:uppercase; color:#274E61;">Recién aprobados por Compras</p>
                  <ul style="margin:0; padding-left:18px; font-size:13px; line-height:1.6; color:#142831;">
                    @foreach ($productos['recien_aprobados'] as $nombre)
                    <li><strong>{{ $nombre }}</strong></li>
                    @endforeach
                  </ul>

                  @if (! empty($productos['otros_pendientes']))
                  <p style="margin:10px 0 2px 0; font-size:11px; letter-spacing:.5px; text-transform:uppercase; color:#274E61;">Ya estaban esperando a Calidad</p>
                  <ul style="margin:0; padding-left:18px; font-size:13px; line-height:1.6; color:#142831;">
                    @foreach ($productos['otros_pendientes'] as $nombre)
                    <li>{{ $nombre }}</li>
                    @endforeach
                  </ul>
                  @endif
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:24px 32px 8px 32px;">
            {{-- Tabla y no un <a> suelto: Outlook ignora el padding de los
                 enlaces y el botón sale como texto plano. --}}
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="background-color:#142831; border-radius:8px;">
                  <a href="{{ $urlAccion }}" style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none;">
                    Calificar a este proveedor
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:24px 32px 32px 32px;">
            <p style="margin:0; font-size:12px; line-height:1.6; color:#7c8a93;">
              Este es un aviso automático del Portal de Proveedores. No respondas a este correo.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
