<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\VmCategoriaEvento;
use Illuminate\Http\Request;

class CategoriaEventoController extends Controller
{
    /**
     * GET /api/vm/eventos/categorias
     * Listar categorías de eventos.
     */
    public function categoriasIndex()
    {
        $categorias = VmCategoriaEvento::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'descripcion']);

        return response()->json([
            'ok'   => true,
            'data' => $categorias,
        ]);
    }

    /**
     * POST /api/vm/eventos/categorias
     * Crear una nueva categoría.
     */
    public function categoriasStore(Request $request)
    {
        $data = $request->validate([
            'nombre'      => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $categoria = VmCategoriaEvento::create($data);

        return response()->json([
            'ok'   => true,
            'data' => $categoria,
        ], 201);
    }

    /**
     * PUT /api/vm/eventos/categorias/{categoria}
     * Actualizar una categoría.
     */
    public function categoriasUpdate(Request $request, VmCategoriaEvento $categoria)
    {
        $data = $request->validate([
            'nombre'      => ['sometimes', 'required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $categoria->fill($data)->save();

        return response()->json([
            'ok'   => true,
            'data' => $categoria->fresh(),
        ]);
    }

    /**
     * DELETE /api/vm/eventos/categorias/{categoria}
     * Eliminar una categoría.
     */
    public function categoriasDestroy(VmCategoriaEvento $categoria)
    {
        $categoria->delete();

        return response()->noContent();
    }
}
