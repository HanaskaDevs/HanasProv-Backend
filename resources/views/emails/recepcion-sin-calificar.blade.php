{{--
  Alerta de escalación: hubo recepción y quedó sin calificar.

  Correo interno de gestión. Va al grano: qué proveedor, a qué hora entregó y
  qué falta. Franja vino (#772125) porque es el color que el portal usa para
  lo que requiere acción, no para lo informativo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recepción sin calificar</title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f4; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f4; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(20,40,49,0.08);">

        <tr>
          <td style="height:5px; background-color:#772125; line-height:5px; font-size:0;">&nbsp;</td>
        </tr>

        <tr>
          <td style="padding:28px 32px 4px 32px;">
            <p style="margin:0; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; color:#772125; font-weight:bold;">
              Portal de Proveedores Hanaska
            </p>
            <h1 style="margin:8px 0 0 0; font-size:19px; color:#142831;">
              @if(count($casos) === 1)
                Una recepción quedó sin calificar
              @else
                {{ count($casos) }} recepciones quedaron sin calificar
              @endif
            </h1>
            <p style="margin:8px 0 0 0; font-size:14px; color:#5A6875; line-height:1.6;">
              {{ $fecha }}. Estos proveedores ya entregaron y pasó
              {{ $horasDePlazo === 1 ? 'más de una hora' : "más de {$horasDePlazo} horas" }}
              sin que se registre la calificación de recepción en el portal.
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:20px 32px 4px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px; color:#142831;">
              <tr>
                <td style="padding:0 8px 8px 0; font-size:11px; letter-spacing:0.5px; text-transform:uppercase; color:#8b98a1; font-weight:bold;">Proveedor</td>
                <td style="padding:0 8px 8px 0; font-size:11px; letter-spacing:0.5px; text-transform:uppercase; color:#8b98a1; font-weight:bold;">Entregó</td>
                <td style="padding:0 0 8px 0; font-size:11px; letter-spacing:0.5px; text-transform:uppercase; color:#8b98a1; font-weight:bold;">Empresa</td>
              </tr>
              @foreach($casos as $caso)
                <tr>
                  <td style="padding:10px 8px 10px 0; border-top:1px solid #e3e9ec; vertical-align:top;">
                    <strong>{{ $caso['proveedor'] }}</strong>
                    @if($caso['ruc'])
                      <br><span style="font-size:12px; color:#8b98a1;">RUC {{ $caso['ruc'] }}</span>
                    @endif
                  </td>
                  <td style="padding:10px 8px 10px 0; border-top:1px solid #e3e9ec; vertical-align:top; white-space:nowrap;">
                    {{ $caso['hora_entrega'] }}
                    @if($caso['anden'])
                      <br><span style="font-size:12px; color:#8b98a1;">{{ $caso['anden'] }}</span>
                    @endif
                  </td>
                  <td style="padding:10px 0 10px 0; border-top:1px solid #e3e9ec; vertical-align:top; font-size:13px; color:#5A6875;">
                    {{ $caso['empresa'] }}
                  </td>
                </tr>
              @endforeach
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:14px 32px; background-color:#f4f7f8; border-top:1px solid #e3e9ec; margin-top:20px;">
            <p style="margin:0; font-size:11px; color:#8b98a1; line-height:1.6;">
              Aviso automático del Portal de Proveedores.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
