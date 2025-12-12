<?php
declare(strict_types=1);
/**
 * Last.php — TRUE VOID ENGINE FULL (Assemble ∞ + MEM + OCM + CKP)
 * Single-file deploy (PHP 7.4+). Uses SQLite (pdo_sqlite).
 *
 * - Pre-match + TRUE VOID: TPO, MPE, MME, UDS, SMK-X, PCE
 * - Added: MEM (Market Equilibrium Mapping), OCM (Outcome Collapse Model), CKP (Contradiction Kill Protocol)
 * - EWMA-LD: adaptive EWMA stored in SQLite
 * - Pattern memory, match_cases, smart_moves persisted
 *
 * Notes:
 * - Backup old files/db before replacing.
 * - The file runs fully from user-provided prices (no external API calls).
 */

// ---------- ENV ----------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');
$BASEDIR = __DIR__;
$DB_FILE = $BASEDIR . '/void_true_engine.db';

// ---------- DB ----------
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Core stores
    $pdo->exec("CREATE TABLE IF NOT EXISTS ewma_store (k TEXT PRIMARY KEY, v REAL, alpha REAL, updated_at INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT, sample REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS results_history (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT, res_json TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS match_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, kickoff_ts INTEGER, league TEXT, payload TEXT, analysis TEXT, outcome TEXT, ts INTEGER)");

    // PM modules
    $pdo->exec("CREATE TABLE IF NOT EXISTS disparity_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, league TEXT, open_json TEXT, now_json TEXT, disparity REAL, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS smart_moves (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, type TEXT, score REAL, details TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pattern_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, signature TEXT, count INTEGER, win_rate REAL, last_seen INTEGER, meta TEXT)");

    // VOID additions
    $pdo->exec("CREATE TABLE IF NOT EXISTS equilibrium_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, tpo_json TEXT, market_json TEXT, equilibrium_json TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS collapse_events (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, collapse_winner TEXT, strength REAL, meta TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, match_key TEXT, reason TEXT, direction TEXT, severity REAL, meta TEXT, ts INTEGER)");
} catch (Exception $e) {
    error_log("DB init error: " . $e->getMessage());
    $pdo = null;
}

// ---------- CONFIG ----------
$CONFIG = [
    'mode' => 'pre_match',
    'ewma_alpha' => [
        'master_netflow' => 0.08,
        'master_voidscore' => 0.06,
        'master_smk' => 0.10,
        'master_lve' => 0.10,
    ],
    'reboundSens_min' => 0.03,
    'netflow_sharp_threshold' => 0.18,
    'divergence_ultra' => 0.18,
    'vpe_weight_pre_match' => 0.20,
    'mpe_sim_count' => 500,
    'tpo_margin_est' => 0.06,
    // MEM thresholds
    'mem_eq_shift_threshold' => 0.03,
    // OCM thresholds
    'ocm_strength_scale' => 100,
    // CKP thresholds
    'ckp_severity_min' => 0.35,
];

