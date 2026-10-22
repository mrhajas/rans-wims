<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIncomingItem;
use App\Models\VisitorLogEvent;
use App\Models\VisitorVisit;
use App\Models\Warehouse;
use App\Services\VisitorLogs\VisitorContacts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sample records for Visitor Logs: visitors, visits (on the premises, left, a long stay, a cancelled one, a corrected one) and incoming items (waiting, ready, handed over, returned,
 * confidential, waiting too long) spread over the last days, with their history. Written straight to the tables, so nobody is notified. Everything is marked "SAMPLE".
 *
 *   php artisan visitor-logs:samples            add the samples (nothing happens if they exist)
 *   php artisan visitor-logs:samples --remove   delete them again (and the Contacts made for them)
 */
class SeedVisitorLogsSamples extends Command
{
    protected $signature = 'visitor-logs:samples {--remove : Delete the sample records instead of creating them}';

    protected $description = 'Create (or remove) sample visitors, visits and incoming items';

    private const MARK = 'SAMPLE';

    public function handle(): int
    {
        $admin = User::withoutGlobalScopes()->role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tmp%')->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin user found.');

            return self::FAILURE;
        }
        Auth::setUser($admin);
        if ($this->option('remove')) {
            return $this->remove();
        }
        if (Visitor::query()->where('remarks', self::MARK)->exists()) {
            $this->info('The samples exist already (use --remove first to start again).');

            return self::SUCCESS;
        }

        $hosts = User::query()->where('is_active', true)->whereNull('disabled_at')->where('username', 'not like', 'tmp%')->orderBy('id')->limit(6)->get();
        $hq = Warehouse::query()->whereRaw('LOWER(name) = ?', ['hq office'])->first() ?: Warehouse::query()->orderBy('id')->first();
        $sites = Warehouse::query()->where('id', '!=', optional($hq)->id)->orderBy('id')->limit(2)->get();
        $reception = User::withoutGlobalScopes()->role('Receptionist')->where('is_active', true)->first() ?: $admin;

        DB::transaction(function () use ($hosts, $hq, $sites, $reception, $admin) {
            $now = now();
            $people = [
                ['Hamad Suleiman', 'Zan Supplies Ltd', '+255777123456', 'Supplier'], ['Sara Mwinyi', 'Dar Couriers Limited', '+255655000111', 'Courier'], ['Peter Kamau', 'Kenya Steel Ltd', '+255712445566', 'Supplier'],
                ['Fatma Said', 'Ministry of Works', '+255744555666', 'Official / authority'], ['Juma Pandu', 'Pandu Contractors', '+255788111000', 'Subcontractor'], ['Asha Nassor', 'Aqua Tech Solutions', '+255713222333', 'Contractor'],
                ['Mohamed Ali', 'City Hardware', '+255754333444', 'Supplier'], ['Grace Mushi', 'ABC Consultants', '+255765444555', 'Client'],
            ];
            $v = [];
            foreach ($people as $i => [$name, $company, $phone, $cat]) {
                $x = Visitor::create(['reference' => Visitor::nextReference(), 'full_name' => $name, 'company' => $company, 'phone' => $phone, 'phone_key' => Visitor::phoneKey($phone), 'category' => $cat, 'remarks' => self::MARK, 'is_active' => true, 'created_by_user_id' => $reception->id]);
                $c = VisitorContacts::link($x);
                if ($c && $c->category === Contact::VISITORS_CATEGORY) {
                    $c->update(['details' => self::MARK . ' - ' . $company]);
                }
                $v[$i] = $x;
            }
            $host = fn ($i) => $hosts[$i % max(1, $hosts->count())];
            $purposes = ['Business meeting', 'Supplier delivery', 'Project meeting', 'Client meeting', 'Official correspondence', 'Contractor / subcontractor meeting', 'Interview', 'Maintenance / service visit'];
            $depts = ['Management', 'Procurement', 'Projects', 'Accounts', 'Administration', 'Stores', 'Human Resources'];
            $visit = function (int $vi, int $hi, string $status, $in, ?int $minutes, int $n, $wh) use ($host, $purposes, $depts, $reception, $v) {
                $h = $host($hi);
                $out = $status === VisitorVisit::OUT && $minutes !== null ? (clone $in)->addMinutes($minutes) : null;
                $x = VisitorVisit::create(['reference' => VisitorVisit::nextReference(), 'visitor_id' => $v[$vi]->id, 'visit_date' => $in->toDateString(), 'check_in_at' => $in, 'check_out_at' => $out, 'duration_minutes' => $out ? $minutes : null, 'status' => $status,
                    'host_user_id' => $h->id, 'host_name' => $h->name, 'department' => $depts[$n % count($depts)], 'purpose' => $purposes[$n % count($purposes)], 'companions' => $n % 3 === 0 ? 1 : 0, 'remarks' => self::MARK, 'registered_by_user_id' => $reception->id,
                    'checked_out_by_user_id' => $out ? $reception->id : null, 'warehouse_id' => $wh ? $wh->id : null]);
                $this->ev('visit', $x, 'checked_in', [], ['check_in_at' => $in->toDateTimeString(), 'host' => $h->name, 'purpose' => $x->purpose], null, $in, $reception);
                if ($out) {
                    $this->ev('visit', $x, 'checked_out', ['status' => 'Checked In'], ['status' => 'Checked Out', 'check_out_at' => $out->toDateTimeString(), 'minutes' => $minutes], null, $out, $reception);
                }

                return $x;
            };
            // today: three on the premises (one has stayed longer than the reminder hours) and two who already left
            $visit(0, 0, VisitorVisit::IN, $now->copy()->subMinutes(35), null, 0, $hq);
            $visit(2, 1, VisitorVisit::IN, $now->copy()->subHours(2)->subMinutes(10), null, 2, $hq);
            $visit(4, 2, VisitorVisit::IN, $now->copy()->subHours(9), null, 4, $sites[0] ?? $hq);
            $visit(1, 0, VisitorVisit::OUT, $now->copy()->subHours(3), 25, 1, $hq);
            $visit(3, 3, VisitorVisit::OUT, $now->copy()->subHours(5), 70, 3, $hq);
            // the days before
            $past = [[5, 1, 1, 48, 5, $sites[1] ?? $hq], [6, 2, 2, 95, 6, $hq], [7, 3, 3, 30, 7, $sites[0] ?? $hq], [0, 4, 4, 130, 0, $hq], [2, 1, 6, 55, 2, $hq], [3, 0, 7, 40, 3, $hq]];
            foreach ($past as [$vi, $hi, $d, $min, $n, $wh]) {
                $visit($vi, $hi, VisitorVisit::OUT, $now->copy()->subDays($d)->setTime(8 + $n % 6, 10 + $n * 3), $min, $n, $wh);
            }
            // one corrected (the receptionist forgot to check the visitor out)
            $c = $visit(4, 1, VisitorVisit::OUT, $now->copy()->subDays(4)->setTime(9, 0), 240, 1, $hq);
            $this->ev('visit', $c, 'corrected', ['check_out_at' => $c->check_in_at->copy()->addHours(9)->toDateTimeString()], ['check_out_at' => $c->check_out_at->toDateTimeString(), 'status' => 'Checked Out'], 'The receptionist forgot to check out; the visitor left at the time on the gate sheet', $now->copy()->subDays(3), $admin);
            // one cancelled
            $x = $visit(7, 2, VisitorVisit::IN, $now->copy()->subDays(2)->setTime(11, 15), null, 5, $hq);
            $x->update(['status' => VisitorVisit::CANCELLED, 'cancelled_by_user_id' => $admin->id, 'cancelled_at' => $now->copy()->subDays(2)->setTime(11, 20), 'cancel_reason' => 'Registered by mistake: the visitor was for another office']);
            $this->ev('visit', $x, 'cancelled', ['status' => 'Checked In'], ['status' => 'Cancelled'], 'Registered by mistake: the visitor was for another office', $now->copy()->subDays(2)->setTime(11, 20), $admin);

            // incoming items
            $dhl = Contact::create(['name' => 'DHL Express Zanzibar', 'category' => Contact::VISITORS_CATEGORY, 'phone' => '+255777000111', 'details' => self::MARK]);
            $item = function (string $type, int $hi, $at, string $subject, ?string $sender, string $status, ?string $flag, array $x = []) use ($host, $reception, $hq) {
                $h = $host($hi);
                $i = VisitorIncomingItem::create($x + ['reference' => VisitorIncomingItem::nextReference(), 'received_at' => $at, 'item_type' => $type, 'sender_name' => $sender, 'recipient_user_id' => $h->id, 'recipient_name' => $h->name, 'subject' => $subject,
                    'item_count' => 1, 'received_by_user_id' => $reception->id, 'status' => $status, 'status_flag' => $flag, 'status_changed_at' => $at, 'remarks' => self::MARK, 'warehouse_id' => $hq ? $hq->id : null]);
                $this->ev('item', $i, 'received', [], ['status' => 'Received at Reception', 'recipient' => $h->name, 'type' => $type], null, $at, $reception);

                return $i;
            };
            $item('Letter', 0, $now->copy()->subMinutes(50), 'Invitation to the project launch', 'Ministry of Works', 'Received at Reception', 'initial');
            $item('Package', 1, $now->copy()->subDays(5), 'Spare parts for the generator', 'Sara Mwinyi (Dar Couriers)', 'Received at Reception', 'initial', ['item_count' => 2, 'courier' => 'Dar Couriers', 'tracking_number' => 'DC-889120']);
            $r = $item('Document', 2, $now->copy()->subDays(1), 'Signed contract documents', 'Kenya Steel Ltd', 'Ready for Collection', 'ready', ['visitor_id' => $v[2]->id]);
            $this->ev('item', $r, 'status_changed', ['status' => 'Received at Reception'], ['status' => 'Ready for Collection'], null, $now->copy()->subDays(1)->addHour(), $reception);
            $r->update(['status_changed_at' => $now->copy()->subDays(1)->addHour()]);
            $h = $item('Official Correspondence', 3, $now->copy()->subDays(3), 'Tax assessment notice', 'TRA', 'Collected', 'handed');
            $this->ev('item', $h, 'status_changed', ['status' => 'Received at Reception'], ['status' => 'Ready for Collection'], null, $now->copy()->subDays(3)->addHour(), $reception);
            $this->ev('item', $h, 'handed_over', ['status' => 'Ready for Collection'], ['status' => 'Collected', 'handed_to' => $host(3)->name . ' (in person)', 'handed_at' => $now->copy()->subDays(2)->toDateTimeString(), 'released_by' => $reception->name], null, $now->copy()->subDays(2), $reception);
            $h->update(['handed_to' => $host(3)->name . ' (in person)', 'handed_at' => $now->copy()->subDays(2), 'released_by_user_id' => $reception->id, 'status_changed_at' => $now->copy()->subDays(2)]);
            $ret = $item('Parcel', 4, $now->copy()->subDays(6), 'Wrong address: not for RANS', 'Unknown courier', 'Returned to Sender', 'closed');
            $this->ev('item', $ret, 'status_changed', ['status' => 'Received at Reception'], ['status' => 'Returned to Sender'], 'Returned with the same courier the next morning', $now->copy()->subDays(5), $reception);
            $item('Letter', 0, $now->copy()->subHours(2), 'Personal and confidential', 'Ministry of Finance', 'Recipient Notified', null, ['is_confidential' => true]);
            $item('Courier Delivery', 5, $now->copy()->subHours(4), 'Laptop for the site office', $dhl->name, 'Received at Reception', 'initial', ['sender_contact_id' => $dhl->id, 'sender_contact' => $dhl->phone, 'sender_company' => 'DHL', 'courier' => 'DHL', 'tracking_number' => 'JD0146000019', 'warehouse_id' => $sites[0]->id ?? ($hq->id ?? null)]);
            $item('Package', 2, $now->copy()->subDays(2)->subHours(3), 'Samples from the supplier', 'Hamad Suleiman (Zan Supplies)', 'Received at Reception', 'initial', ['visit_id' => VisitorVisit::query()->where('visitor_id', $v[0]->id)->value('id'), 'visitor_id' => $v[0]->id]);
        });
        $this->info(Visitor::query()->where('remarks', self::MARK)->count() . ' visitors, ' . VisitorVisit::query()->where('remarks', self::MARK)->count() . ' visits and ' . VisitorIncomingItem::query()->where('remarks', self::MARK)->count() . ' items added (marked SAMPLE).');

        return self::SUCCESS;
    }

    private function ev(string $type, $subject, string $event, array $old, array $new, ?string $reason, $at, User $by): void
    {
        $e = VisitorLogEvent::create(['subject_type' => $type, 'subject_id' => $subject->id, 'subject_ref' => $subject->reference, 'event' => $event, 'user_id' => $by->id, 'reason' => $reason, 'old_value' => $old ?: null, 'new_value' => $new ?: null]);
        DB::table('visitor_log_events')->where('id', $e->id)->update(['created_at' => $at]);
    }

    private function remove(): int
    {
        $visits = VisitorVisit::withoutGlobalScopes()->withTrashed()->where('remarks', self::MARK)->pluck('id');
        $items = VisitorIncomingItem::withoutGlobalScopes()->withTrashed()->where('remarks', self::MARK)->pluck('id');
        $visitors = Visitor::withoutGlobalScopes()->withTrashed()->where('remarks', self::MARK)->get();
        DB::table('visitor_log_events')->where(fn ($q) => $q->where('subject_type', 'visit')->whereIn('subject_id', $visits))->orWhere(fn ($q) => $q->where('subject_type', 'item')->whereIn('subject_id', $items))->delete();
        VisitorVisit::withoutGlobalScopes()->withTrashed()->whereIn('id', $visits)->forceDelete();
        VisitorIncomingItem::withoutGlobalScopes()->withTrashed()->whereIn('id', $items)->forceDelete();
        $contacts = Contact::withoutGlobalScopes()->withTrashed()->where('category', Contact::VISITORS_CATEGORY)->where('details', 'like', self::MARK . '%')->pluck('id');
        DB::table('visitor_log_events')->where('subject_type', 'visitor')->whereIn('subject_id', $visitors->pluck('id'))->delete();
        Visitor::withoutGlobalScopes()->withTrashed()->whereIn('id', $visitors->pluck('id'))->forceDelete();
        Contact::withoutGlobalScopes()->withTrashed()->whereIn('id', $contacts)->forceDelete();
        $this->info('Removed ' . $visitors->count() . ' visitors, ' . $visits->count() . ' visits, ' . $items->count() . ' items and ' . $contacts->count() . ' contacts.');

        return self::SUCCESS;
    }
}
