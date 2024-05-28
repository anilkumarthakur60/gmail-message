<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_token',
        'created',
        'email',
        'expires_in',
        'refresh_token',
        'refresh_token_updated_at',
        'scope',
        'token_type',
    ];
}
