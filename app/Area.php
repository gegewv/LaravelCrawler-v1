<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    //
    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name', 'parent_id'
    ];
}
