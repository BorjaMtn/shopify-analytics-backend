<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Asegúrate de que el nombre de la clase coincida con tu nombre de archivo (ej. CreateGaConnectionsTable)
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('ga_connections', function (Blueprint $table) {
            $table->id(); // ID primario autoincremental

            // Clave foránea para relacionar con el usuario
             $table->foreignId('user_id')
                   ->unique() // Asumimos una conexión GA por usuario (puedes quitar unique() si permites varias)
                   ->constrained('users') // Referencia a la tabla 'users', columna 'id'
                   ->onDelete('cascade'); // Si se borra el usuario, se borra esta fila

            // ID de la Propiedad GA4 que el usuario quiere ver
            $table->string('property_id')->nullable();

            // Token de acceso OAuth2 de Google (guardado encriptado)
            $table->text('access_token')->nullable();

            // Token de refresco OAuth2 de Google (guardado encriptado) - ¡Muy importante!
            $table->text('refresh_token')->nullable();

            // Cuándo expira el access_token (timestamp Unix o datetime)
            $table->timestamp('expires_at')->nullable();

            // Campos created_at y updated_at automáticos
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('ga_connections');
    }
};