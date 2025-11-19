<?php
// tests/Unit/Services/FacultadServiceTest.php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\Repositories\FacultadRepository;
use App\Models\Facultad;
use App\Services\Academico\FacultadService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FacultadServiceTest extends TestCase
{
    #[Test]
    public function crear_valida_universidad_id_y_persiste(): void
    {
        $repo = $this->createMock(FacultadRepository::class);
        $service = new FacultadService($repo);

        $data = ['universidad_id' => 1, 'codigo' => 'FIA', 'nombre' => 'Ingeniería'];
        $repo->expects($this->once())
            ->method('create')
            ->with($data)
            ->willReturn(new Facultad($data));

        $result = $service->crear($data);

        $this->assertInstanceOf(Facultad::class, $result);
        $this->assertSame('FIA', $result->codigo);
    }

    #[Test]
    public function crear_lanza_error_si_falta_universidad_id(): void
    {
        $service = new FacultadService($this->createMock(FacultadRepository::class));

        $this->expectException(ValidationException::class);

        $service->crear(['codigo' => 'FIA']);
    }
}
