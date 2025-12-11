<?php
declare(strict_types=1);
/*
 Last.php — VOID ENGINE v8 — Market Breaker Edition (Football-fit)
 - Adds LTME, SFR, VPE modules to VOID ENGINE v7
 - EWMA adaptive store (SQLite) still used
 - API: ?action=void_analyze (POST JSON), ?action=ewma_stats
 - UI included
*/

// ---------------- Safety & env ----------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$basedir = __DIR__;

// ---------------- SQLite EWMA store & samples ----------------
$db_file = $basedir . '/void_engine.db';
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS ewma_store (k TEXT PRIMARY KEY, v REAL, alpha REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT, sample REAL, ts INTEGER)");
} catch (Exception $e) {
    error_log("SQLite init error: ".$e->getMessage());
    $pdo = null;
}

// ---------------- EWMA helpers ----------------
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
    $N = 30;
    $st = $pdo->prepare("SELECT sample, ts FROM samples WHERE k=:k ORDER BY id DESC LIMIT :n");
    $st->bindValue(':k', $k);
    $st->bindValue(':n', $N, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows || count($rows) < 6) return;
    $arr = array_map(function($r){ return floatval($r['sample']); }, $rows);
    $mean = array_sum($arr)/count($arr);
    $var = 0.0; foreach ($arr as $v) $var += pow($v - $mean, 2);
    $std = sqrt($var / count($arr));
    $norm = clampf($std / max(1e-6, abs($mean)+1e-6), 0.0, 1.0);
    $newAlpha = 0.02 + ($norm * 0.58);
    ewma_set_alpha($k, $newAlpha);
}

// ---------------- Utility ----------------
function safeFloat($v){ if(!isset($v)) return NAN; $s = trim((string)$v); if ($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s) ? floatval($s) : NAN; }
function netflow($open,$now){ if(is_nan($open) || is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n = netflow($open,$now); return is_nan($n) ? NAN : abs($n); }
function dir_label($open,$now){ if(is_nan($open) || is_nan($now)) return 'flat'; if ($now < $open) return 'down'; if ($now > $open) return 'up'; return 'flat'; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }

// ---------------- Rebound helpers ----------------
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

// ---------------- LVE (Liquidity Velocity Engine) ----------------
function get_last_samples(string $k, int $n=5) {
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
    $rows = get_last_samples('void_netflow', 6);
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
    $jerk = 0.0;
    if (count($velocities) >= 3) {
        $prev_acc = ($velocities[count($velocities)-2] - $velocities[count($velocities)-3]) / max(1, $data[count($data)-2]['ts'] - $data[count($data)-3]['ts']);
        $jerk = ($acceleration - $prev_acc);
    }
    $mw = min(1.0, abs($velocity) * 200.0 + max(0.0, $acceleration)*50.0);
    $money_weight = clampf($mw * 100.0, 0.0, 100.0);
    $velocity_score = clampf(abs($velocity) * 1000.0, 0.0, 100.0);
    return ['velocity'=>$velocity,'acceleration'=>$acceleration,'jerk'=>$jerk,'money_weight'=>$money_weight,'velocity_score'=>$velocity_score];
}

// ---------------- SFD (Shock & Fake-Spike Detector) ----------------
function sfd_analyze(float $current_sample): array {
    $rows = get_last_samples('void_netflow', 12);
    $vals = array_map(function($r){ return floatval($r['sample']); }, $rows);
    $ts = array_map(function($r){ return intval($r['ts']); }, $rows);
    $spike_score = 0.0; $noise_factor = 0.0; $spoof_probability = 0.0; $patterns = [];
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
            if (abs($r) > 0.01) { $noise_factor += 8; $patterns[]='soft_spike'; }
        }
        $n = count($vals);
        if ($n>=5) {
            $a = $vals[$n-5]; $b=$vals[$n-4]; $c=$vals[$n-3]; $d=$vals[$n-2]; $e=$vals[$n-1];
            if (($b>$c && $c<$d && $d>$e && abs($d-e)<0.0005) || ($b<$c && $c>$d && $d<$e && abs($d-e)<0.0005)) {
                $spike_score += 30; $patterns[]='mirrored_rebound';
            }
        }
        $avg_rate = array_sum(array_map('abs',$deltas))/count($deltas);
        $spoof_probability = clampf(($spike_score/100.0) + ($noise_factor/100.0) + ($avg_rate*5.0), 0.0, 1.0);
    }
    $spike_score = clampf($spike_score, 0.0, 100.0);
    $noise_factor = clampf($noise_factor, 0.0, 100.0);
    return ['spike_score'=>$spike_score,'noise_factor'=>$noise_factor,'spoof_probability'=>$spoof_probability,'patterns'=>array_values(array_unique($patterns))];
}

