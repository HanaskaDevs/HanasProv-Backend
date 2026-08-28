<?php

namespace App\Modules\Configuraciones\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\BotRegla;
use App\Modules\Configuraciones\Models\Configuracion;
use App\Modules\Configuraciones\Models\GuiaPaso;
use App\Modules\Configuraciones\Models\HomeSlide;
use App\Modules\Configuraciones\Models\Politica;
use App\Modules\Documentos_Proveedor\Services\VencimientoDocumentosService;
use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Shared\OptimizadorImagen;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConfiguracionService
{
    // Disco público: a diferencia de 'repositorio_proveedores'/'reclamos' (privados,
    // solo detrás de login), este contenido se sirve en Landing/Login,
    // ANTES de cualquier autenticación -> debe ser accesible por URL directa.
    // Vive físicamente en /var/repositorio/multimedia (ver REPOSITORIO_BASE_PATH),
    // expuesto vía symlink public/media (config/filesystems.php -> 'links').
    protected const DISCO_PUBLICO = 'multimedia';

    protected function verificarSistemas(Usuario $usuario): void
    {
        if (! $usuario->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar configuraciones.');
        }
    }

    /**
     * SQL Server (driver ODBC) a veces falla al convertir un datetime
     * enviado como parámetro nvarchar (SQLSTATE 22007, "fuera de intervalo"),
     * dependiendo de la configuración regional de la conexión. La forma
     * segura y ya probada en este proyecto (ver pedidos:cerrar-vencidos) es
     * forzar la conversión con CONVERT(datetime, ..., 120) como SQL crudo,
     * en vez de dejar que el driver adivine el formato.
     */
    protected function ahoraSql(): \Illuminate\Database\Query\Expression
    {
        return DB::raw("CONVERT(datetime, '" . now()->format('Y-m-d H:i:s') . "', 120)");
    }

    // ---------- Home Slides ----------

    public function listarSlides(): Collection
    {
        return HomeSlide::orderBy('Orden')->get()->each(function (HomeSlide $slide) {
            $slide->Ruta_Poster = $this->urlDelPoster($slide);
            $slide->Ruta_Media = $this->urlAbsolutaMedia($slide->Ruta_Media);
        });
    }

    /**
     * Imagen de primer cuadro de un video del home, si está en el repositorio
     * junto al archivo (mismo nombre, extensión .jpg).
     *
     * PARA QUÉ: la landing no muestra el video hasta que empieza a reproducir,
     * y hasta entonces se veía un degradado con un spinner. El video pesa unos
     * 450 KB y el poster unos 25 KB, así que con el poster la foto aparece casi
     * de inmediato y el video la reemplaza sin salto cuando termina de cargar.
     *
     * SE DERIVA DEL NOMBRE, NO SE GUARDA EN LA BASE: no hay columna para esto y
     * no hacía falta una, porque el nombre del archivo de video ya es único.
     * OJO: cuando Sistemas sube un video nuevo desde Configuraciones NO se
     * genera el poster (haría falta ffmpeg desde PHP), así que ese slide vuelve
     * al comportamiento anterior hasta que se le ponga el .jpg al lado.
     */
    protected function urlDelPoster(HomeSlide $slide): ?string
    {
        if ($slide->Tipo_Media !== 'video' || ! $slide->Ruta_Media) {
            return null;
        }

        $poster = preg_replace('/\.[A-Za-z0-9]+$/', '.jpg', $slide->Ruta_Media);

        if ($poster === null || $poster === $slide->Ruta_Media) {
            return null;
        }

        if (! Storage::disk(self::DISCO_PUBLICO)->exists($poster)) {
            return null;
        }

        return $this->urlAbsolutaMedia($poster);
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
            'Fecha_Modificacion' => $this->ahoraSql(),
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
            'Fecha_Modificacion' => $this->ahoraSql(),
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
        return $this->urlAbsolutaMedia(Configuracion::obtener('login_imagen_url'));
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
            'Fecha_Modificacion' => $this->ahoraSql(),
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
            'Fecha_Modificacion' => $this->ahoraSql(),
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
            'Fecha_Modificacion' => $this->ahoraSql(),
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
            'Fecha_Modificacion' => $this->ahoraSql(),
        ])->save();

        return $paso;
    }

    public function eliminarPasoGuia(Usuario $usuario, int $idPaso): void
    {
        $this->verificarSistemas($usuario);

        GuiaPaso::findOrFail($idPaso)->delete();
    }

    // ---------- Políticas ----------

    /** Admin: todas, para poder editar/reordenar/activar-desactivar. */
    public function listarPoliticas(): Collection
    {
        return Politica::orderBy('Orden')->get();
    }

    /** Sección Políticas dentro de la plataforma: solo las activas. */
    public function listarPoliticasActivas(): Collection
    {
        return Politica::where('Activo', true)->orderBy('Orden')->get();
    }

    public function crearPolitica(Usuario $usuario, array $data): Politica
    {
        $this->verificarSistemas($usuario);

        return Politica::create([
            'Orden' => $data['orden'] ?? (Politica::max('Orden') + 1),
            'Titulo' => $data['titulo'],
            'Descripcion' => $data['descripcion'],
            'Activo' => true,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => $this->ahoraSql(),
        ]);
    }

    public function actualizarPolitica(Usuario $usuario, int $idPolitica, array $data): Politica
    {
        $this->verificarSistemas($usuario);

        $politica = Politica::findOrFail($idPolitica);

        $politica->forceFill([
            'Titulo' => $data['titulo'] ?? $politica->Titulo,
            'Descripcion' => $data['descripcion'] ?? $politica->Descripcion,
            'Orden' => $data['orden'] ?? $politica->Orden,
            'Activo' => $data['activo'] ?? $politica->Activo,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => $this->ahoraSql(),
        ])->save();

        return $politica;
    }

    public function eliminarPolitica(Usuario $usuario, int $idPolitica): void
    {
        $this->verificarSistemas($usuario);

        Politica::findOrFail($idPolitica)->delete();
    }

    /**
     * Extrae el texto plano de un PDF subido desde el panel (para pegarlo
     * directo en el campo Descripción de una Política). El PDF en sí NUNCA
     * se guarda: solo se usa en memoria para sacar el texto y se descarta.
     */
    public function extraerTextoPdf(Usuario $usuario, UploadedFile $archivo): string
    {
        $this->verificarSistemas($usuario);

        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($archivo->getRealPath());
        $texto = $pdf->getText();

        $texto = preg_replace("/[ \t]+/", ' ', $texto);
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);

        return trim($texto);
    }

    // ---------- Suspensión automática por documentos vencidos ----------

    /**
     * Interruptor de la suspensión automática de proveedores con
     * documentación vencida. La clave y el valor por defecto los define
     * VencimientoDocumentosService (que es quien los consume) -> acá solo se
     * expone para la pantalla de Configuraciones, sin repetir el nombre de
     * la clave en dos lugares.
     *
     * Los AVISOS por correo no se pueden apagar desde acá a propósito:
     * avisar no rompe nada, suspender sí.
     */
    public function obtenerSuspensionAutomatica(): bool
    {
        return app(VencimientoDocumentosService::class)->suspensionAutomaticaActiva();
    }

    public function definirSuspensionAutomatica(Usuario $usuario, bool $activa): bool
    {
        $this->verificarSistemas($usuario);

        app(VencimientoDocumentosService::class)->definirSuspensionAutomatica($activa, $usuario->Id_Usuario);

        return $activa;
    }

    // ---------- Anuncios por voz del Modo TV ----------

    /**
     * Interruptor de los anuncios por voz del Modo TV del calendario de
     * entregas. La clave y el valor por defecto los define
     * HorarioEntregaService (que es quien los consume) -> acá solo se
     * expone para la pantalla de Configuraciones, mismo criterio que la
     * suspensión automática de arriba.
     *
     * LEER el interruptor NO pasa por acá: lo hace el propio Modo TV
     * contra /horarios-entrega/config-anuncios, porque quien mira la TV
     * suele ser el Guardia o Compras y no tiene acceso a Configuraciones.
     * Acá está solo la ESCRITURA, que sí es exclusiva de Sistemas.
     */
    public function obtenerAnunciosVoz(): bool
    {
        return app(HorarioEntregaService::class)->anunciosVozActivos();
    }

    public function definirAnunciosVoz(Usuario $usuario, bool $activa): bool
    {
        $this->verificarSistemas($usuario);

        app(HorarioEntregaService::class)->definirAnunciosVoz($activa, $usuario->Id_Usuario);

        return $activa;
    }

    // ---------- Helpers de archivo público ----------

    protected function guardarMediaPublica(UploadedFile $archivo, string $carpeta): array
    {
        $extension = $archivo->getClientOriginalExtension();
        $nombreFisico = uniqid($carpeta . '_') . '.' . $extension;

        Storage::disk(self::DISCO_PUBLICO)->putFileAs($carpeta, $archivo, $nombreFisico);

        $tipoMedia = str_starts_with($archivo->getMimeType(), 'video') ? 'video' : 'imagen';

        $rutaRelativa = $carpeta . '/' . $nombreFisico;

        // Las imágenes se reducen ACÁ, al subirlas, y no con un script de
        // una sola vez: el fondo de login que había pesaba 1.8 MB a
        // 2560x1440 y era lo primero que descargaba cualquiera que abriera
        // el portal. Optimizar la que ya estaba arregla el caso de hoy;
        // hacerlo en la subida arregla también la próxima.
        //
        // Los videos NO se tocan: recodificarlos en el request tomaría
        // minutos y dejaría al usuario esperando. Si hace falta, va en una
        // tarea en cola aparte.
        if ($tipoMedia === 'imagen') {
            app(OptimizadorImagen::class)->optimizarEnSitio(
                Storage::disk(self::DISCO_PUBLICO)->path($rutaRelativa)
            );
        }

        // OJO: antes acá se guardaba la URL ABSOLUTA (con host y puerto)
        // calculada en este momento con Storage::disk(...)->url() -> esa
        // URL quedaba "congelada" en la base para siempre. El día que
        // cambia el host o el puerto de la app (dev a otro puerto,
        // paso a producción, etc.), todo lo subido ANTES de ese cambio
        // quedaba roto (imagen/video apuntando a un host que ya no
        // corre ahí), porque la URL guardada nunca se vuelve a calcular.
        // Ahora se guarda solo la ruta relativa, y la URL absoluta se
        // arma de nuevo en cada lectura (ver urlAbsolutaMedia), con el
        // host/puerto ACTUAL, sin importar cuándo se subió el archivo.
        return [$rutaRelativa, $tipoMedia];
    }

    /**
     * Convierte una ruta relativa guardada en Ruta_Media/login_imagen_url
     * a una URL absoluta usando el host/puerto ACTUAL de la app -> se
     * llama siempre que se lee este dato, nunca al guardarlo.
     *
     * Compatibilidad hacia atrás: si el valor guardado ya es una URL
     * absoluta (registros de antes de este cambio), se devuelve tal
     * cual -> sigue apuntando al host viejo hasta que se vuelva a subir
     * ese archivo puntual, pero no rompe nada mientras tanto.
     */
    protected function urlAbsolutaMedia(?string $ruta): ?string
    {
        if (! $ruta) {
            return $ruta;
        }

        if (str_starts_with($ruta, 'http://') || str_starts_with($ruta, 'https://')) {
            return $ruta;
        }

        return Storage::disk(self::DISCO_PUBLICO)->url($ruta);
    }

    protected function eliminarMediaFisica(?string $rutaPublica): void
    {
        if (! $rutaPublica) {
            return;
        }

        // Compatibilidad con registros viejos que todavía tengan la URL
        // absoluta guardada (ver guardarMediaPublica) -> a partir de acá
        // ya es solo la ruta relativa, no hace falta parsear una URL.
        $rutaDisco = str_starts_with($rutaPublica, 'http://') || str_starts_with($rutaPublica, 'https://')
            ? ltrim(str_replace('/media/', '', parse_url($rutaPublica, PHP_URL_PATH) ?? $rutaPublica), '/')
            : $rutaPublica;

        if (Storage::disk(self::DISCO_PUBLICO)->exists($rutaDisco)) {
            Storage::disk(self::DISCO_PUBLICO)->delete($rutaDisco);
        }
    }
}