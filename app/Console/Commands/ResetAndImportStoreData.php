<?php

namespace App\Console\Commands;

use App\Models\Unit;
use App\Models\User;
use App\Models\Category;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FULL inventory reset + import from StoreData.xlsx.
 * Wipes all item/transaction data, then loads the sheet as deduped items
 * (with categories, units, prices) + per-warehouse check-ins + stock.
 * Documents-only stock writes (no events) via bulk inserts for reliability.
 */
class ResetAndImportStoreData extends Command
{
    protected $signature = 'import:reset
        {--file=/tmp/storedata.json}
        {--as=1}
        {--contact=1}
        {--preview}';

    protected $description = 'Wipe inventory and import only the StoreData.xlsx items (deduped) with quantities/prices/categories';

    private array $unitMap = [
        'bags'=>'bags','tons'=>'tons','pcs'=>'pcs','box'=>'box',
        'rolls'=>'rolls','drum'=>'drum','kg'=>'kg','sqm'=>'sqm',
    ];

    private array $wipeTables = [
        'items','categorizables','stocks','stock_trails','serials','variations',
        'checkins','checkin_items','checkin_item_serial','checkin_item_variation',
        'checkouts','checkout_items','checkout_item_serial','checkout_item_variation',
        'transfers','transfer_items','transfer_item_variation','serial_transfer_item',
        'adjustments','adjustment_items','adjustment_item_serial','adjustment_item_variation',
    ];

    private function aggr(?string $s): string { return preg_replace('/[^A-Z0-9]/','', mb_strtoupper((string)$s)); }

    private function categoryName(string $n): string
    {
        $n = mb_strtoupper($n);
        $has = fn(...$ks) => (bool) array_filter($ks, fn($k)=>str_contains($n,$k));
        if ($has('TILE')) return 'Tiles';
        if ($has('CEMENT') && $has('WHITE')) return 'White Cement';
        if ($has('CEMENT')) return 'Cement';
        if ($has('GYPSUM')) return 'Gypsum Powder';
        if ($has('PUTTY','PUTY')) return 'Wall Puty';
        if ($has('PRIMER')) return 'Primer';
        if ($has('SILK')) return 'Silk Paint';
        if ($has('PAINT','EMULSION','COAT','CLADDING','OIL DOVE','TUFF','ENAMEL','GLOSS','SYNTHETIC','NATIONAL ')) return 'Painting';
        if ($has('CABLE')) return 'Cables';
        if ($has('SOLAR')) return 'Solar Lights';
        if ($has('NAIL')) return 'Nails';
        if ($has('STEEL')) return 'Steel';
        if ($has('WC ','TOILET','WASH BASIN','BASIN','SHOWER','SEAT COVER','MIRROW','MIRROR','BIDET','SOAP','TOWEL','URINAL','BATH','SHATTAF','FLUSH','CISTERN','KITCHEN SINK','K.SINK','K SINK','PENDESTAL','PEDESTAL')) return 'Sanitary';
        if ($has('PPR','PVC','PIPE','VALVE','ELBOW','TEE','SOCKET','SOCKATE','CONNECTOR','CONECTOR','GATE','BIB','TAP','MIXER','TRAP','GULTRAP','UNION','BUSH','BUSGH','ANGEL VALVE','STOP CORK','FLEXIBLE')) return 'Plumbing';
        if ($has('LIGHT','LAMP','BULB','NICE WAY','FLOOD','FLORECENT','FLOURECENT','DIFFUSER','LED')) return 'Lights';
        if ($has('FAN','SWITCH','MCB','FUSE','ISOLATOR','MAINSWITCH','MEANSWITCH','GANG','CONDUIT','PLUG','SHAVER','HEATER','AIRCON','CONDISION','TERMINAL','RIVET','POWER','BOX')) return 'Electrical';
        if ($has('CYPRESS','WOOD','BLACK PIPE','PURLIN','ANGLE LINE','ROOFING','BOLT','HINGE','LOCK','DOOR','ALUMINIUM','WELDING','MARINE','EPS','BOARD','BINDING WIRE','PLYWOOD','BLOCK')) return 'Construction Material';
        return 'General';
    }

    private function runTx(callable $fn): void
    {
        for ($a=1; $a<=20; $a++) {
            try { DB::purge(); DB::reconnect(); DB::transaction($fn); return; }
            catch (\Throwable $e) { $this->line("   retry {$a}: ".$e->getMessage()); usleep(500000); }
        }
        throw new \RuntimeException('phase failed after retries');
    }

