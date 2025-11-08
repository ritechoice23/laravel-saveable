<?php

namespace Ritechoice23\Saveable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ritechoice23\Saveable\Traits\HasSaves;

class Team extends Model
{
    use HasSaves;

    protected $guarded = [];
}
