<?php
declare(strict_types=1);
/**
 * Last.php — Integrated VOID Engine (Mode B)
 * - Integrated: VOID becomes the primary decision layer (merged into master_analyze)
 * - Preserves legacy modules and stores, uses SQLite (pdo_sqlite)
 * - PHP 7.4+ recommended
 *
 * Notes:
 * - Backup old files/db before deploying.
 * - pdo_sqlite extension required.
 */

// ---------------- ENV ----------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$BASEDIR = __DIR__;
$DB_FILE = $BASEDIR . '/void_integrated.db';

// ---------------- DB init ----------------
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // core stores
    $pdo->exec("CREATE TABLE IF NOT EXISTS ewma_store (k TEXT PRIMARY KEY, v REAL, alpha REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT, sample REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS match_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, kickoff_ts INTEGER, league TEXT, payload TEXT, analysis TEXT, outcome TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pattern_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, signature TEXT, count INTEGER, win_rate REAL, last_seen INTEGER, meta TEXT)");
    // PM & VOID stores
    $pdo->exec("CREATE TABLE IF NOT EXISTS disparity_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, open_json TEXT, now_json TEXT, disparity REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS smart_moves (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, type TEXT, score REAL, details TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS equilibrium_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, tpo_json TEXT, market_json TEXT, equilibrium_json TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS collapse_events (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, collapse_winner TEXT, strength REAL, meta TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, reason TEXT, direction TEXT, severity REAL, meta TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_outcome (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, outcome TEXT, ts INTEGER)");
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
    'reboundSens_min'=>0.03,
    'mem_eq_shift_threshold'=>0.03,
    'ocm_strength_scale'=>100,
    'ckp_severity_min'=>0.35,
    'autotune_enabled'=>true,
    'autotune_min_cases'=>30,
    'autotune_alpha_step'=>0.01,
    // VOID thresholds
    'void_divergence_threshold'=>0.08,
    'void_overload_threshold'=>0.55,
    'void_lock_layers_required'=>3,
];

// ---------------- Helpers ----------------
function safeFloat($v){ if(!isset($v)) return NAN; $s=trim((string)$v); if($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s)? floatval($s) : NAN; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }
function nf($v,$d=4){ return (is_nan($v) || $v===null) ? '-' : number_format((float)$v,$d,'.',''); }
function netflow($open,$now){ if(is_nan($open)||is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n=netflow($open,$now); return is_nan($n)?NAN:abs($n); }
function dir_label($open,$now){ if(is_nan($open)||is_nan($now)) return 'flat'; if($now<$open) return 'down'; if($now>$open) return 'up'; return 'flat'; }
function now_ts(){ return time(); }

// ---------------- EWMA ----------------
function ewma_get(string $k, float $fallback=0.0, float $default_alpha=0.08){
    global $pdo, $CONFIG;
    if (!$pdo) return ['v'=>$fallback,'alpha'=>$default_alpha];
    $st = $pdo->prepare("SELECT v,alpha FROM ewma_store WHERE k=:k");
    $st->execute([':k'=>$k]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r===false) {
        $alpha = $default_alpha;
        if (isset($CONFIG['ewma_alpha'][$k])) $alpha = floatval($CONFIG['ewma_alpha'][$k]);
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

// ---------------- Basic utils ----------------
function poisson_rand($lambda){ $L = exp(-$lambda); $k = 0; $p = 1.0; do { $k++; $p *= mt_rand()/mt_getrandmax(); } while ($p > $L); return $k - 1; }

// ---------------- TPO / MPE / PM-DE / MME / UDS / SMK-X / PCE etc. ----------------
// (These functions are similar to the previously provided engine functions.)
// For brevity and correctness we include essential versions used by the integrated VOID Core.

function tpo_estimate(array $now1, array $open1=[]){
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
    return $tpo;
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

// ---------------- MEM ----------------
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
        $st = $pdo->prepare("INSERT INTO equilibrium_stats (match_key,tpo_json,market_json,equilibrium_json,ts) VALUES(:mk,:tpo,:mkt,:eq,:ts)");
        $st->execute([':mk'=>$mk,':tpo'=>json_encode($tpo),':mkt'=>json_encode($market_prob),':eq'=>json_encode($equilibrium),':ts'=>now_ts()]);
    }
    return ['equilibrium'=>$equilibrium,'shift'=>$shift,'maxShift'=>$maxShift,'label'=>$label];
}

// ---------------- CROSS-MARKET SHADOW (heuristic) ----------------
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

// ---------------- EMOTIONAL SIM ----------------
function emotional_sim(array $open1, array $now1, array $ah_details){
    $nf_home = abs(netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)));
    $nf_away = abs(netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null)));
    $ah_moves = 0; foreach ($ah_details as $ad) { if (!is_nan($ad['mom_home'] ?? NAN) && ($ad['mom_home'] ?? 0) > 0.03) $ah_moves++; if (!is_nan($ad['mom_away'] ?? NAN) && ($ad['mom_away'] ?? 0) > 0.03) $ah_moves++; }
    $panic = ($nf_home + $nf_away) > 0.25 && $ah_moves > 1;
    $herd = ($nf_home + $nf_away) > 0.08 && $ah_moves >= 1 && !($panic);
    $smoke = $ah_moves > 0 && ($nf_home + $nf_away) < 0.02;
    return ['panic'=>$panic,'herd'=>$herd,'smoke'=>$smoke,'nf_home'=>$nf_home,'nf_away'=>$nf_away,'ah_moves'=>$ah_moves];
}

