<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An SMTP profile used to send mail. Only one row may be active at a time.
 */
class SmtpSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'host', 'port', 'username', 'password',
        'encryption', 'from_address', 'from_name', 'is_active',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'port'      => 'integer',
        ];
    }

    /**
     * The currently active SMTP profile, or null when none is configured.
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
