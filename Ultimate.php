<?php
declare(strict_types=1);
/**
 * Last.php — THE VOID ENGINE ∞ Ultimate (Full assemble D)
 * - Full Pre-match: Core Engine + MEM + OCM + CKP + Dynamic Equilibrium Override + Cross-Market Shadow Engine (heuristic) +
 *   Emotional Market Simulation + Contextless Insight AI + Decision Engine V2 + Auto-Tune EWMA-LD + UI (Chinese motif) + Mark Outcome
 * Single-file deploy (PHP 7.4+). Uses SQLite.
 *
 * IMPORTANT:
 * - Backup original Last.php and DB before replacing.
 * - Ensure pdo_sqlite enabled.
 * - This file is deterministic and works only from user-provided prices (no external web calls).
 * - After gaining match outcomes (Mark Outcome), system will auto-tune α and pattern memory.
 */

// ---------------- ENV ----------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$BASEDIR = __DIR__;
$DB_FILE = $BASEDIR . '/void_ultra.db';

// ---------------- DB init ----------------
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // core tables
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
    // diagnostic / tuning
    $pdo->exec("CREATE TABLE IF NOT EXISTS autotune_log (id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT, old REAL, new REAL, reason TEXT, ts INTEGER)");
} catch (Exception $e) {
    error_log("DB init error: " . $e->getMessage());
    $pdo = null;
}

// ---------------- CONFIG ----------------
$CONFIG = [
    'mode'=>'pre_match',
    'mpe_sim_count'=>700,
    'tpo_margin_est'=>0.06,
    'reboundSens_min'=>0.03,
    'mem_eq_shift_threshold'=>0.03,
    'ocm_strength_scale'=>100,
    'ckp_severity_min'=>0.35,
    // autotune settings
    'autotune_enabled'=>true,
    'autotune_min_cases'=>40, // start auto tuning after N marked outcomes
    'autotune_alpha_step'=>0.01,
];