// ---------------- INSIGHT PREDICT ----------------
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

// ---------------- CKP ----------------
function ckp_analyze(array $open1, array $now1, array $ah_details, array $mem, array $smkx, array $uds, array $mme){
    global $pdo, $CONFIG;
    $reasons=[]; $severity=0.0; $direction=null;
    $d_home = dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
    $d_away = dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
    $ah_home_dir=0; $ah_away_dir=0;
    foreach($ah_details as $ad){ if (($ad['dir_home'] ?? '')==='down') $ah_home_dir--; if (($ad['dir_home'] ?? '')==='up') $ah_home_dir++; if (($ad['dir_away'] ?? '')==='down') $ah_away_dir--; if (($ad['dir_away'] ?? '')==='up') $ah_away_dir++; }
    if ($d_home==='down' && $ah_home_dir>0) { $reasons[]='1x2_home_down_vs_AH_home_up'; $severity+=0.25; $direction='away'; }
    if ($d_away==='down' && $ah_away_dir>0) { $reasons[]='1x2_away_down_vs_AH_away_up'; $severity+=0.25; $direction='home'; }
    if ($smkx['score']>=0.6 && abs($mem['maxShift'])>0.04){ $reasons[]='SMK_vs_MEM_mismatch'; $severity+=0.3; $direction = ($mem['maxShift']>0) ? 'home':'away'; }
    if ($uds['conflict']>0){ $reasons[]='UDS_multi_price_conflict'; $severity+=0.2; }
    if ($mme['isTrap'] && $mme['isSharp']){ $reasons[]='MME_trap_and_sharp'; $severity+=0.2; }
    $severity = clampf($severity,0.0,1.0);
    if ($severity >= $CONFIG['ckp_severity_min']) {
        if ($pdo) {
            $mk = md5(json_encode($now1).microtime(true));
            $st = $pdo->prepare("INSERT INTO ck_alerts (match_key,reason,direction,severity,meta,ts) VALUES(:mk,:r,:d,:s,:m,:ts)");
            $st->execute([':mk'=>$mk,':r'=>implode(';',$reasons),':d'=>$direction,':s'=>$severity,':m'=>json_encode(['reasons'=>$reasons,'mem'=>$mem,'smkx'=>$smkx,'uds'=>$uds,'mme'=>$mme]),':ts'=>now_ts()]);
        }
        return ['kill_alert'=>true,'reason'=>$reasons,'direction'=>$direction,'severity'=>$severity];
    }
    return ['kill_alert'=>false,'reason'=>$reasons,'direction'=>$direction,'severity'=>$severity];
}