// ---------- HELPERS ----------
function safeFloat($v){ if(!isset($v)) return NAN; $s=trim((string)$v); if($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s)? floatval($s) : NAN; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }
function nf($v,$d=4){ return (is_nan($v) || $v===null) ? '-' : number_format((float)$v,$d,'.',''); }
function netflow($open,$now){ if(is_nan($open)||is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n=netflow($open,$now); return is_nan($n)?NAN:abs($n); }
function dir_label($open,$now){ if(is_nan($open)||is_nan($now)) return 'flat'; if($now<$open) return 'down'; if($now>$open) return 'up'; return 'flat'; }

// ---------- EWMA ----------
function ewma_get(string $k, float $fallback = 0.0, float $default_alpha = 0.08) {
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
    $alpha = floatval($cur['alpha'] ?? 0.08);
    $newv = ($alpha * $sample) + ((1.0 - $alpha) * floatval($cur['v']));
    $st = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
    $st->execute([':k'=>$k,':v'=>$newv,':a'=>$alpha,':t'=>time()]);
    $st2 = $pdo->prepare("INSERT INTO samples (k,sample,ts) VALUES(:k,:s,:t)");
    $st2->execute([':k'=>$k,':s'=>$sample,':t'=>time()]);
    return ['k'=>$k,'v'=>$newv,'alpha'=>$alpha];
}

// ---------- POISSON util ----------
function poisson_rand($lambda){
    $L = exp(-$lambda);
    $k = 0; $p = 1.0;
    do { $k++; $p *= mt_rand()/mt_getrandmax(); } while ($p > $L);
    return $k - 1;
}

// ---------- TPO ----------
function tpo_estimate(array $now1, array $open1 = []) {
    global $CONFIG;
    $imp = []; $sum = 0;
    foreach (['home','draw','away'] as $k) {
        $o = safeFloat($now1[$k] ?? null);
        $p = (is_nan($o) || $o <= 0) ? 0.0 : (1.0 / $o);
        $imp[$k] = $p; $sum += $p;
    }
    if ($sum <= 0) return null;
    foreach (['home','draw','away'] as $k) $imp[$k] /= $sum;
    $overround = $sum - 1.0;
    $est_margin = $overround > 0 ? $overround : $CONFIG['tpo_margin_est'];
    $tpo = []; $sumt=0;
    foreach (['home','draw','away'] as $k) {
        $raw = $imp[$k] / (1.0 + $est_margin);
        $tpo[$k] = max(1e-6, $raw);
        $sumt += $tpo[$k];
    }
    foreach (['home','draw','away'] as $k) $tpo[$k] /= max(1e-9,$sumt);
    return $tpo;
}

// ---------- MPE (compact) ----------
function mpe_simulate(array $tpo, int $sim_count = 500) {
    $ph = max(1e-6, $tpo['home']); $pa = max(1e-6, $tpo['away']);
    $strength = log($ph / $pa + 1e-9);
    $base = 1.15;
    $home_xg = max(0.2, $base + ($strength * 0.45));
    $away_xg = max(0.2, $base - ($strength * 0.45));
    $counts = ['home'=>0,'draw'=>0,'away'=>0];
    $sim_count = max(100, min(2000, $sim_count));
    for ($i=0;$i<$sim_count;$i++){
        $h = poisson_rand($home_xg);
        $a = poisson_rand($away_xg);
        if ($h > $a) $counts['home']++;
        elseif ($a > $h) $counts['away']++;
        else $counts['draw']++;
    }
    return ['home'=>$counts['home']/$sim_count,'draw'=>$counts['draw']/$sim_count,'away'=>$counts['away']/$sim_count,'home_xg'=>$home_xg,'away_xg'=>$away_xg];
}

// ---------- PM-DE helpers ----------
function pm_disparity(array $open1, array $now1) {
    $io=[]; $in=[]; $sumo=0; $sumn=0;
    foreach (['home','draw','away'] as $k) {
        $o = safeFloat($open1[$k] ?? null); $n = safeFloat($now1[$k] ?? null);
        $io[$k] = (is_nan($o)||$o<=0)?0.0:1.0/$o; $sumo += $io[$k];
        $in[$k] = (is_nan($n)||$n<=0)?0.0:1.0/$n; $sumn += $in[$k];
    }
    if ($sumo>0) foreach (['home','draw','away'] as $k) $io[$k]/=$sumo;
    if ($sumn>0) foreach (['home','draw','away'] as $k) $in[$k]/=$sumn;
    $details=[]; $mag=0; foreach (['home','draw','away'] as $k) { $d = $in[$k]-$io[$k]; $details[$k]=$d; $mag += abs($d); }
    return ['disparity'=>$mag,'details'=>$details];
}

// ---------- MME ----------
function mme_analyze(array $ah_details, array $flow1, ?int $kickoff_ts = null) {
    $juice = 0.0; foreach ($ah_details as $ad) { $hj = (!is_nan($ad['open_home']) && !is_nan($ad['now_home'])) ? abs($ad['open_home'] - $ad['now_home']) : 0.0; $aj = (!is_nan($ad['open_away']) && !is_nan($ad['now_away'])) ? abs($ad['open_away'] - $ad['now_away']) : 0.0; $juice += $hj + $aj; }
    $stackHome=0;$stackAway=0;
    foreach ($ah_details as $ls){ $hRel = (!is_nan($ls['open_home']) && $ls['open_home']>0) ? (($ls['now_home']-$ls['open_home'])/$ls['open_home']) : 0; $aRel = (!is_nan($ls['open_away']) && $ls['open_away']>0) ? (($ls['now_away']-$ls['open_away'])/$ls['open_away']) : 0; if ($hRel<0||$aRel<0) $stackHome++; if ($hRel>0||$aRel>0) $stackAway++; }
    $stackFactor = count($ah_details)? max($stackHome,$stackAway) / max(1,count($ah_details)) : 0;
    $hours = 48; if ($kickoff_ts) { $ttk = max(0,$kickoff_ts - time()); $hours = $ttk / 3600.0; }
    $late_weight = ($hours <= 2) ? 1.5 : (($hours <= 24) ? 1.1 : 0.9);
    $juiceNorm = clampf($juice * $late_weight, 0.0, 10.0);
    $isSharp = ($juiceNorm > 0.2 && $stackFactor > 0.4);
    $isTrap = ($juiceNorm > 0.2 && $stackFactor < 0.25);
    return ['juice'=>$juice,'juiceNorm'=>$juiceNorm,'stackFactor'=>$stackFactor,'isSharp'=>$isSharp,'isTrap'=>$isTrap,'hours_to_kick'=>$hours];
}

// ---------- UDS ----------
function uds_detect(array $ah_details, array $flow1) {
    $totalAH = 0.0; foreach ($ah_details as $ad) { $totalAH += (abs($ad['net_home'] ?? 0) + abs($ad['net_away'] ?? 0)); }
    $total1x2 = 0.0; foreach (['home','away'] as $s) { $total1x2 += abs($flow1[$s] ?? 0); }
    $divergence = abs($totalAH - $total1x2);
    $conflict = 0;
    for ($i=0;$i<count($ah_details);$i++){
        for ($j=$i+1;$j<count($ah_details);$j++){
            $a=$ah_details[$i]; $b=$ah_details[$j];
            if (($a['dir_home'] ?? '') !== ($b['dir_home'] ?? '') || ($a['dir_away'] ?? '') !== ($b['dir_away'] ?? '')) $conflict++;
        }
    }
    $pattern = ($conflict > 0) ? 'multi_price_conflict' : 'aligned';
    return ['divergence'=>$divergence,'conflict'=>$conflict,'pattern'=>$pattern];
}

// ---------- SMK-X ----------
function smkx_score(array $tpo, array $true_prob, array $mme, array $uds) {
    $gap = 0.0;
    foreach (['home','draw','away'] as $k) $gap += abs(($true_prob[$k] ?? 0) - ($tpo[$k] ?? 0));
    $gap = clampf($gap, 0.0, 1.0);
    $score = 0.0;
    $score += clampf($gap,0,1) * 0.35;
    $score += ($mme['isSharp'] ? 0.3 : 0.0);
    $score += ($mme['isTrap'] ? 0.05 : 0.0);
    $score += clampf($uds['divergence'] / 0.5, 0.0, 0.3);
    $score = clampf($score, 0.0, 1.0);
    $type = $score >= 0.7 ? 'smart_money_killer' : ($score >= 0.45 ? 'smart_money_possible' : 'no_smk');
    return ['score'=>$score,'type'=>$type,'components'=>['gap'=>$gap,'mme'=>$mme,'uds'=>$uds]];
}

// ---------- PCE ----------
function pce_project(array $market_prob, array $tpo, array $mme, array $uds) {
    $proj = $market_prob;
    $moveFrac = $mme['isSharp'] ? 0.5 : 0.15;
    if ($mme['isTrap']) $moveFrac = -0.35;
    foreach (['home','draw','away'] as $k) {
        $proj[$k] = clampf($market_prob[$k] + ($tpo[$k] - $market_prob[$k]) * $moveFrac, 0.0001, 0.9999);
    }
    $s = array_sum($proj); foreach (['home','draw','away'] as $k) $proj[$k] /= max(1e-9,$s);
    return $proj;
}

// ---------- MEM: Market Equilibrium Mapping ----------
/**
 * Estimate equilibrium baseline and compute shifts.
 * - equilibrium: blended baseline between TPO and historical baseline (EWMA)
 * - equilibrium_shift: difference between market_now and equilibrium
 */
function mem_compute(array $tpo, array $market_prob) {
    global $pdo, $CONFIG;
    // historical baseline: use EWMA master_tpo if exists (per-side)
    // For simplicity, compute EWMA on 'master_tpo_home' etc.
    $hist_home = ewma_get('master_tpo_home', $tpo['home'])['v'];
    $hist_draw = ewma_get('master_tpo_draw', $tpo['draw'])['v'];
    $hist_away = ewma_get('master_tpo_away', $tpo['away'])['v'];
    // equilibrium = blend (60% hist, 40% tpo)
    $equilibrium = [
        'home' => clampf(0.6 * $hist_home + 0.4 * $tpo['home'], 1e-6, 0.9999),
        'draw' => clampf(0.6 * $hist_draw + 0.4 * $tpo['draw'], 1e-6, 0.9999),
        'away' => clampf(0.6 * $hist_away + 0.4 * $tpo['away'], 1e-6, 0.9999),
    ];
    // normalize
    $s = array_sum($equilibrium); foreach (['home','draw','away'] as $k) $equilibrium[$k] /= max(1e-9,$s);
    // shift = market_prob - equilibrium per side
    $shift = [];
    $maxShift = 0;
    foreach (['home','draw','away'] as $k) {
        $shift[$k] = ($market_prob[$k] ?? 0) - ($equilibrium[$k] ?? 0);
        if (abs($shift[$k]) > abs($maxShift)) $maxShift = $shift[$k];
    }
    // label: if maxShift abs > threshold => shifted
    $label = abs($maxShift) > $CONFIG['mem_eq_shift_threshold'] ? 'shifted' : 'aligned';
    // persist equilibrium snapshot
    if ($pdo) {
        $mk = md5(json_encode($market_prob).time());
        $st = $pdo->prepare("INSERT INTO equilibrium_stats (match_key,tpo_json,market_json,equilibrium_json,ts) VALUES(:mk,:tpo,:mkt,:eq,:ts)");
        $st->execute([':mk'=>$mk,':tpo'=>json_encode($tpo),':mkt'=>json_encode($market_prob),':eq'=>json_encode($equilibrium),':ts'=>time()]);
    }
    return ['equilibrium'=>$equilibrium,'shift'=>$shift,'maxShift'=>$maxShift,'label'=>$label];
}

// ---------- OCM: Outcome Collapse Model ----------
/**
 * Compute collapse candidate and strength.
 * - Uses combined signals: true_prob bias, smkx, mme sharpness, mem shift
 * - collapse_strength scaled to 0..100
 */
function ocm_compute(array $true_prob, array $smkx, array $mme, array $mem) {
    global $CONFIG;
    // base candidate = argmax true_prob
    $cand = 'draw'; $best = $true_prob['draw'];
    foreach (['home','away'] as $s) if ($true_prob[$s] > $best) { $best = $true_prob[$s]; $cand = $s; }
    // compute factors
    $bias_strength = ($best - 0.33); // how far from flat
    $smk_factor = $smkx['score'] ?? 0;
    $sharp = $mme['isSharp'] ? 1.0 : 0.0;
    $shift = abs($mem['maxShift'] ?? 0);
    $strength_raw = clampf(($bias_strength * 1.5) + ($smk_factor * 0.8) + ($sharp * 0.5) + ($shift * 1.2), 0.0, 1.0);
    $strength = round($strength_raw * $CONFIG['ocm_strength_scale'],1); // 0..100
    // persist collapse if strong
    if ($strength >= 45 && $pdo) {
        $mk = md5(json_encode($true_prob).time());
        $st = $pdo->prepare("INSERT INTO collapse_events (match_key,collapse_winner,strength,meta,ts) VALUES(:mk,:c,:s,:m,:ts)");
        $st->execute([':mk'=>$mk,':c'=>$cand,':s'=>$strength,':m'=>json_encode(['true_prob'=>$true_prob,'smkx'=>$smkx,'mem'=>$mem,'mme'=>$mme]),':ts'=>time()]);
    }
    return ['collapse_winner'=>$cand,'strength'=>$strength,'components'=>['bias'=>$bias_strength,'smkx'=>$smk_factor,'sharp'=>$sharp,'shift'=>$shift]];
}

// ---------- CKP: Contradiction Kill Protocol ----------
/**
 * Detect contradictions across markets and emit kill alert if severity high.
 * Checks:
 * - 1X2 vs AH direction conflict
 * - MEM shift vs SMK-X
 * - UDS conflict count
 * Returns kill alert object or null.
 */
function ckp_analyze(array $open1, array $now1, array $ah_details, array $mem, array $smkx, array $uds, array $mme) {
    global $pdo, $CONFIG;
    $reasons = []; $severity = 0.0; $direction = null;
    // 1x2 direction
    $d_home = dir_label(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null));
    $d_away = dir_label(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null));
    // AH aggregated directions
    $ah_home_dir = 0; $ah_away_dir = 0;
    foreach ($ah_details as $ad) {
        if (($ad['dir_home'] ?? '') === 'down') $ah_home_dir--;
        if (($ad['dir_home'] ?? '') === 'up') $ah_home_dir++;
        if (($ad['dir_away'] ?? '') === 'down') $ah_away_dir--;
        if (($ad['dir_away'] ?? '') === 'up') $ah_away_dir++;
    }
    // conflict case: 1x2 says home favored but AH says away favored
    if ($d_home === 'down' && $ah_home_dir > 0) {
        $reasons[] = '1x2_home_down_vs_AH_home_up';
        $severity += 0.25;
        $direction = 'away';
    }
    if ($d_away === 'down' && $ah_away_dir > 0) {
        $reasons[] = '1x2_away_down_vs_AH_away_up';
        $severity += 0.25;
        $direction = 'home';
    }
    // MEM vs SMK-X conflict
    $memShift = $mem['maxShift'] ?? 0;
    if ($smkx['score'] >= 0.6 && abs($memShift) > 0.04) {
        $reasons[] = 'SMK_vs_MEM_mismatch';
        $severity += 0.3;
        $direction = ($memShift > 0) ? 'home' : 'away';
    }
    // UDS conflict count
    if ($uds['conflict'] > 0) {
        $reasons[] = 'UDS_multi_price_conflict';
        $severity += 0.2;
    }
    // MME trap high but market shows sharp -> contradiction
    if ($mme['isTrap'] && $mme['isSharp']) {
        $reasons[] = 'MME_trap_and_sharp';
        $severity += 0.2;
    }
    $severity = clampf($severity, 0.0, 1.0);
    if ($severity >= $CONFIG['ckp_severity_min']) {
        // persist alert
        if ($pdo) {
            $mk = md5(json_encode($now1).microtime(true));
            $st = $pdo->prepare("INSERT INTO ck_alerts (match_key,reason,direction,severity,meta,ts) VALUES(:mk,:r,:d,:s,:m,:ts)");
            $st->execute([':mk'=>$mk,':r'=>implode(';',$reasons),':d'=>$direction,':s'=>$severity,':m'=>json_encode(['reasons'=>$reasons,'mem'=>$mem,'smkx'=>$smkx,'uds'=>$uds,'mme'=>$mme]),':ts'=>time()]);
        }
        return ['kill_alert'=>true,'reason'=>$reasons,'direction'=>$direction,'severity'=>$severity];
    }
    return ['kill_alert'=>false,'reason'=>$reasons,'direction'=>$direction,'severity'=>$severity];
}

