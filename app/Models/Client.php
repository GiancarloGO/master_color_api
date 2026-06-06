<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Client extends Authenticatable implements JWTSubject
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'client_type',
        'identity_document',
        'document_type',
        'token_version',
        'phone',
        'verification_token',
        'email_verified_at',
        'is_test'
    ];

    protected $hidden = [
        'password',
        'deleted_at',
    ];

    protected $casts = [
        'identity_document' => 'string',
        'token_version' => 'string',
        'is_active' => 'boolean',
        'is_test' => 'boolean',
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the addresses for the client.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get the orders for the client.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the sold units (registered/purchased) for the client.
     */
    public function soldUnits(): HasMany
    {
        return $this->hasMany(SoldUnit::class);
    }

    /**
     * Get the support tickets for the client.
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * Tokens push (FCM) registrados por el cliente.
     */
    public function deviceTokens(): MorphMany
    {
        return $this->morphMany(DeviceToken::class, 'tokenable');
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return ['type' => 'client'];
    }
}
