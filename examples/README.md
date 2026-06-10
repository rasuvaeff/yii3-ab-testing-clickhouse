# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | `ClickHouseExposureTracker` buffering and flushing events | No (prints rows via an in-memory writer) |

The script wires an inline writer that prints rows instead of inserting them, so
it runs without a ClickHouse server. In production inject a
`Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter` built from your client and call
`flush()` once at the end of the request.

## Running

```bash
# From package root, after composer install
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```
