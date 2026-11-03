<?php

namespace Webkul\Project\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProgressV2Enabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('project-progress.enabled'), 404);

        return $next($request);
    }
}
