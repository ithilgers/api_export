<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

/**
 * A feed provider delivers records of a specific type (e.g. events, news)
 * as normalized, JSON-serializable items.
 *
 * New data types are added by another class implementing this interface;
 * it is registered automatically (tagged service) and becomes reachable via
 * its resource name. For database tables, AbstractTableFeedProvider does most
 * of the work.
 */
interface FeedProviderInterface
{
    /**
     * Unique resource name used in the URL/API,
     * e.g. "events" -> /api/v1/events.
     */
    public function getResource(): string;

    /**
     * Returns the items of the requested page ($demand->offset/limit) and
     * the total number of all matching items.
     *
     * If a category filter is set that the provider cannot apply, it must
     * return an empty result (never an unfiltered one).
     */
    public function findItems(FeedDemand $demand): FeedResult;
}
