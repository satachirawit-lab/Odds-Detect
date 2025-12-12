<?php
declare(strict_types=1);
/**
 * Last.php — THE VOID FULL (PM-DE + PM-SMC + PM-PATTERN AI integrated)
 * Single-file deploy — PHP 7.4+ (uses pdo_sqlite)
 *
 * - Pre-match focused: EWMA learning, Pattern memory, Disparity Engine, Smart-Money Classifier
 * - Stores historical samples & match_cases for long-term learning
 * - UI included (Chinese themed, responsive)
 *
 * NOTES:
 * - Ensure pdo_sqlite enabled.
 * - Backup your previous Last.php before replacing.
 */

// ---------------- ENV ----------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$BASEDIR = __DIR__;
$DB_FILE = $BASEDIR . '/void_master_full.db';

// ---------------- DB INIT ----------------
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // core stores
    $pdo->exec("CREATE TABLE IF NOT EXISTS ewma_store (k TEXT PRIMARY KEY, v REAL, alpha REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT, sample REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS results_history (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT, res_json TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS league_profiles (league TEXT PRIMARY KEY, sample_count INTEGER, avg_move REAL, volatility REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS match_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, kickoff_ts INTEGER, league TEXT, payload TEXT, analysis TEXT, outcome TEXT, ts INTEGER)");

    // new stores for PM modules
    $pdo->exec("CREATE TABLE IF NOT EXISTS disparity_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, league TEXT, open_json TEXT, now_json TEXT, disparity REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS smart_moves (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, type TEXT, score REAL, details TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pattern_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, signature TEXT, count INTEGER, win_rate REAL, last_seen INTEGER, meta TEXT)");
} catch (Exception $e) {
    error_log("DB init error: ".$e->getMessage());
    $pdo = null;
}

// ---------------- CONFIG ----------------
$CONFIG = [
    'mode' => 'pre_match',
    'ewma_alpha' => [
        'master_netflow' => 0.08,
        'master_voidscore' => 0.06,
        'master_smk' => 0.10,
        'master_lve' => 0.10,
        'master_spike' => 0.06,
        'master_vpe_home' => 0.08,
        'master_vpe_away' => 0.08
    ],
    'reboundSens_min' => 0.03,
    'netflow_sharp_threshold' => 0.18,
    'divergence_ultra' => 0.18,
    'vpe_weight_pre_match' => 0.20,
    'window_weights' => ['open'=>0.08,'24h_6h'=>0.20,'6h_1h'=>0.30,'1h_15m'=>0.42],
];

