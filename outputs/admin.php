<?php

declare(strict_types=1);

$openclawApi = 'api/openclaw.php';
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ArkGrid Admin</title>
  <style>
    :root {
      --ink: #172331;
      --muted: #657487;
      --line: #d8e1ea;
      --panel: #ffffff;
      --soft: #f4f8fb;
      --navy: #101a27;
      --teal: #18cfe3;
      --red: #c84d5b;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      color: var(--ink);
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #eef5f8;
    }

    .layout {
      display: grid;
      grid-template-columns: 240px minmax(0, 1fr);
      min-height: 100vh;
    }

    .nav {
      padding: 22px 16px;
      color: #e9fbff;
      background: var(--navy);
    }

    .brand {
      display: block;
      margin: 0 0 22px;
      font-size: 18px;
      font-weight: 800;
    }

    .nav a {
      display: block;
      margin: 8px 0;
      padding: 10px 12px;
      border-radius: 8px;
      color: inherit;
      text-decoration: none;
    }

    .nav a.active {
      color: #071821;
      background: var(--teal);
      font-weight: 800;
    }

    main {
      padding: 28px;
    }

    h1 {
      margin: 0 0 8px;
      font-size: 28px;
      letter-spacing: 0;
    }

    .subhead {
      margin: 0 0 22px;
      color: var(--muted);
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
    }

    .panel {
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 16px;
      background: var(--panel);
    }

    .panel h2 {
      margin: 0 0 8px;
      font-size: 16px;
    }

    .panel p {
      margin: 0 0 14px;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
    }

    button {
      min-height: 38px;
      border: 0;
      border-radius: 8px;
      padding: 0 14px;
      color: #071821;
      background: var(--teal);
      font-weight: 800;
      cursor: pointer;
    }

    button.secondary {
      color: var(--ink);
      background: var(--soft);
      border: 1px solid var(--line);
    }

    label {
      display: block;
      margin: 0 0 6px;
      font-size: 13px;
      font-weight: 800;
    }

    input {
      width: 100%;
      min-height: 38px;
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 8px 10px;
      margin: 0 0 12px;
      color: var(--ink);
      background: #fff;
    }

    pre {
      overflow: auto;
      min-height: 160px;
      margin: 18px 0 0;
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 14px;
      background: #071821;
      color: #d9faff;
      font-size: 13px;
      line-height: 1.45;
    }

    @media (max-width: 900px) {
      .layout { grid-template-columns: 1fr; }
      .nav { position: static; }
      .grid { grid-template-columns: 1fr; }
      main { padding: 20px; }
    }
  </style>
</head>
<body>
  <div class="layout">
    <aside class="nav">
      <strong class="brand">ArkGrid Admin</strong>
      <a href="../index.html">CRM 控制台</a>
      <a class="active" href="admin.php">OpenClaw 控制台</a>
    </aside>
    <main>
      <h1>OpenClaw 控制台</h1>
      <p class="subhead">机器 token 仅用于低风险调度、每日审批提醒和每日决策报告触发。</p>

      <section class="panel">
        <label for="machineToken">机器 Token</label>
        <input id="machineToken" type="password" autocomplete="off" placeholder="Bearer token">
        <button class="secondary" type="button" data-action="status">刷新状态</button>
      </section>

      <section class="grid" aria-label="OpenClaw actions">
        <article class="panel">
          <h2>调度低风险智能体任务</h2>
          <p>提交已授权的低风险动作，禁止 approve/reject、密钥修改、付款等高风险动作。</p>
          <input id="jobAction" type="text" value="openclaw.health_check" aria-label="任务动作">
          <button type="button" data-action="dispatch">加入队列</button>
        </article>
        <article class="panel">
          <h2>每日审批提醒</h2>
          <p>触发当天审批提醒任务，重复触发会更新当天运行记录。</p>
          <button type="button" data-action="approval-reminders">触发提醒</button>
        </article>
        <article class="panel">
          <h2>每日决策报告</h2>
          <p>触发当天决策报告任务，供调度器异步生成报告。</p>
          <button type="button" data-action="decision-report">触发报告</button>
        </article>
      </section>

      <pre id="output">等待操作...</pre>
    </main>
  </div>

  <script>
    const apiBase = <?php echo json_encode($openclawApi, JSON_UNESCAPED_SLASHES); ?>;
    const tokenInput = document.querySelector('#machineToken');
    const output = document.querySelector('#output');

    async function callOpenClaw(action) {
      const token = tokenInput.value.trim();
      if (!token) {
        output.textContent = 'machine token required';
        return;
      }

      const options = {
        method: action === 'status' ? 'GET' : 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      };

      if (action === 'dispatch') {
        options.body = JSON.stringify({
          action: document.querySelector('#jobAction').value.trim(),
          payload: {}
        });
      } else if (action !== 'status') {
        options.body = JSON.stringify({});
      }

      const response = await fetch(`${apiBase}?action=${encodeURIComponent(action)}`, options);
      const data = await response.json();
      output.textContent = JSON.stringify(data, null, 2);
    }

    document.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-action]');
      if (!button) {
        return;
      }

      callOpenClaw(button.dataset.action).catch((error) => {
        output.textContent = error.message;
      });
    });
  </script>
</body>
</html>