// ---------------- AUTOTUNE ----------------
function autotune_check_and_apply(){
    global $pdo, $CONFIG;
    if (!$pdo || !$CONFIG['autotune_enabled']) return ['applied'=>false,'reason'=>'no_db_or_disabled'];
    $st = $pdo->query("SELECT COUNT(*) as c FROM ck_outcome");
    $c = intval($st->fetchColumn());
    if ($c < $CONFIG['autotune_min_cases']) return ['applied'=>false,'reason'=>'not_enough_cases','cases'=>$c];
    $st2 = $pdo->query("SELECT signature,win_rate,count FROM pattern_memory ORDER BY count DESC LIMIT 20");
    $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['applied'=>false,'reason'=>'no_pattern'];
    $avgWR = array_sum(array_map(function($r){ return floatval($r['win_rate']); }, $rows)) / max(1,count($rows));
    $baseKey = 'master_smk';
    $cur = ewma_get($baseKey, 0.05);
    $oldAlpha = $cur['alpha'];
    $newAlpha = $oldAlpha;
    if ($avgWR > 0.55) $newAlpha = clampf($oldAlpha + $CONFIG['autotune_alpha_step'], 0.01, 0.3);
    elseif ($avgWR < 0.48) $newAlpha = clampf($oldAlpha - $CONFIG['autotune_alpha_step'], 0.01, 0.3);
    $st3 = $pdo->prepare("UPDATE ewma_store SET alpha=:a, updated_at=:t WHERE k=:k");
    $st3->execute([':a'=>$newAlpha,':t'=>now_ts(),':k'=>$baseKey]);
    $st4 = $pdo->prepare("INSERT INTO autotune_log (param,old,new,reason,ts) VALUES(:p,:o,:n,:r,:t)");
    $st4->execute([':p'=>$baseKey,':o'=>$oldAlpha,':n'=>$newAlpha,':r'=>'autotune_avgWR_'.$avgWR,':t'=>now_ts()]);
    return ['applied'=>true,'param'=>$baseKey,'old'=>$oldAlpha,'new'=>$newAlpha,'avgWR'=>$avgWR];
}

