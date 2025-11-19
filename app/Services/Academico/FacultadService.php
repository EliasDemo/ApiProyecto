<?php
// app/Services/Academico/FacultadService.php
namespace App\Services\Academico;

use App\Contracts\Repositories\FacultadRepository;
use App\Models\Facultad;
use Illuminate\Validation\ValidationException;

final class FacultadService
{
    public function __construct(private FacultadRepository $repo) {}

    public function crear(array $data): Facultad
    {
        // ejemplo de regla simple
        if (empty($data['universidad_id'])) {
            throw ValidationException::withMessages(['universidad_id' => 'Requerido']);
        }
        return $this->repo->create($data);
    }

    public function actualizar(int $id, array $data): Facultad
    {
        $fac = $this->repo->find($id);
        if (!$fac) {
            throw ValidationException::withMessages(['id' => 'Facultad no encontrada']);
        }
        return $this->repo->update($fac, $data);
    }

    public function eliminar(int $id): void
    {
        $fac = $this->repo->find($id);
        if (!$fac) return;
        $this->repo->delete($fac);
    }
}
