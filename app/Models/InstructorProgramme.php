<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One programme an instructor teaches.
 *
 * A row per instructor per programme, because an instructor teaches several
 * and users.course holds one. See the create_instructor_programmes_table
 * migration for why this is a table rather than a column.
 *
 * The course is a string from User::COURSES_BY_COLLEGE, which is already the
 * single source of truth for what CSPC offers.
 */
class InstructorProgramme extends Model
{
    protected $fillable = ['user_id', 'course'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
