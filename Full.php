<?php
declare(strict_types=1);
/**
 * Last.php — PRE-MATCH MAX (Merged)
 * - Pre-Match MAX Engine added to Integrated VOID system
 * - Single-file deploy (PHP 7.4+). Uses SQLite (pdo_sqlite)
 * - Backup your existing Last.php and DB before deploying
 *
 * Features added:
 * - Real Odds Engine (pricing deconvolution)
 * - Bookmaker Deception Layer (BID)
 * - Outcome Probability Pulse (OPP)
 * - Pre-Match Integrity (PMI) and Early Drop Detector (EDM)
 * - Integrated into master_analyze as high-priority layer (Pre-Match MAX)
 */

// ---------------- ENV ----------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$BASEDIR = __DIR__;
$DB_FILE = $BASEDIR . '/last_prematch_max.db';

// ---------------- DB init ----------------
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS ewma_store (k TEXT PRIMARY KEY, v REAL, alpha REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT, sample REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS match_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, kickoff_ts INTEGER, league TEXT, payload TEXT, analysis TEXT, outcome TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pattern_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, signature TEXT, count INTEGER, win_rate REAL, last_seen INTEGER, meta TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pm_integrity (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, issues TEXT, score REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS autotune_log (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT, old REAL, new REAL, reason TEXT, ts INTEGER)");
} catch (Exception $e) {
    error_log("DB init error: " . $e->getMessage());
    $pdo = null;
}

// ---------------- CONFIG ----------------
$CONFIG = [
    'mode'=>'pre_match',
    'mpe_sim_count'=>800,
    'tpo_margin_est'=>0.06,
    'reboundSens_min'=>0.02,
    'mem_eq_shift_threshold'=>0.03,
    'autotune_enabled'=>true,
    'autotune_min_cases'=>30,
    'autotune_alpha_step'=>0.01,
    // VOID thresholds
    'void_divergence_threshold'=>0.08,
    'void_overload_threshold'=>0.55,
    'void_lock_layers_required'=>3,
    // PRE-MATCH MAX parameters
    'pm_max_overround_cut'=>0.12,
    'pm_early_drop_threshold'=>0.08,
    'pm_deception_score_cut'=>0.42,
    'pm_opp_resolution'=>1000,
];

// ---------------- Helpers ----------------
function safeFloat($v){ if(!isset($v)) return NAN; $s=trim((string)$v); if($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s)? floatval($s) : NAN; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }
function nf($v,$d=4){ return (is_nan($v) || $v===null) ? '-' : number_format((float)$v,$d,'.',''); }
function netflow($open,$now){ if(is_nan($open)||is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n=netflow($open,$now); return is_nan($n)?NAN:abs($n); }
function dir_label($open,$now){ if(is_nan($open)||is_nan($now)) return 'flat'; if($now<$open) return 'down'; if($now>$open) return 'up'; return 'flat'; }
function now_ts(){ return time(); }

// ---------------- EWMA simple ----------------
function ewma_get(string $k, float $fallback=0.0, float $default_alpha=0.08){
    global $pdo;
    if (!$pdo) return ['v'=>$fallback,'alpha'=>$default_alpha];
    $st = $pdo->prepare("SELECT v,alpha FROM ewma_store WHERE k=:k");
    $st->execute([':k'=>$k]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r===false) {
        $alpha = $default_alpha;
        $ins = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
        $ins->execute([':k'=>$k,':v'=>$fallback,':a'=>$alpha,':t'=>now_ts()]);
        return ['v'=>$fallback,'alpha'=>$alpha];
    }
    return ['v'=>floatval($r['v']),'alpha'=>floatval($r['alpha'])];
}
function ewma_update(string $k, float $sample){
    global $pdo;
    if (!$pdo) return null;
    $cur = ewma_get($k);
    $alpha = floatval($cur['alpha'] ?? 0.08);
    $newv = ($alpha * $sample) + ((1.0 - $alpha) * floatval($cur['v']));
    $st = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
    $st->execute([':k'=>$k,':v'=>$newv,':a'=>$alpha,':t'=>now_ts()]);
    $st2 = $pdo->prepare("INSERT INTO samples (k,sample,ts) VALUES(:k,:s,:t)");
    $st2->execute([':k'=>$k,':s'=>$sample,':t'=>now_ts()]);
    return ['k'=>$k,'v'=>$newv,'alpha'=>$alpha];
}

// ---------------- Basic Poisson ----------------
function poisson_rand($lambda){
    $L = exp(-$lambda); $k = 0; $p = 1.0;
    do { $k++; $p *= mt_rand()/mt_getrandmax(); } while ($p > $L);
    return $k - 1;
}

// ---------------- Existing helpers (TPO/MPE/PME...) ----------------
function tpo_estimate(array $now1){
    global $CONFIG;
    $imp=[]; $sum=0;
    foreach(['home','draw','away'] as $k){
        $o = safeFloat($now1[$k] ?? null);
        $p = (is_nan($o) || $o<=0) ? 0.0 : (1.0/$o);
        $imp[$k]=$p; $sum += $p;
    }
    if ($sum<=0) return null;
    foreach(['home','draw','away'] as $k) $imp[$k]/=$sum;
    $overround = $sum - 1.0;
    $est_margin = $overround>0 ? $overround : $CONFIG['tpo_margin_est'];
    $tpo=[]; $sumt=0;
    foreach(['home','draw','away'] as $k){
        $raw = $imp[$k] / (1.0 + $est_margin);
        $tpo[$k] = max(1e-6,$raw);
        $sumt += $tpo[$k];
    }
    foreach(['home','draw','away'] as $k) $tpo[$k] /= max(1e-9,$sumt);
    return ['tpo'=>$tpo,'overround'=>$overround];
}

function mpe_simulate(array $tpo, int $sim_count=800) {
    $ph = max(1e-6,$tpo['home']); $pa = max(1e-6,$tpo['away']);
    $strength = log($ph / $pa + 1e-9);
    $base = 1.15;
    $home_xg = max(0.15,$base + ($strength * 0.45));
    $away_xg = max(0.15,$base - ($strength * 0.45));
    $counts=['home'=>0,'draw'=>0,'away'=>0];
    $sim = max(100, min(3000, $sim_count));
    for ($i=0;$i<$sim;$i++){
        $h=poisson_rand($home_xg); $a=poisson_rand($away_xg);
        if ($h>$a) $counts['home']++; elseif ($a>$h) $counts['away']++; else $counts['draw']++;
    }
    return ['home'=>$counts['home']/$sim,'draw'=>$counts['draw']/$sim,'away'=>$counts['away']/$sim,'home_xg'=>$home_xg,'away_xg'=>$away_xg];
}

// ---------------- PM-DE disparity ----------------
function pm_disparity(array $open1, array $now1){
    $io=[];$in=[];$sumo=0;$sumn=0;
    foreach(['home','draw','away'] as $k){
        $o=safeFloat($open1[$k] ?? null); $n=safeFloat($now1[$k] ?? null);
        $io[$k]=(is_nan($o)||$o<=0)?0.0:1.0/$o; $sumo += $io[$k];
        $in[$k]=(is_nan($n)||$n<=0)?0.0:1.0/$n; $sumn += $in[$k];
    }
    if ($sumo>0) foreach(['home','draw','away'] as $k) $io[$k]/=$sumo;
    if ($sumn>0) foreach(['home','draw','away'] as $k) $in[$k]/=$sumn;
    $details=[]; $mag=0;
    foreach(['home','draw','away'] as $k){ $d = $in[$k]-$io[$k]; $details[$k]=$d; $mag += abs($d); }
    return ['disparity'=>$mag,'details'=>$details];
}

// ---------------- MME / UDS / SMK-X (core legacy) ----------------
// Reused earlier logic (kept concise)
function mme_analyze(array $ah_details, array $flow1, ?int $kickoff_ts=null){
    $juice=0.0; foreach ($ah_details as $ad){ $hj = (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) ? abs($ad['open_home']-$ad['now_home']) : 0.0; $aj = (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) ? abs($ad['open_away']-$ad['now_away']) : 0.0; $juice += $hj+$aj; }
    $stackHome=0;$stackAway=0;
    foreach ($ah_details as $ls) {
        $hRel = (!is_nan($ls['open_home']) && $ls['open_home']>0) ? (($ls['now_home']-$ls['open_home'])/$ls['open_home']) : 0;
        $aRel = (!is_nan($ls['open_away']) && $ls['open_away']>0) ? (($ls['now_away']-$ls['open_away'])/$ls['open_away']) : 0;
        if ($hRel<0||$aRel<0) $stackHome++; if ($hRel>0||$aRel>0) $stackAway++;
    }
    $stackFactor = count($ah_details) ? max($stackHome,$stackAway)/max(1,count($ah_details)) : 0;
    $hours = 48; if ($kickoff_ts) { $ttk = max(0,$kickoff_ts - time()); $hours = $ttk / 3600.0; }
    $late_weight = ($hours <= 2) ? 1.5 : (($hours <= 24) ? 1.1 : 0.9);
    $juiceNorm = clampf($juice * $late_weight, 0.0, 10.0);
    $isSharp = ($juiceNorm > 0.2 && $stackFactor > 0.4);
    $isTrap = ($juiceNorm > 0.2 && $stackFactor < 0.25);
    return ['juice'=>$juice,'juiceNorm'=>$juiceNorm,'stackFactor'=>$stackFactor,'isSharp'=>$isSharp,'isTrap'=>$isTrap,'hours_to_kick'=>$hours];
}

function uds_detect(array $ah_details, array $flow1){
    $totalAH=0.0; foreach($ah_details as $ad) $totalAH += (abs($ad['net_home'] ?? 0) + abs($ad['net_away'] ?? 0));
    $total1x2=0.0; foreach(['home','away'] as $s) $total1x2 += abs($flow1[$s] ?? 0);
    $divergence = abs($totalAH - $total1x2);
    $conflict=0;
    for ($i=0;$i<count($ah_details);$i++){
        for ($j=$i+1;$j<count($ah_details);$j++){
            $a=$ah_details[$i]; $b=$ah_details[$j];
            if (($a['dir_home'] ?? '') !== ($b['dir_home'] ?? '') || ($a['dir_away'] ?? '') !== ($b['dir_away'] ?? '')) $conflict++;
        }
    }
    $pattern = ($conflict>0) ? 'multi_price_conflict' : 'aligned';
    return ['divergence'=>$divergence,'conflict'=>$conflict,'pattern'=>$pattern];
}

function smkx_score(array $tpo, array $true_prob, array $mme, array $uds){
    $gap=0.0; foreach(['home','draw','away'] as $k) $gap += abs(($true_prob[$k] ?? 0) - ($tpo[$k] ?? 0));
    $gap = clampf($gap,0.0,1.0);
    $score=0.0; $score += clampf($gap,0,1)*0.35; $score += ($mme['isSharp']?0.3:0.0); $score += ($mme['isTrap']?0.05:0.0); $score += clampf($uds['divergence']/0.5,0.0,0.3);
    $score = clampf($score,0.0,1.0);
    $type = $score>=0.7 ? 'smart_money_killer' : ($score>=0.45 ? 'smart_money_possible' : 'no_smk');
    return ['score'=>$score,'type'=>$type,'components'=>['gap'=>$gap,'mme'=>$mme,'uds'=>$uds]];
}

function pce_project(array $market_prob, array $tpo, array $mme, array $uds){
    $proj = $market_prob;
    $moveFrac = $mme['isSharp'] ? 0.5 : 0.15; if ($mme['isTrap']) $moveFrac = -0.35;
    foreach(['home','draw','away'] as $k) $proj[$k] = clampf($market_prob[$k] + ($tpo[$k] - $market_prob[$k])*$moveFrac, 0.0001, 0.9999);
    $s = array_sum($proj); foreach(['home','draw','away'] as $k) $proj[$k] /= max(1e-9,$s);
    return $proj;
}

// ---------------- MEM / CROSS-SHADOW / EMOTIONAL / INSIGHT ----------------
// (Kept similar to earlier implementations)
function mem_compute(array $tpo, array $market_prob){
    global $pdo, $CONFIG;
    $hist_home = ewma_get('master_tpo_home', $tpo['home'])['v'];
    $hist_draw = ewma_get('master_tpo_draw', $tpo['draw'])['v'];
    $hist_away = ewma_get('master_tpo_away', $tpo['away'])['v'];
    $equilibrium = [
        'home'=>clampf(0.6*$hist_home + 0.4*$tpo['home'],1e-6,0.9999),
        'draw'=>clampf(0.6*$hist_draw + 0.4*$tpo['draw'],1e-6,0.9999),
        'away'=>clampf(0.6*$hist_away + 0.4*$tpo['away'],1e-6,0.9999)
    ];
    $s = array_sum($equilibrium); foreach(['home','draw','away'] as $k) $equilibrium[$k]/=max(1e-9,$s);
    $shift=[]; $maxShift=0;
    foreach(['home','draw','away'] as $k){ $shift[$k] = ($market_prob[$k] ?? 0) - ($equilibrium[$k] ?? 0); if (abs($shift[$k]) > abs($maxShift)) $maxShift=$shift[$k]; }
    $label = abs($maxShift) > $CONFIG['mem_eq_shift_threshold'] ? 'shifted' : 'aligned';
    if ($pdo) {
        $mk = md5(json_encode($market_prob).microtime(true));
        $st = $pdo->prepare("INSERT INTO pm_integrity (match_key,issues,score,ts) VALUES(:mk,:iss,:sc,:ts)");
        $st->execute([':mk'=>$mk,':iss'=>json_encode(['shift'=>$shift]),':sc'=>abs($maxShift),':ts'=>now_ts()]);
    }
    return ['equilibrium'=>$equilibrium,'shift'=>$shift,'maxShift'=>$maxShift,'label'=>$label];
}

function crossmarket_shadow(array $now1, array $ah_details){
    $overround = 0.0; $sum=0;
    foreach(['home','draw','away'] as $k){ $o=safeFloat($now1[$k] ?? null); if (!is_nan($o) && $o>0) { $sum += 1.0/$o; } }
    $overround = $sum - 1.0;
    $ah_count = count($ah_details);
    $aligned = 0;
    foreach ($ah_details as $ad) { if (($ad['dir_home'] ?? '') === 'down' || ($ad['dir_away'] ?? '') === 'up') $aligned++; }
    $leader_score = clampf((($ah_count>0 ? ($aligned/$ah_count) : 0) * 0.6) + (max(0,0.06 - $overround) * 4.0), 0.0, 1.0);
    $role = $leader_score > 0.55 ? 'leader' : 'follower';
    return ['leader_score'=>$leader_score,'role'=>$role,'overround'=>$overround,'aligned_lines'=>$aligned,'total_lines'=>$ah_count];
}

function emotional_sim(array $open1, array $now1, array $ah_details){
    $nf_home = abs(netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)));
    $nf_away = abs(netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null)));
    $ah_moves = 0; foreach ($ah_details as $ad) { if (!is_nan($ad['mom_home'] ?? NAN) && ($ad['mom_home'] ?? 0) > 0.03) $ah_moves++; if (!is_nan($ad['mom_away'] ?? NAN) && ($ad['mom_away'] ?? 0) > 0.03) $ah_moves++; }
    $panic = ($nf_home + $nf_away) > 0.25 && $ah_moves > 1;
    $herd = ($nf_home + $nf_away) > 0.08 && $ah_moves >= 1 && !($panic);
    $smoke = $ah_moves > 0 && ($nf_home + $nf_away) < 0.02;
    return ['panic'=>$panic,'herd'=>$herd,'smoke'=>$smoke,'nf_home'=>$nf_home,'nf_away'=>$nf_away,'ah_moves'=>$ah_moves];
}

