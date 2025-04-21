<?php

namespace Modules\AmeiseModule\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

class AmeiseToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /** Access‑Token automatisch ver‑/ent­schlüsseln */
    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => Crypt::decryptString($v),
            set: fn ($v) => Crypt::encryptString($v)
        );
    }

    /** Refresh‑Token automatisch ver‑/ent­schlüsseln */
    protected function refreshToken(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => Crypt::decryptString($v),
            set: fn ($v) => Crypt::encryptString($v)
        );
    }
}
