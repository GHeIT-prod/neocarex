<?php

namespace OpenEMR\Modules\GheitPriorAuth\Service;

use OpenEMR\Common\Uuid\UuidRegistry;

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

    public static function hostId(): string
    {
        return (string) (getenv('PAX_HOST_ID') ?: ($_SERVER['PAX_HOST_ID'] ?? ''));
    }
 
    public static function hostSecret(): string
    {
        return (string) (getenv('PAX_HOST_SECRET') ?: ($_SERVER['PAX_HOST_SECRET'] ?? ''));
    }

    public static function lookupWebhookSecret(string $clientId): ?string
    {
        $hostId = self::hostId();
        $secret = self::hostSecret();

        if (!$hostId || !$secret || !hash_equals((string) $hostId, $clientId)) {
            return null;
        }

        return (string) $secret;
    }

    public static function getCounter(): int
    {
        $row = sqlQuery('SELECT counter FROM pax_sync_seq LIMIT 1');
        return (int) ($row['counter'] ?? 0);
    }

     public static function getStatusForOrder(string $orderId): ?array
    {
        $row = sqlQuery(
            'SELECT order_id, status, seq, occurred_at, updated_at,
                    authorization_number, approved_quantity, denial_reason,
                    cpt_code, dtr_launch_url, resource_id
               FROM cds_hooks_crd_status
              WHERE order_id = ?
              LIMIT 1',
            [$orderId]
        );
 
        if (empty($row)) {
            return null;
        }
 
        [$label, $badgeClass] = self::describe($row['status']);
        $row['label'] = $label;
        $row['badgeClass'] = $badgeClass;
 
        return $row;
    }

    public static function getStatusList(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
 
        $result = sqlStatement(
            'SELECT cds_hooks_crd_status.order_id, cds_hooks_crd_status.status,
                    cds_hooks_crd_status.seq, cds_hooks_crd_status.updated_at,
                    cds_hooks_crd_status.authorization_number,
                    patient_data.fname, patient_data.lname
               FROM cds_hooks_crd_status
               JOIN patient_data ON patient_data.pid = cds_hooks_crd_status.patient_id
              ORDER BY cds_hooks_crd_status.updated_at DESC
              LIMIT ' . $limit
        );
 
        $rows = [];
        while ($row = sqlFetchArray($result)) {
            [$label, $badgeClass] = self::describe($row['status']);
            $row['label'] = $label;
            $row['badgeClass'] = $badgeClass;
            $rows[] = $row;
        }
 
        return $rows;
    }

    public static function proposeStateToPax(string $orderId, string $state, ?string $reason): array
    {
        $hostId = self::hostId();
        $secret = self::hostSecret();
        if ($hostId === '' || $secret === '') {
            throw new \RuntimeException('PAX_HOST_ID / PAX_HOST_SECRET are not set.');
        }
 
        $origin = rtrim((string) (getenv('PAX_ORIGIN') ?: ($_SERVER['PAX_ORIGIN'] ?? '')), '/');
        if ($origin === '') {
            throw new \RuntimeException('PAX_ORIGIN is not set.');
        }
 
        $body = ['orderId' => $orderId, 'state' => $state];
        if ($reason !== null && $reason !== '') {
            $body['reason'] = mb_substr($reason, 0, 500);
        }
 
        $json = json_encode($body);
        if ($json === false) {
            throw new \RuntimeException('Could not encode the PATCH body: ' . json_last_error_msg());
        }
 
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $json, $secret);
 
        $ch = curl_init($origin . '/integrations/pa-status');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Pax-Client: ' . $hostId,
                'X-Pax-Timestamp: ' . $timestamp,
                'X-Pax-Signature: ' . $signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log('[pax-push] order=' . $orderId . ' transport error: ' . $error);
            throw new \RuntimeException('Could not reach Pax.');
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
 
        $decoded = json_decode((string) $responseBody, true);
 
        return [
            'httpCode' => $httpCode,
            'body' => is_array($decoded) ? $decoded : [],
        ];
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
                    SET status = ?, approved_quantity = ?,
                        denial_reason = ?, cpt_code = ?, seq = ?, occurred_at = ?,
                        updated_at = NOW()
                  WHERE order_id = ?',
                [$state, $approvedQuantity, $denialReason, $cptCode, $seq, $occurredAt, $orderId]
            );

            sqlStatement(
                'INSERT INTO pax_status_events (event_id, order_id, received_at) VALUES (?, ?, NOW())',
                [$eventId, $orderId]
            );

            sqlStatement('UPDATE pax_sync_seq SET counter = counter + 1');

            $newCounter = self::getCounter();
            sqlStatement(
                'UPDATE cds_hooks_crd_status SET sync_seq = ? WHERE order_id = ?',
                [$newCounter, $orderId]
            );

            sqlCommitTrans();
            return true;
        } catch (\Throwable $e) {
            sqlRollbackTrans();
            error_log('[pax-status] apply failed for order ' . $orderId . ': ' . $e->getMessage());
            throw $e;
        }
    }

    public static function getChangesSince(int $cursor, ?int $patientPid, int $limit = 500): array
    {
        $limit = max(1, min($limit, 500));
 
        if ($patientPid !== null) {
            $result = sqlStatement(
                'SELECT order_id, patient_id, status, authorization_number, approved_quantity,
                        denial_reason, cpt_code, seq, occurred_at, sync_seq
                   FROM cds_hooks_crd_status
                  WHERE sync_seq > ? AND patient_id = ?
                  ORDER BY sync_seq ASC
                  LIMIT ' . $limit,
                [$cursor, $patientPid]
            );
        } else {
            $result = sqlStatement(
                'SELECT s.order_id, s.patient_id, s.status, s.authorization_number, s.approved_quantity,
                        s.denial_reason, s.cpt_code, s.seq, s.occurred_at, s.sync_seq,
                        p.fname, p.lname
                   FROM cds_hooks_crd_status s
                   JOIN patient_data p ON p.pid = s.patient_id
                  WHERE s.sync_seq > ?
                  ORDER BY s.sync_seq ASC
                  LIMIT ' . $limit,
                [$cursor]
            );
        }
 
        $changes = [];
        $newCursor = $cursor;
        while ($row = sqlFetchArray($result)) {
            [$label, $badgeClass] = self::describe($row['status']);
            $row['label'] = $label;
            $row['badgeClass'] = $badgeClass;
            $row['syncSeq'] = (int) $row['sync_seq'];
            if ($row['syncSeq'] > $newCursor) {
                $newCursor = $row['syncSeq'];
            }
            $changes[] = $row;
        }
 
        return ['cursor' => $newCursor, 'changes' => $changes];
    }
}