function insight_predict(array $features){
    global $pdo;
    $sig = md5(json_encode($features));
    if ($pdo) {
        $st = $pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:sig");
        $st->execute([':sig'=>$sig]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return ['from_pattern'=>true,'win_rate'=>floatval($r['win_rate']),'count'=>intval($r['count']),'signature'=>$sig];
        }
    }
    $score = 0.0;
    if (!empty($features['smkx_score'])) $score += $features['smkx_score'] * 0.6;
    if (!empty($features['mem_shift'])) $score += clampf(abs($features['mem_shift']) * 1.2, 0.0, 0.3);
    if (!empty($features['emotional_panic'])) $score -= 0.2;
    $wr = clampf(0.5 + ($score*0.5), 0.0, 1.0);
    return ['from_pattern'=>false,'win_rate'=>$wr,'count'=>0,'signature'=>$sig];
}

// ---------------- PM-MAX: Real Odds Engine (Pricing Deconvolution) ----------------
/**
 * Remove visible & estimated hidden margin, adjust for rounding bias and regional skew.
 * Uses adaptive historical baseline (EWMA) to nudge result toward long-term realities.
 */
function prematch_real_odds_decon(array $now1){
    global $pdo, $CONFIG;
    // market implied
    $market=[]; $sum=0;
    foreach(['home','draw','away'] as $k){ $o=safeFloat($now1[$k] ?? null); $market[$k] = (is_nan($o)||$o<=0)?0.0:(1.0/$o); $sum += $market[$k]; }
    if ($sum<=0) return ['true_prob'=>['home'=>0.33,'draw'=>0.34,'away'=>0.33],'overround'=>0.0,'blend'=>[]];
    foreach(['home','draw','away'] as $k) $market[$k]/=$sum;
    $overround = $sum - 1.0;
    // estimate hidden spread (heuristic): relate overround to hidden margin
    $hidden = clampf($overround * 0.6, 0.0, $CONFIG['pm_max_overround_cut']);
    // hist baseline
    $hist_home = ewma_get('pm_hist_home',$market['home'])['v'];
    $hist_draw = ewma_get('pm_hist_draw',$market['draw'])['v'];
    $hist_away = ewma_get('pm_hist_away',$market['away'])['v'];
    // weights
    $market_weight = clampf(1.0 - $hidden, 0.35, 0.95);
    $hist_weight = 1.0 - $market_weight;
    $true = [
        'home' => clampf($market_weight*$market['home'] + $hist_weight*$hist_home, 1e-6, 0.9999),
        'draw' => clampf($market_weight*$market['draw'] + $hist_weight*$hist_draw, 1e-6, 0.9999),
        'away' => clampf($market_weight*$market['away'] + $hist_weight*$hist_away, 1e-6, 0.9999),
    ];
    $s = array_sum($true); foreach (['home','draw','away'] as $k) $true[$k]/=max(1e-9,$s);
    // persist slight learning
    ewma_update('pm_hist_home', $true['home']);
    ewma_update('pm_hist_draw', $true['draw']);
    ewma_update('pm_hist_away', $true['away']);
    return ['true_prob'=>$true,'overround'=>$overround,'hidden'=>$hidden,'blend'=>['market_weight'=>$market_weight,'hist_weight'=>$hist_weight]];
}