// ---------------- OUCE (inferred OU mismatch) ----------------
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

// ---------------- SMK 3.0 ----------------
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

// ---------------- LTME (Liquidity Trap & Market Engineering) ----------------
function ltme_detect(array $ah_details, array $flow1, array $pairs, float $reboundSens): array {
    // football interpretation:
    // - sweep: very large single netflow on AH or 1x2 side
    // - trap zone: range around sweep price to use as 'entry trap'
    // - trap_score: 0..100
    $sweep_detected = false; $sweep_side = null; $sweep_mag = 0.0; $trap_zone = null; $trap_score = 0.0; $trap_type = null;
    // check AH lines for sudden big net changes
    foreach ($ah_details as $ad) {
        $nh = $ad['now_home'] ?? NAN; $oh = $ad['open_home'] ?? NAN;
        $na = $ad['now_away'] ?? NAN; $oa = $ad['open_away'] ?? NAN;
        $nh_net = is_nan($oh) || is_nan($nh) ? 0.0 : abs($oh - $nh);
        $na_net = is_nan($oa) || is_nan($na) ? 0.0 : abs($oa - $na);
        if ($nh_net > $sweep_mag) { $sweep_mag = $nh_net; $sweep_side = 'home'; }
        if ($na_net > $sweep_mag) { $sweep_mag = $na_net; $sweep_side = 'away'; }
    }
    // check 1x2
    if (isset($flow1['home']) && abs($flow1['home']) > $sweep_mag) { $sweep_mag = abs($flow1['home']); $sweep_side = 'home'; }
    if (isset($flow1['away']) && abs($flow1['away']) > $sweep_mag) { $sweep_mag = abs($flow1['away']); $sweep_side = 'away'; }
    if ($sweep_mag > 0.10) $sweep_detected = true; // heuristic threshold
    // compute trap score
    $trap_score = clampf(($sweep_mag / 0.5) * 100.0, 0.0, 100.0);
    if ($sweep_detected) {
        // trap zone: derive price window from AH lines that moved most
        $best = null; $best_move = -1;
        foreach ($ah_details as $ad) {
            $move = abs($ad['net_home'] ?? 0) + abs($ad['net_away'] ?? 0);
            if ($move > $best_move) { $best_move = $move; $best = $ad; }
        }
        if ($best) {
            // create zone: around the line value choose min and max of open/now for recommended SFR zone
            $h_vals = [$best['open_home'],$best['now_home']]; $a_vals = [$best['open_away'],$best['now_away']];
            $minH = min($h_vals); $maxH = max($h_vals);
            $minA = min($a_vals); $maxA = max($a_vals);
            $trap_zone = ['home'=>['min'=>$minH,'max'=>$maxH],'away'=>['min'=>$minA,'max'=>$maxA]];
        }
        // trap type: if sweep side favored cheaper price -> 'liquidation' else 'bait'
        $trap_type = ($sweep_side === 'home') ? 'sweep_home' : 'sweep_away';
    }
    return ['sweep_detected'=>$sweep_detected,'sweep_side'=>$sweep_side,'sweep_mag'=>$sweep_mag,'trap_zone'=>$trap_zone,'trap_score'=>$trap_score,'trap_type'=>$trap_type];
}

