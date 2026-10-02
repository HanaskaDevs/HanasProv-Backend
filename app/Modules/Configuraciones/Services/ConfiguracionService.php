<?php

namespace App\Modules\Configuraciones\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\BannerInformativo;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConfiguracionService
{
    // Disco público: a diferencia de 'repositorio_proveedores'/'reclamos' (privados,
    // solo detrás de login), este contenido se sirve en Landing/Login,
    // ANTES de cualquier autenticación -> debe ser accesible por URL directa.
    // Vive físicamente en /var/repositorio/multimedia (ver REPOSITORIO_BASE_PATH),
    // expuesto vía symlink public/media (config/filesystems.php -> 'links').
    protected const DISCO_PUBLICO = 'multimedia';

    /** Clave en la tabla Configuracion del video tutorial del proveedor. */
    protected const CLAVE_VIDEO_TUTORIAL = 'video_tutorial_url';

    /** Interruptor, textos y versión del banner informativo. */
    protected const CLAVE_BANNER_ACTIVO = 'banner_informativo_activo';

    protected const CLAVE_BANNER_TITULO = 'banner_informativo_titulo';

    protected const CLAVE_BANNER_MENSAJE = 'banner_informativo_mensaje';

    protected const CLAVE_BANNER_VERSION = 'banner_informativo_version';

    /** A quién se le muestra: todos, internos o proveedores. */
    protected const CLAVE_BANNER_AUDIENCIA = 'banner_informativo_audiencia';

    /** Cada cuánto vuelve a aparecer: una_vez o siempre (en cada inicio de sesión). */
    protected const CLAVE_BANNER_FRECUENCIA = 'banner_informativo_frecuencia';

    public const AUDIENCIAS_BANNER = ['todos', 'internos', 'proveedores'];

    public const FRECUENCIAS_BANNER = ['una_vez', 'siempre'];

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
            // GUARDAR PRIMERO, BORRAR DESPUÉS. Antes era al revés: si la
            // escritura del archivo nuevo fallaba, el slide se quedaba sin
            // ninguno de los dos y el carrusel del home perdía esa imagen.
            $anterior = $slide->Ruta_Media;

            [$cambios['Ruta_Media'], $cambios['Tipo_Media']] = $this->guardarMediaPublica($media, 'home');

            $this->eliminarMediaFisica($anterior);
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

        // GUARDAR PRIMERO, BORRAR DESPUÉS. Antes se borraba la imagen anterior
        // antes de escribir la nueva: si la escritura fallaba, el portal se
        // quedaba sin fondo de login y sin forma de recuperarlo.
        // Si guardarMediaPublica lanza, la anterior sigue en su sitio y la
        // configuración sigue apuntando a ella.
        [$ruta] = $this->guardarMediaPublica($imagen, 'login');

        Configuracion::establecer('login_imagen_url', $ruta, $usuario->Id_Usuario);

        $this->eliminarMediaFisica($anterior);

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

    // ---------- Banner informativo ----------

