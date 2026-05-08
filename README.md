# UNSRAT Profile Finder (SPA)

Portal pencarian profil Mahasiswa dan Dosen Universitas Sam Ratulangi (UNSRAT) yang efisien, otomatis, dan modern. Proyek ini merupakan implementasi *Single Page Application* (SPA) yang dirancang untuk memudahkan akses informasi akademik secara cepat tanpa kendala CORS.

## 🚀 Fitur Utama

- **Pencarian Real-Time**: Menampilkan data Mahasiswa dan Dosen UNSRAT dengan respon instan.
- **SIMG Sync Engine**: Menggunakan engine khusus (`simg_sync_engine.php`) untuk sinkronisasi data dan berfungsi sebagai API Proxy guna mengatasi isu CORS.
- **Deteksi Identitas Otomatis**: Sistem secara cerdas membedakan antara Mahasiswa dan Dosen berdasarkan pola input.
- **Ekstraksi NIM Pintar**: Algoritma terintegrasi untuk mengekstrak tahun angkatan, kode fakultas, dan detail lainnya langsung dari NIM.
- **Antarmuka Modern (Tailwind CSS)**: Desain UI yang bersih, responsif, dan elegan menggunakan framework Tailwind CSS.
- **Ekstraksi Data JSON**: Pemrosesan data JSON mentah menjadi informasi yang terstruktur dan mudah dibaca.

## 🛠️ Teknologi yang Digunakan

- **Frontend**: HTML5, Tailwind CSS, JavaScript (Vanilla SPA Logic)
- **Backend**: PHP (API Proxy & Synchronization Engine)
- **Data Source**: UNSRAT Academic Data Integration

## 📂 Struktur Proyek

Sesuai dengan repositori saat ini:

```text
├── index.php              # Halaman utama aplikasi & Antarmuka SPA
├── simg_sync_engine.php   # Engine sinkronisasi data & API Proxy
└── README.md              # Dokumentasi proyek
