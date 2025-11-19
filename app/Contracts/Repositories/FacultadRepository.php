<?php
// app/Contracts/Repositories/FacultadRepository.php
namespace App\Contracts\Repositories;

use App\Models\Facultad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface FacultadRepository
{
    public function find(int $id): ?Facultad;
    public function listByUniversidad(int $universidadId, int $perPage = 15): LengthAwarePaginator;
    public function create(array $data): Facultad;
    public function update(Facultad $facultad, array $data): Facultad;
    public function delete(Facultad $facultad): void;

    /** Para lookups simples en tests/postman */
    public function allByUniversidad(int $universidadId): Collection;
}
