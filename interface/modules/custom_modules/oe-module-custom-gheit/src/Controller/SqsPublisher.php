<?php
namespace OpenEMR\Modules\CustomModuleGheit\Controller;

use Aws\Sqs\SqsClient;
use OpenEMR\Common\Logging\SystemLogger;
use Dotenv\Dotenv;

class SqsPublisher
{
    private ?SqsClient $client = null;
    private string $queueUrl;
    private SystemLogger $logger;

    public function __construct(?string $queueUrl = null)
    {
        $this->logger = new SystemLogger();

        Dotenv::createImmutable(dirname(__DIR__, 6))->safeLoad();

        $this->queueUrl = $queueUrl ?: ($_ENV['SQS_FHIR_QUEUE_URL'] ?? '');

        try {
            $this->client = new SqsClient([
                'region'      => $_ENV['AWS_REGION'],
                'version'     => $_ENV['SQS_VERSION'],
                'credentials' => [
                    'key'    => trim($_ENV['AWS_ACCESS_KEY_ID'] ?? ''),
                    'secret' => trim($_ENV['AWS_SECRET_ACCESS_KEY'] ?? ''),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->errorLogCaller('SQS client init failed', ['error' => $e->getMessage()]);
        }
    }

    public function publish(
        string $event,
        string $method,
        array $eventPayload,
        ?string $groupKey = null,
        ?string $dedupKey = null
    ): bool {
        if ($this->client === null || $this->queueUrl === '') {
            $this->logger->errorLogCaller('SQS not configured');
            return false;
        }

        $data         = $eventPayload['data'] ?? [];
        $resourceType = $data['resourceType'] ?? 'Unknown';
        $resourceId   = $data['id'] ?? null;

        try {
            $body = json_encode(
                $eventPayload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $e) {
            $this->logger->errorLogCaller('SQS: json_encode failed', ['error' => $e->getMessage()]);
            return false;
        }

        if (strlen($body) > 250 * 1024) {
            $this->logger->errorLogCaller('SQS: payload too large', [
                'bytes' => strlen($body), 'resourceType' => $resourceType,
            ]);
            return false;
        }

        $params = [
            'QueueUrl'    => $this->queueUrl,
            'MessageBody' => $body,
            'MessageAttributes' => [
                'event'         => ['DataType' => 'String', 'StringValue' => $event],
                "resourceType"  => ['DataType' => 'String', 'StringValue' => $resourceType],
                'method'        => ['DataType' => 'String', 'StringValue' => $method],
            ],
        ];

        if (str_ends_with($this->queueUrl, '.fifo')) {
            $params['MessageGroupId'] = $groupKey ?: ($resourceId ?: 'default');
            $params['MessageDeduplicationId'] = hash(
                'sha256',
                $event . '|' . $resourceType . '|' . ($dedupKey ?: ($resourceId ?? $body))
            );
        }

        try {
            $this->client->sendMessage($params);
            return true;
        } catch (\Throwable $e) {
            $this->logger->errorLogCaller('SQS publish failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}