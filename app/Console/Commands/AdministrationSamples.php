<?php

namespace App\Console\Commands;

use App\Models\AdminCharityPerson;
use App\Models\AdminDocument;
use App\Models\User;
use App\Services\Administration\AdminDocumentService;
use App\Services\Administration\AdminTemplateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Puts review samples in the LOCAL database: the starting template, a sample Charity list and a few documents in different stages (each is marked SAMPLE). --remove takes them out again. */
class AdministrationSamples extends Command
{
    protected $signature = 'administration:samples {--remove : Remove the samples instead}';

    protected $description = 'Add (or remove) sample Administration documents, Charity people and the starting template';

    public function handle(): int
    {
        if ($this->option('remove')) {
            $ids = AdminDocument::withTrashed()->where('notes', 'like', 'SAMPLE%')->pluck('id');
            DB::table('admin_document_items')->whereIn('admin_document_id', $ids)->delete();
            DB::table('admin_document_charities')->whereIn('admin_document_id', $ids)->delete();
            DB::table('admin_document_sends')->whereIn('admin_document_id', $ids)->delete();
            AdminDocument::withTrashed()->whereIn('id', $ids)->forceDelete();
            AdminCharityPerson::withTrashed()->where('notes', 'like', 'SAMPLE%')->forceDelete();
            $this->info('Samples removed (' . $ids->count() . ' documents).');

            return self::SUCCESS;
        }
        $this->info('Template lines added: ' . AdminTemplateSeeder::run());
        $user = User::role('Super Admin')->orderBy('id')->first();
        if (! $user) {
            $this->error('No Super Admin user found.');

            return self::FAILURE;
        }
        auth()->setUser($user);
        foreach (AdminTemplateSeeder::CHARITY as $i => [$name, $amount]) {
            AdminCharityPerson::firstOrCreate(['name' => $name], ['monthly_amount' => $amount, 'is_active' => true, 'sort_order' => $i + 1, 'notes' => 'SAMPLE charity person']);
        }
        $made = [];
        foreach ([[-21, 'Authorized', false], [-14, 'Authorized', true], [-7, 'Approved', false], [0, 'Requested', false]] as [$offset, $status, $charity]) {
            $date = now()->addDays($offset);
            $d = AdminDocument::create(['reference' => AdminDocument::nextReference(), 'status' => AdminDocument::REQUESTED, 'doc_date' => $date->toDateString(), 'department' => AdminDocument::departmentName(), 'currency' => AdminDocument::currency(),
                'beneficiary_user_id' => $user->id, 'beneficiary_name' => $user->name, 'include_charity' => $charity, 'notes' => 'SAMPLE document', 'source' => 'template', 'user_id' => $user->id]);
            AdminDocumentService::saveLines($d, AdminDocumentService::templateLines());
            AdminDocumentService::saveCharity($d, $charity ? AdminDocumentService::charityRows($date) : []);
            $d->recalculate();
            if (in_array($status, ['Approved', 'Authorized'], true)) {
                $d->forceFill(['status' => AdminDocument::APPROVED, 'approved_by_user_id' => $user->id, 'approved_at' => $date->copy()->addHour()])->save();
            }
            if ($status === 'Authorized') {
                $d->forceFill(['status' => AdminDocument::AUTHORIZED, 'authorized_by_user_id' => $user->id, 'authorized_at' => $date->copy()->addHours(2)])->save();
            }
            $made[] = $d->reference . ' ' . $status . ' ' . number_format((float) $d->total_amount, 2);
        }
        $this->info(implode("\n", $made));

        return self::SUCCESS;
    }
}
