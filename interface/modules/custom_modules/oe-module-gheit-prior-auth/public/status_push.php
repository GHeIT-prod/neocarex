<?php

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/Service/StatusSync.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\GheitPriorAuth\Service\StatusSync;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

if (!AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to do this.']);
    exit;
}

$orderId = (string) ($_POST['orderId'] ?? '');
$state = (string) ($_POST['state'] ?? '');
$reason = isset($_POST['reason']) && $_POST['reason'] !== '' ? (string) $_POST['reason'] : null;

if ($orderId === '' || !preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $orderId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order id.']);
    exit;
}

// only these two may ever be proposed by a clinician. Every other
// state is derived from the payer's answer or Pax's own workflow.
if (!StatusSync::isProposable($state)) {
    http_response_code(422);
    echo json_encode(['error' => 'That state cannot be proposed from NeoCareX.']);
    exit;
}

// Re-derive order ownership from our own tables rather than trust the
// client further than orderId/state — same posture as pax_launch.php.
$formid = (int) $orderId;
$order = sqlQuery('SELECT patient_id FROM procedure_order WHERE procedure_order_id = ?', [$formid]);
if (empty($order)) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found.']);
    exit;
}
if (isset($pid) && (int) $pid !== (int) $order['patient_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    $result = StatusSync::proposeStateToPax($orderId, $state, $reason);
} catch (\Throwable $e) {
    // NFR-04: no payload/secret in the log line.
    error_log('[pax-push] order=' . $orderId . ' failed: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not reach Pax. Try again shortly.']);
    exit;
}

switch ($result['httpCode']) {
    case 200:
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Sent to Pax. The queue will update once Pax confirms it.']);
        break;

    case 409:
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'This case already reached a final state at Pax.',
            'state' => $result['body']['state'] ?? null,
        ]);
        break;

    case 404:
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Pax has no case on file for this order.']);
        break;

    case 422:
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Pax will not accept that state for this case right now.']);
        break;

    case 401:
        error_log('[pax-push] 401 from Pax for order ' . $orderId . ' — check PAX_HOST_ID/PAX_HOST_SECRET and clock.');
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Prior authorization sync is temporarily unavailable.']);
        break;

    case 503:
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Pax is temporarily unavailable. Try again shortly.']);
        break;

    default:
        error_log('[pax-push] unexpected response for order ' . $orderId . ': httpCode=' . $result['httpCode']);
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Could not reach Pax. Try again shortly.']);
}