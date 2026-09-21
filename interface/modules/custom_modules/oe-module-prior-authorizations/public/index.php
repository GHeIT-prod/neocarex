<?php

/*
 *  package OpenEMR
 *  link    https://www.open-emr.org
 *  author  Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c) 2022.
 *  All Rights Reserved
 */

require_once dirname(__FILE__, 5) . "/globals.php";
require_once dirname(__DIR__) . '/src/Controller/ListAuthorizations.php';

use Juggernaut\OpenEMR\Modules\PriorAuthModule\Controller\AuthorizationService;
use Juggernaut\OpenEMR\Modules\PriorAuthModule\Controller\ListAuthorizations;
use OpenEMR\Core\Header;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\GheitPriorAuth\Service\StatusSync;

require_once dirname(__DIR__, 5) . '/vendor/autoload.php';

$pid = $_SESSION['pid'] ?? null;
function isValid($date, $format = 'Y-m-d'): bool
{
    $dt = DateTime::createFromFormat($format, $date);
    return $dt && $dt->format($format) === $date;
}

if (!empty($_POST['token'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["token"])) {
        CsrfUtils::csrfNotVerified();
    }

    $postStartDate = DateToYYYYMMDD($_POST['start_date']);
    $startDate = isValid($postStartDate) === true ? $postStartDate : $_POST['start_date'];

    $postEndDate = DateToYYYYMMDD($_POST['end_date']);
    $endDate = isValid($postEndDate) === true ? $postEndDate : $_POST['end_date'];

    $postData = new AuthorizationService();
    $rawId = $_POST['id'] ?? null;
    $rawId = (ctype_digit((string)$rawId)) ? (int)$rawId : null;
    $postData->setId($rawId);
    $postData->setPid($pid);
    $postData->setAuthNum($_POST['authorization']);
    $postData->setInitUnits($_POST['units']);
    $postData->setStartDate($startDate);
    $postData->setEndDate($endDate);
    $postData->setCpt($_POST['cpts']);
    $postData->storeAuthorizationInfo();
}

$listData = new ListAuthorizations();
$listData->setPid($pid);
$authList = $listData->getAllAuthorizations($pid);

/**
 * mock
 */
function getPaAdjudicationForCpt(string $cptCode): array
{
    $rules = [
        '97161' => [
            'adj_icon' => '✗', 'adj_label' => xl('Submission error'),
            'adj_bg' => '#fdecea', 'adj_color' => '#c0392b',
            'sla_icon' => '⚠️', 'sla_label' => xl('SLA Halted'),
            'sla_bg' => '#fdecea', 'sla_color' => '#c0392b',
            'sla_subtext' => xl('Missing data block'), 'sla_progress' => null,
            'action_label' => xl('Fix & Resubmit'), 'action_type' => 'button',
            'action_bg' => '#c0392b', 'action_color' => '#fff',
        ],
        '99214' => [
            'adj_icon' => '⏳', 'adj_label' => xl('In Review / Payer Engine'),
            'adj_bg' => '#eaf2fb', 'adj_color' => '#1a5fb4',
            'sla_icon' => '⏳', 'sla_label' => xl('51h 06m left'),
            'sla_bg' => '#fff6df', 'sla_color' => '#9a7d0a',
            'sla_subtext' => xl('In 72h Expedited Window'), 'sla_progress' => 30,
            'action_label' => xl('Ping Payer'), 'action_type' => 'button',
            'action_bg' => '#fff', 'action_color' => '#333', 'action_border' => '#ccc',
        ],
        'K0823' => [
            'adj_icon' => '✅', 'adj_label' => xl('Certified in Full'),
            'adj_bg' => '#e8f8ee', 'adj_color' => '#1e7e34',
            'sla_icon' => '✓', 'sla_label' => xl('Adjudicated in 24h'),
            'sla_bg' => '#e8f8ee', 'sla_color' => '#1e7e34',
            'sla_subtext' => xl('CMS SLA Satisfied'), 'sla_progress' => 100,
            'action_label' => xl('Dispensing Authorized'), 'action_type' => 'text',
            'action_color' => '#1e7e34',
        ],
        'K0861' => [
            'adj_icon' => '✅', 'adj_label' => xl('Certified in Full'),
            'adj_bg' => '#e8f8ee', 'adj_color' => '#1e7e34',
            'sla_icon' => '✓', 'sla_label' => xl('Adjudicated in 18h'),
            'sla_bg' => '#e8f8ee', 'sla_color' => '#1e7e34',
            'sla_subtext' => null, 'sla_progress' => 100,
            'action_label' => xl('Active'), 'action_type' => 'text',
            'action_color' => '#1e7e34',
        ],
        '15823' => [
            'adj_icon' => '✗', 'adj_label' => xl('Denied: Cosmetic'),
            'adj_bg' => '#fdecea', 'adj_color' => '#c0392b',
            'sla_icon' => '✓', 'sla_label' => xl('Decided in 12h'),
            'sla_bg' => '#e8f8ee', 'sla_color' => '#1e7e34',
            'sla_subtext' => null, 'sla_progress' => 100,
            'action_label' => xl('File Appeal'), 'action_type' => 'button',
            'action_bg' => '#fff', 'action_color' => '#c0392b', 'action_border' => '#c0392b',
        ],
        '97542' => [
            'adj_icon' => '⚠️', 'adj_label' => xl('Certified Partial'),
            'adj_bg' => '#fff8e1', 'adj_color' => '#9a7d0a',
            'sla_icon' => '✓', 'sla_label' => xl('Decided in 22h'),
            'sla_bg' => '#e8f8ee', 'sla_color' => '#1e7e34',
            'sla_subtext' => null, 'sla_progress' => 100,
            'action_label' => xl('Request Remaining 6'), 'action_type' => 'button',
            'action_bg' => '#fff', 'action_color' => '#333', 'action_border' => '#ccc',
        ],
        '97162' => [
            'adj_icon' => '⭐', 'adj_label' => xl('Gold-Card Auto-Approved'),
            'adj_bg' => '#fff8e1', 'adj_color' => '#b8860b',
            'sla_icon' => '⚡', 'sla_label' => xl('Instantaneous (0.8s)'),
            'sla_bg' => '#e8f8ee', 'sla_color' => '#1e7e34',
            'sla_subtext' => null, 'sla_progress' => 100,
            'action_label' => xl('Exemption Verified'), 'action_type' => 'text',
            'action_color' => '#b8860b',
        ],
        'K0848' => [
            'adj_icon' => '📞', 'adj_label' => xl('Pended: P2P Scheduled'),
            'adj_bg' => '#fdf2e3', 'adj_color' => '#b8621b',
            'sla_icon' => '⏳', 'sla_label' => xl('48h to P2P Window'),
            'sla_bg' => '#fff6df', 'sla_color' => '#9a7d0a',
            'sla_subtext' => null, 'sla_progress' => 15,
            'action_label' => xl('Join P2P Call'), 'action_type' => 'button',
            'action_bg' => '#fff', 'action_color' => '#b8621b', 'action_border' => '#b8621b',
        ],
    ];

    return $rules[$cptCode] ?? [
        'adj_icon' => '—', 'adj_label' => xl('Unknown'),
        'adj_bg' => '#f0f0f0', 'adj_color' => '#666',
        'sla_icon' => '—', 'sla_label' => xl('N/A'),
        'sla_bg' => '#f0f0f0', 'sla_color' => '#666',
        'sla_subtext' => null, 'sla_progress' => null,
        'action_label' => '—', 'action_type' => 'text', 'action_color' => '#999',
    ];
}