// ---------- Pattern memory ----------
function pm_pattern_lookup(array $signature) {
    global $pdo;
    if (!$pdo) return null;
    $sig = md5(json_encode($signature));
    $st = $pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:sig");
    $st->execute([':sig'=>$sig]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}
function pm_pattern_learn(array $signature, bool $win, array $meta=[]) {
    global $pdo;
    if (!$pdo) return false;
    $sig = md5(json_encode($signature));
    $st = $pdo->prepare("SELECT * FROM pattern_memory WHERE signature=:sig");
    $st->execute([':sig'=>$sig]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $now = time();
    if ($r) {
        $count = intval($r['count']) + 1;
        $prev = floatval($r['win_rate']);
        $new = (($prev * intval($r['count'])) + ($win?1.0:0.0)) / max(1,$count);
        $upd = $pdo->prepare("UPDATE pattern_memory SET count=:c, win_rate=:w, last_seen=:ls, meta=:m WHERE id=:id");
        $upd->execute([':c'=>$count,':w'=>$new,':ls'=>$now,':m'=>json_encode($meta),':id'=>$r['id']]);
    } else {
        $ins = $pdo->prepare("INSERT INTO pattern_memory (signature,count,win_rate,last_seen,meta) VALUES(:sig,1,:wr,:ls,:m)");
        $ins->execute([':sig'=>$sig,':wr'=>($win?1.0:0.0),':ls'=>$now,':m'=>json_encode($meta)]);
    }
    return true;
}

// ---------- Auto rebound helpers ----------
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

// ---------- Master analyze (TRUE VOID) ----------
function master_analyze(array $payload): array {
    global $pdo, $CONFIG;
    $home = $payload['home'] ?? 'เหย้า'; $away = $payload['away'] ?? 'เยือน';
    $league = $payload['league'] ?? 'generic';
    $kickoff = isset($payload['kickoff']) ? intval(strtotime($payload['kickoff'])) : ($payload['kickoff_ts'] ?? null);
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];
    $context = $payload['context'] ?? [];

    // AH details & pairs
    $pairs=[]; $ah_details=[]; $totalAH_mom=0.0;
    foreach ($ah_list as $i=>$r) {
        $line = $r['line'] ?? ('AH'.($i+1));
        $oh = safeFloat($r['open_home'] ?? null); $oa = safeFloat($r['open_away'] ?? null);
        $nh = safeFloat($r['now_home'] ?? null); $na = safeFloat($r['now_away'] ?? null);
        $mh = is_nan($oh)||is_nan($nh)?NAN:abs($oh-$nh);
        $ma = is_nan($oa)||is_nan($na)?NAN:abs($oa-$na);
        if (!is_nan($mh)) $totalAH_mom += $mh; if (!is_nan($ma)) $totalAH_mom += $ma;
        $ad = ['index'=>$i,'line'=>$line,'open_home'=>$oh,'open_away'=>$oa,'now_home'=>$nh,'now_away'=>$na,'net_home'=>is_nan($oh)||is_nan($nh)?0.0:$oh-$nh,'net_away'=>is_nan($oa)||is_nan($na)?0.0:$oa-$na,'mom_home'=>$mh,'mom_away'=>$ma,'dir_home'=>dir_label($oh,$nh),'dir_away'=>dir_label($oa,$na)];
        $ah_details[]=$ad; $pairs[]=['open'=>$oh,'now'=>$nh]; $pairs[]=['open'=>$oa,'now'=>$na];
    }
    // 1x2 flows & mom
    $flow1 = ['home'=> netflow(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> netflow(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> netflow(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $mom1 = ['home'=> mom_abs(safeFloat($open1['home'] ?? null), safeFloat($now1['home'] ?? null)),'draw'=> mom_abs(safeFloat($open1['draw'] ?? null), safeFloat($now1['draw'] ?? null)),'away'=> mom_abs(safeFloat($open1['away'] ?? null), safeFloat($now1['away'] ?? null))];
    $total1x2_mom = 0.0; foreach ($mom1 as $v) if (!is_nan($v)) $total1x2_mom += $v;
    $marketMomentum = $total1x2_mom + $totalAH_mom;

    // rebound
    $reboundSens = compute_auto_rebound_agg($pairs);
    if (($payload['mode'] ?? $CONFIG['mode']) === 'pre_match') $reboundSens = max($reboundSens, floatval($CONFIG['reboundSens_min']));

    // pm disparity
    $dis = pm_disparity($open1,$now1);

    // tpo estimate
    $tpo = tpo_estimate($now1,$open1);
    if (!$tpo) $tpo = ['home'=>0.33,'draw'=>0.34,'away'=>0.33];

    // mpe simulate
    $mpe = mpe_simulate($tpo, intval($CONFIG['mpe_sim_count']));

    // mme analyze
    $mme = mme_analyze($ah_details, $flow1, $kickoff);

    // uds detect
    $uds = uds_detect($ah_details,$flow1);

    // market_prob (now)
    $market_prob = [];
    $sum_now=0;
    foreach (['home','draw','away'] as $s) { $n = safeFloat($now1[$s] ?? null); $market_prob[$s] = (is_nan($n)||$n<=0)?0.0:(1.0/$n); $sum_now += $market_prob[$s]; }
    if ($sum_now>0) foreach (['home','draw','away'] as $s) $market_prob[$s] /= $sum_now;

    // true_prob blend (MPE 60%, TPO 20%, market 20%)
    $true_prob = [];
    foreach (['home','draw','away'] as $s) $true_prob[$s] = clampf(0.6*($mpe[$s] ?? 0.33) + 0.2*($tpo[$s] ?? 0.33) + 0.2*($market_prob[$s] ?? 0.33), 0.0001, 0.9999);
    $sump = array_sum($true_prob); foreach (['home','draw','away'] as $s) $true_prob[$s] /= max(1e-9,$sump);

    // pce projection
    $pce_proj = pce_project($market_prob,$tpo,$mme,$uds);

    // smkx
    $smkx = smkx_score($tpo,$true_prob,$mme,$uds);

    // mem equilibrium
    $mem = mem_compute($tpo,$market_prob);

    // ocm collapse
    $ocm = ocm_compute($true_prob,$smkx,$mme,$mem);

    // ckp check
    $ckp = ckp_analyze($open1,$now1,$ah_details,$mem,$smkx,$uds,$mme);

    // final_signal / label
    $predicted = 'ไม่แน่ใจ';
    if ($true_prob['home'] > $true_prob['away'] && $true_prob['home'] > $true_prob['draw']) $predicted = $home;
    if ($true_prob['away'] > $true_prob['home'] && $true_prob['away'] > $true_prob['draw']) $predicted = $away;

    $final_label = '⚠️ ไม่ชัดเจน'; $recommendation = 'รอ confirm';
    if ($ckp['kill_alert']) { $final_label = '❌ CKP ALERT — CONTRADICTION DETECTED'; $recommendation = 'หลีกเลี่ยง / ตรวจสอบละเอียด'; }
    elseif ($smkx['type']==='smart_money_killer' && $ocm['strength'] >= 45) { $final_label = '💥 SMK-X + OCM — Market Push'; $recommendation = 'พิจารณาตาม — จำกัดความเสี่ยง'; }
    elseif ($ocm['strength'] >= 60) { $final_label = '🔥 STRONG COLLAPSE — ตลาดบีบผล'; $recommendation = 'พิจารณาแบบผู้เชี่ยวชาญเท่านั้น'; }
    elseif ($smkx['type'] === 'smart_money_possible') { $final_label = '⚡ Smart Money Possible'; $recommendation = 'Watch / small stake only'; }

    // confidence
    $conf = 50 + ($smkx['score']*25) + (min(1.0,$marketMomentum/0.3)*10) + ($ocm['strength']/10) - ($uds['divergence']*10);
    $confidence = round(clampf($conf,0,100),1);

    // persist EWMA & samples
    ewma_update('master_netflow', floatval((($flow1['home'] ?? 0) - ($flow1['away'] ?? 0))));
    ewma_update('master_voidscore', floatval($smkx['score']));
    ewma_update('master_smk', floatval($smkx['score']));
    // persist per-side tpo for history
    if ($pdo) {
        $st = $pdo->prepare("INSERT OR REPLACE INTO ewma_store (k,v,alpha,updated_at) VALUES(:k,:v,:a,:t)");
        $th = ewma_get('master_tpo_home', $tpo['home']);
        $st->execute([':k'=>'master_tpo_home',':v'=> ($th['v']*0.85 + $tpo['home']*0.15),':a'=>$th['alpha'],':t'=>time()]);
        $td = ewma_get('master_tpo_draw', $tpo['draw']);
        $st->execute([':k'=>'master_tpo_draw',':v'=> ($td['v']*0.85 + $tpo['draw']*0.15),':a'=>$td['alpha'],':t'=>time()]);
        $ta = ewma_get('master_tpo_away', $tpo['away']);
        $st->execute([':k'=>'master_tpo_away',':v'=> ($ta['v']*0.85 + $tpo['away']*0.15),':a'=>$ta['alpha'],':t'=>time()]);
    }

    // persist match_case
    if ($pdo) {
        $mk = md5($home.'|'.$away.'|'.($kickoff?:time()));
        $ins = $pdo->prepare("INSERT INTO match_cases (match_key,kickoff_ts,league,payload,analysis,outcome,ts) VALUES(:mk,:ks,:league,:p,:a,:o,:t)");
        $ins->execute([':mk'=>$mk,':ks'=>($kickoff?:0),':league'=>$league,':p'=>json_encode($payload,JSON_UNESCAPED_UNICODE),':a'=>json_encode(['final_label'=>$final_label,'true_prob'=>$true_prob,'pce'=>$pce_proj,'smkx'=>$smkx,'mem'=>$mem,'ocm'=>$ocm,'ckp'=>$ckp],JSON_UNESCAPED_UNICODE),':o'=>'',':t'=>time()]);
    }

    // final response
    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'league'=>$league,'kickoff_ts'=>$kickoff,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list],
        'metrics'=>[
            'marketMomentum'=>$marketMomentum,
            'total1x2_mom'=>$total1x2_mom,
            'totalAH_mom'=>$totalAH_mom,
            'reboundSens'=>$reboundSens,
            'disparity'=>$dis,
            'tpo'=>$tpo,
            'mpe'=>$mpe,
            'mme'=>$mme,
            'uds'=>$uds,
            'smkx'=>$smkx,
            'mem'=>$mem,
            'ocm'=>$ocm,
            'ckp'=>$ckp
        ],
        'void_score'=>$smkx['score'],
        'final_label'=>$final_label,
        'recommendation'=>$recommendation,
        'predicted_winner'=>$predicted,
        'true_prob'=>$true_prob,
        'market_prob_now'=>$market_prob,
        'pce_projection'=>$pce_proj,
        'ah_details'=>$ah_details,
        'confidence'=>$confidence
    ];
}

// ---------- HTTP endpoints ----------
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
        header('Content-Type: application/json; charset=utf-utf-8');
        if (!$pdo) { echo json_encode(['status'=>'error','msg'=>'no_db']); exit; }
        $st = $pdo->query("SELECT id,match_key,kickoff_ts,league,ts FROM match_cases ORDER BY id DESC LIMIT 100");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','cases'=>$data], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }
}

// ---------- Minimal frontend for quick test ----------
?><!doctype html>
<html lang="th">
<head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>THE TRUE VOID ENGINE</title>
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
  <div class="card"><h2 style="margin:0">THE TRUE VOID ENGINE — Assembled</h2><div class="small">MEM • OCM • CKP • SMK-X • EWMA-LD • Pattern Memory</div></div>

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
    <div style="display:flex;justify-content:flex-end"><button id="analyzeBtn" class="btn">🔎 Analyze</button></div>
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
    open1: {home: toNum(document.getElementById('open_home').value), draw: toNum(document.getElementById('open_draw').value), away: toNum(document.getElementById('open_away').value)},
    now1: {home: toNum(document.getElementById('now_home').value), draw: toNum(document.getElementById('now_draw').value), away: toNum(document.getElementById('now_away').value)},
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
