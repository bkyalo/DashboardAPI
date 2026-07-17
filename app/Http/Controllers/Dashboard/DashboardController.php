<?php

namespace App\Http\Controllers\Dashboard;
use App\Support\ReadsFromKirima;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\MilkCollection\MilkCollectionController;

/**
 * DashboardController — optimised version.
 *
 * Every helper uses a SINGLE aggregated query (no N+1).
 * glByClass, storePerformance, milkTraceability and plSparkles
 * are all single GROUP-BY queries.
 */
class DashboardController extends Controller
{
    use ReadsFromKirima;

    private const P = '0_';

    private function fmt(string $date): string
    {
        return date('Y-m-d', strtotime($date));
    }

    // ── 1. GL totals by account class — single query ─────────────────────────

    /**
     * Returns [ ctype => ['total'=>float, 'class_name'=>string] ]
     * ctype: 1=Income, 2=COGS, 3=Expenses, 4=Assets, 5=Liabilities
     */
    private function glByClass(string $from, string $to,$type,$parent): array
    {
        // chart_class cc		JOIN 0_chart_types ct on ct.class_id=cc.cid		
        // WHERE !cc.inactive and ctype>3 OR ctype=0 and (parent = '' OR parent = '-1')   ORDER BY class_id, cid, parent
        $from = Carbon::parse($from)->format('Y-m-d');
         $to   = Carbon::parse($to)->format('Y-m-d');

        
           $sqls= "SELECT  cm.account_name as class_name , SUM(amount*$parent) as total
             FROM    " . self::P . "gl_trans gl
             JOIN " . self::P . "chart_master cm ON cm.account_code = gl.account
             JOIN " . self::P . "chart_types ct ON ct.id           = cm.account_type  
             JOIN " . self::P . "chart_class   cc ON cc.cid = ct.class_id
             WHERE gl.tran_date BETWEEN '$from'AND '$to' and   cc.class_name='$type' and (parent='$parent' or parent = '')
             and   (cc.ctype>3 OR cc.ctype=0)
             group by cm.account_name ";
              
        $rows = $this->kirima()->select($sqls); 
         if($type=='EXPENSES'){ 
        // dd($rows);
         }
        $total = collect($rows)->sum('total');
        return [$rows,$total];
    }
  private function glByClassGroupBydate(string $from, string $to,$type,$parent): array
    {
        // chart_class cc		JOIN 0_chart_types ct on ct.class_id=cc.cid		
        // WHERE !cc.inactive and ctype>3 OR ctype=0 and (parent = '' OR parent = '-1')   ORDER BY class_id, cid, parent
        $from = Carbon::parse($from)->format('Y-m-d');
         $to   = Carbon::parse($to)->format('Y-m-d');

        
           $sqls= "SELECT  gl.tran_date , SUM(amount*$parent) as total
             FROM    " . self::P . "gl_trans gl
             JOIN " . self::P . "chart_master cm ON cm.account_code = gl.account
             JOIN " . self::P . "chart_types ct ON ct.id           = cm.account_type  
             JOIN " . self::P . "chart_class   cc ON cc.cid = ct.class_id
             WHERE gl.tran_date BETWEEN '$from'AND '$to' and   cc.class_name='$type' and (parent='$parent' or parent = '')
             and   (cc.ctype>3 OR cc.ctype=0)
             group by gl.tran_date ";
              
        $rows = $this->kirima()->select($sqls);  
        $total = collect($rows)->sum('total');
        return [$rows,$total];
    }
    // ── 2. Sales category breakdown — single query ───────────────────────────

    private function salesByCategory(string $from, string $to): array
    {
        return $this->kirima()->select(
            "SELECT ct.name AS category,
                    IFNULL(SUM(gl.amount), 0) AS amount
             FROM " . self::P . "gl_trans gl
             JOIN " . self::P . "chart_master cm ON cm.account_code = gl.account
             JOIN " . self::P . "chart_types  ct ON ct.id           = cm.account_type
             JOIN " . self::P . "chart_class  cc ON cc.cid          = ct.class_id
             WHERE gl.tran_date BETWEEN ? AND ? AND cc.ctype = 1
             GROUP BY ct.id, ct.name
             ORDER BY amount DESC",
            [$from, $to]
        );
    }

    // ── 3. Store performance — single GROUP BY query (was N+1) ───────────────