// ---------------- PM-MAX: Bookmaker Deception Detector (BID) ----------------
/**
 * Score deception by checking:
 * - AH heavy moves with small 1x2 move
 * - multiple AH lines conflicting
 * - rapid swings around opening (early drop)
 * - asymmetry between implied and historic true
 */
function bookmaker_deception_detector(array $open1, array $now1, array $ah_details){
    global $CONFIG;
    $score = 0.0; $reasons=[];
    // AH vs 1x2 mismatch
    $ah_move=0; foreach($ah_details as $ad){ $ah_move += (abs(($ad['open_home'] ?? 0)-($ad['now_home'] ?? 0)) + abs(($ad['open_away'] ?? 0)-($ad['now_away'] ?? 0))); }
    $x2_move = abs(netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null))) + abs(netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null)));
    if ($ah_move > 0.02 && $x2_move < 0.02) { $score += 0.28; $reasons[]='AH-heavy_x2-stable'; }
    // multi-line conflict
    $conflict=0; for($i=0;$i<count($ah_details);$i++){ for($j=$i+1;$j<count($ah_details);$j++){ $a=$ah_details[$i]; $b=$ah_details[$j]; if (($a['dir_home'] ?? '') !== ($b['dir_home'] ?? '')) $conflict++; } }
    if ($conflict>0){ $score += clampf($conflict*0.06,0,0.2); $reasons[]='multi-line-conflict'; }
    // early drop: large change from open quickly
    $early_drop = 0.0;
    foreach(['home','away'] as $s){ $o=safeFloat($open1[$s] ?? null); $n=safeFloat($now1[$s] ?? null); if(!is_nan($o)&&!is_nan($n)&&$o>0){ $rel = abs(($n-$o)/$o); if ($rel > $CONFIG['pm_early_drop_threshold']) $early_drop += $rel; } }
    if ($early_drop > 0){ $score += clampf($early_drop,0,0.25); $reasons[]='early_drop'; }
    // asymmetry vs hist
    $hist_home = ewma_get('pm_hist_home',0.33)['v']; $hist_away = ewma_get('pm_hist_away',0.33)['v'];
    $cur_home = (is_nan(safeFloat($now1['home'] ?? null))?0.0:(1.0/safeFloat($now1['home'])));
    $cur_away = (is_nan(safeFloat($now1['away'] ?? null))?0.0:(1.0/safeFloat($now1['away'])));
    $asym = abs(($cur_home - $hist_home)) + abs(($cur_away - $hist_away));
    if ($asym > 0.05){ $score += clampf($asym,0,0.15); $reasons[]='hist_asym'; }
    $score = clampf($score, 0.0, 1.0);
    $type = $score >= $CONFIG['pm_deception_score_cut'] ? 'deceptive' : ($score >= 0.25 ? 'suspect' : 'clean');
    return ['score'=>$score,'type'=>$type,'reasons'=>$reasons,'details'=>['ah_move'=>$ah_move,'x2_move'=>$x2_move,'conflict'=>$conflict,'early_drop'=>$early_drop,'asym'=>$asym]];
}

