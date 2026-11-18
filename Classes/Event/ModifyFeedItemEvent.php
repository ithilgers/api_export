<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Event;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched for every feed item before it is output.
 *
 * This lets projects add, change or remove fields without modifying the
 * extension – e.g. to output the price of an event:
 *
 *   if ($event->getResource() === 'events') {
 *       $item = $event->getItem();
 *       $item['price'] = (float)$event->getRecord()['price'];
 *       $event->setItem($item);
 *   }
 *
 * The feed is generated directly by a middleware: there is no
 * TypoScriptFrontendController and no ContentObjectRenderer. Everything about
 * the call is available via getRequest().
 */
final class ModifyFeedItemEvent
{
    /** @var array{uid: int, title: string} */
    private readonly array $client;

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $record
     * @param array<string, mixed> $client Only uid and title are passed on
     */
    public function __construct(
        private readonly string $resource,
        private array $item,
        private readonly array $record,
        array $client,
        private readonly ServerRequestInterface $request,
    ) {
        $this->client = [
            'uid' => (int)($client['uid'] ?? 0),
            'title' => (string)($client['title'] ?? ''),
        ];
    }

    /**
     * Resource name, e.g. "events" or "news".
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * @return array<string, mixed> The item as it will be output
     */
    public function getItem(): array
    {
        return $this->item;
    }

    /**
     * @param array<string, mixed> $item Must remain JSON-serializable
     */
    public function setItem(array $item): void
    {
        $this->item = $item;
    }

    /**
     * @return array<string, mixed> Complete record from the database
     */
    public function getRecord(): array
    {
        return $this->record;
    }

    /**
     * @return array{uid: int, title: string} Requesting client, without any token data
     */
    public function getClient(): array
    {
        return $this->client;
    }

    /**
     * The feed request (site, language, query parameters, normalizedParams, …).
     * Frontend and backend users are never authenticated for feed requests.
     */
    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }
}
