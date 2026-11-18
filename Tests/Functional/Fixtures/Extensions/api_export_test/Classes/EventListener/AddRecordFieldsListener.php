<?php

declare(strict_types=1);

namespace Ithilgers\ApiExportTest\EventListener;

use Ithilgers\ApiExport\Event\ModifyFeedItemEvent;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\PropagateResponseException;

/**
 * Adds fields from the record, the requesting client and the request to
 * events; removes the teaser from news. For the client "Aborted by listener"
 * it aborts the feed with its own response (410).
 */
final class AddRecordFieldsListener
{
    public const ABORTING_CLIENT = 'Aborted by listener';

    public function __invoke(ModifyFeedItemEvent $event): void
    {
        if ($event->getClient()['title'] === self::ABORTING_CLIENT) {
            throw new PropagateResponseException(new JsonResponse(['error' => 'Gone'], 410), 1730600000);
        }

        $item = $event->getItem();
        if ($event->getResource() === 'events') {
            $item['pid'] = (int)$event->getRecord()['pid'];
            $item['client'] = (string)$event->getClient()['title'];
            $item['clientFields'] = array_keys($event->getClient());
            $item['requestPath'] = $event->getRequest()->getUri()->getPath();
            $item['requestPage'] = $event->getRequest()->getQueryParams()['page'] ?? null;
        } else {
            unset($item['teaser']);
        }
        $event->setItem($item);
    }
}
