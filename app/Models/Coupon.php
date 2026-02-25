<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Coupon extends Model
{
    use HasFactory;
    protected $fillable = [
            'code',
            'discount_type',
            'discount',
            'limit_per_user',
            'total_use_limit',
            'expire_date',
            'description',
            'available_limit',
            'status'
        ];
        
     public function customers()
        {
            return $this->belongsToMany(Customer::class, 'coupon_user', 'coupon_id', 'user_id');
        }



}