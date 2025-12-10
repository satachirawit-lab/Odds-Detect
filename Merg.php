<?php
declare(strict_types=1);
/*
 Last_void_merge.php — THE VOID MERGE ENGINE (Full)
 - Backup Last.php if exists
 - Include Last.php to preserve original logic safely
 - SQLite-backed EWMA & learning store (void_engine.db)
 - Modules: SMK 3.0, FDA (Flow Divergence Analyzer), EWMA adaptive alpha, VOID compression
 - API endpoints:
    - ?action=void_analyze  (POST JSON)
    - ?action=ewma_stats    (GET) -> show EWMA store debug
 - Single-file UI: Chinese-ancient theme, dragon, smoke, scroll, responsive
 - IMPORTANT: This file DOES NOT overwrite Last.php automatically; it will create a backup if you choose to integrate later.
*/

// ---------------- Safety & env ----------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
$basedir = __DIR__;
$last_path = $basedir . '/Last.php';
date_default_timezone_set('Asia/Bangkok');

// ---------------- auto-backup Last.php if present ----------------
if (file_exists($last_path)) {
    $bak = $basedir . '/Last.php.bak.' . date('Ymd_His');
    if (!file_exists($bak)) {
        @copy($last_path, $bak);
    }
}

// ---------------- include original (non-fatal) ----------------
if (file_exists($last_path)) {
    try {
        require_once $last_path;
    } catch (\Throwable $e) {
        error_log("Last.php include error: " . $e->getMessage());
    }
}

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

// ---------------- EWMA helpers (get/update/set alpha) ----------------
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
    // auto-tune alpha occasionally
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

// ---------------- Adaptive alpha tuning (simple heuristic) ----------------
function adaptive_alpha_tune(string $k) {
    global $pdo;
    if (!$pdo) return;
    // Check last N samples variance; higher variance => larger alpha
    $N = 30;
    $st = $pdo->prepare("SELECT sample FROM samples WHERE k=:k ORDER BY id DESC LIMIT :n");
    $st->bindValue(':k',$k);
    $st->bindValue(':n',$N,PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_COLUMN,0);
    if (!$rows || count($rows)<6) return; // insufficient data
    $arr = array_map('floatval',$rows);
    $mean = array_sum($arr)/count($arr);
    $var = 0.0; foreach ($arr as $v) $var += pow($v-$mean,2);
    $std = sqrt($var / count($arr));
    // heuristic: std normalized to a suggested alpha between 0.02..0.6
    $norm = clampf($std / max(1e-6, abs($mean)+1e-6), 0.0, 1.0);
    $newAlpha = 0.02 + ($norm * 0.58);
    // bound and write
    ewma_set_alpha($k, $newAlpha);
}