// ---------------- Helpers ----------------
function safeFloat($v){ if(!isset($v)) return NAN; $s=trim((string)$v); if($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s)? floatval($s) : NAN; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }
function nf($v,$d=4){ return (is_nan($v) || $v===null) ? '-' : number_format((float)$v,$d,'.',''); }
function netflow($open,$now){ if(is_nan($open)||is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n=netflow($open,$now); return is_nan($n)?NAN:abs($n); }
function dir_label($open,$now){ if(is_nan($open)||is_nan($now)) return 'flat'; if($now<$open) return 'down'; if($now>$open) return 'up'; return 'flat'; }
function now_ts(){ return time(); }

// ---------------- EWMA (adaptive) ----------------
function ewma_get(string $k, float $fallback=0.0, float $default_alpha=0.08){
    global $pdo, $CONFIG;
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

// ---------------- Poisson ----------------
function poisson_rand($lambda){
    $L = exp(-$lambda); $k = 0; $p = 1.0;
    do { $k++; $p *= mt_rand()/mt_getrandmax(); } while ($p > $L);
    return $k - 1;
}

// ---------------- TPO (True Price Origin) ----------------
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

// ---------------- MPE (compact Poisson xG-based) ----------------
function mpe_simulate(array $tpo, int $sim_count=700) {
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

// ---------------- MME Market Microstructure ----------------
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

// ---------------- UDS divergence ----------------
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

// ---------------- SMK-X ----------------
function smkx_score(array $tpo, array $true_prob, array $mme, array $uds){
    $gap=0.0; foreach(['home','draw','away'] as $k) $gap += abs(($true_prob[$k] ?? 0) - ($tpo[$k] ?? 0));
    $gap = clampf($gap,0.0,1.0);
    $score=0.0; $score += clampf($gap,0,1)*0.35; $score += ($mme['isSharp']?0.3:0.0); $score += ($mme['isTrap']?0.05:0.0); $score += clampf($uds['divergence']/0.5,0.0,0.3);
    $score = clampf($score,0.0,1.0);
    $type = $score>=0.7 ? 'smart_money_killer' : ($score>=0.45 ? 'smart_money_possible' : 'no_smk');
    return ['score'=>$score,'type'=>$type,'components'=>['gap'=>$gap,'mme'=>$mme,'uds'=>$uds]];
}

// ---------------- PCE projection ----------------
function pce_project(array $market_prob, array $tpo, array $mme, array $uds){
    $proj = $market_prob;
    $moveFrac = $mme['isSharp'] ? 0.5 : 0.15; if ($mme['isTrap']) $moveFrac = -0.35;
    foreach(['home','draw','away'] as $k) $proj[$k] = clampf($market_prob[$k] + ($tpo[$k] - $market_prob[$k])*$moveFrac, 0.0001, 0.9999);
    $s = array_sum($proj); foreach(['home','draw','away'] as $k) $proj[$k] /= max(1e-9,$s);
    return $proj;
}

// ---------------- MEM equilibrium (dynamic override) ----------------
function mem_compute(array $tpo, array $market_prob){
    global $pdo, $CONFIG;
    // historical baseline
    $hist_home = ewma_get('master_tpo_home', $tpo['home'])['v'];
    $hist_draw = ewma_get('master_tpo_draw', $tpo['draw'])['v'];
    $hist_away = ewma_get('master_tpo_away', $tpo['away'])['v'];
    // dynamic override: if market deviates strongly and recent pattern memory indicates repeat, give more weight to market
    $pattern_bias = 0.0;
    $equilibrium = [
        'home'=>clampf(0.6*$hist_home + 0.4*$tpo['home'],1e-6,0.9999),
        'draw'=>clampf(0.6*$hist_draw + 0.4*$tpo['draw'],1e-6,0.9999),
        'away'=>clampf(0.6*$hist_away + 0.4*$tpo['away'],1e-6,0.9999)
    ];
    $s = array_sum($equilibrium); foreach(['home','draw','away'] as $k) $equilibrium[$k] /= max(1e-9,$s);
    $shift=[]; $maxShift=0;
    foreach(['home','draw','away'] as $k){ $shift[$k] = ($market_prob[$k] ?? 0) - ($equilibrium[$k] ?? 0); if (abs($shift[$k]) > abs($maxShift)) $maxShift=$shift[$k]; }
    $label = abs($maxShift) > $CONFIG['mem_eq_shift_threshold'] ? 'shifted' : 'aligned';
    // persist equilibrium snapshot
    if ($pdo) {
        $mk = md5(json_encode($market_prob).microtime(true));
        $st = $pdo->prepare("INSERT INTO equilibrium_stats (match_key,tpo_json,market_json,equilibrium_json,ts) VALUES(:mk,:tpo,:mkt,:eq,:ts)");
        $st->execute([':mk'=>$mk,':tpo'=>json_encode($tpo),':mkt'=>json_encode($market_prob),':eq'=>json_encode($equilibrium),':ts'=>now_ts()]);
    }
    return ['equilibrium'=>$equilibrium,'shift'=>$shift,'maxShift'=>$maxShift,'label'=>$label];
}

// ---------------- Cross-Market Shadow Engine (heuristic) ----------------
/**
 * Without multi-book API, we heuristically estimate whether current market looks like 'leader' vs 'follower'
 * - If market shows small spread and low overround -> likely leader
 * - If many AH lines align with market -> leader
 */
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

// ---------------- Emotional Market Simulation ----------------
/**
 * Heuristic patterns translating common 'emotional' behaviors to signals:
 * - Panic squeeze: rapid move + high reverted probability
 * - Herd bullish: steady move with low draw probability
 * - Smoke test: tiny AH moves but 1x2 unchanged
 */
function emotional_sim(array $open1, array $now1, array $ah_details){
    // netflow magnitudes
    $nf_home = abs(netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)));
    $nf_away = abs(netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null)));
    $ah_moves = 0; foreach ($ah_details as $ad) { if (!is_nan($ad['mom_home'] ?? NAN) && ($ad['mom_home'] ?? 0) > 0.03) $ah_moves++; if (!is_nan($ad['mom_away'] ?? NAN) && ($ad['mom_away'] ?? 0) > 0.03) $ah_moves++; }
    $panic = ($nf_home + $nf_away) > 0.25 && $ah_moves > 1;
    $herd = ($nf_home + $nf_away) > 0.08 && $ah_moves >= 1 && !($panic);
    $smoke = $ah_moves > 0 && ($nf_home + $nf_away) < 0.02;
    return ['panic'=>$panic,'herd'=>$herd,'smoke'=>$smoke,'nf_home'=>$nf_home,'nf_away'=>$nf_away,'ah_moves'=>$ah_moves];
}

