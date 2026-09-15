<?php

/**
 * status_webhook.php — receives a signed prior-authorization status event from Pax.
 *
 * FRD-PAX-NCX-001 v1.0, section 5.13. This endpoint carries no OpenEMR session:
 * Pax's server calls it directly, server to server. The HMAC signature checked
 * below is the WHOLE of this endpoint's access control (R-03), not a formality
 * alongside a session check.
 *
 * FR-B-14c: this is the only endpoint in the module exempt from the ACL rule
 * in section 6 (NFR-02). Do not add AclMain::aclCheckCore() here.
 */

// FR-B-14a: $ignoreAuth must be set before globals.php is required. OpenEMR
// reads $ignoreAuth early in interface/globals.php (around line 108), well
// before the authentication check near line 251, so setting it after the
// require does nothing at all.
$ignoreAuth = true;
$sessionAllowWrite = true;

require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../src/Service/StatusSync.php';

use OpenEMR\Modules\GheitPriorAuth\Service\StatusSync;

$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($contentLength > 16 * 1024) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'The body is too large.']);
    exit;
}

$rawBody = file_get_contents('php://input');

if (strlen($rawBody) > 16 * 1024) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'The body is too large.']);
    exit;
}

$timestamp  = $_SERVER['HTTP_X_PAX_TIMESTAMP'] ?? null;
$signature  = $_SERVER['HTTP_X_PAX_SIGNATURE'] ?? null;
$clientId   = $_SERVER['HTTP_X_PAX_CLIENT'] ?? null;
$eventId    = $_SERVER['HTTP_X_PAX_EVENT_ID'] ?? null;

if (!$timestamp || !$signature || !$clientId || !$eventId) {
    http_response_code(401);
    exit;
}

[$algorithm, $providedHex] = array_pad(explode('=', $signature, 2), 2, null);
if ($algorithm !== 'sha256' || !$providedHex || !ctype_xdigit($providedHex)) {
    http_response_code(401);
    exit;
}

$secret = StatusSync::lookupWebhookSecret($clientId);
if ($secret === null) {
    http_response_code(401);
    exit;
}

$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
if (!hash_equals($expected, strtolower($providedHex))) {
    http_response_code(401);
    exit;
}

if (!ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > 300) {
    http_response_code(401);
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    http_response_code(400);
    exit;
}

$requiredFields = ['v', 'orderId', 'patientId', 'state', 'seq', 'occurredAt'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $event)) {
        http_response_code(400);
        exit;
    }
}
if ((int) $event['v'] !== 1) {
    http_response_code(400);
    exit;
}
if (!is_string($event['orderId']) || !is_string($event['patientId']) || !is_string($event['state'])) {
    http_response_code(400);
    exit;
}
if (!StatusSync::isValidState($event['state'])) {
    http_response_code(400);
    exit;
}
if (!is_int($event['seq'])) {
    http_response_code(400);
    exit;
}

if (StatusSync::alreadyProcessed($eventId)) {
    http_response_code(200);
    exit;
}

$expectedPatientId = StatusSync::resolvePatientIdForOrder($event['orderId']);
if ($expectedPatientId === null) {
    http_response_code(401);
    exit;
}
if ($expectedPatientId !== $event['patientId']) {
    http_response_code(401);
    exit;
}

$applied = StatusSync::applyEvent(
    orderId: $event['orderId'],
    state: $event['state'],
    seq: $event['seq'],
    occurredAt: $event['occurredAt'],
    authNumber: $event['authNumber'] ?? null,
    approvedQuantity: $event['approvedQuantity'] ?? null,
    denialReason: $event['denialReason'] ?? null,
    cptCode: $event['cptCode'] ?? null,
    eventId: $eventId
);

error_log(sprintf(
    '[pax-status] order=%s state=%s seq=%d applied=%s',
    $event['orderId'],
    $event['state'],
    $event['seq'],
    $applied ? 'yes' : 'stale'
));

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);