<?php
/*
|--------------------------------------------------------------------------
| GPT Prompt Machine
|--------------------------------------------------------------------------
|
| SETUP: Set the OPENAI_API_KEY environment variable on your server,
|        then upload this single file. Open it in a browser and hit Generate.
|
| Get your key at: https://platform.openai.com/api-keys
|
*/

session_start();

// Login credentials — change these

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Brute-force protection: 5 attempts, then 15-minute lockout
$MAX_ATTEMPTS   = 5;
$LOCKOUT_SECONDS = 900;

if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['lockout_until']))  $_SESSION['lockout_until']  = 0;

$lockedOut = $_SESSION['lockout_until'] > time();

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if ($lockedOut) {
        $remaining = ceil(($_SESSION['lockout_until'] - time()) / 60);
        $loginError = "Too many failed attempts. Try again in {$remaining} minute(s).";
    } elseif ($_POST['username'] === $AUTH_USERNAME && $_POST['password'] === $AUTH_PASSWORD) {
        $_SESSION['authenticated'] = true;
        $_SESSION['login_attempts'] = 0;
        $_SESSION['lockout_until']  = 0;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        $_SESSION['login_attempts']++;
        if ($_SESSION['login_attempts'] >= $MAX_ATTEMPTS) {
            $_SESSION['lockout_until'] = time() + $LOCKOUT_SECONDS;
            $loginError = 'Too many failed attempts. Locked out for 15 minutes.';
        } else {
            $left = $MAX_ATTEMPTS - $_SESSION['login_attempts'];
            $loginError = "Wrong username or password. {$left} attempt(s) remaining.";
        }
    }
}

// If not authenticated, show login form and stop
if (empty($_SESSION['authenticated'])) {
    showLoginPage($loginError ?? null, $lockedOut);
    exit;
}

$DEFAULT_MODEL  = 'gpt-5-mini';

