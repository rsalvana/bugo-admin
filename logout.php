<?php
session_start();
require_once './include/connection.php';
$mysqli = db_connection();
require_once './logs/logs_trig.php';

$trigger    = new Trigger();
$employeeId = $_SESSION['employee_id'] ?? null;

// 🔐 Audit logging
if ($employeeId) { $trigger->isLogout(7, $employeeId); }

// 🧹 Delete remember_token from DB
if (!empty($_COOKIE['remember_token'])) {
    $tokenHash = hash('sha256', $_COOKIE['remember_token']);
    if ($stmt = $mysqli->prepare("DELETE FROM login_tokens WHERE token_hash = ?")) {
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $stmt->close();
    }
}

// ❌ Clear remember_token cookie client-side (secure, httponly)
setcookie('remember_token', '', time() - 3600, '/', '', true, true);

// 🔒 Clear session
session_unset();
session_destroy();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Logging out…</title>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
  <meta http-equiv="Cache-Control" content="no-store" />
  <script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{
      --bg1:#0ea5e9; /* sky-500 */
      --bg2:#22c55e; /* green-500 */
      --ring:rgba(255,255,255,.65);
      --card-bg:rgba(255,255,255,.80);
      --text:#0f172a; /* slate-900 */
      --muted:#475569; /* slate-600 */
      --shadow:0 20px 40px rgba(2,6,23,.18);
    }
    @media (prefers-color-scheme: dark){
      :root{
        --ring:rgba(148,163,184,.35);
        --card-bg:rgba(15,23,42,.75);
        --text:#e2e8f0;
        --muted:#94a3b8;
        --shadow:0 20px 40px rgba(0,0,0,.5);
      }
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
      color:var(--text);
      background:
        radial-gradient(1200px 800px at 10% 10%, color-mix(in oklab, var(--bg1) 35%, transparent) 0%, transparent 60%),
        radial-gradient(1200px 800px at 90% 90%, color-mix(in oklab, var(--bg2) 35%, transparent) 0%, transparent 60%),
        linear-gradient(160deg, #0b1020, #0b1222);
      display:flex; align-items:center; justify-content:center;
      min-height:100svh; padding:clamp(16px, 3vw, 32px);
    }
    .shell{
      width:min(560px, 96vw);
      background:var(--card-bg);
      backdrop-filter:saturate(150%) blur(14px);
      border:1px solid var(--ring);
      border-radius:24px;
      box-shadow:var(--shadow);
      padding:clamp(18px, 4vw, 32px);
    }
    .row{display:flex; gap:16px; align-items:center}
    .col{flex:1}
    .icon-wrap{
      width:72px; height:72px; min-width:72px;
      border-radius:50%;
      display:grid; place-items:center;
      background:conic-gradient(from 90deg, var(--bg2), var(--bg1));
      box-shadow:inset 0 0 0 6px rgba(255,255,255,.55);
    }
    .icon-wrap svg{width:42px; height:42px; color:white; filter:drop-shadow(0 2px 6px rgba(0,0,0,.35))}
    h1{
      margin:0 0 6px; font-weight:700; letter-spacing:.2px;
      font-size:clamp(20px, 2.6vw, 26px);
    }
    p{margin:0; color:var(--muted); font-size:clamp(14px, 1.8vw, 16px)}
    .progress{
      position:relative; height:12px; width:100%; margin:20px 0 10px;
      background:linear-gradient(180deg, rgba(148,163,184,.25), rgba(148,163,184,.15));
      border-radius:999px; overflow:hidden; border:1px solid var(--ring);
    }
    .bar{
      height:100%; width:0%;
      background:linear-gradient(90deg, var(--bg1), var(--bg2));
      transition:width .2s linear;
    }
    .meta{
      display:flex; justify-content:space-between; align-items:center; gap:8px;
      font-size:clamp(12px, 1.6vw, 14px); color:var(--muted);
    }
    .actions{display:flex; gap:10px; margin-top:14px; flex-wrap:wrap}
    .btn{
      appearance:none; border:none; cursor:pointer;
      padding:10px 14px; border-radius:12px; font-weight:600; text-decoration:none;
      background:linear-gradient(90deg, var(--bg1), var(--bg2)); color:white;
      box-shadow:0 6px 16px rgba(2,6,23,.20);
    }
    .btn.secondary{
      background:transparent; color:var(--text);
      border:1px solid var(--ring);
    }
    @media (prefers-reduced-motion: reduce){
      .bar{transition:none}
    }
    /* Tiny screens */
    @media (max-width:420px){
      .row{flex-direction:column; align-items:flex-start}
      .icon-wrap{width:64px; height:64px; min-width:64px}
    }
  </style>
</head>
<body>
  <main class="shell" role="status" aria-live="polite">
    <div class="row">
      <div class="icon-wrap" aria-hidden="true">
        <!-- Check Icon -->
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/>
        </svg>
      </div>
      <div class="col">
        <h1>You're logged out</h1>
        <p>We cleared your session and security tokens. Redirecting to the login page shortly.</p>

        <div class="progress" aria-label="Redirect progress">
          <div class="bar" id="bar"></div>
        </div>

        <div class="meta">
          <span id="status-text">Preparing redirect…</span>
          <span><strong id="count">3</strong>s</span>
        </div>

        <div class="actions">
          <a class="btn" href="index.php" id="nowBtn" rel="nofollow">Back to Login now</a>
          <!--<a class="btn secondary" href="https://office.bugoportal.site" rel="nofollow">Go to Home</a>-->
        </div>
      </div>
    </div>
  </main>

  <noscript>
    <div style="max-width:560px;margin:16px auto;color:#334155;font:14px/1.5 system-ui,Segoe UI,Roboto,Helvetica,Arial">
      JavaScript is disabled. <a href="index.php">Click here</a> to return to the login page.
    </div>
  </noscript>

  <script>
    // Optional toast
    // window.addEventListener('DOMContentLoaded', () => {
    //   if (window.Swal) {
    //     const toast = Swal.mixin({toast:true, position:'top', showConfirmButton:false, timer:1800, timerProgressBar:true});
    //     toast.fire({icon:'success', title:'Logged out successfully'});
    //   }
    // });

    // Countdown + progress + redirect
    (function(){
      const total = 3; // seconds
      const bar   = document.getElementById('bar');
      const cnt   = document.getElementById('count');
      const txt   = document.getElementById('status-text');

      let elapsed = 0;
      cnt.textContent = total;

      const tick = () => {
        elapsed += 0.1;
        const left = Math.max(total - elapsed, 0);
        cnt.textContent = left.toFixed(0);
        bar.style.width = ((elapsed/total)*100) + '%';
        txt.textContent = left > 0 ? 'Redirecting…' : 'Redirecting now…';
        if (elapsed >= total) {
          clearInterval(tmr);
          window.location.replace('index.php');
        }
      };

      const tmr = setInterval(tick, 100);
      tick();
      document.getElementById('nowBtn').addEventListener('click', e => {
        // allow instant navigation; also stop interval
        clearInterval(tmr);
      });
    })();
  </script>
</body>
</html>
