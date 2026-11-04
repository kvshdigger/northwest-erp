<?php

namespace Webkul\Project\Http\Controllers\API\V2;

use App\Http\Controllers\Controller;
use App\Services\WorkOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Webkul\Project\Http\Requests\ProgressDraftRequest;
use Webkul\Project\Http\Requests\ProgressMemberRequest;
use Webkul\Project\Http\Requests\ProgressPreviewRequest;
use Webkul\Project\Http\Requests\ProgressPublishRequest;
use Webkul\Project\Http\Requests\ProgressQueryRequest;
use Webkul\Project\Http\Requests\ProgressReportRequest;
use Webkul\Project\Http\Requests\ProgressSetupRequest;
use Webkul\Project\Http\Resources\ProgressActivityResource;
use Webkul\Project\Http\Resources\ProgressReportResource;
use Webkul\Project\Models\ProgressActivity;
use Webkul\Project\Models\ProgressMembership;
use Webkul\Project\Models\ProgressReport;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;
use Webkul\Project\Policies\ProgressAccess;
use Webkul\Project\Services\ProgressConfiguration;
use Webkul\Project\Services\ProgressWorkflow;
use Webkul\Security\Models\User;

class ProgressController extends Controller
{
    public function __construct(private ProgressWorkflow $workflow, private ProgressAccess $access, private ProgressConfiguration $configuration) {}

    public function preview(ProgressPreviewRequest $request, Project $progressProject): JsonResponse
    {
        try {
            $result = $this->configuration->calculate($request->validated('configuration'), $request->validated('inputs'));

            return response()->json(['data' => ['inputs' => $result['inputs'], 'result_type' => $result['result_type'], 'result' => $result['result_numeric'] ?? $result['result_text']]]);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['inputs' => $error->getMessage()]);
        }
    }

    public function projects(Request $request): JsonResponse
    {
        return response()->json($this->access->projects($request->user())->select(['id', 'name', 'company_id'])->orderBy('name')->paginate(30));
    }

    public function workspace(ProgressQueryRequest $request, Project $progressProject): JsonResponse
    {
        $canConfigure = $this->access->configure($request->user(), $progressProject);
        $request->attributes->set('can_configure_progress', $canConfigure);
        $activities = ProgressActivity::query()->where('project_id', $progressProject->id)->with('published')->orderBy('id')->get();
        if (! $canConfigure) {
            $activities = $activities->filter(fn (ProgressActivity $activity): bool => $activity->is_active && $activity->published !== null)->values();
        }

        return response()->json([
            'project'      => ['id' => $progressProject->id, 'name' => $progressProject->name],
            'capabilities' => ['can_configure' => $canConfigure, 'can_submit' => $this->access->submit($request->user(), $progressProject), 'can_manage_members' => $this->access->manageMembers($request->user(), $progressProject)],
            'routes'       => app(WorkOrders::class)->routes($progressProject),
            'activities'   => ProgressActivityResource::collection($activities)->resolve($request),
            'members'      => $this->access->manageMembers($request->user(), $progressProject)
                ? ProgressMembership::query()->where('project_id', $progressProject->id)->with('user:id,name')->get()->map(fn (ProgressMembership $member): array => [
                    'user_id' => $member->user_id, 'name' => $member->user?->name, 'can_submit' => $member->can_submit, 'can_configure' => $member->can_configure,
                ]) : [],
            'member_candidates' => $this->access->manageMembers($request->user(), $progressProject)
                ? User::query()->where('is_active', true)->when($progressProject->company_id, fn ($query) => $query->whereHas('allowedCompanies', fn ($companies) => $companies->whereKey($progressProject->company_id)))->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function storeDraft(ProgressDraftRequest $request, Project $progressProject): JsonResponse
    {
        abort_if((int) $request->validated('revision') !== 0, 409, 'New activities start at revision zero.');

        return $this->activityResponse($request, $this->workflow->saveDraft($progressProject, $request->user(), $request->validated('configuration'), null, 0), 201);
    }

    public function updateDraft(ProgressDraftRequest $request, Project $progressProject, int $activity): JsonResponse
    {
        return $this->activityResponse($request, $this->workflow->saveDraft($progressProject, $request->user(), $request->validated('configuration'), $activity, (int) $request->validated('revision')));
    }

    public function publish(ProgressPublishRequest $request, Project $progressProject, int $activity): JsonResponse
    {
        try {
            return $this->activityResponse($request, $this->workflow->publish($progressProject, $request->user(), $activity, (int) $request->validated('revision')));
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['configuration' => $error->getMessage()]);
        }
    }

    public function storeReport(ProgressReportRequest $request, Project $progressProject): JsonResponse
    {
        try {
            return (new ProgressReportResource($this->workflow->record($progressProject, $request->user(), $request->validated())))->response()->setStatusCode(201);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['entries' => $error->getMessage()]);
        }
    }

    public function showReport(ProgressQueryRequest $request, Project $progressProject, int $report): ProgressReportResource
    {
        return new ProgressReportResource(ProgressReport::query()->where('project_id', $progressProject->id)->with(['route', 'entries.version', 'workOrderRoute.version.workOrder'])->findOrFail($report));
    }

    public function tracker(ProgressQueryRequest $request, Project $progressProject): JsonResponse
    {
        return response()->json($this->workflow->tracker($progressProject, $request->validated()));
    }

    public function storeRoute(ProgressSetupRequest $request, Project $progressProject): JsonResponse
    {
        return $progressProject->getConnection()->transaction(function () use ($request, $progressProject): JsonResponse {
            $this->workflow->lock($progressProject);
            abort_unless($this->access->configure($request->user(), $progressProject), 403);
            $data = $request->validated();
            if (ProgressRoute::query()->where('project_id', $progressProject->id)->where('name_key', $data['name_key'])->exists()) {
                throw ValidationException::withMessages(['name' => 'This project already has that route.']);
            }
            $route = ProgressRoute::query()->create([...$data, 'project_id' => $progressProject->id]);
            $this->workflow->audit($progressProject, $request->user(), 'route_created', 'route', $route->id, null, $data);

            return response()->json(['data' => $route->only(['id', 'project_id', 'name', 'is_active'])], 201);
        });
    }

    public function upsertMember(ProgressMemberRequest $request, Project $progressProject): JsonResponse
    {
        return $progressProject->getConnection()->transaction(function () use ($request, $progressProject): JsonResponse {
            $this->workflow->lock($progressProject);
            abort_unless($this->access->manageMembers($request->user(), $progressProject), 403);
            $data = $request->validated();
            $user = User::query()->findOrFail($data['user_id']);
            if (! $user->is_active || ($progressProject->company_id && ! $user->allowedCompanies()->whereKey($progressProject->company_id)->exists())) {
                throw ValidationException::withMessages(['user_id' => 'The member must be active and allowed to access this project company.']);
            }
            $member = ProgressMembership::query()->firstOrNew(['project_id' => $progressProject->id, 'user_id' => $user->id]);
            $before = $member->exists ? $member->only(['can_submit', 'can_configure']) : null;
            $member->fill($data)->save();
            $this->workflow->audit($progressProject, $request->user(), 'member_updated', 'membership', $member->id, $before, $data);

            return response()->json(['data' => $member]);
        });
    }

    private function activityResponse(Request $request, ProgressActivity $activity, int $status = 200): JsonResponse
    {
        $request->attributes->set('can_configure_progress', true);

        return (new ProgressActivityResource($activity))->response($request)->setStatusCode($status);
    }
}
