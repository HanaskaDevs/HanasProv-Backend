<?php

namespace Tests\Feature\Calidad;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\AuditoriaRespuestaImagen;
use App\Modules\Auditorias\Models\CalificacionRecepcionRespuestaImagen;
use App\Modules\Auditorias\Models\TipoAuditoria;
use App\Modules\Auditorias\Services\ImagenObservacionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fotos en las observaciones de Calidad (12-nov-2026).
 *
 * El disco 'auditorias' se reemplaza por uno falso en cada prueba
 * (Storage::fake): así no se escribe nada en el repositorio real ni en el
 * servidor, y se puede comprobar que el binario se guardó.
 */
class ImagenesObservacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('auditorias');
    }

    private function idTipo(): int
    {
        return (int) TipoAuditoria::where('Activo', true)->firstOrFail()->Id_Tipo_Auditoria;
    }

    /** Auditoría en Borrador con su primera pregunta, lista para responder. */
    private function auditoriaConPregunta(): array
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $detalle = $this->postJson('/api/auditorias/iniciar', [
            'id_tipo_auditoria' => $this->idTipo(),
            'id_proveedor' => $proveedor->Id_Proveedor,
        ], $this->cabecerasComo($calidad, $empresa))->assertSuccessful()->json();

        $idPregunta = $detalle['secciones'][0]['preguntas'][0]['id_auditoria_pregunta'];

        return [$empresa, $calidad, $detalle['id_auditoria'], $idPregunta];
    }

    private function foto(string $nombre = 'evidencia.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, 400, 300);
    }

    // ------------------------------------------------------------------
    // Auditorías
    // ------------------------------------------------------------------

    public function test_se_adjunta_una_foto_a_la_observacion_de_una_pregunta(): void
    {
        [$empresa, $calidad, $idAuditoria, $idPregunta] = $this->auditoriaConPregunta();

        $respuesta = $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
            'id_auditoria_pregunta' => $idPregunta,
            'imagen' => $this->foto(),
        ], $this->cabecerasComo($calidad, $empresa));

        $respuesta->assertCreated()->assertJsonStructure(['id', 'nombre', 'url']);

        // El binario quedó en el disco y la fila enlazada existe.
        $this->assertCount(1, Storage::disk('auditorias')->allFiles());
        $this->assertSame(1, AuditoriaRespuestaImagen::whereHas(
            'respuesta',
            fn ($q) => $q->where('Id_Auditoria', $idAuditoria)
        )->count());

        // Y aparece en el detalle que dibuja el formulario.
        $detalle = $this->getJson("/api/auditorias/{$idAuditoria}", $this->cabecerasComo($calidad, $empresa))->json();
        $pregunta = collect($detalle['secciones'][0]['preguntas'])->firstWhere('id_auditoria_pregunta', $idPregunta);
        $this->assertCount(1, $pregunta['imagenes']);
    }

    public function test_no_se_pueden_subir_mas_de_dos_fotos_por_pregunta(): void
    {
        [$empresa, $calidad, $idAuditoria, $idPregunta] = $this->auditoriaConPregunta();
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        for ($i = 0; $i < ImagenObservacionService::MAX_IMAGENES; $i++) {
            $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
                'id_auditoria_pregunta' => $idPregunta,
                'imagen' => $this->foto("foto-{$i}.jpg"),
            ], $cabeceras)->assertCreated();
        }

        $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
            'id_auditoria_pregunta' => $idPregunta,
            'imagen' => $this->foto('tercera.jpg'),
        ], $cabeceras)
            ->assertStatus(422)
            ->assertJsonValidationErrors('imagen');

        $this->assertCount(ImagenObservacionService::MAX_IMAGENES, Storage::disk('auditorias')->allFiles());
    }

    public function test_se_elimina_una_foto_y_libera_el_cupo(): void
    {
        [$empresa, $calidad, $idAuditoria, $idPregunta] = $this->auditoriaConPregunta();
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $primera = $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
            'id_auditoria_pregunta' => $idPregunta,
            'imagen' => $this->foto(),
        ], $cabeceras)->json();

        $this->deleteJson("/api/auditorias/imagenes/{$primera['id']}", [], $cabeceras)->assertSuccessful();

        // Baja lógica: la fila sigue, pero ya no se muestra ni ocupa cupo.
        $this->assertFalse((bool) AuditoriaRespuestaImagen::find($primera['id'])->Activo);

        $detalle = $this->getJson("/api/auditorias/{$idAuditoria}", $cabeceras)->json();
        $pregunta = collect($detalle['secciones'][0]['preguntas'])->firstWhere('id_auditoria_pregunta', $idPregunta);
        $this->assertCount(0, $pregunta['imagenes']);
    }

    public function test_un_archivo_que_no_es_imagen_se_rechaza(): void
    {
        [$empresa, $calidad, $idAuditoria, $idPregunta] = $this->auditoriaConPregunta();

        $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
            'id_auditoria_pregunta' => $idPregunta,
            'imagen' => UploadedFile::fake()->create('informe.pdf', 100, 'application/pdf'),
        ], $this->cabecerasComo($calidad, $empresa))
            ->assertStatus(422)
            ->assertJsonValidationErrors('imagen');

        $this->assertCount(0, Storage::disk('auditorias')->allFiles());
    }

    public function test_compras_no_puede_adjuntar_fotos(): void
    {
        [$empresa, , $idAuditoria, $idPregunta] = $this->auditoriaConPregunta();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
            'id_auditoria_pregunta' => $idPregunta,
            'imagen' => $this->foto(),
        ], $this->cabecerasComo($compras, $empresa))->assertForbidden();
    }

    public function test_una_auditoria_finalizada_no_admite_fotos_nuevas(): void
    {
        [$empresa, $calidad, $idAuditoria, $idPregunta] = $this->auditoriaConPregunta();

        Auditoria::where('Id_Auditoria', $idAuditoria)->update(['Estado' => 'Finalizada']);

        $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
            'id_auditoria_pregunta' => $idPregunta,
            'imagen' => $this->foto(),
        ], $this->cabecerasComo($calidad, $empresa))
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    /**
     * El mismo "ver la foto" pero del lado de AUDITORÍAS. Está para poder
     * distinguir, cuando algo falla, si el problema es del endpoint (que
     * es idéntico en los dos módulos) o del binding de un modelo concreto.
     */
    public function test_la_foto_de_la_auditoria_se_puede_ver(): void
    {
        [$empresa, $calidad, $idAuditoria, $idPregunta] = $this->auditoriaConPregunta();
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $imagen = $this->post("/api/auditorias/{$idAuditoria}/imagenes", [
            'id_auditoria_pregunta' => $idPregunta,
            'imagen' => $this->foto(),
        ], $cabeceras)->assertCreated()->json();

        $this->assertIsInt($imagen['id'] ?? null, 'El POST de la foto tiene que devolver su id.');

        $this->get("/api/auditorias/imagenes/{$imagen['id']}/ver", $cabeceras)->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // Calificación de recepciones
    // ------------------------------------------------------------------

    /** Calificación de recepción empezada, con su primer parámetro. */
    private function recepcionConParametro(): array
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $detalle = $this->postJson('/api/calificacion-recepciones/iniciar', [
            'id_proveedor' => $proveedor->Id_Proveedor,
        ], $this->cabecerasComo($calidad, $empresa))->assertSuccessful()->json();

        return [$empresa, $calidad, $detalle['id_calificacion_recepcion'], $detalle['parametros'][0]['id_recepcion_parametro']];
    }

    public function test_en_recepciones_hay_que_responder_antes_de_adjuntar(): void
    {
        [$empresa, $calidad, $idCalificacion, $idParametro] = $this->recepcionConParametro();
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        // Sin responder el parámetro: el backend lo explica en vez de romper.
        $this->post("/api/calificacion-recepciones/{$idCalificacion}/imagenes", [
            'id_recepcion_parametro' => $idParametro,
            'imagen' => $this->foto(),
        ], $cabeceras)
            ->assertStatus(422)
            ->assertJsonValidationErrors('imagen');

        // Respondido, la misma llamada entra.
        $this->postJson("/api/calificacion-recepciones/{$idCalificacion}/respuestas", [
            'id_recepcion_parametro' => $idParametro,
            'cumple' => false,
            'observacion' => 'Faltó el certificado.',
        ], $cabeceras)->assertSuccessful();

        $this->post("/api/calificacion-recepciones/{$idCalificacion}/imagenes", [
            'id_recepcion_parametro' => $idParametro,
            'imagen' => $this->foto(),
        ], $cabeceras)->assertCreated();

        $this->assertSame(1, CalificacionRecepcionRespuestaImagen::whereHas(
            'respuesta',
            fn ($q) => $q->where('Id_Calificacion_Recepcion', $idCalificacion)
        )->count());
    }

    public function test_la_foto_de_la_recepcion_aparece_en_el_detalle_y_se_puede_ver(): void
    {
        [$empresa, $calidad, $idCalificacion, $idParametro] = $this->recepcionConParametro();
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $this->postJson("/api/calificacion-recepciones/{$idCalificacion}/respuestas", [
            'id_recepcion_parametro' => $idParametro,
            'cumple' => true,
            'observacion' => null,
        ], $cabeceras)->assertSuccessful();

        $imagen = $this->post("/api/calificacion-recepciones/{$idCalificacion}/imagenes", [
            'id_recepcion_parametro' => $idParametro,
            'imagen' => $this->foto(),
        ], $cabeceras)->assertCreated()->json();

        $this->assertIsInt($imagen['id'] ?? null, 'El POST de la foto tiene que devolver su id.');

        $detalle = $this->getJson("/api/calificacion-recepciones/{$idCalificacion}", $cabeceras)->json();
        $parametro = collect($detalle['parametros'])->firstWhere('id_recepcion_parametro', $idParametro);
        $this->assertCount(1, $parametro['imagenes']);

        // El endpoint de la foto devuelve el binario, no un JSON.
        $this->get("/api/calificacion-recepciones/imagenes/{$imagen['id']}/ver", $cabeceras)
            ->assertSuccessful();
    }

    /**
     * Sin sesión, el endpoint de la foto contesta 401 y no 404.
     *
     * NO sube ninguna foto antes a propósito: lo que se comprueba es que
     * la autenticación corre ANTES de ir a buscar la fila, así que sirve
     * cualquier id. Haciéndolo con llamadas autenticadas previas en el
     * mismo test, el estado de esas llamadas enturbiaba el resultado.
     */
    public function test_sin_sesion_no_se_ve_una_foto(): void
    {
        $this->getJson('/api/calificacion-recepciones/imagenes/999999/ver')->assertUnauthorized();
        $this->getJson('/api/auditorias/imagenes/999999/ver')->assertUnauthorized();
    }
}
