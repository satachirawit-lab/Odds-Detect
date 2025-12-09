<?php
declare(strict_types=1);
/**
 * index.php — Imperial Odds Analyzer (Refactor A, single-file)
 * - Single-file: PHP + HTML + CSS + JS
 * - PHP 7.4+
 * - EWMA Learning saved into MySQL table `ewma_learning`
 *
 * IMPORTANT:
 * 1) Update DB config below to match your server.
 * 2) Backup your old file before replacing.
 */

// ---------------- DB CONFIG (ปรับตามเซิร์ฟเวอร์ของคุณ) ----------------
$DB_HOST = 'sql100.infinityfree.com';
$DB_USER = 'if0_40382363';
$DB_PASS = '084023Jek';
$DB_NAME = 'if0_40382363_odds_system';
$DB_PORT = 3306;
// ---------------------------------------------------------------------

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);
ob_start();

// ----------------------- Helpers -----------------------
function db_connect(){
    global $DB_HOST,$DB_USER,$DB_PASS,$DB_NAME,$DB_PORT;
    $m = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME,$DB_PORT);
    if ($m->connect_errno) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','msg'=>'DB connect failed: '.$m->connect_error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $m->set_charset('utf8mb4');
    return $m;
}
function safeFloat($v){ if(!isset($v)) return NAN; $s=trim((string)$v); if($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s)? floatval($s) : NAN; }
function clamp($v,$a,$b){ return max($a,min($b,$v)); }
function netflow($open,$now){ if(is_nan($open) || is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n = netflow($open,$now); return is_nan($n)?NAN:abs($n); }
function dir_label($open,$now){ if(is_nan($open) || is_nan($now)) return 'flat'; if($now < $open) return 'down'; if($now > $open) return 'up'; return 'flat'; }
function impliedProb($dec){ return (is_nan($dec) || $dec<=0) ? NAN : (1.0 / $dec); }

// ----------------------- EWMA Table & Defaults -----------------------
function ensure_learning_table_and_defaults(){
    $m = db_connect();
    $sql = "CREATE TABLE IF NOT EXISTS ewma_learning (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_name VARCHAR(80) NOT NULL UNIQUE,
        value DOUBLE NOT NULL,
        alpha DOUBLE NOT NULL DEFAULT 0.25,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $m->query($sql);

    // defaults: initial values + tuned alphas (fàn-lǐ / téh-zhū-gōng style)
    $defaults = [
        'home_prob' => ['v'=>0.3333,'a'=>0.20],
        'draw_prob' => ['v'=>0.3333,'a'=>0.20],
        'away_prob' => ['v'=>0.3333,'a'=>0.20],
        'price_move'      => ['v'=>0.0,'a'=>0.28],
        'net_flow'        => ['v'=>0.0,'a'=>0.34],
        'sync_score'      => ['v'=>0.5,'a'=>0.20],
        'rebound'         => ['v'=>0.0,'a'=>0.12],
        'volatility'      => ['v'=>0.0,'a'=>0.18],
        'direction_force' => ['v'=>0.0,'a'=>0.26],
        'flow_power'      => ['v'=>0.0,'a'=>0.22]
    ];
    $stmt = $m->prepare("INSERT IGNORE INTO ewma_learning (key_name,value,alpha) VALUES (?, ?, ?)");
    foreach ($defaults as $k=>$o){
        $v = $o['v']; $a = $o['a'];
        $stmt->bind_param('sdd',$k,$v,$a);
        $stmt->execute();
    }
    $stmt->close();
    $m->close();
}
ensure_learning_table_and_defaults();

function get_learning_map(): array {
    $m = db_connect();
    $res = $m->query("SELECT key_name, value, alpha FROM ewma_learning");
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[$r['key_name']] = ['value'=>floatval($r['value']),'alpha'=>floatval($r['alpha'])];
    }
    $m->close();
    return $out;
}
function update_ewma_key(string $key, float $sample){
    $m = db_connect();
    $stmt = $m->prepare("SELECT value, alpha FROM ewma_learning WHERE key_name=? LIMIT 1");
    $stmt->bind_param('s',$key);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()){
        $old = floatval($row['value']); $alpha = floatval($row['alpha']);
        $updated = ($alpha * $sample) + ((1.0 - $alpha) * $old);
        $u = $m->prepare("UPDATE ewma_learning SET value=? WHERE key_name=?");
        $u->bind_param('ds',$updated,$key);
        $u->execute();
        $u->close();
    } else {
        $ins = $m->prepare("INSERT INTO ewma_learning (key_name,value,alpha) VALUES (?, ?, 0.25)");
        $ins->bind_param('sd',$key,$sample);
        $ins->execute();
        $ins->close();
    }
    $stmt->close();
    $m->close();
}

// ----------------------- API: get_learning -----------------------
if (isset($_GET['action']) && $_GET['action']==='get_learning'){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'ok','learning'=>get_learning_map()], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

