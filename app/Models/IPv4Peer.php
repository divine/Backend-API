<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IPv4Peer extends Model {

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ipv4_peers';

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['id', 'created_at', 'updated_at'];

}