/*
|--------------------------------------------------------------------------
| API handler — responds to POST requests from the Generate button
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(120);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');

    // Disable all output buffering so lines stream live
    while (ob_get_level()) ob_end_flush();
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);

    $t0 = microtime(true);
    $ms = function() use ($t0) { return round((microtime(true) - $t0) * 1000) . 'ms'; };

    // Stream a log line to the client immediately
    function slog($ms, $msg, $type = 'info') {
        echo json_encode(['log' => true, 't' => $ms(), 'msg' => $msg, 'type' => $type]) . "\n";
        flush();
    }

    slog($ms, 'POST received from ' . $_SERVER['REMOTE_ADDR']);
    slog($ms, 'PHP max_execution_time set to 120s');

    if (empty($OPENAI_API_KEY)) {
        slog($ms, 'ERROR: No API key configured', 'err');
        echo json_encode(['error' => 'OPENAI_API_KEY environment variable is not set on this server.']) . "\n";
        exit;
    }
    slog($ms, 'API key loaded (ends ...' . substr($OPENAI_API_KEY, -4) . ')');

    $rawBody = file_get_contents('php://input');
    slog($ms, 'Request body read — ' . strlen($rawBody) . ' bytes');

    $input = json_decode($rawBody, true);
    if (!$input || empty($input['prompt'])) {
        slog($ms, 'ERROR: Missing or invalid prompt in request body', 'err');
        echo json_encode(['error' => 'Missing prompt']) . "\n";
        exit;
    }

    $promptLen = strlen($input['prompt']);
    slog($ms, 'Prompt parsed — ' . $promptLen . ' chars');

    $payload = [
        'model'    => $DEFAULT_MODEL,
        'messages' => [
            ['role' => 'user', 'content' => $input['prompt']],
        ],
    ];

    $payloadJson = json_encode($payload);
    slog($ms, 'Payload built — model: ' . $DEFAULT_MODEL . ' — ' . strlen($payloadJson) . ' bytes');
    slog($ms, 'Opening cURL to api.openai.com/v1/chat/completions');

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => $payloadJson,
        CURLOPT_TIMEOUT        => 60,
    ]);

    slog($ms, 'cURL configured — timeout: 60s — executing request...');
    slog($ms, 'Waiting for OpenAI response...');

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo  = curl_getinfo($ch);
    curl_close($ch);

    $elapsed = round((microtime(true) - $t0) * 1000);

    slog($ms, 'cURL complete — HTTP ' . $httpCode . ' — ' . strlen($response ?: '') . ' bytes received');
    slog($ms, 'Timing — connect: ' . round(($curlInfo['connect_time'] ?? 0) * 1000) . 'ms — DNS: ' . round(($curlInfo['namelookup_time'] ?? 0) * 1000) . 'ms — total: ' . round(($curlInfo['total_time'] ?? 0) * 1000) . 'ms');

    if ($curlError) {
        slog($ms, 'cURL ERROR: ' . $curlError, 'err');
        echo json_encode(['error' => 'API request failed: ' . $curlError, 'elapsed_ms' => $elapsed]) . "\n";
        exit;
    }

    $data = json_decode($response, true);
    slog($ms, 'Response JSON decoded');

    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? 'OpenAI API error (HTTP ' . $httpCode . ')';
        slog($ms, 'API ERROR (HTTP ' . $httpCode . '): ' . $msg, 'err');
        echo json_encode(['error' => $msg, 'elapsed_ms' => $elapsed]) . "\n";
        exit;
    }

    $content      = $data['choices'][0]['message']['content'] ?? '';
    $finishReason = $data['choices'][0]['finish_reason'] ?? 'unknown';
    $usage        = $data['usage'] ?? null;
    $modelUsed    = $data['model'] ?? $DEFAULT_MODEL;

    slog($ms, 'Model: ' . $modelUsed . ' — finish_reason: ' . $finishReason . ' — response: ' . strlen($content) . ' chars');
    if ($usage) {
        slog($ms, 'Tokens — prompt: ' . ($usage['prompt_tokens'] ?? '?') . ' — completion: ' . ($usage['completion_tokens'] ?? '?') . ' — total: ' . ($usage['total_tokens'] ?? '?'));
    }

    // Strip markdown code fences if the model wrapped the JSON
    $jsonContent = $content;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
        $jsonContent = trim($m[1]);
        slog($ms, 'Stripped markdown code fences from response');
    }

    $parsed    = json_decode($jsonContent, true);
    $jsonError = ($parsed === null && !empty($content)) ? json_last_error_msg() : null;

    if ($parsed) {
        slog($ms, 'JSON parsed successfully — ' . count($parsed) . ' top-level keys', 'ok');
    } elseif ($jsonError) {
        slog($ms, 'JSON parse FAILED: ' . $jsonError, 'warn');
    }

    slog($ms, 'Done — total server time: ' . $elapsed . 'ms', 'ok');

    echo json_encode([
        'raw'           => $content,
        'parsed'        => $parsed,
        'usage'         => $usage,
        'finish_reason' => $finishReason,
        'elapsed_ms'    => $elapsed,
        'json_error'    => $jsonError,
        'model'         => $modelUsed,
    ]) . "\n";
    exit;
}

/*
|--------------------------------------------------------------------------
| UI — serves the page for GET requests
|--------------------------------------------------------------------------
*/
$needsSetup = empty($OPENAI_API_KEY);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GPT Prompt Machine</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f1117; color: #e1e4e8; height: 100vh; display: flex; flex-direction: column; }

  .header { background: #161b22; border-bottom: 1px solid #30363d; padding: 10px 24px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
  .header h1 { font-size: 18px; font-weight: 600; color: #58a6ff; }

  .setup-banner { background: #6e401080; border-bottom: 1px solid #d29922; padding: 12px 24px; font-size: 14px; color: #d29922; }
  .setup-banner code { background: #30363d; padding: 2px 6px; border-radius: 3px; }

  .main { display: flex; flex: 1; overflow: hidden; }

  .left { width: 50%; border-right: 1px solid #30363d; display: flex; flex-direction: column; }
  .left .panel-header { padding: 10px 16px; background: #161b22; border-bottom: 1px solid #30363d; font-size: 13px; font-weight: 600; color: #8b949e; text-transform: uppercase; letter-spacing: 0.5px; display: flex; justify-content: space-between; align-items: center; }
  .left textarea { flex: 1; background: #0d1117; color: #c9d1d9; border: none; padding: 16px; font-family: 'SF Mono', 'Fira Code', monospace; font-size: 13px; line-height: 1.6; resize: none; outline: none; }

  .chat-bar { display: flex; align-items: center; padding: 8px 12px; background: #0d1117; border-top: 1px solid #30363d; flex-shrink: 0; }
  .chat-bar-inner { display: flex; align-items: center; width: 100%; background: #1c2128; border: 1px solid #30363d; border-radius: 20px; padding: 4px 4px 4px 16px; }
  .chat-bar-label { flex: 1; font-size: 13px; color: #484f58; user-select: none; }
  .chat-bar-label.active { color: #8b949e; }
  .btn-send { width: 32px; height: 32px; border-radius: 50%; background: #238636; color: #fff; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s; flex-shrink: 0; }
  .btn-send:hover { background: #2ea043; }
  .btn-send:disabled { background: #30363d; color: #484f58; cursor: not-allowed; }
  .btn-send svg { width: 16px; height: 16px; fill: currentColor; }
  .btn-stop-pill { width: 32px; height: 32px; border-radius: 50%; background: #da3633; color: #fff; border: none; cursor: pointer; display: none; align-items: center; justify-content: center; transition: background 0.15s; flex-shrink: 0; }
  .btn-stop-pill:hover { background: #f85149; }
  .btn-stop-pill svg { width: 14px; height: 14px; fill: currentColor; }

  .right { width: 50%; display: flex; flex-direction: column; }
  .right .panel-header { padding: 10px 16px; background: #161b22; border-bottom: 1px solid #30363d; font-size: 13px; font-weight: 600; color: #8b949e; text-transform: uppercase; letter-spacing: 0.5px; display: flex; justify-content: space-between; align-items: center; }

  .tabs { display: flex; gap: 4px; }
  .tab { background: none; border: 1px solid transparent; color: #8b949e; padding: 4px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; }
  .tab.active { background: #30363d; color: #e1e4e8; border-color: #30363d; }

  .output-area { flex: 1; overflow-y: scroll; padding: 16px; }

  /* Chat bubbles */
  .chat-row { margin-bottom: 16px; }
  .chat-row.user { display: flex; justify-content: flex-end; }
  .chat-row.assistant { display: flex; justify-content: flex-start; }

  .bubble { max-width: 90%; position: relative; }
  .bubble-user { background: #1f6feb; color: #fff; padding: 8px 14px; border-radius: 16px 16px 4px 16px; font-size: 13px; line-height: 1.4; }
  .bubble-user .bubble-meta { font-size: 10px; color: #ffffffaa; margin-top: 4px; }

  .bubble-assistant { background: #21262d; border: 1px solid #30363d; border-radius: 16px 16px 16px 4px; overflow: hidden; }
  .bubble-assistant .bubble-actions { display: flex; justify-content: flex-end; gap: 4px; padding: 6px 12px; background: #161b22; border-top: 1px solid #30363d; }

  .bubble-loading { background: #21262d; border: 1px solid #30363d; border-radius: 16px 16px 16px 4px; padding: 14px 18px; color: #8b949e; font-size: 13px; }

  .bubble-error { background: #6e1b1b80; border: 1px solid #f85149; border-radius: 16px 16px 16px 4px; padding: 12px 16px; color: #f85149; font-size: 13px; white-space: pre-wrap; max-width: 90%; }

  .email-preview { background: #fff; color: #333; padding: 20px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.6; }
  .email-preview .subject { font-size: 16px; font-weight: 700; color: #1a1a1a; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 2px solid #eee; }
  .email-preview p { margin-bottom: 12px; }
  .email-preview ul, .email-preview li { margin-left: 16px; }
  .email-preview li { margin-bottom: 6px; }

  .json-output { background: #0d1117; border: 1px solid #30363d; border-radius: 6px; padding: 14px; margin: 12px; font-family: 'SF Mono', 'Fira Code', monospace; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; color: #79c0ff; }

  .placeholder { display: flex; align-items: center; justify-content: center; height: 100%; color: #484f58; font-size: 14px; }

  .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #30363d; border-top-color: #58a6ff; border-radius: 50%; animation: spin 0.6s linear infinite; margin-right: 8px; vertical-align: middle; }
  @keyframes spin { to { transform: rotate(360deg); } }

  .btn-copy { background: #30363d; color: #c9d1d9; border: 1px solid #484f58; padding: 4px 12px; border-radius: 4px; font-size: 11px; cursor: pointer; }
  .btn-copy:hover { background: #484f58; }

  .error { background: #6e1b1b80; border: 1px solid #f85149; border-radius: 6px; padding: 12px 16px; color: #f85149; font-size: 13px; white-space: pre-wrap; }

  .warning { background: #6e401040; border: 1px solid #d29922; border-radius: 6px; padding: 8px 12px; color: #d29922; font-size: 12px; margin-bottom: 12px; }

  /* Log terminal */
  .log-bar { background: #0d1117; border-top: 1px solid #30363d; height: 25vh; flex-shrink: 0; display: flex; flex-direction: column; }
  .log-bar-header { display: flex; align-items: center; justify-content: space-between; padding: 6px 16px; background: #161b22; border-bottom: 1px solid #30363d; flex-shrink: 0; }
  .log-bar-title { font-size: 11px; font-weight: 600; color: #8b949e; text-transform: uppercase; letter-spacing: 0.5px; }
  .log-bar-config { display: flex; gap: 16px; font-size: 11px; color: #8b949e; }
  .log-bar-config span { display: flex; align-items: center; gap: 4px; }
  .log-bar-config .val { color: #58a6ff; font-weight: 600; }
  .log-bar-config .val-green { color: #3fb950; font-weight: 600; }
  .log-bar-config .val-yellow { color: #d29922; font-weight: 600; }
  .log-bar-config .val-red { color: #f85149; font-weight: 600; }
  .log-entries { flex: 1; overflow-y: auto; padding: 4px 0; font-family: 'SF Mono', 'Fira Code', monospace; }
  .log-entries::-webkit-scrollbar { width: 6px; }
  .log-entries::-webkit-scrollbar-track { background: #0d1117; }
  .log-entries::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }
  .log-entry { padding: 3px 16px; font-size: 12px; line-height: 1.6; color: #8b949e; }
  .log-entry .log-time { color: #484f58; margin-right: 8px; }
  .log-entry .log-ok { color: #3fb950; }
  .log-entry .log-err { color: #f85149; }
  .log-entry .log-warn { color: #d29922; }
  .log-empty { padding: 16px; color: #30363d; font-family: 'SF Mono', 'Fira Code', monospace; font-size: 12px; }
</style>
</head>
<body>

<?php if ($needsSetup): ?>
<div class="setup-banner">
  Setup required: Set the <code>OPENAI_API_KEY</code> environment variable on your server.
</div>
<?php endif; ?>

<div class="header">
  <h1>GPT Prompt Machine</h1>
  <a href="?logout" style="color:#8b949e;font-size:12px;text-decoration:none;">Logout</a>
</div>

<div class="main">
  <div class="left">
    <div class="panel-header">
      Instructions / Prompt
    </div>
    <textarea id="promptInput" spellcheck="false"><?php echo htmlspecialchars('You are to return valid JSON in the following structure and follow all instructions carefully.

STRUCTURE:
{
  "subject": "<subject line>",
  "body": [
    "<p>Paragraph 1</p>",
    "<p>Paragraph 2 into sentence :<ul><li></li><li></li><li></li></ul></p>",
    "<p>Paragraph 3</p>"
  ]
}

SUBJECT LINE:
Create a subject line that is similar to this and never reuse this: "Same-Day Business Funding—Up to $1M Available"

PARAGRAPH 1:
- Max 2 sentences. Do not greet the user.
- Create the intro paragraph similar to this, and never reuse this: "In today\'s uncertain economy, waiting weeks for financing can mean missed opportunities. That\'s why we provide funding that works on your timeline."

PARAGRAPH 2:
- Create the 2nd paragraph similar to this 1 intro line + 3 bullet pointed version.
- Vary the intro line every generation. Never reuse the same phrase. Example: "With our programs, you can:"
- Each bullet point begins with a **capital letter** and does not include any links or <a> tags
<p>With our programs, you can:
<li>Access <b>same-day or 24-hour funding</b>—up to <b>$1,000,000</b></li>
<li>Get approved quickly, with <b>no collateral required</b></li>
<li>Apply in minutes—<b>no cost, no obligation</b></li>
<li>Choose from <b>customized programs</b> built around your business needs</li>
<li>Enjoy <b>zero out-of-pocket costs</b> and fast, accessible capital</li>
</p>

PARAGRAPH 3:
- Create the 3rd paragraph similar to this and never reuse this: "See what you qualify for by filling out our free 2-minute application today."

TONE & STYLE:
- First-person, friendly, conversational — never robotic or overly formal
- Speak like a funding advisor staying in touch

STRICTLY DO NOT USE:
- Never say: APR, rate, loan, freedom, lock in, locking in, deal, attractive, golden opportunity, productive, encourage, queries, still stands, awaits, welcome to 2025
- Never use: "pre-approved" — always say "pre-approval"
- Never mention anything about the past year
- Never include <a> tags or hyperlinks
- Never write anything after the bullet list

RETURN:
Only valid JSON in the defined structure above. No markdown, no explanations, no formatting extras.'); ?></textarea>
  </div>

  <div class="right">
    <div class="panel-header">
      <div style="display:flex;align-items:center;">
        Output
        <span style="font-size:12px;color:#8b949e;margin-left:8px;" id="historyCount"></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <div class="tabs">
          <button class="tab active" data-view="preview">Preview</button>
          <button class="tab" data-view="json">JSON</button>
        </div>
        <button class="btn-copy" id="btnClear">Clear</button>
      </div>
    </div>
    <div class="output-area" id="outputArea">
      <div class="placeholder">Press Generate to create a funding email</div>
    </div>
    <div class="chat-bar">
      <div class="chat-bar-inner">
        <span class="chat-bar-label" id="chatBarLabel">Press enter or click to generate</span>
        <button class="btn-stop-pill" id="btnStop" title="Stop"><svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/></svg></button>
        <button class="btn-send" id="btnGenerate" title="Generate"><svg viewBox="0 0 24 24"><path d="M3 20l18-8L3 4v6.27L14 12 3 13.73z"/></svg></button>
      </div>
    </div>
  </div>
</div>

<!-- Persistent log bar -->
<div class="log-bar">
  <div class="log-bar-header">
    <span class="log-bar-title">Log</span>
    <div class="log-bar-config">
      <span>Model: <span class="val"><?php echo htmlspecialchars($DEFAULT_MODEL); ?></span></span>
      <span id="logStatus">Status: <span class="val">Ready</span></span>
      <span id="logTokens"></span>
      <span id="logTime"></span>
      <span id="logCount"></span>
    </div>
  </div>
  <div class="log-entries" id="logEntries">
    <div class="log-empty">Waiting for first generation...</div>
  </div>
</div>

<script>
const promptInput   = document.getElementById('promptInput');
const btnGenerate   = document.getElementById('btnGenerate');
const btnStop       = document.getElementById('btnStop');
const btnClear      = document.getElementById('btnClear');
const chatBarLabel  = document.getElementById('chatBarLabel');
const outputArea   = document.getElementById('outputArea');
const historyCount = document.getElementById('historyCount');
const logEntries   = document.getElementById('logEntries');
const logStatus    = document.getElementById('logStatus');
const logTokens    = document.getElementById('logTokens');
const logTime      = document.getElementById('logTime');
const logCount     = document.getElementById('logCount');
let currentView = 'preview';
let history = [];
let generationCount = 0;

function addLogEntry(msg, type = 'info') {
  const empty = logEntries.querySelector('.log-empty');
  if (empty) empty.remove();
  const now = new Date();
  const ts = now.toLocaleTimeString();
  const cls = type === 'ok' ? 'log-ok' : type === 'err' ? 'log-err' : type === 'warn' ? 'log-warn' : '';
  const el = document.createElement('div');
  el.className = 'log-entry';
  el.innerHTML = '<span class="log-time">' + ts + '</span><span class="' + cls + '">' + escapeHtml(msg) + '</span>';
  logEntries.appendChild(el);
  logEntries.scrollTop = logEntries.scrollHeight;
}

function addServerLog(entry) {
  const type = entry.type || 'info';
  addLogEntry('[server +' + entry.t + '] ' + entry.msg, type);
}

// Read a streaming response line by line, calling onLine for each
async function readStream(response, onLine) {
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop(); // keep incomplete last line in buffer
    for (const line of lines) {
      if (line.trim()) onLine(line.trim());
    }
  }
  // flush remaining
  if (buffer.trim()) onLine(buffer.trim());
}

// --- Tabs: re-render all bubbles when switching view ---
document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    currentView = tab.dataset.view;
    rerenderHistory();
  });
});

// --- Clear history ---
btnClear.addEventListener('click', () => {
  history = [];
  generationCount = 0;
  historyCount.textContent = '';
  outputArea.innerHTML = '<div class="placeholder">Press Generate to start</div>';
});

let isGenerating = false;
let abortController = null;

btnGenerate.addEventListener('click', generate);
btnStop.addEventListener('click', stopGenerating);
chatBarLabel.addEventListener('click', () => { if (!isGenerating) generate(); });

promptInput.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    e.preventDefault();
    if (!isGenerating) generate();
  }
});

function setRunning(on) {
  isGenerating = on;
  btnGenerate.style.display = on ? 'none' : 'flex';
  btnStop.style.display = on ? 'flex' : 'none';
  chatBarLabel.textContent = on ? 'Generating...' : 'Press enter or click to generate';
  chatBarLabel.classList.toggle('active', on);
}

function stopGenerating() {
  if (abortController) abortController.abort();
  addLogEntry('Stopped by user', 'warn');
  logStatus.innerHTML = 'Status: <span class="val">Ready</span>';
}

async function generate() {
  const prompt = promptInput.value.trim();
  if (!prompt) return;

  isGenerating = true;
  abortController = new AbortController();
  setRunning(true);

  // Clear placeholder
  const placeholder = outputArea.querySelector('.placeholder');
  if (placeholder) placeholder.remove();

  const now = new Date().toLocaleTimeString();

  // User bubble
  const userRow = document.createElement('div');
  userRow.className = 'chat-row user';
  userRow.innerHTML = '<div class="bubble"><div class="bubble-user">Generate #' + (generationCount + 1) + '<div class="bubble-meta">' + now + '</div></div></div>';
  outputArea.appendChild(userRow);

  // Loading bubble
  const loadingRow = document.createElement('div');
  loadingRow.className = 'chat-row assistant';
  loadingRow.innerHTML = '<div class="bubble"><div class="bubble-loading"><span class="spinner"></span> Generating response...</div></div>';
  outputArea.appendChild(loadingRow);
  outputArea.scrollTop = outputArea.scrollHeight;

  logStatus.innerHTML = 'Status: <span class="val-yellow">Generating...</span>';
  addLogEntry('Sending request to OpenAI (' + prompt.length + ' chars)');

  let attempt = 0;
  while (isGenerating) {
    attempt++;
    try {
      const res = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: prompt }),
        signal: abortController.signal,
      });

      // Check if we got a non-streaming response (e.g. 504 HTML page)
      const ct = res.headers.get('content-type') || '';
      if (!ct.includes('text/plain')) {
        const text = await res.text();
        addLogEntry('Attempt ' + attempt + ' — gateway timeout (HTTP ' + res.status + '), retrying...', 'warn');
        logStatus.innerHTML = 'Status: <span class="val-yellow">Timed out, retrying...</span>';
        chatBarLabel.textContent = 'Timed out — retrying (attempt ' + attempt + ')...';
        loadingRow.querySelector('.bubble-loading').innerHTML = '<span class="spinner"></span> Timed out — retrying (attempt ' + attempt + ')...';
        continue;
      }

      // Stream the response line by line
      let data = null;
      await readStream(res, (line) => {
        let obj;
        try { obj = JSON.parse(line); } catch (e) { return; }
        if (obj.log) {
          addServerLog(obj);
        } else {
          data = obj;
        }
      });

      if (!data) {
        addLogEntry('Attempt ' + attempt + ' — empty response from server, retrying...', 'warn');
        logStatus.innerHTML = 'Status: <span class="val-yellow">Empty response, retrying...</span>';
        chatBarLabel.textContent = 'Empty response — retrying (attempt ' + attempt + ')...';
        loadingRow.querySelector('.bubble-loading').innerHTML = '<span class="spinner"></span> Empty response — retrying (attempt ' + attempt + ')...';
        continue;
      }

      if (data.error) {
        loadingRow.remove();
        logStatus.innerHTML = 'Status: <span class="val-red">Error</span>';
        addLogEntry('API error: ' + data.error, 'err');
        if (data.elapsed_ms) logTime.innerHTML = 'Time: <span class="val">' + data.elapsed_ms + 'ms</span>';
        appendErrorBubble(data.error);
        break;
      }

      // Success
      loadingRow.remove();
      generationCount++;
      data._number = generationCount;
      history.push(data);
      historyCount.textContent = generationCount + ' generated';

      const tokIn  = data.usage ? data.usage.prompt_tokens : '?';
      const tokOut = data.usage ? data.usage.completion_tokens : '?';
      logTokens.innerHTML = 'Tokens: <span class="val">' + tokIn + ' in / ' + tokOut + ' out</span>';
      logTime.innerHTML = 'Time: <span class="val">' + (data.elapsed_ms || '?') + 'ms</span>';
      logCount.innerHTML = 'Generations: <span class="val">' + generationCount + '</span>';

      if (data.finish_reason === 'stop' && data.parsed) {
        logStatus.innerHTML = 'Status: <span class="val-green">OK</span>';
        addLogEntry('#' + generationCount + ' — OK — ' + tokIn + ' in / ' + tokOut + ' out — ' + (data.elapsed_ms || '?') + 'ms — model: ' + (data.model || '?'), 'ok');
      } else if (data.finish_reason === 'length') {
        logStatus.innerHTML = 'Status: <span class="val-yellow">Truncated</span>';
        addLogEntry('#' + generationCount + ' — Truncated — ' + tokIn + ' in / ' + tokOut + ' out — ' + (data.elapsed_ms || '?') + 'ms', 'warn');
      } else {
        logStatus.innerHTML = 'Status: <span class="val-yellow">Partial</span>';
        const reason = data.json_error ? 'JSON parse failed: ' + data.json_error : 'finish_reason: ' + data.finish_reason;
        addLogEntry('#' + generationCount + ' — ' + reason + ' — ' + tokIn + ' in / ' + tokOut + ' out — ' + (data.elapsed_ms || '?') + 'ms', 'warn');
      }

      appendResponseBubble(data);
      outputArea.scrollTop = outputArea.scrollHeight;
      if (attempt > 1) addLogEntry('Succeeded on attempt ' + attempt, 'ok');
      break;

    } catch (err) {
      if (err.name === 'AbortError') {
        loadingRow.remove();
        break;
      }
      addLogEntry('Attempt ' + attempt + ' — network error: ' + err.message + ', retrying...', 'warn');
      logStatus.innerHTML = 'Status: <span class="val-yellow">Connection error, retrying...</span>';
      chatBarLabel.textContent = 'Connection error — retrying (attempt ' + attempt + ')...';
      loadingRow.querySelector('.bubble-loading').innerHTML = '<span class="spinner"></span> Connection error — retrying (attempt ' + attempt + ')...';
      continue;
    }
  }

  abortController = null;
  setRunning(false);
}

function appendErrorBubble(msg) {
  const row = document.createElement('div');
  row.className = 'chat-row assistant';
  row.innerHTML = '<div class="bubble-error">' + escapeHtml(msg) + '</div>';
  outputArea.appendChild(row);
  outputArea.scrollTop = outputArea.scrollHeight;
}

function appendResponseBubble(data) {
  const idx = history.indexOf(data);
  const row = document.createElement('div');
  row.className = 'chat-row assistant';

  let bodyHtml = '';
  const warning = data.finish_reason === 'length' ? '<div class="warning" style="margin:12px;">Response was cut off (hit token limit).</div>' : '';

  if (currentView === 'json') {
    const jsonStr = data.parsed ? JSON.stringify(data.parsed, null, 2) : data.raw || '(empty response)';
    bodyHtml = warning + '<div class="json-output">' + escapeHtml(jsonStr) + '</div>';
  } else {
    if (data.parsed && data.parsed.subject && data.parsed.body) {
      const p = data.parsed;
      let inner = '<div class="subject">' + p.subject + '</div>';
      for (const block of p.body) { inner += block; }
      bodyHtml = warning + '<div class="email-preview">' + inner + '</div>';
    } else {
      const raw = data.raw || '(empty response from API)';
      bodyHtml = warning + '<div class="warning" style="margin:12px;">Could not parse structured JSON. Showing raw output:</div><div class="json-output">' + escapeHtml(raw) + '</div>';
    }
  }

  const actions = '<div class="bubble-actions">'
    + '<button class="btn-copy btn-copy-html" data-idx="' + idx + '">Copy HTML</button>'
    + '<button class="btn-copy btn-copy-json" data-idx="' + idx + '">Copy JSON</button>'
    + '</div>';

  row.innerHTML = '<div class="bubble"><div class="bubble-assistant">' + bodyHtml + actions + '</div></div>';
  outputArea.appendChild(row);
}

function rerenderHistory() {
  outputArea.innerHTML = '';
  if (history.length === 0) {
    outputArea.innerHTML = '<div class="placeholder">Press Generate to start</div>';
    return;
  }
  history.forEach((data) => {
    // Re-add user bubble
    const userRow = document.createElement('div');
    userRow.className = 'chat-row user';
    userRow.innerHTML = '<div class="bubble"><div class="bubble-user">Generate #' + data._number + '</div></div>';
    outputArea.appendChild(userRow);
    // Re-add response bubble
    appendResponseBubble(data);
  });
  outputArea.scrollTop = outputArea.scrollHeight;
}

// Delegate copy clicks
outputArea.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-copy-json, .btn-copy-html');
  if (!btn) return;

  const idx = parseInt(btn.dataset.idx);
  const data = history[idx];
  if (!data) return;

  if (btn.classList.contains('btn-copy-json')) {
    const text = data.parsed ? JSON.stringify(data.parsed, null, 2) : data.raw;
    navigator.clipboard.writeText(text).then(() => {
      btn.textContent = 'Copied!';
      setTimeout(() => btn.textContent = 'Copy JSON', 1500);
    });
  } else {
    if (data.parsed && data.parsed.body) {
      const html = data.parsed.body.join('\n');
      navigator.clipboard.writeText(html).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy HTML', 1500);
      });
    }
  }
});

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}
</script>
</body>
</html>
<?php
function showLoginPage($error = null, $locked = false) {
  $dis = $locked ? 'disabled' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — GPT Prompt Machine</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f1117; color: #e1e4e8; height: 100vh; display: flex; align-items: center; justify-content: center; }
  .login-box { background: #161b22; border: 1px solid #30363d; border-radius: 10px; padding: 40px; width: 360px; }
  .login-box h1 { font-size: 20px; color: #58a6ff; margin-bottom: 6px; }
  .login-box p { font-size: 13px; color: #8b949e; margin-bottom: 24px; }
  .login-box label { display: block; font-size: 13px; font-weight: 600; color: #c9d1d9; margin-bottom: 6px; }
  .login-box input[type="text"],
  .login-box input[type="password"] { width: 100%; padding: 10px 12px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-size: 14px; margin-bottom: 16px; outline: none; }
  .login-box input:focus { border-color: #58a6ff; }
  .login-box input:disabled { opacity: 0.4; cursor: not-allowed; }
  .login-box button { width: 100%; padding: 10px; background: #238636; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
  .login-box button:hover { background: #2ea043; }
  .login-box button:disabled { background: #21262d; color: #484f58; cursor: not-allowed; }
  .login-error { background: #6e1b1b80; border: 1px solid #f85149; border-radius: 6px; padding: 10px 14px; color: #f85149; font-size: 13px; margin-bottom: 16px; }
</style>
</head>
<body>
<form class="login-box" method="POST">
  <h1>GPT Prompt Machine</h1>
  <p>Log in to continue.</p>
  <?php if ($error): ?>
    <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <label for="username">Username</label>
  <input type="text" id="username" name="username" autofocus required <?php echo $dis; ?>>
  <label for="password">Password</label>
  <input type="password" id="password" name="password" required <?php echo $dis; ?>>
  <button type="submit" <?php echo $dis; ?>>Log In</button>
</form>
</body>
</html>
<?php } ?>
