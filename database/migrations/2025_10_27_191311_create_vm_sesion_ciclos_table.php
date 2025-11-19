<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vm_sesion_ciclos', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sesion_id');
            $table->foreign('sesion_id')
                ->references('id')->on('vm_sesiones')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->unsignedBigInteger('proyecto_ciclo_id');
            $table->foreign('proyecto_ciclo_id')
                ->references('id')->on('vm_proyecto_ciclos')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->unique(['sesion_id','proyecto_ciclo_id'], 'uk_vm_sesion_ciclo');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vm_sesion_ciclos');
    }
};
