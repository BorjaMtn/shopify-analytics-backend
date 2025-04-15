<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Asegúrate de que el nombre de la clase coincida con tu nombre de archivo (ej. CreateShopsTable)
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id(); // ID primario autoincremental

            // Clave foránea para relacionar con el usuario
            $table->foreignId('user_id')
                  ->unique() // Asegura que cada usuario solo tenga una tienda (relación 1 a 1)
                  ->constrained('users') // Referencia a la tabla 'users', columna 'id'
                  ->onDelete('cascade'); // Si se borra el usuario, se borra esta fila

            // Dominio de la tienda Shopify (ej. 'tu-tienda.myshopify.com')
            $table->string('shop_domain')->unique(); // Debe ser único

            // Token de acceso (guardado encriptado) - Usamos TEXT por si es muy largo
            $table->text('access_token');

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
        Schema::dropIfExists('shops');
    }
};