    /**
     * El banner que ve cualquier usuario al iniciar sesión.
     *
     * LA VERSIÓN ES LA PIEZA CLAVE. El banner se cierra con la X y no
     * tiene que volver a aparecer en cada pantalla, pero SÍ tiene que
     * volver a aparecer cuando Sistemas lo cambia. La versión es una marca
     * de tiempo que se renueva con cualquier edición: el navegador recuerda
     * cuál cerró y, si la versión cambió, lo muestra de nuevo. Sin esto,
     * la única forma de que un aviso nuevo llegara a quien ya cerró el
     * anterior sería pedirle que borre los datos del navegador.
     *
     * Cuando está apagado se devuelve lo mínimo y NO se consultan las
     * piezas: esto lo pide cada usuario al entrar, y el caso normal es
     * que esté apagado.
     *
     * @return array<string, mixed>
     */
    public function obtenerBannerInformativo(bool $incluirInactivas = false, ?Usuario $para = null): array
    {
        $activo = Configuracion::obtener(self::CLAVE_BANNER_ACTIVO, '0') === '1';
        $audiencia = Configuracion::obtener(self::CLAVE_BANNER_AUDIENCIA, 'todos');

        /*
         * LA AUDIENCIA SE FILTRA ACÁ, NO EN LA PANTALLA. A quien no le
         * corresponde el aviso no se le manda: ni el texto, ni las URLs de
         * las imágenes. Resolverlo en el frontend significaría que el
         * contenido igual viajó y basta mirar la respuesta para leerlo, y
         * un aviso interno puede decir cosas que un proveedor no tiene por
         * qué ver.
         */
        if ($activo && $para !== null && ! $this->leCorrespondeElBanner($para, $audiencia)) {
            $activo = false;
        }

        /*
         * ENCENDIDO PERO VACÍO NO ES ENCENDIDO, para quien lo consume.
         * Si se borra la última imagen y no quedan título ni mensaje, no
         * hay nada que mostrar: el modal dibujaba una tarjeta vacía sobre
         * la pantalla oscurecida y parecía la página colgada (pasó al
         * borrar una pieza desde Configuraciones).
         *
         * La pantalla de administración NO pasa por acá -usa
         * incluirInactivas-, así que sigue viendo el interruptor en su
         * estado real y puede avisar "encendido, pero sin piezas".
         */
        if ($activo && ! $incluirInactivas && ! $this->bannerTieneContenido()) {
            $activo = false;
        }

        if (! $activo && ! $incluirInactivas) {
            return [
                'activo' => false,
                'titulo' => null,
                'mensaje' => null,
                'version' => null,
                'audiencia' => null,
                'frecuencia' => null,
                'piezas' => [],
            ];
        }

        $piezas = BannerInformativo::query()
            ->when(! $incluirInactivas, fn ($query) => $query->where('Activo', 1))
            ->orderBy('Orden')
            ->get()
            ->map(fn (BannerInformativo $pieza) => [
                'id_banner_informativo' => $pieza->Id_Banner_Informativo,
                'orden' => $pieza->Orden,
                'titulo' => $pieza->Titulo,
                'descripcion' => $pieza->Descripcion,
                'tipo_media' => $pieza->Tipo_Media,
                'url_media' => $this->urlAbsolutaMedia($pieza->Ruta_Media),
                'activo' => (bool) $pieza->Activo,
            ])
            ->values()
            ->all();

        return [
            'activo' => $activo,
            'titulo' => Configuracion::obtener(self::CLAVE_BANNER_TITULO),
            'mensaje' => Configuracion::obtener(self::CLAVE_BANNER_MENSAJE),
            'version' => Configuracion::obtener(self::CLAVE_BANNER_VERSION),
            'audiencia' => $audiencia,
            'frecuencia' => Configuracion::obtener(self::CLAVE_BANNER_FRECUENCIA, 'una_vez'),
            'piezas' => $piezas,
        ];
    }

    /** ¿Hay al menos una pieza activa con archivo, o algún texto? */
    protected function bannerTieneContenido(): bool
    {
        $hayPiezas = BannerInformativo::query()
            ->where('Activo', 1)
            ->whereNotNull('Ruta_Media')
            ->exists();

        if ($hayPiezas) {
            return true;
        }

        return trim((string) Configuracion::obtener(self::CLAVE_BANNER_TITULO)) !== ''
            || trim((string) Configuracion::obtener(self::CLAVE_BANNER_MENSAJE)) !== '';
    }

    /**
     * "Internos" es el personal de Hanaska; "proveedores", los externos.
     * La distinción sale de Usuario.Tipo_Usuario, que es el mismo campo
     * con el que el portal decide todo lo demás.
     */
    protected function leCorrespondeElBanner(Usuario $usuario, string $audiencia): bool
    {
        return match ($audiencia) {
            'internos' => $usuario->Tipo_Usuario === 'Interno',
            'proveedores' => $usuario->Tipo_Usuario === 'Proveedor',
            default => true,
        };
    }

    /** Interruptor y textos generales. */
    public function guardarBannerInformativo(Usuario $usuario, array $datos): array
    {
        $this->verificarSistemas($usuario);

        $validados = validator($datos, [
            'activo' => ['required', 'boolean'],
            'titulo' => ['nullable', 'string', 'max:200'],
            'mensaje' => ['nullable', 'string', 'max:1000'],
            'audiencia' => ['required', Rule::in(self::AUDIENCIAS_BANNER)],
            'frecuencia' => ['required', Rule::in(self::FRECUENCIAS_BANNER)],
        ])->validate();

        Configuracion::establecer(self::CLAVE_BANNER_ACTIVO, $validados['activo'] ? '1' : '0', $usuario->Id_Usuario);
        Configuracion::establecer(self::CLAVE_BANNER_TITULO, (string) ($validados['titulo'] ?? ''), $usuario->Id_Usuario);
        Configuracion::establecer(self::CLAVE_BANNER_MENSAJE, (string) ($validados['mensaje'] ?? ''), $usuario->Id_Usuario);
        Configuracion::establecer(self::CLAVE_BANNER_AUDIENCIA, $validados['audiencia'], $usuario->Id_Usuario);
        Configuracion::establecer(self::CLAVE_BANNER_FRECUENCIA, $validados['frecuencia'], $usuario->Id_Usuario);

        $this->renovarVersionBanner($usuario);

        return $this->obtenerBannerInformativo(incluirInactivas: true);
    }

