<?php

namespace App\Http\Middleware;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Services\SchoolAccess;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Bound teaching records recover their verified offering; class ownership never does. */
class InstitutionalLegacyBoundary
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/school*')) {
            foreach ($request->route()?->parameters() ?? [] as $resource) {
                if ($resource instanceof Classroom) {
                    if ($resource->is_official) {
                        abort_unless($request->isMethod('get') && $request->route()->uri() === 'api/classes/{classroom}', 403);
                        abort_unless(app(SchoolAccess::class)->canView($request->user(), $resource), 403);
                    }
                } elseif ($resource instanceof Model) {
                    $classroomId = $resource->getAttribute('classroom_id');
                    if ($classroomId) {
                        $this->scopeRecord($request, $resource);
                    }
                    foreach (['assignment', 'quiz', 'deck'] as $relation) {
                        if (method_exists($resource, $relation)) {
                            $parent = $resource->$relation;
                            if ($parent && $parent->getAttribute('classroom_id')) {
                                $this->scopeRecord($request, $parent);
                            }
                        }
                    }
                }
            }
        }

        return $next($request);
    }

    private function scopeRecord(Request $request, $resource): void
    {
        $group = Classroom::whereKey($resource->classroom_id)->where('is_official', true)->first();
        if (! $group) {
            return;
        }
        abort_unless($resource->offering_id, 403);
        $access = app(SchoolAccess::class);
        $offering = $access->offering($request->user(), $resource->offering_id);
        abort_unless($offering->classroom_id === $group->id, 403);
        if (! $request->isMethod('get')) {
            $access->writable($group);
        }
        if ($resource instanceof Assignment && $request->user()->isStudent()) {
            abort_unless(in_array($resource->publication_status, ['published', 'closed']), 404);
            if ($request->isMethod('post') && str_ends_with($request->route()->uri(), '/submissions')) {
                abort_unless($resource->publication_status === 'published', 409);
            }
        }
        $request->attributes->set('school_offering_id', $offering->id);
    }
}
