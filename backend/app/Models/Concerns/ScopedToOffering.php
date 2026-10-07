<?php

namespace App\Models\Concerns;

use App\Services\SchoolAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait ScopedToOffering
{
    protected static function bootScopedToOffering(): void
    {
        static::addGlobalScope('offering_request', function (Builder $query) {
            $id = request()?->attributes->get('school_offering_id');
            if ($id) {
                $query->where($query->getModel()->getTable().'.offering_id', $id);
                if ($query->getModel()->getTable() === 'assignments' && request()->user()?->isStudent()) {
                    $query->whereIn('publication_status', ['published', 'closed']);
                }
            }
        });
        static::creating(function ($model) {
            if ($id = request()?->attributes->get('school_offering_id')) {
                if (! DB::table('module_offerings')->where('id', $id)->where('classroom_id', $model->classroom_id)->exists()) {
                    return;
                }
                app(SchoolAccess::class)->offering(request()->user(), $id, true);
                $model->offering_id = $id;
                if ($model->getTable() === 'assignments') {
                    $model->publication_status = 'draft';
                }
            }
        });
    }
}