    public function crearPiezaBanner(Usuario $usuario, array $datos, ?UploadedFile $media = null): BannerInformativo
    {
        $this->verificarSistemas($usuario);

        if (! $media) {
            throw ValidationException::withMessages([
                'media' => ['Sube una imagen o un video para esta pieza del banner.'],
            ]);
        }

        [$rutaMedia, $tipoMedia] = $this->guardarMediaPublica($media, 'banner');

        $pieza = BannerInformativo::create([
            'Orden' => $datos['orden'] ?? ((int) BannerInformativo::max('Orden') + 1),
            'Titulo' => $datos['titulo'] ?? null,
            'Descripcion' => $datos['descripcion'] ?? null,
            'Ruta_Media' => $rutaMedia,
            'Tipo_Media' => $tipoMedia,
            'Activo' => true,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => $this->ahoraSql(),
        ]);

        $this->renovarVersionBanner($usuario);

        return $pieza;
    }

    public function actualizarPiezaBanner(
        Usuario $usuario,
        int $idPieza,
        array $datos,
        ?UploadedFile $media = null
    ): BannerInformativo {
        $this->verificarSistemas($usuario);

        $pieza = BannerInformativo::findOrFail($idPieza);

        $cambios = [
            'Titulo' => array_key_exists('titulo', $datos) ? $datos['titulo'] : $pieza->Titulo,
            'Descripcion' => array_key_exists('descripcion', $datos) ? $datos['descripcion'] : $pieza->Descripcion,
            'Orden' => $datos['orden'] ?? $pieza->Orden,
            'Activo' => array_key_exists('activo', $datos) ? (bool) $datos['activo'] : $pieza->Activo,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => $this->ahoraSql(),
        ];

        if ($media) {
            // Guardar primero, borrar después: si la escritura del nuevo
            // falla, la pieza no puede quedarse sin ninguno de los dos
            // (mismo criterio que actualizarSlide).
            $anterior = $pieza->Ruta_Media;

            [$cambios['Ruta_Media'], $cambios['Tipo_Media']] = $this->guardarMediaPublica($media, 'banner');

            $this->eliminarMediaFisica($anterior);
        }

        $pieza->forceFill($cambios)->save();

        $this->renovarVersionBanner($usuario);

        return $pieza;
    }

    public function eliminarPiezaBanner(Usuario $usuario, int $idPieza): void
    {
        $this->verificarSistemas($usuario);

        $pieza = BannerInformativo::findOrFail($idPieza);

        $this->eliminarMediaFisica($pieza->Ruta_Media);
        $pieza->delete();

        $this->renovarVersionBanner($usuario);
    }

    /**
     * Renueva la marca de versión. Se llama ante CUALQUIER cambio -el
     * interruptor, un texto, una pieza nueva, una borrada- porque desde
     * el navegador no hay forma de saber qué cambió: solo si la versión
     * que cerró sigue siendo la vigente.
     */
    protected function renovarVersionBanner(Usuario $usuario): void
    {
        Configuracion::establecer(self::CLAVE_BANNER_VERSION, (string) now()->getTimestamp(), $usuario->Id_Usuario);
    }

    // ---------- Video tutorial para proveedores ----------

    /**
     * URL del video de YouTube que el proveedor ve desde "Ver video
     * tutorial" en su panel (01-oct-2026).
     *
     * SE GUARDA LA URL TAL COMO LA PEGA EL ADMINISTRADOR y, aparte, se
     * devuelve el id ya extraído. Así el administrador vuelve a ver en el
     * formulario exactamente lo que escribió (si guardáramos solo el id,
     * la próxima vez que abra la pantalla encontraría otra cosa), y el
     * frontend no tiene que volver a parsear nada: recibe la URL para el
     * enlace "Abrir en YouTube" y la de embed para el iframe.
     *
     * El parseo vive SOLO acá. Hacerlo también en el frontend sería tener
     * dos reglas distintas sobre qué es una URL válida, y la del navegador
     * no es la que valida al guardar.
     *
     * @return array{url: ?string, video_id: ?string, url_embed: ?string}
     */
    public function obtenerVideoTutorial(): array
    {
        $url = Configuracion::obtener(self::CLAVE_VIDEO_TUTORIAL);

        if ($url === null || trim($url) === '') {
            return ['url' => null, 'video_id' => null, 'url_embed' => null];
        }

        $videoId = self::idDeVideoYoutube($url);

        return [
            'url' => $url,
            'video_id' => $videoId,
            // rel=0 para que al terminar no ofrezca videos de otros
            // canales, que en un tutorial corporativo queda pésimo.
            'url_embed' => $videoId ? "https://www.youtube.com/embed/{$videoId}?rel=0" : null,
        ];
    }

