-- Run only against a disposable PostgreSQL database with TimescaleDB 2.17.2.
-- psql must use autocommit: transaction_per_chunk cannot run inside BEGIN.
\set ON_ERROR_STOP on

CREATE EXTENSION IF NOT EXISTS timescaledb;
CREATE SCHEMA main;
CREATE TABLE main.chat_messages (
    msg_id varchar(64) NOT NULL,
    corp_id varchar(32) NOT NULL,
    msg_type varchar(32) NOT NULL,
    msg_time timestamp NOT NULL,
    raw_content jsonb NOT NULL,
    msg_content text NOT NULL
);
SELECT create_hypertable('main.chat_messages', 'msg_time', chunk_time_interval => interval '1 month');

INSERT INTO main.chat_messages (msg_id, corp_id, msg_type, msg_time, raw_content, msg_content)
SELECT g::text, 'corp-a', 'image', timestamp '2026-07-07' + (g % 84) * interval '1 day',
       jsonb_build_object('md5sum', upper(md5(g::text))), md5(g::text)
FROM generate_series(1, 9000) AS g;
INSERT INTO main.chat_messages (msg_id, corp_id, msg_type, msg_time, raw_content, msg_content)
VALUES ('pending', 'corp-a', 'image', timestamp '2026-09-18',
        jsonb_build_object('md5sum', upper(md5('42'))), '');

DO $$
BEGIN
    IF (SELECT num_chunks FROM timescaledb_information.hypertables
        WHERE hypertable_schema = 'main' AND hypertable_name = 'chat_messages') <> 3 THEN
        RAISE EXCEPTION 'Expected three monthly chunks';
    END IF;
END;
$$;

CREATE INDEX idx_chat_messages_media_md5_lookup
ON main.chat_messages (corp_id, msg_type, lower(raw_content->>'md5sum'), msg_time DESC)
WITH (timescaledb.transaction_per_chunk)
WHERE msg_content <> '';

ANALYZE main.chat_messages;
SELECT c.relname, i.indisvalid
FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
WHERE c.relname = 'idx_chat_messages_media_md5_lookup';
SELECT count(*) AS child_index_count, bool_and(i.indisvalid) AS all_children_valid
FROM _timescaledb_catalog.chunk_index ci
JOIN pg_class c ON c.relname = ci.index_name
JOIN pg_index i ON i.indexrelid = c.oid
WHERE ci.hypertable_index_name = 'idx_chat_messages_media_md5_lookup';
DO $$
BEGIN
    IF (SELECT count(*) FROM _timescaledb_catalog.chunk_index
        WHERE hypertable_index_name = 'idx_chat_messages_media_md5_lookup') <> 3 THEN
        RAISE EXCEPTION 'Expected one index on each chunk';
    END IF;
END;
$$;
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF)
SELECT msg_id FROM main.chat_messages
WHERE corp_id = 'corp-a' AND msg_type = 'image' AND msg_content <> ''
  AND lower(raw_content->>'md5sum') = lower(md5('42'))
ORDER BY msg_time DESC LIMIT 20;

DROP INDEX main.idx_chat_messages_media_md5_lookup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_indexes
               WHERE schemaname = 'main' AND indexname = 'idx_chat_messages_media_md5_lookup') THEN
        RAISE EXCEPTION 'Root index was not removed';
    END IF;
    IF EXISTS (SELECT 1 FROM _timescaledb_catalog.chunk_index
               WHERE hypertable_index_name = 'idx_chat_messages_media_md5_lookup') THEN
        RAISE EXCEPTION 'Chunk indexes were not removed';
    END IF;
    IF (SELECT count(*) FROM main.chat_messages) <> 9001 THEN
        RAISE EXCEPTION 'Message rows changed during rollback';
    END IF;
END;
$$;
SELECT count(*) AS rows_after_rollback FROM main.chat_messages;
