<?php

namespace App\Services;

use App\Models\BusinessEvent;
use App\Models\FinanceConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Models\Move;
use Webkul\Purchase\Models\PurchaseOrder;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Sequence;
use Webkul\Support\Services\SequenceService;

class FinanceDocuments
{
    public function canConfigure(User $user): bool
    {
        return $user->is_active && $user->can('v2_finance_configure');
    }

    public function company(User $user, int $id, bool $lock = false): Company
    {
        abort_unless($user->is_active && in_array($id, app(NativeHr::class)->companyIds($user), true), 403);

        return Company::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function defaults(Company $company): array
    {
        return ['display_name' => $company->name, 'address' => implode("\n", array_filter([$company->street1, $company->street2, trim($company->zip.' '.$company->city)])), 'registration_number' => $company->registration_number, 'tax_number' => $company->tax_id, 'email' => $company->email, 'phone' => $company->phone, 'bank_details' => '', 'po_terms' => '', 'invoice_terms' => '', 'footer' => '', 'logo_data' => null];
    }

    public function sequence(int $company, string $kind): Sequence
    {
        return SequenceService::ensure('v2.finance.'.$kind, $company, ['prefix' => $kind === 'po' ? 'PO/%(year)/' : 'INV/%(year)/', 'padding' => 5]);
    }

    public function configuration(User $user, int $id): array
    {
        abort_unless($this->canConfigure($user), 403);
        $company = $this->company($user, $id);
        $record = FinanceConfiguration::query()->where('company_id', $id)->first();
        $settings = array_replace($this->defaults($company), $record?->settings ?? []);
        foreach (['po', 'invoice'] as $kind) {
            $seq = $this->sequence($id, $kind);
            foreach (['prefix', 'suffix', 'padding', 'next_number'] as $field) {
                $settings[$kind.'_'.($field === 'next_number' ? 'next' : $field)] = $seq->{$field};
            }
            $settings[$kind.'_preview'] = $seq->formatNumber($seq->next_number);
        }

        return ['companies' => $user->allowedCompanies()->whereKey(app(NativeHr::class)->companyIds($user))->get(['companies.id', 'companies.name'])->toArray(), 'company_id' => $id, 'revision' => $record?->revision ?? 0, 'settings' => $settings];
    }

    public function save(User $user, array $input, ?UploadedFile $logo): array
    {
        abort_unless($this->canConfigure($user), 403);

        return (new FinanceConfiguration)->getConnection()->transaction(function () use ($user, $input, $logo): array {
            $company = $this->company($user, (int) $input['company_id'], true);
            $record = FinanceConfiguration::query()->where('company_id', $company->id)->lockForUpdate()->first();
            abort_unless(($record?->revision ?? 0) === (int) $input['revision'], 409, 'Configuration changed. Reopen it before saving.');
            $settings = array_replace($this->defaults($company), $record?->settings ?? [], $input['settings']);
            foreach (['po', 'invoice'] as $kind) {
                $seq = $this->sequence($company->id, $kind);
                if ((int) $settings[$kind.'_next'] < $seq->next_number) {
                    throw ValidationException::withMessages(['settings.'.$kind.'_next' => 'The next number cannot move backwards.']);
                }
                $seq->update(['prefix' => $settings[$kind.'_prefix'], 'suffix' => $settings[$kind.'_suffix'], 'padding' => $settings[$kind.'_padding'], 'next_number' => $settings[$kind.'_next']]);
                foreach (['prefix', 'suffix', 'padding', 'next'] as $field) {
                    unset($settings[$kind.'_'.$field]);
                }
            }
            if ($input['remove_logo'] ?? false) {
                $settings['logo_data'] = null;
            }
            if ($logo) {
                $settings['logo_data'] = 'data:'.$logo->getMimeType().';base64,'.base64_encode($logo->get());
            }
            $record ??= new FinanceConfiguration(['company_id' => $company->id, 'revision' => 0]);
            $record->settings = $settings;
            $record->revision++;
            $record->save();
            BusinessEvent::create(['company_id' => $company->id, 'actor_id' => $user->id, 'kind' => 'finance-configuration', 'record_id' => $record->id, 'action' => 'saved', 'changes' => ['revision' => $record->revision, 'settings' => $settings]]);

            return $this->configuration($user, $company->id);
        });
    }

    public function next(Company $company, string $kind, string $date): string
    {
        $sequence = $this->sequence($company->id, $kind);
        $model = $kind === 'po' ? PurchaseOrder::class : Move::class;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $number = SequenceService::next($sequence->code, $company->id, [], CarbonImmutable::parse($date));
            if (! $model::query()->where('company_id', $company->id)->where('name', $number)->exists()) {
                return $number;
            }
        }
        throw ValidationException::withMessages(['sequence' => 'This numbering range conflicts with existing documents. Configure a new prefix or higher next number.']);
    }

    public function snapshot(Company $company): array
    {
        $config = FinanceConfiguration::query()->where('company_id', $company->id)->first();

        return ['revision' => $config?->revision ?? 0, ...array_replace($this->defaults($company), $config?->settings ?? [])];
    }
}
