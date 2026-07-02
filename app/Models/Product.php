<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\FileUploadService;
class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'sku',
        'image_url',
        'barcode',
        'brand',
        'description',
        'category',
        'category_id',
        'presentation',
        'unidad',
        'default_warranty_months',
        'user_id'
    ];

    protected $casts = [
        'default_warranty_months' => 'integer',
    ];

    public function getImageUrlAttribute($value)
    {
        return app(FileUploadService::class)->getImageUrl($value);
    }

    /**
     * Get the user that owns the product.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category that owns the product.
     *
     * Se nombra `productCategory` (no `category`) para no colisionar con la
     * columna string legada `category`, que se conserva por compatibilidad.
     */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Get the stock for the product.
     */
    public function stock(): HasOne
    {
        return $this->hasOne(Stock::class);
    }

    /**
     * Get the order details for the product.
     */
    public function orderDetails(): HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }

    /**
     * Get the sold units for the product.
     */
    public function soldUnits(): HasMany
    {
        return $this->hasMany(SoldUnit::class);
    }
}
