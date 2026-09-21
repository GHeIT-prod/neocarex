<?php

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/Service/StatusSync.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\GheitPriorAuth\Service\StatusSync;

$scope = (string) ($_GET['scope'] ?? 'patient');
$scopePid = null;

if ($scope === 'patient') {
    if (!AclMain::aclCheckCore('patients', 'demo')) {
        http_response_code(403);
        exit;
    }
    $scopePid = isset($pid) ? (int) $pid : 0;
    if ($scopePid < 1) {
        http_response_code(400);
        exit;
    }
} elseif ($scope === 'manager') {
    // Same placeholder-ACL caveat as status_poll.php — TODO: confirm the
    // real "may see the cross-patient PA Manager list" ACL.
    if (!AclMain::aclCheckCore('admin', 'super')) {
        http_response_code(403);
        exit;
    }
} else {
    http_response_code(400);
    exit;
}

$cursor = isset($_GET['cursor']) ? (int) $_GET['cursor'] : StatusSync::getCounter();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (session_id() !== '') {
    session_write_close();
}

while (ob_get_level() > 0) {
    ob_end_flush();
}

$startedAt = time();
$maxRuntime = 55;
$tickSeconds = 2;
$lastHeartbeat = time();

while (true) {
    if (connection_aborted()) {
        break;
    }
    if (time() - $startedAt > $maxRuntime) {
        break;
    }

    $result = StatusSync::getChangesSince($cursor, $scopePid);
    if (!empty($result['changes'])) {
        $cursor = $result['cursor'];
        echo 'data: ' . json_encode($result) . "\n\n";
        flush();
    }

    if (time() - $lastHeartbeat >= 15) {
        echo ": keep-alive\n\n";
        flush();
        $lastHeartbeat = time();
    }

    sleep($tickSeconds);
}