// ---------------- SFR (Smart Flow Reversal) ----------------
function sfr_zone_calc(array $ah_details, array $flow1): array {
    // Find zones where flow reversed or compressed (use mom and net)
    $zones = []; $best_zone = null; $best_conf = 0.0;
    foreach ($ah_details as $ad) {
        $hRel = (!is_nan($ad['open_home']) && $ad['open_home']>0) ? (($ad['now_home'] - $ad['open_home']) / $ad['open_home']) : 0;
        $aRel = (!is_nan($ad['open_away']) && $ad['open_away']>0) ? (($ad['now_away'] - $ad['open_away']) / $ad['open_away']) : 0;
        $mom = ($ad['mom_home'] ?? 0) + ($ad['mom_away'] ?? 0);
        // SFR confidence higher when mom is mid-high and directions show reversal
        $conf = clampf(($mom / 0.2),0.0,1.0);
        // candidate zone: midpoint between open and now for AH home/away
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
    if (!$best_zone) return ['sfr_zone'=>null,'sfr_confidence'=>0.0];
    return ['sfr_zone'=>$best_zone,'sfr_confidence'=>$best_conf];
}

// ---------------- VPE (Volume Pressure Equilibrium) ----------------
function vpe_compute(float $agg_sample, array $lve): array {
    // convert agg_sample and lve money_weight into home/away pressure 0..100
    // heuristic: positive agg_sample -> favor home, negative -> favor away
    $base = clampf($agg_sample / 1.0, -1.0, 1.0); // normalize roughly
    $mw = isset($lve['money_weight']) ? floatval($lve['money_weight'])/100.0 : 0.0;
    // home bias score -1..1
    $bias = clampf($base * (0.6 + 0.4*$mw), -1.0, 1.0);
    $home_pct = round((0.5 + $bias/2.0) * 100.0,1);
    $away_pct = round(100.0 - $home_pct,1);
    return ['home'=>$home_pct,'away'=>$away_pct,'bias'=>$bias];
}

// ---------------- void_compress_score (updated) ----------------
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

// ---------------- Main analyze (void_engine_analyze) ----------------
function void_engine_analyze(array $payload): array {
    $home = $payload['home'] ?? 'เหย้า';
    $away = $payload['away'] ?? 'เยือน';
    $favorite = $payload['favorite'] ?? 'none';
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];

    // pairs for rebound
    $pairs = [];
    $pairs[] = ['open'=>$open1['home'] ?? NAN,'now'=>$now1['home'] ?? NAN];
    $pairs[] = ['open'=>$open1['away'] ?? NAN,'now'=>$now1['away'] ?? NAN];

    $ah_details = []; $totalAH_mom = 0.0;
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

    // 1x2 flow & momentum
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

    // rebound auto
    $reboundSens = compute_auto_rebound_agg($pairs);

    // divergence
    $divergence = abs($totalAH_mom - $total1x2_mom);

    // detect trap (bounce)
    $trap = false; $trapFlags = [];
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

    // SMK & FDA
    $smk = smk3_score($ah_details, $flow1, $juicePressureNorm, $stackFactor, $divergence);
    $smartMoneyScore = $smk['score']; $smartFlags = $smk['flags'];
    $fda = fda_analyze($ah_details, $flow1, $reboundSens);

    // agg sample (for liquidity & VPE)
    $agg_sample = 0.0;
    $agg_sample += (is_nan($flow1['home'])?0:$flow1['home']) * 2.0;
    $agg_sample -= (is_nan($flow1['away'])?0:$flow1['away']) * 2.0;
    foreach ($ah_details as $ad) {
        $agg_sample += (($ad['net_home'] ?? 0) - ($ad['net_away'] ?? 0));
    }

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

    // LTME
    $ltme = ltme_detect($ah_details, $flow1, $pairs, $reboundSens);
    $ltme_trap_score = $ltme['trap_score'];
    $ltme_trap_zone = $ltme['trap_zone'];
    $ltme_trap_type = $ltme['trap_type'];

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

    // confidence & flowPower (apply OUCE confidence drop)
    $confidence = round(min(100, max(0, abs($hackScore) * 120 + ($w_juice * 20) - ($confidence_drop*100))), 1);
    $flowPower = round(min(100, max(0, (abs($hackScore)*0.6 + $w_sync*0.2 + $w_juice*0.2 + $smartMoneyScore*0.15 + ($liquidity_weight/100.0)*0.12) * 100)));

    // market kill detection & signatures
    $signature = []; $market_kill=false;
    if ($flowPower >= 88 && $confidence >= 82 && $stackFactor > 0.65 && !$trap) { $market_kill = true; $signature[]='STACK+SHARP+HIGH_FLOW'; }
    if ($trap && $flowPower < 40) { $signature[]='TRAP_DETECTED'; }
    if ($divergence > 0.22) { $signature[]='ULTRA_DIV'; }
    if ($ltme['sweep_detected']) { $signature[]='LTME_SWEEP'; }

    // Void compress
    $metrics_for_void = [
        'flowPower'=>$flowPower,'confidence'=>$confidence,'smartMoneyScore'=>$smartMoneyScore,
        'juicePressureNorm'=>$juicePressureNorm,'stackFactor'=>$stackFactor,'divergence'=>$divergence,
        'trap'=>$trap,'liquidity'=>$liquidity_weight,'spike_penalty'=>$spike_penalty,'ou_mismatch'=>$ou_mismatch,
        'ltme_trap'=>$ltme_trap_score
    ];
    $void_score = void_compress_score($metrics_for_void);

    // final label
    if ($market_kill) {
        $final_label = '💀 MARKET KILL — ห้ามเสี่ยง';
        $recommendation = 'ห้ามเสี่ยง/ควบคุมเดิมพันเข้มงวด';
    } elseif ($void_score > 0.35) {
        $final_label = '✅ VOID: ไหลจริง — ฝั่งเหย้า';
        $recommendation = 'พิจารณาตาม (จัดการความเสี่ยง)';
    } elseif ($void_score < -0.35) {
        $final_label = '✅ VOID: ไหลจริง — ฝั่งเยือน';
        $recommendation = 'พิจารณาตาม (จัดการความเสี่ยง)';
    } elseif ($trap || $ltme_trap_score > 35) {
        $final_label = '❌ VOID: สัญญาณกับดัก';
        $recommendation = 'ไม่แนะนำเดิมพัน';
    } else {
        $final_label = '⚠️ VOID: ไม่ชัดเจน — สัญญาณผสม';
        $recommendation = 'รอ confirm';
    }

    // predicted winner heuristic
    $predicted_winner = 'ไม่แน่ใจ'; if ($agg_sample > 0.12) $predicted_winner = $home; if ($agg_sample < -0.12) $predicted_winner = $away;

    // update EWMA store
    ewma_update('void_netflow', floatval($agg_sample));
    ewma_update('void_rebound', floatval($reboundSens));
    ewma_update('void_voidscore', floatval($void_score));
    ewma_update('void_smk', floatval($smartMoneyScore));
    ewma_update('void_fda', floatval($fda['intensity'] ?? 0.0));
    ewma_update('void_liquidity', floatval($liquidity_weight));
    ewma_update('void_spike', floatval($spike_penalty));
    ewma_update('void_ou', floatval($ou_mismatch));
    ewma_update('void_ltme', floatval($ltme_trap_score));
    ewma_update('void_vpe_home', floatval($vpe['home']));
    ewma_update('void_vpe_away', floatval($vpe['away']));

    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'favorite'=>$favorite,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list],
        'metrics'=>array_merge($metrics_for_void,['marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,'reboundSens'=>$reboundSens,'flowPower'=>$flowPower,'confidence'=>$confidence,'signature'=>$signature,'trapFlags'=>$trapFlags,'fda'=>$fda,'smk_flags'=>$smartFlags,'lve'=>$lve,'sfd'=>$sfd,'ouce'=>$ouce,'ltme'=>$ltme,'vpe'=>$vpe,'sfr'=>sfr_zone_calc($ah_details,$flow1)]),
        'void_score'=>$void_score,
        'final_label'=>$final_label,
        'recommendation'=>$recommendation,
        'predicted_winner'=>$predicted_winner,
        'hackScore'=>$hackScore,
        'ah_details'=>$ah_details
    ];
}