// ---------------- Utility helpers ----------------
function safeFloat($v){ if(!isset($v)) return NAN; $s = trim((string)$v); if ($s==='') return NAN; $s=str_replace([',',' '],['.',''],$s); return is_numeric($s) ? floatval($s) : NAN; }
function netflow($open,$now){ if(is_nan($open) || is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n = netflow($open,$now); return is_nan($n) ? NAN : abs($n); }
function dir_label($open,$now){ if(is_nan($open) || is_nan($now)) return 'flat'; if ($now < $open) return 'down'; if ($now > $open) return 'up'; return 'flat'; }
function clampf($v,$a,$b){ return max($a,min($b,$v)); }

// ---------------- Compute auto rebound (aggregated) ----------------
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

// ---------------- SMK 3.0 — Smart Money Killer (core heuristics) ----------------
function smk3_score(array $ah_details, array $flow1, float $juicePressureNorm, float $stackFactor, float $divergence) {
    $score = 0.0;
    $flags = [];
    // high juice -> strong signal
    if ($juicePressureNorm > 0.35) { $score += 0.35; $flags[] = 'juice_pressure'; }
    // stacked lines -> concentration of moves
    if ($stackFactor > 0.6) { $score += 0.25; $flags[] = 'stacked_lines'; }
    // divergence -> suspicion
    if ($divergence > 0.12) { $score += 0.12; $flags[] = 'divergence'; }
    // AH->1x2 lag detection
    foreach ($ah_details as $ad) {
        // if AH moved strongly but 1x2 did not follow -> suspicious
        $ah_move = abs(($ad['open_home'] ?? 0) - ($ad['now_home'] ?? 0)) + abs(($ad['open_away'] ?? 0) - ($ad['now_away'] ?? 0));
        if ($ah_move > 0.12) {
            $score += 0.08;
            $flags[] = 'ah_strong_move';
        }
    }
    // flow1 asymmetry (big imbalance)
    $imbalance = abs(($flow1['home'] ?? 0) - ($flow1['away'] ?? 0));
    if ($imbalance > 0.12) { $score += 0.10; $flags[] = 'flow_imbalance'; }
    // normalize
    $score = clampf($score, 0.0, 1.0);
    return ['score'=>$score,'flags'=>array_values(array_unique($flags))];
}

// ---------------- FDA (Flow Divergence Analyzer) ----------------
function fda_analyze(array $ah_details, array $flow1, float $reboundSens): array {
    // calculate divergence angle, intensity, pattern
    $angles = []; $intensity = 0.0; $patterns = [];
    foreach ($ah_details as $ad) {
        $hNet = $ad['net_home'] ?? 0; $aNet = $ad['net_away'] ?? 0;
        // compute simple divergence metric between AH net and 1x2 flow
        $home_diff = abs(($flow1['home'] ?? 0) - $hNet);
        $away_diff = abs(($flow1['away'] ?? 0) - $aNet);
        $ang = ($home_diff + $away_diff) / 2.0;
        $angles[] = $ang;
        $intensity += $ang;
        // pattern heuristics
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

// ---------------- Void compression aggregator ----------------
function void_compress_score(array $metrics): float {
    // metrics keys: flowPower, confidence, smartMoneyScore, divergence, juicePressureNorm, stackFactor, trap
    $w = [
        'flowPower' => 0.30,
        'confidence' => 0.22,
        'smartMoneyScore' => 0.18,
        'juicePressureNorm' => 0.12,
        'stackFactor' => 0.10,
        'divergence' => -0.12
    ];
    $score = 0.0;
    foreach ($w as $k => $wt) {
        $val = isset($metrics[$k]) ? floatval($metrics[$k]) : 0.0;
        if (in_array($k,['flowPower','confidence'])) $val = $val / 100.0;
        if ($k === 'divergence') $val = clampf($val / 0.3, 0.0, 1.0);
        $score += $wt * $val;
    }
    if (!empty($metrics['trap'])) $score *= 0.28;
    return clampf($score, -1.0, 1.0);
}

// ---------------- Main void analyze function ----------------
function void_engine_analyze(array $payload): array {
    // parse inputs
    $home = $payload['home'] ?? 'เหย้า';
    $away = $payload['away'] ?? 'เยือน';
    $favorite = $payload['favorite'] ?? 'none';
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];

    // prepare pairs for rebound
    $pairs = [];
    $pairs[] = ['open'=>$open1['home'] ?? NAN,'now'=>$now1['home'] ?? NAN];
    $pairs[] = ['open'=>$open1['away'] ?? NAN,'now'=>$now1['away'] ?? NAN];

    // AH details
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

    // detect trap (using rebound sensitivity)
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

    // stack factor (count of lines favoring each side)
    $stackHome=0;$stackAway=0;
    foreach($ah_details as $ls){
        $hRel = (!is_nan($ls['open_home']) && $ls['open_home']>0) ? (($ls['now_home'] - $ls['open_home']) / $ls['open_home']) : 0;
        $aRel = (!is_nan($ls['open_away']) && $ls['open_away']>0) ? (($ls['now_away'] - $ls['open_away']) / $ls['open_away']) : 0;
        if ($hRel < 0 || $aRel < 0) $stackHome++;
        if ($hRel > 0 || $aRel > 0) $stackAway++;
    }
    $stackMax = max($stackHome,$stackAway);
    $stackFactor = clampf($stackMax / max(1, count($ah_details)), 0.0, 1.0);

    // SMK 3.0 scoring
    $smk = smk3_score($ah_details, $flow1, $juicePressureNorm, $stackFactor, $divergence);
    $smartMoneyScore = $smk['score']; $smartFlags = $smk['flags'];

    // FDA analyze
    $fda = fda_analyze($ah_details, $flow1, $reboundSens);

    // rawSignal (combined)
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

    // direction approx using flows
    $dirScore = 0.0; $dirScore += (($flow1['home'] ?? 0) - ($flow1['away'] ?? 0)) * 0.5;
    $dirNorm = ($rawSignal > 1e-6) ? tanh($dirScore / (0.5 + $marketMomentum)) : 0.0;
    $hackScore = clampf($rawSignal * $dirNorm * 1.5, -1.0, 1.0);

    // confidence & flowPower
    $confidence = round(min(100, max(0, abs($hackScore) * 120 + ($w_juice * 20))), 1);
    $flowPower = round(min(100, max(0, (abs($hackScore)*0.6 + $w_sync*0.2 + $w_juice*0.2 + $smartMoneyScore*0.15) * 100)));

    // market kill detection
    $signature = []; $market_kill=false;
    if ($flowPower >= 88 && $confidence >= 82 && $stackFactor > 0.65 && !$trap) { $market_kill = true; $signature[]='STACK+SHARP+HIGH_FLOW'; }
    if ($trap && $flowPower < 40) { $signature[]='TRAP_DETECTED'; }
    if ($divergence > 0.22) { $signature[]='ULTRA_DIV'; }

    // VOID compress
    $metrics_for_void = [
        'flowPower'=>$flowPower,'confidence'=>$confidence,'smartMoneyScore'=>$smartMoneyScore,
        'juicePressureNorm'=>$juicePressureNorm,'stackFactor'=>$stackFactor,'divergence'=>$divergence,'trap'=>$trap
    ];
    $void_score = void_compress_score($metrics_for_void);

    // final labels
    if ($market_kill) {
        $final_label = '💀 MARKET KILL — ห้ามเสี่ยง';
        $recommendation = 'ห้ามเสี่ยง/ควบคุมเดิมพันเข้มงวด';
    } elseif ($void_score > 0.35) {
        $final_label = '✅ VOID: ไหลจริง — ฝั่งเหย้า';
        $recommendation = 'พิจารณาตาม (จัดการความเสี่ยง)';
    } elseif ($void_score < -0.35) {
        $final_label = '✅ VOID: ไหลจริง — ฝั่งเยือน';
        $recommendation = 'พิจารณาตาม (จัดการความเสี่ยง)';
    } elseif ($trap) {
        $final_label = '❌ VOID: สัญญาณกับดัก';
        $recommendation = 'ไม่แนะนำเดิมพัน';
    } else {
        $final_label = '⚠️ VOID: ไม่ชัดเจน — สัญญาณผสม';
        $recommendation = 'รอ confirm';
    }

    // predicted winner heuristic
    $agg = 0.0;
    $agg += (is_nan($flow1['home']) ? 0 : $flow1['home']) * 2.0;
    $agg -= (is_nan($flow1['away']) ? 0 : $flow1['away']) * 2.0;
    foreach ($ah_details as $ad) {
        $agg += (($ad['net_home'] ?? 0) - ($ad['net_away'] ?? 0));
    }
    $predicted_winner = 'ไม่แน่ใจ'; if ($agg > 0.12) $predicted_winner = $home; if ($agg < -0.12) $predicted_winner = $away;

    // update EWMA store for long-term learning
    ewma_update('void_netflow', floatval($agg));
    ewma_update('void_rebound', floatval($reboundSens));
    ewma_update('void_voidscore', floatval($void_score));
    // store smk and fda summary
    ewma_update('void_smk', floatval($smartMoneyScore));
    ewma_update('void_fda', floatval($fda['intensity'] ?? 0.0));

    // assemble response
    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'favorite'=>$favorite,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list],
        'metrics'=>array_merge($metrics_for_void,['marketMomentum'=>$marketMomentum,'total1x2_mom'=>$total1x2_mom,'totalAH_mom'=>$totalAH_mom,'reboundSens'=>$reboundSens,'flowPower'=>$flowPower,'confidence'=>$confidence,'signature'=>$signature,'trapFlags'=>$trapFlags,'fda'=>$fda,'smk_flags'=>$smartFlags]),
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

// ---------------- UI ----------------
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>THE VOID — Imperial Odds Analyzer (Merged)</title>
<style>
:root{
  --gold:#d4a017; --royal:#5b21b6; --card:#0f0b0a; --muted:#d9c89a; --danger:#ff3b30;
  --jade1:#7ee2c7; --ruby:#b00010; --void:#060409;
}
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
.void-result{font-size:1.05rem;padding:12px;border-radius:12px;background:linear-gradient(90deg,#091018,#1a0f16);border:1px solid rgba(255,255,255,0.02);color:#dfe7ff}
.void-score{font-weight:900;font-size:1.6rem;color:var(--jade1)}
.scroll-btn{cursor:pointer;background:transparent;border:0;color:var(--gold);font-weight:900}
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="logo">鴻</div>
        <div>
          <h1 style="margin:0;color:var(--gold)">THE VOID — Imperial Odds Analyzer (Merged)</h1>
          <div style="color:#d9c89a;font-size:0.95rem">SMK3.0 • FDA • EWMA learning (SQLite) • Void compression</div>
        </div>
      </div>
      <div><button id="voidToggle" class="btn">Toggle VOID Mode</button></div>
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
// Utilities
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function nf(v,d=4){ return (v===null||v===undefined||isNaN(v))?'-':Number(v).toFixed(d); }
function clamp(v,a,b){ return Math.max(a,Math.min(b,v)); }

// AH UI
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

// Ink splash
function spawnInk(){
  const wrap=document.getElementById('inkCanvasWrap'); wrap.innerHTML='';
  const canvas=document.createElement('canvas');
  canvas.width=wrap.clientWidth; canvas.height=220; wrap.appendChild(canvas);
  const ctx=canvas.getContext('2d');
  for(let i=0;i<10;i++){
    const x=Math.random()*canvas.width; const y=Math.random()*canvas.height; const r=8+Math.random()*50;
    ctx.fillStyle='rgba(11,8,8,'+(0.02+Math.random()*0.08)+')';
    ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill();
    for(let j=0;j<16;j++){ const rx=x+(Math.random()-0.5)*r*4; const ry=y+(Math.random()-0.5)*r*4; const rr=Math.random()*8; ctx.fillRect(rx,ry,rr,rr); }
  }
}

// collect payload
function collectPayload(){ const home=document.getElementById('home').value.trim()||'ทีมเหย้า'; const away=document.getElementById('away').value.trim()||'ทีมเยือน'; const favorite=document.getElementById('favorite').value||'none'; const open1={home:toNum(document.getElementById('open1_home').value), draw:toNum(document.getElementById('open1_draw').value), away:toNum(document.getElementById('open1_away').value)}; const now1={home:toNum(document.getElementById('now1_home').value), draw:toNum(document.getElementById('now1_draw').value), away:toNum(document.getElementById('now1_away').value)}; const ahNodes=Array.from(document.querySelectorAll('#ahContainer .ah-block')); const ah=ahNodes.map(n=>({ line:n.querySelector('input[name=ah_line]').value, open_home:toNum(n.querySelector('input[name=ah_open_home]').value), open_away:toNum(n.querySelector('input[name=ah_open_away]').value), now_home:toNum(n.querySelector('input[name=ah_now_home]').value), now_away:toNum(n.querySelector('input[name=ah_now_away]').value)})); return {home,away,favorite,open1,now1,ah,options:{}}; }

// analyze
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

// render
function renderResult(r){
  document.getElementById('voidScoreValue').innerText = (r.void_score!==undefined)?(r.void_score.toFixed(4)): '--';
  document.getElementById('confValue').innerText = r.metrics && r.metrics.confidence!==undefined ? (r.metrics.confidence+'%') : '--%';
  document.getElementById('flowPowerValue').innerText = r.metrics && r.metrics.flowPower!==undefined ? r.metrics.flowPower : '--';
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
  // SMK & FDA display
  if (r.metrics && r.metrics.smk_flags && r.metrics.smk_flags.length) {
    html2 += `<div style="margin-top:8px;background:rgba(255,140,54,0.04);padding:8px;border-radius:8px"><strong>SMK Flags:</strong> ${r.metrics.smk_flags.join(', ')}</div>`;
  }
  if (r.metrics && r.metrics.fda) {
    html2 += `<div style="margin-top:8px;background:rgba(120,140,255,0.04);padding:8px;border-radius:8px"><strong>FDA:</strong> Phase ${r.metrics.fda.phase} — Intensity ${r.metrics.fda.intensity.toFixed(2)}</div>`;
  }
  dt.innerHTML=html2;

  const tome = document.getElementById('tome');
  let tHtml = `<div class="card" style="padding:12px;background:linear-gradient(180deg,#fff6e1,#f7e2b8);color:#2b1708"><h3>คัมภีร์ลับ — VOID INSIGHTS</h3>`;
  tHtml += `<div><strong>Void Score:</strong> ${(r.void_score!==undefined)?r.void_score.toFixed(4):'-'}</div>`;
  if (r.metrics && r.metrics.signature && r.metrics.signature.length>0) tHtml += `<div style="margin-top:8px"><strong>Signature:</strong> ${r.metrics.signature.join(', ')}</div>`;
  if (r.metrics && r.metrics.trapFlags && r.metrics.trapFlags.length>0) tHtml += `<div style="margin-top:8px"><strong>Trap Flags:</strong> ${r.metrics.trapFlags.join(', ')}</div>`;
  if (r.metrics && r.metrics.smk_flags && r.metrics.smk_flags.length>0) tHtml += `<div style="margin-top:8px"><strong>SMK Flags:</strong> ${r.metrics.smk_flags.join(', ')}</div>`;
  if (r.metrics && r.metrics.fda) tHtml += `<div style="margin-top:8px"><strong>Divergence Phase:</strong> ${r.metrics.fda.phase} (${r.metrics.fda.intensity.toFixed(2)})</div>`;
  tHtml += `<div style="margin-top:8px"><strong>สรุป:</strong> ${r.final_label}</div></div>`;
  tome.innerHTML = tHtml; tome.style.display='block';
}

// EWMA debug button
document.getElementById('ewmaDebug').addEventListener('click', async ()=>{
  const res = await fetch('?action=ewma_stats'); const j = await res.json();
  alert(JSON.stringify(j, null, 2));
});

// prevent mobile zoom simple
document.querySelectorAll('input,select,textarea').forEach(i=>{ i.addEventListener('focus', ()=>{ document.documentElement.style.fontSize='16px'; }); });

</script>
</body>
</html>
