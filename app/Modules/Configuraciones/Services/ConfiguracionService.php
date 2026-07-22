<?php

namespace App\Modules\Configuraciones\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\BotRegla;
use App\Modules\Configuraciones\Models\Configuracion;
use App\Modules\Configuraciones\Models\GuiaPaso;
use App\Modules\Configuraciones\Models\HomeSlide;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConfiguracionService
{
    // Disco público: a diferencia de 'repositorio_proveedores' (privado,
    // solo detrás de login), este contenido se sirve en Landing/Login,
    // ANTES de cualquier autenticación -> debe ser accesible por URL directa.
    protected const DISCO_PUBLICO = 'public';

    protected function verificarSistemas(Usuario $usuario): void
    {
        if (! $usuario->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar configuraciones.');
        }
    }

    // ---------- Home Slides ----------

    public function listarSlides(): Collection
    {
        return HomeSlide::orderBy('Orden')->get();
    }

    public function crearSlide(Usuario $usuario, array $data, ?UploadedFile $media = null): HomeSlide
    {
        $this->verificarSistemas($usuario);

        $rutaMedia = null;
        $tipoMedia = null;

        if ($media) {
            [$rutaMedia, $tipoMedia] = $this->guardarMediaPublica($media, 'home');
        }

        return HomeSlide::create([
            'Orden' => $data['orden'] ?? (HomeSlide::max('Orden') + 1),
            'Eyebrow' => $data['eyebrow'],
            'Titulo' => $data['titulo'],
            'Descripcion' => $data['descripcion'],
            'Ruta_Media' => $rutaMedia,
            'Tipo_Media' => $tipoMedia,
            'Activo' => true,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ]);
    }

    public function actualizarSlide(Usuario $usuario, int $idSlide, array $data, ?UploadedFile $media = null): HomeSlide
    {
        $this->verificarSistemas($usuario);

        $slide = HomeSlide::findOrFail($idSlide);

        $cambios = [
            'Eyebrow' => $data['eyebrow'] ?? $slide->Eyebrow,
            'Titulo' => $data['titulo'] ?? $slide->Titulo,
            'Descripcion' => $data['descripcion'] ?? $slide->Descripcion,
            'Orden' => $data['orden'] ?? $slide->Orden,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ];

        if ($media) {
            $this->eliminarMediaFisica($slide->Ruta_Media);
            [$cambios['Ruta_Media'], $cambios['Tipo_Media']] = $this->guardarMediaPublica($media, 'home');
        }

        $slide->forceFill($cambios)->save();

        return $slide;
    }

    public function eliminarSlide(Usuario $usuario, int $idSlide): void
    {
        $this->verificarSistemas($usuario);

        $slide = HomeSlide::findOrFail($idSlide);
        $this->eliminarMediaFisica($slide->Ruta_Media);
        $slide->delete();
    }

    // ---------- Imagen de Login ----------

    public function obtenerImagenLogin(): ?string
    {
        return Configuracion::obtener('login_imagen_url');
    }

    public function actualizarImagenLogin(Usuario $usuario, UploadedFile $imagen): string
    {
        $this->verificarSistemas($usuario);

        $anterior = Configuracion::obtener('login_imagen_url');
        $this->eliminarMediaFisica($anterior);

        [$ruta] = $this->guardarMediaPublica($imagen, 'login');

        Configuracion::establecer('login_imagen_url', $ruta, $usuario->Id_Usuario);

        return $ruta;
    }

    // ---------- Bot Reglas ----------

    public function listarReglasBot(): Collection
    {
        return BotRegla::orderBy('Tipo')->orderBy('Orden')->get();
    }

    public function crearReglaBot(Usuario $usuario, array $data): BotRegla
    {
        $this->verificarSistemas($usuario);

        return BotRegla::create([
            'Tipo' => $data['tipo'],
            'Palabra_Clave' => $data['palabra_clave'] ?? null,
            'Contenido' => $data['contenido'],
            'Orden' => $data['orden'] ?? (BotRegla::where('Tipo', $data['tipo'])->max('Orden') + 1),
            'Activo' => true,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ]);
    }

    public function actualizarReglaBot(Usuario $usuario, int $idRegla, array $data): BotRegla
    {
        $this->verificarSistemas($usuario);

        $regla = BotRegla::findOrFail($idRegla);

        $regla->forceFill([
            'Palabra_Clave' => $data['palabra_clave'] ?? $regla->Palabra_Clave,
            'Contenido' => $data['contenido'] ?? $regla->Contenido,
            'Orden' => $data['orden'] ?? $regla->Orden,
            'Activo' => $data['activo'] ?? $regla->Activo,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        return $regla;
    }

    public function eliminarReglaBot(Usuario $usuario, int $idRegla): void
    {
        $this->verificarSistemas($usuario);

        BotRegla::findOrFail($idRegla)->delete();
    }

    // ---------- Guía de inicio ----------

    public function listarPasosGuia(): Collection
    {
        return GuiaPaso::orderBy('Orden')->get();
    }

    public function crearPasoGuia(Usuario $usuario, array $data): GuiaPaso
    {
        $this->verificarSistemas($usuario);

        return GuiaPaso::create([
            'Orden' => $data['orden'] ?? (GuiaPaso::max('Orden') + 1),
            'Target_Id' => $data['target_id'],
            'Titulo' => $data['titulo'],
            'Texto' => $data['texto'],
            'Activo' => true,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ]);
    }

    public function actualizarPasoGuia(Usuario $usuario, int $idPaso, array $data): GuiaPaso
    {
        $this->verificarSistemas($usuario);

        $paso = GuiaPaso::findOrFail($idPaso);

        $paso->forceFill([
            'Target_Id' => $data['target_id'] ?? $paso->Target_Id,
            'Titulo' => $data['titulo'] ?? $paso->Titulo,
            'Texto' => $data['texto'] ?? $paso->Texto,
            'Orden' => $data['orden'] ?? $paso->Orden,
            'Activo' => $data['activo'] ?? $paso->Activo,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        return $paso;
    }

    public function eliminarPasoGuia(Usuario $usuario, int $idPaso): void
    {
        $this->verificarSistemas($usuario);

        GuiaPaso::findOrFail($idPaso)->delete();
    }

    // ---------- Helpers de archivo público ----------

    protected function guardarMediaPublica(UploadedFile $archivo, string $carpeta): array
    {
        $extension = $archivo->getClientOriginalExtension();
        $nombreFisico = uniqid($carpeta . '_') . '.' . $extension;

        Storage::disk(self::DISCO_PUBLICO)->putFileAs($carpeta, $archivo, $nombreFisico);

        $tipoMedia = str_starts_with($archivo->getMimeType(), 'video') ? 'video' : 'imagen';
        $rutaPublica = '/storage/' . $carpeta . '/' . $nombreFisico;

        return [$rutaPublica, $tipoMedia];
    }

    protected function eliminarMediaFisica(?string $rutaPublica): void
    {
        if (! $rutaPublica) {
            return;
        }

        // La ruta pública empieza con /storage/... -> hay que quitar ese
        // prefijo para obtener la ruta real dentro del disco 'public'.
        $rutaDisco = ltrim(str_replace('/storage/', '', $rutaPublica), '/');

        if (Storage::disk(self::DISCO_PUBLICO)->exists($rutaDisco)) {
            Storage::disk(self::DISCO_PUBLICO)->delete($rutaDisco);
        }
    }
}
