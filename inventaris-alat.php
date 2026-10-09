<?php
// Inventaris Alat Teknik — PHP native + SQLite (1 file, tanpa framework/composer).
// Jalankan: php -S 127.0.0.1:8001  →  buka http://127.0.0.1:8001/inventaris-alat.php
$db = new PDO('sqlite:' . __DIR__ . '/inventaris.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS barang (
  id INTEGER PRIMARY KEY AUTOINCREMENT, nama TEXT NOT NULL, stok INTEGER NOT NULL DEFAULT 0,
  satuan TEXT NOT NULL DEFAULT 'pcs', rak TEXT DEFAULT '-', minimum INTEGER NOT NULL DEFAULT 1)");
$db->exec("CREATE TABLE IF NOT EXISTS mutasi (
  id INTEGER PRIMARY KEY AUTOINCREMENT, barang_id INTEGER NOT NULL, jenis TEXT NOT NULL,
  jumlah INTEGER NOT NULL, ket TEXT DEFAULT '', waktu TEXT NOT NULL DEFAULT (datetime('now','localtime')))");

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'tambah') {
        $nama = trim($_POST['nama'] ?? '');
        if ($nama === '') { $flash = 'Nama barang wajib diisi.'; }
        else {
            $st = $db->prepare("INSERT INTO barang (nama, stok, satuan, rak, minimum) VALUES (?,?,?,?,?)");
            $st->execute([$nama, max(0, (int)($_POST['stok'] ?? 0)), trim($_POST['satuan'] ?? 'pcs') ?: 'pcs', trim($_POST['rak'] ?? '-') ?: '-', max(0, (int)($_POST['minimum'] ?? 1))]);
            $flash = "Barang “$nama” masuk rak.";
        }
    } elseif ($aksi === 'mutasi') {
        $id = (int)($_POST['id'] ?? 0); $jml = abs((int)($_POST['jumlah'] ?? 0));
        $b = $db->prepare("SELECT * FROM barang WHERE id=?"); $b->execute([$id]); $brg = $b->fetch(PDO::FETCH_ASSOC);
        if (!$brg || $jml < 1) { $flash = 'Data mutasi tidak valid.'; }
        elseif (($_POST['jenis'] ?? '') === 'keluar' && $brg['stok'] < $jml) { $flash = "Stok {$brg['nama']} kurang (sisa {$brg['stok']})." ; }
        else {
            $jenis = $_POST['jenis'] === 'keluar' ? 'keluar' : 'masuk';
            $baru = $jenis === 'keluar' ? $brg['stok'] - $jml : $brg['stok'] + $jml;
            $db->prepare("UPDATE barang SET stok=? WHERE id=?")->execute([$baru, $id]);
            $db->prepare("INSERT INTO mutasi (barang_id, jenis, jumlah, ket) VALUES (?,?,?,?)")
               ->execute([$id, $jenis, $jml, trim($_POST['ket'] ?? '')]);
            $flash = "$jml {$brg['satuan']} {$brg['nama']} $jenis. Sisa: $baru.";
        }
    } elseif ($aksi === 'hapus') {
        $db->prepare("DELETE FROM barang WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        $flash = 'Barang dihapus dari rak.';
    }
}
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $st = $db->prepare("SELECT * FROM barang WHERE nama LIKE ? OR rak LIKE ? ORDER BY nama");
    $st->execute(["%$q%", "%$q%"]);
} else {
    $st = $db->query("SELECT * FROM barang ORDER BY nama");
}
$barangs = $st->fetchAll(PDO::FETCH_ASSOC);
$riwayat = $db->query("SELECT m.*, b.nama FROM mutasi m JOIN barang b ON b.id=m.barang_id ORDER BY m.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$menipis = array_values(array_filter($barangs, fn($b) => $b['stok'] <= $b['minimum']));
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Inventaris Alat Teknik</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f0ead8;color:#2b2620;font-family:Georgia,serif}
.wrap{max-width:720px;margin:0 auto;padding:0 16px 40px}
header{border-bottom:3px double #8a7a5c;padding:18px 0 10px}header b{font-family:'Courier New',monospace;font-size:20px;letter-spacing:2px}
header small{display:block;color:#8a7a5c;font-size:13px}
.warn{background:#fff3d6;border:1px solid #9a6a00;border-radius:8px;padding:10px 14px;margin-top:12px;font-size:14px}
.flash{background:#e8f3df;border:1px solid #6a9a4a;border-radius:8px;padding:10px 14px;margin-top:12px}
.card{background:#fbf7ec;border:1px solid #d8cdb2;border-radius:6px;padding:16px;margin-top:14px;box-shadow:2px 3px 0 rgba(90,70,40,.15)}
label{display:block;font-family:'Courier New',monospace;font-size:13px;margin:10px 0 4px;color:#5a4f3c}
input,select{width:100%;padding:10px;border:1px solid #b8a888;border-radius:4px;background:#fffdf4;font-size:16px;font-family:Georgia,serif}
.row{display:flex;gap:10px}.row>div{flex:1}
.btn{font-family:'Courier New',monospace;font-weight:bold;background:#2b2620;color:#fbf7ec;border:none;border-radius:4px;padding:11px 18px;font-size:14px;margin-top:12px;cursor:pointer}
.btn.ghost{background:transparent;color:#2b2620;border:2px solid #2b2620}
table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14.5px}
th,td{text-align:left;padding:8px 6px;border-bottom:1px dashed #c9bc9c;vertical-align:top}
th{font-family:'Courier New',monospace;font-size:12px;color:#8a7a5c}
.stok-rendah{color:#b3261e;font-weight:bold}
.mut{font-size:13px;color:#5a4f3c}.mut b{font-family:'Courier New',monospace}
.search{margin-top:12px}
h2{margin:4px 0 0}
</style>
</head>
<body><div class="wrap">
<header><b>🔧 INVENTARIS ALAT</b><small>gudang teknik — barang masuk & keluar tercatat</small></header>

<?php if ($flash): ?><div class="flash">✔ <?= e($flash) ?></div><?php endif; ?>
<?php if ($menipis): ?><div class="warn">⚠️ Stok menipis: <?= e(implode(', ', array_map(fn($b) => $b['nama'] . " ({$b['stok']})", $menipis))) ?></div><?php endif; ?>

<div class="card">
<h2>➕ Barang baru</h2>
<form method="POST">
<input type="hidden" name="aksi" value="tambah">
<div class="row"><div><label>Nama *</label><input name="nama" maxlength="80" required placeholder="cth: Kabel UTP Cat6"></div>
<div><label>Stok awal</label><input name="stok" type="number" min="0" value="0"></div></div>
<div class="row"><div><label>Satuan</label><input name="satuan" maxlength="10" value="pcs"></div>
<div><label>Rak</label><input name="rak" maxlength="20" placeholder="cth: A-3"></div>
<div><label>Min. stok</label><input name="minimum" type="number" min="0" value="1"></div></div>
<button class="btn" type="submit">Simpan</button>
</form>
</div>

<div class="card">
<h2>📦 Stok</h2>
<form class="search" method="GET"><input name="q" value="<?= e($q) ?>" placeholder="🔍 Cari nama / rak..."></form>
<table><tr><th>Barang</th><th>Stok</th><th>Aksi</th></tr>
<?php foreach ($barangs as $b): ?>
<tr>
<td><b><?= e($b['nama']) ?></b><br><span class="mut">rak <?= e($b['rak']) ?></span></td>
<td class="<?= $b['stok'] <= $b['minimum'] ? 'stok-rendah' : '' ?>"><?= $b['stok'] ?> <?= e($b['satuan']) ?></td>
<td>
<form method="POST" style="display:inline"><input type="hidden" name="aksi" value="mutasi"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="jenis" value="masuk"><input name="jumlah" type="number" min="1" value="1" style="width:64px;padding:6px"><button class="btn" style="padding:7px 10px" type="submit">+</button></form>
<form method="POST" style="display:inline" onsubmit="return this.jumlah.value>0"><input type="hidden" name="aksi" value="mutasi"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="jenis" value="keluar"><input name="jumlah" type="number" min="1" value="1" style="width:64px;padding:6px"><button class="btn ghost" style="padding:5px 10px" type="submit">−</button></form>
</td>
</tr>
<?php endforeach; ?>
<?php if (!$barangs): ?><tr><td colspan="3">Rak masih kosong. Tambahkan barang di atas 👆</td></tr><?php endif; ?>
</table>
</div>

<div class="card">
<h2>🧾 8 mutasi terakhir</h2>
<?php foreach ($riwayat as $m): ?>
<p class="mut"><b><?= e($m['waktu']) ?></b> — <?= $m['jenis'] === 'masuk' ? '➕' : '➖' ?> <?= $m['jumlah'] ?> <?= e($m['nama']) ?><?= $m['ket'] ? ' (' . e($m['ket']) . ')' : '' ?></p>
<?php endforeach; ?>
<?php if (!$riwayat): ?><p class="mut">Belum ada mutasi.</p><?php endif; ?>
</div>
</div></body>
</html>
