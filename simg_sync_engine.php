<?php
// ==========================================
// KONFIGURASI DATABASE
// ==========================================
$host = '127.0.0.1';
$user = 'root';      // Sesuaikan dengan user XAMPP / aaPanel
$pass = '';          // Sesuaikan dengan password database
$db   = 'simg_db';   // Nama database SIMG

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Koneksi Database Gagal: " . $conn->connect_error);
}

// ==========================================
// KONFIGURASI API PDDIKTI
// ==========================================
// Ambil query pencarian dari parameter URL, contoh: ?q=Miracle
$searchQuery = isset($_GET['q']) ? $_GET['q'] : '';

if (empty($searchQuery)) {
    die("Masukkan parameter pencarian. Contoh: ?q=240211");
}

$apiUrl = "https://pddikti.rone.dev/api/search/all/" . urlencode($searchQuery);

// Inisiasi cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Abaikan SSL untuk local testing
curl_setopt($ch, CURLOPT_USERAGENT, 'SIMG-Sync-Engine/1.0'); // Identitas request

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ==========================================
// PROSES DATA & SINKRONISASI KE MYSQL
// ==========================================
if ($httpCode == 200 && $response) {
    $data = json_decode($response, true);
    
    if (isset($data['data']['mahasiswa']) && count($data['data']['mahasiswa']) > 0) {
        $mahasiswaList = $data['data']['mahasiswa'];
        $insertedCount = 0;
        $updatedCount = 0;

        foreach ($mahasiswaList as $mhs) {
            // FILTER: Hanya ambil data dari Universitas Sam Ratulangi
            if (stripos($mhs['pt'], 'Sam Ratulangi') !== false) {
                
                $nama = $conn->real_escape_string($mhs['nama']);
                $nim = $conn->real_escape_string($mhs['nim']);
                $prodi = $conn->real_escape_string($mhs['prodi']);
                
                // Gunakan UPSERT (Insert if not exists, Update if exists)
                $sql = "INSERT INTO mahasiswa (nim, nama, prodi) 
                        VALUES ('$nim', '$nama', '$prodi') 
                        ON DUPLICATE KEY UPDATE 
                        nama = VALUES(nama), prodi = VALUES(prodi)";
                
                if ($conn->query($sql) === TRUE) {
                    if ($conn->affected_rows == 1) {
                        $insertedCount++;
                    } else {
                        $updatedCount++;
                    }
                }
            }
        }
        echo "<h3>Sinkronisasi Selesai!</h3>";
        echo "<p>Data Mahasiswa UNSRAT ditambahkan: $insertedCount</p>";
        echo "<p>Data Mahasiswa UNSRAT diperbarui: $updatedCount</p>";
    } else {
        echo "Data tidak ditemukan di PDDikti untuk pencarian: " . htmlspecialchars($searchQuery);
    }
} else if ($httpCode == 503) {
    echo "Gagal: PDDikti sedang membatasi lalu lintas (HTTP 503). Silakan coba beberapa saat lagi.";
} else {
    echo "Gagal terhubung ke API PDDikti. HTTP Code: $httpCode";
}

$conn->close();
?>