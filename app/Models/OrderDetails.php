<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDetails extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function productSizeVariant()
    {
        return $this->belongsTo(Productsize::class, 'product_size_id');
    }

    public function productColorVariant()
    {
        return $this->belongsTo(Productcolor::class, 'product_color_id');
    }
    public function image()
    {
        return $this->belongsTo(Productimage::class, 'product_id', 'product_id')->select('id','product_id','image');
    }
    public function shipping(){
        return $this->belongsTo(Shipping::class, 'order_id','order_id')->select('id','order_id','name','phone','address');
    }
    public function order(){
        return $this->belongsTo(Order::class, 'order_id')->select('id','invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
