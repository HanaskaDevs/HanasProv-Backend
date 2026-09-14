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
          <td align="center" style="padding:0 32px 8px 32px;">
            <p style="margin:0; font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#274E61; font-weight:bold;">
              Portal de Proveedores Hanaska
            </p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:8px 32px 4px 32px;">
            <p style="margin:0; font-size:19px; font-weight:bold; color:#142831;">Auditorías de recepción de hoy</p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:0 32px 16px 32px;">
            <p style="margin:0; font-size:13px; color:#6b7c82;">{{ ucfirst($fechaHoy) }} · {{ $nombreEmpresa }}</p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0 0 12px 0; font-size:14px; line-height:1.6; color:#142831;">
              @if (count($proveedores) === 1)
                Hoy corresponde hacer la <strong>evaluación de calidad en la recepción</strong> del siguiente proveedor:
              @else
                Hoy corresponde hacer la <strong>evaluación de calidad en la recepción</strong> de los siguientes {{ count($proveedores) }} proveedores:
              @endif
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td style="padding:16px;">
                  @foreach ($proveedores as $indice => $proveedor)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                           style="{{ $indice > 0 ? 'border-top:1px solid #e2e8ea; margin-top:12px; padding-top:12px;' : '' }}">
                      <tr>
                        <td style="padding:{{ $indice > 0 ? '12px 0 0 0' : '0' }};">
                          <p style="margin:0; font-size:15px; font-weight:bold; color:#142831;">{{ $proveedor['razon_social'] }}</p>
                          <p style="margin:2px 0 0 0; font-size:12px; color:#6b7c82;">
                            @if (! empty($proveedor['nombre_comercial'])){{ $proveedor['nombre_comercial'] }} · @endif
                            RUC {{ $proveedor['ruc'] ?? 'no registrado' }}
                          </p>
                        </td>
                      </tr>
                    </table>
                  @endforeach
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:8px 32px 0 32px;">
            <p style="margin:0; font-size:13px; line-height:1.6; color:#6b7c82;">
              La evaluación se llena en el portal, en <strong>Auditorías &rsaquo; Calificación de Recepciones</strong>,
              sobre el formulario FGH04.15.05-1 (200 puntos posibles).
            </p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:24px 32px 24px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="border-radius:8px; background-color:#142831;">
                  <a href="{{ $urlPantalla }}"
                     style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                    Ir a Calificación de Recepciones
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 32px 32px;">
            <p style="margin:0; font-size:11px; line-height:1.5; color:#9aa7ab; word-break:break-all;">
              Si tiene problemas con el botón, copie y pegue este enlace en su navegador:<br>
              <a href="{{ $urlPantalla }}" style="color:#274E61;">{{ $urlPantalla }}</a>
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
