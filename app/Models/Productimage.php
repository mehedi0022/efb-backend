<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Productimage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'image',
        'image_color',
        'is_feature',
        'sort_order',
    ];

    protected $casts = [
        'is_feature' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
