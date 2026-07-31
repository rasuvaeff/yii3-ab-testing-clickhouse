ALTER TABLE {{conversions_table}}
MODIFY TTL ts + INTERVAL {{retention_days}} DAY