// ---------------- PATTERN MEMORY ----------------
function pm_lookup($signature){ global $pdo; if(!$pdo) return null; $sig=md5(json_encode($signature)); $st=$pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:s"); $st->execute([':s'=>$sig]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?$r:null; }
function pm_learn($signature, bool $win, array $meta=[]){ global $pdo; if(!$pdo) return false; $sig=md5(json_encode($signature)); $st=$pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:s"); $st->execute([':s'=>$sig]); $r=$st->fetch(PDO::FETCH_ASSOC); $now=now_ts(); if($r){ $count=intval($r['count'])+1; $prev=floatval($r['win_rate']); $new=(($prev*intval($r['count'])) + ($win?1.0:0.0))/max(1,$count); $upd=$pdo->prepare("UPDATE pattern_memory SET count=:c,win_rate=:w,last_seen=:ls,meta=:m WHERE id=:id"); $upd->execute([':c'=>$count,':w'=>$new,':ls'=>$now,':m'=>json_encode($meta),':id'=>$r['id']]); } else { $ins=$pdo->prepare("INSERT INTO pattern_memory (signature,count,win_rate,last_seen,meta) VALUES(:sig,1,:wr,:ls,:m)"); $ins->execute([':sig'=>$sig,':wr'=>($win?1.0:0.0),':ls'=>$now,':m'=>json_encode($meta)]); } return true; }

// ---------------- AUTO REBOUND ----------------
function compute_auto_rebound_from_pair(float $open, float $now): float { if ($open <= 0 || $now <= 0) return 0.02; $delta = abs($now - $open); if ($delta <= 0.000001) return 0.04; $strength = $delta / $open; if ($strength < 0.02) return 0.04; if ($strength < 0.05) return 0.03; if ($strength < 0.12) return 0.02; return 0.015; }
function compute_auto_rebound_agg(array $pairs): float { global $CONFIG; $sList=[]; foreach ($pairs as $p){ if(!isset($p['open'])||!isset($p['now'])) continue; $o=floatval($p['open']); $n=floatval($p['now']); if($o>0 && $n>0) $sList[] = compute_auto_rebound_from_pair($o,$n); } if(count($sList)===0) return 0.025; $val=array_sum($sList)/count($sList); if(isset($CONFIG['reboundSens_min'])) $val = max($val,floatval($CONFIG['reboundSens_min'])); return $val; }

// ---------------- VOID CORE (Integrated) ----------------
/**
 * VOID Core functions implement the pre-match VOID logic:
 * - void_shell: remove margin & compute true probabilities
 * - void_balance_mapper: detect price intention & equilibrium
 * - void_flow_engine: detect real flow direction vs fake moves
 * - void_divergence: measure difference market vs true
 * - void_trap_machine: detect traps and assign level
 * - void_interpreter: produce human-readable verdict
 */

// VOID Shell: estimate true probabilities by deconstructing margin and hidden bias
function void_shell(array $now1) {
    // Step 1: compute market implied probs
    $market = []; $sum = 0;
    foreach (['home','draw','away'] as $k) {
        $o = safeFloat($now1[$k] ?? null);
        $market[$k] = (is_nan($o) || $o <= 0) ? 0.0 : (1.0 / $o);
        $sum += $market[$k];
    }
    if ($sum <= 0) return ['true_prob'=>['home'=>0.33,'draw'=>0.34,'away'=>0.33],'overround'=>0.0];
    foreach (['home','draw','away'] as $k) $market[$k] /= $sum;
    $overround = $sum - 1.0;

    // Step 2: attempt to remove visible overround + hidden bias via simple deconvolution:
    // We use an adaptive baseline from EWMA (historical) to reweight market.
    $hist_home = ewma_get('master_tpo_home', $market['home'])['v'];
    $hist_draw = ewma_get('master_tpo_draw', $market['draw'])['v'];
    $hist_away = ewma_get('master_tpo_away', $market['away'])['v'];

    // Blend weight: if overround large, reduce market weight
    $market_weight = clampf(1.0 - min(0.5, $overround*4.0), 0.4, 0.95);
    $hist_weight = 1.0 - $market_weight;

    $true = [
        'home' => clampf($market_weight * $market['home'] + $hist_weight * $hist_home, 1e-6, 0.9999),
        'draw' => clampf($market_weight * $market['draw'] + $hist_weight * $hist_draw, 1e-6, 0.9999),
        'away' => clampf($market_weight * $market['away'] + $hist_weight * $hist_away, 1e-6, 0.9999)
    ];
    // Normalize and return
    $s = array_sum($true);
    foreach (['home','draw','away'] as $k) $true[$k] /= max(1e-9,$s);

    return ['true_prob'=>$true,'market_prob'=>$market,'overround'=>$overround,'blend'=>['market_weight'=>$market_weight,'hist_weight'=>$hist_weight]];
}

// VOID Balance Mapper: detect market intention
function void_balance_mapper(array $open1, array $now1, array $ah_details) {
    // Determine directional bias of market: which side market pushes
    $d_home = dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
    $d_away = dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
    $ah_home_dir = 0; $ah_away_dir = 0;
    foreach ($ah_details as $ad) {
        if (($ad['dir_home'] ?? '') === 'down') $ah_home_dir--; if (($ad['dir_home'] ?? '') === 'up') $ah_home_dir++;
        if (($ad['dir_away'] ?? '') === 'down') $ah_away_dir--; if (($ad['dir_away'] ?? '') === 'up') $ah_away_dir++;
    }
    // Compute bias score: positive favors home, negative favors away
    $biasScore = 0.0;
    if ($d_home === 'down') $biasScore += 0.25;
    if ($d_away === 'down') $biasScore -= 0.25;
    $biasScore += ($ah_home_dir - $ah_away_dir) * 0.08;
    return ['d_home'=>$d_home,'d_away'=>$d_away,'ah_home_dir'=>$ah_home_dir,'ah_away_dir'=>$ah_away_dir,'biasScore'=>clampf($biasScore,-1.0,1.0)];
}

// VOID Flow Engine: detect real vs fake moves
function void_flow_engine(array $open1, array $now1, array $ah_details) {
    $pairs = [];
    $pairs[] = ['open'=>$open1['home'] ?? NAN, 'now'=>$now1['home'] ?? NAN];
    $pairs[] = ['open'=>$open1['away'] ?? NAN, 'now'=>$now1['away'] ?? NAN];
    foreach ($ah_details as $ad) {
        $pairs[] = ['open'=>$ad['open_home'] ?? NAN,'now'=>$ad['now_home'] ?? NAN];
        $pairs[] = ['open'=>$ad['open_away'] ?? NAN,'now'=>$ad['now_away'] ?? NAN];
    }
    // compute aggregate netflow relative strengths
    $totalRel = 0.0; $count=0;
    foreach ($pairs as $p) {
        if (!isset($p['open']) || !isset($p['now'])) continue;
        $o = floatval($p['open']); $n = floatval($p['now']);
        if ($o>0) { $totalRel += abs(($n - $o)/$o); $count++; }
    }
    $avgRel = $count ? ($totalRel/$count) : 0.0;
    // sharp if avgRel passes threshold
    $isSharp = $avgRel > 0.08;
    // fake move if AH moves but 1x2 hardly moves
    $flow1_change = abs(netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null))) + abs(netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null)));
    $ah_move_total = 0; foreach ($ah_details as $ad) { $ah_move_total += (abs($ad['open_home'] - $ad['now_home']) + abs($ad['open_away'] - $ad['now_away'])); }
    $fake = ($ah_move_total > 0.02 && $flow1_change < 0.02);
    return ['avgRel'=>$avgRel,'isSharp'=>$isSharp,'fake'=>$fake,'flow1_change'=>$flow1_change,'ah_move_total'=>$ah_move_total];
}

