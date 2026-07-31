ALTER TABLE {{exposures_table}}
MODIFY TTL ts + INTERVAL {{retention_days}} DAY
