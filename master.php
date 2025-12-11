<?php
declare(strict_types=1);
/**
 * master_engine.php
 * Football Master Engine v1.0 — Single-file (PHP + SQLite + HTML/JS)
 * - All analysis modules: RTOT, PFC, LTME, SFR, VPE, SMK3, FDA, LVE, SFD, HPC, CER, ESP, Value Spotter
 * - EWMA learning stored in SQLite (void_master.db)
 * - Input: JSON POST to ?action=master_analyze (or UI form)
 * - UI included (Chinese ancient theme)
 *
 * Requirements: PHP 7.4+, PDO SQLite extension
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$basedir = __DIR__;
$db_file = $basedir . '/void_master.db';

// ---------- SQLite Init ----------
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS ewma_store (k TEXT PRIMARY KEY, v REAL, alpha REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT, sample REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS results_history (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT, res_json TEXT, ts INTEGER)");
} catch (Exception $e) {
    error_log("DB init: " . $e->getMessage());
    $pdo = null;
}

// ---------- Helpers ----------
function safeFloat($v){ if(!isset($v)) return NAN; $s = trim((string)$v); if ($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s) ? floatval($s) : NAN; }
function netflow($open,$now){ if(is_nan($open) || is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n = netflow($open,$now); return is_nan($n) ? NAN : abs($n); }
function dir_label($open,$now){ if(is_nan($open) || is_nan($now)) return 'flat'; if ($now < $open) return 'down'; if ($now > $open) return 'up'; return 'flat'; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }
function nf($v,$d=4){ return (is_nan($v) || $v===null) ? '-' : number_format((float)$v,$d,'.',''); }

// ---------- EWMA ----------
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
function ewma_set_alpha(string $k, float $alpha) {
    global $pdo;
    if (!$pdo) return false;
    $alpha = max(0.01, min(0.9, $alpha));
    $cur = ewma_get($k);
    $st = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
    $st->execute([':k'=>$k,':v'=>$cur['v'],':a'=>$alpha,':t'=>time()]);
    return true;
}
function adaptive_alpha_tune(string $k) {
    global $pdo;
    if (!$pdo) return;
    $N = 40;
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

// ---------- LVE ----------
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

// ---------- SFD ----------
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

// ---------- OUCE ----------
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

// ---------- SMK 3.0 ----------
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

// ---------- FDA ----------
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

// ---------- LTME ----------
function ltme_detect(array $ah_details, array $flow1, array $pairs, float $reboundSens): array {
    $sweep_detected = false; $sweep_side = null; $sweep_mag = 0.0; $trap_zone = null; $trap_score = 0.0; $trap_type = null;
    foreach ($ah_details as $ad) {
        $nh = $ad['now_home'] ?? NAN; $oh = $ad['open_home'] ?? NAN;
        $na = $ad['now_away'] ?? NAN; $oa = $ad['open_away'] ?? NAN;
        $nh_net = is_nan($oh) || is_nan($nh) ? 0.0 : abs($oh - $nh);
        $na_net = is_nan($oa) || is_nan($na) ? 0.0 : abs($oa - $na);
        if ($nh_net > $sweep_mag) { $sweep_mag = $nh_net; $sweep_side = 'home'; }
        if ($na_net > $sweep_mag) { $sweep_mag = $na_net; $sweep_side = 'away'; }
    }
    if (isset($flow1['home']) && abs($flow1['home']) > $sweep_mag) { $sweep_mag = abs($flow1['home']); $sweep_side = 'home'; }
    if (isset($flow1['away']) && abs($flow1['away']) > $sweep_mag) { $sweep_mag = abs($flow1['away']); $sweep_side = 'away'; }
    if ($sweep_mag > 0.10) $sweep_detected = true;
    $trap_score = clampf(($sweep_mag / 0.5) * 100.0, 0.0, 100.0);
    if ($sweep_detected) {
        $best = null; $best_move = -1;
        foreach ($ah_details as $ad) {
            $move = abs($ad['net_home'] ?? 0) + abs($ad['net_away'] ?? 0);
            if ($move > $best_move) { $best_move = $move; $best = $ad; }
        }
        if ($best) {
            $h_vals = [$best['open_home'],$best['now_home']]; $a_vals = [$best['open_away'],$best['now_away']];
            $minH = min($h_vals); $maxH = max($h_vals);
            $minA = min($a_vals); $maxA = max($a_vals);
            $trap_zone = ['home'=>['min'=>$minH,'max'=>$maxH],'away'=>['min'=>$minA,'max'=>$maxA]];
        }
        $trap_type = ($sweep_side === 'home') ? 'sweep_home' : 'sweep_away';
    }
    return ['sweep_detected'=>$sweep_detected,'sweep_side'=>$sweep_side,'sweep_mag'=>$sweep_mag,'trap_zone'=>$trap_zone,'trap_score'=>$trap_score,'trap_type'=>$trap_type];
}

// ---------- SFR ----------
function sfr_zone_calc(array $ah_details, array $flow1): array {
    $zones = []; $best_zone = null; $best_conf = 0.0;
    foreach ($ah_details as $ad) {
        $hRel = (!is_nan($ad['open_home']) && $ad['open_home']>0) ? (($ad['now_home'] - $ad['open_home']) / $ad['open_home']) : 0;
        $aRel = (!is_nan($ad['open_away']) && $ad['open_away']>0) ? (($ad['now_away'] - $ad['open_away']) / $ad['open_away']) : 0;
        $mom = ($ad['mom_home'] ?? 0) + ($ad['mom_away'] ?? 0);
        $conf = clampf(($mom / 0.2),0.0,1.0);
        $zoneH = null; $zoneA = null;
        if (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) {
            $zoneH = ['min'=>min($ad['open_home'],$ad['now_home']),'max'=>max($ad['open_home'],$ad['now_home'])];
        }
        if (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) {
            $zoneA = ['min'=>min($ad['open_away'],$ad['now_away']),'max'=>max($ad['open_away'],$ad['now_away'])];
        }
        $zones[] = ['line'=>$ad['line'],'zoneH'=>$zoneH,'zoneA'=>$zoneA,'conf'=>$conf,'mom'=>$mom];
        if ($conf > $best_conf) { $best_conf = $conf; $best_zone = end($zones); }
    }
    return ['sfr_zone'=>$best_zone,'sfr_confidence'=>$best_conf];
}

// ---------- VPE ----------
function vpe_compute(float $agg_sample, array $lve): array {
    $base = clampf($agg_sample / 1.0, -1.0, 1.0);
    $mw = isset($lve['money_weight']) ? floatval($lve['money_weight'])/100.0 : 0.0;
    $bias = clampf($base * (0.6 + 0.4*$mw), -1.0, 1.0);
    $home_pct = round((0.5 + $bias/2.0) * 100.0,1);
    $away_pct = round(100.0 - $home_pct,1);
    return ['home'=>$home_pct,'away'=>$away_pct,'bias'=>$bias];
}

// ---------- Void compress scoring ----------
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
    foreach ($w as $k => $wt) {
        $val = isset($metrics[$k]) ? floatval($metrics[$k]) : 0.0;
        if (in_array($k,['flowPower','confidence'])) $val = $val / 100.0;
        if ($k === 'divergence') $val = clampf($val / 0.3, 0.0, 1.0);
        if ($k === 'liquidity') $val = clampf($val / 100.0, 0.0, 1.0);
        if ($k === 'ltme_trap') $val = clampf($val / 100.0, 0.0, 1.0);
        $score += $wt * $val;
    }
    if (!empty($metrics['trap'])) $score *= 0.28;
    return clampf($score, -1.0, 1.0);
}

// ---------- Historical Pattern Corrector (HPC) ----------
function hpc_correction(array $payload, array $res): float {
    // Baseline: if we have results_history, compute simple correction factor
    global $pdo;
    if (!$pdo) return 0.0;
    // simple heuristic: check similarity of AH moves and netflow magnitude with last 100 results
    $st = $pdo->prepare("SELECT res_json FROM results_history ORDER BY id DESC LIMIT 120");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0.0;
    $simScores = [];
    foreach ($rows as $r) {
        $past = json_decode($r['res_json'], true);
        if (!$past) continue;
        // compare magnitude of totalAH_mom and total1x2_mom
        $pA = $past['metrics']['totalAH_mom'] ?? 0; $p1 = $past['metrics']['total1x2_mom'] ?? 0;
        $cA = $res['metrics']['totalAH_mom'] ?? 0; $c1 = $res['metrics']['total1x2_mom'] ?? 0;
        $dist = abs($pA - $cA) + abs($p1 - $c1);
        $sim = 1.0 / (1.0 + $dist);
        $simScores[] = $sim * (($past['final_label'] === $res['final_label']) ? 1.0 : 0.6);
    }
    if (count($simScores) === 0) return 0.0;
    $meanSim = array_sum($simScores) / count($simScores);
    // convert similarity into correction: if similarity high, raise confidence
    $correction = clampf(($meanSim - 0.5) * 0.5, -0.2, 0.2);
    return $correction;
}

// ---------- CER (Context Event Reasoner) ----------
function cer_adjustment(array $context): array {
    // context is optional structure: lineup_impact, injury_impact, form_home, form_away, importance
    // return adjustment factors for home/away probability (-0.15..0.15)
    $home_adj = 0.0; $away_adj = 0.0;
    $lineup = floatval($context['lineup_impact'] ?? 0.0); // -1..1
    $injury = floatval($context['injury_impact'] ?? 0.0);
    $form_home = floatval($context['form_home'] ?? 0.0); // -1..1
    $form_away = floatval($context['form_away'] ?? 0.0);
    $motivation = floatval($context['motivation'] ?? 0.0); // -1..1
    // simple mapping
    $home_adj += clampf($lineup * 0.12, -0.15, 0.15);
    $home_adj -= clampf($injury * 0.10, -0.15, 0.15);
    $home_adj += clampf($form_home * 0.08, -0.12, 0.12);
    $home_adj += clampf($motivation * 0.06, -0.08, 0.08);
    $away_adj += clampf(-$lineup * 0.12, -0.15, 0.15);
    $away_adj -= clampf(-$injury * 0.10, -0.15, 0.15);
    $away_adj += clampf($form_away * 0.08, -0.12, 0.12);
    $away_adj += clampf(-$motivation * 0.06, -0.08, 0.08);
    // normalize to keep sum near zero
    $sum = $home_adj + $away_adj;
    if (abs($sum) > 0.0001) { $home_adj -= $sum/2; $away_adj -= $sum/2; }
    return ['home'=>$home_adj,'away'=>$away_adj];
}

// ---------- ESP (Expected Score Projection) ----------
function esp_compute(array $probabilities, array $context = []): array {
    // probabilities: ['home'=>pH,'away'=>pA,'draw'=>pD] sum~1
    // A simple heuristic mapping to xG-like expected goals
    $pH = max(0.0001, floatval($probabilities['home'] ?? 0.33));
    $pA = max(0.0001, floatval($probabilities['away'] ?? 0.33));
    $pD = max(0.0001, floatval($probabilities['draw'] ?? 0.33));
    // baseline xG: scaled by log-odds
    $h_xg = max(0.1, -log(max(1e-6, 1 - $pH)) * 0.6);
    $a_xg = max(0.1, -log(max(1e-6, 1 - $pA)) * 0.6);
    // apply context small tweaks
    $ctx = cer_adjustment($context);
    $h_xg *= (1.0 + $ctx['home']);
    $a_xg *= (1.0 + $ctx['away']);
    // expected score approximate (Poisson mean)
    $home_expected = round($h_xg,2);
    $away_expected = round($a_xg,2);
    return ['home_xg'=>$home_expected,'away_xg'=>$away_expected,'expected_score'=>sprintf("%.2f - %.2f",$home_expected,$away_expected)];
}

// ---------- Value Spotter ----------
function value_spot(array $market_prob, array $true_prob): array {
    // market_prob & true_prob: both normalized (sum ~1)
    // return value edges and categorization
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
    // recommend No Bet if no edges and market volatile
    $bestEdgeKey = array_search(max($edges), $edges);
    $bestEdge = $edges[$bestEdgeKey];
    $bet_type = 'NoBet';
    $stake = 0;
    if ($bestEdge > 0.08) { $bet_type = 'Aggressive'; $stake = 5; }
    elseif ($bestEdge > 0.04) { $bet_type = 'Moderate'; $stake = 2; }
    elseif ($bestEdge > 0.02) { $bet_type = 'Small'; $stake = 1; }
    return ['edges'=>$edges,'labels'=>$labels,'best'=>$bestEdgeKey,'bestEdge'=>$bestEdge,'bet_type'=>$bet_type,'stake_pct'=>$stake];
}

// ---------- Auto rebound ----------
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
    $sList = [];
    foreach ($pairs as $p) {
        if (!isset($p['open']) || !isset($p['now'])) continue;
        $o = floatval($p['open']); $n = floatval($p['now']);
        if ($o>0 && $n>0) $sList[] = compute_auto_rebound_from_pair($o,$n);
    }
    if (count($sList)===0) return 0.025;
    return array_sum($sList)/count($sList);
}

// ---------- Main Analyze: master_analyze ----------
function master_analyze(array $payload): array {
    global $pdo;
    $home = $payload['home'] ?? 'เหย้า';
    $away = $payload['away'] ?? 'เยือน';
    $favorite = $payload['favorite'] ?? 'none';
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];
    $context = $payload['context'] ?? [];

    // build AH details
    $ah_details = []; $totalAH_mom = 0.0;
    $pairs = [];
    foreach ($ah_list as $i => $r) {
        $line = $r['line'] ?? ('AH'.($i+1));
        $oh = safeFloat($r['open_home'] ?? null);
        $oa = safeFloat($r['open_away'] ?? null);
        $nh = safeFloat($r['now_home'] ?? null);
        $na = safeFloat($r['now_away'] ?? null);
        $mh = mom_abs($oh,$nh); $ma = mom_abs($oa,$na);
        if (!is_nan($mh)) $totalAH_mom += $mh;
        if (!is_nan($ma)) $totalAH_mom += $ma;
        $ad = [
            'index'=>$i,'line'=>$line,
            'open_home'=>$oh,'open_away'=>$oa,'now_home'=>$nh,'now_away'=>$na,
            'net_home'=>netflow($oh,$nh),'net_away'=>netflow($oa,$na),'mom_home'=>$mh,'mom_away'=>$ma,
            'dir_home'=>dir_label($oh,$nh),'dir_away'=>dir_label($oa,$na)
        ];
        $ah_details[] = $ad;
        $pairs[] = ['open'=>$oh,'now'=>$nh];
        $pairs[] = ['open'=>$oa,'now'=>$na];
    }

    // 1x2 flows
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

    // rebound
    $reboundSens = compute_auto_rebound_agg($pairs);

    // divergence & trap flags
    $divergence = abs($totalAH_mom - $total1x2_mom);
    $trap = false; $trapFlags=[];
    foreach (['home','draw','away'] as $s) {
        if (!is_nan(safeFloat($open1[$s] ?? null)) && !is_nan(safeFloat($now1[$s] ?? null))) {
            $rel = abs(safeFloat($now1[$s] ?? null) - safeFloat($open1[$s] ?? null)) / max(0.0001, abs(safeFloat($open1[$s] ?? null)));
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
        $juicePressure += abs($hj) + abs($aj);
    }
    foreach (['home','away'] as $s) { $o=safeFloat($open1[$s]??null); $n=safeFloat($now1[$s]??null); if(!is_nan($o)&&!is_nan($n)) $juicePressure += abs($o-$n); }
    $juicePressureNorm = min(3.0, $juicePressure / max(0.02, $marketMomentum + 1e-9));

    // stack factor
    $stackHome=0;$stackAway=0;
    foreach($ah_details as $ls){
        $hRel = (!is_nan($ls['open_home']) && $ls['open_home']>0) ? (($ls['now_home'] - $ls['open_home']) / $ls['open_home']) : 0;
        $aRel = (!is_nan($ls['open_away']) && $ls['open_away']>0) ? (($ls['now_away'] - $ls['open_away']) / $ls['open_away']) : 0;
        if ($hRel < 0 || $aRel < 0) $stackHome++;
        if ($hRel > 0 || $aRel > 0) $stackAway++;
    }
    $stackMax = max($stackHome,$stackAway);
    $stackFactor = count($ah_details)>0 ? clampf($stackMax / max(1, count($ah_details)), 0.0, 1.0) : 0.0;

    // SMK, FDA
    $smk = smk3_score($ah_details, $flow1, $juicePressureNorm, $stackFactor, $divergence);
    $smartMoneyScore = $smk['score']; $smartFlags = $smk['flags'];
    $fda = fda_analyze($ah_details, $flow1, $reboundSens);

    // agg sample
    $agg_sample = 0.0;
    $agg_sample += (is_nan($flow1['home'])?0:$flow1['home']) * 2.0;
    $agg_sample -= (is_nan($flow1['away'])?0:$flow1['away']) * 2.0;
    foreach ($ah_details as $ad) { $agg_sample += (($ad['net_home'] ?? 0) - ($ad['net_away'] ?? 0)); }

    // LVE & VPE
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
    $ltme_trap_score = $ltme['trap_score'];
    $ltme_trap_zone = $ltme['trap_zone'];
    $ltme_trap_type = $ltme['trap_type'];
    $sfr = sfr_zone_calc($ah_details, $flow1);

    // raw signal combine
    $w_momentum = min(1.0, $marketMomentum / 1.0);
    $w_stack = min(1.0, $stackFactor);
    $w_juice = min(1.0, $juicePressureNorm / 1.2);
    $w_sync = 0.5;
    $rawSignal = (
        ($w_momentum * 0.28) +
        ($w_stack * 0.20) +
        ($w_juice * 0.18) +
        ($w_sync * 0.12)
    );
    if ($trap) $rawSignal *= 0.32;

    // direction approx
    $dirScore = 0.0; $dirScore += (($flow1['home'] ?? 0) - ($flow1['away'] ?? 0)) * 0.5;
    $dirNorm = ($rawSignal > 1e-6) ? tanh($dirScore / (0.5 + $marketMomentum)) : 0.0;
    $hackScore = clampf($rawSignal * $dirNorm * 1.5, -1.0, 1.0);

    // confidence & flowPower
    $confidence = round(min(100, max(0, abs($hackScore) * 120 + ($w_juice * 20) - ($confidence_drop*100))), 1);
    $flowPower = round(min(100, max(0, (abs($hackScore)*0.6 + $w_sync*0.2 + $w_juice*0.2 + $smartMoneyScore*0.15 + ($liquidity_weight/100.0)*0.12) * 100)));

    // market kill detection & signatures
    $signature = []; $market_kill=false;
    if ($flowPower >= 88 && $confidence >= 82 && $stackFactor > 0.65 && !$trap) { $market_kill = true; $signature[]='STACK+SHARP+HIGH_FLOW'; }
    if ($trap && $flowPower < 40) { $signature[]='TRAP_DETECTED'; }
    if ($divergence > 0.22) { $signature[]='ULTRA_DIV'; }
    if ($ltme['sweep_detected']) { $signature[]='LTME_SWEEP'; }

    // void score
    $metrics_for_void = [
        'flowPower'=>$flowPower,'confidence'=>$confidence,'smartMoneyScore'=>$smartMoneyScore,
        'juicePressureNorm'=>$juicePressureNorm,'stackFactor'=>$stackFactor,'divergence'=>$divergence,
        'trap'=>$trap,'liquidity'=>$liquidity_weight,'spike_penalty'=>$spike_penalty,'ou_mismatch'=>$ou_mismatch,
        'ltme_trap'=>$ltme_trap_score
    ];
    $void_score = void_compress_score($metrics_for_void);

    // probabilities: RTOT (1/odds) normalized
    $io = []; $in = [];
    foreach (['home','draw','away'] as $s) { $io[$s] = safeFloat($open1[$s] ?? NAN); $in[$s] = safeFloat($now1[$s] ?? NAN); }
    $market_imp_now = []; $market_imp_open = [];
    $sum_now=0;$sum_open=0;
    foreach (['home','draw','away'] as $s) {
        $pnow = (is_nan($in[$s])||$in[$s]<=0) ? 0.0 : (1.0 / $in[$s]);
        $popen = (is_nan($io[$s])||$io[$s]<=0) ? 0.0 : (1.0 / $io[$s]);
        $market_imp_now[$s]=$pnow; $market_imp_open[$s]=$popen; $sum_now+=$pnow; $sum_open+=$popen;
    }
    foreach (['home','draw','away'] as $s) { if ($sum_now>0) $market_imp_now[$s]/=$sum_now; if ($sum_open>0) $market_imp_open[$s]/=$sum_open; }

    // true probability (placeholder model: combine EWMA corrected void_score & VPE)
    // We'll map void_score (-1..1) to a bias towards the direction
    $true_prob = $market_imp_now;
    // bias from void_score
    $bias = clampf($void_score, -0.25, 0.25);
    $true_prob['home'] = clampf($market_imp_now['home'] + $bias * 0.6 + ($vpe['home'] - 50)/100.0 * 0.1, 0.0001, 0.9999);
    $true_prob['away'] = clampf($market_imp_now['away'] - $bias * 0.6 + ($vpe['away'] - 50)/100.0 * 0.1, 0.0001, 0.9999);
    // adjust draw to keep sum=1
    $sum_tp = $true_prob['home'] + $true_prob['away'];
    $true_prob['draw'] = max(0.0001, 1.0 - $sum_tp);
    // normalize
    $sum_tp2 = $true_prob['home']+$true_prob['draw']+$true_prob['away'];
    foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sum_tp2);

    // HPC correction
    $hpc_corr = hpc_correction($payload, ['metrics'=>array_merge($metrics_for_void,['total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom]), 'final_label'=>'temp']);
    // apply small correction to true_prob
    $true_prob['home'] = clampf($true_prob['home'] * (1.0 + $hpc_corr), 0.0001, 0.9999);
    $true_prob['away'] = clampf($true_prob['away'] * (1.0 - $hpc_corr), 0.0001, 0.9999);
    $sum_tp3 = $true_prob['home'] + $true_prob['away'];
    $true_prob['draw'] = max(0.0001, 1.0 - $sum_tp3);
    $sum_tp4 = $true_prob['home']+$true_prob['draw']+$true_prob['away'];
    foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sum_tp4);

    // apply CER (context)
    $cer = cer_adjustment($context);
    $true_prob['home'] = clampf($true_prob['home'] * (1.0 + $cer['home']), 0.0001, 0.9999);
    $true_prob['away'] = clampf($true_prob['away'] * (1.0 + $cer['away']), 0.0001, 0.9999);
    $sum_tp5 = $true_prob['home'] + $true_prob['away'];
    $true_prob['draw'] = max(0.0001, 1.0 - $sum_tp5);
    $sum_tp6 = $true_prob['home']+$true_prob['draw']+$true_prob['away'];
    foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sum_tp6);

    // ESP
    $esp = esp_compute($true_prob, $context);

    // Value spotter
    $value = value_spot($market_imp_now, $true_prob);

    // predicted winner
    $predicted_winner = 'ไม่แน่ใจ'; if ($true_prob['home'] > $true_prob['away'] && $true_prob['home'] > $true_prob['draw']) $predicted_winner = $home;
    if ($true_prob['away'] > $true_prob['home'] && $true_prob['away'] > $true_prob['draw']) $predicted_winner = $away;

    // store samples (EWMA)
    ewma_update('master_netflow', floatval($agg_sample));
    ewma_update('master_voidscore', floatval($void_score));
    ewma_update('master_smk', floatval($smartMoneyScore));
    ewma_update('master_lve', floatval($lve['money_weight']));
    ewma_update('master_spike', floatval($spike_penalty));
    ewma_update('master_ltme', floatval($ltme_trap_score));
    ewma_update('master_vpe_home', floatval($vpe['home']));
    ewma_update('master_vpe_away', floatval($vpe['away']));

    // persist history (short)
    if ($pdo) {
        $ins = $pdo->prepare("INSERT INTO results_history (payload,res_json,ts) VALUES(:p,:r,:t)");
        $ins->execute([':p'=>json_encode($payload, JSON_UNESCAPED_UNICODE),' :r'=>json_encode([]),' :t'=>time()]);
        // update last row with proper JSON (lightweight)
        $resForStore = ['metrics'=>array_merge($metrics_for_void,['total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom]),'final_label'=>'temp'];
        $stx = $pdo->prepare("UPDATE results_history SET res_json=:r WHERE id=(SELECT max(id) FROM results_history)");
        $stx->execute([':r'=>json_encode(['metrics'=>$metrics_for_void,'void_score'=>$void_score,'final_label'=>'temp'], JSON_UNESCAPED_UNICODE)]);
    }

    // final label & recommendation
    if ($market_kill) {
        $final_label = '💀 MARKET KILL — ห้ามเสี่ยง';
        $recommendation = 'ห้ามเสี่ยง/จำกัดเดิมพัน';
    } elseif ($void_score > 0.35) {
        $final_label = '✅ ไหลจริง — ฝั่งเหย้า';
        $recommendation = 'พิจารณาตาม (ควบคุมความเสี่ยง)';
    } elseif ($void_score < -0.35) {
        $final_label = '✅ ไหลจริง — ฝั่งเยือน';
        $recommendation = 'พิจารณาตาม (ควบคุมความเสี่ยง)';
    } elseif ($trap || $ltme_trap_score > 35) {
        $final_label = '❌ พบกับดัก/หลอก';
        $recommendation = 'ไม่แนะนำเดิมพัน';
    } else {
        $final_label = '⚠️ สัญญาณผสม — รอ confirm';
        $recommendation = 'รอดูการยืนยัน';
    }

    $resp = [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'favorite'=>$favorite,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list,'context'=>$context],
        'metrics'=>array_merge($metrics_for_void,['marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,'reboundSens'=>$reboundSens,'flowPower'=>$flowPower,'confidence'=>$confidence,'signature'=>$signature,'trapFlags'=>$trapFlags,'fda'=>$fda,'smk_flags'=>$smartFlags,'lve'=>$lve,'sfd'=>$sfd,'ouce'=>$ouce,'ltme'=>$ltme,'vpe'=>$vpe,'sfr'=>$sfr]),
        'void_score'=>$void_score,
        'final_label'=>$final_label,
        'recommendation'=>$recommendation,
        'predicted_winner'=>$predicted_winner,
        'hackScore'=>$hackScore,
        'true_prob'=>$true_prob,
        'market_prob_now'=>$market_imp_now,
        'market_prob_open'=>$market_imp_open,
        'esp'=>$esp,
        'value'=>$value,
        'ah_details'=>$ah_details
    ];
    return $resp;
}

// ---------- HTTP endpoints ----------
if (php_sapi_name() !== 'cli') {
    if (isset($_GET['action']) && $_GET['action'] === 'master_analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'error','msg'=>'invalid_payload','raw'=>substr($raw,0,200)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
            exit;
        }
        $res = master_analyze($payload);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'ewma_stats') {
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');
        if (!$pdo) { echo json_encode(['status'=>'error','msg'=>'no_db']); exit; }
        $st = $pdo->query("SELECT * FROM ewma_store");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','ewma'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }
}

// ---------- UI ----------
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Football Master Engine v1.0 — หงหยาต้ง (Chinese Imperial UX)</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
:root{
  --bg1:#07050a; --bg2:#120713; --gold:#d4a017; --muted:#d9c89a; --accent:#c026d3;
  --card-grad: linear-gradient(145deg,#12060a,#2b1513);
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter, 'Noto Sans Thai', 'Noto Serif', system-ui;background:linear-gradient(180deg,var(--bg1),var(--bg2));color:#fff}
.container{max-width:1200px;margin:16px auto;padding:16px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.logo{width:96px;height:96px;border-radius:16px;background:radial-gradient(circle at 30% 20%, rgba(255,255,255,0.04), rgba(91,33,182,0.95));display:flex;align-items:center;justify-content:center;font-weight:900;font-size:40px;color:#fff;box-shadow:0 30px 80px rgba(0,0,0,0.6)}
.card{background:var(--card-grad);border-radius:14px;padding:16px;margin-top:14px;border:1px solid rgba(212,160,23,0.06)}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
label{font-size:0.95rem;color:var(--muted)}
input, select, textarea{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.02);color:#fff}
.btn{padding:10px 14px;border-radius:12px;border:none;color:#110b06;background:linear-gradient(90deg,#7e2aa3,var(--gold));cursor:pointer;box-shadow:0 8px 30px rgba(0,0,0,0.5)}
.controls .btn{margin-left:10px}
.resultWrap{display:flex;gap:14px;align-items:flex-start;margin-top:14px}
.analysisCard{flex:1;padding:18px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));position:relative;overflow:visible}
.sidePanel{width:360px;padding:14px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.00))}
.dragon{position:absolute;right:-80px;top:-60px;width:420px;height:220px;pointer-events:none;opacity:0.95;mix-blend-mode:screen}
.ink{position:absolute;left:0;bottom:0;width:100%;height:200px;pointer-events:none;opacity:0.14}
.tome{margin-top:12px;padding:12px;border-radius:10px;background:linear-gradient(180deg,#0e0710,#231217);border:1px solid rgba(212,160,23,0.06);color:#ffdba1}
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th,.table td{padding:8px;border-bottom:1px dashed rgba(255,235,200,0.03);text-align:left;color:#f8eedf;font-size:0.95rem}
.kpi{font-weight:900;font-size:1.45rem;color:var(--gold);margin-bottom:6px;text-align:center}
.small{color:#e6d5a8;font-size:0.9rem}
.badge{padding:6px 8px;border-radius:8px;background:linear-gradient(90deg,#ffb703,#d97706);color:#2b1708;font-weight:900;display:inline-block}
.muted{color:#d7caa3}
@media(max-width:980px){.grid{grid-template-columns:1fr}.sidePanel{width:100%}.dragon{display:none}}
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="logo">鴻</div>
        <div>
          <h1 style="margin:0;color:var(--gold)">Football Master Engine v1.0 — หงหยาต้ง</h1>
          <div class="small muted">LTME • SFR • VPE • SMK3 • FDA • LVE • SFD • HPC • CER • ESP</div>
        </div>
      </div>
      <div><button id="themeBtn" class="btn">โหมด: จีนโบราณ</button></div>
    </div>

    <div class="card" role="form">
      <form id="mainForm" onsubmit="return false;">
        <div class="grid">
          <div><label>ทีมเหย้า</label><input id="home" placeholder="ทีมเหย้า"></div>
          <div><label>ทีมเยือน</label><input id="away" placeholder="ทีมเยือน"></div>
          <div><label>ทีมต่อ (SBOBET)</label><select id="favorite"><option value="none">ไม่ระบุ</option><option value="home">เหย้า</option><option value="away">เยือน</option></select></div>
        </div>

        <div style="height:12px"></div>

        <div class="card">
          <strong class="small">1X2 — ราคาเปิด</strong>
          <div class="grid" style="margin-top:8px">
            <div><label>เหย้า (open)</label><input id="open1_home" type="number" step="0.01" placeholder="2.10"></div>
            <div><label>เสมอ (open)</label><input id="open1_draw" type="number" step="0.01" placeholder="3.40"></div>
            <div><label>เยือน (open)</label><input id="open1_away" type="number" step="0.01" placeholder="3.10"></div>
          </div>
          <div style="height:8px"></div>
          <strong class="small">1X2 — ราคาปัจจุบัน</strong>
          <div class="grid" style="margin-top:8px">
            <div><label>เหย้า (now)</label><input id="now1_home" type="number" step="0.01" placeholder="1.95"></div>
            <div><label>เสมอ (now)</label><input id="now1_draw" type="number" step="0.01" placeholder="3.60"></div>
            <div><label>เยือน (now)</label><input id="now1_away" type="number" step="0.01" placeholder="3.80"></div>
          </div>
        </div>

        <div style="height:12px"></div>

        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <strong class="small">Asian Handicap — เพิ่ม/ลบได้ (หลายราคา)</strong>
            <div class="controls"><button id="addAhBtn" type="button" class="btn">+ เพิ่ม AH</button><button id="clearAhBtn" type="button" class="btn" style="background:transparent;border:1px solid rgba(255,255,255,0.06);color:var(--gold)">ล้าง</button></div>
          </div>
          <div id="ahContainer" style="margin-top:12px"></div>
        </div>

        <div style="height:12px"></div>

        <div style="display:flex;justify-content:space-between;align-items:center">
          <div>
            <label class="small muted">Context (optional)</label>
            <div style="display:flex;gap:8px"><input id="ctx_lineup" placeholder="lineup impact -0..1 (eg.0.2)"><input id="ctx_injury" placeholder="injury impact -0..1"></div>
          </div>
          <div style="text-align:right"><button id="analyzeBtn" class="btn">🔎 วิเคราะห์ Master</button></div>
        </div>
      </form>
    </div>

    <div id="resultWrap" class="card" style="display:none;position:relative;">
      <svg class="dragon" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid meet">
        <defs><linearGradient id="g1" x1="0%" x2="100%"><stop offset="0%" stop-color="#ffd78c"/><stop offset="100%" stop-color="#f59e0b"/></linearGradient>
        <path id="p" d="M20,160 C150,10 350,10 480,140 C560,210 760,120 780,60"/></defs>
        <use xlink:href="#p" fill="none" stroke="rgba(212,160,23,0.06)" stroke-width="8"/>
        <circle r="14" fill="url(#g1)"><animateMotion dur="7s" repeatCount="indefinite"><mpath xlink:href="#p"></mpath></animateMotion></circle>
      </svg>

      <div class="ink" id="inkWrap"></div>

      <div class="resultWrap">
        <div class="analysisCard">
          <div id="mainSummary"></div>
          <div id="mainReasons" style="margin-top:12px"></div>
          <div id="detailTables" style="margin-top:12px"></div>
          <div id="tome" class="tome" style="display:block"></div>
        </div>

        <div class="sidePanel">
          <div style="text-align:center">
            <div class="kpi" id="voidScore">--</div><div class="small">VOID Score</div>
            <div style="height:8px"></div>
            <div class="kpi" id="confidence">--%</div><div class="small">Confidence</div>
            <div style="height:8px"></div>
            <div class="kpi" id="flowPower">--</div><div class="small">Flow Power</div>
            <div style="height:12px"></div>
            <div style="padding:8px;border-radius:8px;background:linear-gradient(90deg,rgba(212,160,23,0.06),rgba(126,226,199,0.02));color:#ffdca8">
              VPE Home: <span id="vpeHome">--</span>%<br>VPE Away: <span id="vpeAway">--</span>%
            </div>
            <div style="height:10px"></div>
            <div id="stakeSuggestion" style="padding:10px;border-radius:8px;color:#ffdca8"></div>
            <div style="height:8px"></div>
            <button id="ewmaBtn" class="btn" style="background:linear-gradient(90deg,#1b1b4a,#7e2aa3)">EWMA Stats</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card small" style="margin-top:12px"><strong>หมายเหตุ</strong><div class="small muted">ระบบวิเคราะห์จากราคาที่ผู้ใช้กรอกเท่านั้น — EWMA learning ถูกเก็บบน server (SQLite)</div></div>
  </div>

<script>
// ---------- UI helpers ----------
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function nf(v,d=4){ return (v===null||v===undefined||isNaN(v))?'-':Number(v).toFixed(d); }
function spawnInk(){ const w = document.getElementById('inkWrap'); w.innerHTML=''; const c=document.createElement('canvas'); c.width=w.clientWidth; c.height=200; w.appendChild(c); const ctx=c.getContext('2d'); for(let i=0;i<10;i++){ const x=Math.random()*c.width; const y=Math.random()*c.height; const r=10+Math.random()*60; ctx.fillStyle='rgba(11,8,8,'+(0.02+Math.random()*0.08)+')'; ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill(); for(let j=0;j<12;j++){ const rx=x+(Math.random()-0.5)*r*3; const ry=y+(Math.random()-0.5)*r*3; const rr=Math.random()*8; ctx.fillRect(rx,ry,rr,rr); } } }
document.addEventListener('DOMContentLoaded', ()=>{ if (!document.querySelectorAll('#ahContainer .ah-block').length) createAhBlock(); spawnInk(); });

// AH UI
const addAhBtn = document.getElementById('addAhBtn');
const clearAhBtn = document.getElementById('clearAhBtn');
const ahContainer = document.getElementById('ahContainer');
function createAhBlock(data={}) {
  const div = document.createElement('div'); div.className='ah-block';
  div.style="background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.00));padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);margin-bottom:10px";
  div.innerHTML = `
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
    </div>`;
  ahContainer.appendChild(div);
  div.querySelector('.remove').addEventListener('click', ()=>div.remove());
}
addAhBtn.addEventListener('click', ()=>createAhBlock());
clearAhBtn.addEventListener('click', ()=>{ ahContainer.innerHTML=''; createAhBlock(); });

// collect payload
function collectPayload(){
  const home=document.getElementById('home').value.trim()||'ทีมเหย้า';
  const away=document.getElementById('away').value.trim()||'ทีมเยือน';
  const favorite=document.getElementById('favorite').value||'none';
  const open1={home:toNum(document.getElementById('open1_home').value), draw:toNum(document.getElementById('open1_draw').value), away:toNum(document.getElementById('open1_away').value)};
  const now1={home:toNum(document.getElementById('now1_home').value), draw:toNum(document.getElementById('now1_draw').value), away:toNum(document.getElementById('now1_away').value)};
  const ahNodes = Array.from(document.querySelectorAll('#ahContainer .ah-block'));
  const ah = ahNodes.map(n=>({
    line:n.querySelector('input[name=ah_line]').value,
    open_home:toNum(n.querySelector('input[name=ah_open_home]').value),
    open_away:toNum(n.querySelector('input[name=ah_open_away]').value),
    now_home:toNum(n.querySelector('input[name=ah_now_home]').value),
    now_away:toNum(n.querySelector('input[name=ah_now_away]').value)
  }));
  const ctx = {
    lineup_impact: toNum(document.getElementById('ctx_lineup').value) || 0,
    injury_impact: toNum(document.getElementById('ctx_injury').value) || 0
  };
  return {home,away,favorite,open1,now1,ah,context:ctx};
}

// analyze
async function analyze(){
  const payload = collectPayload();
  document.getElementById('resultWrap').style.display='block';
  document.getElementById('mainSummary').innerHTML = '<div class="small muted">กำลังวิเคราะห์…</div>';
  try {
    const res = await fetch('?action=master_analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j = await res.json();
    renderResult(j);
    spawnInk();
  } catch (e) {
    document.getElementById('mainSummary').innerHTML = '<div class="small muted">Fetch error: '+e.message+'</div>';
  }
}
document.getElementById('analyzeBtn').addEventListener('click', analyze);

// renderResult
function renderResult(r){
  document.getElementById('voidScore').innerText = (r.void_score!==undefined)?r.void_score.toFixed(4):'--';
  document.getElementById('confidence').innerText = r.metrics && r.metrics.confidence!==undefined ? (r.metrics.confidence+'%') : '--%';
  document.getElementById('flowPower').innerText = r.metrics && r.metrics.flowPower!==undefined ? r.metrics.flowPower : '--';
  document.getElementById('vpeHome').innerText = r.metrics && r.metrics.vpe ? r.metrics.vpe.home : '--';
  document.getElementById('vpeAway').innerText = r.metrics && r.metrics.vpe ? r.metrics.vpe.away : '--';

  const main = document.getElementById('mainSummary');
  let html = `<div style="display:flex;justify-content:space-between;align-items:center">
    <div><div style="font-weight:900;font-size:1.15rem;color:var(--gold)">${r.final_label}</div>
    <div style="margin-top:6px"><strong>ข้อแนะนำ:</strong> ${r.recommendation}</div>
    <div style="margin-top:6px"><strong>คาดการณ์:</strong> <strong>${r.predicted_winner}</strong></div></div>
    <div style="text-align:right"><div class="badge">VOID v1.0</div></div></div>`;
  if (r.metrics && r.metrics.signature && r.metrics.signature.length) html = '<div style="margin-bottom:8px" class="badge">Signature: '+r.metrics.signature.join(', ')+'</div>' + html;
  main.innerHTML = html;

  const dt = document.getElementById('detailTables');
  let dhtml = '<strong>1X2</strong><table class="table"><thead><tr><th>ฝั่ง</th><th>Open</th><th>Now</th><th>MarketProb</th><th>TrueProb</th></tr></thead><tbody>';
  ['home','draw','away'].forEach(side=>{
    const o=r.input.open1[side]||'-'; const n=r.input.now1[side]||'-';
    const mp = r.market_prob_now && r.market_prob_now[side] ? (r.market_prob_now[side].toFixed(4)) : '-';
    const tp = r.true_prob && r.true_prob[side] ? (r.true_prob[side].toFixed(4)) : '-';
    dhtml += `<tr><td>${side}</td><td>${o}</td><td>${n}</td><td>${mp}</td><td>${tp}</td></tr>`;
  });
  dhtml += '</tbody></table>';
  dhtml += '<div style="height:8px"></div><strong>AH Lines</strong><table class="table"><thead><tr><th>Line</th><th>Open H</th><th>Now H</th><th>Net H</th><th>Mom H</th><th>Dir H</th><th>Dir A</th></tr></thead><tbody>';
  (r.ah_details||[]).forEach(ad=>{
    dhtml += `<tr><td>${ad.line||'-'}</td><td>${ad.open_home||'-'}</td><td>${ad.now_home||'-'}</td><td>${ad.net_home===undefined?'-':ad.net_home}</td><td>${ad.mom_home===undefined?'-':ad.mom_home}</td><td>${ad.dir_home||'-'}</td><td>${ad.dir_away||'-'}</td></tr>`;
  });
  dhtml += '</tbody></table>';

  if (r.metrics && r.metrics.sfd) dhtml += `<div style="margin-top:8px" class="small muted">SFD Spike: ${r.metrics.sfd.spike_score} Spoof:${(r.metrics.sfd.spoof_probability*100).toFixed(1)}%</div>`;
  if (r.metrics && r.metrics.ltme) {
    const lt=r.metrics.ltme;
    dhtml += `<div style="margin-top:8px" class="small muted">LTME TrapScore: ${lt.trap_score.toFixed(1)} Sweep:${lt.sweep_detected?lt.sweep_side+'('+lt.sweep_mag.toFixed(3)+')':'no'}</div>`;
    if (lt.trap_zone) dhtml += `<div class="small muted">Trap zone H:[${lt.trap_zone.home.min}-${lt.trap_zone.home.max}] A:[${lt.trap_zone.away.min}-${lt.trap_zone.away.max}]</div>`;
  }
  if (r.esp) dhtml += `<div style="margin-top:8px" class="small muted">ESP: ${r.esp.expected_score} (home_xg:${r.esp.home_xg}, away_xg:${r.esp.away_xg})</div>`;
  if (r.value) dhtml += `<div style="margin-top:8px" class="small muted">Value Best: ${r.value.best} (${r.value.bestEdge.toFixed(3)}) — ${r.value.bet_type} stake ${r.value.stake_pct}%</div>`;
  dt.innerHTML = dhtml;

  const tome = document.getElementById('tome');
  let t = `<h3 style="margin:0;color:var(--gold)">คัมภีร์ลับ</h3><div style="margin-top:8px">VoidScore:${r.void_score.toFixed(4)} Confidence:${r.metrics.confidence}% FlowPower:${r.metrics.flowPower}</div>`;
  t += `<div style="margin-top:8px"><strong>Value:</strong> ${JSON.stringify(r.value.labels)}</div>`;
  t += `<div style="margin-top:8px"><strong>ESP:</strong> ${r.esp.expected_score}</div>`;
  t += `<div style="margin-top:8px"><strong>Recommendation:</strong> ${r.recommendation}</div>`;
  tome.innerHTML = t;

  // stake suggestion panel
  const stakeEl = document.getElementById('stakeSuggestion');
  if (r.value && r.value.bet_type !== 'NoBet' && r.metrics.confidence > 55) {
    stakeEl.innerHTML = `<div style="font-weight:900;color:var(--gold)">${r.value.bet_type}: ${r.value.best} — stake ${r.value.stake_pct}%</div>`;
  } else {
    stakeEl.innerHTML = `<div class="small muted">ไม่แนะนำเดิมพันชัดเจน</div>`;
  }
}

// EWMA stats
document.getElementById('ewmaBtn').addEventListener('click', async ()=>{
  const res = await fetch('?action=ewma_stats'); const j = await res.json();
  alert(JSON.stringify(j, null, 2));
});
</script>
</body>
</html>
