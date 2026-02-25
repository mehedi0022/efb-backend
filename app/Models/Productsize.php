<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Productsize extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'size_id',
        'size_name',
        'price',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sort_order' => 'integer',
        'is_default' => 'boolean',
    ];

    public function size()
    {
        return $this->belongsTo(Size::class, 'size_id', 'id');
    }

    public function getResolvedSizeNameAttribute(): string
    {
        $directName = trim((string) ($this->size_name ?? ''));
        if ($directName !== '') {
            return $directName;
        }

        return (string) ($this->size?->sizeName ?? '');
    }
}