// ---------------- HTTP API endpoints ----------------
if (php_sapi_name() !== 'cli') {
    if (isset($_GET['action']) && $_GET['action'] === 'void_analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'error','msg'=>'invalid_payload','raw'=>substr($raw,0,200)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
            exit;
        }
        $res = void_engine_analyze($payload);
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

// ---------------- UI (unchanged look but shows new metrics) ----------------
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>THE VOID — Market Breaker (v8)</title>
<style>
:root{ --gold:#d4a017; --royal:#5b21b6; --void:#060409; --jade:#7ee2c7; }
*{box-sizing:border-box}
body{margin:0;font-family:Inter, 'Noto Sans Thai', Arial;background:linear-gradient(180deg,#020205,#0b0710);color:#f6eedf}
.container{max-width:1200px;margin:18px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.logo{width:88px;height:88px;border-radius:14px;background:radial-gradient(circle at 30% 20%, rgba(255,255,255,0.04), rgba(91,33,182,0.95));display:flex;align-items:center;justify-content:center;color:white;font-weight:900;font-size:34px;box-shadow:0 20px 60px rgba(0,0,0,0.6)}
.card{background:linear-gradient(145deg,#0c0606,#241316);border-radius:14px;padding:16px;margin-top:14px;border:1px solid rgba(212,160,23,0.06)}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
label{font-size:0.95rem;color:#f3e6cf}
input, select{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,235,200,0.05);background:rgba(255,255,255,0.02);color:#ffecc9}
.btn{padding:10px 14px;border-radius:12px;border:none;color:#110b06;background:linear-gradient(90deg,var(--royal),var(--gold));cursor:pointer}
.resultWrap{display:flex;gap:14px;align-items:flex-start;margin-top:14px}
.analysisCard{flex:1;padding:18px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));position:relative;overflow:visible}
.sidePanel{width:360px;padding:14px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01))}
.dragon{position:absolute;right:-80px;top:-60px;width:420px;height:220px;pointer-events:none;opacity:0.95;mix-blend-mode:screen}
.ink-canvas{position:absolute;left:0;bottom:0;width:100%;height:220px;pointer-events:none;opacity:0.14}
.tome{margin-top:12px;padding:12px;border-radius:10px;background:linear-gradient(180deg,#0e0710,#231217);border:1px solid rgba(212,160,23,0.06);color:#ffdba1}
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th,.table td{padding:8px;border-bottom:1px dashed rgba(255,235,200,0.03);text-align:left}
@media(max-width:980px){.grid{grid-template-columns:1fr}.sidePanel{width:100%}.dragon{display:none}}
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="logo">鴻</div>
        <div>
          <h1 style="margin:0;color:var(--gold)">THE VOID — Market Breaker (v8)</h1>
          <div style="color:#d9c89a;font-size:0.95rem">LTME • SFR • VPE • SMK3.0 • FDA • LVE • SFD • EWMA</div>
        </div>
      </div>
      <div><button id="voidToggle" class="btn">Void Mode</button></div>
    </div>

    <div class="card">
      <form id="mainForm" onsubmit="return false;">
        <div class="grid">
          <div><label>ทีมเหย้า</label><input id="home" placeholder="ทีมเหย้า"></div>
          <div><label>ทีมเยือน</label><input id="away" placeholder="ทีมเยือน"></div>
          <div><label>ทีมต่อ (SBOBET)</label><select id="favorite"><option value="none">ไม่ระบุ</option><option value="home">เหย้า</option><option value="away">เยือน</option></select></div>
        </div>
        <div style="height:12px"></div>

        <div class="card" style="padding:12px">
          <strong style="color:#ffdca8">1X2 — ราคาเปิด</strong>
          <div class="grid" style="margin-top:8px">
            <div><label>เหย้า (open)</label><input id="open1_home" type="number" step="0.01" placeholder="2.10"></div>
            <div><label>เสมอ (open)</label><input id="open1_draw" type="number" step="0.01" placeholder="3.40"></div>
            <div><label>เยือน (open)</label><input id="open1_away" type="number" step="0.01" placeholder="3.10"></div>
          </div>
          <div style="height:8px"></div>
          <strong style="color:#ffdca8">1X2 — ราคาปัจจุบัน</strong>
          <div class="grid" style="margin-top:8px">
            <div><label>เหย้า (now)</label><input id="now1_home" type="number" step="0.01" placeholder="1.95"></div>
            <div><label>เสมอ (now)</label><input id="now1_draw" type="number" step="0.01" placeholder="3.60"></div>
            <div><label>เยือน (now)</label><input id="now1_away" type="number" step="0.01" placeholder="3.80"></div>
          </div>
        </div>

        <div style="height:12px"></div>
        <div class="card" style="padding:12px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <strong style="color:#ffdca8">Asian Handicap — เพิ่ม/ลบได้ (หลายราคา)</strong>
            <div class="controls"><button id="addAhBtn" type="button" class="btn">+ เพิ่ม AH</button><button id="clearAhBtn" type="button" class="btn" style="background:transparent;border:1px solid rgba(255,235,200,0.05);color:#ffdca8">ล้าง</button></div>
          </div>
          <div id="ahContainer" style="margin-top:12px"></div>
        </div>

        <div style="height:12px"></div>
        <div style="text-align:right"><button id="analyzeBtn" class="btn">🔎 วิเคราะห์ VOID</button></div>
      </form>
    </div>

    <div id="resultWrap" class="card" style="display:none;position:relative;overflow:visible">
      <svg class="dragon" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid meet">
        <defs><linearGradient id="g1" x1="0%" x2="100%"><stop offset="0%" stop-color="#ffd78c"/><stop offset="100%" stop-color="#f59e0b"/></linearGradient>
        <path id="dragonPath" d="M20,160 C150,10 350,10 480,140 C560,210 760,120 780,60"/></defs>
        <use xlink:href="#dragonPath" fill="none" stroke="rgba(212,160,23,0.06)" stroke-width="8"/>
        <circle r="14" fill="url(#g1)"><animateMotion dur="6s" repeatCount="indefinite"><mpath xlink:href="#dragonPath"></mpath></animateMotion></circle>
      </svg>

      <div class="ink-canvas" id="inkCanvasWrap"></div>

      <div class="resultWrap">
        <div class="analysisCard">
          <div id="mainSummary"></div>
          <div id="mainReasons" style="margin-top:12px"></div>
          <div id="detailTables" style="margin-top:14px"></div>
          <div id="tome" class="tome" aria-hidden="false"></div>
        </div>

        <div class="sidePanel">
          <div style="text-align:center">
            <div class="kpi"><div class="num void-score" id="voidScoreValue">--</div></div>
            <div class="small">VOID Score (-1..1)</div>
            <div style="height:8px"></div>
            <div class="kpi"><div class="num" id="confValue">--%</div></div>
            <div class="small">Confidence</div>
            <div style="height:8px"></div>
            <div class="kpi"><div class="num" id="flowPowerValue">--</div></div>
            <div class="small">Flow Power</div>
            <div style="height:12px"></div>
            <div id="vpePanel" style="padding:8px;border-radius:8px;background:linear-gradient(90deg,rgba(126,226,199,0.06),rgba(212,160,23,0.02));color:#dff7ee">
              <div>VPE Home: <span id="vpeHome">--</span>%</div>
              <div>VPE Away: <span id="vpeAway">--</span>%</div>
            </div>
            <div style="height:12px"></div>
            <div id="stakeSuggestion" style="padding:12px;border-radius:10px;text-align:center;color:#ffdca8"></div>
            <div style="height:12px"></div>
            <button id="ewmaDebug" class="btn" style="background:linear-gradient(90deg,#1b1b4a,#7e2aa3)">EWMA Debug</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card small" style="margin-top:12px"><strong>หมายเหตุ</strong><div class="small">ระบบนี้วิเคราะห์จากราคาที่ผู้ใช้กรอกเท่านั้น — EWMA learning ถูกเก็บบน server (SQLite)</div></div>
  </div>

<script>
// JS UI unchanged except display of new metrics
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function nf(v,d=4){ return (v===null||v===undefined||isNaN(v))?'-':Number(v).toFixed(d); }
function clamp(v,a,b){ return Math.max(a,Math.min(b,v)); }

const addAhBtn = document.getElementById('addAhBtn');
const clearAhBtn = document.getElementById('clearAhBtn');
const ahContainer = document.getElementById('ahContainer');
function createAhBlock(data={}){
  const div = document.createElement('div');
  div.className='ah-block';
  div.style = "background:linear-gradient(180deg,rgba(255,255,255,0.01),rgba(255,255,255,0.00));padding:12px;border-radius:10px;border:1px solid rgba(255,235,200,0.03);margin-bottom:10px";
  div.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
      <div><label>AH line</label><input name="ah_line" placeholder="เช่น 0, +0.25, -0.5" value="${data.line||''}"></div>
      <div><label>เปิด (เหย้า)</label><input name="ah_open_home" type="number" step="0.01" placeholder="1.92" value="${data.open_home||''}"></div>
      <div><label>เปิด (เยือน)</label><input name="ah_open_away" type="number" step="0.01" placeholder="1.95" value="${data.open_away||''}"></div>
    </div>
    <div style="height:8px"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:8px">
      <div><label>ตอนนี้ (เหย้า)</label><input name="ah_now_home" type="number" step="0.01" placeholder="1.80" value="${data.now_home||''}"></div>
      <div><label>ตอนนี้ (เยือน)</label><input name="ah_now_away" type="number" step="0.01" placeholder="1.95" value="${data.now_away||''}"></div>
      <div style="align-self:end;text-align:right"><button type="button" class="btn remove">ลบ</button></div>
    </div>`;
  ahContainer.appendChild(div);
  div.querySelector('.remove').addEventListener('click', ()=>div.remove());
}
addAhBtn.addEventListener('click', ()=>createAhBlock());
clearAhBtn.addEventListener('click', ()=>{ ahContainer.innerHTML=''; createAhBlock(); });
window.addEventListener('DOMContentLoaded', ()=>{ if (!document.querySelectorAll('#ahContainer .ah-block').length) createAhBlock(); spawnInk(); });

function spawnInk(){ const wrap=document.getElementById('inkCanvasWrap'); wrap.innerHTML=''; const canvas=document.createElement('canvas'); canvas.width=wrap.clientWidth; canvas.height=220; wrap.appendChild(canvas); const ctx=canvas.getContext('2d'); for(let i=0;i<10;i++){ const x=Math.random()*canvas.width; const y=Math.random()*canvas.height; const r=8+Math.random()*50; ctx.fillStyle='rgba(11,8,8,'+(0.02+Math.random()*0.08)+')'; ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill(); for(let j=0;j<16;j++){ const rx=x+(Math.random()-0.5)*r*4; const ry=y+(Math.random()-0.5)*r*4; const rr=Math.random()*8; ctx.fillRect(rx,ry,rr,rr); } } }

function collectPayload(){ const home=document.getElementById('home').value.trim()||'ทีมเหย้า'; const away=document.getElementById('away').value.trim()||'ทีมเยือน'; const favorite=document.getElementById('favorite').value||'none'; const open1={home:toNum(document.getElementById('open1_home').value), draw:toNum(document.getElementById('open1_draw').value), away:toNum(document.getElementById('open1_away').value)}; const now1={home:toNum(document.getElementById('now1_home').value), draw:toNum(document.getElementById('now1_draw').value), away:toNum(document.getElementById('now1_away').value)}; const ahNodes=Array.from(document.querySelectorAll('#ahContainer .ah-block')); const ah=ahNodes.map(n=>({ line:n.querySelector('input[name=ah_line]').value, open_home:toNum(n.querySelector('input[name=ah_open_home]').value), open_away:toNum(n.querySelector('input[name=ah_open_away]').value), now_home:toNum(n.querySelector('input[name=ah_now_home]').value), now_away:toNum(n.querySelector('input[name=ah_now_away]').value)})); return {home,away,favorite,open1,now1,ah,options:{}}; }

async function analyze(){
  const payload=collectPayload();
  document.getElementById('resultWrap').style.display='block';
  document.getElementById('mainSummary').innerHTML='<div class="small">กำลังวิเคราะห์…</div>';
  try{
    const res = await fetch('?action=void_analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j = await res.json();
    renderResult(j);
    spawnInk();
  }catch(e){
    document.getElementById('mainSummary').innerHTML='<div class="small">Fetch error: '+e.message+'</div>';
  }
}
document.getElementById('analyzeBtn').addEventListener('click', analyze);

function renderResult(r){
  document.getElementById('voidScoreValue').innerText = (r.void_score!==undefined)?(r.void_score.toFixed(4)): '--';
  document.getElementById('confValue').innerText = r.metrics && r.metrics.confidence!==undefined ? (r.metrics.confidence+'%') : '--%';
  document.getElementById('flowPowerValue').innerText = r.metrics && r.metrics.flowPower!==undefined ? r.metrics.flowPower : '--';
  document.getElementById('vpeHome').innerText = r.metrics && r.metrics.vpe ? r.metrics.vpe.home : '--';
  document.getElementById('vpeAway').innerText = r.metrics && r.metrics.vpe ? r.metrics.vpe.away : '--';
  const mainSummary=document.getElementById('mainSummary');
  let html=`<div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-weight:900;font-size:1.15rem;color:var(--gold)">${r.final_label}</div><div style="margin-top:8px"><strong>คำแนะนำ:</strong> ${r.recommendation}</div><div style="margin-top:6px"><strong>คาดการณ์:</strong> <strong>${r.predicted_winner}</strong></div></div></div>`;
  mainSummary.innerHTML=html;
  const dt=document.getElementById('detailTables');
  let html2='<strong>รายละเอียด 1X2</strong>';
  html2+='<table class="table"><thead><tr><th>ฝั่ง</th><th>Open</th><th>Now</th></tr></thead><tbody>';
  ['home','draw','away'].forEach(side=>{
    const o=r.input&&r.input.open1?r.input.open1[side]:'-';
    const n=r.input&&r.input.now1?r.input.now1[side]:'-';
    html2+=`<tr><td>${side}</td><td>${o}</td><td>${n}</td></tr>`;
  });
  html2+='</tbody></table>';
  html2+='<div style="height:8px"></div><strong>AH Lines</strong>';
  html2+='<table class="table"><thead><tr><th>Line</th><th>Open H</th><th>Now H</th><th>Net H</th><th>Mom H</th><th>Dir H</th><th>Dir A</th></tr></thead><tbody>';
  (r.ah_details||[]).forEach(ad=>{
    html2+=`<tr><td>${ad.line||'-'}</td><td>${ad.open_home||'-'}</td><td>${ad.now_home||'-'}</td><td>${ad.net_home===undefined?'-':ad.net_home}</td><td>${ad.mom_home===undefined?'-':ad.mom_home}</td><td>${ad.dir_home||'-'}</td><td>${ad.dir_away||'-'}</td></tr>`;
  });
  html2+='</tbody></table>';
  if (r.metrics && r.metrics.smk_flags && r.metrics.smk_flags.length) {
    html2 += `<div style="margin-top:8px;background:rgba(255,140,54,0.04);padding:8px;border-radius:8px"><strong>SMK Flags:</strong> ${r.metrics.smk_flags.join(', ')}</div>`;
  }
  if (r.metrics && r.metrics.fda) {
    html2 += `<div style="margin-top:8px;background:rgba(120,140,255,0.04);padding:8px;border-radius:8px"><strong>FDA:</strong> Phase ${r.metrics.fda.phase} — Intensity ${r.metrics.fda.intensity.toFixed(2)}</div>`;
  }
  if (r.metrics && r.metrics.lve) {
    html2 += `<div style="margin-top:8px;background:rgba(120,255,180,0.04);padding:8px;border-radius:8px"><strong>LVE:</strong> Velocity ${r.metrics.lve.velocity.toFixed(6)} Acc ${r.metrics.lve.acceleration.toFixed(6)} MoneyWeight ${r.metrics.lve.money_weight.toFixed(1)}</div>`;
  }
  if (r.metrics && r.metrics.sfd) {
    html2 += `<div style="margin-top:8px;background:rgba(255,120,140,0.04);padding:8px;border-radius:8px"><strong>SFD:</strong> Spike ${r.metrics.sfd.spike_score.toFixed(1)} SpoofProb ${(r.metrics.sfd.spoof_probability*100).toFixed(1)}%</div>`;
  }
  if (r.metrics && r.metrics.ltme) {
    const lt = r.metrics.ltme;
    html2 += `<div style="margin-top:8px;background:rgba(200,180,255,0.04);padding:8px;border-radius:8px"><strong>LTME:</strong> Sweep:${lt.sweep_detected?lt.sweep_side+'('+lt.sweep_mag.toFixed(3)+')':'no'} TrapScore:${lt.trap_score.toFixed(1)}</div>`;
    if (lt.trap_zone) html2 += `<div style="margin-top:6px;color:#ffdca8">Trap zone: H[${lt.trap_zone.home.min}-${lt.trap_zone.home.max}] A[${lt.trap_zone.away.min}-${lt.trap_zone.away.max}]</div>`;
  }
  if (r.metrics && r.metrics.sfr) {
    const sf = r.metrics.sfr;
    if (sf.sfr_zone) html2 += `<div style="margin-top:8px;background:rgba(240,240,200,0.02);padding:8px;border-radius:8px"><strong>SFR Zone:</strong> line ${sf.sfr_zone.line} conf ${(sf.sfr_confidence*100).toFixed(0)}%</div>`;
  }
  dt.innerHTML=html2;

  const tome = document.getElementById('tome');
  let tHtml = `<div class="card" style="padding:12px;background:linear-gradient(180deg,#fff6e1,#f7e2b8);color:#2b1708"><h3>คัมภีร์ลับ — VOID INSIGHTS</h3>`;
  tHtml += `<div><strong>Void Score:</strong> ${(r.void_score!==undefined)?r.void_score.toFixed(4):'-'}</div>`;
  if (r.metrics && r.metrics.signature && r.metrics.signature.length>0) tHtml += `<div style="margin-top:8px"><strong>Signature:</strong> ${r.metrics.signature.join(', ')}</div>`;
  if (r.metrics && r.metrics.trapFlags && r.metrics.trapFlags.length>0) tHtml += `<div style="margin-top:8px"><strong>Trap Flags:</strong> ${r.metrics.trapFlags.join(', ')}</div>`;
  if (r.metrics && r.metrics.smk_flags && r.metrics.smk_flags.length>0) tHtml += `<div style="margin-top:8px"><strong>SMK Flags:</strong> ${r.metrics.smk_flags.join(', ')}</div>`;
  if (r.metrics && r.metrics.fda) tHtml += `<div style="margin-top:8px"><strong>Divergence Phase:</strong> ${r.metrics.fda.phase} (${r.metrics.fda.intensity.toFixed(2)})</div>`;
  if (r.metrics && r.metrics.lve) tHtml += `<div style="margin-top:8px"><strong>Liquidity:</strong> MoneyWeight ${r.metrics.lve.money_weight.toFixed(1)}</div>`;
  if (r.metrics && r.metrics.sfd) tHtml += `<div style="margin-top:8px"><strong>Spike Patterns:</strong> ${r.metrics.sfd.patterns.join(', ')}</div>`;
  if (r.metrics && r.metrics.vpe) tHtml += `<div style="margin-top:8px"><strong>VPE:</strong> Home ${r.metrics.vpe.home}% / Away ${r.metrics.vpe.away}%</div>`;
  if (r.metrics && r.metrics.ltme) tHtml += `<div style="margin-top:8px"><strong>LTME Type:</strong> ${r.metrics.ltme.trap_type}</div>`;
  tHtml += `<div style="margin-top:8px"><strong>สรุป:</strong> ${r.final_label}</div></div>`;
  tome.innerHTML = tHtml; tome.style.display='block';
}

// EWMA debug
document.getElementById('ewmaDebug').addEventListener('click', async ()=>{
  const res = await fetch('?action=ewma_stats'); const j = await res.json();
  alert(JSON.stringify(j, null, 2));
});

// prevent mobile zoom
document.querySelectorAll('input,select,textarea').forEach(i=>{ i.addEventListener('focus', ()=>{ document.documentElement.style.fontSize='16px'; }); });

</script>
</body>
</html>
