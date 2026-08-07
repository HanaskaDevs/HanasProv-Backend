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
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(20,40,49,0.08);">

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
            <p style="margin:0 0 16px 0; font-size:15px; color:#142831;">Hola {{ $nombreProveedor }},</p>
            <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:#142831;">
              Le informamos que su postulación como proveedor de <strong>{{ $nombreEmpresa }}</strong> fue
              <strong>rechazada</strong>. A continuación el detalle de lo que se rechazó y el motivo:
            </p>
          </td>
        </tr>

        @if(count($camposRechazados))
        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 8px 0; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7c82; font-weight:bold;">Ficha del proveedor</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea; margin-bottom:12px;">
              @foreach($camposRechazados as $campo)
              <tr>
                <td style="padding:10px 14px; border-bottom:1px solid #e2e8ea;">
                  <p style="margin:0; font-size:13px; font-weight:bold; color:#142831;">{{ $campo['nombre'] }}</p>
                  @if(!empty($campo['motivo']))
                  <p style="margin:4px 0 0 0; font-size:12px; color:#6b7c82;">{{ $campo['motivo'] }}</p>
                  @endif
                </td>
              </tr>
              @endforeach
            </table>
          </td>
        </tr>
        @endif

        @if(count($documentosRechazados))
        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 8px 0; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7c82; font-weight:bold;">Documentos</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea; margin-bottom:12px;">
              @foreach($documentosRechazados as $doc)
              <tr>
                <td style="padding:10px 14px; border-bottom:1px solid #e2e8ea;">
                  <p style="margin:0; font-size:13px; font-weight:bold; color:#142831;">{{ $doc['nombre'] }}</p>
                  @if(!empty($doc['motivo']))
                  <p style="margin:4px 0 0 0; font-size:12px; color:#6b7c82;">{{ $doc['motivo'] }}</p>
                  @endif
                </td>
              </tr>
              @endforeach
            </table>
          </td>
        </tr>
        @endif

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 8px 0; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7c82; font-weight:bold;">Productos</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea; margin-bottom:12px;">
              @if(count($productosRechazados))
                @foreach($productosRechazados as $producto)
                <tr>
                  <td style="padding:10px 14px; border-bottom:1px solid #e2e8ea;">
                    <p style="margin:0; font-size:13px; font-weight:bold; color:#142831;">{{ $producto['nombre'] }}</p>
                    @if(!empty($producto['motivo']))
                    <p style="margin:4px 0 0 0; font-size:12px; color:#6b7c82;">{{ $producto['motivo'] }}</p>
                    @endif
                  </td>
                </tr>
                @endforeach
              @else
                <tr>
                  <td style="padding:10px 14px;">
                    <p style="margin:0; font-size:13px; color:#142831;">Ningún producto quedó aprobado en esta revisión.</p>
                  </td>
                </tr>
              @endif
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:8px 32px 24px 32px;">
            <p style="margin:0; font-size:13px; line-height:1.6; color:#6b7c82;">
              Puede corregir lo señalado e ingresar nuevamente al portal para reenviar su postulación.
            </p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:20px 32px; background-color:#f5f7f8; border-top:1px solid #e2e8ea;">
            <p style="margin:0; font-size:11px; color:#9aa7ab;">
              © {{ date('Y') }} Hanaska · Portal de Proveedores
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