    /**
     * Guarda la URL. Una cadena vacía BORRA la configuración -> es la
     * forma de apagar el botón sin tener que tocar código ni desplegar.
     *
     * @return array{url: ?string, video_id: ?string, url_embed: ?string}
     */
    public function definirVideoTutorial(Usuario $usuario, ?string $url): array
    {
        $this->verificarSistemas($usuario);

        $url = trim((string) $url);

        if ($url === '') {
            Configuracion::establecer(self::CLAVE_VIDEO_TUTORIAL, '', $usuario->Id_Usuario);

            return ['url' => null, 'video_id' => null, 'url_embed' => null];
        }

        if (self::idDeVideoYoutube($url) === null) {
            throw ValidationException::withMessages([
                'url' => ['Pega el enlace de un video de YouTube (youtube.com/watch?v=... o youtu.be/...).'],
            ]);
        }

        Configuracion::establecer(self::CLAVE_VIDEO_TUTORIAL, $url, $usuario->Id_Usuario);

        return $this->obtenerVideoTutorial();
    }

    /**
     * Id del video dentro de una URL de YouTube, o null si no es una.
     *
     * Se contemplan las cuatro formas con las que alguien llega pegando
     * desde el navegador o desde el botón "Compartir": el watch de
     * siempre, el enlace corto youtu.be, el /embed/ (por si pega uno ya
     * armado) y /shorts/. Un id de YouTube son 11 caracteres de
     * [A-Za-z0-9_-]; cualquier cosa que no encaje se rechaza al guardar,
     * para no descubrir el error recién cuando un proveedor abre el modal
     * y ve un recuadro negro.
     */
    public static function idDeVideoYoutube(string $url): ?string
    {
        $patrones = [
            '~youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})~',
            '~youtu\.be/([A-Za-z0-9_-]{11})~',
            '~youtube\.com/embed/([A-Za-z0-9_-]{11})~',
            '~youtube\.com/shorts/([A-Za-z0-9_-]{11})~',
        ];

        foreach ($patrones as $patron) {
            if (preg_match($patron, $url, $coincidencia) === 1) {
                return $coincidencia[1];
            }
        }

        return null;
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

        $rutaRelativa = $carpeta . '/' . $nombreFisico;

        // COMPROBAR QUE DE VERDAD SE ESCRIBIÓ. Antes el resultado de
        // putFileAs se descartaba, y el disco 'multimedia' está declarado con
        // 'throw' => false / 'report' => false: un fallo de escritura no
        // lanzaba excepción ni dejaba rastro en el log.
        //
        // Eso fue un problema real: el 26-ago-2026 alguien cambió el fondo del
        // login desde Configuraciones, la pantalla dijo "guardado" y el
        // archivo nunca se escribió (PHP corre como 'nginx' y la carpeta no
        // tenía permiso de escritura para el grupo). En la base quedó la ruta
        // de un archivo inexistente y nadie se enteró, porque el login tiene
        // una imagen de respaldo que tapa el síntoma.
        //
        // Se comprueba el retorno Y la existencia: putFileAs puede devolver
        // la ruta y aun así dejar el archivo incompleto si el disco se llena.
        $guardado = Storage::disk(self::DISCO_PUBLICO)->putFileAs($carpeta, $archivo, $nombreFisico);

        if ($guardado === false || ! Storage::disk(self::DISCO_PUBLICO)->exists($rutaRelativa)) {
            throw ValidationException::withMessages([
                'media' => ['No se pudo guardar el archivo en el servidor. Avisa a Sistemas: revisar permisos de escritura en la carpeta de multimedia.'],
            ]);
        }

        $tipoMedia = str_starts_with($archivo->getMimeType(), 'video') ? 'video' : 'imagen';

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