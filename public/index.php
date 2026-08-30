<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Solportalen\Config\Env;
use Solportalen\Database\Connection;
use Solportalen\Device\Simulator\EnergySimulator;
use Solportalen\Repository\StateRepository;
use Solportalen\Repository\InsightRepository;
use Solportalen\Energy\Planning\PlanService;
use Solportalen\Integration\Supplier\ElprisSupplierService;
use Solportalen\Repository\HistoryRepository;

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
    echo json_encode(['status' => $online ? 'ok' : 'stale', 'mode' => $mode, 'data_age_seconds' => $age, 'writes_enabled' => Env::bool('WRITES_ENABLED',false), 'commissioning_writes_enabled'=>Env::bool('COMMISSIONING_WRITES_ENABLED',false), 'automation_mode'=>Env::get('AUTOMATION_MODE','shadow'), 'server_timestamp' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR);
    exit;
}
if ($path === '/api/v1/state') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['server_timestamp' => gmdate(DATE_ATOM), 'data_timestamp' => $state['source_timestamp'] ?? null, 'data_age_seconds' => $age, 'source' => $mode === 'simulator' ? 'simulator' : 'growatt_rs485', 'data_quality' => $state['data_quality'] ?? 'unavailable', 'system_state' => $online ? 'monitoring' : 'stale', 'data' => $state], JSON_THROW_ON_ERROR);
    exit;
}
if ($path === '/api/v1/history') {header('Content-Type: application/json; charset=utf-8');$range=(string)($_GET['range']??'24h');echo json_encode((new HistoryRepository(Connection::get()))->series($range),JSON_THROW_ON_ERROR);exit;}
if ($path === '/api/v1/insights') {
    header('Content-Type: application/json; charset=utf-8');
    try { echo json_encode((new InsightRepository(Connection::get()))->dashboard(), JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { http_response_code(503); echo json_encode(['error' => 'Prognosedata er ikke klar endnu'], JSON_THROW_ON_ERROR); }
    exit;
}
if ($path === '/api/v1/suppliers') {
    header('Content-Type: application/json; charset=utf-8');
    try { echo json_encode((new ElprisSupplierService(Connection::get()))->comparison(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); }
    catch(Throwable $error){http_response_code(503);echo json_encode(['error'=>'Leverandørdata er ikke klar endnu'],JSON_THROW_ON_ERROR);}exit;
}
if ($path === '/api/v1/commissioning/holding') {
    header('Content-Type: application/json; charset=utf-8');
    $file=SOLPORTAL_ROOT.'/var/commissioning-growatt-holding.json';
    if(!is_file($file)){http_response_code(404);echo json_encode(['error'=>'Der er endnu ikke gemt et holding-register-snapshot'],JSON_THROW_ON_ERROR);exit;}
    readfile($file);exit;
}
if ($path === '/api/v1/plans/latest' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    try { echo json_encode((new PlanService(Connection::get()))->latest(), JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { http_response_code(503); echo json_encode(['error'=>'Planen er ikke klar endnu'],JSON_THROW_ON_ERROR); } exit;
}
if (preg_match('#^/api/v1/plans/(\d+)/approve$#',$path,$match)===1 && ($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $token=(string)($_SERVER['HTTP_X_SOLPORTAL_TOKEN']??'');$actor=(string)($_SERVER['REMOTE_ADDR']??'local');
        echo json_encode((new PlanService(Connection::get()))->approve((int)$match[1],$token,$actor),JSON_THROW_ON_ERROR);
    } catch (Throwable $error) { http_response_code(422); echo json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR); } exit;
}
if (preg_match('#^/api/v1/plans/(\d+)/apply$#',$path,$match)===1 && ($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    header('Content-Type: application/json; charset=utf-8');
    try{$token=(string)($_SERVER['HTTP_X_SOLPORTAL_TOKEN']??'');$actor=(string)($_SERVER['REMOTE_ADDR']??'local');echo json_encode((new PlanService(Connection::get()))->queueApply((int)$match[1],$token,$actor),JSON_THROW_ON_ERROR);}
    catch(Throwable$error){http_response_code(422);echo json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR);}exit;
}
if (str_starts_with($path, '/api/')) {
    http_response_code(404); header('Content-Type: application/json'); echo '{"error":"Endpoint findes ikke"}'; exit;
}

if ($path === '/weather' || $path === '/prices') {
    try { $insights = (new InsightRepository(Connection::get()))->dashboard(); }
    catch (Throwable) { $insights = ['weather'=>[],'prices'=>[],'electricity_tax'=>[],'location'=>Env::get('LOCATION_NAME','Værløse')]; }
    $forecastType = $path === '/weather' ? 'weather' : 'prices';
    require dirname(__DIR__) . '/resources/views/forecast.php';
    exit;
}
if ($path === '/suppliers') {
    try { $supplierComparison=(new ElprisSupplierService(Connection::get()))->comparison(); }
    catch(Throwable){$supplierComparison=['current'=>[],'offers'=>[],'last_run'=>null,'export_comparison_included'=>false];}
    require dirname(__DIR__).'/resources/views/suppliers.php';exit;
}
if ($path === '/commissioning') {
    $file=SOLPORTAL_ROOT.'/var/commissioning-growatt-holding.json';$commissioning=[];
    if(is_file($file)){try{$commissioning=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);}catch(Throwable){$commissioning=[];}}
    require dirname(__DIR__).'/resources/views/commissioning.php';exit;
}

$wallboard = $path === '/wallboard';
require dirname(__DIR__) . '/resources/views/dashboard.php';
