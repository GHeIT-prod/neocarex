<?php

/**
 * Standalone full-screen launch page for the PAX (neocarex-pa) embedded app.
 * Opened via dlgopen(..., 'modal-full', ...) from common.php / templates,
 * mirroring the same pattern used for SMART app launches (library/js/utility.js oeSMART.initLaunch).
 *
 * FRD-PAX-NCX-001 Part A. This replaces the legacy shared-secret {p, e}
 * token with a per-host signed assertion carrying u/p/ord/r/e/j, so Pax can
 * create a case keyed (host id, order id) and later sign a webhook back to
 * us for it. Without the `ord` claim below, Pax records nothing and Part B
 * never fires (section 2.2).
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';
require_once("$srcdir/api.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\Header;


if (!defined('PAX_ORIGIN')) {
    define('PAX_ORIGIN', rtrim((string) (getenv('PAX_ORIGIN') ?: ($_SERVER['PAX_ORIGIN'] ?? '')), '/'));
}
if (!defined('PAX_ASSERTION_TTL')) {
    define('PAX_ASSERTION_TTL', 60);
}
if (!defined('PAX_DEV')) {
    define('PAX_DEV', (bool) (getenv('PAX_DEV') ?: ($_SERVER['PAX_DEV'] ?? false)));
}

function pax_host_id(): string
{
    return (string) (getenv('PAX_HOST_ID') ?: ($_SERVER['PAX_HOST_ID'] ?? ''));
}

function pax_host_secret(): string
{
    return (string) (getenv('PAX_HOST_SECRET') ?: ($_SERVER['PAX_HOST_SECRET'] ?? ''));
}


function pax_user_may_view(string $patientFhirId): bool
{
    if ($patientFhirId === '') {
        return false;
    }
    if (!class_exists(AclMain::class)) {
        return false;
    }
    return AclMain::aclCheckCore('patients', 'demo');
}


function pax_permissions_for_current_user(): array
{
    $permissions = ['pax:read'];

    if (class_exists(AclMain::class) && AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
        $permissions[] = 'pax:write';
        $permissions[] = 'pax:submit';
    }

    return $permissions;
}

/** How long a launch assertion lives. Seconds — it only has to survive one page render. */
// const PAX_ASSERTION_TTL = 60;

/** A host order id as Pax accepts it: short, printable, no separators it uses itself. */
const PAX_ORDER_ID = '/^[A-Za-z0-9._:-]{1,64}$/';

function pax_assertion(
    string $userId,
    ?string $patientId = null,
    array $permissions = ['pax:read', 'pax:write', 'pax:submit'],
    ?string $organization = null,
    ?string $orderId = null
): string {
    $hostId = pax_host_id();
    $secret = pax_host_secret();

    if ($hostId === '' || $secret === '') {
        throw new RuntimeException('PAX_HOST_ID / PAX_HOST_SECRET are not set; Pax will refuse this launch.');
    }
    if ($userId === '') {
        throw new InvalidArgumentException('A user id is required to sign a launch.');
    }

    $claims = [
        'u' => $userId,
        'e' => time() + PAX_ASSERTION_TTL,
        'j' => bin2hex(random_bytes(8)),
    ];
    if ($patientId !== null && $patientId !== '') {
        $claims['p'] = $patientId;
    }
    if ($permissions !== []) {
        $claims['r'] = $permissions;
    }
    if ($organization !== null && $organization !== '') {
        $claims['o'] = $organization;
    }
    if ($orderId !== null && $orderId !== '') {
        // Pax refuses an order id without a patient: an order belongs to one,
        // and a worklist launch carrying one would file whatever the clinician
        // opens next against it. Caught here so it fails at the call site
        // rather than as an opaque 401 at launch.
        if (!isset($claims['p'])) {
            throw new InvalidArgumentException('An order id needs the patient it belongs to.');
        }
        if (!preg_match(PAX_ORDER_ID, $orderId)) {
            throw new InvalidArgumentException('Unusable order id: ' . $orderId);
        }
        $claims['ord'] = $orderId;
    }

    $json = json_encode($claims);
    if ($json === false) {
        // A non-UTF-8 id would otherwise sign the string "false".
        throw new RuntimeException('Could not encode the launch claims: ' . json_last_error_msg());
    }

    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $signed = $hostId . '.' . $payload;

    return $signed . '.' . hash_hmac('sha256', $signed, $secret);
}

// --- Request handling (formid/type in, order + patient re-derived) -------

if (!CsrfUtils::verifyCsrfToken($_GET['csrf_token_form'] ?? '')) {
    CsrfUtils::csrfNotVerified();
}

$formid = (int) ($_GET['formid'] ?? 0);
$type = $_GET['type'] ?? '';
if (!in_array($type, ['pa', 'dtr'], true) || $formid < 1) {
    die(xlt('Invalid PAX launch request.'));
}

$order = sqlQuery('SELECT patient_id, encounter_id FROM procedure_order WHERE procedure_order_id = ?', [$formid]);
if (empty($order)) {
    die(xlt('Order not found.'));
}
$orderPid = (int) $order['patient_id'];

if ((int) $pid !== $orderPid) {
    die(xlt('Access denied.'));
}

$patient = sqlQueryNoLog('SELECT * FROM `patient_data` WHERE `pid` = ?', [$pid]);
$patientFhirId = !empty($patient['uuid']) ? UuidRegistry::uuidToString($patient['uuid']) : '';

$paxUserId = (string) ($_SESSION['authUserID'] ?? '');
$paxStep = $type;
$paxAssertion = '';
$paxError = '';

try {
    if (!pax_user_may_view($patientFhirId)) {
        throw new RuntimeException('The signed-in user may not view this patient.');
    }
    // NOTE: pax_assertion()'s signature is (userId, patientId, permissions,
    // organization, orderId) — permissions is 3rd, orderId is 5th. Passing
    // formid positionally as the 3rd argument (as this call used to) lands
    // it in the $permissions slot, which is typed `array`, and throws a
    // TypeError caught below — surfacing as the generic "unavailable"
    // message with no obvious cause. Named/ordered correctly here.
    $paxAssertion = pax_assertion(
        $paxUserId,
        $patientFhirId,
        pax_permissions_for_current_user(),
        null,
        (string) $formid
    );
} catch (Throwable $e) {
    error_log('[pax] ' . $e->getMessage());
    $paxError = 'Prior authorization is unavailable — the launch could not be opened.';
    if (PAX_DEV) {
        $paxError .= ' [dev] ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <script src="<?php echo attr(PAX_ORIGIN); ?>/embed.js" defer></script>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        #paxFrameContainer {
            width: 100%;
            height: 100%;
            overflow-y: auto;
        }
        #paxFrame {
            width: 100%;
            min-height: 100%;
            display: block;
            border: none;
        }
        .pax-launch-error {
            padding: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
<div id="paxFrameContainer">
    <?php if ($paxError !== '') { ?>
        <p class="text-danger pax-launch-error"><?php echo text($paxError); ?></p>
    <?php } else { ?>
        <neocarex-pa
            id="paxFrame"
            view="case"
            patient="<?php echo attr($patientFhirId); ?>"
            step="<?php echo attr($paxStep); ?>"
            assertion="<?php echo attr($paxAssertion); ?>">
        </neocarex-pa>
    <?php } ?>
</div>
</body>
</html>