    private function storePerformance(string $from, string $to): array
    { 
          $from = Carbon::parse($from)->format('Y-m-d');
         $to   = Carbon::parse($to)->format('Y-m-d');

        $rows = $this->kirima()->select(
            "SELECT
                 l.location_name,
                 move.loc_code,
                 IFNULL(SUM(-move.qty * move.price), 0)                                  AS revenue,
                 IFNULL(SUM(-IF(move.standard_cost <> 0,
                     move.qty * move.standard_cost,
                     move.qty * COALESCE(NULLIF(item.material_cost + item.labour_cost + item.overhead_cost, 0), item.purchase_cost, 0))), 0) AS cost
             FROM " . self::P . "stock_moves move
             JOIN " . self::P . "stock_master  item  ON item.stock_id  = move.stock_id
             JOIN " . self::P . "debtor_trans  trans ON trans.trans_no = move.trans_no
                                                    AND trans.type     = move.type
             JOIN " . self::P . "locations     l     ON l.loc_code     = move.loc_code
             WHERE move.tran_date BETWEEN ? AND ?
               AND (trans.type = 13 OR move.type = 11)
               AND l.inactive = 0
             GROUP BY move.loc_code, l.location_name
             HAVING revenue <> 0 OR cost <> 0
             ORDER BY revenue DESC",
            [$from, $to]
        );
        // $rows = $this->kirima()->select($sqls);
       
        $rowss = $this->kirima()->table(self::P.'stock_moves as move')
    ->selectRaw("
        l.location_name,
        move.loc_code,
        IFNULL(SUM(-move.qty * move.price),0) AS revenue,
        IFNULL(SUM(-IF(move.standard_cost <> 0,
            move.qty * move.standard_cost,
            move.qty * COALESCE(NULLIF(item.material_cost + item.labour_cost + item.overhead_cost, 0), item.purchase_cost, 0))),0) AS cost
    ")
    ->join(self::P.'stock_master as item','item.stock_id','=','move.stock_id')
    ->join(self::P.'stock_category as category','item.category_id','=','category.category_id')
    ->join(self::P.'debtor_trans as trans','trans.trans_no','=','move.trans_no')
    ->join(self::P.'debtors_master as debtor', function($join){
        $join->on('trans.debtor_no','=','debtor.debtor_no')
             ->on('trans.type','=','move.type');
    })
    ->join(self::P.'locations as l','l.loc_code','=','move.loc_code')
    ->whereDate('move.tran_date','>=',$from)
    ->whereDate('move.tran_date','<=',$to)
    ->where(function($q){
        $q->where('trans.type',13)
          ->orWhere('move.type',11);
    })
    ->where('l.inactive',0)
    ->groupBy('move.loc_code','l.location_name')
    ->havingRaw('revenue <> 0 OR cost <> 0')
    ->orderByDesc('revenue')
    ->get();
 
        return array_map(function ($r) {
            $revenue = (float) $r->revenue;
            $cost    = (float) $r->cost;
            $profit  = $revenue - $cost;
            $margin  = $revenue != 0 ? round(($profit / $revenue) * 100, 2) : 0;
            return [
                'location' => $r->location_name,
                'loc_code' => $r->loc_code,
                'revenue'  => $revenue,
                'cost'     => $cost,
                'profit'   => $profit,
                'margin'   => $margin,
            ];
        }, $rows);
    }
//get tare value and millk collections

    // ── 4. Milk traceability — 3 targeted queries (was 6+) ───────────────────

    /**
     * Compute raw farmer/scale/center/factory aggregates for a given date range.
     * This is the expensive DB operation — called by milkTraceability with incremental caching.
     */
    private function computeTraceabilityRaw(string $from, string $to): array
    {
        $cp = self::P;

        $scaleIds  = array_map(fn ($r) => (int) $r->supplier_id,
            $this->kirima()->select("SELECT supplier_id FROM {$cp}suppliers WHERE supp_name LIKE '%scale tare%'"));
        $farmerIds = array_map(fn ($r) => (int) $r->supplier_id,
            $this->kirima()->select("SELECT supplier_id FROM {$cp}suppliers WHERE supp_name NOT LIKE '%scale tare%' AND supp_type = 'farmer'"));

        $scaleIn  = implode(',', $scaleIds  ?: [0]);
        $farmerIn = implode(',', $farmerIds ?: [0]);
        $row = $this->kirima()->selectOne(
            "SELECT
                 ROUND(SUM(CASE WHEN po.supplier_id IN ($scaleIn)  THEN pod.quantity_ordered ELSE 0 END), 2) AS scale_qty,
                 ROUND(SUM(CASE WHEN po.supplier_id IN ($farmerIn) THEN pod.quantity_ordered ELSE 0 END), 2) AS farmer_qty
             FROM {$cp}purch_orders po FORCE INDEX (idx_cover_po_date)
             STRAIGHT_JOIN {$cp}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '0001'
             WHERE po.ord_date BETWEEN ? AND ?",
            [$from, $to]
        );
        
        $locRows = $this->kirima()->select(
            "SELECT l.location_type, IFNULL(ABS(SUM(sm.qty)), 0) AS qty
             FROM {$cp}stock_moves sm
             JOIN {$cp}locations l ON l.loc_code = sm.loc_code
             WHERE sm.stock_id = '0001'
               AND l.location_type IN (2, 3)
               AND sm.tran_date BETWEEN ? AND ?
             GROUP BY l.location_type",
            [$from, $to]
        );
        $centerQty = 0.0; $factoryQty = 0.0;
        foreach ($locRows as $r) {
            if ($r->location_type == 2) $centerQty  = (float) $r->qty;
            if ($r->location_type == 3) $factoryQty = (float) $r->qty;
        }

        return [
            'farmer_qty'  => (float) ($row->farmer_qty  ?? 0),
            'scale_qty'   => (float) ($row->scale_qty   ?? 0),
            'center_qty'  => $centerQty,
            'factory_qty' => $factoryQty,
        ];
    }

    private function milkTraceability(string $from, string $to): array
    {
        $raw = $this->computeTraceabilityRaw($from, $to);

        $farmerQty  = $raw['farmer_qty'];
        $scaleQty   = $raw['scale_qty'];
        $centerQty  = $raw['center_qty'];
        $factoryQty = $raw['factory_qty'];

        return [
            ['icon' => '🧑‍🌾', 'label' => 'Farmers',  'qty' => $farmerQty,  'status' => 'ok'],
            ['icon' => '🚛',   'label' => 'Grader',   'qty' => $farmerQty,  'status' => 'ok'],
            ['icon' => '🏢',   'label' => 'Center',   'qty' => $centerQty,  'status' => 'ok'],
            ['icon' => '⚖️',  'label' => 'Tare',     'qty' => $scaleQty,   'status' => $scaleQty > $farmerQty * 0.1 ? 'warn' : 'ok'],
            ['icon' => '🏭',   'label' => 'Factory',  'qty' => $factoryQty, 'status' => 'ok'],
            ['icon' => '🛒',   'label' => 'Sold',     'qty' => 0,           'status' => 'ok'],
        ];
    }

    /**
     * GET /api/dashboard/milk-chain?from=&to=
     * Separate endpoint so the main summary doesn't block on this slow query.
     */
    public function milkChain(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from = Carbon::parse($request->from)->format('Y-m-d');
        $to   = Carbon::parse($request->to)->format('Y-m-d');

        try {
            $data = $this->milkTraceability($from, $to);
            return ApiResponse::success($data, 'Milk chain retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Milk chain query failed: ' . $e->getMessage());
        }
    }
    // ── 5. P&L sparklines — single query for all three periods ───────────────
    private function plSparkles(array $byClass, string $from, string $to): array
    {
        $today    = date('Y-m-d');
        $mtdStart = date('Y-m-01');
        $ytdStart = date('Y-01-01');
        $from = Carbon::parse($from)->format('Y-m-d');
        $to   = Carbon::parse($to)->format('Y-m-d');
        // $from = Carbon::parse($from)->format('Y-m-d');
        //  $to   = Carbon::parse($to)->format('Y-m-d');


        // One query, three conditional aggregates
        $periods = $this->kirima()->selectOne(
            "SELECT
                 IFNULL(SUM(CASE WHEN gl.tran_date = ?            AND cc.ctype = 1 THEN gl.amount ELSE 0 END), 0) AS rev_today,
                 IFNULL(SUM(CASE WHEN gl.tran_date BETWEEN ? AND ? AND cc.ctype = 1 THEN gl.amount ELSE 0 END), 0) AS rev_mtd,
                 IFNULL(SUM(CASE WHEN gl.tran_date BETWEEN ? AND ? AND cc.ctype = 1 THEN gl.amount ELSE 0 END), 0) AS rev_ytd,
                 IFNULL(SUM(CASE WHEN gl.tran_date BETWEEN ? AND ? AND cc.ctype = 2 THEN gl.amount ELSE 0 END), 0) AS cogs_period,
                 IFNULL(SUM(CASE WHEN gl.tran_date BETWEEN ? AND ? AND cc.ctype = 3 THEN gl.amount ELSE 0 END), 0) AS exp_period
             FROM " . self::P . "gl_trans gl
             JOIN " . self::P . "chart_master cm ON cm.account_code = gl.account
             JOIN " . self::P . "chart_types  ct ON ct.id           = cm.account_type
             JOIN " . self::P . "chart_class  cc ON cc.cid          = ct.class_id",
            [
                $today,
                $mtdStart, $today,
                $ytdStart, $today,
                $from, $to,
                $from, $to,
            ]
        );

        $revPeriod = abs($byClass[1]['total'] ?? 0);
        $revMtd    = abs((float) ($periods->rev_mtd    ?? 0));
        $revYtd    = abs((float) ($periods->rev_ytd    ?? 0));
        $cogsPrd   = abs((float) ($periods->cogs_period ?? 0));
        $expPrd    = abs((float) ($periods->exp_period  ?? 0));
        $netPrd    = $revPeriod - $cogsPrd - $expPrd;
        $netPct    = $revPeriod > 0 ? round(($netPrd / $revPeriod) * 100, 2) : 0;

        $rows   = [['cat' => 'Total Revenue', 'period' => $revPeriod, 'mtd' => $revMtd, 'ytd' => $revYtd, 'bold' => true]];
        $cats   = $this->salesByCategory($from, $to);
        foreach ($cats as $c) {
            $rows[] = ['cat' => $c->category, 'period' => (float) $c->amount, 'mtd' => 0, 'ytd' => 0];
        }
        $rows[] = ['cat' => 'Net Profit', 'period' => $netPrd, 'mtd' => 0, 'ytd' => $netPct . '%', 'bold' => true, 'profit' => true];

        return $rows;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PUBLIC ENDPOINTS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/dashboard/summary?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
   
    public function stores(){
        try {
            // stock_moves has 1.7M+ rows — DISTINCT scan with LIKE filters takes 13s+.
            // Stores almost never change, so cache for 1 hour.
            $items = Cache::remember('dashboard_stores_list', 3600, function () {
                $sqls = "SELECT * FROM " . self::P . "locations WHERE loc_code IN (
                    SELECT DISTINCT loc_code FROM " . self::P . "stock_moves
                    WHERE loc_code NOT LIKE '%T0%' AND loc_code NOT LIKE '%VET%'
                )";
                return $this->kirima()->select($sqls);
            });
            return ApiResponse::success($items, "Stores Retrieved");
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Stores query failed: ' . $e->getMessage());
        }
    }
    function creditstatus($cp,$debtor_no,$from,$to) {
    // header("Content-Type: application/json"); 
    // $cp=$_POST['cp'];
    // $debtor_no = $_POST['debtor_no'];
    global $conn;
    try {  
        $today = Carbon::parse($to);
        $dayOfMonth = $today->day;
        $multiplier=20;
    // --- If first day of the month, get last 5 days of previous month ---
    if ($dayOfMonth >= 1 && $dayOfMonth <= 20) {
       // Parse date
    $today = Carbon::parse($to); 
    // End date = yesterday
    $endDateObj = $today->copy()->subDay();
    $enddays = $endDateObj->toDateString(); 
    // Start date = 4 days before end date (total 5 days range)
    $startDateObj = $endDateObj->copy()->subDays(4);
    $startDate = $startDateObj->toDateString(); 
    // End of month (same as Y-m-t)
    $endDate = $endDateObj->copy()->endOfMonth()->toDateString(); 
    // Difference in days
    $totaldays = $startDateObj->diffInDays($endDateObj);
        $sql=" SELECT sp.supplier_id FROM 0_debtors_master dm
                JOIN {$cp}suppliers sp on sp.member_no=dm.cust_no
                 WHERE debtor_no='$debtor_no'"; 
        $resultc = $conn->query($sql);  
        $row =  $resultc?$resultc->fetch_all(MYSQLI_ASSOC)[0]:[];
        $supplierId = isset($row['supplier_id']) ? $row['supplier_id'] : 0;
        // echo $enddays.'polked'.$startDate.'uyo'.$totaldays;
        $credits=$this->getCreditWorth(100, $supplierId, $startDate, $enddays, $cp,$totaldays,0,$multiplier);
        // echo json_encode($credits);
//          "data": {
//     "credit_limit": 20000,
//     "credit_invoices_allowed": 2,
//     "total_debt": 860,
//     "balance_available": 19140,
//     "no_of_unpaid_invoices": 3
//   }
        $startDateObj = clone $today;
        $startDate = $startDateObj->modify('first day of this month')->format('Y-m-d');
        $endDate=date('Y-m-t');
        $sql = "SELECT 
            dm.credit_limit,
            dm.credit_invoices_allowed,
            COALESCE(
                (SELECT Sum(IFNULL(
                    IF(`type` IN(11,12,2), -1, 1) * 
                    (trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount),
                    0
                ))
                FROM {$cp}debtor_trans trans 
                WHERE trans.type <> 13 
                AND trans.debtor_no = '$debtor_no' and trans.tran_date between '$startDate' and '$endDate' ),
                0
            ) as total_debt,
            (dm.credit_limit - 
                COALESCE(
                    (SELECT Sum(IFNULL(
                        IF(`type` IN(11,12,2), -1, 1) * 
                        (trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount),
                        0
                    ))
                    FROM {$cp}debtor_trans trans 
                    WHERE trans.type <> 13 
                    AND trans.debtor_no = '$debtor_no' and trans.tran_date between '$startDate' and '$endDate' ),
                    0
                )
            ) AS balance_available,
            COALESCE(
                (SELECT Sum(IFNULL(
                    IF(`type` IN(11,12,2), -1, 1) * 
                    (trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount),
                    0
                ))
                FROM {$cp}debtor_trans trans 
                WHERE trans.type <> 13 
                AND trans.debtor_no = '$debtor_no' and trans.tran_date < '$startDate' ),
                0
            ) as prev_outstanding_debt,
            (SELECT COUNT(*)
            FROM {$cp}debtor_trans dt
            WHERE dm.debtor_no = dt.debtor_no 
            AND dt.type = 10
            AND dt.ov_amount <> dt.alloc and dt.tran_date between '$startDate' and '$endDate' ) AS no_of_unpaid_invoices 
        FROM {$cp}debtors_master dm
        WHERE debtor_no = ?";
        
        //echo $sql;
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $debtor_no);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if (!$row) {
            throw new Exception("Customer not found");
        }
        
        // Round numerical values $totaldays
        $keys="average_for_last_".$totaldays."_days";
        $row[$keys]=$totaldays;
        $row['credit_limit']=$credits['average_worth'];
        $row["total_days"]=$totaldays;
        $row["total_worth"]=$credits['total_worth'];
        $row['credit_limit'] = round(floatval($row['credit_limit']!=null?$row['credit_limit']:0), 2);
        $row['balance_available'] = round(floatval($credits['average_worth']>0&&$credits['average_worth']!=null?$credits['average_worth']-$row['total_debt']:0), 2);
        $row['total_debt'] = round(floatval($row['total_debt']), 2);
    }else{
        // First day of current month
        // $startDateObj = clone $today;
        // $startDate = $startDateObj->modify('first day of this month')->format('Y-m-d');
        $multiplier=1;
        // $endDate=date('Y-m-d');
        /* $endDateObj = clone $today;
        //$endDateObj = $endDateObj->modify('-1 day');
        // $endDateObj = $endDateObj->modify('+4 day');
        $endDate= $endDateObj->format('Y-m-d');

        $startDateObj = clone $endDateObj;
        //$startDateObj =$startDateObj->modify('-4 days');
        $startDateObj = new DateTime($startDateObj->format('Y-m-01'));
        $startDate = $startDateObj->format('Y-m-d');
        $startDateObj = new DateTime($startDate); 
        $endDateObj   = new DateTime($endDate);
    //    echo json_encode([$startDate,$endDate]);
        $interval = $startDateObj->diff($endDateObj);
        $totaldays = $interval->days ; */
        // Parse your date
        $today = Carbon::parse($to);

        // End date = today (no subtraction as per your code)
        $endDateObj = $today->copy();
        $endDate = $endDateObj->toDateString();

        // Start date = first day of the same month
        $startDateObj = $endDateObj->copy()->startOfMonth();
        $startDate = $startDateObj->toDateString();

        // Difference in days
        $totaldays = $startDateObj->diffInDays($endDateObj);
        $sql=" SELECT sp.supplier_id FROM {$cp}debtors_master dm
                JOIN {$cp}suppliers sp on sp.member_no=dm.cust_no
                 WHERE debtor_no='$debtor_no'"; 
        $resultc = $this->kirima()->select($sql);  
        $row =  $resultc;//?$resultc->fetch_all(MYSQLI_ASSOC)[0]:[];
        $supplierId = isset($row[0]) ? $row[0]->supplier_id : 0; 
        $credits=$this->getCreditWorth(100, $supplierId, $startDate, $endDate, $cp,$totaldays,1,$multiplier);
        //    echo json_encode($credits);
        $creditworth=$credits['average_worth'];
        $total_worth=$credits['total_worth'];

        $startDateObj = clone $today;
        $startDate = $startDateObj->modify('first day of this month')->format('Y-m-d');
        $sql = "SELECT 
            dm.credit_limit,
            dm.credit_invoices_allowed,
            COALESCE(
                (SELECT Sum(IFNULL(
                    IF(`type` IN(11,12,2), -1, 1) * 
                    (trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount),
                    0
                ))
                FROM {$cp}debtor_trans trans 
                WHERE trans.type <> 13 
                AND trans.debtor_no = '$debtor_no'),
                0
            ) as total_debt,
            (dm.credit_limit - 
                COALESCE(
                    (SELECT Sum(IFNULL(
                        IF(`type` IN(11,12,2), -1, 1) * 
                        (trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount),
                        0
                    ))
                    FROM {$cp}debtor_trans trans 
                    WHERE trans.type <> 13 
                    AND trans.debtor_no = '$debtor_no'),
                    0
                )
            ) AS balance_available,
			COALESCE(
                (SELECT Sum(IFNULL(
                    IF(`type` IN(11,12,2), -1, 1) * 
                    (trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount),
                    0
                ))
                FROM {$cp}debtor_trans trans 
                WHERE trans.type <> 13 
                AND trans.debtor_no = '$debtor_no' and trans.tran_date < '$startDate' ),
                0
            ) as prev_outstanding_debt,
            (SELECT COUNT(*)
            FROM {$cp}debtor_trans dt
            WHERE dm.debtor_no = dt.debtor_no 
            AND dt.type = 10
            AND dt.ov_amount <> dt.alloc) AS no_of_unpaid_invoices
        FROM {$cp}debtors_master dm
        WHERE debtor_no = '$debtor_no'";
        
        $row = $this->kirima()->select($sql);
        
         
        //   echo json_encode($row);
        // Round numerical values
        $keys="average_for_last_".$totaldays."_days";
        // $row[$keys]=$totaldays;
        $row[0]->total_days=$totaldays;
        $row[0]->total_worth=$total_worth;
        $row[0]->credit_limit =$total_worth;// round(floatval($row['credit_limit']), 2);
        $row[0]->credit_limit = round(floatval($row[0]->credit_limit!=null?$row[0]->credit_limit:0), 2);
        $row[0]->balance_available = round(floatval($credits['average_worth']>0&&$credits['average_worth']!=null?$credits['average_worth']-$row[0]->total_debt:0), 2);
        $row[0]->total_debt = round(floatval($row[0]->total_debt), 2);
    } 
    // echo $credits['average_worth']-$row['total_debt'];
    // echo json_encode($row);
    return $row;
    //  echo json_encode(array(
    //         "status" => "SUCCESS",
    //         "data" => $row
    //     ));
} catch (Exception $e) {
        return [];
    }
}
function getCreditWorth($totalRequest = 0, $supplierId, $startDate, $endDate, $dbPrefix,$totaldays,$aver=0,$multipliers)
{ 
  $sql = "
        SELECT 
            SUM(pod.qty_invoiced * pod.unit_price) AS total_worth
        FROM 
            {$dbPrefix}supp_trans st
        INNER JOIN 
            {$dbPrefix}supp_invoice_items sit ON sit.supp_trans_no = st.trans_no
        INNER JOIN 
            {$dbPrefix}purch_order_details pod ON pod.po_detail_item = sit.po_detail_item_id
        WHERE 
            st.type = '20'
            AND st.supplier_id = '$supplierId'
            AND st.tran_date BETWEEN '$startDate' AND '$endDate'
    ";  
    $row = $this->kirima()->select($sql);

    $totalWorth = isset($row) ? (float)$row[0]->total_worth : 0.0;
    // $totalWorth = isset($ammmt1) ? (float)$ammmt1 : 0.0;
    //   echo $ammmt1;
//     $today = new DateTime('today');
//    $target = new DateTime('2025-12-20');

// if ($today >= $target) {
   //  echo $multipliers;
//    // echo $endDate;
//    // echo $startDate;
//     $multipliers=1;
// }
    $totaldays=$totaldays+1;
    if($aver==1){
$worthallowed=$totalWorth>0?($totalWorth/$totaldays)*$multipliers:0;
    }else{
$worthallowed=$totalWorth>0?($totalWorth/$totaldays)*$multipliers:0;
    }
//     if ($today >= $target) {
//     $worthallowed=$totalWorth;
// }
       
    return array(
        'is_within_limit' => ($totalWorth > $totalRequest),
        'average_worth'     => round($worthallowed, 2),
        'total_worth'     => round($totalWorth, 2),
        'range_start'     => $startDate,
        'range_end'       => $endDate
    );
}
//Check Farmer status - returns the highest risk farmer (highest feeds credit)
function getHighRiskFarmer(Request $request) {
    $cp = self::P;
    $sql = "SELECT cust.name,
              cust.credit_limit,
              cust.credit_limit - Sum(IFNULL(IF(trans.type IN(11,12,2),
                -1, 1) * (ov_amount + ov_gst + ov_freight + ov_freight_tax + ov_discount),0)) as cur_credit,
              Sum(IFNULL(IF(trans.type IN(11,12,2),
                -1, 1) * (ov_amount + ov_gst + ov_freight + ov_freight_tax + ov_discount),0)) as used_credit
            FROM {$cp}debtors_master cust
              LEFT JOIN {$cp}debtor_trans trans ON trans.type!=13 AND trans.debtor_no = cust.debtor_no,
              {$cp}credit_status credit_status,
              {$cp}sales_types stype,
              {$cp}suppliers s
            WHERE cust.sales_type=stype.id
              AND cust.credit_status=credit_status.id
              AND s.member_no=cust.cust_no
              AND s.supp_type='Farmer'
            GROUP BY cust.debtor_no, cust.name, cust.credit_limit
            ORDER BY cur_credit ASC
            LIMIT 1";

    $cacheKey = 'dashboard_high_risk_farmer_v2';
    $result   = Cache::store('file')->get($cacheKey);
    if ($result === null) {
        $result = $this->kirima()->select($sql);
        Cache::store('file')->put($cacheKey, $result, 300);
    }

    if (empty($result)) {
        return ApiResponse::success(null, 'No farmer data found');
    }

    $f            = (array) $result[0];
    $limit        = (float) $f['credit_limit'];
    $used         = (float) $f['used_credit'];
    $cur          = (float) $f['cur_credit'];
    $pct          = $limit > 0 ? round(($used / $limit) * 100, 2) : 0;
    $exceeded_pct = $cur < 0 ? ($limit > 0 ? round((abs($cur) / $limit) * 100, 2) : 100) : 0;

    return ApiResponse::success([
        'farmer_name'  => $f['name'],
        'credit_limit' => $limit,
        'used_credit'  => $used,
        'cur_credit'   => $cur,
        'limit_pct'    => $pct,
        'exceeded_pct' => $exceeded_pct,
    ], 'High risk farmer retrieved');
}
public function getGraderHighestVariance(Request $request)
{
    $cp    = self::P;
    $date  = $request->input('date', date('Y-m-d'));
    $today = Carbon::parse($date);
    $firstDay = $today->copy()->startOfMonth()->toDateString();
    $lastDay  = $today->copy()->endOfMonth()->toDateString();

    try {
        $cacheKey = "grader_variance_{$firstDay}_{$lastDay}";

        $highest = Cache::remember($cacheKey, 300, function () use ($cp, $firstDay, $lastDay) {
            $sql = "
                SELECT
                    move.loc_code,
                    loc.location_name AS grader_name,
                    SUM(CASE WHEN move.qty > 0 THEN move.qty ELSE 0 END) AS qty_colected,
                    SUM(CASE WHEN move.qty < 0 THEN move.qty ELSE 0 END) AS qty_delivered,
                    ROUND(
                        CASE
                            WHEN SUM(CASE WHEN move.qty > 0 THEN move.qty ELSE 0 END) = 0 THEN 0
                            ELSE (
                                SUM(CASE WHEN move.qty > 0 THEN move.qty ELSE 0 END)
                                + SUM(CASE WHEN move.qty < 0 THEN move.qty ELSE 0 END)
                            ) / SUM(CASE WHEN move.qty > 0 THEN move.qty ELSE 0 END) * 100
                        END, 2
                    ) AS variance_pct
                FROM {$cp}stock_moves move
                JOIN {$cp}locations loc ON loc.loc_code = move.loc_code
                WHERE move.stock_id   = '0001'
                  AND move.loc_code   LIKE 'T0%'
                  AND move.loc_code   NOT LIKE '%VET%'
                  AND move.tran_date  BETWEEN '$firstDay' AND '$lastDay'
                GROUP BY move.loc_code, loc.location_name
                ORDER BY variance_pct DESC
                LIMIT 1
            ";

            $row = $this->kirima()->selectOne($sql);
            if ($row) {
                $row->variance = $row->qty_colected + $row->qty_delivered;
            }
            return $row;
        });

        return ApiResponse::success($highest, 'Grader variance retrieved');
    } catch (\Throwable $th) {
        return ApiResponse::serverError($th->getMessage());
    }
}
    public function getGraderHighestVariances(Request $request): JsonResponse
    {
        $cp   = self::P;
        $date = $request->input('date', date('Y-m-d'));

        try {
            $rows = $this->kirima()->select("
                SELECT
                    l.location_name                                                          AS grader_name,
                    sm.loc_code,
                    ROUND(SUM(CASE WHEN sm.qty > 0 THEN sm.qty ELSE 0 END), 2)              AS delivered,
                    ROUND(ABS(SUM(CASE WHEN sm.qty < 0 THEN sm.qty ELSE 0 END)), 2)         AS transferred,
                    ROUND(SUM(sm.qty), 2)                                                    AS variance,
                    CASE
                        WHEN SUM(CASE WHEN sm.qty > 0 THEN sm.qty ELSE 0 END) > 0
                        THEN ROUND(
                            ABS(SUM(sm.qty)) / SUM(CASE WHEN sm.qty > 0 THEN sm.qty ELSE 0 END) * 100
                        , 2)
                        ELSE 0
                    END                                                                      AS variance_pct
                FROM {$cp}stock_moves sm
                JOIN {$cp}locations l ON l.loc_code = sm.loc_code
                WHERE sm.stock_id = '0001'
                  AND sm.tran_date = ?
                  AND sm.trans_id > 0
                GROUP BY sm.loc_code, l.location_name
                HAVING delivered > 0
                ORDER BY variance_pct DESC
                LIMIT 1
            ", [$date]);

            if (empty($rows)) {
                return ApiResponse::success(null, 'No grader data found');
            }

            $g = (array) $rows[0];

            return ApiResponse::success([
                'grader_name'  => $g['grader_name'],
                'delivered'    => (float) $g['delivered'],
                'transferred'  => (float) $g['transferred'],
                'variance'     => (float) $g['variance'],
                'variance_pct' => (float) $g['variance_pct'],
            ], 'Grader variance retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Grader variance query failed: ' . $e->getMessage());
        }
    }

    public function getHighRiskFarmers(Request $request): JsonResponse
    {
        $cp = self::P;
        try {
            $sql = "
                SELECT
                    dm.debtor_no,
                    dm.name         AS farmer_name,
                    dm.credit_limit,
                    s.member_no,
                    COALESCE((
                        SELECT SUM(ov_amount - alloc)
                        FROM {$cp}debtor_trans
                        WHERE debtor_no = dm.debtor_no
                          AND (TYPE = '10' OR TYPE = '0')
                          AND tran_date >= LAST_DAY(CURRENT_TIMESTAMP - INTERVAL 1 MONTH) + INTERVAL 1 DAY
                          AND tran_date <= LAST_DAY(CURRENT_TIMESTAMP)
                          AND trans_no IN (
                              SELECT debtor_trans_no
                              FROM {$cp}debtor_trans_details
                              WHERE (debtor_trans_type = '10' OR debtor_trans_type = '0')
                                AND stock_id IN (
                                    SELECT item_code FROM {$cp}item_codes WHERE item_cat = 'Feeds'
                                )
                          )
                    ), 0) AS store_credit,
                    COALESCE((
                        SELECT ROUND(SUM(dtd.unit_price * dtd.quantity), 2)
                        FROM {$cp}debtor_trans dt
                        INNER JOIN {$cp}debtor_trans_details dtd ON dt.trans_no = dtd.debtor_trans_no
                        WHERE dt.debtor_no = dm.debtor_no
                          AND dt.tran_date BETWEEN DATE_ADD(DATE_ADD(LAST_DAY(CURRENT_TIMESTAMP), INTERVAL 1 DAY), INTERVAL -1 MONTH) AND LAST_DAY(CURRENT_TIMESTAMP)
                          AND dtd.debtor_trans_type = 10
                          AND dt.type = 10
                    ), 0) AS total_invoiced_feeds,
                    COALESCE((
                        SELECT ROUND(SUM(
                            (SELECT rate FROM {$cp}payment_details pd ORDER BY pd.trans_date DESC LIMIT 1)
                            * pod.quantity_received
                        ), 2)
                        FROM {$cp}purch_orders po
                        INNER JOIN {$cp}purch_order_details pod ON pod.order_no = po.order_no
                        WHERE po.supplier_id = s.supplier_id
                          AND po.ord_date BETWEEN DATE_ADD(DATE_ADD(LAST_DAY(CURRENT_TIMESTAMP), INTERVAL 1 DAY), INTERVAL -1 MONTH) AND LAST_DAY(CURRENT_TIMESTAMP)
                    ), 0) AS total_value_of_milk
                FROM {$cp}suppliers s
                JOIN {$cp}debtors_master dm ON dm.cust_no = s.member_no
                ORDER BY store_credit DESC
                LIMIT 1
            ";
            $cacheKey = 'dashboard_high_risk_farmer';
            $fileCache = Cache::store('file');
            $rows = $fileCache->get($cacheKey);
            if ($rows === null) {
                $rows = $this->kirima()->select($sql);
                try { $fileCache->put($cacheKey, $rows, 300); } catch (\Throwable) {}
            }

            if (empty($rows)) {
                return ApiResponse::success(null, 'No farmer data found');
            }

            $f = (array) $rows[0];
            $limit = (float) $f['credit_limit'];
            $used  = (float) $f['total_invoiced_feeds'];
            $milk  = (float) $f['total_value_of_milk'];
            $pct   = $limit > 0 ? round(($used / $limit) * 100, 2) : 0;

            return ApiResponse::success([
                'farmer_name'          => $f['farmer_name'],
                'store_credit'         => (float) $f['store_credit'],
                'credit_limit'         => $limit,
                'total_invoiced_feeds' => $used,
                'total_value_of_milk'  => $milk,
                'limit_pct'            => $pct,
            ], 'High risk farmer retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('High risk farmer query failed: ' . $e->getMessage());
        }
    }

    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from = $this->fmt($request->from);
        $to   = $this->fmt($request->to);
        $from = Carbon::parse($from)->format('Y-m-d');
        $to   = Carbon::parse($to)->format('Y-m-d');

        // Cache longer for historical dates, shorter for today
        $isToday = $to === now()->format('Y-m-d');
        $ttl     = $isToday ? 300 : 86400; // 5 min live, 24h historical
        $cacheKey = "dashboard_summary_{$from}_{$to}";

        try {
            $data = Cache::remember($cacheKey, $ttl, function () use ($from, $to, $request) {
                $byClassIncome   = $this->glByClass($from, $to, 'INCOME',   -1);
                $byClassExpenses = $this->glByClass($from, $to, 'EXPENSES', -1);
                //monthly revenues
                $byClassIndailly = $this->glByClassGroupBydate($from, $to, 'INCOME', -1);
                $byClassExpensesdaily = $this->glByClassGroupBydate($from, $to, 'EXPENSES', -1);

                // dd($byClassIndailly,$byClassExpensesdaily );

                $revenue     = $byClassIncome[1];
                $cogs        = abs(collect($byClassExpenses[0])->filter(fn($r) => str_starts_with(strtolower($r->class_name), 'cogs-')
                                || str_starts_with(strtolower($r->class_name), 'cost'))->sum('total'));
                $expenses    = abs($byClassExpenses[1]);
                $expensesy   = $byClassExpenses[0];
                $grossProfit = $revenue - $cogs;
                $netProfit   = $revenue - $expenses;
                $netPct      = $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0;

                // Build graphdata: merge daily income + expenses by date
                $incomeByDate   = collect($byClassIndailly[0])->keyBy('tran_date');
                $expenseByDate  = collect($byClassExpensesdaily[0])->keyBy('tran_date');
                $allDates       = $incomeByDate->keys()->merge($expenseByDate->keys())->unique()->sort()->values();
                $graphdata      = $allDates->map(function ($date) use ($incomeByDate, $expenseByDate) {
                    $revenue = (float) round($incomeByDate->get($date)?->total  ?? 0, 2);
                    $cost    = (float) round(abs($expenseByDate->get($date)?->total ?? 0), 2);
                    $profit  = round($revenue - $cost, 2);
                    return [
                        'tran_date' => Carbon::parse($date)->format('M j'),
                        'revenue'   => $revenue,
                        'cost'      => $cost,
                        'profit'    => $profit,
                        'margin'    => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                    ];
                })->values();
                // dd( $graphdata );
                return [
                    'revenue'        => $revenue,
                    'expenses'       => [$expensesy, $expenses],
                    'cogs'           => $revenue > 0 ? $cogs : 0,
                    'grossProfit'    => $revenue > 0 ? $grossProfit : 0,
                    'net_profit'     => $revenue > 0 ? $netProfit : 0,
                    'net_profit_pct' => $netPct,
                    'sales_category' => $byClassIncome[0],
                    'stores'         => [],
                    'milk_chain'     => [],
                    'sparkles'       => [],
                    'period'         => ['from' => $from, 'to' => $to],
                    'graphdata'      => $graphdata,
                ];
            });

            return ApiResponse::success($data, 'Dashboard summary retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Dashboard query failed: ' . $e->getMessage());
        }
    }
 function storerevenue(Request $request): array
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
        $from   = $this->fmt($request->from);
        $to     = $this->fmt($request->to);
        $stores = $request->stores;
        $loc    = $stores ?? '';
        $locTag = $stores ?? 'all';

        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $fileCache = Cache::store('file');

        // Raw compute for any sub-range
        $compute = function (string $f, string $t) use ($loc): array {
            $storeRows = collect($this->getTransactions($f, $t, 1, 1, $loc));
            $revenues  = $storeRows
                ->groupBy('loc_code')
                ->map(function ($items) {
                    $revenue = (float) round($items->sum('amt'), 2);
                    $cost    = $items->sum('price') <= 0 || $items->sum('price') == ''
                        ? (float) round($items->sum('cost'), 2)
                        : (float) round($items->sum('price'), 2);
                    $profit  = $revenue - $cost;
                    return [
                        'location' => $items->first()->location_name,
                        'loc_code' => $items->first()->loc_code,
                        'revenue'  => $revenue,
                        'cost'     => $cost,
                        'costs'    => (float) round($items->sum('cost'), 2),
                        'prices'   => (float) round($items->sum('price'), 2),
                        'profit'   => $profit,
                        'margin'   => $revenue ? round(($profit / $revenue) * 100, 2) : 0,
                    ];
                })
                ->sortByDesc('revenue')
                ->values()
                ->toArray();

            $locFilter = $loc ? 'AND move.loc_code = ?' : '';
            $bindings  = $loc ? [$f, $t, $loc] : [$f, $t];
            $chartRows = $this->kirima()->select(
                "SELECT move.tran_date,
                        SUM(-move.qty * move.price) AS amt,
                        SUM(-IF(move.standard_cost <> 0,
                                move.qty * move.standard_cost,
                                move.qty * item.material_cost)) AS cost
                 FROM " . self::P . "stock_moves move FORCE INDEX (idx_tran_date_type)
                 STRAIGHT_JOIN " . self::P . "stock_master item ON item.stock_id = move.stock_id
                 WHERE move.tran_date BETWEEN ? AND ?
                   AND move.type IN (13, 11)
                   AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')
                   $locFilter
                 GROUP BY move.tran_date
                 ORDER BY move.tran_date",
                $bindings
            );

            $chart = collect($chartRows)->map(function ($row) {
                $revenue = (float) round($row->amt,  2);
                $cost    = (float) round($row->cost, 2);
                $profit  = $revenue - $cost;
                return [
                    'tran_date' => Carbon::parse($row->tran_date)->format('M j'),
                    'revenue'   => $revenue,
                    'cost'      => $cost,
                    'profit'    => $profit,
                    'margin'    => $revenue ? round(($profit / $revenue) * 100, 2) : 0,
                ];
            })->values()->toArray();

            return [$revenues, $chart];
        };

        // Incremental: historical cached permanently + today cached 5 min
        if ($to === $today && $from < $today) {
            $histKey  = "store_revenue_hist_{$from}_{$yesterday}_{$locTag}";
            $todayKey = "store_revenue_today_{$today}_{$locTag}";

            $histData = $fileCache->get($histKey);
            if ($histData === null) {
                $histData = $compute($from, $yesterday);
                $fileCache->put($histKey, $histData, 86400 * 60);
            }

            $todayData = $fileCache->get($todayKey);
            if ($todayData === null) {
                $todayData = $compute($today, $today);
                $fileCache->put($todayKey, $todayData, 300);
            }

            [$histRevenues, $histChart]   = $histData;
            [$todayRevenues, $todayChart] = $todayData;

            $merged = collect($histRevenues)->keyBy('loc_code');
            foreach ($todayRevenues as $row) {
                $key = $row['loc_code'];
                if ($merged->has($key)) {
                    $e   = $merged[$key];
                    $rev = round($e['revenue'] + $row['revenue'], 2);
                    $cst = round($e['cost']    + $row['cost'],    2);
                    $prf = $rev - $cst;
                    $merged[$key] = array_merge($e, [
                        'revenue' => $rev,
                        'cost'    => $cst,
                        'costs'   => round($e['costs']  + $row['costs'],  2),
                        'prices'  => round($e['prices'] + $row['prices'], 2),
                        'profit'  => $prf,
                        'margin'  => $rev ? round(($prf / $rev) * 100, 2) : 0,
                    ]);
                } else {
                    $merged[$key] = $row;
                }
            }

            $revenues = $merged->sortByDesc('revenue')->values()->toArray();
            $grouped  = collect(array_merge($histChart, $todayChart))->values();
            return [$revenues, $grouped];
        }

        // Fully historical or today-only
        $fullKey = "store_revenue_{$from}_{$to}_{$locTag}";
        if ($cached = $fileCache->get($fullKey)) {
            return $cached;
        }

        [$revenues, $chart] = $compute($from, $to);
        $grouped = collect($chart)->values();
        $result  = [$revenues, $grouped];
        $fileCache->put($fullKey, $result, $to === $today ? 300 : 86400 * 60);

        return $result;
    }
    public function getTransactions($from, $to, $summary, $datesummary, $location = '')
    {
        return [];
        // ── SELECT ────────────────────────────────────────────────────────────
        $select = "SELECT
            item.category_id,
            category.description AS cat_description,
            item.stock_id,
            item.description,
            item.inactive,
            item.mb_flag,
            move.loc_code";

        if ($summary) {
            $select .= ",\n            l.location_name";
        }

        if (!$summary) {
            $select .= ",\n            trans.debtor_no,\n            debtor.name AS debtor_name";
        }

        if (!$datesummary) {
            $select .= ",\n            move.tran_date";
        }

        $P = self::P;

        $select .= ",
            SUM(-move.qty)                                                          AS qty,
            SUM(-move.qty * move.price)                                             AS amt,
            SUM(-IF(move.standard_cost <> 0,
                    move.qty * move.standard_cost,
                    move.qty * item.material_cost))                                 AS cost,
            SUM(-move.qty) * MAX(COALESCE(pp.price, 0))                            AS price";

        // ── FROM ──────────────────────────────────────────────────────────────- 
        $joins = "FROM {$P}stock_moves    move  FORCE INDEX (idx_tran_date_type)
            JOIN {$P}debtor_trans  trans ON  trans.trans_no = move.trans_no
                                         AND trans.type     = move.type
            JOIN {$P}stock_master  item  ON  item.stock_id  = move.stock_id
            JOIN {$P}stock_category category ON category.category_id = item.category_id
            LEFT JOIN (
                SELECT stock_id, price
                FROM (
                    SELECT stock_id, price,
                           ROW_NUMBER() OVER (PARTITION BY stock_id ORDER BY tran_date) AS rn
                    FROM {$P}stock_moves FORCE INDEX (idx_tran_date_type)
                    WHERE tran_date >= '$from'
                      AND tran_date <= '$to'
                      AND (type = 25 OR type = 21)
                ) ranked
                WHERE rn = 1
            ) pp ON pp.stock_id = item.stock_id";

        if ($summary) {
            $joins .= "\n            JOIN {$P}locations      l      ON  l.loc_code      = move.loc_code";
        } else {
            $joins .= "\n            JOIN {$P}debtors_master debtor ON  debtor.debtor_no = trans.debtor_no";
        }

        // move.type = trans.type is guaranteed by the JOIN condition, so
        // IN(13,11) is equivalent to (trans.type=13 OR move.type=11) and
        // lets the optimizer use the idx_tran_date_type index on the leading column.
        $where = "WHERE move.tran_date >= '$from'
              AND move.tran_date <= '$to'
              AND move.type IN (13, 11)
              AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')";

        if ($location) {
            $where .= "\n              AND move.loc_code = '$location'";
        }

        // ── GROUP BY ──────────────────────────────────────────────────────────
        $group = "GROUP BY item.stock_id, item.category_id, category.description,
            item.description, item.inactive, item.mb_flag, move.loc_code";

        if ($summary) {
            $group .= ", l.location_name";
        } else {
            $group .= ", trans.debtor_no, debtor.debtor_no, debtor.name";
        }

        if (!$datesummary) {
            $group .= ", move.tran_date";
        }

        // ── ORDER BY ──────────────────────────────────────────────────────────
        $order = "ORDER BY item.category_id, item.stock_id";
        if (!$summary)     $order .= ", debtor.name";
        if (!$datesummary) $order .= ", move.tran_date";

        return $this->kirima()->select("$select\n$joins\n$where\n$group\n$order");
    }
    /**
     * GET /api/dashboard/category-items?loccode=&from=&to=
     */
    public function categoryItems(Request $request): JsonResponse
    {
        $request->validate([
            'from'    => 'required|date',
            'to'      => 'required|date|after_or_equal:from',
            'loccode' => 'nullable|string|max:20',
        ]);

        $from    = $this->fmt($request->from);
        $to      = $this->fmt($request->to);
        $from = Carbon::parse($from)->format('Y-m-d');
         $to   = Carbon::parse($to)->format('Y-m-d');

        $loccode = $request->input('loccode', '');

        try {
            $sql = "SELECT
                        item.category_id,
                        category.description          AS cat_description,
                        item.stock_id,
                        item.description,
                        move.loc_code                 AS store,
                        move.tran_date                AS date,
                        SUM(-move.qty)                AS qty,
                        move.price                    AS unit_price,
                        SUM(-move.qty * move.price)   AS amount,
                        SUM(-IF(move.standard_cost <> 0,
                            move.qty * move.standard_cost,
                            move.qty * COALESCE(NULLIF(item.material_cost + item.labour_cost + item.overhead_cost, 0), item.purchase_cost, 0))) AS cost
                    FROM " . self::P . "stock_master    item
                    JOIN " . self::P . "stock_category  category ON category.category_id = item.category_id
                    JOIN " . self::P . "stock_moves     move     ON move.stock_id        = item.stock_id
                    JOIN " . self::P . "debtor_trans    trans    ON trans.trans_no       = move.trans_no
                                                                AND trans.type           = move.type
                    WHERE move.tran_date BETWEEN ? AND ?
                      AND (trans.type = 13 OR move.type = 11)";

            $params = [$from, $to];
            if ($loccode) {
                $sql     .= " AND move.loc_code = ?";
                $params[] = $loccode;
            }
            $sql .= " GROUP BY item.stock_id, move.tran_date ORDER BY amount DESC";

            $items = $this->kirima()->select($sql, $params);
            return ApiResponse::success(array_map(fn($r) => (array)$r, $items), 'Category items retrieved');

        } catch (\Throwable $e) {
            return ApiResponse::serverError('Category items query failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/dashboard/overview
     * At-a-glance home metrics: milk/revenue deltas, 7-day trends, top performers.
     */
    public function overview(): JsonResponse
    {
        $today          = date('Y-m-d');
        $yesterday      = date('Y-m-d', strtotime('-1 day'));
        $weekFrom       = date('Y-m-d', strtotime('-6 days'));
        $mtdFrom        = date('Y-m-01');
        $prevMonthStart = date('Y-m-01', strtotime('first day of last month'));
        // Same day-of-month window last month (fairer than full prior month).
        $dayOfMonth     = (int) date('j');
        $prevMonthDays  = (int) date('t', strtotime($prevMonthStart));
        $prevComparableEnd = date(
            'Y-m-d',
            strtotime($prevMonthStart . ' +' . (min($dayOfMonth, $prevMonthDays) - 1) . ' days')
        );
        $P              = self::P;

        $cacheKey = "dashboard_overview_v4_{$today}";
        if ($cached = Cache::get($cacheKey)) {
            return ApiResponse::success($cached, 'Dashboard overview retrieved');
        }

        try {
            $scaleIds = $this->kirima()->table($P . 'suppliers')
                ->where('supp_name', 'LIKE', '%scale%')
                ->pluck('supplier_id')->toArray();
            $scaleIn = implode(',', array_map('intval', $scaleIds ?: [0]));

            $milkPeriod = function (string $from, string $to) use ($P, $scaleIn) {
                $row = $this->kirima()->selectOne("
                    SELECT
                        ROUND(SUM(CASE WHEN po.supplier_id NOT IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS milk_qty,
                        ROUND(SUM(CASE WHEN po.supplier_id IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS tare_qty
                    FROM {$P}purch_orders po FORCE INDEX (ord_date)
                    JOIN {$P}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '0001'
                    WHERE po.ord_date BETWEEN ? AND ?
                      AND po.ord_date > '2022-11-30'
                ", [$from, $to]);
                return [
                    'milk' => (float) ($row->milk_qty ?? 0),
                    'tare' => (float) ($row->tare_qty ?? 0),
                ];
            };

            $revPeriod = function (string $from, string $to) use ($P) {
                $row = $this->kirima()->selectOne("
                    SELECT ROUND(SUM(-move.qty * move.price), 2) AS revenue
                    FROM {$P}stock_moves move FORCE INDEX (idx_tran_date_type)
                    STRAIGHT_JOIN {$P}stock_master item ON item.stock_id = move.stock_id
                    WHERE move.tran_date BETWEEN ? AND ?
                      AND move.type IN (13, 11)
                      AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')
                ", [$from, $to]);
                return (float) ($row->revenue ?? 0);
            };

            // Supplier invoices (20) − credits (21) — net purchase spend
            // Note: this FA schema has no ov_freight / ov_freight_tax on supp_trans.
            $purchPeriod = function (string $from, string $to) use ($P) {
                $row = $this->kirima()->selectOne("
                    SELECT ROUND(SUM(
                        CASE WHEN st.type = 21 THEN -1 ELSE 1 END
                        * (st.ov_amount + st.ov_gst + st.ov_discount)
                    ), 2) AS purchases
                    FROM {$P}supp_trans st
                    WHERE st.tran_date BETWEEN ? AND ?
                      AND st.type IN (20, 21)
                ", [$from, $to]);
                return (float) ($row->purchases ?? 0);
            };

            $delta = function (float $curr, float $prev): array {
                $abs = round($curr - $prev, 2);
                $pct = $prev > 0
                    ? round(($abs / $prev) * 100, 1)
                    : ($curr > 0 ? 100.0 : 0.0);
                return [
                    'value'     => round($curr, 2),
                    'prev'      => round($prev, 2),
                    'delta'     => $abs,
                    'delta_pct' => $pct,
                    'up'        => $abs >= 0,
                ];
            };

            // ── Milk daily trend (last 7 days) ───────────────────────────────
            $milkDailyRows = $this->kirima()->select("
                SELECT po.ord_date,
                       ROUND(SUM(CASE WHEN po.supplier_id NOT IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS milk_qty,
                       ROUND(SUM(CASE WHEN po.supplier_id IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS tare_qty
                FROM {$P}purch_orders po FORCE INDEX (ord_date)
                JOIN {$P}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '0001'
                WHERE po.ord_date BETWEEN ? AND ?
                  AND po.ord_date > '2022-11-30'
                GROUP BY po.ord_date
                ORDER BY po.ord_date ASC
            ", [$weekFrom, $today]);

            $milkByDate = [];
            foreach ($milkDailyRows as $r) {
                $milkByDate[$r->ord_date] = [
                    'date' => $r->ord_date,
                    'qty'  => (float) $r->milk_qty,
                    'tare' => (float) $r->tare_qty,
                ];
            }
            $milkTrend = [];
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime("{$weekFrom} +{$i} days"));
                $milkTrend[] = $milkByDate[$d] ?? ['date' => $d, 'qty' => 0.0, 'tare' => 0.0];
            }

            $milkToday     = $milkByDate[$today]['qty'] ?? 0.0;
            $milkYesterday = $milkByDate[$yesterday]['qty'] ?? 0.0;
            $milkMtd       = $milkPeriod($mtdFrom, $today)['milk'];
            $milkLastMonth = $milkPeriod($prevMonthStart, $prevComparableEnd)['milk'];

            // ── Revenue daily trend (last 7 days) ────────────────────────────
            $revDailyRows = $this->kirima()->select("
                SELECT move.tran_date,
                       ROUND(SUM(-move.qty * move.price), 2) AS revenue
                FROM {$P}stock_moves move FORCE INDEX (idx_tran_date_type)
                STRAIGHT_JOIN {$P}stock_master item ON item.stock_id = move.stock_id
                WHERE move.tran_date BETWEEN ? AND ?
                  AND move.type IN (13, 11)
                  AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')
                GROUP BY move.tran_date
                ORDER BY move.tran_date ASC
            ", [$weekFrom, $today]);

            $revByDate = [];
            foreach ($revDailyRows as $r) {
                $revByDate[$r->tran_date] = (float) $r->revenue;
            }
            $revTrend = [];
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime("{$weekFrom} +{$i} days"));
                $revTrend[] = ['date' => $d, 'revenue' => $revByDate[$d] ?? 0.0];
            }
            $revToday     = $revByDate[$today] ?? 0.0;
            $revYesterday = $revByDate[$yesterday] ?? 0.0;
            $revMtd       = $revPeriod($mtdFrom, $today);
            $revLastMonth = $revPeriod($prevMonthStart, $prevComparableEnd);

            // ── Purchases daily trend (last 7 days) ──────────────────────────
            $purchDailyRows = $this->kirima()->select("
                SELECT st.tran_date,
                       ROUND(SUM(
                           CASE WHEN st.type = 21 THEN -1 ELSE 1 END
                           * (st.ov_amount + st.ov_gst + st.ov_discount)
                       ), 2) AS purchases
                FROM {$P}supp_trans st
                WHERE st.tran_date BETWEEN ? AND ?
                  AND st.type IN (20, 21)
                GROUP BY st.tran_date
                ORDER BY st.tran_date ASC
            ", [$weekFrom, $today]);

            $purchByDate = [];
            foreach ($purchDailyRows as $r) {
                $purchByDate[$r->tran_date] = (float) $r->purchases;
            }
            $purchTrend = [];
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime("{$weekFrom} +{$i} days"));
                $purchTrend[] = ['date' => $d, 'purchases' => $purchByDate[$d] ?? 0.0];
            }
            $purchToday     = $purchByDate[$today] ?? 0.0;
            $purchYesterday = $purchByDate[$yesterday] ?? 0.0;
            $purchMtd       = $purchPeriod($mtdFrom, $today);
            $purchLastMonth = $purchPeriod($prevMonthStart, $prevComparableEnd);

            // ── Top 5 stores (last 7 days) ───────────────────────────────────
            $storeRows = $this->kirima()->select("
                SELECT l.loc_code, l.location_name,
                       ROUND(SUM(-move.qty * move.price), 2) AS revenue
                FROM {$P}stock_moves move FORCE INDEX (idx_tran_date_type)
                STRAIGHT_JOIN {$P}stock_master item ON item.stock_id = move.stock_id
                JOIN {$P}locations l ON l.loc_code = move.loc_code
                WHERE move.tran_date BETWEEN ? AND ?
                  AND move.type IN (13, 11)
                  AND (item.mb_flag = 'B' OR item.mb_flag = 'M' OR item.mb_flag = 'D')
                GROUP BY l.loc_code, l.location_name
                ORDER BY revenue DESC
                LIMIT 5
            ", [$weekFrom, $today]);

            $topStores = array_map(fn($r) => [
                'loc_code' => $r->loc_code,
                'name'     => $r->location_name,
                'revenue'  => (float) $r->revenue,
            ], $storeRows);

            // ── Top 5 graders (last 7 days) ──────────────────────────────────
            $graderRows = $this->kirima()->select("
                SELECT po.into_stock_location, l.location_name,
                       ROUND(SUM(CASE WHEN po.supplier_id NOT IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS quantity_ordered,
                       ROUND(SUM(CASE WHEN po.supplier_id IN ({$scaleIn}) THEN pod.quantity_ordered ELSE 0 END), 2) AS tare
                FROM {$P}purch_orders po FORCE INDEX (ord_date)
                JOIN {$P}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '0001'
                JOIN {$P}locations l ON l.loc_code = po.into_stock_location
                WHERE po.ord_date BETWEEN ? AND ?
                  AND po.ord_date > '2022-11-30'
                GROUP BY po.into_stock_location, l.location_name
                ORDER BY quantity_ordered DESC
                LIMIT 5
            ", [$weekFrom, $today]);

            $topGraders = array_map(fn($r) => [
                'code' => $r->into_stock_location,
                'name' => $r->location_name,
                'qty'  => (float) $r->quantity_ordered,
                'tare' => (float) $r->tare,
            ], $graderRows);

            // ── Top 5 farmers (last 7 days) ──────────────────────────────────
            $farmerRows = $this->kirima()->select("
                SELECT po.supplier_id, s.member_no, s.supp_name,
                       ROUND(SUM(pod.quantity_ordered), 2) AS total_qty
                FROM {$P}purch_orders po FORCE INDEX (ord_date)
                JOIN {$P}purch_order_details pod ON pod.order_no = po.order_no AND pod.item_code = '0001'
                JOIN {$P}suppliers s ON s.supplier_id = po.supplier_id
                WHERE po.ord_date BETWEEN ? AND ?
                  AND po.ord_date > '2022-11-30'
                  AND po.supplier_id NOT IN ({$scaleIn})
                GROUP BY po.supplier_id, s.member_no, s.supp_name
                ORDER BY total_qty DESC
                LIMIT 5
            ", [$weekFrom, $today]);

            $topFarmers = array_map(fn($r) => [
                'member_no' => $r->member_no,
                'name'      => $r->supp_name,
                'qty'       => (float) $r->total_qty,
            ], $farmerRows);

            $weekMilkTotal  = array_sum(array_column($milkTrend, 'qty'));
            $weekTareTotal  = array_sum(array_column($milkTrend, 'tare'));
            $weekRevTotal   = array_sum(array_column($revTrend, 'revenue'));
            $weekPurchTotal = array_sum(array_column($purchTrend, 'purchases'));

            $data = [
                'as_of' => $today,
                'milk'  => [
                    'today'  => $delta($milkToday, $milkYesterday),
                    'month'  => $delta($milkMtd, $milkLastMonth),
                    'trend7' => $milkTrend,
                    'week_total' => round($weekMilkTotal, 2),
                    'week_tare'  => round($weekTareTotal, 2),
                ],
                'revenue' => [
                    'today'  => $delta($revToday, $revYesterday),
                    'month'  => $delta($revMtd, $revLastMonth),
                    'trend7' => $revTrend,
                    'week_total' => round($weekRevTotal, 2),
                ],
                'purchases' => [
                    'today'  => $delta($purchToday, $purchYesterday),
                    'month'  => $delta($purchMtd, $purchLastMonth),
                    'trend7' => $purchTrend,
                    'week_total' => round($weekPurchTotal, 2),
                ],
                'top_stores'  => $topStores,
                'top_farmers' => $topFarmers,
                'top_graders' => $topGraders,
            ];

            Cache::put($cacheKey, $data, 300);

            return ApiResponse::success($data, 'Dashboard overview retrieved');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Dashboard overview failed: ' . $e->getMessage());
        }
    }
}