// VOID Divergence: difference between market and true
function void_divergence(array $market_prob, array $true_prob) {
    $div = 0.0;
    foreach (['home','draw','away'] as $k) $div += abs(($market_prob[$k] ?? 0) - ($true_prob[$k] ?? 0));
    return $div; // 0..2
}

// VOID Trap Machine: produce trap level 0..5
function void_trap_machine(array $open1, array $now1, array $ah_details, array $mme, array $ckp, array $emo) {
    $level = 0; $reasons = [];
    $div = 0;
    foreach ($ah_details as $ad) if (($ad['dir_home'] ?? '') !== ($ad['dir_away'] ?? '')) $div++;
    if ($div > 0) { $level += 1; $reasons[] = 'multi_price_conflict'; }
    if ($mme['isTrap']) { $level += 1; $reasons[] = 'mme_trap'; }
    if ($ckp['kill_alert']) { $level += 2; $reasons[] = 'ckp_alert'; }
    if ($emo['panic']) { $level += 1; $reasons[] = 'emotional_panic'; }
    $level = clampf($level, 0, 5);
    return ['level'=>$level,'reasons'=>$reasons];
}

// VOID Interpreter: human readable
function void_interpreter(array $data) {
    // data contains keys: true_prob, market_prob, div, trap, flow, bias...
    $messages = [];
    $advice = 'รอ confirm';
    if ($data['trap']['level'] >= 3) { $messages[] = 'ตลาดกำลังใช้กับดัก (Trap Level '.$data['trap']['level'].')'; $advice = 'หลีกเลี่ยง/ไม่แนะนำเดิมพันทั่วไป'; }
    if ($data['div'] > 0.12) { $messages[] = 'Divergence สูง — ตลาดปั้นราคา'; $advice = 'พิจารณาสัญญาณ VOID เท่านั้น'; }
    if ($data['flow']['isSharp'] && !$data['flow']['fake']) { $messages[] = 'Sharp Move — ไหลจริง'; $advice = 'พิจารณาตาม — จำกัดความเสี่ยง'; }
    if ($data['flow']['fake']) { $messages[] = 'AH ไหลแต่ 1x2 นิ่ง — สัญญาณหลอก'; $advice = 'ไม่แนะนำเดิมพัน'; }
    // Which side VOID favors?
    $winner = 'ไม่แน่ใจ';
    if ($data['true_prob']['home'] > $data['true_prob']['away'] && $data['true_prob']['home'] > $data['true_prob']['draw']) $winner = $data['homeName'] ?? 'Home';
    if ($data['true_prob']['away'] > $data['true_prob']['home'] && $data['true_prob']['away'] > $data['true_prob']['draw']) $winner = $data['awayName'] ?? 'Away';
    $summary = "VOID Verdict: Favours {$winner} — True Prob H:".round($data['true_prob']['home']*100,1)."%, D:".round($data['true_prob']['draw']*100,1)."%, A:".round($data['true_prob']['away']*100,1)."%";
    return ['messages'=>$messages,'advice'=>$advice,'winner'=>$winner,'summary'=>$summary];
}

