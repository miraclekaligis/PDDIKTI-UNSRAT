<?php
// ============================================================
// PDDIKTI (UNSRAT) + PENCARIAN (NO CRUD) + DETAIL DATA
// ============================================================

// -------------------- Helpers & Parser --------------------
function get_field_any($arr, $keys) {
    if (!is_array($arr)) return null;
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') return $arr[$k];
    }
    return null;
}

function normalize_spaces($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function smart_title_case($s) {
    $s = normalize_spaces($s);
    if ($s === '') return $s;

    $letters = preg_replace('/[^A-Za-z]/', '', $s);
    if ($letters !== '' && strtoupper($letters) === $letters) {
        $s = strtolower($s);
        $words = explode(' ', $s);
        foreach ($words as &$w) {
            if (strlen($w) <= 2) $w = strtoupper($w);
            else $w = strtoupper(substr($w,0,1)) . substr($w,1);
        }
        $s = implode(' ', $words);
    }
    return $s;
}

function item_to_row($it) {
    if (!is_array($it)) return null;

    $nama = get_field_any($it, ['nama','nama_mahasiswa','nm_mahasiswa','namaMahasiswa','nm_mhs','name','nm_pd','nama_pd','namaPd','nmPd']);
    $nim  = get_field_any($it, ['nim','NIM','nim_mahasiswa','nomor_induk_mahasiswa','no_mahasiswa']);
    $nidn = get_field_any($it, ['nidn','NIDN']);
    $nuptk = get_field_any($it, ['nuptk','NUPTK']);
    $prodi = get_field_any($it, ['prodi','nama_prodi','nm_prodi','program_studi','namaProgramStudi','nmProdi']);
    $pt = get_field_any($it, ['nama_pt','nm_pt','perguruan_tinggi','namaPerguruanTinggi','pt','universitas']);

    // Trik parsing data PDDIKTI jika informasi digabung di dalam atribut 'text'
    $text = get_field_any($it, ['text']);
    if ($text) {
        // Pola: "NAMA (NIM) - PRODI - KAMPUS"
        if (preg_match('/^(.*?)\s*\((.*?)\)(?:\s*-\s*(.*?)\s*-\s*(.*))?$/', trim($text), $matches)) {
            if (!$nama) $nama = trim($matches[1]);
            // Jika dalam kurung bukan format NIM, biarkan kosong agar dicek sebagai NIDN
            if (!$nim && !preg_match('/^[0-9]{10,16}$/', trim($matches[2]))) $nim = trim($matches[2]); 
            if (!$prodi && isset($matches[3])) $prodi = trim($matches[3]);
            if (!$pt && isset($matches[4])) $pt = trim($matches[4]);
        } else if (!$nama) {
            $nama = $text;
        }
    }

    $nama = smart_title_case($nama);
    $nim = normalize_spaces($nim);
    $prodi = normalize_spaces($prodi);
    $pt = normalize_spaces($pt);

    if ($nama === '' || strlen($nama) < 3) return null;

    return [
        'nama' => $nama,
        'nim' => $nim,
        'nidn' => $nidn,
        'nuptk' => $nuptk,
        'prodi' => $prodi,
        'pt' => $pt,
        'raw' => $it // Simpan data asli untuk ditampilkan di Modal
    ];
}

function parse_pddikti_response($decoded) {
    $candidates = [];
    if (!is_array($decoded)) return [];
    
    // Kadang data adalah array lurus, kadang dibagi per kategori (mahasiswa, dosen, dll)
    $is_list = true;
    $i=0; foreach ($decoded as $k=>$v){ if($k!==$i){ $is_list=false; break; } $i++; }
    
    if ($is_list) {
        $candidates = $decoded;
    } else {
        foreach (['mahasiswa', 'dosen', 'data', 'results'] as $k) {
            if (isset($decoded[$k]) && is_array($decoded[$k])) {
                $candidates = array_merge($candidates, $decoded[$k]);
            }
        }
    }
    
    $rows = [];
    foreach ($candidates as $it) {
        if (!is_array($it)) continue;
        
        // Filter spesifik UNSRAT
        $isUnsrat = false;
        $kampus = get_field_any($it, ['nama_pt','nm_pt','perguruan_tinggi','namaPerguruanTinggi','pt','universitas','namaPT','nmPT', 'sinkatan_pt']);
        $json = json_encode($it, JSON_UNESCAPED_UNICODE);
        
        if (($kampus && (stripos($kampus, 'Sam Ratulangi') !== false || stripos($kampus, 'UNSRAT') !== false)) || 
            (stripos($json, 'Sam Ratulangi') !== false || stripos($json, 'UNSRAT') !== false)) {
            $isUnsrat = true;
        }
        
        if (!$isUnsrat) continue;
        
        $row = item_to_row($it);
        if ($row) $rows[] = $row;
    }
    
    return $rows;
}

// -------------------- API: sync --------------------
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    header('Content-Type: application/json; charset=utf-8');
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($query === '') { echo json_encode(['status'=>'error','message'=>'Query kosong']); exit; }

    $apiUrl = "https://pddikti.fastapicloud.dev/api/search/all/" . urlencode($query) . "/";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response) {
        echo json_encode(['status'=>'success','http_code'=>$httpCode,'count'=>0,'rows'=>[]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        echo json_encode(['status'=>'success','http_code'=>$httpCode,'count'=>0,'rows'=>[]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = parse_pddikti_response($decoded);
    echo json_encode(['status'=>'success','http_code'=>$httpCode,'count'=>count($rows),'rows'=>$rows], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Database UNSRAT</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    body { 
        font-family: 'Inter', sans-serif; 
        background: linear-gradient(135deg, #7f1d1d 0%, #b91c1c 100%); 
        color: #0f172a; 
        min-height: 100vh; 
    }
    
    body::before {
        content: "";
        position: fixed;
        inset: 0;
        background-image: radial-gradient(rgba(255, 255, 255, 0.1) 1px, transparent 1px);
        background-size: 20px 20px;
        z-index: -1;
    }

    .mono { font-family: 'JetBrains Mono', monospace; }
    .card-shadow { box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2); }
    .input-focus:focus-within { border-color: #dc2626; box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1); }
    
    .btn-primary { background-color: #991b1b; color: white; transition: all 0.2s ease; }
    .btn-primary:hover { background-color: #7f1d1d; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(153, 27, 27, 0.3); }
    
    .pagebtn { border: 1px solid #e2e8f0; background: white; color: #475569; transition: all 0.2s; }
    .pagebtn:hover:not(:disabled) { background: #fee2e2; color: #991b1b; }
    .pagebtn.active { border-color: #dc2626; background: #fef2f2; color: #b91c1c; font-weight: 600; }

    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.5); }
    
    .modal-overlay { background-color: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); }
  </style>
</head>
<body class="antialiased selection:bg-red-200 selection:text-red-900">

<!-- Top Navigation -->
<div class="bg-white/95 backdrop-blur-md border-b border-red-900/20 sticky top-0 z-30 shadow-sm">
    <div class="container mx-auto px-6 py-4 max-w-6xl flex justify-between items-center">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-700 rounded-lg flex items-center justify-center text-white shadow-md">
                <i class="fa-solid fa-database"></i>
            </div>
            <div>
                <h1 class="font-bold text-slate-800 text-lg leading-tight">PDDIKTI UNSRAT</h1>
                <p class="text-xs text-slate-500">Pangkalan Data Universitas Sam Ratulangi</p>
            </div>
        </div>
    </div>
</div>

<div class="container mx-auto px-4 py-8 max-w-6xl relative z-10">
  <div class="space-y-6">

    <!-- Search Section -->
    <div class="bg-white rounded-2xl p-6 md:p-8 card-shadow border border-slate-100/50">
      <div class="flex flex-col md:flex-row gap-4">
        <div class="relative flex-1 bg-slate-50 border border-slate-200 rounded-xl input-focus transition-all">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <i class="fa-solid fa-magnifying-glass text-slate-400"></i>
            </div>
            <input id="q" class="w-full pl-11 pr-4 py-3.5 bg-transparent border-none outline-none text-slate-800 placeholder-slate-400 font-medium" placeholder="Cari Nama, NIM, atau NIDN...">
        </div>
        <button onclick="doSearch(1)" class="px-8 py-3.5 rounded-xl font-semibold btn-primary flex items-center justify-center gap-2">
          <i class="fa-solid fa-search text-sm"></i>
          <span>Cari Data</span>
        </button>
      </div>

      <div class="mt-4 pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-2 px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-xs font-medium border border-slate-200">
            <div id="statusDot" class="w-2 h-2 rounded-full bg-slate-400"></div>
            <span id="statusBadge">Menunggu Perintah</span>
        </div>
        <label class="text-sm text-slate-600 flex items-center gap-3 font-medium">
          Tampilkan
          <select id="pageSize" class="px-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 focus:outline-none focus:border-red-500 cursor-pointer">
            <option value="10">10</option>
            <option value="20" selected>20</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </label>
      </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-2xl card-shadow border border-slate-100/50 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs uppercase tracking-wider">
                <th class="px-6 py-4 font-semibold w-2/5">Nama Lengkap</th>
                <th class="px-6 py-4 font-semibold">NIM / NIDN</th>
                <th class="px-6 py-4 font-semibold">Prodi</th>
              </tr>
            </thead>
            <tbody id="tbody" class="divide-y divide-slate-100 text-sm">
                <tr>
                    <td class="px-6 py-16 text-center text-slate-400" colspan="3">
                        <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fa-solid fa-magnifying-glass text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-medium text-slate-500">Ketik kata kunci untuk memulai pencarian</p>
                    </td>
                </tr>
            </tbody>
          </table>
        </div>

        <div class="bg-slate-50 px-6 py-4 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
            <button class="text-sm px-4 py-2 rounded-lg pagebtn font-medium flex items-center gap-2" onclick="prevPage()"><i class="fa-solid fa-arrow-left text-xs"></i> Prev</button>
            <div id="pager" class="flex flex-wrap gap-1.5 justify-center"></div>
            <button class="text-sm px-4 py-2 rounded-lg pagebtn font-medium flex items-center gap-2" onclick="nextPage()">Next <i class="fa-solid fa-arrow-right text-xs"></i></button>
        </div>
        <div class="text-center py-2 bg-slate-100 border-t border-slate-200 text-xs text-slate-500" id="info"></div>
    </div>

  </div>
</div>

<!-- ================= MODAL DETAIL ================= -->
<div id="detailModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-overlay transition-opacity p-4">
    <div class="bg-white max-w-lg w-full rounded-2xl overflow-hidden shadow-2xl flex flex-col max-h-[90vh]">
        <div class="bg-slate-50 px-6 py-4 border-b border-slate-100 flex justify-between items-center shrink-0">
            <h3 id="modalTitle" class="font-bold text-slate-800 text-lg">Profil Mahasiswa</h3>
            <button onclick="closeModal('detailModal')" class="text-slate-400 hover:text-rose-600 transition-colors bg-slate-200 hover:bg-rose-100 w-8 h-8 rounded-full flex items-center justify-center">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto grow space-y-4">
            
            <!-- Item 1: Nama -->
            <div class="border-b border-slate-100 pb-3">
                <p class="text-xs font-semibold text-slate-500 mb-1">Nama</p>
                <p id="detNama" class="text-base font-bold text-slate-800 uppercase"></p>
            </div>

            <!-- Item 2: PT -->
            <div class="border-b border-slate-100 pb-3">
                <p class="text-xs font-semibold text-slate-500 mb-1">Perguruan Tinggi</p>
                <p id="detPt" class="text-sm font-semibold text-slate-700"></p>
            </div>

            <!-- Item 3: Jenis Kelamin -->
            <div class="border-b border-slate-100 pb-3">
                <p class="text-xs font-semibold text-slate-500 mb-1">Jenis Kelamin</p>
                <p id="detJk" class="text-sm font-semibold text-slate-700"></p>
            </div>

            <!-- Item 4: Tanggal Masuk -->
            <div class="border-b border-slate-100 pb-3">
                <p class="text-xs font-semibold text-slate-500 mb-1">Tanggal Masuk</p>
                <p id="detTglMasuk" class="text-sm font-semibold text-slate-700"></p>
            </div>

            <!-- Item 5: NIM / Identitas -->
            <div class="border-b border-slate-100 pb-3">
                <p id="lblNim" class="text-xs font-semibold text-slate-500 mb-1">NIM</p>
                <p id="detNim" class="text-sm font-semibold text-slate-700 mono"></p>
            </div>

            <!-- Item 6: Jenjang & Prodi -->
            <div class="border-b border-slate-100 pb-3">
                <p class="text-xs font-semibold text-slate-500 mb-1">Jenjang - Program Studi</p>
                <p id="detJenjangProdi" class="text-sm font-semibold text-slate-700"></p>
            </div>

            <!-- Item 7: Status Awal / Sekarang -->
            <div class="border-b border-slate-100 pb-3">
                <p id="lblStatusAwal" class="text-xs font-semibold text-slate-500 mb-1">Status Awal Mahasiswa</p>
                <p id="detStatusAwal" class="text-sm font-semibold text-slate-700"></p>
            </div>

            <!-- Item 8: Status Terakhir / Ikatan Kerja -->
            <div class="pb-2">
                <p id="lblStatusAkhir" class="text-xs font-semibold text-slate-500 mb-1">Status Terakhir Mahasiswa</p>
                <p id="detStatusAkhir" class="text-sm font-semibold text-slate-700"></p>
            </div>

            <!-- Raw Data Accordion -->
            <details class="mt-4 group bg-slate-50 rounded-xl border border-slate-200">
                <summary class="text-xs font-bold text-slate-500 p-3 cursor-pointer select-none flex items-center justify-between">
                    <span><i class="fa-solid fa-code mr-2"></i>Lihat Data Mentah API</span>
                    <i class="fa-solid fa-chevron-down group-open:rotate-180 transition-transform"></i>
                </summary>
                <div class="p-3 pt-0">
                    <pre id="detRaw" class="text-[10px] text-emerald-600 mono bg-slate-900 rounded-lg p-3 overflow-x-auto"></pre>
                </div>
            </details>

        </div>
    </div>
</div>

<script>
  // --- APP STATE ---
  const state = {
    apiRows: [],
    page: 1
  };

  // --- UI HELPERS ---
  function setStatus(text, tone='ok'){
    const badge = document.getElementById('statusBadge');
    const dot = document.getElementById('statusDot');
    badge.textContent = text;
    if (tone==='ok') {
        badge.className = 'text-emerald-700 font-semibold';
        dot.className = 'w-2 h-2 rounded-full bg-emerald-500 animate-pulse';
        badge.parentElement.className = 'flex items-center gap-2 px-3 py-1 bg-emerald-50 rounded-full text-xs border border-emerald-200';
    } else if (tone==='warn') {
        badge.className = 'text-amber-700 font-semibold';
        dot.className = 'w-2 h-2 rounded-full bg-amber-500 animate-pulse';
        badge.parentElement.className = 'flex items-center gap-2 px-3 py-1 bg-amber-50 rounded-full text-xs border border-amber-200';
    } else {
        badge.className = 'text-rose-700 font-semibold';
        dot.className = 'w-2 h-2 rounded-full bg-rose-500 animate-pulse';
        badge.parentElement.className = 'flex items-center gap-2 px-3 py-1 bg-rose-50 rounded-full text-xs border border-rose-200';
    }
  }

  function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
  function pageSize(){ return Math.max(1, Number(document.getElementById('pageSize').value || 20)); }
  function pageCount(){ return Math.max(1, Math.ceil(state.apiRows.length / pageSize())); }

  function openModal(modalId) {
      document.getElementById(modalId).classList.remove('hidden');
      document.getElementById(modalId).classList.add('flex');
  }
  function closeModal(modalId) {
      document.getElementById(modalId).classList.add('hidden');
      document.getElementById(modalId).classList.remove('flex');
  }

  // --- DETAIL DATA ---
  function showDetail(index) {
      const item = state.apiRows[index];
      if(!item) return;
      
      const raw = item.raw || {};

      // Cek apakah data dosen
      const isDosen = !!(item.nidn || item.nuptk || raw.nidn || raw.nuptk);
      
      document.getElementById('modalTitle').textContent = isDosen ? 'Profil Dosen' : 'Profil Mahasiswa';

      // Mapping Nama
      document.getElementById('detNama').textContent = item.nama || '-';
      
      // Mapping Perguruan Tinggi
      document.getElementById('detPt').textContent = item.pt || raw.nama_pt || raw.pt || 'Universitas Sam Ratulangi';
      
      // ==========================================
      // Mapping Jenis Kelamin (Super Brute-force)
      // ==========================================
      let jk = '-';
      let rawJk = String(raw.jk || raw.jenis_kelamin || raw.gender || '').trim().toUpperCase();
      
      if (rawJk === 'L' || rawJk === 'LAKI-LAKI' || rawJk === 'M') {
          jk = 'Laki-laki';
      } else if (rawJk === 'P' || rawJk === 'PEREMPUAN' || rawJk === 'F') {
          jk = 'Perempuan';
      } else {
          // Cari paksa dari keseluruhan data JSON
          let rawStr = JSON.stringify(raw).toUpperCase();
          if (rawStr.includes('"LAKI-LAKI"') || rawStr.includes('"JK":"L"') || rawStr.includes('"JENIS_KELAMIN":"L"')) {
              jk = 'Laki-laki';
          } else if (rawStr.includes('"PEREMPUAN"') || rawStr.includes('"JK":"P"') || rawStr.includes('"JENIS_KELAMIN":"P"')) {
              jk = 'Perempuan';
          }
      }
      document.getElementById('detJk').textContent = jk;

      // ==========================================
      // Mapping Dinamis (Mahasiswa vs Dosen)
      // ==========================================
      if (isDosen) {
          // Tanggal Masuk tidak relevan untuk data dosen
          document.getElementById('detTglMasuk').textContent = '-';

          // Ubah label secara dinamis khusus dosen (NIDN/NUPTK)
          document.getElementById('lblNim').textContent = 'NIDN / NUPTK';
          let ids = [];
          if (item.nidn || raw.nidn) ids.push("NIDN: " + (item.nidn || raw.nidn));
          if (item.nuptk || raw.nuptk) ids.push("NUPTK: " + (item.nuptk || raw.nuptk));
          document.getElementById('detNim').textContent = ids.length > 0 ? ids.join(" | ") : "-";

          // Ubah label Status Awal menjadi "Status Sekarang"
          document.getElementById('lblStatusAwal').textContent = 'Status Sekarang';
          document.getElementById('detStatusAwal').textContent = raw.status_aktivitas || raw.status || raw.ikatan_kerja || 'Aktif';

          // Ubah label Status Akhir menjadi "Ikatan Kerja"
          document.getElementById('lblStatusAkhir').textContent = 'Ikatan Kerja / Status Dosen';
          document.getElementById('detStatusAkhir').textContent = raw.ikatan_kerja || raw.status_dosen || '-';

      } else {
          // Tanggal Masuk (Modifikasi dari NIM)
          let tglMasuk = raw.tgl_masuk || raw.tanggal_masuk || raw.mulai_smt || '-';
          let nimStr = String(item.nim || '').trim();
          
          if (nimStr.length >= 2) {
              let duaDigitAwal = nimStr.substring(0, 2);
              if (/^\d{2}$/.test(duaDigitAwal)) {
                  let tahun = parseInt(duaDigitAwal) > 50 ? "19" + duaDigitAwal : "20" + duaDigitAwal;
                  tglMasuk = tahun;
              }
          }
          document.getElementById('detTglMasuk').textContent = tglMasuk;

          // Label kembali ke NIM
          document.getElementById('lblNim').textContent = 'NIM';
          document.getElementById('detNim').textContent = item.nim || '-';

          // Label Status Awal Mahasiswa
          document.getElementById('lblStatusAwal').textContent = 'Status Awal Mahasiswa';
          document.getElementById('detStatusAwal').textContent = raw.status_awal || raw.jns_daftar || 'Peserta didik baru';

          // Label Status Terakhir Mahasiswa
          document.getElementById('lblStatusAkhir').textContent = 'Status Terakhir Mahasiswa';
          document.getElementById('detStatusAkhir').textContent = raw.status_terakhir || raw.status_mhs || raw.ket_keluar || raw.smt_terakhir || raw.status_aktivitas || '-';
      }

      // Mapping Jenjang & Prodi
      const jenjang = raw.namajenjang || raw.jenjang || (isDosen ? 'Dosen Tetap' : 'Sarjana');
      const prodi = item.prodi || raw.nama_prodi || raw.prodi || '-';
      document.getElementById('detJenjangProdi').textContent = `${jenjang} - ${prodi}`;
      
      // Tampilkan raw data JSON untuk debug
      document.getElementById('detRaw').textContent = JSON.stringify(raw, null, 2);
      
      openModal('detailModal');
  }

  // --- RENDERING ---
  function renderTable(){
    const body = document.getElementById('tbody');
    body.innerHTML = '';

    const ps = pageSize();
    const start = (state.page - 1) * ps;
    const slice = state.apiRows.slice(start, start + ps);

    if (slice.length === 0){
      body.innerHTML = `<tr><td class="px-6 py-16 text-center text-slate-400" colspan="3">
            <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3">
                <i class="fa-solid fa-inbox text-2xl text-slate-300"></i>
            </div>
            <p class="font-medium text-slate-500">Tidak ada data untuk ditampilkan</p>
        </td></tr>`;
      return;
    }

    slice.forEach((r, idx) => {
      const globalIndex = start + idx;
      const tr = document.createElement('tr');
      tr.className = 'hover:bg-red-50/50 transition-colors duration-150 group cursor-pointer';
      tr.onclick = () => showDetail(globalIndex);
      
      // Deteksi di tabel
      let isDosen = !!(r.nidn || r.nuptk || (r.raw && (r.raw.nidn || r.raw.nuptk)));
      let badgeNim = '';

      if (isDosen) {
          let dId = r.nidn ? `NIDN: ${r.nidn}` : `NUPTK: ${r.nuptk}`;
          badgeNim = `<span class="mono bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-md text-sm border border-indigo-200 shadow-sm"><i class="fa-solid fa-chalkboard-user mr-1"></i> ${esc(dId)}</span>`;
      } else if (r.nim && r.nim.trim() !== '') {
          badgeNim = `<span class="mono bg-slate-100 text-slate-700 px-2.5 py-1 rounded-md text-sm border border-slate-200 shadow-sm">${esc(r.nim)}</span>`;
      } else {
          badgeNim = `<span class="text-slate-400 text-sm">-</span>`;
      }

      tr.innerHTML = `
        <td class="px-6 py-4">
            <div class="font-bold text-red-700 hover:text-red-900 hover:underline flex items-center transition-colors">
                ${esc(r.nama || '-')}
            </div>
            <div class="text-xs text-slate-500 mt-0.5">${esc(r.pt || '-')}</div>
        </td>
        <td class="px-6 py-4">
            ${badgeNim}
        </td>
        <td class="px-6 py-4 text-slate-600 text-sm font-medium">${esc(r.prodi || '-')}</td>
      `;
      body.appendChild(tr);
    });
  }

  function renderPager(){
    const pager = document.getElementById('pager');
    pager.innerHTML = '';
    const totalPages = pageCount();
    const current = state.page;
    if (totalPages === 0) return;

    const mkBtn = (p, label=null) => {
      const btn = document.createElement('button');
      btn.className = 'mono text-xs min-w-[32px] h-8 px-2 flex items-center justify-center rounded-lg pagebtn';
      btn.textContent = label ?? String(p);
      if (p === current) btn.classList.add('active');
      btn.onclick = () => { state.page = p; updateView(); };
      return btn;
    };

    const mkDots = () => {
      const span = document.createElement('span');
      span.className = 'mono text-xs text-slate-400 flex items-center px-1';
      span.textContent = '...';
      return span;
    };

    if (totalPages <= 7) {
      for (let p=1; p<=totalPages; p++) pager.appendChild(mkBtn(p));
      return;
    }
    
    pager.appendChild(mkBtn(1));
    if (current > 3) pager.appendChild(mkDots());
    const start = Math.max(2, current - 1);
    const end = Math.min(totalPages - 1, current + 2);
    for (let p=start; p<=end; p++) pager.appendChild(mkBtn(p));
    if (current < totalPages - 2) pager.appendChild(mkDots());
    pager.appendChild(mkBtn(totalPages));
  }

  function updateInfo(){
    const info = document.getElementById('info');
    const total = state.apiRows.length;
    const totalPages = pageCount();
    if (total === 0) { info.innerHTML = `Tidak ada hasil pencarian.`; } 
    else { info.innerHTML = `Total: <strong class="text-slate-800">${total}</strong> data &bull; Halaman <strong class="text-slate-800">${state.page}</strong> dari ${totalPages}`; }
  }

  function updateView() {
      const totalPages = pageCount();
      if (state.page > totalPages && totalPages > 0) state.page = totalPages;
      if (state.page < 1) state.page = 1;
      renderTable();
      renderPager();
      updateInfo();
  }

  function prevPage(){ if(state.page > 1) { state.page--; updateView(); } }
  function nextPage(){ if(state.page < pageCount()) { state.page++; updateView(); } }

  // --- API FETCH ---
  async function doSearch(page){
    const q = document.getElementById('q').value.trim();
    if (!q) {
        state.apiRows = [];
        state.page = 1;
        setStatus('Menunggu Perintah', 'ok');
        updateView();
        return;
    }

    setStatus('Mengambil dari Server...', 'warn');
    document.getElementById('tbody').innerHTML = `<tr><td class="px-6 py-12 text-center" colspan="3"><div class="w-8 h-8 border-2 border-red-600 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div><div class="text-sm text-slate-500 font-medium">Mencari di Pangkalan Data...</div></td></tr>`;

    try{
      const res = await fetch(`index.php?action=sync&q=${encodeURIComponent(q)}`);
      const j = await res.json();
      if (j.status !== 'success') throw new Error(j.message || 'Gagal terhubung ke API.');

      state.apiRows = j.rows || [];
      state.page = page || 1;

      setStatus('Pencarian Sukses', 'ok');
      updateView();
    } catch (e) {
      setStatus('Koneksi Gagal', 'bad');
      document.getElementById('tbody').innerHTML = `<tr><td class="px-6 py-12 text-center" colspan="3"><div class="bg-rose-50 text-rose-700 border border-rose-200 p-4 rounded-xl inline-flex items-center gap-3"><i class="fa-solid fa-circle-exclamation text-xl"></i> <span class="font-medium text-sm">Gagal: ${esc(e.message)}</span></div></td></tr>`;
      document.getElementById('pager').innerHTML = '';
      document.getElementById('info').textContent = '';
    }
  }

  // --- INIT ---
  document.getElementById('pageSize').addEventListener('change', () => { state.page=1; updateView(); });
  document.getElementById('q').addEventListener('keypress', (e) => { if (e.key === 'Enter') doSearch(1); });
  
</script>
<footer class="text-center text-xs text-white/80 py-4">
  © 2026 Miracle Kaligis
</footer>
</body>
</html>