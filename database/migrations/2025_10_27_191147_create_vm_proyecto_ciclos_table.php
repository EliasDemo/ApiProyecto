<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vm_proyecto_ciclos', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('proyecto_id');
            $table->foreign('proyecto_id')
                ->references('id')->on('vm_proyectos')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            // Desnormalizados para el UNIQUE global
            $table->unsignedBigInteger('ep_sede_id');
            $table->foreign('ep_sede_id')
                ->references('id')->on('ep_sede')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->unsignedBigInteger('periodo_id');
            $table->foreign('periodo_id')
                ->references('id')->on('periodos_academicos')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->unsignedTinyInteger('nivel'); // 1..10

            // Reglas
            // No repetir el mismo nivel dentro del proyecto
            $table->unique(['proyecto_id','nivel'], 'uk_vm_proy_ciclo_en_proyecto');
            // 1 proyecto por (ep_sede, periodo, nivel)
            $table->unique(['ep_sede_id','periodo_id','nivel'], 'uk_vm_proy_ep_per_nivel');

            $table->index(['ep_sede_id','periodo_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vm_proyecto_ciclos');
    }
};
