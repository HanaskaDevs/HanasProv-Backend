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

        {{-- Franja ámbar mientras falta, vino una vez vencido --}}
        <tr>
          <td style="height:5px; background-color:{{ $vencido ? '#772125' : '#E2DE3D' }}; line-height:5px; font-size:0;">&nbsp;</td>
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
            <p style="margin:0; font-size:19px; font-weight:bold; color:#142831;">
              @if ($vencido)
                Documento vencido
              @else
                Documento por vencer
              @endif
            </p>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:0 32px 16px 32px;">
            <p style="margin:0; font-size:13px; color:#6b7c82;">{{ $nombreProveedor }} · {{ $nombreEmpresa }}</p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 8px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7f8; border-radius:8px; border:1px solid #e2e8ea;">
              <tr>
                <td style="padding:16px;">
                  <p style="margin:0 0 10px 0; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7c82;">Documento</p>
                  <p style="margin:0 0 14px 0; font-size:15px; font-weight:bold; color:#142831;">{{ $nombreDocumento }}</p>

                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="font-size:13px; color:#6b7c82; padding:4px 0;">Fecha de caducidad</td>
                      <td align="right" style="font-size:14px; color:#142831; padding:4px 0;">{{ $fechaCaducidad }}</td>
                    </tr>
                    <tr>
                      <td style="font-size:13px; color:#6b7c82; padding:4px 0;">
                        @if ($vencido) Vencido hace @else Vence en @endif
                      </td>
                      <td align="right" style="font-size:14px; font-weight:bold; color:{{ $vencido ? '#772125' : '#142831' }}; padding:4px 0;">
                        {{ $diasRestantes }} {{ $diasRestantes === 1 ? 'día' : 'días' }}
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:12px 32px 0 32px;">
            @if ($vencido)
              <p style="margin:0; font-size:14px; line-height:1.6; color:#142831;">
                Este documento ya está vencido. Debe cargar la versión actualizada
                @if ($diasParaSuspension > 0)
                  dentro de los próximos <strong>{{ $diasParaSuspension }} {{ $diasParaSuspension === 1 ? 'día' : 'días' }}</strong>.
                @else
                  <strong>de inmediato</strong>.
                @endif
              </p>
              <p style="margin:10px 0 0 0; font-size:13px; line-height:1.6; color:#772125;">
                Pasados {{ $diasGracia }} días del vencimiento sin la documentación actualizada, el acceso del
                proveedor al portal queda suspendido y habrá que contactar al administrador para reactivarlo.
              </p>
            @else
              <p style="margin:0; font-size:14px; line-height:1.6; color:#142831;">
                Le recordamos cargar la versión actualizada antes de la fecha de caducidad, desde
                <strong>Documentación</strong> en el portal.
              </p>
              <p style="margin:10px 0 0 0; font-size:13px; line-height:1.6; color:#6b7c82;">
                Si el documento vence y pasan {{ $diasGracia }} días sin actualizarlo, el acceso del proveedor
                al portal queda suspendido.
              </p>
            @endif
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:24px 32px 24px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="border-radius:8px; background-color:#142831;">
                  <a href="{{ $urlDocumentos }}"
                     style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                    Cargar documento actualizado
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
              <a href="{{ $urlDocumentos }}" style="color:#274E61;">{{ $urlDocumentos }}</a>
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
