#
# API clients: one record per consumer with its own token.
# Only the SHA-256 hash of the token and its last 4 characters are stored;
# the token itself has no column. The UNIQUE key also covers deleted records.
# uid/pid/tstamp/crdate/deleted/hidden/starttime/endtime are added
# automatically by the TYPO3 schema analyzer (DefaultTcaSchema).
#
CREATE TABLE tx_apiexport_domain_model_client (
    title varchar(255) DEFAULT '' NOT NULL,
    description text,
    token_hash varchar(64) DEFAULT NULL,
    token_hint varchar(4) DEFAULT '' NOT NULL,
    feeds int(11) unsigned DEFAULT 0 NOT NULL,
    last_used int(11) unsigned DEFAULT 0 NOT NULL,

    UNIQUE KEY token_hash (token_hash)
);

#
# Grants (inline children of an API client): per resource, which data the
# client receives. uid/pid/tstamp/crdate/deleted/hidden/sorting are added
# automatically by the schema analyzer.
#
CREATE TABLE tx_apiexport_domain_model_feed (
    client int(11) unsigned DEFAULT 0 NOT NULL,
    resource varchar(64) DEFAULT '' NOT NULL,
    storage_pages text,
    recursive int(11) unsigned DEFAULT 0 NOT NULL,
    categories text,
    category_conjunction varchar(6) DEFAULT 'or' NOT NULL,
    category_include_subcategories tinyint(1) unsigned DEFAULT 0 NOT NULL,
    max_items int(11) unsigned DEFAULT 0 NOT NULL,
    settings mediumtext,
    detail_pid int(11) unsigned DEFAULT 0 NOT NULL,
    detail_map int(11) unsigned DEFAULT 0 NOT NULL,

    KEY client (client, resource)
);

#
# Detail pages per folder (inline children of a grant):
# storage_pid -> detail_pid. Determines which detail page items from a
# specific storage folder link to. uid/pid/tstamp/crdate/deleted/hidden/
# sorting are added automatically by the schema analyzer.
#
CREATE TABLE tx_apiexport_domain_model_detailmap (
    feed int(11) unsigned DEFAULT 0 NOT NULL,
    storage_pid int(11) unsigned DEFAULT 0 NOT NULL,
    detail_pid int(11) unsigned DEFAULT 0 NOT NULL,

    KEY feed (feed)
);
