<?php
declare(strict_types=1);
/*
  Last_merged.php
  Wrapper / Integrator for Last.php
  - ไม่ลบไฟล์ Last.php เดิม
  - เพิ่ม: UI (จีนโบราณ), EWMA learning (file-backed), Smart Money Killer, NetFlow, Divergence
  - Scroll คัมภีร์, Dragon/Smoke/Ink effects, Responsive fixes
  - Usage: put this file alongside Last.php and open Last_merged.php
*/

/* ---------------- Safety & Setup ---------------- */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
$basedir = __DIR__;
$last_base = $basedir . '/Last.php';
if (!file_exists($last_base)) {
    // If Last.php missing, we show message but still run wrapper UI and engine
    $last_included = false;
} else {
    $last_included = true;
}

/* ---------------- EWMA store (file-backed) ---------------- */
$ewma_file = $basedir . '/ewma_store.json';
if (!file_exists($ewma_file)) {
    $defaults = [
        'home_prob'=>['value'=>0.3333,'alpha'=>0.28],
        'draw_prob'=>['value'=>0.3333,'alpha'=>0.28],
        'away_prob'=>['value'=>0.3333,'alpha'=>0.28],
        'net_flow'=>['value'=>0.0,'alpha'=>0.22],
        'rebound'=>['value'=>0.02,'alpha'=>0.12],
    ];
    @file_put_contents($ewma_file, json_encode($defaults, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
function ewma_read() {
    global $ewma_file;
    $s = @file_get_contents($ewma_file);
    $a = json_decode($s, true);
    return is_array($a) ? $a : [];
}
function ewma_update_sample(string $key, float $sample) {
    global $ewma_file;
    $map = ewma_read();
    if (!isset($map[$key])) {
        $map[$key] = ['value'=>$sample, 'alpha'=>0.25];
    } else {
        $alpha = isset($map[$key]['alpha']) ? floatval($map[$key]['alpha']) : 0.25;
        $map[$key]['value'] = ($alpha * $sample) + ((1.0 - $alpha) * floatval($map[$key]['value']));
    }
    @file_put_contents($ewma_file, json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    return $map[$key] ?? null;
}

/* ---------------- Utility functions ---------------- */
function safeFloat($v){ if(!isset($v)) return NAN; $s = trim((string)$v); if ($s==='') return NAN; $s=str_replace([',',' '], ['.',''], $s); return is_numeric($s) ? floatval($s) : NAN; }
function netflow($open,$now){ if(is_nan($open) || is_nan($now)) return NAN; return $open - $now; }
function mom_abs($open,$now){ $n = netflow($open,$now); return is_nan($n) ? NAN : abs($n); }
function dir_label($open,$now){ if(is_nan($open) || is_nan($now)) return 'flat'; if ($now < $open) return 'down'; if ($now > $open) return 'up'; return 'flat'; }

/* ---------------- Smart Money / Engine (Core) ----------------
   This engine runs independently of Last.php — it uses user's input payload to compute:
   - NetFlow
   - Divergence AH vs 1x2
   - Smart Money Score
   - Trap flags
   - Rebound sensitivity (auto)
   - FlowPower and confidence
----------------------------------------------------------- */

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

/* Analyze payload (same contract as UI will send) */
function merged_analyze(array $payload): array {
    $home = $payload['home'] ?? 'เหย้า';
    $away = $payload['away'] ?? 'เยือน';
    $favorite = $payload['favorite'] ?? 'none';
    $open1 = $payload['open1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $now1  = $payload['now1'] ?? ['home'=>NAN,'draw'=>NAN,'away'=>NAN];
    $ah_list = $payload['ah'] ?? [];

    // pairs for rebound calc
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
        $ah_details[] = [
            'index'=>$i,'line'=>$line,
            'open_home'=>$oh,'open_away'=>$oa,'now_home'=>$nh,'now_away'=>$na,
            'net_home'=>netflow($oh,$nh),'net_away'=>netflow($oa,$na),'mom_home'=>$mh,'mom_away'=>$ma,
            'dir_home'=>dir_label($oh,$nh),'dir_away'=>dir_label($oa,$na)
        ];
        $pairs[] = ['open'=>$oh,'now'=>$nh];
        $pairs[] = ['open'=>$oa,'now'=>$na];
    }

    // 1x2 momentum
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

    $reboundSens = compute_auto_rebound_agg($pairs);
    // divergence & conflict
    $divergence = abs($totalAH_mom - $total1x2_mom);
    $isUltraDivergence = $divergence > 0.12;

    // trap detection
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

    // SMK heuristics (juice pressure, concentration, stack)
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
        if(($ls['hRel'] ?? null) === null){
            // compute quick
            $hRel = (!is_nan($ls['open_home']) && $ls['open_home']>0) ? (($ls['now_home'] - $ls['open_home']) / $ls['open_home']) : 0;
            $aRel = (!is_nan($ls['open_away']) && $ls['open_away']>0) ? (($ls['now_away'] - $ls['open_away']) / $ls['open_away']) : 0;
            if ($hRel < 0 || $aRel < 0) $stackHome++;
            if ($hRel > 0 || $aRel > 0) $stackAway++;
        } else {
            if ($ls['hRel'] < 0 || $ls['aRel'] < 0) $stackHome++;
            if ($ls['hRel'] > 0 || $ls['aRel'] > 0) $stackAway++;
        }
    }
    $stackMax = max($stackHome, $stackAway);
    $stackFactor = $stackMax / max(1, max(1, count($ah_details)));

    // smart money score (heuristic)
    $smartMoneyScore = 0.0; $smartFlags = [];
    if ($juicePressureNorm > 0.3) { $smartMoneyScore += 0.35; $smartFlags[] = 'juice_pressure'; }
    if ($stackFactor > 0.6) { $smartMoneyScore += 0.2; $smartFlags[] = 'stacked_lines'; }
    if ($isUltraDivergence) { $smartMoneyScore += 0.15; $smartFlags[] = 'divergence'; }
    $smartMoneyScore = min(1.0, $smartMoneyScore);

    // aggregate raw signal (weighted)
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

    // direction approximate
    $dirScore = 0.0;
    $dirScore += (($flow1['home'] ?? 0) - ($flow1['away'] ?? 0)) * 0.5;
    // normalize
    $dirNorm = 0.0;
    if ($rawSignal > 0.0001) $dirNorm = tanh($dirScore / (0.5 + $marketMomentum));
    $hackScore = max(-1.0, min(1.0, ($rawSignal * $dirNorm * 1.5)));

    $confidence = round(min(100, max(0, abs($hackScore) * 120 + ($w_juice * 20))), 1);
    $flowPower = round(min(100, max(0, (abs($hackScore)*0.6 + $w_sync*0.2 + $w_juice*0.2 + $smartMoneyScore*0.15) * 100)));

    // signatures
    $signature = [];
    $market_kill = false;
    if ($flowPower >= 88 && $confidence >= 82 && $stackFactor > 0.65 && !$trap) { $market_kill = true; $signature[] = 'STACK+SHARP+HIGH_FLOW'; }
    if ($trap && $flowPower < 40) { $signature[] = 'TRAP_DETECTED'; }
    if ($isUltraDivergence && $divergence > 0.22) { $signature[] = 'ULTRA_DIV'; }

    // predicted winner (simple agg)
    $agg = 0.0;
    $agg += (is_nan($flow1['home']) ? 0 : $flow1['home']) * 2.0;
    $agg -= (is_nan($flow1['away']) ? 0 : $flow1['away']) * 2.0;
    foreach ($ah_details as $ad) {
        $agg += (($ad['net_home'] ?? 0) - ($ad['net_away'] ?? 0));
    }
    $predicted_winner = 'ไม่แน่ใจ'; if ($agg > 0.12) $predicted_winner = $home; if ($agg < -0.12) $predicted_winner = $away;

    // update EWMA learning samples
    ewma_update_sample('net_flow', floatval($agg));
    ewma_update_sample('rebound', floatval($reboundSens));

    // final label
    if ($market_kill) {
        $final_label = '💀 MARKET KILL — ไหลแรงครบทุกเงื่อนไข (ห้ามเสี่ยง)';
        $recommendation = 'หลีกเลี่ยงหรือวางเดิมพันจำกัดเฉพาะผู้เชี่ยวชาญ';
    } else if ($hackScore > 0.35) {
        $final_label = '✅ ไหลจริง (โจมตีฝั่งเหย้า)';
        $recommendation = 'พิจารณาตาม — ควบคุมความเสี่ยง';
    } else if ($hackScore < -0.35) {
        $final_label = '✅ ไหลจริง (โจมตีฝั่งเยือน)';
        $recommendation = 'พิจารณาตาม — ควบคุมความเสี่ยง';
    } else if ($trap) {
        $final_label = '❌ สัญญาณก้ำกึ่ง — พบกับดัก';
        $recommendation = 'ไม่แนะนำเดิมพัน';
    } else {
        $final_label = '⚠️ ไม่ชัดเจน — สัญญาณผสม';
        $recommendation = 'รอ confirm';
    }

    return [
        'status'=>'ok',
        'input'=>['home'=>$home,'away'=>$away,'favorite'=>$favorite,'open1'=>$open1,'now1'=>$now1,'ah'=>$ah_list],
        'metrics'=>[
            'marketMomentum'=>$marketMomentum,
            'total1x2_mom'=>$total1x2_mom,
            'totalAH_mom'=>$totalAH_mom,
            'divergence'=>$divergence,
            'reboundSens'=>$reboundSens,
            'juicePressure'=>$juicePressure,
            'juicePressureNorm'=>$juicePressureNorm,
            'stackFactor'=>$stackFactor,
            'smartMoneyScore'=>$smartMoneyScore,
            'flowPower'=>$flowPower,
            'confidence'=>$confidence,
            'signature'=>$signature,
            'trap'=>$trap,
            'trapFlags'=>$trapFlags
        ],
        'final_label'=>$final_label,
        'recommendation'=>$recommendation,
        'predicted_winner'=>$predicted_winner,
        'hackScore'=>$hackScore,
        'ah_details'=>$ah_details
    ];
}

/* ---------------- HTTP API (wrapper) ---------------- */
if (php_sapi_name() !== 'cli') {
    // If Post to ?action=merged_analyze => run merged engine
    if (isset($_GET['action']) && $_GET['action'] === 'merged_analyze' && $_SERVER['REQUEST_METHOD']==='POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            header('Content-Type:application/json; charset=utf-8');
            echo json_encode(['status'=>'error','msg'=>'invalid_payload','raw'=>strlen($raw)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
            exit;
        }
        $res = merged_analyze($payload);
        header('Content-Type:application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }
}

/* ---------------- UI: Inject frontend wrapper (Chinese fantasy theme) ---------------- */
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>หงหยาต้ง — Imperial Odds Analyzer (MERGED)</title>
<style>
:root{
  --gold:#d4a017; --royal:#5b21b6; --card:#0f0b0a; --muted:#d9c89a; --danger:#ff3b30;
  --jade1:#7ee2c7; --jade2:#1aa07a; --ruby:#b00010;
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter, 'Noto Sans Thai', Arial;background:linear-gradient(180deg,#0b0810,#120916);color:#f6eedf; -webkit-font-smoothing:antialiased}
.container{max-width:1120px;margin:18px auto;padding:18px}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.logo{width:80px;height:80px;border-radius:12px;background:radial-gradient(circle at 30% 20%, rgba(255,255,255,0.06), rgba(91,33,182,0.95));display:flex;align-items:center;justify-content:center;color:white;font-weight:900;font-size:32px}
.card{background:linear-gradient(145deg,#140b07,#2a1a13);border-radius:14px;padding:16px;margin-top:14px;border:1px solid rgba(212,160,23,0.06)}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
label{font-size:0.95rem;color:#f3e6cf}
input, select{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,235,200,0.05);background:rgba(255,255,255,0.02);color:#ffecc9}
.btn{padding:10px 14px;border-radius:12px;border:none;color:#110b06;background:linear-gradient(90deg,var(--royal),var(--gold));cursor:pointer}
.resultWrap{display:flex;gap:14px;align-items:flex-start;margin-top:14px}
.analysisCard{flex:1;padding:18px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));position:relative}
.sidePanel{width:380px;padding:14px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01))}
.dragon{position:absolute;right:-60px;top:-40px;width:360px;height:180px;pointer-events:none;opacity:0.95;mix-blend-mode:screen}
.ink-canvas{position:absolute;left:0;bottom:0;width:100%;height:200px;pointer-events:none;opacity:0.12}
.tome{margin-top:12px;padding:12px;border-radius:10px;background:linear-gradient(180deg,#0e0710,#231217);border:1px solid rgba(212,160,23,0.06);color:#ffdba1}
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th,.table td{padding:8px;border-bottom:1px dashed rgba(255,235,200,0.03);text-align:left}
.alarm{padding:10px;border-radius:8px;background:linear-gradient(90deg,rgba(255,40,40,0.95),rgba(200,20,20,0.9));color:white;font-weight:900;text-align:center;margin-bottom:10px;animation:shake 0.9s infinite alternate}
@keyframes shake{0%{transform:translateX(-4px)}100%{transform:translateX(4px)}}
.pulse{animation:pulse 1.6s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 rgba(255,59,48,0.0)}50%{box-shadow:0 0 20px rgba(255,59,48,0.18)}100%{box-shadow:0 0 0 rgba(255,59,48,0.0)}}
@media(max-width:980px){.grid{grid-template-columns:1fr}.sidePanel{width:100%}.dragon{display:none}}
/* Additional UI bits */
.imperial-btn{position:relative;display:inline-block;padding:10px 14px;border-radius:12px;border:none;background:linear-gradient(180deg,var(--jade1),var(--jade2));color:#07110b;font-weight:800;box-shadow:0 8px 24px rgba(0,0,0,0.6)}
.spark{position:absolute;width:12px;height:12px;border-radius:50%;background:radial-gradient(circle,#ffd7d7,#ff6b6b 60%);pointer-events:none}
.tome-panel{position:fixed;right:20px;bottom:20px;width:420px;max-height:76vh;overflow:auto;display:none;z-index:9999;border-radius:12px}
.tome-panel.open{display:block;animation:pop .45s ease}
@keyframes pop{0%{transform:translateY(30px);opacity:0}100%{transform:translateY(0);opacity:1}}
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="logo">鴻</div>
        <div>
          <h1 style="margin:0;color:var(--gold)">หงหยาต้ง — Imperial Odds Analyzer (MERGED)</h1>
          <div style="color:#d9c89a;font-size:0.95rem">รวมระบบต้นแบบ + Smart Money Killer + UI จีนโบราณ — ไม่แตะไฟล์ฐาน</div>
        </div>
      </div>
      <div><button id="modeBtn" class="btn">โหมด: จีนโบราณ</button></div>
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
        <div style="text-align:right"><button id="analyzeBtn" class="btn">🔎 วิเคราะห์สุดโหด</button></div>
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
          <div id="tome" class="tome-panel" aria-hidden="true"></div>
        </div>

        <div class="sidePanel">
          <div style="text-align:center">
            <div class="kpi"><div class="num" id="confValue">--%</div></div>
            <div class="small">Confidence</div>
            <div style="height:8px"></div>
            <div class="kpi"><div class="num" id="flowPowerValue">--</div></div>
            <div class="small">Flow Power (0–100)</div>
            <div style="height:8px"></div>
            <div class="kpi"><div class="num" id="smartValue">--</div></div>
            <div class="small">Smart Money</div>
            <div style="height:16px"></div>
            <div id="stakeSuggestion" style="padding:12px;border-radius:10px;text-align:center;color:#ffdca8"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card small" style="margin-top:12px"><strong>หมายเหตุ</strong><div class="small">ระบบนี้วิเคราะห์จากราคาที่ผู้ใช้กรอกเท่านั้น — Auto-Rebound คำนวณสดจาก Open/Now ที่กรอก — EWMA learning ถูกเก็บภายในเซิร์ฟเวอร์</div></div>
  </div>

<script>
/* Utility */
function toNum(v){ if(v===null||v==='') return NaN; return Number(String(v).replace(',','.')); }
function nf(v,d=4){ return (v===null||v===undefined||isNaN(v))?'-':Number(v).toFixed(d); }

/* AH UI handlers */
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


/* Ink splash art */
function spawnInk(){
  const wrap=document.getElementById('inkCanvasWrap'); wrap.innerHTML='';
  const canvas=document.createElement('canvas');
  canvas.width=wrap.clientWidth; canvas.height=200; wrap.appendChild(canvas);
  const ctx=canvas.getContext('2d');
  for(let i=0;i<8;i++){
    const x=Math.random()*canvas.width; const y=Math.random()*canvas.height; const r=8+Math.random()*40;
    ctx.fillStyle='rgba(11,8,8,'+(0.02+Math.random()*0.08)+')';
    ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill();
    for(let j=0;j<12;j++){ const rx=x+(Math.random()-0.5)*r*3; const ry=y+(Math.random()-0.5)*r*3; const rr=Math.random()*6; ctx.fillRect(rx,ry,rr,rr); }
  }
}

/* Collect input payload */
function collectPayload(){
  const home=document.getElementById('home').value.trim()||'ทีมเหย้า';
  const away=document.getElementById('away').value.trim()||'ทีมเยือน';
  const favorite=document.getElementById('favorite').value||'none';
  const open1={home:toNum(document.getElementById('open1_home').value), draw:toNum(document.getElementById('open1_draw').value), away:toNum(document.getElementById('open1_away').value)};
  const now1={home:toNum(document.getElementById('now1_home').value), draw:toNum(document.getElementById('now1_draw').value), away:toNum(document.getElementById('now1_away').value)};
  const ahNodes=Array.from(document.querySelectorAll('#ahContainer .ah-block'));
  const ah=ahNodes.map(n=>({
    line:n.querySelector('input[name=ah_line]').value,
    open_home:toNum(n.querySelector('input[name=ah_open_home]').value),
    open_away:toNum(n.querySelector('input[name=ah_open_away]').value),
    now_home:toNum(n.querySelector('input[name=ah_now_home]').value),
    now_away:toNum(n.querySelector('input[name=ah_now_away]').value)
  }));
  return {home,away,favorite,open1,now1,ah,options:{}};
}

/* Analyze call */
async function analyze(){
  const payload = collectPayload();
  document.getElementById('resultWrap').style.display='block';
  document.getElementById('mainSummary').innerHTML='<div class="small">กำลังวิเคราะห์…</div>';
  try {
    const res = await fetch('?action=merged_analyze', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const j = await res.json();
    renderResult(j);
    spawnInk();
  } catch (e) {
    document.getElementById('mainSummary').innerHTML='<div class="small">Fetch error: '+e.message+'</div>';
  }
}
document.getElementById('analyzeBtn').addEventListener('click', analyze);

/* Render results */
function renderResult(r){
  document.getElementById('confValue').innerText = r.metrics && r.metrics.confidence ? (r.metrics.confidence+'%') : '--%';
  document.getElementById('flowPowerValue').innerText = r.metrics && r.metrics.flowPower ? r.metrics.flowPower : '--';
  document.getElementById('smartValue').innerText = r.metrics && r.metrics.smartMoneyScore ? Math.round(r.metrics.smartMoneyScore*100) : '--';
  const mainSummary=document.getElementById('mainSummary');
  let html=`<div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-weight:900;font-size:1.15rem;color:var(--gold)">${r.final_label}</div><div style="margin-top:8px"><strong>คำแนะนำ:</strong> ${r.recommendation}</div><div style="margin-top:6px"><strong>คาดการณ์:</strong> <strong>${r.predicted_winner}</strong></div></div></div>`;
  mainSummary.innerHTML=html;
  const dt=document.getElementById('detailTables');
  let html2='<strong>รายละเอียด 1X2</strong>';
  html2+='<table class="table"><thead><tr><th>ฝั่ง</th><th>Open</th><th>Now</th><th>NetFlow</th><th>Mom</th></tr></thead><tbody>';
  ['home','draw','away'].forEach(side=>{
    const o=r.input&&r.input.open1?r.input.open1[side]:'-';
    const n=r.input&&r.input.now1?r.input.now1[side]:'-';
    const mom = (r.metrics && r.metrics['total1x2_mom']) ? nf(r.metrics['total1x2_mom'],4) : '-';
    html2+=`<tr><td>${side}</td><td>${o}</td><td>${n}</td><td>-</td><td>${mom}</td></tr>`;
  });
  html2+='</tbody></table>';
  html2+='<div style="height:8px"></div><strong>AH Lines</strong>';
  html2+='<table class="table"><thead><tr><th>Line</th><th>Open H</th><th>Now H</th><th>Net H</th><th>Mom H</th><th>Dir H</th><th>Dir A</th></tr></thead><tbody>';
  (r.ah_details||[]).forEach(ad=>{
    html2+=`<tr><td>${ad.line||'-'}</td><td>${ad.open_home||'-'}</td><td>${ad.now_home||'-'}</td><td>${ad.net_home===undefined?'-':ad.net_home}</td><td>${ad.mom_home===undefined?'-':ad.mom_home}</td><td>${ad.dir_home||'-'}</td><td>${ad.dir_away||'-'}</td></tr>`;
  });
  html2+='</tbody></table>';
  dt.innerHTML=html2;

  // Tome (scroll) content
  const tome = document.getElementById('tome');
  let tHtml = `<div class="card" style="padding:12px;background:linear-gradient(180deg,#fff6e1,#f7e2b8);color:#2b1708"><h3>คัมภีร์ลับ — อ่านราคาแม่</h3>`;
  if (r.ah_details && r.ah_details.length>0) {
    const mother = r.ah_details[0];
    tHtml += `<div><strong>ราคาแม่:</strong> ${mother.line} — เปิด H:${mother.open_home} / A:${mother.open_away}</div>`;
  } else {
    tHtml += `<div>ยังไม่มี AH เพียงพอสำหรับอ่านราคาแม่</div>`;
  }
  tHtml += `<div style="margin-top:8px"><strong>สรุป:</strong> ${r.final_label} — ${r.recommendation}</div></div>`;
  tome.innerHTML = tHtml;
  tome.classList.add('open');
}

/* Prevent mobile zoom (simple fix) */
document.querySelectorAll('input,select,textarea').forEach(i=>{ i.addEventListener('focus', ()=>{ document.documentElement.style.fontSize='16px'; }); });

</script>
</body>
</html>