// ---------------- PM-MAX: Outcome Probability Pulse (OPP) ----------------
/**
 * High-resolution Poisson ensemble to produce stable probability pulse.
 * Uses many simulations and returns distribution shape & entropy.
 */
function outcome_probability_pulse(array $true_prob, int $resolution=1000){
    // Map true_prob to xG seeds (heuristic)
    $base = 1.12;
    $home_seed = max(0.08, $base + ($true_prob['home'] - $true_prob['away'])*1.1);
    $away_seed = max(0.08, $base + ($true_prob['away'] - $true_prob['home'])*1.1);
    $counts=['home'=>0,'draw'=>0,'away'=>0];
    $sim = max(300, min(3000, $resolution));
    for ($i=0;$i<$sim;$i++){
        $h = poisson_rand($home_seed); $a = poisson_rand($away_seed);
        if ($h>$a) $counts['home']++; elseif ($a>$h) $counts['away']++; else $counts['draw']++;
    }
    $dist = ['home'=>$counts['home']/$sim,'draw'=>$counts['draw']/$sim,'away'=>$counts['away']/$sim];
    // entropy (lower entropy = clearer)
    $ent = 0.0;
    foreach($dist as $p) if ($p>0) $ent -= $p * log($p);
    return ['dist'=>$dist,'entropy'=>$ent,'home_seed'=>$home_seed,'away_seed'=>$away_seed,'sim'=>$sim];
}

