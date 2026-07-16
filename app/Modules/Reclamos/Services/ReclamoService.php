<?php

namespace App\Modules\Reclamos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Reclamos\Models\Reclamo;
use App\Modules\Reclamos\Models\ReclamoDestinatario;
use App\Modules\Reclamos\Models\ReclamoMensaje;
use App\Modules\Reclamos\Models\ReclamoMensajeImagen;
use App\Modules\Reclamos\Notifications\ReclamoNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReclamoService
{
    protected const DISCO = 'repositorio_proveedores';
    protected const MAX_IMAGENES = 5;

    /**
     * Listado para usuarios internos: todos los reclamos de la empresa activa,
     * cualquier rol interno puede verlos y crearlos (no hay restricción de rol).
     */
    public function listarInterno(Usuario $usuario, int $idEmpresaActiva, string $estado): Collection
    {
        $this->verificarEsInterno($usuario);

        return Reclamo::where('Id_Empresa', $idEmpresaActiva)
            ->where('Estado', $estado)
            ->where('Activo', 1)
            ->with(['proveedor', 'creadoPor', 'mensajes'])
            ->orderByDesc('Fecha_Creacion')
            ->get();
    }

    /**
     * Listado para el proveedor: solo sus propios reclamos.
     */
    public function listarProveedor(Usuario $usuario, int $idEmpresaActiva, string $estado): Collection
    {
        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        return Reclamo::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Estado', $estado)
            ->where('Activo', 1)
            ->with(['proveedor', 'creadoPor', 'mensajes'])
            ->orderByDesc('Fecha_Creacion')
            ->get();
    }

    public function detalle(Usuario $usuario, int $idEmpresaActiva, int $idReclamo): Reclamo
    {
        $reclamo = Reclamo::where('Id_Empresa', $idEmpresaActiva)
            ->with(['proveedor', 'creadoPor', 'destinatarios', 'mensajes.autor', 'mensajes.imagenes.archivo'])
            ->findOrFail($idReclamo);

        $this->verificarAcceso($usuario, $idEmpresaActiva, $reclamo);

        return $reclamo;
    }

    /**
     * Buscador de proveedores por razón social o RUC, para el paso 1 de crear reclamo.
     */
    public function buscarProveedores(int $idEmpresaActiva, string $termino): Collection
    {
        return Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->where(function ($q) use ($termino) {
                $q->where('Razon_Social', 'like', "%{$termino}%")
                    ->orWhere('Ruc', 'like', "%{$termino}%")
                    ->orWhere('Nombre_Comercial', 'like', "%{$termino}%");
            })
            ->limit(15)
            ->get();
    }

    /**
     * Crea el reclamo, guarda los destinatarios elegidos, crea el primer mensaje
     * (con imágenes) y dispara la notificación por correo a los destinatarios.
     *
     * $destinatarios = [['rol_contacto' => 'Ventas', 'nombre_contacto' => ?, 'email' => '...'], ...]
     */
    public function crear(
        Usuario $usuario,
        int $idEmpresaActiva,
        int $idProveedor,
        string $asunto,
        string $mensajeTexto,
        array $destinatarios,
        array $imagenes = []
    ): Reclamo {
        $this->verificarEsInterno($usuario);

        if (count($imagenes) > self::MAX_IMAGENES) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
            );
        }

        $proveedor = Proveedor::where('Id_Empresa', $idEmpresaActiva)->findOrFail($idProveedor);

        return DB::transaction(function () use ($usuario, $idEmpresaActiva, $proveedor, $asunto, $mensajeTexto, $destinatarios, $imagenes) {
            $reclamo = Reclamo::create([
                'Id_Empresa' => $idEmpresaActiva,
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Asunto' => $asunto,
                'Estado' => 'Abierto',
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
                'Activo' => 1,
            ]);

            foreach ($destinatarios as $destinatario) {
                ReclamoDestinatario::create([
                    'Id_Reclamo' => $reclamo->Id_Reclamo,
                    'Rol_Contacto' => $destinatario['rol_contacto'],
                    'Nombre_Contacto' => $destinatario['nombre_contacto'] ?? null,
                    'Email' => $destinatario['email'],
                ]);
            }

            $mensaje = $this->crearMensaje($usuario, $reclamo, $mensajeTexto, $imagenes);

            $this->notificar($reclamo, $mensaje, esMensajeInicial: true);

            return $reclamo->load('destinatarios', 'mensajes.imagenes.archivo');
        });
    }

    /**
     * Agrega una respuesta al hilo (de un usuario interno o del proveedor) y
     * notifica al lado contrario correspondiente.
     */
    public function responder(Usuario $usuario, int $idEmpresaActiva, int $idReclamo, string $texto, array $imagenes = []): ReclamoMensaje
    {
        $reclamo = Reclamo::where('Id_Empresa', $idEmpresaActiva)->findOrFail($idReclamo);

        $this->verificarAcceso($usuario, $idEmpresaActiva, $reclamo);

        if ($reclamo->Estado === 'Cerrado') {
            throw new AccessDeniedHttpException('Este reclamo ya está cerrado y no admite más respuestas.');
        }

        return DB::transaction(function () use ($usuario, $reclamo, $texto, $imagenes) {
            $mensaje = $this->crearMensaje($usuario, $reclamo, $texto, $imagenes);

            $this->notificar($reclamo, $mensaje, esMensajeInicial: false);

            return $mensaje->load('imagenes.archivo');
        });
    }

    /**
     * Solo el usuario que creó el reclamo puede cerrarlo.
     */
    public function cerrar(Usuario $usuario, int $idEmpresaActiva, int $idReclamo): Reclamo
    {
        $reclamo = Reclamo::where('Id_Empresa', $idEmpresaActiva)->findOrFail($idReclamo);

        if ((int) $reclamo->Creado_Por !== $usuario->Id_Usuario) {
            throw new AccessDeniedHttpException('Solo el usuario que creó el reclamo puede cerrarlo.');
        }

        $reclamo->forceFill([
            'Estado' => 'Cerrado',
            'Cerrado_Por' => $usuario->Id_Usuario,
            'Fecha_Cierre' => now(),
        ])->save();

        return $reclamo;
    }

    protected function crearMensaje(Usuario $usuario, Reclamo $reclamo, string $texto, array $imagenes): ReclamoMensaje
    {
        if (count($imagenes) > self::MAX_IMAGENES) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'imagenes' => ['Máximo ' . self::MAX_IMAGENES . ' imágenes por mensaje.'],
            ]);
        }

        $mensaje = ReclamoMensaje::create([
            'Id_Reclamo' => $reclamo->Id_Reclamo,
            'Id_Usuario_Autor' => $usuario->Id_Usuario,
            'Mensaje' => $texto,
            'Fecha_Creacion' => now(),
        ]);

        foreach ($imagenes as $imagen) {
            $this->guardarImagen($usuario, $reclamo, $mensaje, $imagen);
        }

        return $mensaje;
    }

    protected function guardarImagen(Usuario $usuario, Reclamo $reclamo, ReclamoMensaje $mensaje, UploadedFile $imagen): ReclamoMensajeImagen
    {
        $registroArchivo = Archivo::create([
            'Id_Proveedor' => $reclamo->Id_Proveedor,
            'Nombre_Original' => $imagen->getClientOriginalName(),
            'Ruta_Almacenamiento' => '',
            'Hash_Archivo' => hash_file('sha256', $imagen->getRealPath()),
            'Tipo_Mime' => $imagen->getMimeType(),
            'Tamano_Bytes' => $imagen->getSize(),
            'Categoria_Archivo' => 'reclamos',
            'Id_Usuario_Carga' => $usuario->Id_Usuario,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        $extension = $imagen->getClientOriginalExtension();
        $carpeta = "{$reclamo->Id_Empresa}/{$reclamo->Id_Proveedor}/reclamos/{$reclamo->Id_Reclamo}/{$mensaje->Id_Reclamo_Mensaje}";
        $nombreFisico = "{$registroArchivo->Id_Archivo}.{$extension}";

        Storage::disk(self::DISCO)->putFileAs($carpeta, $imagen, $nombreFisico);

        $registroArchivo->update(['Ruta_Almacenamiento' => "{$carpeta}/{$nombreFisico}"]);

        return ReclamoMensajeImagen::create([
            'Id_Reclamo_Mensaje' => $mensaje->Id_Reclamo_Mensaje,
            'Id_Archivo' => $registroArchivo->Id_Archivo,
            'Activo' => 1,
        ]);
    }

    /**
     * Resuelve a quién notificar según quién escribió el mensaje:
     * - Si escribe un interno -> se notifica a los Reclamo_Destinatario guardados.
     * - Si escribe el proveedor -> se notifica al usuario que creó el reclamo.
     */
    protected function notificar(Reclamo $reclamo, ReclamoMensaje $mensaje, bool $esMensajeInicial): void
    {
        $autor = $mensaje->autor ?? Usuario::find($mensaje->Id_Usuario_Autor);

        if ($autor->Tipo_Usuario === 'Proveedor') {
            $creador = $reclamo->creadoPor ?? Usuario::find($reclamo->Creado_Por);
            (new AnonymousNotifiable)
                ->route('mail', $creador->Email)
                ->notify(new ReclamoNotification($reclamo, $mensaje, $esMensajeInicial));

            return;
        }

        $emails = $reclamo->destinatarios()->pluck('Email');

        foreach ($emails as $email) {
            (new AnonymousNotifiable)
                ->route('mail', $email)
                ->notify(new ReclamoNotification($reclamo, $mensaje, $esMensajeInicial));
        }
    }

    protected function verificarEsInterno(Usuario $usuario): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos pueden crear reclamos.');
        }
    }

    protected function miProveedor(Usuario $usuario, int $idEmpresaActiva): Proveedor
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo usuarios externos (Proveedor) consultan sus propios reclamos.');
        }

        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario no tiene un Proveedor asociado a la empresa activa.');
        }

        return $proveedor;
    }

    protected function verificarAcceso(Usuario $usuario, int $idEmpresaActiva, Reclamo $reclamo): void
    {
        if ($usuario->Tipo_Usuario === 'Interno') {
            return;
        }

        $proveedor = $this->miProveedor($usuario, $idEmpresaActiva);

        if ((int) $reclamo->Id_Proveedor !== $proveedor->Id_Proveedor) {
            throw new AccessDeniedHttpException('No tiene acceso a este reclamo.');
        }
    }

    public function verImagen(Usuario $usuario, int $idEmpresaActiva, int $idReclamoMensajeImagen)
    {
        $imagen = \App\Modules\Reclamos\Models\ReclamoMensajeImagen::with('archivo', 'mensaje.reclamo')
            ->findOrFail($idReclamoMensajeImagen);

        $reclamo = $imagen->mensaje->reclamo;

        if ((int) $reclamo->Id_Empresa !== $idEmpresaActiva) {
            throw new AccessDeniedHttpException('Esta imagen no pertenece a la empresa activa.');
        }

        $this->verificarAcceso($usuario, $idEmpresaActiva, $reclamo);

        $rutaCompleta = Storage::disk(self::DISCO)->path($imagen->archivo->Ruta_Almacenamiento);

        if (! is_file($rutaCompleta)) {
            throw new NotFoundHttpException('El archivo físico no se encuentra en el repositorio.');
        }

        return response()->file($rutaCompleta, [
            'Content-Type' => $imagen->archivo->Tipo_Mime,
            'Content-Disposition' => 'inline; filename="' . $imagen->archivo->Nombre_Original . '"',
        ]);
    }
}
