<?php
declare(strict_types=1);
/**
 * Last.php — VOID MASTER (Merged & Replace-ready)
 * Single-file PHP (PHP 7.4+) — SQLite backed EWMA & History
 *
 * Contains:
 *  - Core engines: LTME, SFR, VPE, SMK3, FDA, LVE, SFD, OUCE
 *  - Learning: EWMA (adaptive alpha), samples, results_history
 *  - Advanced modules: CMF (Cross-Market Fusion), MMRA (Minute-by-Minute Reaction Analyzer), SMAI (Smart Market AI Agent)
 *  - UI: Chinese ancient theme, dragon, ink, tome, responsive
 *
 * IMPORTANT: Backup your previous Last.php and DB before replacing.
 */

// ---------------- ENVIRONMENT ----------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$BASEDIR = __DIR__;
$DB_FILE = $BASEDIR . '/void_master.db';

// ---------------- SQLITE INIT ----------------
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS ewma_store (k TEXT PRIMARY KEY, v REAL, alpha REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT, sample REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS results_history (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT, res_json TEXT, ts INTEGER)");
} catch (Exception $e) {
    error_log("DB init error: ".$e->getMessage());
    $pdo = null;
}

// ---------------- HELPERS ----------------
function safeFloat($v){ if(!isset($v)) return NAN; $s=trim((string)$v); if($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s)? floatval($s) : NAN;}
function clampf($v,$a,$b){ return max($a,min($b,$v)); }
function netflow($open,$now){ if(is_nan($open)||is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n=netflow($open,$now); return is_nan($n)?NAN:abs($n); }
function dir_label($open,$now){ if(is_nan($open)||is_nan($now)) return 'flat'; if($now<$open) return 'down'; if($now>$open) return 'up'; return 'flat'; }
function nf($v,$d=4){ return (is_nan($v) || $v===null) ? '-' : number_format((float)$v,$d,'.',''); }

// ---------------- EWMA (adaptive) ----------------
function ewma_get(string $k, float $fallback = 0.0, float $default_alpha = 0.25) {
    global $pdo;
    if (!$pdo) return ['v'=>$fallback,'alpha'=>$default_alpha];
    $st = $pdo->prepare("SELECT v,alpha FROM ewma_store WHERE k=:k");
    $st->execute([':k'=>$k]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        $ins = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
        $ins->execute([':k'=>$k,':v'=>$fallback,':a'=>$default_alpha,':t'=>time()]);
        return ['v'=>$fallback,'alpha'=>$default_alpha];
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
    adaptive_alpha_tune($k);
    return ['k'=>$k,'v'=>$newv,'alpha'=>$alpha];
}
function ewma_set_alpha(string $k,float $alpha){ global $pdo; if(!$pdo) return false; $alpha=clampf($alpha,0.01,0.9); $cur=ewma_get($k); $st=$pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)"); $st->execute([':k'=>$k,':v'=>$cur['v'],':a'=>$alpha,':t'=>time()]); return true;}
function adaptive_alpha_tune(string $k) {
    global $pdo;
    if (!$pdo) return;
    $N = 36;
    $st = $pdo->prepare("SELECT sample, ts FROM samples WHERE k=:k ORDER BY id DESC LIMIT :n");
    $st->bindValue(':k', $k);
    $st->bindValue(':n', $N, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows || count($rows) < 8) return;
    $arr = array_map(function($r){ return floatval($r['sample']); }, $rows);
    $mean = array_sum($arr)/count($arr);
    $var = 0.0; foreach ($arr as $v) $var += pow($v - $mean, 2);
    $std = sqrt($var / count($arr));
    $norm = clampf($std / max(1e-6, abs($mean)+1e-6), 0.0, 1.0);
    $newAlpha = 0.02 + ($norm * 0.68);
    ewma_set_alpha($k, $newAlpha);
}

// ---------------- LVE (Liquidity Velocity Engine) ----------------
function get_last_samples(string $k, int $n=8) {
    global $pdo;
    if (!$pdo) return [];
    $st = $pdo->prepare("SELECT sample,ts FROM samples WHERE k=:k ORDER BY id DESC LIMIT :n");
    $st->bindValue(':k',$k);
    $st->bindValue(':n',$n,PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return array_reverse($rows);
}
function lve_compute(float $current_sample): array {
    $rows = get_last_samples('master_netflow', 8);
    $now_ts = time();
    $data = [];
    foreach ($rows as $r) $data[] = ['sample'=>floatval($r['sample']),'ts'=>intval($r['ts'])];
    $data[] = ['sample'=>$current_sample,'ts'=>$now_ts];
    if (count($data) < 2) return ['velocity'=>0.0,'acceleration'=>0.0,'jerk'=>0.0,'money_weight'=>0.0,'velocity_score'=>0.0];
    $velocities = [];
    for ($i=1;$i<count($data);$i++){
        $dt = max(1, $data[$i]['ts'] - $data[$i-1]['ts']);
        $v = ($data[$i]['sample'] - $data[$i-1]['sample']) / $dt;
        $velocities[] = $v;
    }
    $velocity = end($velocities);
    $acceleration = (count($velocities)>=2) ? ($velocity - $velocities[count($velocities)-2]) / max(1, $data[count($data)-1]['ts'] - $data[count($data)-2]['ts']) : 0.0;
    $mw = min(1.0, abs($velocity) * 200.0 + max(0.0, $acceleration)*50.0);
    $money_weight = clampf($mw * 100.0, 0.0, 100.0);
    $velocity_score = clampf(abs($velocity) * 1000.0, 0.0, 100.0);
    return ['velocity'=>$velocity,'acceleration'=>$acceleration,'jerk'=>0.0,'money_weight'=>$money_weight,'velocity_score'=>$velocity_score];
}

// ---------------- SFD (Shock & Spoof Detection) ----------------
function sfd_analyze(float $current_sample): array {
    $rows = get_last_samples('master_netflow', 12);
    $vals = array_map(function($r){ return floatval($r['sample']); }, $rows);
    $ts = array_map(function($r){ return intval($r['ts']); }, $rows);
    $spike_score = 0.0; $patterns = []; $spoof_probability = 0.0;
    if (count($vals) >= 3) {
        $deltas = [];
        for ($i=1;$i<count($vals);$i++){
            $d = $vals[$i] - $vals[$i-1];
            $dt = max(1, $ts[$i] - $ts[$i-1]);
            $rate = $d / $dt;
            $deltas[] = $rate;
        }
        foreach ($deltas as $r) {
            if (abs($r) > 0.02) { $spike_score += 20; $patterns[]='jump_spike'; }
            if (abs($r) > 0.01) { $spike_score += 8; $patterns[]='soft_spike'; }
        }
        $avg_rate = array_sum(array_map('abs',$deltas))/count($deltas);
        $spoof_probability = clampf(($spike_score/100.0) + ($avg_rate*5.0), 0.0, 1.0);
    }
    $spike_score = clampf($spike_score, 0.0, 100.0);
    return ['spike_score'=>$spike_score,'patterns'=>array_values(array_unique($patterns)),'spoof_probability'=>$spoof_probability];
}

// ---------------- OUCE (Cross-Market Inference) ----------------
function ouce_infer(array $ah_details, array $flow1): array {
    $score=0.0; $notes=[];
    foreach ($ah_details as $ad) {
        $hMove = ($ad['open_home'] ?? 0) - ($ad['now_home'] ?? 0);
        $aMove = ($ad['open_away'] ?? 0) - ($ad['now_away'] ?? 0);
        if (abs($hMove) > 0.08 && abs($flow1['home'] ?? 0) < 0.02) { $score += 0.18; $notes[]='AH moves but 1x2 stale (home)'; }
        if (abs($aMove) > 0.08 && abs($flow1['away'] ?? 0) < 0.02) { $score += 0.18; $notes[]='AH moves but 1x2 stale (away)'; }
    }
    $score = clampf($score, 0.0, 1.0);
    $confidence_drop = $score * 0.4;
    return ['ou_mismatch_score'=>$score,'confidence_drop'=>$confidence_drop,'notes'=>array_values(array_unique($notes))];
}

// ---------------- SMK 3.0 (Smart Money Killer) ----------------
function smk3_score(array $ah_details, array $flow1, float $juicePressureNorm, float $stackFactor, float $divergence) {
    $score = 0.0; $flags = [];
    if ($juicePressureNorm > 0.35) { $score += 0.35; $flags[] = 'juice_pressure'; }
    if ($stackFactor > 0.6) { $score += 0.25; $flags[] = 'stacked_lines'; }
    if ($divergence > 0.12) { $score += 0.12; $flags[] = 'divergence'; }
    foreach ($ah_details as $ad) {
        $ah_move = abs(($ad['open_home'] ?? 0) - ($ad['now_home'] ?? 0)) + abs(($ad['open_away'] ?? 0) - ($ad['now_away'] ?? 0));
        if ($ah_move > 0.12) { $score += 0.08; $flags[] = 'ah_strong_move'; }
    }
    $imbalance = abs(($flow1['home'] ?? 0) - ($flow1['away'] ?? 0));
    if ($imbalance > 0.12) { $score += 0.10; $flags[] = 'flow_imbalance'; }
    $score = clampf($score, 0.0, 1.0);
    return ['score'=>$score,'flags'=>array_values(array_unique($flags))];
}

// ---------------- FDA (Flow Divergence Analyzer) ----------------
function fda_analyze(array $ah_details, array $flow1, float $reboundSens): array {
    $angles = []; $intensity = 0.0; $patterns = [];
    foreach ($ah_details as $ad) {
        $hNet = $ad['net_home'] ?? 0; $aNet = $ad['net_away'] ?? 0;
        $home_diff = abs(($flow1['home'] ?? 0) - $hNet);
        $away_diff = abs(($flow1['away'] ?? 0) - $aNet);
        $ang = ($home_diff + $away_diff) / 2.0;
        $angles[] = $ang;
        $intensity += $ang;
        if (($hNet > 0 && ($flow1['home'] ?? 0) < 0.01) || ($aNet > 0 && ($flow1['away'] ?? 0) < 0.01)) {
            $patterns[] = 'AH_only_move';
        }
        if (($hNet > 0 && $aNet < 0) || ($hNet < 0 && $aNet > 0)) {
            $patterns[] = 'multi_direction';
        }
    }
    $avgAng = (count($angles)>0) ? ($intensity / count($angles)) : 0.0;
    $intensityScore = clampf($avgAng / 0.25, 0.0, 1.0) * 100.0;
    $phase = ($intensityScore < 20) ? 'tiny' : (($intensityScore < 50) ? 'minor' : (($intensityScore < 80)?'major':'critical'));
    return ['angle'=>$avgAng,'intensity'=>$intensityScore,'phase'=>$phase,'patterns'=>array_values(array_unique($patterns))];
}

// ---------------- LTME ----------------
function ltme_detect(array $ah_details, array $flow1, array $pairs, float $reboundSens): array {
    $sweep_detected=false; $sweep_side=null; $sweep_mag=0.0; $trap_zone=null; $trap_score=0.0; $trap_type=null;
    foreach ($ah_details as $ad) {
        $nh = $ad['now_home'] ?? NAN; $oh = $ad['open_home'] ?? NAN;
        $na = $ad['now_away'] ?? NAN; $oa = $ad['open_away'] ?? NAN;
        $nh_net = is_nan($oh)||is_nan($nh)?0.0:abs($oh-$nh);
        $na_net = is_nan($oa)||is_nan($na)?0.0:abs($oa-$na);
        if ($nh_net > $sweep_mag) { $sweep_mag = $nh_net; $sweep_side='home'; }
        if ($na_net > $sweep_mag) { $sweep_mag = $na_net; $sweep_side='away'; }
    }
    if (isset($flow1['home']) && abs($flow1['home']) > $sweep_mag) { $sweep_mag = abs($flow1['home']); $sweep_side='home'; }
    if (isset($flow1['away']) && abs($flow1['away']) > $sweep_mag) { $sweep_mag = abs($flow1['away']); $sweep_side='away'; }
    if ($sweep_mag > 0.10) $sweep_detected = true;
    $trap_score = clampf(($sweep_mag / 0.5) * 100.0, 0.0, 100.0);
    if ($sweep_detected) {
        $best=null; $best_move=-1;
        foreach ($ah_details as $ad) {
            $move = abs($ad['net_home'] ?? 0) + abs($ad['net_away'] ?? 0);
            if ($move > $best_move) { $best_move = $move; $best = $ad; }
        }
        if ($best) {
            $h_vals = [$best['open_home'],$best['now_home']]; $a_vals = [$best['open_away'],$best['now_away']];
            $minH = min($h_vals); $maxH = max($h_vals); $minA = min($a_vals); $maxA = max($a_vals);
            $trap_zone=['home'=>['min'=>$minH,'max'=>$maxH],'away'=>['min'=>$minA,'max'=>$maxA]];
        }
        $trap_type = ($sweep_side==='home')?'sweep_home':'sweep_away';
    }
    return ['sweep_detected'=>$sweep_detected,'sweep_side'=>$sweep_side,'sweep_mag'=>$sweep_mag,'trap_zone'=>$trap_zone,'trap_score'=>$trap_score,'trap_type'=>$trap_type];
}

// ---------------- SFR ----------------
function sfr_zone_calc(array $ah_details, array $flow1): array {
    $zones=[]; $best_zone=null; $best_conf=0.0;
    foreach ($ah_details as $ad) {
        $hRel = (!is_nan($ad['open_home']) && $ad['open_home']>0) ? (($ad['now_home'] - $ad['open_home']) / $ad['open_home']) : 0;
        $aRel = (!is_nan($ad['open_away']) && $ad['open_away']>0) ? (($ad['now_away'] - $ad['open_away']) / $ad['open_away']) : 0;
        $mom = ($ad['mom_home'] ?? 0) + ($ad['mom_away'] ?? 0);
        $conf = clampf(($mom / 0.2),0.0,1.0);
        $zoneH=null; $zoneA=null;
        if (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) $zoneH = ['min'=>min($ad['open_home'],$ad['now_home']),'max'=>max($ad['open_home'],$ad['now_home'])];
        if (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) $zoneA = ['min'=>min($ad['open_away'],$ad['now_away']),'max'=>max($ad['open_away'],$ad['now_away'])];
        $zones[]=['line'=>$ad['line'],'zoneH'=>$zoneH,'zoneA'=>$zoneA,'conf'=>$conf,'mom'=>$mom];
        if ($conf > $best_conf) { $best_conf=$conf; $best_zone=end($zones); }
    }
    return ['sfr_zone'=>$best_zone,'sfr_confidence'=>$best_conf];
}

// ---------------- VPE ----------------
function vpe_compute(float $agg_sample, array $lve): array {
    $base = clampf($agg_sample / 1.0, -1.0, 1.0);
    $mw = isset($lve['money_weight']) ? floatval($lve['money_weight'])/100.0 : 0.0;
    $bias = clampf($base * (0.6 + 0.4*$mw), -1.0, 1.0);
    $home_pct = round((0.5 + $bias/2.0) * 100.0,1);
    $away_pct = round(100.0 - $home_pct,1);
    return ['home'=>$home_pct,'away'=>$away_pct,'bias'=>$bias];
}

// ---------------- Void compress scoring ----------------
function void_compress_score(array $metrics): float {
    $w = [
        'flowPower' => 0.28,
        'confidence' => 0.20,
        'smartMoneyScore' => 0.18,
        'juicePressureNorm' => 0.12,
        'stackFactor' => 0.10,
        'divergence' => -0.12,
        'liquidity' => 0.10,
        'spike_penalty' => -0.08,
        'ou_mismatch' => -0.06,
        'ltme_trap' => -0.10
    ];
    $score = 0.0;
    foreach ($w as $k=>$wt) {
        $val = isset($metrics[$k]) ? floatval($metrics[$k]) : 0.0;
        if (in_array($k,['flowPower','confidence'])) $val = $val/100.0;
        if ($k==='divergence') $val = clampf($val/0.3,0.0,1.0);
        if ($k==='liquidity') $val = clampf($val/100.0,0.0,1.0);
        if ($k==='ltme_trap') $val = clampf($val/100.0,0.0,1.0);
        $score += $wt * $val;
    }
    if (!empty($metrics['trap'])) $score *= 0.28;
    return clampf($score, -1.0, 1.0);
}

// ---------------- HPC (Historical Pattern Corrector) ----------------
function hpc_correction(array $payload, array $res): float {
    global $pdo;
    if (!$pdo) return 0.0;
    $st = $pdo->prepare("SELECT res_json FROM results_history ORDER BY id DESC LIMIT 120");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0.0;
    $simScores=[];
    foreach ($rows as $r) {
        $past = json_decode($r['res_json'], true);
        if (!$past) continue;
        $pA = $past['metrics']['totalAH_mom'] ?? 0; $p1 = $past['metrics']['total1x2_mom'] ?? 0;
        $cA = $res['metrics']['totalAH_mom'] ?? 0; $c1 = $res['metrics']['total1x2_mom'] ?? 0;
        $dist = abs($pA - $cA) + abs($p1 - $c1);
        $sim = 1.0 / (1.0 + $dist);
        $simScores[] = $sim * (($past['final_label'] === $res['final_label']) ? 1.0 : 0.6);
    }
    if (count($simScores) === 0) return 0.0;
    $meanSim = array_sum($simScores)/count($simScores);
    $correction = clampf(($meanSim - 0.5) * 0.5, -0.2, 0.2);
    return $correction;
}

// ---------------- CER (Context Event Reasoner) ----------------
function cer_adjustment(array $context): array {
    $home_adj=0.0; $away_adj=0.0;
    $lineup = floatval($context['lineup_impact'] ?? 0.0);
    $injury = floatval($context['injury_impact'] ?? 0.0);
    $form_home = floatval($context['form_home'] ?? 0.0);
    $form_away = floatval($context['form_away'] ?? 0.0);
    $motivation = floatval($context['motivation'] ?? 0.0);
    $home_adj += clampf($lineup * 0.12, -0.15, 0.15);
    $home_adj -= clampf($injury * 0.10, -0.15, 0.15);
    $home_adj += clampf($form_home * 0.08, -0.12, 0.12);
    $home_adj += clampf($motivation * 0.06, -0.08, 0.08);
    $away_adj += clampf(-$lineup * 0.12, -0.15, 0.15);
    $away_adj -= clampf(-$injury * 0.10, -0.15, 0.15);
    $away_adj += clampf($form_away * 0.08, -0.12, 0.12);
    $away_adj += clampf(-$motivation * 0.06, -0.08, 0.08);
    $sum = $home_adj + $away_adj;
    if (abs($sum) > 0.0001) { $home_adj -= $sum/2; $away_adj -= $sum/2; }
    return ['home'=>$home_adj,'away'=>$away_adj];
}

// ---------------- ESP (Expected Score Projection) ----------------
function esp_compute(array $probabilities, array $context = []): array {
    $pH = max(0.0001, floatval($probabilities['home'] ?? 0.33));
    $pA = max(0.0001, floatval($probabilities['away'] ?? 0.33));
    $h_xg = max(0.1, -log(max(1e-6, 1 - $pH)) * 0.6);
    $a_xg = max(0.1, -log(max(1e-6, 1 - $pA)) * 0.6);
    $ctx = cer_adjustment($context);
    $h_xg *= (1.0 + $ctx['home']);
    $a_xg *= (1.0 + $ctx['away']);
    $home_expected = round($h_xg,2);
    $away_expected = round($a_xg,2);
    return ['home_xg'=>$home_expected,'away_xg'=>$away_expected,'expected_score'=>sprintf("%.2f - %.2f",$home_expected,$away_expected)];
}

// ---------------- Value Spotter ----------------
function value_spot(array $market_prob, array $true_prob): array {
    $edges = [
        'home' => ($true_prob['home'] ?? 0) - ($market_prob['home'] ?? 0),
        'away' => ($true_prob['away'] ?? 0) - ($market_prob['away'] ?? 0),
        'draw' => ($true_prob['draw'] ?? 0) - ($market_prob['draw'] ?? 0),
    ];
    $labels = [];
    foreach ($edges as $k=>$v) {
        if ($v > 0.05) $labels[$k] = 'Value Strong';
        elseif ($v > 0.02) $labels[$k] = 'Value';
        elseif ($v < -0.05) $labels[$k] = 'Overpriced Strong';
        elseif ($v < -0.02) $labels[$k] = 'Overpriced';
        else $labels[$k] = 'NoEdge';
    }
    $bestEdgeKey = array_search(max($edges), $edges);
    $bestEdge = $edges[$bestEdgeKey];
    $bet_type='NoBet'; $stake=0;
    if ($bestEdge > 0.08) { $bet_type='Aggressive'; $stake=5; }
    elseif ($bestEdge > 0.04) { $bet_type='Moderate'; $stake=2; }
    elseif ($bestEdge > 0.02) { $bet_type='Small'; $stake=1; }
    return ['edges'=>$edges,'labels'=>$labels,'best'=>$bestEdgeKey,'bestEdge'=>$bestEdge,'bet_type'=>$bet_type,'stake_pct'=>$stake];
}

// ---------------- Auto rebound ----------------
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
    $sList=[];
    foreach ($pairs as $p) {
        if (!isset($p['open'])||!isset($p['now'])) continue;
        $o=floatval($p['open']); $n=floatval($p['now']);
        if ($o>0 && $n>0) $sList[] = compute_auto_rebound_from_pair($o,$n);
    }
    if (count($sList)===0) return 0.025;
    return array_sum($sList)/count($sList);
}

// ---------------- CMF (Cross-Market Fusion) ----------------
function cmf_engine(array $input): array {
    // input contains market arrays: open1, now1, ah_details (line items with open/now)
    $open1 = $input['open1'] ?? []; $now1 = $input['now1'] ?? []; $ah = $input['ah'] ?? [];
    $score = 0.0; $notes = []; $risk = 0.0;
    // basic heuristics: check sync of directions between AH and 1x2
    foreach ($ah as $ad) {
        $oh = safeFloat($ad['open_home'] ?? null); $nh = safeFloat($ad['now_home'] ?? null);
        $oa = safeFloat($ad['open_away'] ?? null); $na = safeFloat($ad['now_away'] ?? null);
        $dirAH_H = dir_label($oh,$nh); $dirAH_A = dir_label($oa,$na);
        $dir1_H = dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
        $dir1_A = dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
        if ($dirAH_H === $dir1_H && $dirAH_A === $dir1_A) { $score += 0.18; }
        if ($dirAH_H !== $dir1_H || $dirAH_A !== $dir1_A) { $risk += 0.12; $notes[] = 'AH vs 1x2 mismatch on '.$ad['line']; }
    }
    // draw checks
    $open_draw = safeFloat($open1['draw'] ?? null); $now_draw = safeFloat($now1['draw'] ?? null);
    if (!is_nan($open_draw) && !is_nan($now_draw)) {
        $drawMove = abs($open_draw - $now_draw);
        if ($drawMove > 0.08) { $score += 0.08; $notes[]='Draw heavy move'; }
    }
    // normalize
    $score = clampf($score, 0.0, 1.0);
    $cmfScore = round($score * 100,1);
    $cmfRisk = round(clampf($risk,0.0,1.0)*100,1);
    $verdict = ($cmfScore>60 && $cmfRisk<40) ? 'Sync' : (($cmfRisk>45) ? 'Diverge' : 'Neutral');
    return ['cmfScore'=>$cmfScore,'cmfRisk'=>$cmfRisk,'verdict'=>$verdict,'notes'=>array_values(array_unique($notes))];
}

// ---------------- MMRA (Minute-by-Minute Reaction Analyzer) ----------------
function mmra_engine(array $input): array {
    // Uses recent samples (from EWMA samples) to compute short-term velocity and burst
    global $pdo;
    // collect last N samples for master_netflow
    $rows = [];
    if ($pdo) {
        $st = $pdo->prepare("SELECT sample,ts FROM samples WHERE k='master_netflow' ORDER BY id DESC LIMIT 16");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $vals = array_map(function($r){ return floatval($r['sample']); }, $rows);
    $ts = array_map(function($r){ return intval($r['ts']); }, $rows);
    $velocity = 0.0; $burst=false; $stability=1.0;
    if (count($vals) >= 2) {
        $deltas = [];
        for ($i=1;$i<count($vals);$i++){
            $dt = max(1, $ts[$i-1] ? ($ts[$i-1]) : 1);
            $d = $vals[$i-1] - $vals[$i];
            $deltas[] = $d;
        }
        $avg = array_sum($deltas)/count($deltas);
        $velocity = $avg;
        $maxDelta = max(array_map('abs',$deltas));
        if ($maxDelta > 0.08) $burst = true;
        $stability = clampf(1.0 - (array_sum(array_map('abs',$deltas))/max(1,count($deltas))/0.2), 0.0, 1.0);
    }
    $pulse = round(clampf(abs($velocity)*200.0 + ($burst?20:0), 0, 100),1);
    $reversalChance = round(clampf(($burst?30:5) + (1-$stability)*40, 0, 100),1);
    return ['pulse'=>$pulse,'burst'=>$burst,'stability'=>$stability,'reversalChance'=>$reversalChance];
}

// ---------------- SMAI (Smart Market AI Agent) ----------------
function smai_brain(array $systems): array {
    // systems: include void_score, cmf, mmra, smk, vpe, lve, sfd, ltme, sfr
    // Lightweight rule-based aggregator + heuristics
    $void = $systems['void_score'] ?? 0.0;
    $cmf = $systems['cmf'] ?? ['cmfScore'=>50,'cmfRisk'=>0,'verdict'=>'Neutral'];
    $mmra = $systems['mmra'] ?? ['pulse'=>10,'burst'=>false,'stability'=>1.0,'reversalChance'=>5];
    $smk = $systems['smk'] ?? ['score'=>0.0,'flags'=>[]];
    $vpe = $systems['vpe'] ?? ['home'=>50,'away'=>50,'bias'=>0.0];
    $ltme = $systems['ltme'] ?? ['sweep_detected'=>false,'sweep_side'=>null,'sweep_mag'=>0.0];
    $sfd = $systems['sfd'] ?? ['spike_score'=>0.0,'spoof_probability'=>0.0];
    $confidence = 50;
    // heuristics
    $confidence += ($cmf['cmfScore'] - 50) * 0.4;
    $confidence += ($smk['score'] * 100) * 0.3;
    $confidence += ($vpe['bias'] * 100) * 0.15;
    $confidence -= ($sfd['spoof_probability'] * 100) * 0.6;
    $confidence += ($mmra['pulse'] - 20) * 0.2;
    if ($ltme['sweep_detected']) $confidence += 15;
    $confidence = clampf($confidence, 0, 100);
    // verdict rules
    $verdict = 'Neutral';
    if ($void > 0.35 && $cmf['verdict'] === 'Sync' && $confidence > 65) $verdict = 'Sharp_Home';
    if ($void < -0.35 && $cmf['verdict'] === 'Sync' && $confidence > 65) $verdict = 'Sharp_Away';
    if ($sfd['spoof_probability'] > 0.45 || $cmf['cmfRisk'] > 55) $verdict = 'Likely_Trap';
    if ($mmra['burst'] && $confidence > 70 && ($void > 0.15 || $void < -0.15)) $verdict = 'Sharp_Burst';
    return ['smai_confidence'=>round($confidence,1),'smai_verdict'=>$verdict,'components'=>['cmf'=>$cmf,'mmra'=>$mmra,'smk'=>$smk,'vpe'=>$vpe,'ltme'=>$ltme,'sfd'=>$sfd]];
}

// ---------------- MAIN MASTER ANALYZE ----------------
function master_analyze(array $payload): array {
    global $pdo;
    $home = $payload['home'] ?? 'เหย้า'; $away = $payload['away'] ?? 'เยือน'; $favorite = $payload['favorite'] ?? 'none';
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? []; $context = $payload['context'] ?? [];

    // prepare AH details
    $pairs = []; $ah_details = []; $totalAH_mom = 0.0;
    foreach ($ah_list as $i => $r) {
        $line = $r['line'] ?? ('AH'.($i+1));
        $oh = safeFloat($r['open_home'] ?? null); $oa = safeFloat($r['open_away'] ?? null);
        $nh = safeFloat($r['now_home'] ?? null); $na = safeFloat($r['now_away'] ?? null);
        $mh = mom_abs($oh,$nh); $ma = mom_abs($oa,$na);
        if (!is_nan($mh)) $totalAH_mom += $mh; if (!is_nan($ma)) $totalAH_mom += $ma;
        $ad = ['index'=>$i,'line'=>$line,'open_home'=>$oh,'open_away'=>$oa,'now_home'=>$nh,'now_away'=>$na,'net_home'=>netflow($oh,$nh),'net_away'=>netflow($oa,$na),'mom_home'=>$mh,'mom_away'=>$ma,'dir_home'=>dir_label($oh,$nh),'dir_away'=>dir_label($oa,$na)];
        $ah_details[] = $ad;
        $pairs[] = ['open'=>$oh,'now'=>$nh]; $pairs[]=['open'=>$oa,'now'=>$na];
    }

    // 1x2 flows
    $flow1 = ['home'=> netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> netflow(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $mom1 = ['home'=> mom_abs(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> mom_abs(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> mom_abs(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $total1x2_mom = 0.0; foreach ($mom1 as $v) if (!is_nan($v)) $total1x2_mom += $v;
    $marketMomentum = $total1x2_mom + $totalAH_mom;

    // rebound sens
    $reboundSens = compute_auto_rebound_agg($pairs);

    // divergence & traps
    $divergence = abs($totalAH_mom - $total1x2_mom);
    $trap=false; $trapFlags=[];
    foreach (['home','draw','away'] as $s) {
        if (!is_nan(safeFloat($open1[$s] ?? null)) && !is_nan(safeFloat($now1[$s] ?? null))) {
            $rel = abs(safeFloat($now1[$s]) - safeFloat($open1[$s])) / max(0.0001, abs(safeFloat($open1[$s])));
            if ($rel <= $reboundSens) { $trapFlags[] = "bounce_1x2_{$s}"; $trap = true; }
        }
    }
    foreach ($ah_details as $ad) {
        if (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) {
            $relh = abs($ad['now_home'] - $ad['open_home']) / max(0.0001, abs($ad['open_home']));
            if ($relh <= $reboundSens) { $trapFlags[] = "bounce_AH_{$ad['line']}_H"; $trap = true; }
        }
        if (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) {
            $rela = abs($ad['now_away'] - $ad['open_away']) / max(0.0001, abs($ad['open_away']));
            if ($rela <= $reboundSens) { $trapFlags[] = "bounce_AH_{$ad['line']}_A"; $trap = true; }
        }
    }

    // juice pressure
    $juicePressure = 0.0;
    foreach ($ah_details as $ad) {
        $hj = (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) ? ($ad['open_home'] - $ad['now_home']) : 0.0;
        $aj = (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) ? ($ad['open_away'] - $ad['now_away']) : 0.0;
        $juicePressure += abs($hj)+abs($aj);
    }
    foreach (['home','away'] as $s) { $o=safeFloat($open1[$s]??null); $n=safeFloat($now1[$s]??null); if(!is_nan($o)&&!is_nan($n)) $juicePressure += abs($o-$n); }
    $juicePressureNorm = min(3.0, $juicePressure / max(0.02, $marketMomentum + 1e-9));

    // stack factor
    $stackHome=0;$stackAway=0;
    foreach ($ah_details as $ls) {
        $hRel = (!is_nan($ls['open_home']) && $ls['open_home']>0) ? (($ls['now_home'] - $ls['open_home']) / $ls['open_home']) : 0;
        $aRel = (!is_nan($ls['open_away']) && $ls['open_away']>0) ? (($ls['now_away'] - $ls['open_away']) / $ls['open_away']) : 0;
        if ($hRel < 0 || $aRel < 0) $stackHome++; if ($hRel > 0 || $aRel > 0) $stackAway++;
    }
    $stackMax = max($stackHome,$stackAway);
    $stackFactor = count($ah_details)>0 ? clampf($stackMax / max(1, count($ah_details)), 0.0, 1.0) : 0.0;

    // SMK & FDA
    $smk = smk3_score($ah_details, $flow1, $juicePressureNorm, $stackFactor, $divergence);
    $smartMoneyScore = $smk['score']; $smartFlags = $smk['flags'];
    $fda = fda_analyze($ah_details, $flow1, $reboundSens);

    // agg sample
    $agg_sample = 0.0;
    $agg_sample += (is_nan($flow1['home'])?0:$flow1['home']) * 2.0;
    $agg_sample -= (is_nan($flow1['away'])?0:$flow1['away']) * 2.0;
    foreach ($ah_details as $ad) $agg_sample += (($ad['net_home'] ?? 0) - ($ad['net_away'] ?? 0));

    // LVE, VPE
    $lve = lve_compute(floatval($agg_sample));
    $liquidity_weight = $lve['money_weight'];
    $vpe = vpe_compute(floatval($agg_sample), $lve);

    // SFD
    $sfd = sfd_analyze(floatval($agg_sample));
    $spike_penalty = $sfd['spike_score'];

    // OUCE
    $ouce = ouce_infer($ah_details, $flow1);
    $ou_mismatch = $ouce['ou_mismatch_score'];
    $confidence_drop = $ouce['confidence_drop'];

    // LTME & SFR
    $ltme = ltme_detect($ah_details, $flow1, $pairs, $reboundSens);
    $sfr = sfr_zone_calc($ah_details, $flow1);
    $ltme_trap_score = $ltme['trap_score'];

    // raw combine & direction
    $w_momentum = min(1.0, $marketMomentum / 1.0);
    $w_stack = min(1.0, $stackFactor);
    $w_juice = min(1.0, $juicePressureNorm / 1.2);
    $w_sync = 0.5;
    $rawSignal = (($w_momentum * 0.28) + ($w_stack * 0.20) + ($w_juice * 0.18) + ($w_sync * 0.12));
    if ($trap) $rawSignal *= 0.32;

    $dirScore = 0.0; $dirScore += (($flow1['home'] ?? 0) - ($flow1['away'] ?? 0)) * 0.5;
    $dirNorm = ($rawSignal > 1e-6) ? tanh($dirScore / (0.5 + $marketMomentum)) : 0.0;
    $hackScore = clampf($rawSignal * $dirNorm * 1.5, -1.0, 1.0);

    $confidence = round(min(100, max(0, abs($hackScore) * 120 + ($w_juice * 20) - ($confidence_drop*100))), 1);
    $flowPower = round(min(100, max(0, (abs($hackScore)*0.6 + $w_sync*0.2 + $w_juice*0.2 + $smartMoneyScore*0.15 + ($liquidity_weight/100.0)*0.12) * 100)));

    $signature = []; $market_kill=false;
    if ($flowPower >= 88 && $confidence >= 82 && $stackFactor > 0.65 && !$trap) { $market_kill=true; $signature[]='STACK+SHARP+HIGH_FLOW'; }
    if ($trap && $flowPower < 40) $signature[]='TRAP_DETECTED';
    if ($divergence > 0.22) $signature[]='ULTRA_DIV';
    if ($ltme['sweep_detected']) $signature[]='LTME_SWEEP';

    $metrics_for_void = [
        'flowPower'=>$flowPower,'confidence'=>$confidence,'smartMoneyScore'=>$smartMoneyScore,
        'juicePressureNorm'=>$juicePressureNorm,'stackFactor'=>$stackFactor,'divergence'=>$divergence,
        'trap'=>$trap,'liquidity'=>$liquidity_weight,'spike_penalty'=>$spike_penalty,'ou_mismatch'=>$ou_mismatch,
        'ltme_trap'=>$ltme_trap_score
    ];
    $void_score = void_compress_score($metrics_for_void);

    // market probs
    $io=[]; $in=[];
    foreach (['home','draw','away'] as $s) { $io[$s]=safeFloat($open1[$s] ?? NAN); $in[$s]=safeFloat($now1[$s] ?? NAN); }
    $market_imp_now=[]; $market_imp_open=[]; $sum_now=0; $sum_open=0;
    foreach (['home','draw','away'] as $s) {
        $pnow = (is_nan($in[$s])||$in[$s]<=0) ? 0.0 : (1.0 / $in[$s]);
        $popen = (is_nan($io[$s])||$io[$s]<=0) ? 0.0 : (1.0 / $io[$s]);
        $market_imp_now[$s]=$pnow; $market_imp_open[$s]=$popen; $sum_now+=$pnow; $sum_open+=$popen;
    }
    foreach (['home','draw','away'] as $s) { if ($sum_now>0) $market_imp_now[$s]/=$sum_now; if ($sum_open>0) $market_imp_open[$s]/=$sum_open; }

    // true probability (apply bias + vpe + hpc + cer)
    $true_prob = $market_imp_now;
    $bias = clampf($void_score, -0.25, 0.25);
    $true_prob['home'] = clampf($market_imp_now['home'] + $bias * 0.6 + ($vpe['home'] - 50)/100.0 * 0.1, 0.0001, 0.9999);
    $true_prob['away'] = clampf($market_imp_now['away'] - $bias * 0.6 + ($vpe['away'] - 50)/100.0 * 0.1, 0.0001, 0.9999);
    $sum_tp = $true_prob['home'] + $true_prob['away'];
    $true_prob['draw'] = max(0.0001, 1.0 - $sum_tp);
    $sum_tp2 = $true_prob['home'] + $true_prob['draw'] + $true_prob['away'];
    foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sum_tp2);

    // HPC correction
    $hpc_corr = hpc_correction($payload, ['metrics'=>array_merge($metrics_for_void,['total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom]), 'final_label'=>'temp']);
    $true_prob['home'] = clampf($true_prob['home'] * (1.0 + $hpc_corr), 0.0001, 0.9999);
    $true_prob['away'] = clampf($true_prob['away'] * (1.0 - $hpc_corr), 0.0001, 0.9999);
    $sum_tp3 = $true_prob['home'] + $true_prob['away'];
    $true_prob['draw'] = max(0.0001, 1.0 - $sum_tp3);
    $sum_tp4 = $true_prob['home'] + $true_prob['draw'] + $true_prob['away'];
    foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sum_tp4);

    // CER adjust
    $cer = cer_adjustment($context);
    $true_prob['home'] = clampf($true_prob['home'] * (1.0 + $cer['home']), 0.0001, 0.9999);
    $true_prob['away'] = clampf($true_prob['away'] * (1.0 + $cer['away']), 0.0001, 0.9999);
    $sum_tp5 = $true_prob['home'] + $true_prob['away'];
    $true_prob['draw'] = max(0.0001, 1.0 - $sum_tp5);
    $sum_tp6 = $true_prob['home'] + $true_prob['draw'] + $true_prob['away'];
    foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sum_tp6);

    // ESP & Value
    $esp = esp_compute($true_prob, $context);
    $value = value_spot($market_imp_now, $true_prob);

    // predicted winner
    $predicted_winner='ไม่แน่ใจ';
    if ($true_prob['home'] > $true_prob['away'] && $true_prob['home'] > $true_prob['draw']) $predicted_winner = $home;
    if ($true_prob['away'] > $true_prob['home'] && $true_prob['away'] > $true_prob['draw']) $predicted_winner = $away;

    // persist EWMA & samples
    ewma_update('master_netflow', floatval($agg_sample));
    ewma_update('master_voidscore', floatval($void_score));
    ewma_update('master_smk', floatval($smartMoneyScore));
    ewma_update('master_lve', floatval($lve['money_weight']));
    ewma_update('master_spike', floatval($spike_penalty));
    ewma_update('master_ltme', floatval($ltme_trap_score));
    ewma_update('master_vpe_home', floatval($vpe['home']));
    ewma_update('master_vpe_away', floatval($vpe['away']));

    // persist history
    if ($pdo) {
        $ins = $pdo->prepare("INSERT INTO results_history (payload,res_json,ts) VALUES(:p,:r,:t)");
        $ins->execute([':p'=>json_encode($payload, JSON_UNESCAPED_UNICODE),' :r'=>json_encode([]),' :t'=>time()]);
        $resForStore = ['metrics'=>$metrics_for_void,'void_score'=>$void_score,'final_label'=>'temp'];
        $stx = $pdo->prepare("UPDATE results_history SET res_json=:r WHERE id=(SELECT max(id) FROM results_history)");
        $stx->execute([':r'=>json_encode(['metrics'=>$metrics_for_void,'void_score'=>$void_score,'final_label'=>'temp'], JSON_UNESCAPED_UNICODE)]);
    }

    // CMF, MMRA, SMAI (advanced modules)
    $cmf = cmf_engine(['open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list]);
    $mmra = mmra_engine([]);
    $smai = smai_brain(['void_score'=>$void_score,'cmf'=>$cmf,'mmra'=>$mmra,'smk'=>$smk,'vpe'=>$vpe,'ltme'=>$ltme,'sfd'=>$sfd]);

    // final labeling
    if ($market_kill) { $final_label='💀 MARKET KILL — ห้ามเสี่ยง'; $recommendation='ห้ามเสี่ยง/จำกัดเดิมพัน'; }
    elseif ($void_score > 0.35) { $final_label='✅ ไหลจริง — ฝั่งเหย้า'; $recommendation='พิจารณาตาม (ควบคุมความเสี่ยง)'; }
    elseif ($void_score < -0.35) { $final_label='✅ ไหลจริง — ฝั่งเยือน'; $recommendation='พิจารณาตาม (ควบคุมความเสี่ยง)'; }
    elseif ($trap || $ltme_trap_score > 35) { $final_label='❌ พบกับดัก/หลอก'; $recommendation='ไม่แนะนำเดิมพัน'; }
    else { $final_label='⚠️ สัญญาณผสม — รอ confirm'; $recommendation='รอดูการยืนยัน'; }

    return [
        'status'=>'ok','input'=>['home'=>$home,'away'=>$away,'favorite'=>$favorite,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list,'context'=>$context],
        'metrics'=>array_merge($metrics_for_void,['marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,'reboundSens'=>$reboundSens,'flowPower'=>$flowPower,'confidence'=>$confidence,'signature'=>$signature,'trapFlags'=>$trapFlags,'fda'=>$fda,'smk_flags'=>$smartFlags,'lve'=>$lve,'sfd'=>$sfd,'ouce'=>$ouce,'ltme'=>$ltme,'vpe'=>$vpe,'sfr'=>$sfr,'cmf'=>$cmf,'mmra'=>$mmra,'smai'=>$smai]),
        'void_score'=>$void_score,'final_label'=>$final_label,'recommendation'=>$recommendation,'predicted_winner'=>$predicted_winner,
        'hackScore'=>$hackScore,'true_prob'=>$true_prob,'market_prob_now'=>$market_imp_now,'market_prob_open'=>$market_imp_open,'esp'=>$esp,'value'=>$value,'ah_details'=>$ah_details,'cmf'=>$cmf,'mmra'=>$mmra,'smai'=>$smai
    ];
}

// ---------------- compatibility quick analyze ----------------
function void_analyze(array $payload): array {
    return master_analyze($payload);
}

// ---------------- HTTP endpoints ----------------
if (php_sapi_name() !== 'cli') {
    if (isset($_GET['action']) && $_GET['action'] === 'master_analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input'); $payload = json_decode($raw, true);
        if (!is_array($payload)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>'invalid_payload','raw'=>substr($raw,0,200)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
        $res = master_analyze($payload); header('Content-Type: application/json; charset=utf-8'); echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'void_analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input'); $payload = json_decode($raw, true);
        if (!is_array($payload)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['status'=>'error','msg'=>'invalid_payload','raw'=>substr($raw,0,200)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
        $res = void_analyze($payload); header('Content-Type: application/json; charset=utf-8'); echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'ewma_stats') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$pdo) { echo json_encode(['status'=>'error','msg'=>'no_db']); exit; }
        $st = $pdo->query("SELECT * FROM ewma_store");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','ewma'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
}

// ---------------- FRONTEND UI ----------------
?><!doctype html>
<html lang="th">
<head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>LAST — VOID MASTER (Merged)</title>
<style>
:root{--gold:#d4a017;--bg1:#05040a;--bg2:#120713;--muted:#d9c89a}
*{box-sizing:border-box} body{margin:0;font-family:Inter,'Noto Sans Thai',sans-serif;background:linear-gradient(180deg,var(--bg1),var(--bg2));color:#fff}
.container{max-width:1200px;margin:18px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between}
.logo{width:80px;height:80px;border-radius:14px;background:radial-gradient(circle at 30% 20%, rgba(255,255,255,0.04), rgba(91,33,182,0.95));display:flex;align-items:center;justify-content:center;font-weight:900;font-size:34px;color:#fff}
.card{background:linear-gradient(145deg,#0c0606,#241316);border-radius:14px;padding:16px;margin-top:14px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
input,select{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.02);color:#fff}
.btn{padding:10px 14px;border-radius:10px;border:none;background:linear-gradient(90deg,#7e2aa3,var(--gold));cursor:pointer}
.dragon{position:absolute;right:-80px;top:-60px;width:420px;height:220px;pointer-events:none;opacity:0.9}
.ink{position:absolute;left:0;bottom:0;width:100%;height:180px;pointer-events:none;opacity:0.14}
@media(max-width:980px){.grid{grid-template-columns:1fr}}
.table{width:100%;border-collapse:collapse;margin-top:8px}.table th,.table td{padding:8px;border-bottom:1px dashed rgba(255,255,255,0.03);text-align:left}
.small{color:var(--muted);font-size:0.9rem}
.tome{margin-top:12px;padding:12px;border-radius:10px;background:linear-gradient(180deg,#0e0710,#231217);border:1px solid rgba(212,160,23,0.06);color:#ffdba1}
.badge{padding:6px 8px;border-radius:8px;background:linear-gradient(90deg,#ffb703,#d97706);color:#2b1708;font-weight:900;display:inline-block}
.kpi{font-weight:900;font-size:1.45rem;color:var(--gold);margin-bottom:6px;text-align:center}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div style="display:flex;gap:12px;align-items:center"><div class="logo">鴻</div><div><h1 style="margin:0;color:var(--gold)">LAST — VOID MASTER (Merged)</h1><div class="small">All engines integrated — LTME / SFR / VPE / SMK / FDA / LVE / SFD / HPC / CER / ESP / CMF / MMRA / SMAI</div></div></div>
    <div><button id="modeBtn" class="btn">切換 โหมด</button></div>
  </div>

  <div class="card">
    <form id="mainForm" onsubmit="return false;">
      <div class="grid">
        <div><label>ทีมเหย้า</label><input id="home" placeholder="ทีมเหย้า"></div>
        <div><label>ทีมเยือน</label><input id="away" placeholder="ทีมเยือน"></div>
        <div><label>ทีมต่อ</label><select id="favorite"><option value="none">ไม่ระบุ</option><option value="home">เหย้า</option><option value="away">เยือน</option></select></div>
      </div>

      <div style="height:12px"></div>
      <div class="card">
        <strong class="small">1X2 — ราคาเปิด</strong>
        <div class="grid" style="margin-top:8px">
          <div><label>เหย้า (open)</label><input id="open1_home" type="number" step="0.01"></div>
          <div><label>เสมอ (open)</label><input id="open1_draw" type="number" step="0.01"></div>
          <div><label>เยือน (open)</label><input id="open1_away" type="number" step="0.01"></div>
        </div>
        <div style="height:8px"></div>
        <strong class="small">1X2 — ราคาปัจจุบัน</strong>
        <div class="grid" style="margin-top:8px">
          <div><label>เหย้า (now)</label><input id="now1_home" type="number" step="0.01"></div>
          <div><label>เสมอ (now)</label><input id="now1_draw" type="number" step="0.01"></div>
          <div><label>เยือน (now)</label><input id="now1_away" type="number" step="0.01"></div>
        </div>
      </div>

      <div style="height:12px"></div>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong class="small">Asian Handicap — เพิ่ม/ลบได้</strong>
          <div><button id="addAhBtn" type="button" class="btn">+ เพิ่ม AH</button><button id="clearAhBtn" type="button" class="btn" style="background:transparent;border:1px solid rgba(255,255,255,0.04);color:#ffdca8">ล้าง</button></div>
        </div>
        <div id="ahContainer" style="margin-top:12px"></div>
      </div>

      <div style="height:12px"></div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div><label class="small">Context (optional)</label><div style="display:flex;gap:8px"><input id="ctx_lineup" placeholder="lineup -0..1"><input id="ctx_injury" placeholder="injury -0..1"></div></div>
        <div><button id="analyzeBtn" class="btn">🔎 วิเคราะห์</button></div>
      </div>
    </form>
  </div>

  <div id="resultWrap" class="card" style="display:none;position:relative;margin-top:14px">
    <div class="ink" id="inkWrap"></div>

    <div style="display:flex;gap:14px">
      <div style="flex:1;padding:12px">
        <div id="mainSummary"></div>
        <div id="detailTables" style="margin-top:12px"></div>
        <div id="tome" class="tome" style="margin-top:12px"></div>
      </div>
      <div style="width:340px;padding:12px">
        <div style="text-align:center">
          <div class="kpi" id="voidScore">--</div><div class="small">VOID Score</div>
          <div style="height:8px"></div>
          <div class="kpi" id="confidence">--%</div><div class="small">Confidence</div>
          <div style="height:8px"></div>
          <div id="vpeBlock" style="padding:8px;border-radius:8px;background:linear-gradient(90deg,rgba(212,160,23,0.04),rgba(126,226,199,0.02));color:#ffdca8">VPE H: <span id="vpeHome">--</span>% / VPE A: <span id="vpeAway">--</span>%</div>
          <div style="height:8px"></div>
          <div id="stakeSuggestion" style="padding:8px;border-radius:8px;color:#ffdca8"></div>
          <div style="height:8px"></div>
          <button id="ewmaBtn" class="btn">EWMA</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card small" style="margin-top:12px"><strong>หมายเหตุ</strong><div class="small">ระบบวิเคราะห์จากราคาที่ผู้ใช้กรอกเท่านั้น — EWMA learning เก็บบน server (SQLite)</div></div>
</div>

<script>
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function spawnInk(){ const w=document.getElementById('inkWrap'); w.innerHTML=''; const c=document.createElement('canvas'); c.width=w.clientWidth; c.height=160; w.appendChild(c); const ctx=c.getContext('2d'); for(let i=0;i<10;i++){ const x=Math.random()*c.width; const y=Math.random()*c.height; const r=10+Math.random()*50; ctx.fillStyle='rgba(11,8,8,'+(0.02+Math.random()*0.08)+')'; ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill(); for(let j=0;j<12;j++){ ctx.fillRect(x+(Math.random()-0.5)*r*3,y+(Math.random()-0.5)*r*3,Math.random()*8,Math.random()*8); } } }
document.addEventListener('DOMContentLoaded', ()=>{ if (!document.querySelectorAll('#ahContainer .ah-block').length) createAhBlock(); spawnInk(); });

const addAhBtn=document.getElementById('addAhBtn'), clearAhBtn=document.getElementById('clearAhBtn'), ahContainer=document.getElementById('ahContainer');
function createAhBlock(data={}){ const div=document.createElement('div'); div.className='ah-block'; div.style="background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.00));padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);margin-bottom:10px"; div.innerHTML=`
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
    <div><label>AH line</label><input name="ah_line" placeholder="เช่น 0, +0.25, -0.5" value="${data.line||''}"></div>
    <div><label>เปิด (เหย้า)</label><input name="ah_open_home" type="number" step="0.01" value="${data.open_home||''}"></div>
    <div><label>เปิด (เยือน)</label><input name="ah_open_away" type="number" step="0.01" value="${data.open_away||''}"></div>
  </div>
  <div style="height:8px"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:8px">
    <div><label>ตอนนี้ (เหย้า)</label><input name="ah_now_home" type="number" step="0.01" value="${data.now_home||''}"></div>
    <div><label>ตอนนี้ (เยือน)</label><input name="ah_now_away" type="number" step="0.01" value="${data.now_away||''}"></div>
    <div style="align-self:end;text-align:right"><button type="button" class="btn remove">ลบ</button></div>
  </div>`; ahContainer.appendChild(div); div.querySelector('.remove').addEventListener('click', ()=>div.remove()); }
addAhBtn.addEventListener('click', ()=>createAhBlock());
clearAhBtn.addEventListener('click', ()=>{ ahContainer.innerHTML=''; createAhBlock(); });

function collectPayload(){ const home=document.getElementById('home').value.trim()||'เหย้า'; const away=document.getElementById('away').value.trim()||'เยือน'; const favorite=document.getElementById('favorite').value||'none'; const open1={home:toNum(document.getElementById('open1_home').value), draw:toNum(document.getElementById('open1_draw').value), away:toNum(document.getElementById('open1_away').value)}; const now1={home:toNum(document.getElementById('now1_home').value), draw:toNum(document.getElementById('now1_draw').value), away:toNum(document.getElementById('now1_away').value)}; const ahNodes=Array.from(document.querySelectorAll('#ahContainer .ah-block')); const ah=ahNodes.map(n=>({ line:n.querySelector('input[name=ah_line]').value, open_home:toNum(n.querySelector('input[name=ah_open_home]').value), open_away:toNum(n.querySelector('input[name=ah_open_away]').value), now_home:toNum(n.querySelector('input[name=ah_now_home]').value), now_away:toNum(n.querySelector('input[name=ah_now_away]').value) })); const ctx={lineup_impact:toNum(document.getElementById('ctx_lineup').value)||0, injury_impact:toNum(document.getElementById('ctx_injury').value)||0}; return {home,away,favorite,open1,now1,ah,context:ctx}; }

async function analyze(){ const payload=collectPayload(); document.getElementById('resultWrap').style.display='block'; document.getElementById('mainSummary').innerHTML='<div class="small">กำลังวิเคราะห์…</div>'; try{ const res=await fetch('?action=master_analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); const j=await res.json(); renderResult(j); spawnInk(); }catch(e){ document.getElementById('mainSummary').innerHTML='<div class="small">Fetch error: '+e.message+'</div>'; } }
document.getElementById('analyzeBtn').addEventListener('click', analyze);

function renderResult(r){ document.getElementById('voidScore').innerText = (r.void_score!==undefined)?r.void_score.toFixed(4):'--'; document.getElementById('confidence').innerText = r.metrics && r.metrics.confidence!==undefined ? (r.metrics.confidence+'%') : '--%'; document.getElementById('vpeHome').innerText = r.metrics && r.metrics.vpe ? r.metrics.vpe.home : '--'; document.getElementById('vpeAway').innerText = r.metrics && r.metrics.vpe ? r.metrics.vpe.away : '--';
  const main=document.getElementById('mainSummary'); let html=`<div style="display:flex;justify-content:space-between"><div><div style="font-weight:900;font-size:1.1rem;color:var(--gold)">${r.final_label}</div><div style="margin-top:6px"><strong>คำแนะนำ:</strong> ${r.recommendation}</div><div style="margin-top:6px"><strong>คาดการณ์:</strong> <strong>${r.predicted_winner}</strong></div></div><div><div class="small">Signature: ${r.metrics.signature? r.metrics.signature.join(', '):'-'}</div></div></div>`; main.innerHTML=html;
  const dt=document.getElementById('detailTables'); let dhtml='<strong>1X2</strong><table class="table"><thead><tr><th>ฝั่ง</th><th>Open</th><th>Now</th><th>MarketProb</th><th>TrueProb</th></tr></thead><tbody>'; ['home','draw','away'].forEach(side=>{ const o=r.input.open1[side]||'-'; const n=r.input.now1[side]||'-'; const mp = r.market_prob_now && r.market_prob_now[side] ? (r.market_prob_now[side].toFixed(4)) : '-'; const tp = r.true_prob && r.true_prob[side] ? (r.true_prob[side].toFixed(4)) : '-'; dhtml += `<tr><td>${side}</td><td>${o}</td><td>${n}</td><td>${mp}</td><td>${tp}</td></tr>`; }); dhtml += '</tbody></table>'; dhtml += '<div style="height:8px"></div><strong>AH Lines</strong><table class="table"><thead><tr><th>Line</th><th>Open H</th><th>Now H</th><th>Net H</th><th>Mom H</th><th>Dir H</th><th>Dir A</th></tr></thead><tbody>'; (r.ah_details||[]).forEach(ad=>{ dhtml += `<tr><td>${ad.line||'-'}</td><td>${ad.open_home||'-'}</td><td>${ad.now_home||'-'}</td><td>${ad.net_home===undefined?'-':ad.net_home}</td><td>${ad.mom_home===undefined?'-':ad.mom_home}</td><td>${ad.dir_home||'-'}</td><td>${ad.dir_away||'-'}</td></tr>`; }); dhtml += '</tbody></table>';
  if (r.metrics && r.metrics.sfd) dhtml += `<div class="small" style="margin-top:8px">SFD Spike: ${r.metrics.sfd.spike_score} Spoof:${(r.metrics.sfd.spoof_probability*100).toFixed(1)}%</div>`;
  if (r.metrics && r.metrics.ltme) { const lt=r.metrics.ltme; dhtml += `<div class="small" style="margin-top:8px">LTME TrapScore: ${lt.trap_score.toFixed(1)} Sweep:${lt.sweep_detected?lt.sweep_side+'('+lt.sweep_mag.toFixed(3)+')':'no'}</div>`; if (lt.trap_zone) dhtml += `<div class="small">Trap zone H:[${lt.trap_zone.home.min}-${lt.trap_zone.home.max}] A:[${lt.trap_zone.away.min}-${lt.trap_zone.away.max}]</div>`; }
  if (r.esp) dhtml += `<div class="small" style="margin-top:8px">ESP: ${r.esp.expected_score} (h_xg:${r.esp.home_xg}, a_xg:${r.esp.away_xg})</div>`;
  if (r.value) dhtml += `<div class="small" style="margin-top:8px">Value Best: ${r.value.best} (${r.value.bestEdge.toFixed(3)}) — ${r.value.bet_type} stake ${r.value.stake_pct}%</div>`;
  // CMF / MMRA / SMAI summary
  if (r.cmf) dhtml += `<div class="small" style="margin-top:8px">CMF: ${r.cmf.cmfScore} Risk:${r.cmf.cmfRisk} Verdict:${r.cmf.verdict}</div>`;
  if (r.mmra) dhtml += `<div class="small" style="margin-top:8px">MMRA Pulse: ${r.mmra.pulse} Burst:${r.mmra.burst} Reversal:${r.mmra.reversalChance}%</div>`;
  if (r.smai) dhtml += `<div class="small" style="margin-top:8px">SMAI Verdict: ${r.smai.smai_verdict} Conf:${r.smai.smai_confidence}%</div>`;
  dt.innerHTML=dhtml;
  const tome=document.getElementById('tome'); let t=`<h3 style="margin:0;color:var(--gold)">คัมภีร์ลับ</h3><div style="margin-top:8px">VoidScore:${r.void_score.toFixed(4)} Confidence:${r.metrics.confidence}% FlowPower:${r.metrics.flowPower}</div>`; t+=`<div style="margin-top:8px"><strong>CMF:</strong> ${JSON.stringify(r.cmf)}</div>`; t+=`<div style="margin-top:8px"><strong>SMAI:</strong> ${r.smai.smai_verdict} (${r.smai.smai_confidence}%)</div>`; t+=`<div style="margin-top:8px"><strong>Recommendation:</strong> ${r.recommendation}</div>`; tome.innerHTML=t;
  const stakeEl=document.getElementById('stakeSuggestion'); if (r.value && r.value.bet_type !== 'NoBet' && r.metrics.confidence > 55) { stakeEl.innerHTML = `<div style="font-weight:900;color:var(--gold)">${r.value.bet_type}: ${r.value.best} — stake ${r.value.stake_pct}%</div>`; } else { stakeEl.innerHTML = `<div class="small">ไม่แนะนำเดิมพันชัดเจน</div>`; }
}

document.getElementById('ewmaBtn').addEventListener('click', async ()=>{ const res = await fetch('?action=ewma_stats'); const j = await res.json(); alert(JSON.stringify(j, null, 2)); });
</script>
</body>
</html>