// ---------------- Contextless Insight AI (pattern predictor) ----------------
/**
 * Simple signature-based lookup: build a signature from features and check pattern_memory for high win_rate
 * If not present, compute heuristic signal score.
 */
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
    // fallback heuristic: return moderate signal based on signature features
    $score = 0.0;
    if (!empty($features['smkx_score'])) $score += $features['smkx_score'] * 0.6;
    if (!empty($features['mem_shift'])) $score += clampf(abs($features['mem_shift']) * 1.2, 0.0, 0.3);
    if (!empty($features['emotional_panic'])) $score -= 0.2;
    $wr = clampf(0.5 + ($score*0.5), 0.0, 1.0);
    return ['from_pattern'=>false,'win_rate'=>$wr,'count'=>0,'signature'=>$sig];
}

// ---------------- CKP Contradiction (same as earlier but adapted) ----------------
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

// ---------------- Autotune (adaptive alpha) ----------------
function autotune_check_and_apply(){
    global $pdo, $CONFIG;
    if (!$pdo || !$CONFIG['autotune_enabled']) return ['applied'=>false,'reason'=>'no_db_or_disabled'];
    // count marked outcomes
    $st = $pdo->query("SELECT COUNT(*) as c FROM ck_outcome");
    $c = intval($st->fetchColumn());
    if ($c < $CONFIG['autotune_min_cases']) return ['applied'=>false,'reason'=>'not_enough_cases','cases'=>$c];
    // simple autotune strategy:
    // - compute historic EWMA performance by comparing pattern_memory win_rate -> if lower than expected decrease alpha, else increase slightly
    $st2 = $pdo->query("SELECT signature,win_rate,count FROM pattern_memory ORDER BY count DESC LIMIT 20");
    $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['applied'=>false,'reason'=>'no_pattern'];
    $avgWR = array_sum(array_map(function($r){ return floatval($r['win_rate']); }, $rows)) / max(1,count($rows));
    // adjust master alpha based on avgWR (heuristic)
    $baseKey = 'master_smk';
    $cur = ewma_get($baseKey, 0.05);
    $oldAlpha = $cur['alpha'];
    $newAlpha = $oldAlpha;
    if ($avgWR > 0.55) $newAlpha = clampf($oldAlpha + $CONFIG['autotune_alpha_step'], 0.01, 0.3);
    elseif ($avgWR < 0.48) $newAlpha = clampf($oldAlpha - $CONFIG['autotune_alpha_step'], 0.01, 0.3);
    // persist change
    $st3 = $pdo->prepare("UPDATE ewma_store SET alpha=:a, updated_at=:t WHERE k=:k");
    $st3->execute([':a'=>$newAlpha,':t'=>now_ts(),':k'=>$baseKey]);
    $st4 = $pdo->prepare("INSERT INTO autotune_log (param,old,new,reason,ts) VALUES(:p,:o,:n,:r,:t)");
    $st4->execute([':p'=>$baseKey,':o'=>$oldAlpha,':n'=>$newAlpha,':r'=>'autotune_avgWR_'.$avgWR,':t'=>now_ts()]);
    return ['applied'=>true,'param'=>$baseKey,'old'=>$oldAlpha,'new'=>$newAlpha,'avgWR'=>$avgWR];
}

