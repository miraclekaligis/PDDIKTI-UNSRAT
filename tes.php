<?php
// Menampilkan semua pesan error yang disembunyikan server
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h3>Diagnostik Server SIMG</h3>";

// 1. Cek fitur cURL (Untuk menembak API)
echo "<b>1. Ekstensi cURL PHP:</b> ";
if (function_exists('curl_init')) {
    echo "<span style='color:green;'>AKTIF</span><br>";
} else {
    echo "<span style='color:red;'>MATI (Silakan aktifkan php_curl di pengaturan XAMPP/aaPanel Anda)</span><br>";
}

// 2. Cek Database MySQL
echo "<b>2. Koneksi Database MySQL:</b> ";
$conn = new mysqli('127.0.0.1', 'root', '', 'simg_db');
if ($conn->connect_error) {
    echo "<span style='color:red;'>GAGAL (" . $conn->connect_error . ")</span><br>";
} else {
    echo "<span style='color:green;'>TERHUBUNG</span><br>";
}

// 3. Tes Tembak PDDikti Langsung
echo "<b>3. Tes Koneksi ke PDDikti:</b> ";
$ch = curl_init("https://pddikti.fastapicloud.dev/api/search/all/informatika/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http == 200) {
    echo "<span style='color:green;'>BERHASIL (HTTP 200)</span><br>";
} else {
    echo "<span style='color:red;'>GAGAL (HTTP Code: $http)</span><br>";
}
?>