<?php

namespace Tests\Feature\Archivos;

use App\Shared\VerificaArchivoFisico;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

/**
 * El portal tiene que distinguir "el archivo no está" de "el archivo está
 * pero no lo puedo leer".
 *
 * No es un detalle cosmético: las carpetas de /var/repositorio/proveedores
 * quedaron con permisos 700 de otro usuario, TODOS los documentos eran
 * ilegibles para la app, y el portal respondía "el archivo físico no se
 * encuentra en el repositorio". Ese mensaje manda a buscar un archivo que
 * está ahí, y esconde que el problema son los permisos.
 */
class DiagnosticoArchivoTest extends TestCase
{
    use VerificaArchivoFisico;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/prueba-archivos-'.uniqid();
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        // Se restauran permisos antes de borrar: una carpeta en 000 no se
        // puede vaciar.
        @chmod($this->carpeta, 0755);
        foreach (glob($this->carpeta.'/*') ?: [] as $archivo) {
            @chmod($archivo, 0644);
            @unlink($archivo);
        }
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    public function test_un_archivo_legible_no_lanza_nada(): void
    {
        $ruta = $this->carpeta.'/ok.pdf';
        file_put_contents($ruta, 'contenido');

        $this->verificarArchivoEntregable($ruta);

        // Llegar acá sin excepción es el resultado esperado.
        $this->assertTrue(true);
    }

    public function test_un_archivo_que_no_existe_da_404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->verificarArchivoEntregable($this->carpeta.'/no-existe.pdf');
    }

    /**
     * El caso que importa: el archivo ESTÁ, pero la carpeta no se puede
     * recorrer. Tiene que dar 503 (problema del servidor) y no 404.
     */
    public function test_un_archivo_ilegible_por_permisos_da_503(): void
    {
        $subcarpeta = $this->carpeta.'/sin-permiso';
        mkdir($subcarpeta, 0755);

        $ruta = $subcarpeta.'/documento.pdf';
        file_put_contents($ruta, 'contenido');

        // 000: ni lectura ni recorrido, igual que las carpetas 700 de otro
        // usuario en el servidor.
        chmod($subcarpeta, 0000);

        // root ignora los permisos, así que si los tests corren como root
        // este caso no se puede reproducir.
        if (is_readable($subcarpeta)) {
            chmod($subcarpeta, 0755);
            $this->markTestSkipped('El proceso puede leer una carpeta en 000 (¿corriendo como root?).');
        }

        try {
            $this->verificarArchivoEntregable($ruta);
            $this->fail('Tendría que haber lanzado ServiceUnavailableHttpException.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertStringContainsString('configuración del servidor', $e->getMessage());
            // El mensaje al usuario NO debe filtrar la ruta del servidor.
            $this->assertStringNotContainsString($subcarpeta, $e->getMessage());
        } finally {
            chmod($subcarpeta, 0755);
            @unlink($ruta);
            @rmdir($subcarpeta);
        }
    }
}
