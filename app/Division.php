<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Division extends Model
{
    //
     protected $fillable = [ 'value', 'text'];
     public static function getByKey($key)
    {
        return self::where('value', $key)->first();
    }
}
