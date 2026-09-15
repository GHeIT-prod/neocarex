<?php

namespace OpenEMR\Modules\GheitPriorAuth\Service;

use OpenEMR\Common\Uuid\UuidRegistry;

/**
 * StatusSync — the one place in the codebase that knows the thirteen Pax
 * status states, per FRD-PAX-NCX-001 FR-B-12e. Nothing else should
 * hardcode a state name.
 *
 * Also owns the DB work behind status_webhook.php (the receiver) and
 * status_push.php (the clinician-initiated PATCH, not implemented here).
 */
class StatusSync
{
    private const STATES = [
        'pa-not-required'    => ['No PA required', 'badge-secondary'],
        'dtr-required'       => ['Documentation required', 'badge-warning'],
        'dtr-pending-review' => ['Questionnaire in progress', 'badge-warning'],
        'dtr-complete'       => ['Questionnaire complete', 'badge-info'],
        'pa-required'        => ['PA required', 'badge-warning'],
        'pas-pending-review' => ['Submission in progress', 'badge-info'],
        'pas-submitted'      => ['Submitted to payer', 'badge-info'],
        'pas-pended'         => ['Payer requested more info', 'badge-warning'],
        'pas-approved'       => ['Approved by payer', 'badge-success'],
        'pas-denied'         => ['Denied by payer', 'badge-danger'],
        'completed'          => ['Completed', 'badge-success'],
        'rejected'           => ['Rejected / withdrawn', 'badge-danger'],
        'error'              => ['Submission error', 'badge-danger'],
    ];

    /** States a clinician may PATCH back to Pax (FR-B-13). */
    private const PROPOSABLE_STATES = ['completed', 'rejected'];

    /** Terminal states — nothing but a payer answer, resubmission, or another terminal state replaces these. */
    private const TERMINAL_STATES = ['completed', 'rejected'];

    public static function isValidState(string $state): bool
    {
        return array_key_exists($state, self::STATES);
    }

    public static function isProposable(string $state): bool
    {
        return in_array($state, self::PROPOSABLE_STATES, true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL_STATES, true);
    }

    /** @return array{0: string, 1: string} [label, badge class] */
    public static function describe(string $state): array
    {
        return self::STATES[$state] ?? ['Unknown', 'badge-secondary'];
    }

    public static function lookupWebhookSecret(string $clientId): ?string
    {
        $hostId = getenv('PAX_HOST_ID') ?: ($_SERVER['PAX_HOST_ID'] ?? null);
        $secret = getenv('PAX_HOST_SECRET') ?: ($_SERVER['PAX_HOST_SECRET'] ?? null);

        if (!$hostId || !$secret || !hash_equals((string) $hostId, $clientId)) {
            return null;
        }

        return (string) $secret;
    }

    public static function alreadyProcessed(string $eventId): bool
    {
        $row = sqlQuery(
            'SELECT 1 FROM pax_status_events WHERE event_id = ? LIMIT 1',
            [$eventId]
        );

        return !empty($row);
    }

    public static function resolvePatientIdForOrder(string $orderId): ?string
    {
        $row = sqlQuery(
            'SELECT patient_data.uuid AS patient_uuid
               FROM cds_hooks_crd_status
               JOIN patient_data ON patient_data.pid = cds_hooks_crd_status.patient_id
              WHERE cds_hooks_crd_status.order_id = ?
              LIMIT 1',
            [$orderId]
        );

        if (empty($row['patient_uuid'])) {
            return null;
        }

        return UuidRegistry::uuidToString($row['patient_uuid']);
    }

    public static function applyEvent(
        string $orderId,
        string $state,
        int $seq,
        string $occurredAt,
        ?string $authNumber,
        ?int $approvedQuantity,
        ?string $denialReason,
        ?string $cptCode,
        string $eventId
    ): bool {
        sqlBeginTrans();
        try {
            $current = sqlQuery(
                'SELECT seq FROM cds_hooks_crd_status WHERE order_id = ? FOR UPDATE',
                [$orderId]
            );

            $currentSeq = $current['seq'] ?? null;

            if ($currentSeq !== null && (int) $currentSeq >= $seq) {
                // Stale or duplicate relative to seq: ignore, still 200 (FR-B-10).
                sqlCommitTrans();
                return false;
            }

            sqlStatement(
                'UPDATE cds_hooks_crd_status
                    SET status = ?, authorization_number = ?, approved_quantity = ?,
                        denial_reason = ?, cpt_code = ?, seq = ?, occurred_at = ?,
                        updated_at = NOW()
                  WHERE order_id = ?',
                [$state, $authNumber, $approvedQuantity, $denialReason, $cptCode, $seq, $occurredAt, $orderId]
            );

            sqlStatement(
                'INSERT INTO pax_status_events (event_id, order_id, received_at) VALUES (?, ?, NOW())',
                [$eventId, $orderId]
            );

            sqlStatement('UPDATE pax_sync_seq SET counter = counter + 1');

            sqlCommitTrans();
            return true;
        } catch (\Throwable $e) {
            sqlRollbackTrans();
            // NFR-04: no payload, no patient identifiers in the log line.
            error_log('[pax-status] apply failed for order ' . $orderId . ': ' . $e->getMessage());
            throw $e;
        }
    }
}