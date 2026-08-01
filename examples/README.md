# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | The schema contract a producer must match, and the query shape a reader must use | No |

This package owns the analytics schema and reads from it — it does not write.
Events arrive through the durable outbox exporter (`yii3-ab-testing-outbox`) or
a log-shipping collector reading the core's logger sinks. The direct writer was
removed in 2.0: under PHP-FPM it issued a synchronous insert of a handful of
rows per request, inside the user's latency.

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```
