# Integration examples

Use a restricted API key and environment variables `EIPSI_API_BASE`, `EIPSI_API_KEY`; set them using your secret manager or a private interactive prompt. Never put a credential in URLs, source, shell history, screenshots, shared notebooks, `set -x`, curl `-v` or logs. Do not print request objects in R/Python. Loopback HTTP examples require WordPress explicitly configured local/development; production uses HTTPS.

## curl

[Executable wrapper](../../examples/curl/eipsi_api.sh) feeds Authorization through stdin configuration, not process arguments. Export EIPSI_API_BASE and EIPSI_API_KEY privately first. Examples use pretty permalink base; query-style WordPress uses `&` for parameters after `rest_route`.

```sh
examples/curl/eipsi_api.sh /status
examples/curl/eipsi_api.sh '/studies?limit=2'
examples/curl/eipsi_api.sh '/studies/YOUR_CODE'
examples/curl/eipsi_api.sh '/studies/YOUR_CODE/data?limit=2'
examples/curl/eipsi_api.sh '/studies/YOUR_CODE/data?format=csv' --output live.csv
examples/curl/eipsi_api.sh '/studies/YOUR_CODE/dictionary'
examples/curl/eipsi_api.sh '/studies/YOUR_CODE/exports' --data '{"format":"json","include_partials":false}'
# Copy only the opaque export_id into subsequent paths. Poll per Retry-After.
examples/curl/eipsi_api.sh '/exports/EXPORT_ID' --dump-header poll.headers
examples/curl/eipsi_api.sh '/exports/EXPORT_ID/manifest' --output manifest.json
examples/curl/eipsi_api.sh '/exports/EXPORT_ID/data?limit=2'
examples/curl/eipsi_api.sh '/exports/EXPORT_ID/dictionary'
examples/curl/eipsi_api.sh '/exports/EXPORT_ID/download' --output snapshot.ndjson --dump-header file.headers
# For a CSV download create format=csv, then use its new export_id.
examples/curl/eipsi_api.sh '/exports/EXPORT_ID/download' --range 0-127 --output first.part
# Replace ETAG below with the exact quoted strong ETag from file.headers:
examples/curl/eipsi_api.sh '/exports/EXPORT_ID/download' --range 128- --header 'If-Range: "ETAG"' --output rest.part --dump-header resume.headers
```

Only concatenate after verifying 206, expected Content-Range and matching ETag; a 200 replaces the partial. Verify the full SHA-256 against manifest/ETag. The human helper performs this comparison automatically. Cursors must be URL encoded; the Python/R examples handle it. On errors inspect HTTP status/code/request_id, respect Retry-After, and stop on revoked/expired/failed snapshots. Do not assume an ambiguous timed-out POST failed: check the administrative monitor before creating another export.

## Python

[Stdlib client](../../examples/python/eipsi_api.py), no dependency installation. `python3 examples/python/eipsi_api.py --study YOUR_CODE --output new-file.ndjson` checks status/list/detail, paginates live data, reads dictionary, creates/polls a JSON export and streams NDJSON to a new file. Validates length and ETag; retains an incomplete `.part` without presenting it as complete. No redirects with Bearer. CLI prints counts and export ID only. Errors omit body/credential. Bounded polling and network timeouts; no automatic retry of export creation. `Client.pages('/exports/'+id+'/data')` also walks snapshot JSON pages. For reproducible analyses prefer snapshot data/dictionary over the live example's independent requests.

## R

[httr2 example](../../examples/R/eipsi_api.R). Install httr2 through your normal reviewed R environment, then source and call `run_example('YOUR_CODE', tempdir())`. Shows status, Studies, pagination, list-column data.frame, historical dictionary, live CSV and snapshot CSV. List columns preserve array/null values rather than imposing an unapproved wide schema. No scientific reshaping is part of the API contract. File names must not already exist. HTTP errors print status only; no request logging/redirects. Large downloads need sufficient disk. The R example does not itself verify SHA-256; use the manifest and your local checksum tool. Tooling availability and actual execution evidence are recorded in the API-4 report; absence of R is not reported as an R execution PASS.

## Canonical wide integration (DATA-1)

Use the V2 negotiation and filters in [Canonical Dataset and Metadata Policy](../dataset/Canonical%20Dataset%20and%20Metadata%20Policy.md). Python supports `--format json|csv|xlsx`; choose a matching new output filename. R supports `run_example(study, directory, format='json')`, with `csv` or `xlsx` alternatives. JSON/XLSX include dictionary, response status, bindings, process metadata and randomization; CSV contains main data. In Python, parse the downloaded JSON with `json.load` and inspect `data`, `dictionary`, `process_metadata`; in R use `jsonlite::fromJSON(path, simplifyVector=FALSE)` if desired. The download mechanism keeps the representation bytes intact and does not flatten multiple-choice arrays.