// ---------------- PM-MAX: Pre-Match Integrity (PMI) ----------------
function prematch_integrity_check(array $open1, array $now1, array $ah_details, array $bid, array $opp){
    global $pdo;
    $issues=[]; $score = 1.0;
    // if deception high reduce integrity
    if ($bid['score'] > 0.45) { $issues[]='deception_high'; $score -= 0.35; }
    // if opp entropy high -> reduce integrity
    if ($opp['entropy'] > 1.05) { $issues[]='opp_high_entropy'; $score -= 0.25; }
    // if early drop flagged
    if ($bid['details']['early_drop'] > 0) { $issues[]='early_drop'; $score -= 0.2; }
    $score = clampf($score, 0.0, 1.0);
    if ($pdo) {
        $mk = md5(json_encode($open1).json_encode($now1).microtime(true));
        $st = $pdo->prepare("INSERT INTO pm_integrity (match_key,issues,score,ts) VALUES(:mk,:iss,:sc,:ts)");
        $st->execute([':mk'=>$mk,':iss'=>json_encode($issues),':sc'=>$score,':ts'=>now_ts()]);
    }
    return ['score'=>$score,'issues'=>$issues];
}

// ---------------- PM-MAX: Early Drop Manipulation Detector (EDM) ----------------
function early_drop_detector(array $open1, array $now1){
    global $CONFIG;
    $maxRel = 0.0;
    foreach(['home','away','draw'] as $s){
        $o = safeFloat($open1[$s] ?? null); $n = safeFloat($now1[$s] ?? null);
        if (!is_nan($o) && !is_nan($n) && $o>0) { $rel = abs(($n - $o)/$o); if ($rel > $maxRel) $maxRel = $rel; }
    }
    $flag = $maxRel >= $CONFIG['pm_early_drop_threshold'];
    return ['maxRel'=>$maxRel,'flag'=>$flag];
}

// ---------------- PM-MAX Integration Function ----------------
function prematch_max_analyze(array $open1, array $now1, array $ah_details){
    // 1) real odds deconvolution
    $decon = prematch_real_odds_decon($now1);
    // 2) bookmaker deception detector
    $bid = bookmaker_deception_detector($open1,$now1,$ah_details);
    // 3) outcome pulse
    $opp = outcome_probability_pulse($decon['true_prob'], intval($GLOBALS['CONFIG']['pm_opp_resolution'] ?? 800));
    // 4) early drop detect
    $edm = early_drop_detector($open1,$now1);
    // 5) integrity
    $pmi = prematch_integrity_check($open1,$now1,$ah_details,$bid,$opp);
    // final prematch score & verdict
    $prematch_score = ($decon['hidden'] * 0.4) + ($bid['score'] * 0.45) + ((1.0 - $pmi['score']) * 0.15);
    $prematch_score = clampf($prematch_score,0.0,1.0);
    $verdict = 'clean';
    if ($prematch_score >= 0.6) $verdict = 'danger';
    elseif ($prematch_score >= 0.35) $verdict = 'suspicious';
    return ['decon'=>$decon,'bid'=>$bid,'opp'=>$opp,'edm'=>$edm,'pmi'=>$pmi,'score'=>$prematch_score,'verdict'=>$verdict];
}