// ---------------- MASTER ANALYZE (Integrated) ----------------
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
    $reboundSens = compute_auto_rebound_agg($pairs);
    if (($payload['mode'] ?? $CONFIG['mode']) === 'pre_match') $reboundSens = max($reboundSens, floatval($CONFIG['reboundSens_min']));

    // pm disparity
    $dis = pm_disparity($open1,$now1);

    // tpo
    $tpo = tpo_estimate($now1,$open1); if (!$tpo) $tpo = ['home'=>0.33,'draw'=>0.34,'away'=>0.33];

    // mpe
    $mpe = mpe_simulate($tpo, intval($CONFIG['mpe_sim_count']));

    // mme
    $mme = mme_analyze($ah_details, $flow1, $kickoff);

    // uds
    $uds = uds_detect($ah_details,$flow1);

    // market_prob now
    $market_prob=[]; $sum_now=0;
    foreach (['home','draw','away'] as $s){ $n = safeFloat($now1[$s] ?? null); $market_prob[$s] = (is_nan($n)||$n<=0)?0.0:(1.0/$n); $sum_now += $market_prob[$s]; }
    if ($sum_now>0) foreach(['home','draw','away'] as $s) $market_prob[$s]/=$sum_now;

    // VOID Shell -> true_prob primary
    $void_shell = void_shell($now1);
    $true_prob = $void_shell['true_prob'];
    $market_prob_norm = $void_shell['market_prob'];
    $overround = $void_shell['overround'];

    // pce projection (use MEM+MME)
    $mem = mem_compute($tpo,$market_prob_norm);
    $pce_proj = pce_project($market_prob_norm,$tpo,$mme,$uds);

    // smkx (legacy)
    $smkx = smkx_score($tpo,$true_prob,$mme,$uds);

    // ocm
    $ocm = ocm_compute($true_prob,$smkx,$mme,$mem);

    // ckp
    $ckp = ckp_analyze($open1,$now1,$ah_details,$mem,$smkx,$uds,$mme);

    // crossmarket shadow
    $shadow = crossmarket_shadow($now1,$ah_details);

    // emotion sim
    $emo = emotional_sim($open1,$now1,$ah_details);

    // insight
    $features = ['smkx_score'=>$smkx['score'],'mem_shift'=>$mem['maxShift'],'emotional_panic'=>$emo['panic']];
    $insight = insight_predict($features);

    // VOID flow detection
    $flow = void_flow_engine($open1,$now1,$ah_details);

    // VOID divergence
    $div = void_divergence($market_prob_norm,$true_prob);

    // VOID trap
    $trap = void_trap_machine($open1,$now1,$ah_details,$mme,$ckp,$emo);

    // VOID interpreter
    $voidData = ['true_prob'=>$true_prob,'market_prob'=>$market_prob_norm,'div'=>$div,'trap'=>$trap,'flow'=>$flow,'homeName'=>$home,'awayName'=>$away];
    $interpret = void_interpreter($voidData);

    // Decision merge logic (Integrated: VOID takes precedence)
    // Create a combined decision score using VOID signals + legacy signals
    $void_strength = 0.0;
    // base components
    $void_strength += clampf(($div / 0.5), 0.0, 1.0) * 0.4; // divergence weight
    $void_strength += clampf($trap['level']/5.0, 0.0, 1.0) * 0.25; // trap increases caution
    $void_strength += clampf($flow['avgRel']/0.25, 0.0, 1.0) * 0.2; // flow strength
    $void_strength += clampf($smkx['score'], 0.0, 1.0) * 0.15; // smart money signal
    $void_strength = clampf($void_strength, 0.0, 1.0);

    // Determine final verdict using VOID thresholds and combined signals
    $void_layers_agree = 0;
    // layer checks: divergence, flow sharp, smkx strong, trap high, mem shifted
    if ($div > $CONFIG['void_divergence_threshold']) $void_layers_agree++;
    if ($flow['isSharp'] && !$flow['fake']) $void_layers_agree++;
    if ($smkx['score'] >= 0.65) $void_layers_agree++;
    if ($trap['level'] >= 2) $void_layers_agree++;
    if ($mem['label'] === 'shifted') $void_layers_agree++;

    // final VOID mode decision
    $void_mode = 'normal'; // normal, lock, overload
    if ($void_layers_agree >= $CONFIG['void_lock_layers_required']) $void_mode = 'lock';
    if ($void_layers_agree >= 5 && $void_strength > $CONFIG['void_overload_threshold']) $void_mode = 'overload';

    // final label selection prioritizes VOID when lock/overload, otherwise legacy combined logic
    $predicted = 'ไม่แน่ใจ';
    if ($true_prob['home'] > $true_prob['away'] && $true_prob['home'] > $true_prob['draw']) $predicted = $home;
    if ($true_prob['away'] > $true_prob['home'] && $true_prob['away'] > $true_prob['draw']) $predicted = $away;

    $final_label = '⚠️ ไม่ชัดเจน'; $recommendation = 'รอ confirm';
    if ($void_mode === 'overload') {
        $final_label = '🔥 VOID OVERLOAD — MARKET BREAK'; $recommendation = 'หลีกเลี่ยงหรือเดิมพันเฉพาะผู้เชี่ยวชาญมากเท่านั้น';
    } elseif ($void_mode === 'lock') {
        $final_label = '💀 VOID LOCK — HIGH CONFIDENCE'; $recommendation = 'พิจารณาตาม — จำกัดขนาด';
    } elseif ($ckp['kill_alert']) {
        $final_label = '❌ CKP ALERT — CONTRADICTION DETECTED'; $recommendation = 'หลีกเลี่ยง';
    } elseif ($smkx['type'] === 'smart_money_killer' && $ocm['strength'] >= 45) {
        $final_label = '💥 SMK-X + OCM — Market Push'; $recommendation = 'พิจารณาตาม — จำกัดความเสี่ยง';
    } elseif ($ocm['strength'] >= 60) {
        $final_label = '🔥 STRONG COLLAPSE — ตลาดบีบผล'; $recommendation = 'พิจารณาแบบผู้เชี่ยวชาญเท่านั้น';
    } elseif ($smkx['type'] === 'smart_money_possible') {
        $final_label = '⚡ Smart Money Possible'; $recommendation = 'Watch / small stake only';
    }

    // Confidence: base on void_strength & marketMomentum & ocm strength
    $conf = 40 + ($void_strength * 40) + (min(1.0,$marketMomentum/0.3) * 8) + ($ocm['strength']/10) - ($uds['divergence']*8);
    $confidence = round(clampf($conf,0,100),1);

    // Persist EWMA + history
    ewma_update('master_netflow', floatval((($flow1['home'] ?? 0) - ($flow1['away'] ?? 0))));
    ewma_update('master_voidscore', floatval($void_strength));
    ewma_update('master_smk', floatval($smkx['score']));
    if ($pdo) {
        $th = ewma_get('master_tpo_home',$tpo['home']); $st = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
        $st->execute([':k'=>'master_tpo_home',':v'=>($th['v']*0.85+$tpo['home']*0.15),':a'=>$th['alpha'],':t'=>now_ts()]);
        $td = ewma_get('master_tpo_draw',$tpo['draw']); $st->execute([':k'=>'master_tpo_draw',':v'=>($td['v']*0.85+$tpo['draw']*0.15),':a'=>$td['alpha'],':t'=>now_ts()]);
        $ta = ewma_get('master_tpo_away',$tpo['away']); $st->execute([':k'=>'master_tpo_away',':v'=>($ta['v']*0.85+$tpo['away']*0.15),':a'=>$ta['alpha'],':t'=>now_ts()]);
    }

    // persist match_case
    if ($pdo) {
        $mk = md5($home.'|'.$away.'|'.($kickoff?:time()).microtime(true));
        $ins = $pdo->prepare("INSERT INTO match_cases (match_key,kickoff_ts,league,payload,analysis,outcome,ts) VALUES(:mk,:ks,:league,:p,:a,:o,:t)");
        $ins->execute([':mk'=>$mk,':ks'=>($kickoff?:0),':league'=>$league,':p'=>json_encode($payload,JSON_UNESCAPED_UNICODE),':a'=>json_encode(['final_label'=>$final_label,'true_prob'=>$true_prob,'pce'=>$pce_proj,'smkx'=>$smkx,'mem'=>$mem,'ocm'=>$ocm,'ckp'=>$ckp,'void'=>['mode'=>$void_mode,'strength'=>$void_strength,'layers'=>$void_layers_agree,'div'=>$div,'trap'=>$trap],'insight'=>$insight],JSON_UNESCAPED_UNICODE),':o'=>'',':t'=>now_ts()]);
    }

    // possibly autotune
    $autotune_res = [];
    if ($CONFIG['autotune_enabled']) { $autotune_res = autotune_check_and_apply(); }

    // final response — integrated
    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'league'=>$league,'kickoff_ts'=>$kickoff,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list],
        'metrics'=>[
            'marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,
            'reboundSens'=>$reboundSens,'disparity'=>$dis,'tpo'=>$tpo,'mpe'=>$mpe,'mme'=>$mme,'uds'=>$uds,'smkx'=>$smkx,'mem'=>$mem,'ocm'=>$ocm,'ckp'=>$ckp,'shadow'=>$shadow,'emotion'=>$emo,'insight'=>$insight,'void'=>['mode'=>$void_mode,'strength'=>$void_strength,'layers'=>$void_layers_agree,'div'=>$div,'trap'=>$trap,'flow'=>$flow]
        ],
        'void_score'=>$void_strength,
        'final_label'=>$final_label,
        'recommendation'=>$recommendation,
        'predicted_winner'=>$predicted,
        'true_prob'=>$true_prob,
        'market_prob_now'=>$market_prob_norm,
        'pce_projection'=>$pce_proj,
        'ah_details'=>$ah_details,
        'confidence'=>$confidence,
        'autotune'=>$autotune_res
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
    if (isset($_GET['action']) && $_GET['action']==='mark_outcome' && $_SERVER['REQUEST_METHOD']==='POST') {
        $raw = file_get_contents('php://input'); $p = json_decode($raw,true);
        if (!is_array($p) || empty($p['match_key']) || empty($p['outcome'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>'invalid']); exit; }
        $mk = $p['match_key']; $o = $p['outcome']; if ($pdo) { $st = $pdo->prepare("INSERT INTO ck_outcome (match_key,outcome,ts) VALUES(:mk,:o,:t)"); $st->execute([':mk'=>$mk,':o'=>$o,':t'=>now_ts()]); }
        if ($pdo) {
            $st2 = $pdo->prepare("SELECT id,payload,analysis FROM match_cases WHERE match_key=:mk ORDER BY id DESC LIMIT 1");
            $st2->execute([':mk'=>$mk]); $r = $st2->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $upd = $pdo->prepare("UPDATE match_cases SET outcome=:o WHERE id=:id"); $upd->execute([':o'=>$o,':id'=>$r['id']]);
                $analysis = json_decode($r['analysis'],true);
                $signature = ['analysis'=>$analysis];
                $win = false;
                if (isset($analysis['true_prob'])) {
                    if ($o === 'home' && $analysis['true_prob']['home'] > $analysis['true_prob']['away']) $win=true;
                    if ($o === 'away' && $analysis['true_prob']['away'] > $analysis['true_prob']['home']) $win=true;
                }
                pm_learn($signature, $win, ['match_case_id'=>$r['id']]);
                $at = autotune_check_and_apply();
                header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'ok','learned'=>$win,'autotune'=>$at], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
            }
        }
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'ok','msg'=>'marked'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
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
        $st = $pdo->query("SELECT id,match_key,kickoff_ts,league,ts FROM match_cases ORDER BY id DESC LIMIT 100");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','cases'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
}

