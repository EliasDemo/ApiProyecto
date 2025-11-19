<?php
// app/Repositories/Eloquent/EloquentFacultadRepository.php
namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\FacultadRepository;
use App\Models\Facultad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class EloquentFacultadRepository implements FacultadRepository
{
    public function find(int $id): ?Facultad
    {
        return Facultad::find($id);
    }

    public function listByUniversidad(int $universidadId, int $perPage = 15): LengthAwarePaginator
    {
        return Facultad::where('universidad_id', $universidadId)
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): Facultad
    {
        return Facultad::create($data);
    }

    public function update(Facultad $facultad, array $data): Facultad
    {
        $facultad->fill($data)->save();
        return $facultad->refresh();
    }

    public function delete(Facultad $facultad): void
    {
        $facultad->delete();
    }

    public function allByUniversidad(int $universidadId): Collection
    {
        return Facultad::where('universidad_id', $universidadId)->get();
    }
}
