<?php

namespace Webkul\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProgressWorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->is_active, 403);
        $head = '<base href="'.e(route('admin.v2.progress-workspace.asset', ['file' => 'app.js'], false)).'">'
            .'<meta name="progress-api" content="'.e(url('/admin/v2/project-progress/api')).'">'
            .'<meta name="csrf-token" content="'.e(csrf_token()).'">';
        $html = str_replace('<head>', '<head>'.$head, file_get_contents(base_path('docs/v2-prototype/index.html')));

        return response($html)->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function asset(Request $request, string $file): BinaryFileResponse
    {
        abort_unless($request->user()->is_active, 403);
        $root = realpath(base_path('docs/v2-prototype'));
        $path = realpath($root.'/'.$file);
        abort_unless($path && str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            && in_array(pathinfo($path, PATHINFO_EXTENSION), ['js', 'css', 'svg', 'woff2'], true), 404);

        $types = ['js' => 'text/javascript; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2'];

        return response()->file($path, ['Content-Type' => $types[pathinfo($path, PATHINFO_EXTENSION)], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache']);
    }
}
