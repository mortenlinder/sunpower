<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Solportalen\Config\Env;
use Solportalen\Database\Connection;
use Solportalen\Device\Simulator\EnergySimulator;
use Solportalen\Repository\StateRepository;

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mode = Env::get('DEVICE_MODE', 'growatt');
try {
    $state = $mode === 'simulator' ? (new EnergySimulator())->state() : (new StateRepository(Connection::get()))->current();
} catch (Throwable $error) {
    $state = [];
}
$timestamp = $state['received_timestamp'] ?? null;
$age = $timestamp === null ? null : max(0, time() - strtotime($timestamp));
$online = $state !== [] && $age !== null && $age < 30;
$state += ['pv_power_w'=>0,'load_power_w'=>0,'battery_power_w'=>0,'battery_soc_pct'=>0,'grid_power_w'=>0,'source_timestamp'=>null,'received_timestamp'=>null,'data_quality'=>'unavailable'];

if ($path === '/healthz' || $path === '/api/v1/health') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($online ? 200 : 503);
    echo json_encode(['status' => $online ? 'ok' : 'stale', 'mode' => $mode, 'data_age_seconds' => $age, 'writes_enabled' => false, 'server_timestamp' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR);
    exit;
}
if ($path === '/api/v1/state') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['server_timestamp' => gmdate(DATE_ATOM), 'data_timestamp' => $state['source_timestamp'] ?? null, 'data_age_seconds' => $age, 'source' => $mode === 'simulator' ? 'simulator' : 'growatt_rs485', 'data_quality' => $state['data_quality'] ?? 'unavailable', 'system_state' => $online ? 'monitoring' : 'stale', 'data' => $state], JSON_THROW_ON_ERROR);
    exit;
}
if (str_starts_with($path, '/api/')) {
    http_response_code(404); header('Content-Type: application/json'); echo '{"error":"Endpoint findes ikke"}'; exit;
}

$wallboard = $path === '/wallboard';
require dirname(__DIR__) . '/resources/views/dashboard.php';
