<?php

namespace OpenEMR\Modules\CustomModuleGheit\EventSubscriber;

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Events\User\UserUpdatedEvent;
use OpenEMR\Services\FHIR\FhirPractitionerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use OpenEMR\Modules\CustomModuleGheit\Controller\SqsPublisher;

class UserUpdatedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            UserUpdatedEvent::EVENT_HANDLE => 'onUserUpdated'
        ];
    }

    public function onUserUpdated(UserUpdatedEvent $event): void
    {
        try {

            $data = $event->getNewUserData();
            $userId = $data['id'];

            $uuidRow = sqlQuery(
                "SELECT uuid FROM users WHERE id = ?",
                [$userId]
            );

            if (empty($uuidRow['uuid'])) {
                error_log("UUID missing");
                return;
            }

            $uuid = UuidRegistry::uuidToString($uuidRow['uuid']);

            $service = new FhirPractitionerService();
            $result = $service->getOne($uuid);

            $practitioner = $result->getData()[0] ?? null;

            if (!$practitioner) {
                error_log("Practitioner not found");
                return;
            }

            $fhir = $practitioner->jsonSerialize();

            /**
             * Publish Practitioner update to SQS
             */
            $eventPayload = [
                'timestamp' => date('c'),
                'data'      => $fhir,
            ];

            try {
                (new SqsPublisher())->publish('practitioner_updated', 'PUT', $eventPayload, $uuid);
            } catch (\Throwable $e) {
                error_log('SQS publisher failed: ' . $e->getMessage());
            }

        } catch (\Throwable $e) {
            error_log("Subscriber error: " . $e->getMessage());
        }
    }
}