// ---------------- Pattern memory lookup/learn ----------------
function pm_lookup($signature){ global $pdo; if(!$pdo) return null; $sig=md5(json_encode($signature)); $st=$pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:s"); $st->execute([':s'=>$sig]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?$r:null; }
function pm_learn($signature, bool $win, array $meta=[]){ global $pdo; if(!$pdo) return false; $sig=md5(json_encode($signature)); $st=$pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:s"); $st->execute([':s'=>$sig]); $r=$st->fetch(PDO::FETCH_ASSOC); $now=now_ts(); if($r){ $count=intval($r['count'])+1; $prev=floatval($r['win_rate']); $new=(($prev*intval($r['count'])) + ($win?1.0:0.0))/max(1,$count); $upd=$pdo->prepare("UPDATE pattern_memory SET count=:c,win_rate=:w,last_seen=:ls,meta=:m WHERE id=:id"); $upd->execute([':c'=>$count,':w'=>$new,':ls'=>$now,':m'=>json_encode($meta),':id'=>$r['id']]); } else { $ins=$pdo->prepare("INSERT INTO pattern_memory (signature,count,win_rate,last_seen,meta) VALUES(:sig,1,:wr,:ls,:m)"); $ins->execute([':sig'=>$sig,':wr'=>($win?1.0:0.0),':ls'=>$now,':m'=>json_encode($meta)]); } return true; }

// ---------------- Auto compute rebound ----------------
function compute_auto_rebound_from_pair(float $open, float $now): float { if ($open <= 0 || $now <= 0) return 0.02; $delta = abs($now - $open); if ($delta <= 0.000001) return 0.04; $strength = $delta / $open; if ($strength < 0.02) return 0.04; if ($strength < 0.05) return 0.03; if ($strength < 0.12) return 0.02; return 0.015; }
function compute_auto_rebound_agg(array $pairs): float { global $CONFIG; $sList=[]; foreach ($pairs as $p){ if(!isset($p['open'])||!isset($p['now'])) continue; $o=floatval($p['open']); $n=floatval($p['now']); if($o>0 && $n>0) $sList[] = compute_auto_rebound_from_pair($o,$n); } if(count($sList)===0) return 0.025; $val=array_sum($sList)/count($sList); if(isset($CONFIG['reboundSens_min'])) $val = max($val,floatval($CONFIG['reboundSens_min'])); return $val; }

// ---------------- Master analyze — assemble everything ----------------
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
    // disparity
    $dis = pm_disparity($open1,$now1);
    // tpo
    $tpo = tpo_estimate($now1,$open1); if (!$tpo) $tpo = ['home'=>0.33,'draw'=>0.34,'away'=>0.33];
    // mpe simulate
    $mpe = mpe_simulate($tpo, intval($CONFIG['mpe_sim_count']));
    // mme analyze
    $mme = mme_analyze($ah_details, $flow1, $kickoff);
    // uds detect
    $uds = uds_detect($ah_details,$flow1);
    // market_prob
    $market_prob=[]; $sum_now=0;
    foreach (['home','draw','away'] as $s){ $n = safeFloat($now1[$s] ?? null); $market_prob[$s] = (is_nan($n)||$n<=0)?0.0:(1.0/$n); $sum_now += $market_prob[$s]; }
    if ($sum_now>0) foreach(['home','draw','away'] as $s) $market_prob[$s]/=$sum_now;
    // true_prob
    $true_prob=[];
    foreach(['home','draw','away'] as $s) $true_prob[$s] = clampf(0.6*($mpe[$s] ?? 0.33) + 0.2*($tpo[$s] ?? 0.33) + 0.2*($market_prob[$s] ?? 0.33), 0.0001, 0.9999);
    $sump = array_sum($true_prob); foreach(['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sump);
    // pce project
    $pce_proj = pce_project($market_prob,$tpo,$mme,$uds);
    // smkx
    $smkx = smkx_score($tpo,$true_prob,$mme,$uds);
    // mem
    $mem = mem_compute($tpo,$market_prob);
    // ocm
    $ocm = ocm_compute($true_prob,$smkx,$mme,$mem);
    // ckp
    $ckp = ckp_analyze($open1,$now1,$ah_details,$mem,$smkx,$uds,$mme);
    // cross-market shadow
    $shadow = crossmarket_shadow($now1,$ah_details);
    // emotional simulation
    $emo = emotional_sim($open1,$now1,$ah_details);
    // insight prediction
    $features = ['smkx_score'=>$smkx['score'],'mem_shift'=>$mem['maxShift'],'emotional_panic'=>$emo['panic']];
    $insight = insight_predict($features);
    // decision engine V2 combine signals
    // weights heuristic: smkx 0.35, ocm strength 0.25, insight win_rate 0.2, shadow leader 0.1, emotive penalty -0.1
    $scoreHome = ($true_prob['home'] - $true_prob['away']) + ($smkx['score'] * ($true_prob['home'] - $true_prob['away']));
    // simpler aggregated direction value
    $aggScore = ($smkx['score'] * (($true_prob['home'] - $true_prob['away']))) + (($ocm['strength']/100.0) * (($true_prob['home'] - $true_prob['away']))) + (($insight['win_rate'] - 0.5) * 0.4);
    // normalize to -1..1
    $aggNorm = clampf($aggScore, -1.0, 1.0);
    $predicted = 'ไม่แน่ใจ';
    if ($true_prob['home'] > $true_prob['away'] && $true_prob['home'] > $true_prob['draw']) $predicted = $home;
    if ($true_prob['away'] > $true_prob['home'] && $true_prob['away'] > $true_prob['draw']) $predicted = $away;
    // final label logic
    $final_label='⚠️ ไม่ชัดเจน'; $recommendation='รอ confirm';
    if ($ckp['kill_alert']) { $final_label='❌ CKP ALERT — CONTRADICTION'; $recommendation='หลีกเลี่ยง/ตรวจสอบ'; }
    elseif ($smkx['type']==='smart_money_killer' && $ocm['strength']>=45) { $final_label='💥 SMK-X + OCM — Market Push'; $recommendation='พิจารณาตาม — จำกัดขนาด'; }
    elseif ($ocm['strength']>=60) { $final_label='🔥 STRONG COLLAPSE — ตลาดบีบผล'; $recommendation='พิจารณาเฉพาะผู้เชี่ยวชาญ'; }
    elseif ($smkx['type']==='smart_money_possible') { $final_label='⚡ Smart Money Possible'; $recommendation='Watch / small stake only'; }
    // confidence
    $conf = 50 + ($smkx['score']*25) + (min(1.0,$marketMomentum/0.3)*10) + ($ocm['strength']/10) - ($uds['divergence']*10) + (($shadow['leader_score']-0.5)*10);
    $confidence = round(clampf($conf,0,100),1);
    // persist EWMA
    ewma_update('master_netflow', floatval((($flow1['home'] ?? 0) - ($flow1['away'] ?? 0))));
    ewma_update('master_voidscore', floatval($smkx['score']));
    ewma_update('master_smk', floatval($smkx['score']));
    // persist per-side tpo
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
        $ins->execute([':mk'=>$mk,':ks'=>($kickoff?:0),':league'=>$league,':p'=>json_encode($payload,JSON_UNESCAPED_UNICODE),':a'=>json_encode(['final_label'=>$final_label,'true_prob'=>$true_prob,'pce'=>$pce_proj,'smkx'=>$smkx,'mem'=>$mem,'ocm'=>$ocm,'ckp'=>$ckp,'shadow'=>$shadow,'emo'=>$emo,'insight'=>$insight],JSON_UNESCAPED_UNICODE),':o'=>'',':t'=>now_ts()]);
    }
    // optionally run autotune (non-blocking light)
    $autotune_res = [];
    if ($CONFIG['autotune_enabled']) { $autotune_res = autotune_check_and_apply(); }
    // return
    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'league'=>$league,'kickoff_ts'=>$kickoff,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list],
        'metrics'=>[
            'marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,
            'reboundSens'=>$reboundSens,'disparity'=>$dis,'tpo'=>$tpo,'mpe'=>$mpe,'mme'=>$mme,'uds'=>$uds,'smkx'=>$smkx,'mem'=>$mem,'ocm'=>$ocm,'ckp'=>$ckp,'shadow'=>$shadow,'emotion'=>$emo,'insight'=>$insight
        ],
        'void_score'=>$smkx['score'],'final_label'=>$final_label,'recommendation'=>$recommendation,'predicted_winner'=>$predicted,
        'true_prob'=>$true_prob,'market_prob_now'=>$market_prob,'pce_projection'=>$pce_proj,'ah_details'=>$ah_details,'confidence'=>$confidence,'autotune'=>$autotune_res
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
        // API to mark outcome: payload { match_key, outcome: 'home'|'away'|'draw' }
        $raw = file_get_contents('php://input'); $p = json_decode($raw,true);
        if (!is_array($p) || empty($p['match_key']) || empty($p['outcome'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>'invalid']); exit; }
        $mk = $p['match_key']; $o = $p['outcome']; $st = $pdo->prepare("INSERT INTO ck_outcome (match_key,outcome,ts) VALUES(:mk,:o,:t)"); $st->execute([':mk'=>$mk,':o'=>$o,':t'=>now_ts()]);
        // auto-learn: find match_case and update outcome & pattern memory
        $st2 = $pdo->prepare("SELECT id,payload,analysis FROM match_cases WHERE match_key=:mk ORDER BY id DESC LIMIT 1");
        $st2->execute([':mk'=>$mk]); $r = $st2->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $upd = $pdo->prepare("UPDATE match_cases SET outcome=:o WHERE id=:id"); $upd->execute([':o'=>$o,':id'=>$r['id']]);
            // generate signature from analysis (if exist)
            $analysis = json_decode($r['analysis'],true);
            $signature = ['analysis'=>$analysis];
            // compute win bool
            $win = false;
            if (isset($analysis['true_prob'])) {
                if ($o === 'home' && $analysis['true_prob']['home'] > $analysis['true_prob']['away']) $win=true;
                if ($o === 'away' && $analysis['true_prob']['away'] > $analysis['true_prob']['home']) $win=true;
            }
            pm_learn($signature, $win, ['match_case_id'=>$r['id']]);
            // after learning, run autotune
            $at = autotune_check_and_apply();
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'ok','learned'=>$win,'autotune'=>$at], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
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
}