/**
 * mock
 */
function buildMockPaDisplayData(array $iter, string $cpt, array $adj, int $approvedUnits = null): array
{
    $authNum = $iter['authorization_number'] ?? ('AUTH-' . ($iter['order_id'] ?? 'UNK'));

    $descMap = [
        'K0823' => "Group 2 standard power wheelchair captain's chair up to 300 lbs",
        'K0861' => "Power wheelchair, group 3 heavy duty, captain's chair, up to 450 lbs",
        '15823' => "Blepharoplasty, upper eyelid; with excessive skin weighting down lid",
        '97542' => "Wheelchair management (e.g., assessment, fitting, training), each 15 minutes",
        '97162' => "Physical therapy evaluation: moderate complexity, 30 minutes",
        'K0848' => "Power wheelchair, group 3 standard, sling/solid seat/back, up to 300 lbs",
    ];

    // Determination "kind" drives letter styling — derive from the adjudication label
    // rather than hardcoding per-CPT, so it stays in sync with getPaAdjudicationForCpt().
    $label = $adj['adj_label'];
    if (stripos($label, 'denied') !== false) {
        $kind = 'denied';
    } elseif (stripos($label, 'partial') !== false) {
        $kind = 'partial';
    } elseif (stripos($label, 'pended') !== false) {
        $kind = 'pended';
    } elseif (stripos($label, 'certified') !== false || stripos($label, 'approved') !== false || stripos($label, 'gold') !== false) {
        $kind = 'approved';
    } else {
        $kind = 'pended';
    }

    $payload = [
        'resourceType' => 'Bundle',
        'type'         => 'collection',
        'entry'        => [[
            'resource' => [
                'resourceType' => 'Claim',
                'id'           => 'claim-' . strtolower($cpt) . '-request',
                'use'          => 'preauthorization',
                'item'         => [[
                    'productOrService' => ['coding' => [['code' => $cpt]]],
                    'quantity'         => ['value' => (int) ($iter['init_units'] ?? 1)],
                ]],
            ],
        ]],
    ];

    $response = [
        'resourceType' => 'ClaimResponse',
        'id'           => 'response-' . strtolower($cpt),
        'outcome'      => $label,
        'disposition'  => $adj['sla_subtext'] ?? $adj['sla_label'],
        'preAuthRef'   => $authNum,
    ];

    return [
        'auth_num'  => $authNum,
        'cpt'       => $cpt,
        'status'    => $label,
        'kind'      => $kind, // approved | denied | partial | pended
        'rationale' => $adj['sla_subtext'] ?: ($adj['sla_label'] . ' — ' . $label),
        'units'     => ($iter['init_units'] ?? 1) . ' Unit(s)',
        'payload'   => $payload,
        'response'  => $response,

        // Letter-specific fields
        'letter' => [
            'payer_name'      => $GLOBALS['pa_payer_name'] ?? 'Global Health Information Technology',
            'payer_id'        => $iter['payer_id'] ?? '6',
            'notice_date'     => date('F j, Y'),
            'txn_control_no'  => 'TXN-PAS-' . ($iter['order_id'] ?? '000') . '-' . date('Y'),
            'req_id'          => 'Claim/claim-' . strtolower($cpt) . '-request',
            'member_name'     => $iter['member_name'],
            'member_id'       => $iter['policy_number'] ?? '—',
            'group_number'    => $iter['group_number'] ?? '—',
            'dob'             => $iter['DOB'] ?? '—',
            'clinician'       => $iter['provider_name'] ?? '—',
            'provider_npi'    => $iter['provider_npi'] ?? '—',
            'facility'        => $iter['facility_name'] ?? '—',
            'pos'             => $iter['pos'] ?? '11 - Office',
            'code'            => $cpt,
            'description'     => $descMap[$cpt] ?? 'Medical Service / Procedure',
            'requested_units' => ($iter['init_units'] ?? 1) . ' Unit(s)',
            'approved_units'  => $kind === 'denied' ? '0 Unit(s)' : (($approvedUnits ?? ($iter['init_units'] ?? 1)) . ' Unit(s)'),
            'validity_period' => $kind === 'approved'
                ? date('Y-m-d') . ' to ' . date('Y-m-d', strtotime('+6 months'))
                : ($kind === 'partial' ? date('Y-m-d') . ' to ' . date('Y-m-d', strtotime('+2 months')) : 'Not Authorized'),
        ],
    ];
}

