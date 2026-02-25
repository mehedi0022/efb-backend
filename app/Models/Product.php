<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'view_home' => 'boolean',
    ];

    public function getSkuAttribute(): ?string
    {
        return $this->attributes['sku'] ?? $this->attributes['product_code'] ?? null;
    }

    public function getSellingPriceAttribute(): mixed
    {
        return $this->attributes['selling_price'] ?? $this->attributes['new_price'] ?? null;
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
    public function image()
    {
        return $this->hasOne(Productimage::class, 'product_id')
            ->select('id', 'image', 'image_color', 'product_id', 'is_feature', 'sort_order')
            ->orderByDesc('is_feature')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function featureImage()
    {
        return $this->hasOne(Productimage::class, 'product_id')
            ->where('is_feature', 1)
            ->select('id', 'image', 'image_color', 'product_id', 'is_feature', 'sort_order')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function images()
    {
        return $this->hasMany(Productimage::class, 'product_id')
            ->select('id', 'image', 'image_color', 'product_id', 'is_feature', 'sort_order')
            ->orderByDesc('is_feature')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function galleryImages()
    {
        return $this->hasMany(Productimage::class, 'product_id')
            ->where('is_feature', 0)
            ->select('id', 'image', 'image_color', 'product_id', 'is_feature', 'sort_order')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
    public function reviews()
    {
        return $this->hasMany(Review::class, 'product_id')->select('id');
    }
    public function category()
    {
        return $this->hasOne(Category::class, 'id', 'category_id')->select('id', 'name', 'slug');
    }
    public function subcategory()
    {
        return $this->hasOne(Subcategory::class, 'id', 'subcategory_id')->select('id', 'subcategoryName', 'slug');
    }
    public function childcategory()
    {
        return $this->hasOne(Childcategory::class, 'id', 'childcategory_id')->select('id', 'childcategoryName', 'slug');
    }
    public function brand()
    {
        return $this->hasOne(Brand::class, 'id', 'brand_id')->select('id', 'name', 'slug');
    }
    public function sizes()
    {
        return $this->belongsToMany('App\Models\Size', 'productsizes')->withTimestamps();
    }
    public function colors()
    {
        return $this->belongsToMany('App\Models\Color', 'productcolors')->withTimestamps();
    }

    public function prosizes()
    {
        return $this->hasMany(Productsize::class, 'product_id')
            ->with('size:id,sizeName')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function sizePricings()
    {
        return $this->hasMany(Productsize::class, 'product_id')
            ->with('size:id,sizeName')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
    public function procolors()
    {
        return $this->hasMany('App\Models\Productcolor');
    }

    public function prosize()
    {
        return $this->hasOne(Productsize::class, 'product_id')
            ->with('size:id,sizeName')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
    public function procolor()
    {
        return $this->hasOne(Productcolor::class, 'product_id');
    }

    // Product.php
}
