<?php
// app/Models/GaConnection.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
// --- PASO 1: CORREGIR el 'use' statement ---
use App\Casts\Encrypted; // Usar el namespace correcto donde movimos la clase
// ------------------------------------------

class GaConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'property_id', // El ID de propiedad de GA4
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        // Ahora usa la clase Encrypted importada correctamente
        'access_token' => Encrypted::class,
        'refresh_token' => Encrypted::class,
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}