// ---------------- Helpers ----------------
function safeFloat($v){ if(!isset($v)) return NAN; $s=trim((string)$v); if($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s)? floatval($s) : NAN; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }
function nf($v,$d=4){ return (is_nan($v) || $v===null) ? '-' : number_format((float)$v,$d,'.',''); }
function netflow($open,$now){ if(is_nan($open)||is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n=netflow($open,$now); return is_nan($n)?NAN:abs($n); }
function dir_label($open,$now){ if(is_nan($open)||is_nan($now)) return 'flat'; if($now<$open) return 'down'; if($now>$open) return 'up'; return 'flat'; }
function impliedProb($o){ return (is_nan($o) || $o<=0) ? NAN : (1.0 / $o); }

// ---------------- EWMA ----------------
function ewma_get(string $k, float $fallback = 0.0, float $default_alpha = 0.25) {
    global $pdo, $CONFIG;
    if (!$pdo) return ['v'=>$fallback,'alpha'=>$default_alpha];
    $st = $pdo->prepare("SELECT v,alpha FROM ewma_store WHERE k=:k");
    $st->execute([':k'=>$k]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        $alpha = $default_alpha;
        if (isset($CONFIG['ewma_alpha'][$k])) $alpha = floatval($CONFIG['ewma_alpha'][$k]);
        $ins = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
        $ins->execute([':k'=>$k,':v'=>$fallback,':a'=>$alpha,':t'=>time()]);
        return ['v'=>$fallback,'alpha'=>$alpha];
    }
    return ['v'=>floatval($r['v']),'alpha'=>floatval($r['alpha'])];
}
function ewma_update(string $k, float $sample) {
    global $pdo;
    if (!$pdo) return null;
    $cur = ewma_get($k);
    $alpha = floatval($cur['alpha'] ?? 0.25);
    $newv = ($alpha * $sample) + ((1.0 - $alpha) * floatval($cur['v']));
    $st = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
    $st->execute([':k'=>$k,':v'=>$newv,':a'=>$alpha,':t'=>time()]);
    $st2 = $pdo->prepare("INSERT INTO samples (k,sample,ts) VALUES(:k,:s,:t)");
    $st2->execute([':k'=>$k,':s'=>$sample,':t'=>time()]);
    return ['k'=>$k,'v'=>$newv,'alpha'=>$alpha];
}

// ---------------- PM-DE: Pre-Match Disparity Engine ----------------
/**
 * Compute disparity between market implied probability and "true" normalized implied,
 * and produce a disparity metric for sides.
 * Returns array: ['disparity'=>float, 'details'=>[home,draw,away], 'disparity_label'=>string]
 */
function pm_disparity(array $open1, array $now1): array {
    // Calculate implied probabilities normalized
    $io=[]; $in=[];
    $sum_open=0;$sum_now=0;
    foreach (['home','draw','away'] as $k) {
        $o = safeFloat($open1[$k] ?? null); $n = safeFloat($now1[$k] ?? null);
        $io[$k] = (is_nan($o) || $o<=0) ? 0.0 : (1.0/$o); $sum_open += $io[$k];
        $in[$k] = (is_nan($n) || $n<=0) ? 0.0 : (1.0/$n); $sum_now += $in[$k];
    }
    if ($sum_open > 0) foreach (['home','draw','away'] as $k) $io[$k] /= $sum_open;
    if ($sum_now > 0) foreach (['home','draw','away'] as $k) $in[$k] /= $sum_now;

    // Disparity: difference between open-normalized and now-normalized
    $details = [];
    $maxDisp = 0.0; $maxKey = null;
    foreach (['home','draw','away'] as $k) {
        $d = ($in[$k] - $io[$k]); // positive means market now favors k more than open
        $details[$k] = $d;
        if (abs($d) > abs($maxDisp)) { $maxDisp = $d; $maxKey = $k; }
    }
    // label
    $label = 'neutral';
    if ($maxKey !== null) {
        if ($maxDisp > 0.03) $label = $maxKey . '_more_backed';
        elseif ($maxDisp < -0.03) $label = $maxKey . '_less_backed';
    }

    // aggregate disparity magnitude
    $magnitude = array_reduce($details, function($carry,$v){ return $carry + abs($v); }, 0.0);
    // persist some stats
    global $pdo;
    if ($pdo) {
        $mk = md5(json_encode($open1).json_encode($now1).time());
        $st = $pdo->prepare("INSERT INTO disparity_stats (match_key,league,open_json,now_json,disparity,ts) VALUES(:mk,:league,:o,:n,:d,:t)");
        $st->execute([':mk'=>$mk,':league'=>'generic',':o'=>json_encode($open1),':n'=>json_encode($now1),':d'=>$magnitude,':t'=>time()]);
    }

    return ['disparity'=>$magnitude,'details'=>$details,'label'=>$label];
}

// ---------------- PM-SMC: Smart Money Classifier ----------------
/**
 * Classify the movement as Smart/Public/Reverse/Trap using heuristics:
 * - Smart moves: high netflow aligned across AH + 1x2 and high concentration
 * - Public moves: large change in price but low AH synchronization
 * - Reverse moves: AH and 1x2 conflict strongly
 */
function pm_smc(array $open1, array $now1, array $ah_details, float $juicePressureNorm, float $stackFactor, float $divergence): array {
    $score = 0.0; $flags = [];
    // baseline from juice & stack
    $score += clampf($juicePressureNorm,0,1) * 0.5; // juice indicates real money
    $score += clampf($stackFactor,0,1) * 0.3;
    $score -= clampf($divergence / 0.3, 0, 1) * 0.4; // divergence reduces confidence
    // check AH+1x2 sync
    $sync = 0; $checks=0;
    foreach ($ah_details as $ad) {
        $checks++;
        $ah_dir_home = $ad['dir_home']; $ah_dir_away = $ad['dir_away'];
        $x2_home_dir = dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
        $x2_away_dir = dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
        if ($ah_dir_home === $x2_home_dir) $sync++;
        if ($ah_dir_away === $x2_away_dir) $sync++;
    }
    $syncRatio = ($checks>0) ? ($sync / ($checks*2)) : 0.0;
    $score += $syncRatio * 0.4;

    // final classification
    $score = clampf($score, 0.0, 1.0);
    $type = 'unknown';
    if ($score >= 0.7) $type = 'smart_money';
    elseif ($score >= 0.45) $type = 'mixed_public';
    else $type = 'public_or_trap';

    // details
    $details = ['juice'=>$juicePressureNorm,'stack'=>$stackFactor,'sync'=>$syncRatio,'div'=>$divergence,'score'=>$score];

    // store event
    global $pdo;
    if ($pdo) {
        $st = $pdo->prepare("INSERT INTO smart_moves (match_key,type,score,details,ts) VALUES(:mk,:t,:s,:d,:ts)");
        $st->execute([':mk'=>md5(json_encode($open1).time()),':t'=>$type,':s'=>$score,':d'=>json_encode($details),':ts'=>time()]);
    }

    return ['type'=>$type,'score'=>$score,'details'=>$details];
}

// ---------------- PM-PATTERN: Pattern Memory (learn & lookup) ----------------
function pm_pattern_lookup(array $signature): ?array {
    global $pdo;
    if (!$pdo) return null;
    $sig = md5(json_encode($signature));
    $st = $pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:sig");
    $st->execute([':sig'=>$sig]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;
    return null;
}
function pm_pattern_learn(array $signature, bool $win, array $meta=[]){
    global $pdo;
    if (!$pdo) return false;
    $sig = md5(json_encode($signature));
    $st = $pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:sig");
    $st->execute([':sig'=>$sig]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $now = time();
    if ($r) {
        $count = intval($r['count']) + 1;
        $prevWR = floatval($r['win_rate']);
        $newWR = (($prevWR * (intval($r['count'])) ) + ($win?1.0:0.0)) / max(1,$count);
        $upd = $pdo->prepare("UPDATE pattern_memory SET count=:cnt, win_rate=:wr, last_seen=:ls, meta=:m WHERE id=:id");
        $upd->execute([':cnt'=>$count,':wr'=>$newWR,':ls'=>$now,':m'=>json_encode($meta),':id'=>$r['id']]);
    } else {
        $ins = $pdo->prepare("INSERT INTO pattern_memory (signature,count,win_rate,last_seen,meta) VALUES(:sig,1,:wr,:ls,:m)");
        $ins->execute([':sig'=>$sig,':wr'=>($win?1.0:0.0),':ls'=>$now,':m'=>json_encode($meta)]);
    }
    return true;
}

// ---------------- Utility analytics from previous engine (trimmed for brevity but preserved) ----------------
function compute_auto_rebound_from_pair(float $open, float $now): float {
    if ($open <= 0 || $now <= 0) return 0.02;
    $delta = abs($now - $open);
    if ($delta <= 0.000001) return 0.04;
    $strength = $delta / $open;
    if ($strength < 0.02) return 0.04;
    if ($strength < 0.05) return 0.03;
    if ($strength < 0.12) return 0.02;
    return 0.015;
}
function compute_auto_rebound_agg(array $pairs): float {
    global $CONFIG;
    $sList=[];
    foreach ($pairs as $p) {
        if (!isset($p['open'])||!isset($p['now'])) continue;
        $o=floatval($p['open']); $n=floatval($p['now']);
        if ($o>0 && $n>0) $sList[] = compute_auto_rebound_from_pair($o,$n);
    }
    if (count($sList)===0) return 0.025;
    $val = array_sum($sList)/count($sList);
    if (isset($CONFIG['reboundSens_min'])) $val = max($val, floatval($CONFIG['reboundSens_min']));
    return $val;
}

// keep essential analysis functions (abbreviated versions of the earlier engine)
function lve_compute(float $current_sample): array {
    // simple version for money weight
    $rows = [];
    global $pdo;
    if ($pdo) {
        $st = $pdo->prepare("SELECT sample,ts FROM samples WHERE k='master_netflow' ORDER BY id DESC LIMIT 8");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $vals = array_map(function($r){ return floatval($r['sample']); }, $rows);
    $vel = 0.0;
    if (count($vals) >= 2) {
        $vel = $vals[0] - end($vals);
    }
    $mw = clampf(abs($vel) * 200.0, 0.0, 100.0);
    return ['velocity'=>$vel,'money_weight'=>$mw];
}
function vpe_compute(float $agg_sample, array $lve): array {
    $base = clampf($agg_sample / 1.0, -1.0, 1.0);
    $mw = isset($lve['money_weight']) ? floatval($lve['money_weight'])/100.0 : 0.0;
    $bias = clampf($base * (0.6 + 0.4*$mw), -1.0, 1.0);
    $home_pct = round((0.5 + $bias/2.0) * 100.0,1);
    $away_pct = round(100.0 - $home_pct,1);
    return ['home'=>$home_pct,'away'=>$away_pct,'bias'=>$bias];
}
function cmf_engine(array $open1, array $now1, array $ah_list): array {
    $score=0.0; $risk=0.0; $notes=[];
    foreach ($ah_list as $ad) {
        $oh = safeFloat($ad['open_home'] ?? null); $nh = safeFloat($ad['now_home'] ?? null);
        $oa = safeFloat($ad['open_away'] ?? null); $na = safeFloat($ad['now_away'] ?? null);
        if (!is_nan($oh) && !is_nan($nh) && abs($oh-$nh) > 0.05) $score += 0.08;
    }
    return ['cmfScore'=>round($score*100,1),'cmfRisk'=>round($risk*100,1),'notes'=>$notes];
}

// ---------------- MASTER ANALYZE (full integrated) ----------------
function master_analyze(array $payload): array {
    global $pdo, $CONFIG;
    $home = $payload['home'] ?? 'เหย้า'; $away = $payload['away'] ?? 'เยือน';
    $league = $payload['league'] ?? 'generic';
    $kickoff = isset($payload['kickoff']) ? intval(strtotime($payload['kickoff'])) : ($payload['kickoff_ts'] ?? null);
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1 = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];
    $context = $payload['context'] ?? [];

    // AH details & pairs
    $pairs = [];
    $ah_details = [];
    $totalAH_mom = 0.0;
    foreach ($ah_list as $i => $r) {
        $line = $r['line'] ?? ('AH'.($i+1));
        $oh = safeFloat($r['open_home'] ?? null); $oa = safeFloat($r['open_away'] ?? null);
        $nh = safeFloat($r['now_home'] ?? null); $na = safeFloat($r['now_away'] ?? null);
        $mh = is_nan($oh) || is_nan($nh) ? NAN : abs($oh - $nh);
        $ma = is_nan($oa) || is_nan($na) ? NAN : abs($oa - $na);
        if (!is_nan($mh)) $totalAH_mom += $mh; if (!is_nan($ma)) $totalAH_mom += $ma;
        $ad = ['index'=>$i,'line'=>$line,'open_home'=>$oh,'open_away'=>$oa,'now_home'=>$nh,'now_away'=>$na,'net_home'=>is_nan($oh)||is_nan($nh)?0.0:$oh-$nh,'net_away'=>is_nan($oa)||is_nan($na)?0.0:$oa-$na,'mom_home'=>$mh,'mom_away'=>$ma,'dir_home'=>dir_label($oh,$nh),'dir_away'=>dir_label($oa,$na)];
        $ah_details[] = $ad;
        $pairs[] = ['open'=>$oh,'now'=>$nh];
        $pairs[] = ['open'=>$oa,'now'=>$na];
    }

    // 1x2 flows
    $flow1 = ['home'=> netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> netflow(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $mom1 = ['home'=> mom_abs(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> mom_abs(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> mom_abs(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $total1x2_mom = 0.0; foreach ($mom1 as $v) if (!is_nan($v)) $total1x2_mom += $v;
    $marketMomentum = $total1x2_mom + $totalAH_mom;

    // rebound
    $reboundSens = compute_auto_rebound_agg($pairs);

    // apply pre-match minimum
    if (($payload['mode'] ?? $CONFIG['mode']) === 'pre_match') {
        $reboundSens = max($reboundSens, floatval($CONFIG['reboundSens_min']));
    }

    // divergence
    $divergence = abs($totalAH_mom - $total1x2_mom);

    // juice pressure & stack
    $juicePressure = 0.0;
    foreach ($ah_details as $ad) {
        $hj = (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) ? ($ad['open_home'] - $ad['now_home']) : 0.0;
        $aj = (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) ? ($ad['open_away'] - $ad['now_away']) : 0.0;
        $juicePressure += abs($hj)+abs($aj);
    }
    foreach (['home','away'] as $s) { $o=safeFloat($open1[$s]??null); $n=safeFloat($now1[$s]??null); if(!is_nan($o)&&!is_nan($n)) $juicePressure += abs($o-$n); }
    $juicePressureNorm = min(3.0, $juicePressure / max(0.02, $marketMomentum + 1e-9));
    $stackHome=0;$stackAway=0;
    foreach ($ah_details as $ls) {
        $hRel = (!is_nan($ls['open_home']) && $ls['open_home']>0) ? (($ls['now_home'] - $ls['open_home']) / $ls['open_home']) : 0;
        $aRel = (!is_nan($ls['open_away']) && $ls['open_away']>0) ? (($ls['now_away'] - $ls['open_away']) / $ls['open_away']) : 0;
        if ($hRel < 0 || $aRel < 0) $stackHome++; if ($hRel > 0 || $aRel > 0) $stackAway++;
    }
    $stackMax = max($stackHome,$stackAway);
    $stackFactor = count($ah_details)>0 ? clampf($stackMax / max(1, count($ah_details)), 0.0, 1.0) : 0.0;

    // pm disparity
    $disparity = pm_disparity($open1, $now1);

    // pm smc
    $smc = pm_smc($open1, $now1, $ah_details, $juicePressureNorm, $stackFactor, $divergence);

    // small analyses
    $cmf = cmf_engine($open1,$now1,$ah_details);
    $lve = lve_compute( ($flow1['home'] ?? 0) - ($flow1['away'] ?? 0) );
    $vpe = vpe_compute((($flow1['home'] ?? 0) - ($flow1['away'] ?? 0)), $lve);

    // raw signal combine (simplified)
    $w_momentum = min(1.0, $marketMomentum / 1.0);
    $w_stack = min(1.0, $stackFactor);
    $w_juice = min(1.0, $juicePressureNorm / 1.2);
    $rawSignal = (($w_momentum * 0.28) + ($w_stack * 0.20) + ($w_juice * 0.18));
    $dirScore = (($flow1['home'] ?? 0) - ($flow1['away'] ?? 0)) * 0.6;
    $dirNorm = ($rawSignal > 1e-6) ? tanh($dirScore / (0.5 + $marketMomentum)) : 0.0;
    $hackScore = clampf($rawSignal * $dirNorm * 1.5, -1.0, 1.0);

    // final void score (compress)
    $metrics_for_void = ['flowPower'=>abs($hackScore),'confidence'=>50,'smartMoneyScore'=>$smc['score'],'juicePressureNorm'=>$juicePressureNorm,'stackFactor'=>$stackFactor,'divergence'=>$divergence];
    $void_score = clampf($metrics_for_void['smartMoneyScore'] * 0.8 + $metrics_for_void['flowPower']*0.2 - ($divergence/1.0), -1.0, 1.0);

    // true probability injection (use disparity and vpe)
    $market_prob = [];
    $sum_now = 0;
    foreach (['home','draw','away'] as $s) {
        $n = safeFloat($now1[$s] ?? null);
        $market_prob[$s] = (is_nan($n) || $n <= 0) ? 0.0 : (1.0 / $n);
        $sum_now += $market_prob[$s];
    }
    if ($sum_now > 0) foreach (['home','draw','away'] as $s) $market_prob[$s] /= $sum_now;
    // inject disparity: if disparity.details indicates strong move, shift true_prob
    $true_prob = $market_prob;
    $bias = clampf($disparity['details']['home'] - $disparity['details']['away'], -0.25, 0.25);
    $true_prob['home'] = clampf($market_prob['home'] + ($void_score*0.4) + ($vpe['bias']*0.01) + ($bias*0.5), 0.0001, 0.9999);
    $true_prob['away'] = clampf($market_prob['away'] - ($void_score*0.4) - ($vpe['bias']*0.01) - ($bias*0.5), 0.0001, 0.9999);
    $true_prob['draw'] = max(0.0001, 1.0 - ($true_prob['home'] + $true_prob['away']));
    $sumt = $true_prob['home'] + $true_prob['draw'] + $true_prob['away'];
    foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sumt);

    // final labels & recommendation
    $predicted = 'ไม่แน่ใจ';
    if ($true_prob['home'] > $true_prob['away'] && $true_prob['home'] > $true_prob['draw']) $predicted = $home;
    if ($true_prob['away'] > $true_prob['home'] && $true_prob['away'] > $true_prob['draw']) $predicted = $away;

    $final_label = '⚠️ ไม่ชัดเจน'; $recommendation = 'รอ confirm';
    if ($smc['type'] === 'smart_money' && $void_score > 0.25) { $final_label = '✅ ไหลจริง (SmartMoney Home)'; $recommendation = 'พิจารณาตาม — จำกัดการเสี่ยง'; }
    if ($smc['type'] === 'smart_money' && $void_score < -0.25) { $final_label = '✅ ไหลจริง (SmartMoney Away)'; $recommendation = 'พิจารณาตาม — จำกัดการเสี่ยง'; }
    if ($smc['type'] === 'public_or_trap' && $divergence > 0.12) { $final_label = '❌ สัญญาณหลอก/Trap'; $recommendation = 'หลีกเลี่ยง'; }

    // persist EWMA & samples
    ewma_update('master_netflow', floatval((($flow1['home'] ?? 0) - ($flow1['away'] ?? 0))));
    ewma_update('master_voidscore', floatval($void_score));
    ewma_update('master_smk', floatval($smc['score']));
    ewma_update('master_vpe_home', floatval($vpe['home']));
    ewma_update('master_vpe_away', floatval($vpe['away']));

    // persist match_case
    if ($pdo) {
        $mk = md5($home.'|'.$away.'|'.($kickoff?:time()));
        $ins = $pdo->prepare("INSERT INTO match_cases (match_key,kickoff_ts,league,payload,analysis,outcome,ts) VALUES(:mk,:ks,:league,:p,:a,:o,:t)");
        $ins->execute([':mk'=>$mk,':ks'=>($kickoff?:0),':league'=>$league,':p'=>json_encode($payload, JSON_UNESCAPED_UNICODE),':a'=>json_encode(['void_score'=>$void_score,'smc'=>$smc,'disparity'=>$disparity,'vpe'=>$vpe,'predicted'=>$predicted], JSON_UNESCAPED_UNICODE),':o'=>'',':t'=>time()]);
    }

    // return
    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'league'=>$league,'kickoff_ts'=>$kickoff,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list,'context'=>$context],
        'metrics'=>[
            'marketMomentum'=>$marketMomentum,
            'total1x2_mom'=>$total1x2_mom,
            'totalAH_mom'=>$totalAH_mom,
            'reboundSens'=>$reboundSens,
            'divergence'=>$divergence,
            'juicePressureNorm'=>$juicePressureNorm,
            'stackFactor'=>$stackFactor,
            'disparity'=>$disparity,
            'smc'=>$smc,
            'vpe'=>$vpe
        ],
        'void_score'=>$void_score,
        'final_label'=>$final_label,
        'recommendation'=>$recommendation,
        'predicted_winner'=>$predicted,
        'true_prob'=>$true_prob,
        'market_prob_now'=>$market_prob,
        'ah_details'=>$ah_details
    ];
}

// ---------------- HTTP endpoints ----------------
if (php_sapi_name() !== 'cli') {
    if (isset($_GET['action']) && $_GET['action'] === 'master_analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input'); $payload = json_decode($raw, true);
        if (!is_array($payload)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>'invalid_payload','raw'=>substr($raw,0,200)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
        $res = master_analyze($payload);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'ewma_stats') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$pdo) { echo json_encode(['status'=>'error','msg'=>'no_db']); exit; }
        $st = $pdo->query("SELECT * FROM ewma_store");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','ewma'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'match_cases') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$pdo) { echo json_encode(['status'=>'error','msg'=>'no_db']); exit; }
        $st = $pdo->query("SELECT id,match_key,kickoff_ts,league,ts FROM match_cases ORDER BY id DESC LIMIT 100");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','cases'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
}

// ---------------- FRONTEND UI (simpler, chinese motif) ----------------
?><!doctype html>
<html lang="th">
<head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>THE VOID — Full Pre-match Engine</title>
<style>
:root{--gold:#d4a017;--bg1:#05040a;--bg2:#120713;--muted:#d9c89a}
*{box-sizing:border-box} body{margin:0;font-family:Inter,'Noto Sans Thai',sans-serif;background:linear-gradient(180deg,var(--bg1),var(--bg2));color:#fff}
.container{max-width:1200px;margin:18px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between}
.logo{width:88px;height:88px;border-radius:16px;background:radial-gradient(circle at 30% 20%, rgba(255,255,255,0.04), rgba(91,33,182,0.95));display:flex;align-items:center;justify-content:center;font-weight:900;font-size:36px;color:#fff}
.card{background:linear-gradient(145deg,#0c0606,#241316);border-radius:14px;padding:16px;margin-top:14px;box-shadow:0 10px 30px rgba(0,0,0,0.6)}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
input,select,textarea{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.02);color:#fff}
.btn{padding:10px 14px;border-radius:10px;border:none;background:linear-gradient(90deg,#7e2aa3,var(--gold));cursor:pointer}
@media(max-width:980px){.grid{grid-template-columns:1fr}}
.small{color:var(--muted);font-size:0.9rem}
.table{width:100%;border-collapse:collapse;margin-top:8px}.table th,.table td{padding:8px;border-bottom:1px dashed rgba(255,255,255,0.03);text-align:left}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div style="display:flex;gap:12px;align-items:center"><div class="logo">鴻</div><div><h1 style="margin:0;color:var(--gold)">THE VOID — Full Pre-match Engine</h1><div class="small">PM-DE • PM-SMC • Pattern Memory • EWMA</div></div></div>
    <div><button onclick="alert('Backup your files first!')" class="btn">สำรองไฟล์</button></div>
  </div>

  <div class="card">
    <form id="mainForm" onsubmit="return false;">
      <div class="grid">
        <div><label>ทีมเหย้า</label><input id="home" placeholder="ทีมเหย้า"></div>
        <div><label>ทีมเยือน</label><input id="away" placeholder="ทีมเยือน"></div>
        <div><label>ลีก</label><input id="league" placeholder="EPL, LaLiga"></div>
      </div>
      <div style="height:12px"></div>

      <div class="card">
        <strong class="small">Kickoff</strong>
        <input id="kickoff" placeholder="YYYY-MM-DD HH:MM">
        <div style="height:8px"></div>
        <strong class="small">1X2 Open</strong>
        <div class="grid"><input id="open1_home" placeholder="2.10"><input id="open1_draw" placeholder="3.40"><input id="open1_away" placeholder="3.10"></div>
        <div style="height:8px"></div>
        <strong class="small">1X2 Now</strong>
        <div class="grid"><input id="now1_home" placeholder="1.95"><input id="now1_draw" placeholder="3.60"><input id="now1_away" placeholder="3.80"></div>
      </div>

      <div style="height:12px"></div>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center"><strong class="small">Asian Handicap (add multiple)</strong><div><button id="addAh" type="button" class="btn">+ AH</button></div></div>
        <div id="ahContainer"></div>
      </div>

      <div style="height:12px;display:flex;justify-content:space-between;align-items:center">
        <div><input id="favorite" placeholder="ทีมต่อ (home/away)"></div>
        <div><button id="analyzeBtn" class="btn">🔎 วิเคราะห์</button></div>
      </div>
    </form>
  </div>

  <div id="resultWrap" class="card" style="display:none;margin-top:14px">
    <div id="mainSummary"></div>
    <div id="detail"></div>
  </div>

  <div class="card small" style="margin-top:12px"><strong>หมายเหตุ</strong><div class="small">ระบบนี้ใช้ข้อมูลที่คุณกรอกเท่านั้น — บันทึกลง DB เพื่อเรียนรู้แบบต่อเนื่อง</div></div>
</div>

<script>
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function createAhBlock(data={}){ const cont=document.getElementById('ahContainer'); const div=document.createElement('div'); div.style='margin-top:8px;padding:8px;background:rgba(255,255,255,0.01);border-radius:8px'; div.innerHTML=`<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px"><input placeholder="line" class="ah_line" value="${data.line||''}"><input placeholder="open_home" class="ah_open_home" value="${data.open_home||''}"><input placeholder="open_away" class="ah_open_away" value="${data.open_away||''}"></div><div style="height:8px"></div><div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:8px"><input placeholder="now_home" class="ah_now_home" value="${data.now_home||''}"><input placeholder="now_away" class="ah_now_away" value="${data.now_away||''}"><button class="removeBtn">ลบ</button></div>`; cont.appendChild(div); div.querySelector('.removeBtn').addEventListener('click', ()=>div.remove()); }
document.getElementById('addAh').addEventListener('click', ()=>createAhBlock());
if(!document.querySelectorAll('#ahContainer div').length) createAhBlock();

async function analyze(){
  document.getElementById('resultWrap').style.display='block';
  document.getElementById('mainSummary').innerText = 'กำลังวิเคราะห์...';
  const home=document.getElementById('home').value||'เหย้า'; const away=document.getElementById('away').value||'เยือน';
  const league=document.getElementById('league').value||'generic'; const kickoff=document.getElementById('kickoff').value||'';
  const open1={home:toNum(document.getElementById('open1_home').value),draw:toNum(document.getElementById('open1_draw')?.value),away:toNum(document.getElementById('open1_away')?.value)};
  const now1={home:toNum(document.getElementById('now1_home').value),draw:toNum(document.getElementById('now1_draw')?.value),away:toNum(document.getElementById('now1_away')?.value)};
  const ahNodes=Array.from(document.querySelectorAll('#ahContainer > div'));
  const ah=ahNodes.map(n=>({line:n.querySelector('input').value, open_home:toNum(n.querySelector('.ah_open_home').value), open_away:toNum(n.querySelector('.ah_open_away').value), now_home:toNum(n.querySelector('.ah_now_home').value), now_away:toNum(n.querySelector('.ah_now_away').value)}));
  const payload={home,away,league,kickoff,open1,now1,ah,mode:'pre_match',context:{}};
  try{
    const res=await fetch('?action=master_analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j=await res.json();
    render(j);
  }catch(e){ document.getElementById('mainSummary').innerText = 'Fetch error: '+e.message; }
}
document.getElementById('analyzeBtn').addEventListener('click', analyze);

function render(r){
  document.getElementById('mainSummary').innerHTML = `<div style="font-weight:900;color:#d4a017">${r.final_label}</div><div style="margin-top:6px">Recommendation: ${r.recommendation}</div><div style="margin-top:6px">Predicted: ${r.predicted_winner}</div>`;
  let html = '<div style="margin-top:8px"><strong>Metrics</strong><table class="table"><tr><th>Metric</th><th>Value</th></tr>';
  for (const k in r.metrics) {
    try { html += `<tr><td>${k}</td><td>${JSON.stringify(r.metrics[k])}</td></tr>`; } catch(e){}
  }
  html += '</table></div>';
  html += '<div style="margin-top:8px"><strong>True Prob</strong><pre>'+JSON.stringify(r.true_prob,null,2)+'</pre></div>';
  document.getElementById('detail').innerHTML = html;
}
</script>
</body>
</html>
