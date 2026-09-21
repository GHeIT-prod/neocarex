<?php

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/Service/StatusSync.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\GheitPriorAuth\Service\StatusSync;

header('Content-Type: application/json');

$cursor = (int) ($_GET['cursor'] ?? 0);
$scope = (string) ($_GET['scope'] ?? 'patient');

if ($scope === 'patient') {
    if (!AclMain::aclCheckCore('patients', 'demo')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }

    $scopePid = isset($pid) ? (int) $pid : 0;
    if ($scopePid < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'No patient in session context.']);
        exit;
    }

    $result = StatusSync::getChangesSince($cursor, $scopePid);
} elseif ($scope === 'manager') {

    if (!AclMain::aclCheckCore('admin', 'super')) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }

    $result = StatusSync::getChangesSince($cursor, null);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown scope.']);
    exit;
}

echo json_encode($result);