// ---------------- FRONTEND UI (Chinese motif + Mark Outcome) ----------------
?><!doctype html>
<html lang="th">
<head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>THE VOID ENGINE ∞ — Dragon Throne</title>
<style>
:root{--gold:#d4a017;--royal:#6b21b6;--bg1:#0b0710;--bg2:#150816;--muted:#d9c89a}
*{box-sizing:border-box} body{margin:0;font-family:Inter,'Noto Serif TC','Noto Sans Thai',system-ui,-apple-system,'Segoe UI',Roboto,Arial;background:linear-gradient(180deg,var(--bg1),var(--bg2));color:#f6eedf}
.container{max-width:1200px;margin:18px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between}
.logo{width:84px;height:84px;border-radius:14px;background:radial-gradient(circle at 30% 20%, rgba(255,255,255,0.04), rgba(107,33,182,0.95));display:flex;align-items:center;justify-content:center;color:white;font-weight:900;font-size:36px}
.card{background:linear-gradient(145deg,#12070a,#2b1712);border-radius:16px;padding:18px;margin-top:12px;box-shadow:0 18px 50px rgba(0,0,0,0.6)}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
input,select,textarea{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,0.04);background:rgba(255,255,255,0.02);color:#fff}
.btn{padding:10px 14px;border-radius:12px;border:none;color:#110b06;background:linear-gradient(90deg,var(--royal),var(--gold));cursor:pointer}
.small{color:var(--muted);font-size:0.9rem}
.resultWrap{display:flex;gap:14px;align-items:flex-start;margin-top:14px}
.analysisCard{flex:1;padding:18px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));position:relative}
.sidePanel{width:340px;padding:14px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01))}
.dragon{position:absolute;right:-60px;top:-40px;width:360px;height:180px;pointer-events:none;opacity:0.95;mix-blend-mode:screen}
.scroll-tome{background:linear-gradient(180deg,#2b160f,#0e0704);border-radius:12px;padding:12px;border:1px solid rgba(212,160,23,0.06)}
@media(max-width:980px){.grid{grid-template-columns:1fr}.dragon{display:none}.sidePanel{width:100%}}
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="logo">鴻</div>
        <div>
          <h1 style="margin:0;color:var(--gold)">THE VOID ENGINE ∞ — Dragon Throne</h1>
          <div class="small">Full Pre-match Intelligence · Market Equilibrium · SMK-X · CKP · Auto-Tune</div>
        </div>
      </div>
      <div><button id="backupBtn" class="btn">สำรองก่อนวาง</button></div>
    </div>

    <div class="card">
      <form id="mainForm" onsubmit="return false;">
        <div class="grid">
          <div><label>ทีมเหย้า</label><input id="home" placeholder="ทีมเหย้า"></div>
          <div><label>ทีมเยือน</label><input id="away" placeholder="ทีมเยือน"></div>
          <div><label>ลีก</label><input id="league" placeholder="EPL"></div>
        </div>

        <div style="height:12px"></div>
        <div class="card">
          <div class="small">Kickoff (YYYY-MM-DD HH:MM)</div>
          <input id="kickoff">
          <div style="height:8px"></div>
          <div class="small">1X2 — ราคาเปิด</div>
          <div class="grid"><input id="open_home" placeholder="2.10"><input id="open_draw" placeholder="3.40"><input id="open_away" placeholder="3.10"></div>
          <div style="height:8px"></div>
          <div class="small">1X2 — ราคาปัจจุบัน</div>
          <div class="grid"><input id="now_home" placeholder="1.95"><input id="now_draw" placeholder="3.60"><input id="now_away" placeholder="3.80"></div>
        </div>

        <div style="height:12px"></div>
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <strong class="small">Asian Handicap — เพิ่ม/ลบได้</strong>
            <div><button id="addAh" type="button" class="btn">+ เพิ่ม AH</button></div>
          </div>
          <div id="ahContainer" style="margin-top:12px"></div>
        </div>

        <div style="height:12px"></div>
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div><input id="favorite" placeholder="ทีมต่อ (home/away)"></div>
          <div style="display:flex;gap:8px"><button id="analyzeBtn" class="btn">🔎 วิเคราะห์</button><button id="markBtn" class="btn" style="background:linear-gradient(90deg,#ef4444,#ffbaba)">📝 Mark Outcome</button></div>
        </div>
      </form>
    </div>

    <div id="resultWrap" class="card" style="display:none;position:relative;overflow:visible">
      <svg class="dragon" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid meet">
        <defs><linearGradient id="g1" x1="0%" x2="100%"><stop offset="0%" stop-color="#ffd78c"/><stop offset="100%" stop-color="#f59e0b"/></linearGradient><path id="dragonPath" d="M20,160 C150,10 350,10 480,140 C560,210 760,120 780,60"/></defs>
        <use xlink:href="#dragonPath" fill="none" stroke="rgba(212,160,23,0.06)" stroke-width="8"/>
        <circle r="14" fill="url(#g1)"><animateMotion dur="6s" repeatCount="indefinite"><mpath xlink:href="#dragonPath"></mpath></animateMotion></circle>
      </svg>

      <div class="resultWrap">
        <div class="analysisCard">
          <div id="mainSummary"></div>
          <div id="mainReasons" style="margin-top:12px"></div>
          <div id="detailTables" style="margin-top:14px"></div>

          <div class="scroll-tome" id="secretTome" style="display:none;margin-top:12px">
            <h4 style="margin:0;color:var(--gold)">คัมภีร์ลับ — อ่านราคาแม่</h4>
            <div id="tomeContent" style="margin-top:8px">ยังไม่มีข้อมูล</div>
          </div>
        </div>

        <div class="sidePanel">
          <div style="text-align:center">
            <div style="font-size:2rem;font-weight:900;color:var(--gold)" id="confValue">--%</div>
            <div class="small">Confidence</div>
            <div style="height:10px"></div>
            <div style="font-size:1.6rem;font-weight:900" id="flowValue">--</div>
            <div class="small">Flow Power</div>
            <div style="height:10px"></div>
            <div id="stakeSuggestion" style="padding:12px;background:linear-gradient(180deg,rgba(255,255,255,0.01),rgba(255,255,255,0.00));border-radius:10px;text-align:center;color:#ffdca8"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card small" style="margin-top:12px"><strong>หมายเหตุ</strong><div class="small">ระบบนี้วิเคราะห์จากราคาที่ผู้ใช้กรอกเท่านั้น — บันทึกผลเพื่อให้ระบบเรียนรู้ (Mark Outcome)</div></div>

  </div>

<script>
// Utilities
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function createAhBlock(data={}) {
  const cont=document.getElementById('ahContainer');
  const div=document.createElement('div');
  div.style='margin-top:8px;padding:10px;background:linear-gradient(180deg,rgba(255,255,255,0.01),rgba(255,255,255,0.00));border-radius:10px';
  div.innerHTML = `<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px"><input class="ah_line" placeholder="line" value="${data.line||''}"><input class="ah_open_home" placeholder="open_home" value="${data.open_home||''}"><input class="ah_open_away" placeholder="open_away" value="${data.open_away||''}"></div><div style="height:8px"></div><div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:8px"><input class="ah_now_home" placeholder="now_home" value="${data.now_home||''}"><input class="ah_now_away" placeholder="now_away" value="${data.now_away||''}"><button class="removeBtn">ลบ</button></div>`;
  cont.appendChild(div);
  div.querySelector('.removeBtn').addEventListener('click', ()=>div.remove());
}
document.getElementById('addAh').addEventListener('click', ()=>createAhBlock());
if (!document.querySelectorAll('#ahContainer > div').length) createAhBlock();

// collect payload
function collectPayload(){
  const home=document.getElementById('home').value||'ทีมเหย้า';
  const away=document.getElementById('away').value||'ทีมเยือน';
  const league=document.getElementById('league').value||'generic';
  const kickoff=document.getElementById('kickoff').value||'';
  const open1={home:toNum(document.getElementById('open_home').value), draw:toNum(document.getElementById('open_draw')?.value), away:toNum(document.getElementById('open_away')?.value)};
  const now1={home:toNum(document.getElementById('now_home').value), draw:toNum(document.getElementById('now_draw')?.value), away:toNum(document.getElementById('now_away')?.value)};
  const ahNodes=Array.from(document.querySelectorAll('#ahContainer > div'));
  const ah = ahNodes.map(n=>({line:n.querySelector('.ah_line').value, open_home:toNum(n.querySelector('.ah_open_home').value), open_away:toNum(n.querySelector('.ah_open_away').value), now_home:toNum(n.querySelector('.ah_now_home').value), now_away:toNum(n.querySelector('.ah_now_away').value)}));
  return {home,away,league,kickoff,open1,now1,ah,mode:'pre_match'};
}

// analyze
async function analyze(){
  const payload = collectPayload();
  document.getElementById('resultWrap').style.display='block';
  document.getElementById('mainSummary').innerHTML = '<div class="small">กำลังวิเคราะห์… มังกรกำลังอ่านคัมภีร์</div>';
  try {
    const res = await fetch('?action=master_analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j = await res.json();
    renderResult(j);
  } catch (e) {
    document.getElementById('mainSummary').innerHTML = '<div class="small">Fetch error: '+e.message+'</div>';
  }
}
document.getElementById('analyzeBtn').addEventListener('click', analyze);

// render
function renderResult(r){
  document.getElementById('confValue').innerText = r.confidence + '%';
  document.getElementById('flowValue').innerText = Math.round((r.metrics.marketMomentum||0)*100);
  document.getElementById('mainSummary').innerHTML = `<div style="display:flex;justify-content:space-between"><div><div style="font-weight:900;font-size:1.1rem;color:var(--gold)">${r.final_label}</div><div style="margin-top:8px">Recommendation: ${r.recommendation}</div><div style="margin-top:6px">Predicted: <strong>${r.predicted_winner}</strong></div></div><div style="text-align:right"><div style="font-size:0.9rem;color:#ffdca8">VoidScore: ${nf(r.void_score,3)}</div></div></div>`;
  if (r.metrics && r.metrics.ckp && r.metrics.ckp.kill_alert) {
    document.getElementById('mainSummary').insertAdjacentHTML('afterbegin','<div style="padding:10px;border-radius:8px;background:linear-gradient(90deg,#ff6b6b,#ffbaba);color:#000;font-weight:900;margin-bottom:8px">⚠️ CKP ALERT</div>');
  }
  // details
  let dt = '<strong>รายละเอียด</strong><div class="small">True Prob / Market / PCE</div><pre>'+JSON.stringify({true:r.true_prob,market:r.market_prob_now,pce:r.pce_projection},null,2)+'</pre>';
  dt += '<div style="margin-top:8px"><strong>Metrics</strong><pre>'+JSON.stringify(r.metrics,null,2)+'</pre></div>';
  document.getElementById('detailTables').innerHTML = dt;
  // tome
  const tome = document.getElementById('secretTome'); if ((r.metrics||{}).mem) { tome.style.display='block'; document.getElementById('tomeContent').innerHTML = `<div>Equilibrium: ${JSON.stringify(r.metrics.mem.equilibrium)}</div><div style="margin-top:6px;color:#ffdca8">คำแนะนำคัมภีร์: ${r.recommendation}</div>`; } else { tome.style.display='none'; }
  window.lastAnalysis = r;
}

// Mark outcome (from UI) - opens prompt to input match_key & outcome
document.getElementById('markBtn').addEventListener('click', async ()=>{
  const mk = prompt('ใส่ match_key (คัดลอกจาก match_cases หรือ leave blank เพื่อเอา last analysis key):');
  let matchKey = mk;
  if (!matchKey) {
    // try to get last inserted match_case by fetching match_cases API (frontend small)
    try {
      const res = await fetch('?action=match_cases'); const j = await res.json();
      if (j.cases && j.cases.length>0) matchKey = j.cases[0].match_key;
    } catch(e){}
  }
  if (!matchKey) { alert('ไม่พบ match_key — โปรดใช้ API หรือ mark ผ่าน match_case list'); return; }
  const outcome = prompt('ผลการแข่งขัน (home / away / draw):');
  if (!outcome) return;
  try {
    const res = await fetch('?action=mark_outcome',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({match_key:matchKey,outcome:outcome})});
    const j = await res.json();
    alert('Marked. Result: '+JSON.stringify(j));
  } catch(e){ alert('Error: '+e.message); }
});

// small helpers
function nf(v,d=4){ return (v===null||v===undefined||isNaN(v))?'-':Number(v).toFixed(d); }
</script>
</body>
</html>
