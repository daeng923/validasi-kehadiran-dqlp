<?php
/**
 * API Proxy DQLP — Menyembunyikan URL GAS dari frontend
 * Lokasi: /akademik/dqlp/api.php
 * 
 * Frontend memanggil api.php, proxy meneruskan ke GAS.
 * URL GAS tidak pernah terekspos di browser.
 */

// ============================================================
// KONFIGURASI — URL GAS disimpan di sini (server-side only)
// ============================================================
define('GAS_MAIN', 'https://script.google.com/macros/s/AKfycbxplpLBevNDxvPAchwesXszGivyV8cf5dy9e8EkhHLAyNB4BsscmS0TxSCA5tXTJKgOLw/exec');
define('GAS_PRESENSI', 'https://script.google.com/macros/s/AKfycbwQUUDKhkEIErNFZP4QSl4bNISYuRuU_eYHuLDxGOUNtBxNPrM2CKLirRgyjquVs6uJ/exec');

// Token proxy — tambahan validasi agar proxy tidak bisa dipanggil sembarang
// Ganti dengan string acak yang sama di frontend
define('PROXY_SECRET', 'dqlp_proxy_2026_umm');

// Allowed origins
$ALLOWED_ORIGINS = [
    'https://simaster.umm.ac.id',
    'http://simaster.umm.ac.id',
    'http://localhost',
    'https://localhost',
    'null', // untuk file:// protocol (development lokal)
];

// ============================================================
// CORS & SECURITY HEADERS
// ============================================================
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $ALLOWED_ORIGINS)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Untuk development lokal / file:// — izinkan juga
    header("Access-Control-Allow-Origin: *");
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// VALIDASI REQUEST
// ============================================================

// Cek proxy secret (opsional — aktifkan jika mau extra security)
// $proxyToken = isset($_GET['_ps']) ? $_GET['_ps'] : (isset($_POST['_ps']) ? $_POST['_ps'] : '');
// if ($proxyToken !== PROXY_SECRET) {
//     http_response_code(403);
//     echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
//     exit;
// }

// Rate limiting sederhana (per IP, 120 request per menit)
$rateLimitFile = sys_get_temp_dir() . '/dqlp_rate_' . md5($_SERVER['REMOTE_ADDR']) . '.json';
$rateLimit = 120;
$rateWindow = 60; // detik

if (file_exists($rateLimitFile)) {
    $rateData = json_decode(file_get_contents($rateLimitFile), true);
    if ($rateData && time() - $rateData['start'] < $rateWindow) {
        if ($rateData['count'] >= $rateLimit) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Terlalu banyak request. Coba lagi nanti.']);
            exit;
        }
        $rateData['count']++;
    } else {
        $rateData = ['start' => time(), 'count' => 1];
    }
} else {
    $rateData = ['start' => time(), 'count' => 1];
}
file_put_contents($rateLimitFile, json_encode($rateData));

// ============================================================
// ROUTING — tentukan target GAS berdasarkan parameter
// ============================================================
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
}

// Tentukan target endpoint
$target = isset($_GET['_target']) ? $_GET['_target'] : 'main';

// Action yang diarahkan ke GAS Presensi Manual
$presensiActions = ['admin_data', 'bulk_simpan'];
if (in_array($action, $presensiActions)) {
    $target = 'presensi';
}

$gasUrl = ($target === 'presensi') ? GAS_PRESENSI : GAS_MAIN;

// ============================================================
// FORWARD REQUEST KE GAS
// ============================================================
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // POST request — forward body
        $postData = file_get_contents('php://input');
        
        // Jika ada query string, tambahkan ke URL
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        // Hapus parameter proxy internal
        $queryString = preg_replace('/&?_target=[^&]*/', '', $queryString);
        $queryString = preg_replace('/&?_ps=[^&]*/', '', $queryString);
        $queryString = ltrim($queryString, '&');
        
        $url = $gasUrl;
        if (!empty($queryString)) {
            $url .= '?' . $queryString;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
    } else {
        // GET request — forward query string
        $params = $_GET;
        // Hapus parameter proxy internal
        unset($params['_target']);
        unset($params['_ps']);
        
        $url = $gasUrl . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'Gagal menghubungi server: ' . $curlError]);
        exit;
    }
    
    http_response_code($httpCode ?: 200);
    echo $response;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal proxy error: ' . $e->getMessage()]);
}