    public function handle(): int
    {
        $path=$this->option('file');
        if (! is_file($path)) { $this->error("File not found: {$path}"); return self::FAILURE; }
        $rows=json_decode(file_get_contents($path), true);
        if (! is_array($rows) || ! count($rows)) { $this->error('No rows.'); return self::FAILURE; }

        $user=User::find((int)$this->option('as'));
        if (! $user) { $this->error('User not found.'); return self::FAILURE; }
        Auth::login($user);
        $acc=$user->account_id;

        // Ensure the Square-Metre unit exists (sheet uses "SQM")
        if (! $this->option('preview') && ! Unit::whereRaw('LOWER(code) = ?', ['sqm'])->exists()) {
            Unit::create(['name'=>'Square Metre','code'=>'sqm','account_id'=>$acc]);
            $this->line('  created missing unit: Square Metre (sqm)');
        }

        $units = Unit::all()->keyBy(fn($u)=>mb_strtolower($u->code));
        $cats  = Category::all()->keyBy(fn($c)=>$c->name); // name => Category
        $whs   = Warehouse::all()->keyBy(fn($w)=>$this->aggr($w->name));

        // Build distinct items (aggressive dedup) + ordered lines grouped by warehouse
        $items=[]; // key => [name,unit_id,price,cat_id]
        $byWh=[];  // whKey => list of [key,quantity,price,unit_id]
        foreach ($rows as $r) {
            $k=$this->aggr($r['name']);
            $uCode=$this->unitMap[mb_strtolower(trim($r['unit']))] ?? null;
            if (! $uCode || ! isset($units[$uCode])) { $this->error("Unit not mappable: {$r['unit']}"); return self::FAILURE; }
            $unitId=$units[$uCode]->id;
            $whKey=$this->aggr($r['warehouse']);
            if (! isset($whs[$whKey])) { $this->error("Warehouse not found: {$r['warehouse']}"); return self::FAILURE; }
            $price = is_numeric($r['price']) ? (float)$r['price'] : 0.0;
            $qty   = is_numeric($r['quantity']) ? (float)$r['quantity'] : 0.0;

            if (! isset($items[$k])) {
                $catName=$this->categoryName($r['name']);
                $items[$k]=['name'=>trim($r['name']),'unit_id'=>$unitId,'price'=>$price,
                    'cat_id'=>optional($cats[$catName] ?? $cats['General'] ?? null)->id];
            } elseif ($items[$k]['price']==0 && $price>0) {
                $items[$k]['price']=$price;
            }
            $byWh[$whKey][]=['key'=>$k,'quantity'=>$qty,'price'=>$price,'unit_id'=>$unitId];
        }

        $this->info(($this->option('preview')?'[PREVIEW] ':'[LIVE] ')
            ."distinct items=".count($items)." | warehouses=".count($byWh)." | lines=".count($rows));
        foreach ($byWh as $wk=>$ls) $this->line("   ".$whs[$wk]->name.": ".count($ls)." lines");

        if ($this->option('preview')) { $this->warn('preview only, no changes.'); return self::SUCCESS; }

        // ---- WIPE ----
        $this->warn('Wiping inventory tables...');
        for ($a=1;$a<=20;$a++){
            try{
                DB::purge(); DB::reconnect();
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                foreach ($this->wipeTables as $t) DB::table($t)->truncate();
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                break;
            } catch(\Throwable $e){ $this->line("   wipe retry {$a}: ".$e->getMessage()); usleep(500000);
                if ($a==20) { $this->error('wipe failed'); return self::FAILURE; } }
        }
        $this->info('  wiped.');

        $now=Carbon::now(); $today=$now->toDateString();

        // ---- ITEMS ----
        $itemRows=[]; $i=0; $keyToCode=[];
        foreach ($items as $k=>$it){ $i++; $code='RSI-'.str_pad((string)$i,4,'0',STR_PAD_LEFT); $keyToCode[$k]=$code;
            $itemRows[]=['name'=>$it['name'],'code'=>$code,'unit_id'=>$it['unit_id'],'price'=>$it['price'],
                'track_quantity'=>1,'track_weight'=>0,'has_variants'=>0,'has_serials'=>0,'alert_quantity'=>0,
                'account_id'=>$acc,'created_at'=>$now,'updated_at'=>$now]; }
        $this->runTx(function() use ($itemRows){ foreach (array_chunk($itemRows,200) as $c) DB::table('items')->insert($c); });
        $codeToId = DB::table('items')->pluck('id','code'); // code=>id
        $keyToId=[]; foreach ($keyToCode as $k=>$code) $keyToId[$k]=$codeToId[$code];
        $this->info('  items inserted: '.count($itemRows));

        // ---- CATEGORIZABLES ----
        $catRows=[];
        foreach ($items as $k=>$it){ if ($it['cat_id']) $catRows[]=['category_id'=>$it['cat_id'],
            'categorizable_id'=>$keyToId[$k],'categorizable_type'=>'App\\Models\\Item']; }
        $this->runTx(function() use ($catRows){ foreach (array_chunk($catRows,300) as $c) DB::table('categorizables')->insert($c); });
        $this->info('  category links: '.count($catRows));

        // ---- CHECKINS (one per warehouse) ----
        $ci=0; $checkinRows=[]; $whKeys=array_keys($byWh);
        foreach ($whKeys as $wk){ $ci++; $checkinRows[]=['date'=>$today,'reference'=>'RSC-'.str_pad((string)$ci,4,'0',STR_PAD_LEFT),
            'draft'=>0,'contact_id'=>(int)$this->option('contact'),'warehouse_id'=>$whs[$wk]->id,'user_id'=>$user->id,
            'account_id'=>$acc,'hash'=>(string)Str::uuid(),'details'=>'Imported from StoreData.xlsx','created_at'=>$now,'updated_at'=>$now]; }
        $this->runTx(function() use ($checkinRows){ DB::table('checkins')->insert($checkinRows); });
        $whToCheckin = DB::table('checkins')->pluck('id','warehouse_id'); // warehouse_id=>checkin_id
        $this->info('  checkins: '.count($checkinRows));

        // ---- CHECKIN ITEMS (ordered: warehouse, then sheet order) ----
        $lineOrder=[]; // keep order to zip with ids for trails
        $ciRows=[];
        foreach ($whKeys as $wk){ $whId=$whs[$wk]->id; $chId=$whToCheckin[$whId];
            foreach ($byWh[$wk] as $ln){ $iid=$keyToId[$ln['key']];
                $ciRows[]=['checkin_id'=>$chId,'item_id'=>$iid,'quantity'=>$ln['quantity'],'weight'=>0,
                    'unit_id'=>$ln['unit_id'],'price'=>$ln['price'],'warehouse_id'=>$whId,'account_id'=>$acc,
                    'draft'=>0,'created_at'=>$now,'updated_at'=>$now];
                $lineOrder[]=['item_id'=>$iid,'warehouse_id'=>$whId,'unit_id'=>$ln['unit_id'],'quantity'=>$ln['quantity']]; } }
        $this->runTx(function() use ($ciRows){ foreach (array_chunk($ciRows,200) as $c) DB::table('checkin_items')->insert($c); });
        $this->info('  checkin_items: '.count($ciRows));

        // ---- STOCKS (sum per item+warehouse) + STOCK TRAILS (per line, running balance) ----
        $ciIds = DB::table('checkin_items')->orderBy('id')->pluck('id')->all(); // same order as insertion
        $running=[]; $trailRows=[]; $finalStock=[];
        foreach ($lineOrder as $idx=>$ln){ $key=$ln['item_id'].'-'.$ln['warehouse_id'];
            $running[$key]=($running[$key]??0)+$ln['quantity']; $finalStock[$key]=$running[$key];
            $trailRows[]=['subject_type'=>'App\\Models\\CheckinItem','subject_id'=>$ciIds[$idx],
                'type'=>'Updating Checkin Item','weight'=>0,'quantity'=>$running[$key],'item_id'=>$ln['item_id'],
                'unit_id'=>$ln['unit_id'],'variation_id'=>null,'warehouse_id'=>$ln['warehouse_id'],
                'account_id'=>$acc,'created_at'=>$now,'updated_at'=>$now]; }
        $stockRows=[];
        foreach ($finalStock as $key=>$qty){ [$iid,$whId]=explode('-',$key);
            $stockRows[]=['item_id'=>(int)$iid,'warehouse_id'=>(int)$whId,'variation_id'=>null,'quantity'=>$qty,
                'weight'=>0,'alert_quantity'=>0,'account_id'=>$acc,'created_at'=>$now,'updated_at'=>$now]; }
        $this->runTx(function() use ($stockRows,$trailRows){
            foreach (array_chunk($stockRows,200) as $c) DB::table('stocks')->insert($c);
            foreach (array_chunk($trailRows,200) as $c) DB::table('stock_trails')->insert($c);
        });
        $this->info('  stock rows: '.count($stockRows).' | stock_trails: '.count($trailRows));

        $this->newLine();
        $this->info('DONE. Active items='.DB::table('items')->whereNull('deleted_at')->count());
        return self::SUCCESS;
    }
}