// ---------------- Minimal integrated frontend (reuse previous UI but indicate VOID integrated) ----------------
?><!doctype html>
<html lang="th">
<head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Last.php — VOID Integrated</title>
<style>
body{background:#07030a;color:#fff;font-family:Inter, 'Noto Sans Thai';padding:18px}
.container{max-width:1100px;margin:0 auto}
.card{background:linear-gradient(180deg,#0d0506,#241116);padding:12px;border-radius:12px;margin-bottom:12px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
input,select{padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.05);background:rgba(255,255,255,0.02);color:#fff;width:100%}
.btn{padding:10px 12px;border-radius:8px;background:linear-gradient(90deg,#7e2aa3,#d4a017);border:none;cursor:pointer}
.small{color:#d9c89a}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
pre{white-space:pre-wrap;word-wrap:break-word}
</style>
</head><body>
<div class="container">
  <div class="card"><h2 style="margin:0">LAST.PHP — VOID INTEGRATED (Mode B)</h2><div class="small">VOID Core is integrated and acts as primary decision layer.</div></div>

  <div class="card">
    <div class="grid">
      <div><label>Home</label><input id="home" placeholder="ทีมเหย้า"></div>
      <div><label>Away</label><input id="away" placeholder="ทีมเยือน"></div>
      <div><label>League</label><input id="league" placeholder="EPL"></div>
    </div>
    <div style="height:8px"></div>
    <div class="card">
      <div class="small">Kickoff (YYYY-MM-DD HH:MM)</div>
      <input id="kickoff">
      <div style="height:8px"></div>
      <div class="small">1X2 Open</div>
      <div class="grid"><input id="open_home" placeholder="2.10"><input id="open_draw" placeholder="3.40"><input id="open_away" placeholder="3.10"></div>
      <div style="height:8px"></div>
      <div class="small">1X2 Now</div>
      <div class="grid"><input id="now_home" placeholder="1.95"><input id="now_draw" placeholder="3.60"><input id="now_away" placeholder="3.80"></div>
    </div>
    <div style="height:8px"></div>
    <div style="display:flex;justify-content:flex-end"><button id="analyzeBtn" class="btn">🔎 Analyze (Integrated VOID)</button></div>
  </div>

  <div id="result" class="card" style="display:none"></div>
</div>
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
  let html = `<div style="font-weight:900;color:#d4a017">${r.final_label}</div><div class="small">Recommend: ${r.recommendation}</div><div class="small">Predicted: ${r.predicted_winner} — Confidence: ${r.confidence}%</div><hr>`;
  html += `<div style="display:flex;gap:12px;flex-wrap:wrap"><div style="flex:1;min-width:300px"><strong>True Prob</strong><pre>${JSON.stringify(r.true_prob, null, 2)}</pre></div><div style="flex:1;min-width:300px"><strong>PCE Projection</strong><pre>${JSON.stringify(r.pce_projection, null, 2)}</pre></div></div>`;
  html += `<div style="margin-top:8px"><strong>Metrics</strong><pre>${JSON.stringify(r.metrics, null, 2)}</pre></div>`;
  document.getElementById('result').innerHTML = html;
}
</script>
</body>
</html>
