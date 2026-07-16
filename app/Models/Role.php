<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'description'];

    /**
     * Get all users with this role.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
