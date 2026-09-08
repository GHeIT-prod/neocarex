<?php

/**
 * Standalone full-screen launch page for the PAX (neocarex-pa) embedded app.
 * Opened via dlgopen(..., 'modal-full', ...) from common.php / templates,
 * mirroring the same pattern used for SMART app launches (library/js/utility.js oeSMART.initLaunch).
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';
require_once("$srcdir/api.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\Header;

if (!CsrfUtils::verifyCsrfToken($_GET["csrf_token_form"] ?? '')) {
    CsrfUtils::csrfNotVerified();
}

$formid = (int)($_GET['formid'] ?? 0);
$type = $_GET['type'] ?? '';
if (!in_array($type, ['pa', 'dtr'], true) || $formid < 1) {
    die(xlt('Invalid PAX launch request.'));
}

// Re-derive everything server-side rather than trusting anything from the query string
// beyond formid/type — same defense-in-depth posture as the original inline modal.
$order = sqlQuery("SELECT patient_id, encounter_id FROM procedure_order WHERE procedure_order_id = ?", [$formid]);
if (empty($order)) {
    die(xlt('Order not found.'));
}
$orderPid = (int)$order['patient_id'];

// Confirm the current session's patient context matches (defense against tampering with formid).
if ((int)$pid !== $orderPid) {
    die(xlt('Access denied.'));
}

$patient = sqlQueryNoLog("SELECT * FROM `patient_data` WHERE `pid` = ?", [$pid]);
$patientFhirId = !empty($patient['uuid']) ? UuidRegistry::uuidToString($patient['uuid']) : '';

$cdrsResult = sqlQuery(
    "SELECT cds_hooks_crd_status.status,
            cds_hooks_crd_status.created_at as status_created_at,
            cds_hooks_crd_status.dtr_launch_url,
            cds_hooks_crd_status.resource_id,
            cds_hooks_crd_status.authorization_number,
            procedure_order_code.procedure_code as code,
            procedure_order_code.procedure_name as name,
            procedure_order_code.diagnoses as icd10
       FROM cds_hooks_crd_status
       INNER JOIN procedure_order_code ON cds_hooks_crd_status.order_id = procedure_order_code.procedure_order_id
      WHERE procedure_order_id = ?",
    [$formid]
);

$paxSecret = getenv('EMBED_SIGNING_SECRET') ?: ($_SERVER['EMBED_SIGNING_SECRET'] ?? '');
$paxToken = '';
if ($paxSecret === '') {
    error_log('[pax] EMBED_SIGNING_SECRET is not set; Pax will refuse this launch with 403.');
} else {
    $paxPayload = rtrim(strtr(base64_encode(json_encode([
        'p' => $patientFhirId,
        'e' => time() + 300,
    ])), '+/', '-_'), '=');
    $paxToken = $paxPayload . '.' . hash_hmac('sha256', $paxPayload, $paxSecret);
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <script src="https://dev-pax.gheit.co/embed.js" defer></script>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            /* overflow: hidden; */
        }
        #paxFrameContainer {
            width: 100%;
            height: 100%;
            overflow-y: auto;
        }
        #dtrFrame {
            width: 100%;
            min-height: 100%;
            display: block;
            border: none;
        }
    </style>
</head>
<body>
    <div id="paxFrameContainer">
        <neocarex-pa
            id="dtrFrame"
            view="case"
            patient="<?php echo attr($patientFhirId); ?>"
            step="<?php echo attr($type); ?>"
            token="<?php echo attr($paxToken); ?>">
        </neocarex-pa>
    </div>
</body>
</html>