// ----------------------- API: save_learning -----------------------
if (isset($_GET['action']) && $_GET['action']==='save_learning' && $_SERVER['REQUEST_METHOD']==='POST'){
    $home = safeFloat($_POST['home'] ?? null);
    $draw = safeFloat($_POST['draw'] ?? null);
    $away = safeFloat($_POST['away'] ?? null);
    if (is_nan($home) || is_nan($draw) || is_nan($away) || $home<=0 || $draw<=0 || $away<=0){
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','msg'=>'invalid odds'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // implied probabilities normalized
    $ipHome = 1.0/$home; $ipDraw = 1.0/$draw; $ipAway = 1.0/$away;
    $sum = $ipHome+$ipDraw+$ipAway;
    $nHome = $ipHome/$sum; $nDraw = $ipDraw/$sum; $nAway = $ipAway/$sum;
    update_ewma_key('home_prob',$nHome);
    update_ewma_key('draw_prob',$nDraw);
    update_ewma_key('away_prob',$nAway);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'ok','saved'=>true,'values'=>['home'=>$nHome,'draw'=>$nDraw,'away'=>$nAway]], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

// ----------------------- API: analyze -----------------------
if (isset($_GET['action']) && $_GET['action']==='analyze' && $_SERVER['REQUEST_METHOD']==='POST'){
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw,true);
    if (!is_array($payload)){
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','msg'=>'invalid payload','raw'=>substr($raw,0,200)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }

    // ---------------- Parse inputs ----------------
    $home = $payload['home'] ?? 'เหย้า';
    $away = $payload['away'] ?? 'เยือน';
    $favorite = $payload['favorite'] ?? 'none';
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1']  ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];
    $options = $payload['options'] ?? [];

    // build pairs for rebound sens
    $pairs = [];
    $pairs[] = ['open'=>$open1['home'] ?? NAN,'now'=>$now1['home'] ?? NAN];
    $pairs[] = ['open'=>$open1['away'] ?? NAN,'now'=>$now1['away'] ?? NAN];

    // parse AH lines
    $ah_details = []; $totalAH_mom = 0.0;
    foreach ($ah_list as $i=>$r){
        $line = $r['line'] ?? ('AH'.($i+1));
        $oh = safeFloat($r['open_home'] ?? null);
        $oa = safeFloat($r['open_away'] ?? null);
        $nh = safeFloat($r['now_home'] ?? null);
        $na = safeFloat($r['now_away'] ?? null);
        $mh = mom_abs($oh,$nh);
        $ma = mom_abs($oa,$na);
        if (!is_nan($mh)) $totalAH_mom += $mh;
        if (!is_nan($ma)) $totalAH_mom += $ma;
        $ah_details[] = [
            'index'=>$i,'line'=>$line,
            'open_home'=>$oh,'open_away'=>$oa,'now_home'=>$nh,'now_away'=>$na,
            'net_home'=>netflow($oh,$nh),'net_away'=>netflow($oa,$na),'mom_home'=>$mh,'mom_away'=>$ma,
            'dir_home'=>dir_label($oh,$nh),'dir_away'=>dir_label($oa,$na)
        ];
        $pairs[] = ['open'=>$oh,'now'=>$nh];
        $pairs[] = ['open'=>$oa,'now'=>$na];
    }

    // ---------------- 1x2 flows + mom ----------------
    $flow1 = [
        'home'=> netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),
        'draw'=> netflow(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),
        'away'=> netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))
    ];
    $mom1 = [
        'home'=> mom_abs(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),
        'draw'=> mom_abs(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),
        'away'=> mom_abs(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))
    ];
    $total1x2_mom = 0.0; foreach ($mom1 as $v) if (!is_nan($v)) $total1x2_mom += $v;
    $marketMomentum = $total1x2_mom + $totalAH_mom;

    // -------------- Rebound sensitivity auto (single-bar heuristic) --------------
    $computeAutoReboundFromPair = function(float $open,float $now):float{
        if ($open <= 0 || $now <= 0) return 0.02;
        $delta = abs($now - $open);
        if ($delta <= 0.000001) return 0.04;
        $strength = $delta / $open;
        if ($strength < 0.02) return 0.04;
        if ($strength < 0.05) return 0.03;
        if ($strength < 0.12) return 0.02;
        return 0.015;
    };
    $computeAutoReboundAggregate = function(array $pairs) use ($computeAutoReboundFromPair): float {
        $sList=[];
        foreach($pairs as $p){
            if (!isset($p['open'])||!isset($p['now'])) continue;
            $o = floatval($p['open']); $n = floatval($p['now']);
            if ($o>0 && $n>0) $sList[] = $computeAutoReboundFromPair($o,$n);
        }
        if (count($sList)===0) return 0.025;
        return array_sum($sList)/count($sList);
    };
    $reboundSens = $computeAutoReboundAggregate($pairs);

    // ---------------- Sync score anchored to favorite ----------------
    $syncPoints = 0; $syncChecks = 0;
    foreach ($ah_details as $ad){
        if ($favorite === 'home'){
            $favAH = $ad['dir_home']; $fav1 = dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
            $dogAH = $ad['dir_away']; $dog1 = dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
        } elseif ($favorite === 'away'){
            $favAH = $ad['dir_away']; $fav1 = dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
            $dogAH = $ad['dir_home']; $dog1 = dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
        } else {
            $fav1 = (abs($flow1['home']) >= abs($flow1['away'])) ? dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)) : dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
            $favAH = $ad['dir_home']; $dogAH = $ad['dir_away'];
            $dog1 = (abs($flow1['home']) >= abs($flow1['away'])) ? dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null)) : dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
        }
        $syncPoints += ($favAH === $fav1)?1:-1; $syncChecks++;
        $syncPoints += ($dogAH === $dog1)?1:-1; $syncChecks++;
    }
    $syncScoreRaw = ($syncChecks>0)?($syncPoints/$syncChecks):0.0;
    $syncScoreNorm = ($syncScoreRaw+1.0)/2.0;

    // ---------------- Divergence & Conflicts ----------------
    $divergence = abs($totalAH_mom - $total1x2_mom);
    $conflictCount=0; $conflictDetails=[];
    for ($i=0;$i<count($ah_details);$i++){
        for ($j=$i+1;$j<count($ah_details);$j++){
            $a=$ah_details[$i]; $b=$ah_details[$j];
            if ($a['dir_home']!==$b['dir_home'] || $a['dir_away']!==$b['dir_away']){
                $conflictCount++;
                $conflictDetails[] = "ความขัดแย้งระหว่าง ".$a['line']." กับ ".$b['line'];
            }
        }
    }
    $isUltraDivergence = $divergence > 0.12;

    // ---------------- Trap detection ----------------
    $trapFlags=[]; $trap=false;
    foreach (['home','draw','away'] as $s){
        if (!is_nan(safeFloat($open1[$s] ?? null)) && !is_nan(safeFloat($now1[$s] ?? null))){
            $rel = abs(safeFloat($now1[$s] ?? null) - safeFloat($open1[$s] ?? null)) / max(0.0001, abs(safeFloat($open1[$s] ?? null)));
            if ($rel <= $reboundSens) { $trapFlags[]="bounce_1x2_{$s}"; $trap = true; }
        }
    }
    foreach ($ah_details as $ad){
        if (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])){
            $relh = abs($ad['now_home'] - $ad['open_home']) / max(0.0001, abs($ad['open_home']));
            if ($relh <= $reboundSens) { $trapFlags[] = "bounce_AH_{$ad['line']}_H"; $trap = true; }
        }
        if (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])){
            $rela = abs($ad['now_away'] - $ad['open_away']) / max(0.0001, abs($ad['open_away']));
            if ($rela <= $reboundSens) { $trapFlags[] = "bounce_AH_{$ad['line']}_A"; $trap = true; }
        }
    }
    $signs=[];
    foreach ($ah_details as $ad) { $nh = $ad['net_home'] ?? 0; $signs[] = ($nh>0?1:($nh<0?-1:0)); }
    $flips=0; for ($i=1;$i<count($signs);$i++) if ($signs[$i] !== $signs[$i-1]) $flips++;
    if ($flips >= 2) { $trapFlags[]='multi_flip_AH'; $trap=true; }

    // ---------------- Ultra-Hard Engine: motions, z-scores ----------------
    $motions=[];
    foreach (['home','draw','away'] as $s){
        $o = safeFloat($open1[$s] ?? null); $n = safeFloat($now1[$s] ?? null);
        if (!is_nan($o) && !is_nan($n) && $o>0) $motions[] = ($n - $o) / $o;
    }
    foreach ($ah_details as $ad){
        if (!is_nan($ad['open_home']) && $ad['open_home']>0) $motions[] = (($ad['now_home'] - $ad['open_home']) / $ad['open_home']);
        if (!is_nan($ad['open_away']) && $ad['open_away']>0) $motions[] = (($ad['now_away'] - $ad['open_away']) / $ad['open_away']);
    }
    if (count($motions)===0) $motions[] = 0.0;
    sort($motions);
    $len = count($motions);
    $trim = max(0,intval($len*0.1));
    $trimmed = array_slice($motions,$trim,max(1,$len-2*$trim));
    $mean = array_sum($trimmed)/count($trimmed);
    $variance = 0.0; foreach ($trimmed as $m) $variance += pow($m - $mean, 2);
    $std = sqrt($variance / max(1, count($trimmed)));
    $z = function($x) use ($mean,$std){ if ($std < 1e-6) return 0.0; return ($x - $mean) / $std; };

    // per-line signals
    $line_signals = [];
    foreach ($ah_details as $ad){
        $hRel = (!is_nan($ad['open_home']) && $ad['open_home']>0) ? (($ad['now_home'] - $ad['open_home']) / $ad['open_home']) : 0.0;
        $aRel = (!is_nan($ad['open_away']) && $ad['open_away']>0) ? (($ad['now_away'] - $ad['open_away']) / $ad['open_away']) : 0.0;
        $zH = $z($hRel); $zA = $z($aRel);
        $dirH = ($hRel < 0) ? 'favor_home' : (($hRel > 0) ? 'favor_away' : 'flat');
        $dirA = ($aRel < 0) ? 'favor_home' : (($aRel > 0) ? 'favor_away' : 'flat');
        $moment = ($ad['mom_home'] ?? 0) + ($ad['mom_away'] ?? 0);
        $line_signals[] = ['line'=>$ad['line'],'hRel'=>$hRel,'aRel'=>$aRel,'zH'=>$zH,'zA'=>$zA,'moment'=>$moment,'dirH'=>$dirH,'dirA'=>$dirA];
    }

    // 1x2 signals
    $x2_signals = [];
    foreach (['home','away'] as $s){
        $o = safeFloat($open1[$s] ?? null); $n = safeFloat($now1[$s] ?? null);
        $rel = (!is_nan($o) && $o>0) ? (($n - $o) / $o) : 0.0;
        $x2_signals[$s] = ['rel'=>$rel,'z'=>$z($rel),'mom'=>$mom1[$s] ?? 0];
    }

    // juice pressure
    $juicePressure = 0.0;
    foreach ($ah_details as $ad){
        $hj = (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) ? ($ad['open_home'] - $ad['now_home']) : 0.0;
        $aj = (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) ? ($ad['open_away'] - $ad['now_away']) : 0.0;
        $juicePressure += abs($hj) + abs($aj);
    }
    foreach (['home','away'] as $s){ $o=safeFloat($open1[$s]??null); $n=safeFloat($now1[$s]??null); if(!is_nan($o)&&!is_nan($n)) $juicePressure += abs($o-$n); }
    $juicePressureNorm = clamp($juicePressure / max(0.02, $marketMomentum + 1e-9), 0.0, 3.0);

    // stack factor
    $stackHome=0;$stackAway=0; foreach($line_signals as $ls){ if($ls['hRel']<0||$ls['aRel']<0) $stackHome++; if($ls['hRel']>0||$ls['aRel']>0) $stackAway++; }
    $stackMax = max($stackHome,$stackAway);
    $stackFactor = clamp($stackMax / max(1,count($line_signals)), 0.0, 1.0);

    // concentration
    $momArray = array_map(function($x){ return $x['moment']; }, $line_signals);
    rsort($momArray);
    $topMom = array_sum(array_slice($momArray, 0, min(3, count($momArray))));
    $totalMom = array_sum($momArray) + 1e-9;
    $concentrationTop = clamp($topMom / $totalMom, 0.0, 1.0);

    // smart money killer detector
    $smartMoneyScore = 0.0; $smartFlags=[];
    $overround_open = 0.0; $overround_now = 0.0;
    foreach (['home','draw','away'] as $s){
        $io = impliedProb(safeFloat($open1[$s]??null)); $in = impliedProb(safeFloat($now1[$s]??null));
        if (!is_nan($io)) $overround_open += $io;
        if (!is_nan($in)) $overround_now += $in;
    }
    $overround_open -= 1.0; $overround_now -= 1.0;
    if ($juicePressureNorm > 0.3) { $smartMoneyScore += 0.35; $smartFlags[]='juice_pressure'; }
    if ($concentrationTop > 0.55) { $smartMoneyScore += 0.25; $smartFlags[]='concentration'; }
    if ($stackFactor > 0.6) { $smartMoneyScore += 0.2; $smartFlags[]='stacked_lines'; }
    if ($overround_now < 0.06) { $smartMoneyScore += 0.15; $smartFlags[]='tight_book'; }
    if ($topMom > ($totalMom * 0.45)) { $smartMoneyScore += 0.15; $smartFlags[]='top_momentum_domination'; }
    $smartMoneyScore = clamp($smartMoneyScore, 0.0, 1.0);
    $smart_money_killer = ($smartMoneyScore >= 0.7 && ($flow1['home']!=0.0 || $flow1['away']!=0.0)) ? true : false;

    // aggregate raw signal
    $w_momentum = clamp($marketMomentum / 1.0, 0.0, 1.0);
    $w_stack = $stackFactor;
    $w_juice = clamp($juicePressureNorm / 1.2, 0.0, 1.0);
    $w_conc = $concentrationTop;
    $w_sync = $syncScoreNorm;
    $w_nfi = clamp((($totalAH_mom*0.6 + $total1x2_mom*0.4) - $divergence) / 0.5, 0.0, 1.0);
    $w_div = clamp(1.0 - ($divergence / 0.3), 0.0, 1.0);
    $rawSignal = (
        ($w_momentum * 0.28) +
        ($w_stack * 0.20) +
        ($w_juice * 0.18) +
        ($w_conc * 0.12) +
        ($w_sync * 0.12) +
        ($w_nfi * 0.10)
    ) * $w_div;
    if ($trap) $rawSignal *= 0.32;

    // direction score
    $directionScore = 0.0;
    foreach ($line_signals as $ls) { $directionScore += (-$ls['zH'] + $ls['zA']) * ($ls['moment'] + 0.01); }
    $directionScore += (-$x2_signals['home']['z'] + $x2_signals['away']['z']) * (($x2_signals['home']['mom'] + $x2_signals['away']['mom']) + 0.01);
    $dirNorm = tanh($directionScore / (0.5 + $marketMomentum));
    $hackScore = clamp($rawSignal * $dirNorm * 1.5, -1.0, 1.0);

    // confidence / flowPower
    $confidence = round(clamp(abs($hackScore) * 120.0 + ($w_juice*20.0), 0.0, 100.0), 1);
    $flowPower = round(clamp((abs($hackScore) * 0.6 + $w_sync*0.2 + $w_juice*0.2 + $smartMoneyScore*0.15) * 100.0, 0, 100));

    // market kill & signature
    $market_kill=false; $signature=[];
    if ($flowPower >= 88 && $confidence >= 82 && $stackFactor > 0.65 && !$trap) { $market_kill = true; $signature[]='STACK+SHARP+HIGH_FLOW'; }
    if ($trap && $flowPower < 40) { $signature[]='TRAP_DETECTED'; }
    if ($isUltraDivergence && $divergence > 0.22) { $signature[]='ULTRA_DIV'; $hackScore *= 0.7; }
    if ($smart_money_killer) { $signature[]='SMART_MONEY_KILLER'; }

    // final label & recommendation
    if ($market_kill) {
        $final_label = '💀 MARKET KILL — ไหลแรงครบทุกเงื่อนไข (ห้ามเสี่ยง)';
        $recommendation = 'หลีกเลี่ยงหรือวางเดิมพันจำกัดเฉพาะผู้เชี่ยวชาญ';
    } else if ($hackScore > 0.35) {
        $final_label = '✅ ไหลจริง (โจมตีฝั่งเหย้า)'; $recommendation = 'พิจารณาตาม — ควบคุมความเสี่ยง';
    } else if ($hackScore < -0.35) {
        $final_label = '✅ ไหลจริง (โจมตีฝั่งเยือน)'; $recommendation = 'พิจารณาตาม — ควบคุมความเสี่ยง';
    } else if ($trap) {
        $final_label = '❌ สัญญาณก้ำกึ่ง — พบกับดัก'; $recommendation = 'ไม่แนะนำเดิมพัน';
    } else {
        $final_label = '⚠️ ไม่ชัดเจน — สัญญาณผสม'; $recommendation = 'รอ confirm';
    }

    // metrics for learning
    $netFlowMag = 0.0; foreach (['home','draw','away'] as $s) { $nf = $flow1[$s] ?? 0.0; if (!is_nan($nf)) $netFlowMag += abs($nf); }
    $priceMove = $marketMomentum;
    $directionForce = abs($directionScore);
    $volatility = $std;
    $syncScore = $syncScoreNorm;
    $reboundMetric = $reboundSens;
    $flowPowerMetric = $flowPower / 100.0;

    // update EWMA keys (best-effort on server-side)
    try {
        update_ewma_key('net_flow', $netFlowMag);
        update_ewma_key('price_move', $priceMove);
        update_ewma_key('direction_force', $directionForce);
        update_ewma_key('volatility', $volatility);
        update_ewma_key('sync_score', $syncScore);
        update_ewma_key('rebound', $reboundMetric);
        update_ewma_key('flow_power', $flowPowerMetric);
    } catch (\Throwable $e) {
        // silent fail
    }

    // predicted winner (aggregate)
    $agg = 0.0;
    $agg += (is_nan($flow1['home'])?0.0:$flow1['home']) * 2.0;
    $agg += (is_nan($flow1['away'])?0.0:$flow1['away']) * -2.0;
    foreach ($ah_details as $ad){
        if ($favorite === 'home') $agg += $ad['net_home'] ?? 0.0;
        elseif ($favorite === 'away') $agg += -($ad['net_away'] ?? 0.0);
        else $agg += (($ad['net_home'] ?? 0.0) - ($ad['net_away'] ?? 0.0));
    }
    $predicted_winner = 'ไม่แน่ใจ'; if ($agg > 0.12) $predicted_winner = $home; if ($agg < -0.12) $predicted_winner = $away;

    // stake suggestion
    $stakePct = 0.0;
    if ($confidence >= 75 && stripos($final_label,'ไหลจริง')!==false) $stakePct = 4.0;
    elseif ($confidence >= 60 && stripos($final_label,'ไหลจริง')!==false) $stakePct = 2.0;
    elseif ($confidence >= 45 && stripos($final_label,'ไม่ชัดเจน')!==false) $stakePct = 1.0;

    // mother/intent/money discovery
    $mother = null; $intent = null; $money = null;
    if (count($ah_details) > 0){
        $mother = $ah_details[0];
        $maxMove=-1; $idxIntent=null; foreach ($ah_details as $ad){ $move = ($ad['mom_home'] ?? 0) + ($ad['mom_away'] ?? 0); if ($move > $maxMove){ $maxMove = $move; $idxIntent = $ad; } }
        $intent = $idxIntent;
        $maxMomentum=-1; $idxMoney=null; foreach ($ah_details as $ad){ $m = abs($ad['net_home'] ?? 0) + abs($ad['net_away'] ?? 0); if ($m > $maxMomentum){ $maxMomentum=$m; $idxMoney=$ad; } }
        $money = $idxMoney;
    }

    // response
    $resp = [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'favorite'=>$favorite,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list,'options'=>['reboundSens'=>$reboundSens]],
        'metrics'=>[
            'marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,
            'syncScoreRaw'=>$syncScoreRaw,'syncScoreNorm'=>$syncScoreNorm,'divergence'=>$divergence,'ultraDivergence'=>$isUltraDivergence,
            'conflictCount'=>$conflictCount,'conflictDetails'=>$conflictDetails,'trap'=>$trap,'trapFlags'=>$trapFlags,
            'reboundSens'=>$reboundSens,'juicePressure'=>$juicePressure,'juicePressureNorm'=>$juicePressureNorm,
            'price_move'=>$priceMove,'net_flow'=>$netFlowMag,'direction_force'=>$directionForce,'volatility'=>$volatility,'sync_score'=>$syncScore,'flow_power'=>$flowPower,'smart_money_score'=>$smartMoneyScore
        ],
        'final_label'=>$final_label,'recommendation'=>$recommendation,'reasons'=>[],'predicted_winner'=>$predicted_winner,'stake_pct'=>$stakePct,
        'ah_details'=>$ah_details,'mother_price'=>$mother,'intent_price'=>$intent,'money_price'=>$money,
        'flows_1x2'=>$flow1,'mom1'=>$mom1,'confidence'=>$confidence,'flowPower'=>$flowPower,'market_kill'=>$market_kill,'signature'=>$signature
    ];

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

