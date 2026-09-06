<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$pdo = Solportalen\Database\Connection::get();
$result = ['now'=>gmdate(DATE_ATOM),'state'=>(new Solportalen\Repository\StateRepository($pdo))->current()];
$result['operational'] = $pdo->query("SELECT state_key,state_value,updated_at FROM operational_state WHERE state_key IN ('manual_schedule','fallback_mode','intelligent_control_enabled','intelligent_control_until','last_auto_plan_date','last_auto_price_until')")->fetchAll();
$result['commands'] = $pdo->query("SELECT id,command_type,payload_json,status,created_at,completed_at,error_message FROM commands ORDER BY id DESC LIMIT 6")->fetchAll();
$result['active_intervals'] = $pdo->query("SELECT i.plan_id,i.starts_at,i.ends_at,i.action,i.power_w,i.soc_after,p.generated_at,a.status FROM plan_intervals i JOIN plans p ON p.id=i.plan_id LEFT JOIN plan_approvals a ON a.plan_id=p.id WHERE i.starts_at<=UTC_TIMESTAMP() AND i.ends_at>UTC_TIMESTAMP() ORDER BY i.plan_id DESC LIMIT 6")->fetchAll();
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
