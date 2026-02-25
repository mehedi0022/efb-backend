<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Productframe extends Model
{
    use HasFactory;
    public function size(){
        return $this->hasOne('App\Models\FrameColor', 'id', 'frame_id');
    }
}