// ---------------- Serve UI ----------------
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"/>
<title>ทีเด็ดเฮียเจ็ก</title>
<style>
:root{
  --gold:#d4a017; --royal:#5b21b6; --bg1:#09060a; --card:#12090a; --muted:#d9c89a;
  --glass: rgba(255,255,255,0.03);
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:Inter, "Noto Sans Thai", system-ui, -apple-system, "Segoe UI", Roboto, Arial;background:linear-gradient(180deg,var(--bg1),#120612);color:#f6eedf;-webkit-font-smoothing:antialiased}
.container{max-width:1200px;margin:18px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.logo{width:72px;height:72px;border-radius:12px;background:radial-gradient(circle at 25% 25%, rgba(255,255,255,0.06), var(--royal));display:flex;align-items:center;justify-content:center;color:white;font-weight:900;font-size:28px;box-shadow:0 10px 30px rgba(0,0,0,0.6)}
.title{line-height:1}
.title h1{margin:0;color:var(--gold);font-size:1.2rem}
.title p{margin:2px 0 0 0;color:var(--muted);font-size:0.9rem}
.card{background:linear-gradient(145deg,#140b07,#2a1a13);border-radius:14px;padding:16px;margin-top:14px;border:1px solid rgba(212,160,23,0.06);box-shadow: 0 8px 30px rgba(0,0,0,0.6)}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
label{font-size:0.9rem;color:#f3e6cf;margin-bottom:6px;display:block}
input,select,button{font-family:inherit}
input, select{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,235,200,0.04);background:var(--glass);color:#ffecc9}
.btn{padding:10px 14px;border-radius:12px;border:none;color:#110b06;background:linear-gradient(90deg,var(--royal),var(--gold));cursor:pointer;box-shadow:0 8px 20px rgba(0,0,0,0.55)}
.btn-ghost{background:transparent;border:1px solid rgba(255,235,200,0.06);color:#ffecc9;padding:8px 10px;border-radius:10px}
.controls{display:flex;gap:8px;align-items:center}
.small{font-size:0.9rem;color:var(--muted)}
.inline{display:flex;gap:8px;align-items:center}
.resultWrap{display:flex;gap:14px;align-items:flex-start;margin-top:14px;flex-wrap:wrap}
.analysisCard{flex:1;min-width:320px;padding:18px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));position:relative;overflow:visible}
.sidePanel{width:360px;min-width:260px;padding:14px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01))}
.dragon{position:absolute;right:-60px;top:-40px;width:320px;height:160px;pointer-events:none;opacity:0.95;mix-blend-mode:screen}
.ink-canvas{position:absolute;left:0;bottom:0;width:100%;height:160px;pointer-events:none;opacity:0.12}
.tome{margin-top:12px;padding:12px;border-radius:10px;background:linear-gradient(180deg,#0e0710,#231217);border:1px solid rgba(212,160,23,0.06);color:#ffdba1}
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th,.table td{padding:8px;border-bottom:1px dashed rgba(255,235,200,0.03);text-align:left;font-size:0.95rem}
.kpi{margin-top:8px;padding:10px;border-radius:10px;background:rgba(0,0,0,0.18);text-align:center}
.kpi .num{font-weight:900;color:var(--gold);font-size:22px}
.alarm{padding:10px;border-radius:8px;background:linear-gradient(90deg,rgba(255,40,40,0.95),rgba(200,20,20,0.9));color:white;font-weight:900;text-align:center;margin-bottom:10px}
@media(max-width:980px){.grid{grid-template-columns:1fr}.sidePanel{width:100%}.dragon{display:none}.resultWrap{flex-direction:column}}
/* subtle animations (A mode): light, not heavy */
.mini-fade{animation:miniFade 900ms cubic-bezier(.2,.9,.2,1)}
@keyframes miniFade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.seal{position:fixed;left:50%;top:16%;transform:translate(-50%,-50%) scale(0.8);z-index:9999;padding:18px;border-radius:50%;width:180px;height:180px;background:radial-gradient(circle at 30% 30%, rgba(255,240,200,0.95), rgba(212,150,20,0.95));box-shadow:0 20px 80px rgba(212,150,20,0.25);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:34px}
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div style="display:flex;gap:12px;align-items:center">
        <div class="logo">鴻</div>
        <div class="title">
          <h1>ทีเด็ดเฮียเจ็ก</h1>
          <p class="small">สุดยอดระบบAIวิเคราะห์ราคาแบบรู้ไต๋</p>
        </div>
      </div>
      <div class="controls">
        <button id="modeBtn" class="btn-ghost">โหมดมืด</button>
      </div>
    </div>

    <div class="card">
      <form id="mainForm" onsubmit="return false;">
        <div class="grid">
          <div>
            <label>ทีมเหย้า</label>
            <input id="home" placeholder="ทีมเหย้า" inputmode="text">
          </div>
          <div>
            <label>ทีมเยือน</label>
            <input id="away" placeholder="ทีมเยือน" inputmode="text">
          </div>
          <div>
            <label>ทีมต่อ (SBOBET)</label>
            <select id="favorite"><option value="none">ไม่ระบุ</option><option value="home">เหย้า</option><option value="away">เยือน</option></select>
          </div>
        </div>

        <div style="height:12px"></div>

        <div class="card" style="padding:12px;">
          <strong style="color:#ffdca8">1X2 — ราคาเปิด</strong>
          <div class="grid" style="margin-top:8px">
            <div><label>เหย้า (open)</label><input id="open1_home" type="number" step="0.01" inputmode="decimal" placeholder="2.10"></div>
            <div><label>เสมอ (open)</label><input id="open1_draw" type="number" step="0.01" inputmode="decimal" placeholder="3.40"></div>
            <div><label>เยือน (open)</label><input id="open1_away" type="number" step="0.01" inputmode="decimal" placeholder="3.10"></div>
          </div>

        <div style="height:8px"></div>

        <strong style="color:#ffdca8">1X2 — ราคาปัจจุบัน</strong>
        <div class="grid" style="margin-top:8px">
          <div><label>เหย้า (now)</label><input id="now1_home" type="number" step="0.01" inputmode="decimal" placeholder="1.95"></div>
          <div><label>เสมอ (now)</label><input id="now1_draw" type="number" step="0.01" inputmode="decimal" placeholder="3.60"></div>
          <div><label>เยือน (now)</label><input id="now1_away" type="number" step="0.01" inputmode="decimal" placeholder="3.80"></div>
        </div>
        </div>

        <div style="height:12px"></div>

        <div class="card" style="padding:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <strong style="color:#ffdca8">Asian Handicap — เพิ่ม/ลบได้ (หลายราคา)</strong>
            <div class="controls"><button id="addAhBtn" type="button" class="btn-ghost">+ เพิ่ม AH</button><button id="clearAhBtn" type="button" class="btn-ghost">ล้างทั้งหมด</button></div>
          </div>
          <div id="ahContainer" style="margin-top:12px"></div>
        </div>

        <div style="height:12px"></div>

        <div class="grid">
          <div><label>Rebound sensitivity (ระบบคำนวณอัตโนมัติ)</label><input id="reboundDisplay" readonly placeholder="Auto"></div>
          <div></div>
          <div style="align-self:end;text-align:right"><button id="analyzeBtn" class="btn" type="button">🔎 วิเคราะห์สุดโหด</button></div>
        </div>
      </form>
    </div>

    <div id="resultWrap" class="card" style="display:none;position:relative;overflow:visible">
      <!-- dragon -->
      <svg class="dragon" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
        <defs><linearGradient id="g1" x1="0%" x2="100%"><stop offset="0%" stop-color="#ffd78c"/><stop offset="100%" stop-color="#f59e0b"/></linearGradient>
          <path id="dragonPath" d="M20,150 C120,20 360,10 480,120 C580,200 760,120 780,70"/>
        </defs>
        <use xlink:href="#dragonPath" fill="none" stroke="rgba(212,160,23,0.06)" stroke-width="8"/>
        <circle r="12" fill="url(#g1)"><animateMotion dur="5.5s" repeatCount="indefinite"><mpath xlink:href="#dragonPath"></mpath></animateMotion></circle>
      </svg>

      <div class="ink-canvas" id="inkCanvasWrap"></div>

      <div class="resultWrap">
        <div class="analysisCard mini-fade" id="analysisCard">
          <div id="mainSummary"></div>
          <div id="mainReasons" style="margin-top:12px"></div>
          <div id="detailTables" style="margin-top:14px"></div>
          <div class="tome" id="secretTome" style="display:none">
            <h4>คัมภีร์ลับ — อ่านราคาแม่</h4>
            <div id="tomeContent">ยังไม่มีข้อมูล</div>
          </div>
        </div>

        <div class="sidePanel mini-fade">
          <div style="text-align:center">
            <div class="kpi"><div class="num" id="confValue">--%</div></div>
            <div class="small">Confidence</div>
            <div style="height:10px"></div>
            <div class="kpi"><div class="num" id="flowPowerValue">--</div></div>
            <div class="small">Flow Power (0–100)</div>
            <div style="height:8px"></div>
            <div class="kpi"><div class="num" id="nfiValue">--</div></div>
            <div class="small">NFI</div>
            <div style="height:16px"></div>
            <div id="stakeSuggestion" style="padding:12px;background:linear-gradient(180deg,rgba(255,255,255,0.01),rgba(255,255,255,0.00));border-radius:10px;text-align:center;color:#ffdca8"></div>
            <div style="height:8px"></div>
            <div class="small">(ระบบเรียนรู้บนเซิร์ฟเวอร์แล้ว)</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card small" style="margin-top:12px"><strong>หมายเหตุ</strong><div class="small">ระบบวิเคราะห์จากราคาที่ผู้ใช้กรอกเท่านั้น — ไม่ดึงข้อมูลจากภายนอก — EWMA Learning เก็บบน DB</div></div>
  </div>

<script>
// ---------- Light client utilities ----------
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function nf(v,d=4){ return (v===null||v===undefined||isNaN(v))?'-':Number(v).toFixed(d); }
function clamp(v,a,b){ return Math.max(a,Math.min(b,v)); }
function tanh(x){ return Math.tanh?Math.tanh(x):(Math.exp(x)-Math.exp(-x))/(Math.exp(x)+Math.exp(-x)); }

// ---------- AH UI ----------
const addAhBtn = document.getElementById('addAhBtn');
const clearAhBtn = document.getElementById('clearAhBtn');
const ahContainer = document.getElementById('ahContainer');
let ahIndex = 0;
function createAhBlock(data={}){
  const div = document.createElement('div');
  div.className = 'ah-block';
  div.style = "background:linear-gradient(180deg,rgba(255,255,255,0.01),rgba(255,255,255,0.00));padding:12px;border-radius:10px;border:1px solid rgba(255,235,200,0.03);margin-bottom:10px";
  div.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
      <div><label>AH line</label><input name="ah_line" placeholder="เช่น 0, +0.25, -0.5" value="${data.line||''}" inputmode="text"></div>
      <div><label>เปิด (เหย้า)</label><input name="ah_open_home" type="number" step="0.01" placeholder="1.92" value="${data.open_home||''}" inputmode="decimal"></div>
      <div><label>เปิด (เยือน)</label><input name="ah_open_away" type="number" step="0.01" placeholder="1.95" value="${data.open_away||''}" inputmode="decimal"></div>
    </div>
    <div style="height:8px"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:8px">
      <div><label>ตอนนี้ (เหย้า)</label><input name="ah_now_home" type="number" step="0.01" placeholder="1.80" value="${data.now_home||''}" inputmode="decimal"></div>
      <div><label>ตอนนี้ (เยือน)</label><input name="ah_now_away" type="number" step="0.01" placeholder="1.95" value="${data.now_away||''}" inputmode="decimal"></div>
      <div style="align-self:end;text-align:right"><button type="button" class="btn-ghost remove">ลบ</button></div>
    </div>`;
  ahContainer.appendChild(div);
  div.querySelector('.remove').addEventListener('click', ()=>div.remove());
  ahIndex++;
}
addAhBtn.addEventListener('click', ()=>createAhBlock());
clearAhBtn.addEventListener('click', ()=>{ ahContainer.innerHTML=''; ahIndex=0; createAhBlock(); });
window.addEventListener('DOMContentLoaded', ()=>{ if (!document.querySelectorAll('#ahContainer .ah-block').length) createAhBlock(); spawnInk(); loadLearning(); });

// ink splatter — light
function spawnInk(){
  const wrap = document.getElementById('inkCanvasWrap');
  wrap.innerHTML = '';
  const canvas = document.createElement('canvas');
  canvas.width = wrap.clientWidth;
  canvas.height = 160;
  wrap.appendChild(canvas);
  const ctx = canvas.getContext('2d');
  for (let i=0;i<6;i++){
    const x = Math.random()*canvas.width;
    const y = Math.random()*canvas.height;
    const r = 6 + Math.random()*30;
    ctx.fillStyle = 'rgba(11,8,8,'+(0.02+Math.random()*0.06)+')';
    ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill();
    for (let j=0;j<8;j++){ const rx = x + (Math.random()-0.5)*r*3; const ry = y + (Math.random()-0.5)*r*3; const rr = Math.random()*5; ctx.fillRect(rx,ry,rr,rr); }
  }
}

// collect payload
function collectPayload(){
  const home = document.getElementById('home').value.trim()||'ทีมเหย้า';
  const away = document.getElementById('away').value.trim()||'ทีมเยือน';
  const favorite = document.getElementById('favorite').value||'none';
  const open1 = { home: toNum(document.getElementById('open1_home').value), draw: toNum(document.getElementById('open1_draw').value), away: toNum(document.getElementById('open1_away').value) };
  const now1  = { home: toNum(document.getElementById('now1_home').value), draw: toNum(document.getElementById('now1_draw').value), away: toNum(document.getElementById('now1_away').value) };
  const ahNodes = Array.from(document.querySelectorAll('#ahContainer .ah-block'));
  const ah = ahNodes.map(n => ({
    line: n.querySelector('input[name=ah_line]').value,
    open_home: toNum(n.querySelector('input[name=ah_open_home]').value),
    open_away: toNum(n.querySelector('input[name=ah_open_away]').value),
    now_home: toNum(n.querySelector('input[name=ah_now_home]').value),
    now_away: toNum(n.querySelector('input[name=ah_now_away]').value)
  }));
  return { home, away, favorite, open1, now1, ah, options: {} };
}

// load learning
let serverLearning = {};
async function loadLearning(){
  try {
    const r = await fetch('?action=get_learning');
    const j = await r.json();
    if (j.status === 'ok' && j.learning) serverLearning = j.learning;
  } catch(e){}
}

// analyze flow: call analyze endpoint, render result, then best-effort save learning
async function analyze(){
  const payload = collectPayload();
  document.getElementById('resultWrap').style.display = 'block';
  document.getElementById('mainSummary').innerHTML = '<div class="small">กำลังวิเคราะห์…</div>';
  try {
    const res = await fetch('?action=analyze', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch(e) { document.getElementById('mainSummary').innerHTML = '<div class="small">Response parse error</div><pre style="background:#fff;color:#000">'+text.replace(/</g,'&lt;')+'</pre>'; return; }
    renderResult(json);
    spawnInk();
    // Save EWMA implied probs (best-effort)
    const openNow = payload.now1 && payload.now1.home ? payload.now1 : payload.open1;
    if (openNow && !isNaN(openNow.home) && openNow.home>0){
      const fd = new FormData();
      fd.append('home', openNow.home);
      fd.append('draw', openNow.draw);
      fd.append('away', openNow.away);
      fetch('?action=save_learning', { method:'POST', body: fd }).then(r=>r.json()).then(j=>{ loadLearning(); }).catch(()=>{});
    }
  } catch(err){
    document.getElementById('mainSummary').innerHTML = '<div class="small">Fetch error: '+err.message+'</div>';
  }
}

document.getElementById('analyzeBtn').addEventListener('click', analyze);

// renderResult (light)
function renderResult(r){
  document.getElementById('reboundDisplay').value = r.metrics && r.metrics.reboundSens ? r.metrics.reboundSens : 'Auto';
  document.getElementById('confValue').innerText = r.confidence!==undefined ? (r.confidence+'%') : '--%';
  document.getElementById('flowPowerValue').innerText = r.flowPower!==undefined ? (r.flowPower) : '--';
  document.getElementById('nfiValue').innerText = r.metrics && r.metrics.nfi!==undefined ? nf(r.metrics.nfi,4) : '--';

  const mainSummary = document.getElementById('mainSummary');
  let html = `<div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-weight:900;font-size:1.15rem;color:var(--gold)">${r.final_label}</div><div style="margin-top:8px"><strong>คำแนะนำ:</strong> ${r.recommendation}</div><div style="margin-top:6px"><strong>คาดการณ์ทีมผู้ชนะ:</strong> <strong>${r.predicted_winner}</strong></div></div><div style="text-align:right"><div class="tag">Hack200%</div></div></div>`;
  mainSummary.innerHTML = html;
  if (r.market_kill) mainSummary.insertAdjacentHTML('afterbegin','<div class="alarm">⚠️ MARKET KILL ALERT — ห้ามเสี่ยง</div>');
  if (r.signature && r.signature.length) mainSummary.insertAdjacentHTML('beforeend','<div style="margin-top:8px;padding:8px;border-radius:8px;background:linear-gradient(90deg,#4b0082,#ff3b30);color:#fff;font-weight:900;text-align:center">🔥 '+(r.signature.join(', '))+'</div>');

  const mainReasons = document.getElementById('mainReasons');
  let reasonsHtml = '<strong>เหตุผลโดยย่อ</strong>';
  if (!r.reasons || r.reasons.length===0) reasonsHtml += '<div class="small" style="color:#cdb68e">ไม่พบสัญญาณพิเศษ</div>';
  else { reasonsHtml += '<ul>'; r.reasons.forEach(rr => reasonsHtml += '<li>'+rr+'</li>'); reasonsHtml += '</ul>'; }
  mainReasons.innerHTML = reasonsHtml;

  let dt = document.getElementById('detailTables');
  let html2 = '<strong>รายละเอียด 1X2</strong>';
  html2 += '<table class="table"><thead><tr><th>ฝั่ง</th><th>Open</th><th>Now</th><th>NetFlow</th><th>Mom</th></tr></thead><tbody>';
  ['home','draw','away'].forEach(side => {
    const o = r.input && r.input.open1 ? r.input.open1[side] : '-';
    const n = r.input && r.input.now1 ? r.input.now1[side] : '-';
    const net = r.flows_1x2 && r.flows_1x2[side]!==undefined ? nf(r.flows_1x2[side],4) : '-';
    const mom = r.mom1 && r.mom1[side]!==undefined ? nf(r.mom1[side],4) : '-';
    html2 += `<tr><td>${side}</td><td>${o===undefined?'-':o}</td><td>${n===undefined?'-':n}</td><td>${net}</td><td>${mom}</td></tr>`;
  });
  html2 += '</tbody></table>';

  html2 += '<div style="height:8px"></div><strong>AH Lines</strong>';
  html2 += '<table class="table"><thead><tr><th>Line</th><th>Open H</th><th>Now H</th><th>Net H</th><th>Mom H</th><th>Dir H</th><th>Dir A</th></tr></thead><tbody>';
  (r.ah_details||[]).forEach(ad=>{
    html2 += `<tr><td>${ad.line||'-'}</td><td>${ad.open_home||'-'}</td><td>${ad.now_home||'-'}</td><td>${ad.net_home===undefined?'-':nf(ad.net_home,4)}</td><td>${ad.mom_home===undefined?'-':nf(ad.mom_home,4)}</td><td>${ad.dir_home||'-'}</td><td>${ad.dir_away||'-'}</td></tr>`;
  });
  html2 += '</tbody></table>';

  if (r.metrics && r.metrics.trapFlags && r.metrics.trapFlags.length) html2 += `<div style="margin-top:8px;background:rgba(244,63,94,0.06);border-left:4px solid rgba(244,63,94,0.6);padding:8px;border-radius:8px;color:#ffd6db"><strong>Trap flags:</strong> ${r.metrics.trapFlags.join(', ')}</div>`;
  if (r.signature && r.signature.length) html2 += `<div style="margin-top:8px;background:rgba(255,140,54,0.04);border-left:4px solid rgba(255,99,72,0.6);padding:8px;border-radius:8px;color:#ffe9c7"><strong>Signature:</strong> ${r.signature.join(', ')}</div>`;

  dt.innerHTML = html2;

  const stakeEl = document.getElementById('stakeSuggestion');
  if (r.stake_pct && r.stake_pct>0) stakeEl.innerHTML = `<div style="font-weight:900;color:var(--gold)">${r.stake_pct}%</div><div class="small">แนะนำความระมัดระวัง</div>`;
  else stakeEl.innerHTML = `<div class="small">ไม่แนะนำเดิมพันแบบชัดเจน</div>`;

  const tome = document.getElementById('secretTome');
  const tc = document.getElementById('tomeContent');
  if (r.mother_price || r.intent_price || r.money_price){
    tome.style.display='block';
    let t='';
    if (r.mother_price) t+=`<div><strong>ราคาแม่ (เปิด):</strong> ${r.mother_price.line} — H:${r.mother_price.open_home} / A:${r.mother_price.open_away}</div>`;
    if (r.intent_price) t+=`<div><strong>ราคาเจตนา (mom):</strong> ${r.intent_price.line} — Mom H:${r.intent_price.mom_home} A:${r.intent_price.mom_away}</div>`;
    if (r.money_price) t+=`<div><strong>ราคาเงินใหญ่ (momentum):</strong> ${r.money_price.line} — Net H:${r.money_price.net_home} A:${r.money_price.net_away}</div>`;
    t+=`<div style="margin-top:8px;color:#ffdca8">คำแนะนำคัมภีร์: หากราคาแม่นิ่ง แต่เงินใหญ่เปลี่ยนเร็ว → ระวังกับดัก; หากเจตนา+เงินใหญ่สอดคล้อง → ไหลจริง</div>`;
    tc.innerHTML = t;
  } else { tome.style.display='none'; tc.innerHTML='ยังไม่มี AH เพียงพอ'; }

  // show seal if market_kill
  if (r.market_kill){
    showImperialSeal(r.signature && r.signature.length ? r.signature.join(', ') : 'MARKET');
  }
  window.lastAnalysis = r;
}

// imperial seal (light)
function showImperialSeal(text='御印'){
  if (document.getElementById('imperialSeal')) return;
  const seal = document.createElement('div');
  seal.id='imperialSeal';
  seal.className='seal mini-fade';
  seal.innerText = text;
  document.body.appendChild(seal);
  setTimeout(()=>{ seal.style.transition='opacity 0.9s'; seal.style.opacity = '0'; setTimeout(()=>seal.remove(),900); }, 2600);
}

// load learning on start
loadLearning();
</script>
</body>
</html>
