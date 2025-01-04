<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MrMaster extends Model
{
    public $timestamps = false;
    protected $table = 'mr_master';
    protected $guarded = [];
    use HasFactory;
}
