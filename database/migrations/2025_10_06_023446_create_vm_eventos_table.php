<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Tabla de categorías de eventos
        Schema::create('vm_categorias_evento', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        // 2) Tabla de eventos
        Schema::create('vm_eventos', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK → periodos_academicos
            $table->unsignedBigInteger('periodo_id');
            $table->foreign('periodo_id')
                ->references('id')->on('periodos_academicos')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            // FK → vm_categorias_evento
            $table->unsignedBigInteger('categoria_evento_id')->nullable();
            $table->foreign('categoria_evento_id')
                ->references('id')->on('vm_categorias_evento')
                ->onDelete('set null');

            // Alcance polimórfico (targetable: Sede, Facultad, EpSede)
            $table->unsignedBigInteger('targetable_id');
            $table->string('targetable_type');

            // Datos principales
            $table->string('codigo')->unique(); // UK global del evento
            $table->string('titulo');
            $table->string('subtitulo')->nullable();

            // Descripción / detalles
            $table->text('descripcion_corta')->nullable();
            $table->longText('descripcion_larga')->nullable();

            // Info de presentación
            $table->enum('modalidad', ['PRESENCIAL', 'VIRTUAL', 'MIXTA'])
                  ->default('PRESENCIAL');
            $table->string('lugar_detallado')->nullable();       // aula, auditorio, etc.
            $table->string('url_imagen_portada')->nullable();    // imagen del evento
            $table->string('url_enlace_virtual')->nullable();    // Zoom/Meet, si aplica

            // Estado / reglas
            $table->enum('estado', ['PLANIFICADO', 'EN_CURSO', 'CERRADO', 'CANCELADO'])
                  ->default('PLANIFICADO');

            $table->boolean('requiere_inscripcion')->default(false);
            $table->integer('cupo_maximo')->nullable();

            // Ventana de inscripción (opcional)
            $table->date('inscripcion_desde')->nullable();
            $table->date('inscripcion_hasta')->nullable();

            // Índices de apoyo
            $table->index(['targetable_type', 'targetable_id']);
            $table->index('periodo_id');
            $table->index('estado');
            $table->index('categoria_evento_id');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vm_eventos');
        Schema::dropIfExists('vm_categorias_evento');
    }
};