function formatTimeInStatus(?string $createdAt): string
{
    if (empty($createdAt)) {
        return '—';
    }

    $created = strtotime($createdAt);
    if ($created === false) {
        return '—';
    }

    $diffSeconds = time() - $created;
    if ($diffSeconds < 0) {
        $diffSeconds = 0;
    }

    $days = intdiv($diffSeconds, 86400);
    $hours = intdiv($diffSeconds % 86400, 3600);
    $minutes = intdiv($diffSeconds % 3600, 60);

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . 'm';
}

const TABLE_TD = "</td><td>";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo xlt('Add Prior Auth'); ?></title>
    <?php Header::setupHeader(['common', 'datetime-picker']) ?>

    <script>
        $(function () {
            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
            });
        })

        function refreshme() {
            top.restoreSession();
            location.reload();
        }
    </script>
</head>
<body>
    <div class="container">
        <div class="m-4">
            <span style="font-size: xx-large; padding-right: 20px"><?php echo xlt('Prior Authorization Manager'); ?></span>
            <a href="../../../../patient_file/summary/demographics.php" onclick="top.restoreSession()"
                title="<?php echo xla('Go Back') ?>">
                <i id="advanced-tooltip" class="fa fa-undo fa-2x" aria-hidden="true"></i></a>

        </div>
        <div class="m-4">
            <?php if (empty($pid)) {
                echo xlt("You must be in a patients Chart to enter this information");
                die;
            } ?>
            <div class="m-3">
                <h3><?php echo xlt('Enter new authorization'); ?></h3>
            </div>
            <form id="theform" method="post" action="index.php" onsubmit="top.restoreSession()">
                <input type="hidden" name="token" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                <input type="hidden" id="id" name="id" value="">
                <div class="form-row">
                    <div class="col">
                        <input class="form-control" id="authorization" name="authorization" value="" placeholder="<?php echo xla('Authorization Number') ?>">
                    </div>
                    <div class="col">
                        <input class="form-control" id="units" name="units" value="" placeholder="<?php echo xla('Units') ?>">
                    </div>
                    <div class="col">
                        <input class="form-control datepicker" id="start_date" name="start_date" value="" placeholder="<?php echo xla('Start Date') ?>" readonly>
                    </div>
                    <div class="col">
                        <input class="form-control datepicker" id="end_date" name="end_date" value="" placeholder="<?php echo xla('End Date') ?>" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="col my-1">
                        <input class="form-control" id="cpts" name="cpts" value="" placeholder="<?php echo xla('CPTs') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="col">
                        <input class="form-control btn btn-primary" type="submit" value="<?php echo xla('Save') ?>">
                    </div>
                </div>
            </form>
        </div>
        <div style="margin: 0 -90.5px; padding: 0 4px;">
            <h3 class="mb-3"><?php echo xlt('Prior Authorization Queue'); ?></h3>
            <div class="table-responsive" id="pa-queue-container" data-cursor="<?php echo (int) StatusSync::getCounter(); ?>">
                <table class="table table-bordered table-sm bg-white mb-0">
                    <tr style="background-color: var(--gray200);">
                        <th><?php echo xlt('Order ID'); ?></th>
                        <th><?php echo xlt('Encounter ID'); ?></th>
                        <th><?php echo xlt('Auth #'); ?></th>
                        <th><?php echo xlt('PA Status'); ?></th>
                        <th><?php echo xlt('PA Adjudication Status'); ?></th>
                        <th><?php echo xlt('CPT/HCPCS'); ?></th>
                        <th><?php echo xlt('ICDs'); ?></th>
                        <th><?php echo xlt('Allotted / Remaining'); ?></th>
                        <th><?php echo xlt('Start Date'); ?></th>
                        <th><?php echo xlt('Time In Status'); ?></th>
                        <th><?php echo xlt('Payload'); ?></th>
                        <th><?php echo xlt('Response'); ?></th>
                        <th><?php echo xlt('Print'); ?></th>
                        <th><?php echo xlt('Action'); ?></th>
                    </tr>
                    <?php
                    if (!empty($authList)) {
                        foreach ($authList as $iter) {
                            $used = AuthorizationService::getUnitsUsed($iter['auth_num'], $iter['pid'], $iter['cpt'], $iter['start_date'], $iter['end_date']);
                            $remaining = $iter['init_units'] - $used;
 
                            $encounterId   = $iter['encounter_id'] ?? '—';
                            $icds          = explode(':', $iter['icd10'])[1] ?? '—';
                            $status        = $iter['pa_status'] ?? '';
                            $orderId       = $iter['order_id'] ?? '';
                            $timeInStatus  = $iter['time_in_status'] ?? '—';
                            $payloadUrl    = $iter['payload_url'] ?? '#';
                            $responseUrl   = $iter['response_url'] ?? '#';
                            $canPrint      = !empty($iter['can_print']);
                            $actionText    = $iter['action_note'] ?? '—';
                            $cpt           = explode(':', $iter['code'])[1] ?? '—';
                            $is_auth       = $iter['pa_status'] !== 'pa-not-required';
 
                            [$statusLabel, $statusBadgeClass] = StatusSync::describe($status);
                            ?>
                            <?php 
                                $useMock = $_ENV['PA_USE_MOCK_DATA'] === 'true';
                                
                                $adj = getPaAdjudicationForCpt($cpt);
                                $mock = buildMockPaDisplayData($iter, $cpt, $adj, (int) $remaining);
                                $mockJson = attr(json_encode($mock));
                                
                            ?>
                            
                            <tr data-order-id="<?php echo attr($orderId); ?>" data-pa-mock="<?php echo $mockJson; ?>">
                                <td><?php echo text($orderId); ?></td>
                                <td><?php echo text($encounterId); ?></td>
                                <td data-field="auth-number"><?php echo text($iter['authorization_number']); ?></td>
                                <td>
                                    <?php if (!empty($is_auth)) : ?>
                                        <span class="badge badge-pill" style="background:#fbe4e4;color:#b3261e;padding:5px 12px;">
                                            <?php echo xlt('Required'); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="badge badge-pill" style="background:#e6f4ea;color:#1e7e34;padding:5px 12px;">
                                            <?php echo xlt('Not Required'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-pill" style="background:<?php echo attr($adj['adj_bg']); ?>;color:<?php echo attr($adj['adj_color']); ?>;padding:6px 12px;">
                                        <?php if ($useMock): ?>
                                            <?php echo htmlspecialchars($adj['adj_icon']); ?> <?php echo text($adj['adj_label']); ?>
                                        <?php else: ?>
                                            <?php echo text($statusLabel); ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td><?php echo text($cpt); ?></td>
                                <td><?php echo text($icds); ?></td>
                                <td><?php echo text($iter['init_units']) . ' / ' . text($remaining); ?></td>
                                 <td><?php echo attr(substr($iter['start_date'], 0, 10)); ?></td>
                                <td>
                                    <?php if ($useMock) : ?>
                                        <span class="badge badge-pill" style="background:<?php echo attr($adj['sla_bg']); ?>;color:<?php echo attr($adj['sla_color']); ?>;padding:6px 12px;">
                                            <?php echo htmlspecialchars($adj['sla_icon']); ?> <?php echo text($adj['sla_label']); ?>
                                        </span>
                                        <?php if ($adj['sla_progress'] !== null) : ?>
                                            <div class="progress" style="height:4px;margin-top:4px;">
                                                <div class="progress-bar" style="width:<?php echo (int) $adj['sla_progress']; ?>%;background:<?php echo attr($adj['sla_color']); ?>;"></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($adj['sla_subtext'])) : ?>
                                            <div class="small" style="color:<?php echo attr($adj['sla_color']); ?>;"><?php echo text($adj['sla_subtext']); ?></div>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <?php echo text(formatTimeInStatus($iter['start_date'] ?? null)); ?>
                                    <?php endif; ?>
                                </td>
                                <?php
                                    $btnOutline = 'background:#fff;color:#334155;border:1px solid #94a3b8;border-radius:4px;padding:4px 12px;font-size:12px;font-weight:600;';
                                    $btnDisabled = 'background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;border-radius:4px;padding:4px 12px;font-size:12px;font-weight:600;cursor:not-allowed;';
                                    $btnLetter = 'background:#fff;color:#0f172a;border:1px solid #94a3b8;border-radius:4px;padding:4px 12px;font-size:12px;font-weight:600;';
                                ?>

                                <td>
                                    <button type="button" style="<?php echo $btnOutline; ?>" onclick="viewPaPayload(this)">
                                        <?php echo xlt('View'); ?>
                                    </button>
                                </td>
                                <td>
                                    <?php if ($useMock) : ?>
                                        <?php if ($adj['adj_label'] === 'Submission error') : ?>
                                            <button type="button" style="<?php echo $btnDisabled; ?>" disabled>—</button>
                                        <?php elseif (stripos($adj['adj_label'], 'in review') !== false || stripos($adj['adj_label'], 'progress') !== false) : ?>
                                            <button type="button" style="<?php echo $btnOutline; ?>" onclick="alert('Checking Payer Task status: Payer Engine actively validating clinical policy criteria.')">
                                                <?php echo xlt('Check'); ?>
                                            </button>
                                        <?php else : ?>
                                            <button type="button" style="<?php echo $btnOutline; ?>" onclick="viewPaResponse(this)">
                                                <?php echo xlt('View'); ?>
                                            </button>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($useMock) : ?>
                                        <button type="button" style="<?php echo $btnLetter; ?>" onclick="printPaLetter(this)">
                                            🖨️ <?php echo xlt('Letter'); ?>
                                        </button>
                                    <?php else : ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($useMock) : ?>
                                        <?php if ($adj['action_type'] === 'button') : ?>
                                            <button type="button" class="btn btn-sm"
                                                style="background:<?php echo attr($adj['action_bg']); ?>;color:<?php echo attr($adj['action_color']); ?>;<?php echo isset($adj['action_border']) ? 'border:1px solid ' . attr($adj['action_border']) . ';' : ''; ?>">
                                                <?php echo text($adj['action_label']); ?>
                                            </button>
                                        <?php else : ?>
                                            <span style="color:<?php echo attr($adj['action_color']); ?>;font-weight:600;">
                                                <?php echo text($adj['action_label']); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </table>
            </div>

            <div id="pa-json-modal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:1000;align-items:center;justify-content:center;">
                <div style="background:#fff;border-radius:8px;max-width:700px;width:90%;max-height:85vh;overflow-y:auto;">
                    <div style="display:flex;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e2e8f0;">
                        <strong id="pa-json-title"><?php echo xlt('FHIR Bundle'); ?></strong>
                        <button type="button" class="btn btn-outline btn-sm" onclick="closePaModal('pa-json-modal')">✕</button>
                    </div>
                    <pre id="pa-json-body" style="padding:16px;font-size:12px;white-space:pre-wrap;word-break:break-word;"></pre>
                </div>
            </div>

            <div id="pa-print-modal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:1000;align-items:center;justify-content:center;">
                <div id="pa-print-body" style="background:#fff;border-radius:8px;max-width:850px;width:90%;max-height:90vh;overflow-y:auto;">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <div>
                            <strong style="font-size:14px;"><?php echo xlt('Official Coverage Determination Notice'); ?></strong>
                            <span style="font-size:11px;color:#64748b;margin-left:8px;"><?php echo xlt('CMS-0057-F Certified Legal Document'); ?></span>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><?php echo xlt('Print Document'); ?></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="closePaModal('pa-print-modal')"><?php echo xlt('Close'); ?></button>
                        </div>
                    </div>

                    <div style="padding:24px 30px;">
                        <div style="border-bottom:2px solid #0f172a;padding-bottom:14px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;">
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#0f172a;" id="pa-letter-payer-name"></div>
                                <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo xlt('Utilization Management & Clinical Appeals Division'); ?> &bull; <?php echo xlt('Payer ID'); ?>: <span id="pa-letter-payer-id"></span></div>
                            </div>
                            <span id="pa-letter-tag" style="font-size:11px;font-weight:700;padding:4px 10px;border-radius:4px;text-transform:uppercase;letter-spacing:.5px;"></span>
                        </div>

                        <div style="display:flex;justify-content:space-between;margin-bottom:20px;font-size:12px;">
                            <div>
                                <strong><?php echo xlt('Date of Notice'); ?>:</strong> <span id="pa-letter-date"></span><br>
                                <strong><?php echo xlt('Transaction Control #'); ?>:</strong> <span id="pa-letter-txn"></span>
                            </div>
                            <div style="text-align:right;">
                                <strong><?php echo xlt('Authorization Reference #'); ?>:</strong> <span id="pa-letter-auth" style="font-weight:800;color:#0f172a;"></span><br>
                                <strong><?php echo xlt('Electronic PAS Request ID'); ?>:</strong> <span id="pa-letter-req"></span>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin-bottom:20px;">
                            <div>
                                <p style="font-weight:700;color:#0f172a;margin-bottom:8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px;"><?php echo xlt('ENROLLEE / BENEFICIARY'); ?></p>
                                <p style="font-size:12px;margin-bottom:5px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Member Name'); ?>:</strong> <span id="pa-letter-member-name"></span></p>
                                <p style="font-size:12px;margin-bottom:5px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Member ID'); ?>:</strong> <span id="pa-letter-member-id"></span></p>
                                <p style="font-size:12px;margin-bottom:5px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Group Number'); ?>:</strong> <span id="pa-letter-group"></span></p>
                                <p style="font-size:12px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Date of Birth'); ?>:</strong> <span id="pa-letter-dob"></span></p>
                            </div>
                            <div>
                                <p style="font-weight:700;color:#0f172a;margin-bottom:8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px;"><?php echo xlt('REQUESTING PROVIDER & FACILITY'); ?></p>
                                <p style="font-size:12px;margin-bottom:5px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Clinician'); ?>:</strong> <span id="pa-letter-clinician"></span></p>
                                <p style="font-size:12px;margin-bottom:5px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Provider NPI'); ?>:</strong> <span id="pa-letter-npi"></span></p>
                                <p style="font-size:12px;margin-bottom:5px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Facility'); ?>:</strong> <span id="pa-letter-facility"></span></p>
                                <p style="font-size:12px;"><strong style="display:inline-block;width:110px;color:#475569;"><?php echo xlt('Place of Service'); ?>:</strong> <span id="pa-letter-pos"></span></p>
                            </div>
                        </div>

                        <div id="pa-letter-callout" style="border-left:4px solid #10b981;background:#f0fdf4;padding:12px 16px;margin-bottom:20px;border-radius:0 6px 6px 0;">
                            <h4 id="pa-letter-callout-title" style="font-size:13px;font-weight:700;margin-bottom:4px;"></h4>
                            <p id="pa-letter-callout-body" style="font-size:12px;line-height:1.5;color:#1e293b;"></p>
                        </div>

                        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                            <thead>
                                <tr>
                                    <th style="width:80px;background:#f1f5f9;border:1px solid #cbd5e1;padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#475569;"><?php echo xlt('Code'); ?></th>
                                    <th style="background:#f1f5f9;border:1px solid #cbd5e1;padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#475569;"><?php echo xlt('Description'); ?></th>
                                    <th style="width:100px;background:#f1f5f9;border:1px solid #cbd5e1;padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#475569;"><?php echo xlt('Requested'); ?></th>
                                    <th style="width:110px;background:#f1f5f9;border:1px solid #cbd5e1;padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#475569;"><?php echo xlt('Approved Qty'); ?></th>
                                    <th style="width:170px;background:#f1f5f9;border:1px solid #cbd5e1;padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#475569;"><?php echo xlt('Authorized Validity Period'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="border:1px solid #cbd5e1;padding:10px 12px;font-size:12px;font-weight:700;color:#0f172a;" id="pa-letter-code"></td>
                                    <td style="border:1px solid #cbd5e1;padding:10px 12px;font-size:12px;" id="pa-letter-desc"></td>
                                    <td style="border:1px solid #cbd5e1;padding:10px 12px;font-size:12px;" id="pa-letter-req-units"></td>
                                    <td style="border:1px solid #cbd5e1;padding:10px 12px;font-size:12px;font-weight:700;" id="pa-letter-appr-units"></td>
                                    <td style="border:1px solid #cbd5e1;padding:10px 12px;font-size:12px;" id="pa-letter-validity"></td>
                                </tr>
                            </tbody>
                        </table>

                        <div style="margin-bottom:20px;">
                            <h4 style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:6px;text-transform:uppercase;"><?php echo xlt('Clinical Determination & Coverage Rationale'); ?></h4>
                            <p id="pa-letter-rationale" style="font-size:12px;line-height:1.6;color:#334155;background:#f8fafc;padding:12px;border-radius:4px;border:1px solid #e2e8f0;"></p>
                        </div>

                        <div style="margin-bottom:24px;">
                            <h4 style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:6px;text-transform:uppercase;"><?php echo xlt('Billing & Claim Submission Instructions'); ?></h4>
                            <p style="font-size:12px;line-height:1.5;color:#334155;">
                                <?php echo xlt('To prevent delayed payment or automatic claim denials, the servicing provider must enter the Authorization Reference Number'); ?>
                                (<strong id="pa-letter-billing-auth"></strong>) <?php echo xlt('in Box 23 of the CMS-1500 claim form or loop 2300 REF*G1 of the electronic 837P transaction.'); ?>
                            </p>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:24px;padding-top:14px;border-top:1px solid #e2e8f0;">
                            <div>
                                <div style="font-family:'Brush Script MT',cursive,sans-serif;font-size:22px;color:#1e3a8a;"><?php echo xlt('Dr. Eleanor Vance, M.D.'); ?></div>
                                <div style="font-weight:600;font-size:11px;color:#0f172a;margin-top:2px;"><?php echo xlt('Eleanor Vance, M.D., Chief Medical Officer'); ?></div>
                                <div style="font-size:11px;color:#64748b;"><?php echo xlt('Utilization Review & Appeals Committee'); ?></div>
                            </div>
                            <div style="text-align:right;font-size:10px;color:#94a3b8;">
                                <?php echo xlt('Electronically Authenticated'); ?> &bull; <?php echo xlt('CMS-0057-F Compliant'); ?>
                            </div>
                        </div>

                        <div style="font-size:11px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:14px;margin-top:20px;line-height:1.5;">
                            <strong><?php echo xlt('Important Rights Notice'); ?>:</strong> <?php echo xlt('If you disagree with this determination, you or your designated representative have the right to request a formal Level 1 Appeal under standard CMS procedures within 180 days of the date of this notice.'); ?>
                        </div>
                    </div>

                    <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn btn-outline-secondary" onclick="closePaModal('pa-print-modal')"><?php echo xlt('Close'); ?></button>
                        <button type="button" class="btn btn-primary" onclick="window.print()"><?php echo xlt('Print Legal Document'); ?></button>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <script>
        function getRowData(jsonData) {
            let dataArray = document.getElementById(jsonData).value;
            const obj = JSON.parse(dataArray);

            document.getElementById('id').value = obj.id;
            document.getElementById('authorization').value = obj.auth_num;
            document.getElementById('start_date').value = obj.start_date;
            document.getElementById('end_date').value = obj.end_date;
            document.getElementById('cpts').value = obj.cpt;
            document.getElementById('units').value = obj.init_units;
        }

        function removeEntry(id) {
            let url = 'deleter.php?id=' + encodeURIComponent(id) + '&csrf_token_form=' + <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>;
            ;
            dlgopen(url, '_blank', 290, 290, '', 'Delete Entry', {
                buttons: [
                    {text: <?php echo xlj('Done') ?>, style: 'danger btn-sm', close: true}
                ],
                onClosed: 'refreshme'
            })
        }

        (function () {
            var container = document.getElementById('pa-queue-container');
            if (!container) {
                return;
            }
            var cursor = parseInt(container.getAttribute('data-cursor') || '0', 10);
            var base = <?php echo js_escape($GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-gheit-prior-auth/public/'); ?>;
 
            function applyChange(change) {
                var row = container.querySelector('tr[data-order-id="' + change.order_id + '"]');
                if (!row) {
                    return;
                }
                var badge = row.querySelector('[data-field="status-badge"]');
                if (badge) {
                    badge.textContent = change.label;
                    badge.className = 'badge badge-pill ' + change.badgeClass;
                }
                var authEl = row.querySelector('[data-field="auth-number"]');
                if (authEl && change.authorization_number) {
                    authEl.textContent = change.authorization_number;
                }
            }
 
            function handlePayload(payload) {
                if (!payload || !payload.changes) {
                    return;
                }
                payload.changes.forEach(applyChange);
                if (payload.cursor) {
                    cursor = payload.cursor;
                }
            }
 
            var polling = false;
            function startPolling() {
                if (polling) {
                    return;
                }
                polling = true;
                setInterval(function () {
                    fetch(base + 'status_poll.php?scope=patient&cursor=' + cursor, { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(handlePayload)
                        .catch(function () {});
                }, 5000);
            }
 
            if (typeof EventSource !== 'undefined') {
                var es = new EventSource(base + 'status_stream.php?scope=patient&cursor=' + cursor);
                es.onmessage = function (e) {
                    try {
                        handlePayload(JSON.parse(e.data));
                    } catch (err) {
                        // malformed frame — ignore, next tick will catch up
                    }
                };
                es.onerror = function () {
                    startPolling();
                };
            } else {
                startPolling();
            }
        })();

        function getPaMockFromButton(btn) {
            var row = btn.closest('tr');
            try {
                return JSON.parse(row.getAttribute('data-pa-mock') || '{}');
            } catch (e) {
                return {};
            }
        }

        function openPaModal(id) {
            document.getElementById(id).style.display = 'flex';
        }
        function closePaModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function viewPaPayload(btn) {
            var mock = getPaMockFromButton(btn);
            document.getElementById('pa-json-title').textContent = 'Payload — ' + (mock.cpt || '');
            document.getElementById('pa-json-body').textContent = JSON.stringify(mock.payload || {}, null, 2);
            openPaModal('pa-json-modal');
        }

        function viewPaResponse(btn) {
            var mock = getPaMockFromButton(btn);
            if (mock.kind === 'approved') {
                printPaLetter(btn); // show the same official letter for approved/certified rows
                return;
            }
            document.getElementById('pa-json-title').textContent = 'Response — ' + (mock.cpt || '');
            document.getElementById('pa-json-body').textContent = JSON.stringify(mock.response || {}, null, 2);
            openPaModal('pa-json-modal');
        }

        function printPaLetter(btn) {
            var mock = getPaMockFromButton(btn);
            var L = mock.letter || {};
            var kind = mock.kind || 'pended';

            var tag = document.getElementById('pa-letter-tag');
            var callout = document.getElementById('pa-letter-callout');
            var title = document.getElementById('pa-letter-callout-title');
            var body = document.getElementById('pa-letter-callout-body');

            var styles = {
                approved: { tagText: 'OFFICIAL APPROVAL NOTICE', tagBg: '#ecfdf5', tagColor: '#065f46', tagBorder: '#a7f3d0',
                    calloutBorder: '#10b981', calloutBg: '#f0fdf4', title: 'Prior Authorization Approved', titleColor: '#065f46',
                    body: 'We have reviewed your prior authorization request. Based on the medical necessity documentation submitted, coverage is hereby approved.' },
                denied: { tagText: 'OFFICIAL ADVERSE DETERMINATION', tagBg: '#fef2f2', tagColor: '#991b1b', tagBorder: '#fecaca',
                    calloutBorder: '#ef4444', calloutBg: '#fef2f2', title: 'Prior Authorization Request Denied', titleColor: '#991b1b',
                    body: 'We have reviewed your prior authorization request. Coverage has been denied based on clinical policy guidelines detailed below.' },
                partial: { tagText: 'PARTIAL APPROVAL NOTICE', tagBg: '#fffbeb', tagColor: '#92400e', tagBorder: '#fde68a',
                    calloutBorder: '#f59e0b', calloutBg: '#fffbeb', title: 'Prior Authorization Partially Approved', titleColor: '#92400e',
                    body: 'Coverage is certified for a modified quantity or initial episode of care. Additional units require clinical re-assessment.' },
                pended: { tagText: 'ADDITIONAL REVIEW NOTICE', tagBg: '#fffbeb', tagColor: '#92400e', tagBorder: '#fde68a',
                    calloutBorder: '#f59e0b', calloutBg: '#fffbeb', title: 'Prior Authorization Pended for Clinical Review', titleColor: '#92400e',
                    body: 'A peer-to-peer discussion or supplementary clinical chart records are required before final determination can be completed.' }
            };
            var s = styles[kind] || styles.pended;

            tag.textContent = s.tagText;
            tag.style.background = s.tagBg;
            tag.style.color = s.tagColor;
            tag.style.border = '1px solid ' + s.tagBorder;

            callout.style.borderLeftColor = s.calloutBorder;
            callout.style.background = s.calloutBg;
            title.textContent = s.title;
            title.style.color = s.titleColor;
            body.textContent = s.body;

            document.getElementById('pa-letter-payer-name').textContent = L.payer_name || '';
            document.getElementById('pa-letter-payer-id').textContent = L.payer_id || '';
            document.getElementById('pa-letter-date').textContent = L.notice_date || '';
            document.getElementById('pa-letter-txn').textContent = L.txn_control_no || '';
            document.getElementById('pa-letter-auth').textContent = mock.auth_num || '';
            document.getElementById('pa-letter-req').textContent = L.req_id || '';
            document.getElementById('pa-letter-member-name').textContent = L.member_name || '';
            document.getElementById('pa-letter-member-id').textContent = L.member_id || '';
            document.getElementById('pa-letter-group').textContent = L.group_number || '';
            document.getElementById('pa-letter-dob').textContent = L.dob || '';
            document.getElementById('pa-letter-clinician').textContent = L.clinician || '';
            document.getElementById('pa-letter-npi').textContent = L.provider_npi || '';
            document.getElementById('pa-letter-facility').textContent = L.facility || '';
            document.getElementById('pa-letter-pos').textContent = L.pos || '';
            document.getElementById('pa-letter-code').textContent = L.code || '';
            document.getElementById('pa-letter-desc').textContent = L.description || '';
            document.getElementById('pa-letter-req-units').textContent = L.requested_units || '';
            var apprEl = document.getElementById('pa-letter-appr-units');
            apprEl.textContent = L.approved_units || '';
            apprEl.style.color = kind === 'approved' ? '#059669' : (kind === 'denied' ? '#dc2626' : '#d97706');
            document.getElementById('pa-letter-validity').textContent = L.validity_period || '';
            document.getElementById('pa-letter-rationale').textContent = mock.rationale || '';
            document.getElementById('pa-letter-billing-auth').textContent = mock.auth_num || '';

            openPaModal('pa-print-modal');
        }

        // Close on outside click, consistent with the earlier standalone mockup
        window.addEventListener('click', function (event) {
            if (event.target.classList && event.target.classList.contains('modal-backdrop')) {
                event.target.style.display = 'none';
            }
        });
    </script>

</body>
</html>