// ---------------- PATTERN MEMORY (learn) ----------------
function pm_learn($signature, bool $win, array $meta=[]){
    global $pdo;
    if (!$pdo) return false;
    $sig = md5(json_encode($signature));
    $st = $pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:s");
    $st->execute([':s'=>$sig]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $now = now_ts();
    if ($r) {
        $count = intval($r['count']) + 1;
        $prev = floatval($r['win_rate']);
        $new = (($prev * intval($r['count'])) + ($win?1.0:0.0)) / max(1,$count);
        $upd = $pdo->prepare("UPDATE pattern_memory SET count=:c,win_rate=:w,last_seen=:ls,meta=:m WHERE id=:id");
        $upd->execute([':c'=>$count,':w'=>$new,':ls'=>$now,':m'=>json_encode($meta),':id'=>$r['id']]);
    } else {
        $ins = $pdo->prepare("INSERT INTO pattern_memory (signature,count,win_rate,last_seen,meta) VALUES(:sig,1,:wr,:ls,:m)");
        $ins->execute([':sig'=>$sig,':wr'=>($win?1.0:0.0),':ls'=>$now,':m'=>json_encode($meta)]);
    }
    return true;
}

// ---------------- AUTOTUNE (light) ----------------
function autotune_check_and_apply(){
    global $pdo, $CONFIG;
    if (!$pdo || !$CONFIG['autotune_enabled']) return ['applied'=>false,'reason'=>'no_db_or_disabled'];
    $st = $pdo->query("SELECT COUNT(*) as c FROM match_cases WHERE outcome != ''");
    $c = intval($st->fetchColumn());
    if ($c < $CONFIG['autotune_min_cases']) return ['applied'=>false,'reason'=>'not_enough_cases','cases'=>$c];
    // quick heuristic: adjust master alpha for pm_hist if patterns show drift
    $cur = ewma_get('pm_hist_home',0.33); $oldAlpha = $cur['alpha'];
    $newAlpha = $oldAlpha;
    // compute avg win_rate top patterns
    $st2 = $pdo->query("SELECT win_rate FROM pattern_memory ORDER BY count DESC LIMIT 30");
    $rows = $st2->fetchAll(PDO::FETCH_COLUMN);
    if ($rows) {
        $avgWR = array_sum($rows)/count($rows);
        if ($avgWR > 0.56) $newAlpha = clampf($oldAlpha + $CONFIG['autotune_alpha_step'], 0.01, 0.3);
        elseif ($avgWR < 0.48) $newAlpha = clampf($oldAlpha - $CONFIG['autotune_alpha_step'], 0.01, 0.3);
        $st3 = $pdo->prepare("UPDATE ewma_store SET alpha=:a, updated_at=:t WHERE k=:k");
        $st3->execute([':a'=>$newAlpha,':t'=>now_ts(),':k'=>'pm_hist_home']);
        $st4 = $pdo->prepare("INSERT INTO autotune_log (param,old,new,reason,ts) VALUES(:p,:o,:n,:r,:t)");
        $st4->execute([':p'=>'pm_hist_home',':o'=>$oldAlpha,':n'=>$newAlpha,':r'=>'autotune_avgWR_'.$avgWR,':t'=>now_ts()]);
        return ['applied'=>true,'param'=>'pm_hist_home','old'=>$oldAlpha,'new'=>$newAlpha,'avgWR'=>$avgWR];
    }
    return ['applied'=>false,'reason'=>'no_patterns'];
}

// ---------------- MASTER ANALYZE (Main integrated with Pre-Match MAX) ----------------
function master_analyze(array $payload){
    global $pdo, $CONFIG;
    $home = $payload['home'] ?? 'เหย้า'; $away = $payload['away'] ?? 'เยือน';
    $league = $payload['league'] ?? 'generic';
    $kickoff = isset($payload['kickoff']) ? intval(strtotime($payload['kickoff'])) : ($payload['kickoff_ts'] ?? null);
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];

    // AH details & pairs
    $pairs=[]; $ah_details=[]; $totalAH_mom=0.0;
    foreach ($ah_list as $i=>$r){
        $line = $r['line'] ?? ('AH'.($i+1));
        $oh = safeFloat($r['open_home'] ?? null); $oa = safeFloat($r['open_away'] ?? null);
        $nh = safeFloat($r['now_home'] ?? null); $na = safeFloat($r['now_away'] ?? null);
        $mh = is_nan($oh)||is_nan($nh)?NAN:abs($oh-$nh);
        $ma = is_nan($oa)||is_nan($na)?NAN:abs($oa-$na);
        if(!is_nan($mh)) $totalAH_mom += $mh; if(!is_nan($ma)) $totalAH_mom += $ma;
        $ad = ['index'=>$i,'line'=>$line,'open_home'=>$oh,'open_away'=>$oa,'now_home'=>$nh,'now_away'=>$na,'net_home'=>is_nan($oh)||is_nan($nh)?0.0:$oh-$nh,'net_away'=>is_nan($oa)||is_nan($na)?0.0:$oa-$na,'mom_home'=>$mh,'mom_away'=>$ma,'dir_home'=>dir_label($oh,$nh),'dir_away'=>dir_label($oa,$na)];
        $ah_details[]=$ad; $pairs[]=['open'=>$oh,'now'=>$nh]; $pairs[]=['open'=>$oa,'now'=>$na];
    }

    // 1x2 flows and mom
    $flow1 = ['home'=> netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> netflow(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $mom1 = ['home'=> mom_abs(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> mom_abs(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> mom_abs(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $total1x2_mom = 0.0; foreach ($mom1 as $v) if(!is_nan($v)) $total1x2_mom += $v;
    $marketMomentum = $total1x2_mom + $totalAH_mom;

    // rebound
    $reboundSens = 0.025;
    foreach ($pairs as $p){ if(isset($p['open']) && isset($p['now']) && $p['open']>0) $reboundSens += abs(($p['now']-$p['open'])/$p['open']) * 0.005; }
    $reboundSens = clampf($reboundSens, $CONFIG['reboundSens_min'], 0.12);

    // primary TPO & MPE
    $tpoRes = tpo_estimate($now1); $tpo = $tpoRes['tpo']; $overround = $tpoRes['overround'];
    $mpe = mpe_simulate($tpo, intval($CONFIG['mpe_sim_count']));

    // legacy modules
    $mme = mme_analyze($ah_details, $flow1, $kickoff);
    $uds = uds_detect($ah_details,$flow1);

    // PM-MAX: run Real Odds Decon & BID & OPP & Integrity
    $pmmax = prematch_max_analyze($open1,$now1,$ah_details);

    // use PM-MAX true_prob as authoritative pre-match true distribution (merged with mem)
    $true_prob = $pmmax['decon']['true_prob'];
    $market_prob_norm = $pmmax['decon']['true_prob']; // keep normalized reference
    // ensure sum 1
    $sump = array_sum($true_prob); if ($sump>0) foreach(['home','draw','away'] as $k) $true_prob[$k] /= $sump;

    // smkx (legacy)
    $smkx = smkx_score($tpo,$true_prob,$mme,$uds);

    // ocm (placeholder simple)
    $ocm_strength = clampf((($pmmax['score']*100) + ($smkx['score']*40) + ($mme['juiceNorm']*10))/3, 0, 100);
    $ocm = ['strength'=>$ocm_strength];

    // ckp
    $ckp = ['kill_alert'=>false,'reason'=>[],'direction'=>null,'severity'=>0.0];
    if ($pmmax['verdict'] === 'danger' || $pmmax['bid']['type']==='deceptive') {
        $ckp['kill_alert'] = true; $ckp['reason'][] = 'pmmax_deceptive'; $ckp['severity'] = clampf($pmmax['score'],0,1);
    }

    // VOID integration (existing logic simplified)
    $flow = ['avgRel'=>0,'isSharp'=>($mme['isSharp']),'fake'=>($mme['isTrap'])];
    $div = 0.0; foreach(['home','draw','away'] as $k) $div += abs((($market_prob_norm[$k] ?? 0) - ($true_prob[$k] ?? 0)));
    $trap = ['level'=>0,'reasons'=>[]];
    if ($pmmax['bid']['score'] > 0.4) { $trap['level'] += 2; $trap['reasons'][]='pm_deception'; }
    if ($mme['isTrap']) { $trap['level'] += 1; $trap['reasons'][]='mme_trap'; }
    $void_strength = clampf(($pmmax['score']*0.45) + ($smkx['score']*0.25) + (abs($div)/0.5 * 0.3), 0,1);
    $void_layers_agree = 0;
    if ($div > $CONFIG['void_divergence_threshold']) $void_layers_agree++;
    if ($flow['isSharp']) $void_layers_agree++;
    if ($smkx['score'] >= 0.65) $void_layers_agree++;
    if ($trap['level'] >= 2) $void_layers_agree++;
    if ($pmmax['decon']['hidden'] > 0.05) $void_layers_agree++;

    $void_mode = 'normal';
    if ($void_layers_agree >= $CONFIG['void_lock_layers_required']) $void_mode = 'lock';
    if ($void_layers_agree >= 5 && $void_strength > $CONFIG['void_overload_threshold']) $void_mode = 'overload';

    // final verdict logic (Pre-Match MAX prioritized)
    $predicted = 'ไม่แน่ใจ';
    if ($true_prob['home'] > $true_prob['away'] && $true_prob['home'] > $true_prob['draw']) $predicted = $home;
    if ($true_prob['away'] > $true_prob['home'] && $true_prob['away'] > $true_prob['draw']) $predicted = $away;

    $final_label = '⚠️ ไม่ชัดเจน'; $recommendation = 'รอ confirm';
    if ($void_mode === 'overload') {
        $final_label = '🔥 VOID OVERLOAD — MARKET BREAK'; $recommendation = 'หลีกเลี่ยงหรือเดิมพันเฉพาะผู้เชี่ยวชาญ';
    } elseif ($void_mode === 'lock') {
        $final_label = '💀 VOID LOCK — HIGH CONFIDENCE'; $recommendation = 'พิจารณาตาม — จำกัดขนาด';
    } elseif ($ckp['kill_alert']) {
        $final_label = '❌ PM Integrity FAIL — Pre-Match Trap'; $recommendation = 'หลีกเลี่ยง';
    } elseif ($pmmax['verdict']==='suspicious') {
        $final_label = '⚡ Pre-Match Suspicious — ระวังกับดัก'; $recommendation = 'Watch / small stake only';
    } elseif ($pmmax['verdict']==='clean') {
        $final_label = '✅ Pre-Match Clean — สัญญาณปกติ'; $recommendation = 'พิจารณาตามความเสี่ยง';
    }

    $confidence = round(clampf(40 + ($void_strength*40) + ($ocm['strength']/20) + ( (1 - $pmmax['opp']['entropy']) * 20 ), 0, 100),1);

    // persist
    ewma_update('master_voidscore', $void_strength);
    ewma_update('pm_last_score', $pmmax['score']);

    if ($pdo) {
        $mk = md5($home.'|'.$away.'|'.($kickoff?:time()).microtime(true));
        $ins = $pdo->prepare("INSERT INTO match_cases (match_key,kickoff_ts,league,payload,analysis,outcome,ts) VALUES(:mk,:ks,:league,:p,:a,:o,:t)");
        $ins->execute([':mk'=>$mk,':ks'=>($kickoff?:0),':league'=>$league,':p'=>json_encode($payload,JSON_UNESCAPED_UNICODE),':a'=>json_encode(['final_label'=>$final_label,'true_prob'=>$true_prob,'pmmax'=>$pmmax,'smkx'=>$smkx,'mem'=>mem_compute($tpo,$market_prob_norm),'ocm'=>$ocm,'ckp'=>$ckp,'void'=>['mode'=>$void_mode,'strength'=>$void_strength,'layers'=>$void_layers_agree,'div'=>$div,'trap'=>$trap]],JSON_UNESCAPED_UNICODE),':o'=>'',':t'=>now_ts()]);
    }

    // optionally autotune
    $autotune_res = [];
    if ($CONFIG['autotune_enabled']) { $autotune_res = autotune_check_and_apply(); }

    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'league'=>$league,'kickoff_ts'=>$kickoff,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list],
        'metrics'=>[
            'marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,'reboundSens'=>$reboundSens,
            'pmmax'=>$pmmax,'smkx'=>$smkx,'mem'=>mem_compute($tpo,$market_prob_norm),'ocm'=>$ocm,'ckp'=>$ckp,'void'=>['mode'=>$void_mode,'strength'=>$void_strength,'layers'=>$void_layers_agree,'div'=>$div,'trap'=>$trap]
        ],
        'final_label'=>$final_label,'recommendation'=>$recommendation,'predicted_winner'=>$predicted,'true_prob'=>$true_prob,'confidence'=>$confidence,'autotune'=>$autotune_res
    ];
}

// ---------------- HTTP endpoints ----------------
if (php_sapi_name() !== 'cli') {
    if (isset($_GET['action']) && $_GET['action']==='master_analyze' && $_SERVER['REQUEST_METHOD']==='POST') {
        $raw = file_get_contents('php://input'); $payload = json_decode($raw,true);
        if (!is_array($payload)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>'invalid_payload','raw'=>substr($raw,0,200)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
        $res = master_analyze($payload);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
    if (isset($_GET['action']) && $_GET['action']==='ewma_stats') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$pdo) { echo json_encode(['status'=>'error','msg'=>'no_db']); exit; }
        $st = $pdo->query("SELECT * FROM ewma_store");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','ewma'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
    if (isset($_GET['action']) && $_GET['action']==='match_cases') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$pdo) { echo json_encode(['status'=>'error','msg'=>'no_db']); exit; }
        $st = $pdo->query("SELECT id,match_key,kickoff_ts,league,ts FROM match_cases ORDER BY id DESC LIMIT 200");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','cases'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
}

// ---------------- Minimal UI for quick test ----------------
?><!doctype html>
<html lang="th">
<head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>PRE-MATCH MAX</title>
<style>body{background:#07030a;color:#fff;font-family:Inter, 'Noto Sans Thai';padding:18px} .card{background:linear-gradient(180deg,#0d0506,#241116);padding:12px;border-radius:12px;margin-bottom:12px} .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px} input{padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.05);background:rgba(255,255,255,0.02);color:#fff;width:100%} .btn{padding:10px 12px;border-radius:8px;background:linear-gradient(90deg,#7e2aa3,#d4a017);border:none;cursor:pointer} pre{white-space:pre-wrap}</style>
</head><body>
<div class="card"><h2 style="margin:0">PRE-MATCH MAX — Ready</h2><div class="small">ระบบPre Match Analyzer</div></div>
<div class="card">
  <div class="grid"><div><label>Home</label><input id="home" placeholder="Team Home"></div><div><label>Away</label><input id="away" placeholder="Team Away"></div><div><label>League</label><input id="league" placeholder="EPL"></div></div>
  <div style="height:8px"></div>
  <div class="card">
    <label>Kickoff (YYYY-MM-DD HH:MM)</label><input id="kickoff">
    <div style="height:8px"></div>
    <div class="small">1X2 Open</div><div class="grid"><input id="open_home" placeholder="2.10"><input id="open_draw" placeholder="3.40"><input id="open_away" placeholder="3.10"></div>
    <div style="height:8px"></div>
    <div class="small">1X2 Now</div><div class="grid"><input id="now_home" placeholder="1.95"><input id="now_draw" placeholder="3.60"><input id="now_away" placeholder="3.80"></div>
  </div>
  <div style="height:8px"></div>
  <div style="text-align:right"><button id="analyzeBtn" class="btn">🔎 Analyze</button></div>
</div>
<div id="result" class="card" style="display:none"></div>
<script>
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
document.getElementById('analyzeBtn').addEventListener('click', async ()=>{
  const payload = {
    home: document.getElementById('home').value||'Home',
    away: document.getElementById('away').value||'Away',
    league: document.getElementById('league').value||'generic',
    kickoff: document.getElementById('kickoff').value||'',
    open1: {home: toNum(document.getElementById('open_home').value), draw: toNum(document.getElementById('open_draw')?.value), away: toNum(document.getElementById('open_away')?.value)},
    now1: {home: toNum(document.getElementById('now_home').value), draw: toNum(document.getElementById('now_draw')?.value), away: toNum(document.getElementById('now_away')?.value)},
    ah: [],
    mode: 'pre_match'
  };
  document.getElementById('result').style.display='block';
  document.getElementById('result').innerText='Analyzing...';
  try {
    const res = await fetch('?action=master_analyze', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j = await res.json();
    render(j);
  } catch (e) { document.getElementById('result').innerText = 'Error: '+e.message; }
});
function render(r){
  let html = `<div style="font-weight:900;color:#d4a017">${r.final_label}</div><div style="color:#d9c89a">Recommendation: ${r.recommendation}</div><div style="margin-top:6px">Predicted: ${r.predicted_winner} — Confidence: ${r.confidence}%</div><hr>`;
  html += `<div style="display:flex;gap:12px;flex-wrap:wrap"><div style="flex:1;min-width:300px"><strong>True Prob</strong><pre>${JSON.stringify(r.true_prob, null, 2)}</pre></div><div style="flex:1;min-width:300px"><strong>PM-MAX Summary</strong><pre>${JSON.stringify(r.metrics.pmmax, null, 2)}</pre></div></div>`;
  html += `<div style="margin-top:8px"><strong>Metrics</strong><pre>${JSON.stringify(r.metrics, null, 2)}</pre></div>`;
  document.getElementById('result').innerHTML = html;
}
</script>
</body>
</html>
