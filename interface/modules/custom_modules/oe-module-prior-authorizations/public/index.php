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
        <div class="m-4">
            <h3 class="mb-3"><?php echo xlt('Prior Authorization Queue'); ?></h3>
            <div class="table-responsive" id="pa-queue-container" data-cursor="<?php echo (int) StatusSync::getCounter(); ?>">
                <table class="table table-bordered table-sm bg-white mb-0">
                    <tr style="background-color: var(--gray200);">
                        <th><?php echo xlt('Order ID'); ?></th>
                        <th><?php echo xlt('Encounter ID'); ?></th>
                        <th><?php echo xlt('Auth #'); ?></th>
                        <th><?php echo xlt('PA Status'); ?></th>
                        <th><?php echo xlt('CPT/HCPCS'); ?></th>
                        <th><?php echo xlt('ICDs'); ?></th>
                        <th><?php echo xlt('Allotted / Remaining'); ?></th>
                        <th><?php echo xlt('Start Date'); ?></th>
                        <th><?php echo xlt('Time in Status'); ?></th>
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
 
                            [$statusLabel, $statusBadgeClass] = StatusSync::describe($status);
                            ?>
                            <tr data-order-id="<?php echo attr($orderId); ?>">
                                <td><?php echo text($orderId); ?></td>
                                <td><?php echo text($encounterId); ?></td>
                                <td data-field="auth-number"><?php echo text($iter['authorization_number']); ?></td>
                                <td>
                                    <span class="badge badge-pill <?php echo attr($statusBadgeClass); ?>" data-field="status-badge">
                                        <?php echo text($statusLabel); ?>
                                    </span>
                                </td>
                                <td><?php echo text($cpt); ?></td>
                                <td><?php echo text($icds); ?></td>
                                <td><?php echo text($iter['init_units']) . ' / ' . text($remaining); ?></td>
                                 <td><?php echo attr(substr($iter['start_date'], 0, 10)); ?></td>
                                <td>
                                    <span class="badge badge-pill" style="background:#eee;color:#555;padding:6px 12px;">
                                        <?php echo text(formatTimeInStatus($iter['start_date'] ?? null)); ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo attr($payloadUrl); ?>" target="_blank">
                                        <?php echo xlt('View Payload'); ?>
                                    </a>
                                </td>
                                <td>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo attr($responseUrl); ?>" target="_blank">
                                        <?php echo xlt('View Response'); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($canPrint) : ?>
                                        <button type="button" class="btn btn-sm" style="background:#2e8b57;color:#fff;"
                                                onclick="printAuth(<?php echo attr_js($iter['id']); ?>)">
                                            <?php echo xlt('Print'); ?>
                                        </button>
                                    <?php else : ?>
                                        <button type="button" class="btn btn-sm" style="background:#ccc;color:#777;" disabled>
                                            <?php echo xlt('Print'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?php echo text($actionText); ?></td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </table>
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

        function printAuth(id) {
            // TODO: wire up to real print/report endpoint for this authorization.
            top.restoreSession();
            window.open('print_auth.php?id=' + encodeURIComponent(id), '_blank', 'width=800,height=900');
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
    </script>

</body>
</html>
