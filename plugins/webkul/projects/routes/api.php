<?php

use Illuminate\Support\Facades\Route;
use Webkul\Project\Http\Controllers\API\V1\MilestoneController;
use Webkul\Project\Http\Controllers\API\V1\ProjectController;
use Webkul\Project\Http\Controllers\API\V1\ProjectStageController;
use Webkul\Project\Http\Controllers\API\V1\TagController;
use Webkul\Project\Http\Controllers\API\V1\TaskController;
use Webkul\Project\Http\Controllers\API\V1\TaskStageController;
use Webkul\Project\Http\Controllers\API\V2\ProgressController;
use Webkul\Project\Http\Middleware\ProgressV2Enabled;
use Webkul\Project\Models\Project;
use Webkul\Security\Models\Scopes\OwnershipScope;

Route::name('admin.api.v1.projects.')->prefix('admin/api/v1/projects')->middleware(['auth:sanctum'])->group(function () {
    Route::softDeletableApiResource('projects', ProjectController::class);
    Route::softDeletableApiResource('tasks', TaskController::class);
    Route::softDeletableApiResource('project-stages', ProjectStageController::class);
    Route::softDeletableApiResource('task-stages', TaskStageController::class);
    Route::apiResource('milestones', MilestoneController::class);
    Route::softDeletableApiResource('tags', TagController::class);
});

Route::bind('progressProject', fn (string $value) => Project::withoutGlobalScope(OwnershipScope::class)->findOrFail($value));

Route::prefix('admin/api/v2/project-progress')->name('admin.api.v2.project-progress.')
    ->middleware(['api', ProgressV2Enabled::class, 'auth:sanctum', 'throttle:60,1'])
    ->group(function (): void {
        $controller = ProgressController::class;
        Route::get('projects', [$controller, 'projects'])->name('projects');
        Route::prefix('projects/{progressProject}')->whereNumber('progressProject')->group(function () use ($controller): void {
            Route::get('workspace', [$controller, 'workspace'])->name('workspace');
            Route::post('routes', [$controller, 'storeRoute'])->name('routes.store');
            Route::put('members', [$controller, 'upsertMember'])->name('members.upsert');
            Route::post('activities', [$controller, 'storeDraft'])->name('activities.store');
            Route::post('activities/preview', [$controller, 'preview'])->name('activities.preview');
            Route::put('activities/{activity}/draft', [$controller, 'updateDraft'])->whereNumber('activity')->name('activities.draft');
            Route::post('activities/{activity}/publish', [$controller, 'publish'])->whereNumber('activity')->name('activities.publish');
            Route::post('reports', [$controller, 'storeReport'])->name('reports.store');
            Route::get('reports/{report}', [$controller, 'showReport'])->whereNumber('report')->name('reports.show');
            Route::get('tracker', [$controller, 'tracker'])->name('tracker');
        });
    });
