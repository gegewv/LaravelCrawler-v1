<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Crawler extends Model
{
    //
    public $timestamps = false;


    protected $fillable = [
        'status', 'data'
    ];
}
