<?php

use Webkul\Project\Filament\Clusters\Configurations;
use Webkul\Project\Filament\Clusters\Configurations\Resources\ActivityPlanResource;
use Webkul\Project\Filament\Clusters\Configurations\Resources\MilestoneResource;
use Webkul\Project\Filament\Clusters\Configurations\Resources\ProjectStageResource;
use Webkul\Project\Filament\Clusters\Configurations\Resources\TagResource;
use Webkul\Project\Filament\Clusters\Configurations\Resources\TaskStageResource;
use Webkul\Project\Filament\Resources\ProjectResource;
use Webkul\Project\Filament\Resources\FibreBoqItemResource;
use Webkul\Project\Filament\Resources\FibreDailyProgressResource;
use Webkul\Project\Filament\Resources\FibreRouteSectionResource;
use Webkul\Project\Filament\Resources\FibreWorkPackageResource;
use Webkul\Project\Filament\Resources\TaskResource;

$basic = ['view_any', 'view', 'create', 'update'];
$delete = ['delete', 'delete_any'];
$forceDelete = ['force_delete', 'force_delete_any'];
$restore = ['restore', 'restore_any'];
$reorder = ['reorder'];

return [
    'resources' => [
        'manage' => [
            MilestoneResource::class    => [...$basic, ...$delete],
            TagResource::class          => [...$basic, ...$delete, ...$restore, ...$forceDelete],
            ActivityPlanResource::class => [...$basic, ...$delete, ...$restore, ...$forceDelete],
            ProjectStageResource::class => [...$basic, ...$delete, ...$restore, ...$forceDelete, ...$reorder],
            TaskStageResource::class    => [...$basic, ...$delete, ...$restore, ...$forceDelete, ...$reorder],
            ProjectResource::class      => [...$basic, ...$delete, ...$restore, ...$forceDelete, ...$reorder],
            FibreBoqItemResource::class => [...$basic, ...$delete],
            FibreDailyProgressResource::class => [...$basic, ...$delete],\n            FibreProgressReviewResource::class => ['view_any', 'view'],
            FibreRouteSectionResource::class => [...$basic, ...$delete],
            FibreWorkPackageResource::class => [...$basic, ...$delete],
            TaskResource::class         => [...$basic, ...$delete, ...$restore, ...$forceDelete, ...$reorder],
        ],
        'exclude' => [],
    ],

    'pages' => [
        'exclude' => [
            Configurations::class,
        ],
    ],
];
