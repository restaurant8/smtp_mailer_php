<?php

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

function post_value(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function uploaded_text(string $field): string
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    return (string) file_get_contents($_FILES[$field]['tmp_name']);
}

function query_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function queue_stats(PDO $pdo): array
{
    $stats = [
        'contacts' => (int) $pdo->query('select count(*) from contacts')->fetchColumn(),
        'suppressed' => (int) $pdo->query('select count(*) from suppressions')->fetchColumn(),
        'queued' => 0,
        'processing' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    foreach ($pdo->query('select status, count(*) as total from send_queue group by status')->fetchAll() as $row) {
        $stats[$row['status']] = (int) $row['total'];
    }

    return $stats;
}

function worker_info(PDO $pdo): array
{
    try {
        $row = $pdo->query("select *, timestampdiff(second, heartbeat_at, now()) as heartbeat_age from worker_status where name = 'default'")->fetch();
    } catch (Throwable) {
        return [
            'online' => false,
            'state' => 'not_initialized',
            'heartbeat_at' => null,
            'heartbeat_age' => null,
            'current_queue_id' => null,
            'current_email' => null,
        ];
    }

    if (!$row) {
        return [
            'online' => false,
            'state' => 'not_started',
            'heartbeat_at' => null,
            'heartbeat_age' => null,
            'current_queue_id' => null,
            'current_email' => null,
        ];
    }

    $age = (int) $row['heartbeat_age'];
    return [
        'online' => $age <= 20,
        'state' => $row['state'],
        'heartbeat_at' => $row['heartbeat_at'],
        'heartbeat_age' => $age,
        'current_queue_id' => $row['current_queue_id'],
        'current_email' => $row['current_email'],
        'pid' => $row['pid'],
    ];
}

function json_response(array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function render_page(string $title, string $body, string $script = ''): void
{
    $notice = isset($_GET['notice']) ? '<div class="notice">' . e($_GET['notice']) . '</div>' : '';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' - SMTP Mailer</title>';
    echo '<style>
    :root{--bg:#f5f7f8;--panel:#fff;--text:#101828;--muted:#667085;--line:#d0d5dd;--brand:#176b5b;--blue:#0b4f6c;--red:#b42318;--green:#067647;--amber:#b54708}
    *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Segoe UI,Arial,sans-serif}
    header{position:sticky;top:0;z-index:3;background:#fff;border-bottom:1px solid var(--line)}
    nav{max-width:1220px;margin:0 auto;min-height:60px;display:flex;align-items:center;gap:12px;padding:0 20px;flex-wrap:wrap}
    nav strong{margin-right:auto}nav a{color:#344054;text-decoration:none;padding:8px 10px;border-radius:6px}nav a:hover{background:#eef4f2;color:var(--brand)}
    main{max-width:1220px;margin:22px auto 60px;padding:0 20px}.grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:16px}
    .card,section{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:16px}.card span{display:block;color:var(--muted);font-size:13px}.card b{display:block;font-size:28px;margin-top:8px}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}.notice{margin-bottom:16px;border:1px solid #a6d8ca;background:#e9f7f2;color:#09523f;border-radius:8px;padding:12px 14px}
    form{display:grid;gap:12px}label{display:grid;gap:6px;font-weight:600}input,textarea,select{width:100%;border:1px solid var(--line);border-radius:6px;padding:10px 12px;font:inherit;background:#fff}
    textarea{min-height:150px;resize:vertical;font-family:Consolas,Menlo,monospace}button{border:0;border-radius:6px;padding:10px 14px;background:var(--brand);color:#fff;font-weight:700;cursor:pointer}button.secondary{background:var(--blue)}
    table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:8px;overflow:hidden}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#f2f4f7;font-size:13px;color:#475467}
    .muted{color:var(--muted);font-size:14px}.status{font-weight:700}.ok{color:var(--green)}.bad{color:var(--red)}.warn{color:var(--amber)}.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
    .progress{height:10px;border-radius:999px;background:#eaecf0;overflow:hidden}.progress>i{display:block;height:100%;background:var(--brand);width:0%}
    @media(max-width:900px){.grid,.split{grid-template-columns:1fr}nav strong{width:100%;margin-right:0}}
    </style></head><body>';
    echo '<header><nav><strong>SMTP Mailer</strong><a href="/">控制台</a><a href="/contacts">联系人</a><a href="/templates">模板</a><a href="/campaigns">发送活动</a><a href="/logs">发送日志</a><a href="/settings">配置</a></nav></header>';
    echo '<main>' . $notice . $body . '</main>' . $script . '</body></html>';
}

if ($path === '/api/status') {
    $campaignId = isset($_GET['campaign_id']) && $_GET['campaign_id'] !== '' ? (int) $_GET['campaign_id'] : null;
    $stats = queue_stats($pdo);
    $worker = worker_info($pdo);
    $campaigns = query_rows($pdo, "select c.id, c.name, c.status, c.interval_seconds, coalesce(l.name, '-') as list_name,
        (select count(*) from send_queue q where q.campaign_id = c.id) as total,
        (select count(*) from send_queue q where q.campaign_id = c.id and q.status = 'sent') as sent,
        (select count(*) from send_queue q where q.campaign_id = c.id and q.status = 'failed') as failed,
        (select count(*) from send_queue q where q.campaign_id = c.id and q.status in ('queued','processing')) as pending
        from campaigns c left join contact_lists l on l.id = c.list_id order by c.id desc limit 20");

    $params = [];
    $where = '';
    if ($campaignId) {
        $where = 'where q.campaign_id = ?';
        $params[] = $campaignId;
    }
    $logs = query_rows($pdo, "select q.id, q.campaign_id, c.name as campaign_name, q.email, q.status, q.error, q.created_at, q.locked_at, q.sent_at
        from send_queue q join campaigns c on c.id = q.campaign_id
        {$where}
        order by q.id desc limit 30", $params);

    json_response([
        'ok' => true,
        'time' => date('Y-m-d H:i:s'),
        'stats' => $stats,
        'worker' => $worker,
        'campaigns' => $campaigns,
        'logs' => $logs,
    ]);
}

if ($path === '/contacts/import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawContacts = uploaded_text('contacts_file');
    if ($rawContacts === '') {
        $rawContacts = post_value('contacts');
    }
    $contacts = parse_contacts($rawContacts);
    if (!$contacts) {
        redirect_with_notice('/contacts', '请上传 txt/csv 名单文件，或粘贴至少一个邮箱');
    }

    $listName = post_value('list_name');
    if ($listName === '') {
        $listName = '名单 ' . date('Y-m-d H:i:s');
    }
    $source = post_value('source', 'manual');
    $filename = isset($_FILES['contacts_file']) && ($_FILES['contacts_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        ? basename((string) $_FILES['contacts_file']['name'])
        : '';
    $imported = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    $pdo->prepare('insert into contact_lists (name, source, filename, total_count) values (?, ?, ?, 0)')
        ->execute([$listName, $source, $filename]);
    $listId = (int) $pdo->lastInsertId();

    $upsertContact = $pdo->prepare(
        'insert into contacts (email, name, company, source, vars_json) values (?, ?, ?, ?, ?)
         on duplicate key update
           name = if(values(name) = "", name, values(name)),
           company = if(values(company) = "", company, values(company)),
           source = values(source),
           vars_json = values(vars_json)'
    );
    $findContact = $pdo->prepare('select id from contacts where email = ?');
    $linkContact = $pdo->prepare('insert ignore into contact_list_items (list_id, contact_id) values (?, ?)');

    foreach ($contacts as $contact) {
        if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            continue;
        }
        try {
            $upsertContact->execute([$contact['email'], $contact['name'], $contact['company'], $source, $contact['vars_json']]);
            $findContact->execute([$contact['email']]);
            $contactId = (int) $findContact->fetchColumn();
            $linkContact->execute([$listId, $contactId]);
            if ($linkContact->rowCount() > 0) {
                $imported++;
            } else {
                $skipped++;
            }
        } catch (Throwable) {
            $skipped++;
        }
    }

    $pdo->prepare('update contact_lists set total_count = ? where id = ?')->execute([$imported, $listId]);
    $pdo->commit();
    redirect_with_notice('/contacts', "名单已创建：{$listName}，导入 {$imported} 个邮箱，跳过 {$skipped} 条");
}

if ($path === '/templates' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('insert into templates (name, subject, html_body, text_body) values (?, ?, ?, ?)');
    $stmt->execute([post_value('name'), post_value('subject'), post_value('html_body'), post_value('text_body')]);
    redirect_with_notice('/templates', '模板已保存');
}

if (preg_match('#^/contact-lists/(\d+)/delete$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $listId = (int) $matches[1];
    $inUse = $pdo->prepare("select count(*) from campaigns where list_id = ? and status in ('queued','sending')");
    $inUse->execute([$listId]);
    if ((int) $inUse->fetchColumn() > 0) {
        redirect_with_notice('/contacts', '这个名单正在发送中，不能删除');
    }
    $pdo->prepare('delete from contact_lists where id = ?')->execute([$listId]);
    redirect_with_notice('/contacts', '名单已删除');
}

if ($path === '/contact-lists/bulk-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $listIds = array_map('intval', (array) ($_POST['list_ids'] ?? []));
    $listIds = array_values(array_filter(array_unique($listIds), fn (int $id) => $id > 0));
    if (!$listIds) {
        redirect_with_notice('/contacts', '请先选择要删除的名单');
    }

    $deleted = 0;
    $skipped = 0;
    $inUse = $pdo->prepare("select count(*) from campaigns where list_id = ? and status in ('queued','sending')");
    $delete = $pdo->prepare('delete from contact_lists where id = ?');

    foreach ($listIds as $listId) {
        $inUse->execute([$listId]);
        if ((int) $inUse->fetchColumn() > 0) {
            $skipped++;
            continue;
        }
        $delete->execute([$listId]);
        $deleted += $delete->rowCount();
    }

    redirect_with_notice('/contacts', "已删除 {$deleted} 个名单，跳过 {$skipped} 个正在发送的名单");
}

if ($path === '/campaigns' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $templateId = (int) post_value('template_id');
    $listId = (int) post_value('list_id');
    $interval = max(1, (int) post_value('interval_seconds', '60'));
    if ($listId <= 0) {
        redirect_with_notice('/campaigns', '请选择一个联系人名单');
    }
    $contacts = query_rows(
        $pdo,
        "select c.id, c.email
         from contact_list_items i
         join contacts c on c.id = i.contact_id
         where i.list_id = ? and c.email not in (select email from suppressions)
         order by i.created_at, c.id",
        [$listId]
    );
    if (!$contacts) {
        redirect_with_notice('/contacts', '这个名单没有可发送联系人，请先上传新的 txt/csv 名单');
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("insert into campaigns (template_id, list_id, name, interval_seconds, status) values (?, ?, ?, ?, 'queued')");
    $stmt->execute([$templateId, $listId, post_value('name', '未命名活动'), $interval]);
    $campaignId = (int) $pdo->lastInsertId();
    $queue = $pdo->prepare("insert into send_queue (campaign_id, contact_id, email, status) values (?, ?, ?, 'queued')");
    foreach ($contacts as $contact) {
        $queue->execute([$campaignId, $contact['id'], $contact['email']]);
    }
    $pdo->commit();
    redirect_with_notice('/campaigns', '活动已创建，发送进程会自动处理队列');
}

if (preg_match('#^/campaigns/(\d+)/stop$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("update campaigns set status = 'stopped', finished_at = now() where id = ?");
    $stmt->execute([(int) $matches[1]]);
    redirect_with_notice('/campaigns', '活动已停止');
}

if (preg_match('#^/campaigns/(\d+)/delete$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $campaignId = (int) $matches[1];
    $status = $pdo->prepare('select status from campaigns where id = ?');
    $status->execute([$campaignId]);
    $campaignStatus = $status->fetchColumn();
    if (!in_array($campaignStatus, ['done', 'stopped'], true)) {
        redirect_with_notice('/campaigns', '只能删除已完成或已停止的活动');
    }

    $pdo->beginTransaction();
    $pdo->prepare('delete from send_queue where campaign_id = ?')->execute([$campaignId]);
    $pdo->prepare('delete from campaigns where id = ?')->execute([$campaignId]);
    $pdo->commit();
    redirect_with_notice('/campaigns', '活动和对应发送日志已删除');
}

if ($path === '/unsubscribe') {
    $email = normalize_email((string) ($_GET['email'] ?? ''));
    $token = (string) ($_GET['token'] ?? '');
    if ($email === '' || !hash_equals(unsubscribe_token($email), $token)) {
        render_page('退订失败', '<section><h2>退订链接无效</h2><p>请检查链接是否完整。</p></section>');
        exit;
    }
    $stmt = $pdo->prepare("replace into suppressions (email, reason, created_at) values (?, 'unsubscribe', now())");
    $stmt->execute([$email]);
    render_page('退订成功', '<section><h2>已退订</h2><p>该邮箱已加入停止发送列表。</p></section>');
    exit;
}

if ($path === '/') {
    render_page('控制台', '
        <div class="grid">
          <div class="card"><span>联系人</span><b id="stat-contacts">0</b></div>
          <div class="card"><span>队列中</span><b id="stat-queued">0</b></div>
          <div class="card"><span>处理中</span><b id="stat-processing">0</b></div>
          <div class="card"><span>已发送</span><b id="stat-sent">0</b></div>
          <div class="card"><span>失败</span><b id="stat-failed">0</b></div>
          <div class="card"><span>退订/跳过</span><b id="stat-skipped">0</b></div>
        </div>
        <section>
          <div class="toolbar"><h2 style="margin:0">实时监控</h2><span class="muted" id="last-refresh">等待刷新</span></div>
          <table><tbody>
            <tr><th>Worker 状态</th><td id="worker-state">-</td></tr>
            <tr><th>Worker 心跳</th><td id="worker-heartbeat">-</td></tr>
            <tr><th>当前处理</th><td id="worker-current">-</td></tr>
          </tbody></table>
        </section>
        <section style="margin-top:16px">
          <h2>最近 20 个发送活动</h2>
          <table><thead><tr><th>活动</th><th>名单</th><th>状态</th><th>间隔</th><th>进度</th><th>失败</th></tr></thead><tbody id="campaign-body"></tbody></table>
        </section>
        <section style="margin-top:16px">
          <h2>最近发送日志</h2>
          <table><thead><tr><th>ID</th><th>活动</th><th>邮箱</th><th>状态</th><th>入队时间</th><th>发送时间</th><th>失败原因</th></tr></thead><tbody id="log-body"></tbody></table>
        </section>',
        realtime_script()
    );
    exit;
}

if ($path === '/contacts') {
    $lists = $pdo->query("select l.*,
        (select count(*) from campaigns c where c.list_id = l.id) as campaign_count
        from contact_lists l order by l.id desc limit 100")->fetchAll();
    $listRows = '';
    foreach ($lists as $list) {
        $listRows .= '<tr><td><input type="checkbox" name="list_ids[]" value="' . e($list['id']) . '" class="list-check"></td><td>#' . e($list['id']) . ' ' . e($list['name']) . '</td><td>' . e((string) $list['total_count']) . '</td><td>' . e($list['filename']) . '</td><td>' . e($list['created_at']) . '</td><td>' . e((string) $list['campaign_count']) . '</td><td><form method="post" action="/contact-lists/' . e($list['id']) . '/delete" onsubmit="return confirm(\'确定删除这个名单？不会删除历史发送日志。\')"><button class="secondary" style="background:#b42318">删除名单</button></form></td></tr>';
    }
    render_page('联系人名单', '<div class="split"><section><h2>上传名单</h2><form method="post" action="/contacts/import" enctype="multipart/form-data">
        <label>名单名称<input name="list_name" placeholder="例如：5月第一批客户" required></label>
        <label>来源备注<input name="source" placeholder="例如：官网注册用户"></label>
        <label>上传 txt/csv 文件<input name="contacts_file" type="file" accept=".csv,.txt,text/csv,text/plain"></label>
        <label>也可以粘贴邮箱列表或 CSV<textarea name="contacts" placeholder="email,name,company&#10;alice@example.com,Alice,Example Inc"></textarea></label>
        <button type="submit">上传并创建名单</button></form>
        <p class="muted">建议每次发送前上传一个独立名单。创建发送活动时选择名单，只会发送该名单里的邮箱。</p>
        </section><section><h2>已上传名单</h2><form method="post" action="/contact-lists/bulk-delete" onsubmit="return confirm(\'确定删除选中的名单？不会删除历史发送日志，正在发送的名单会自动跳过。\')"><div class="toolbar"><label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" id="select-all-lists"> 全选</label><button type="submit" class="secondary" style="background:#b42318">批量删除</button></div><table><thead><tr><th></th><th>名单</th><th>邮箱数</th><th>文件</th><th>上传时间</th><th>活动数</th><th>操作</th></tr></thead><tbody>' . ($listRows ?: '<tr><td colspan="7" class="muted">暂无名单，请先上传 txt/csv 文件</td></tr>') . '</tbody></table></form></section></div><script>const all=document.getElementById("select-all-lists");if(all){all.addEventListener("change",()=>document.querySelectorAll(".list-check").forEach(c=>c.checked=all.checked));}</script>');
    exit;
}

if ($path === '/templates') {
    $rows = $pdo->query('select * from templates order by id')->fetchAll();
    $bodyRows = '';
    foreach ($rows as $row) {
        $bodyRows .= '<tr><td>#' . e($row['id']) . '</td><td>' . e($row['name']) . '</td><td>' . e($row['subject']) . '</td></tr>';
    }
    render_page('模板', '<div class="split"><section><h2>新建模板</h2><form method="post" action="/templates">
        <label>模板名称<input name="name" required></label><label>标题<input name="subject" required></label>
        <label>HTML 正文<textarea name="html_body" required></textarea></label><label>纯文本正文<textarea name="text_body" required></textarea></label>
        <button type="submit">保存模板</button></form><p class="muted">可用变量：{{email}}、{{name}}、{{company}}、{{sender_name}}、{{unsubscribe_url}}</p></section>
        <section><h2>已有模板</h2><table><thead><tr><th>ID</th><th>名称</th><th>标题</th></tr></thead><tbody>' . $bodyRows . '</tbody></table></section></div>');
    exit;
}

if ($path === '/campaigns') {
    $templates = $pdo->query('select id, name from templates order by id')->fetchAll();
    $options = '';
    foreach ($templates as $template) {
        $options .= '<option value="' . e($template['id']) . '">' . e($template['name']) . '</option>';
    }
    $lists = $pdo->query('select id, name, total_count from contact_lists order by id desc limit 100')->fetchAll();
    $listOptions = '';
    foreach ($lists as $list) {
        $listOptions .= '<option value="' . e($list['id']) . '">#' . e($list['id']) . ' ' . e($list['name']) . '（' . e((string) $list['total_count']) . '）</option>';
    }
    render_page('发送活动', '<div class="split"><section><h2>创建发送活动</h2><form method="post" action="/campaigns">
        <label>活动名称<input name="name" required></label><label>联系人名单<select name="list_id" required><option value="">请选择上传过的名单</option>' . $listOptions . '</select></label><label>模板<select name="template_id" required>' . $options . '</select></label>
        <label>每封发送间隔秒数<input name="interval_seconds" type="number" min="1" value="60" required></label><button type="submit">创建并开始发送</button></form>
        <p class="muted">发送流程：先到“联系人”上传一个 txt/csv 名单，再在这里选择该名单创建活动。系统只会把这个名单里的未退订邮箱写入 MySQL 队列。</p></section>
        <section><h2>最近 20 个活动</h2><table><thead><tr><th>活动</th><th>名单</th><th>状态</th><th>间隔</th><th>进度</th><th>失败</th><th>操作</th></tr></thead><tbody id="campaign-body"></tbody></table></section></div>',
        realtime_script(true)
    );
    exit;
}

if ($path === '/logs') {
    render_page('发送日志', '<section><div class="toolbar"><h2 style="margin:0">发送日志</h2><span class="muted" id="last-refresh">等待刷新</span></div>
        <table><thead><tr><th>ID</th><th>活动</th><th>邮箱</th><th>状态</th><th>入队时间</th><th>发送时间</th><th>失败原因</th></tr></thead><tbody id="log-body"></tbody></table></section>',
        realtime_script()
    );
    exit;
}

if ($path === '/settings') {
    $cfg = config();
    render_page('配置', '<section><h2>配置</h2><table><tbody>
        <tr><th>APP_URL</th><td>' . e($cfg['app_url']) . '</td></tr>
        <tr><th>DB</th><td>' . e($cfg['db']['database']) . '@' . e($cfg['db']['host']) . '</td></tr>
        <tr><th>SMTP_HOST</th><td>' . e($cfg['smtp']['host']) . '</td></tr>
        <tr><th>SMTP_PORT</th><td>' . e((string) $cfg['smtp']['port']) . '</td></tr>
        <tr><th>SMTP_USERNAME</th><td>' . e($cfg['smtp']['username']) . '</td></tr>
        <tr><th>MAIL_FROM</th><td>' . e($cfg['smtp']['from_email']) . '</td></tr>
        </tbody></table><p class="muted">修改配置请编辑项目根目录 config.php。</p></section>');
    exit;
}

http_response_code(404);
render_page('404', '<section><h2>页面不存在</h2></section>');

function realtime_script(bool $includeStopButton = false): string
{
    $stopButton = $includeStopButton ? 'true' : 'false';
    return str_replace('__INCLUDE_STOP_BUTTON__', $stopButton, <<<'HTML'
<script>
const includeStopButton = __INCLUDE_STOP_BUTTON__;
function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]));
}
function statusClass(status) {
  if (status === 'sent' || status === 'done') return 'ok';
  if (status === 'failed' || status === 'stopped') return 'bad';
  if (status === 'processing' || status === 'sending') return 'warn';
  return '';
}
async function refreshStatus() {
  const res = await fetch('/api/status', {headers: {'Accept': 'application/json'}});
  const data = await res.json();
  const stats = data.stats || {};
  for (const key of ['contacts','queued','processing','sent','failed','skipped']) {
    const el = document.getElementById('stat-' + key);
    if (el) el.textContent = stats[key] ?? 0;
  }
  const refresh = document.getElementById('last-refresh');
  if (refresh) refresh.textContent = '最后刷新：' + data.time;

  const worker = data.worker || {};
  const workerState = document.getElementById('worker-state');
  if (workerState) workerState.innerHTML = worker.online
    ? '<span class="ok">在线</span> / ' + esc(worker.state)
    : '<span class="bad">离线</span> / ' + esc(worker.state);
  const workerHeartbeat = document.getElementById('worker-heartbeat');
  if (workerHeartbeat) workerHeartbeat.textContent = worker.heartbeat_at ? worker.heartbeat_at + '（' + worker.heartbeat_age + ' 秒前）' : '-';
  const workerCurrent = document.getElementById('worker-current');
  if (workerCurrent) workerCurrent.textContent = worker.current_queue_id ? '#' + worker.current_queue_id + ' ' + worker.current_email : '-';

  const campaignBody = document.getElementById('campaign-body');
  if (campaignBody) {
    campaignBody.innerHTML = (data.campaigns || []).map(c => {
      const total = Number(c.total || 0);
      const sent = Number(c.sent || 0);
      const pct = total > 0 ? Math.round(sent * 100 / total) : 0;
      const canStop = c.status === 'queued' || c.status === 'sending';
      const canDelete = c.status === 'done' || c.status === 'stopped';
      const actions = includeStopButton
        ? `<td>${
            canStop
              ? `<form method="post" action="/campaigns/${esc(c.id)}/stop"><button class="secondary">停止</button></form>`
              : ''
          }${
            canDelete
              ? `<form method="post" action="/campaigns/${esc(c.id)}/delete" onsubmit="return confirm('删除后会同时清理这个活动的发送日志，确定删除？')"><button class="secondary" style="margin-top:6px;background:#b42318">删除</button></form>`
              : ''
          }${!canStop && !canDelete ? '<span class="muted">-</span>' : ''}</td>`
        : '';
      return `<tr>
        <td>#${esc(c.id)} ${esc(c.name)}</td>
        <td>${esc(c.list_name)}</td>
        <td class="status ${statusClass(c.status)}">${esc(c.status)}</td>
        <td>${esc(c.interval_seconds)} 秒</td>
        <td><div>${sent}/${total}（${pct}%）</div><div class="progress"><i style="width:${pct}%"></i></div></td>
        <td class="bad">${esc(c.failed)}</td>
        ${actions}
      </tr>`;
    }).join('') || '<tr><td colspan="6" class="muted">暂无发送活动</td></tr>';
  }

  const logBody = document.getElementById('log-body');
  if (logBody) {
    logBody.innerHTML = (data.logs || []).map(row => `<tr>
      <td>#${esc(row.id)}</td>
      <td>#${esc(row.campaign_id)} ${esc(row.campaign_name)}</td>
      <td>${esc(row.email)}</td>
      <td class="status ${statusClass(row.status)}">${esc(row.status)}</td>
      <td>${esc(row.created_at || '')}</td>
      <td>${esc(row.sent_at || row.locked_at || '')}</td>
      <td class="bad">${esc(row.error || '')}</td>
    </tr>`).join('') || '<tr><td colspan="7" class="muted">暂无发送日志</td></tr>';
  }
}
refreshStatus().catch(console.error);
setInterval(() => refreshStatus().catch(console.error), 2000);
</script>
HTML);
}
