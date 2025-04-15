<?php
// app/Models/Shop.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
// --- PASO 1: Añadir 'use' para la clase movida ---
use App\Casts\Encrypted;
// --------------------------------------------------

// --- PASO 2: ELIMINAR la definición de la clase Encrypted de aquí ---
// class Encrypted implements CastsAttributes { ... } // <= ¡Borra esto!
// -----------------------------------------------------------------

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'shop_domain',
        'access_token',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected $casts = [
        // Usamos la clase importada via 'use'
        'access_token' => Encrypted::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}