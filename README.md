# Last-Mile Dispatch Planner

Backend service for ingesting orders, planning dispatch batches, exporting batch CSVs to S3 on LocalStack, and recomputing plans when forecasts change.

## Summary

This project implements the take-home assignment as a Laravel service with:

- JSON batch ingestion
- S3 CSV ingestion through LocalStack
- scheduled dispatch planning at `06:00` IST
- holiday-aware and weather-aware dispatch dates
- COD currency conversion into destination-local currency
- grouping into dispatch runs by city and dispatch date
- JSON batch retrieval and CSV export

Supported destination countries:

- `IN`
- `DE`

## Stack

- PHP 8.4
- Laravel 13
- MySQL 8
- LocalStack S3
- Terraform
- Docker Compose

## Project Structure

- [app/Http/Controllers/DispatchController.php](app/Http/Controllers/DispatchController.php:1): API endpoints
- [app/Services/DispatchService.php](app/Services/DispatchService.php:1): dispatch planning logic
- [app/Services/CsvOrderImportService.php](app/Services/CsvOrderImportService.php:1): S3 CSV ingestion
- [app/Services/GeocodingService.php](app/Services/GeocodingService.php:1): address-to-location lookup
- [app/Console/Kernel.php](app/Console/Kernel.php:1): scheduled jobs
- [infra/main.tf](infra/main.tf:1): LocalStack S3 infrastructure
- [tests/Feature/DispatchTest.php](tests/Feature/DispatchTest.php:1): feature coverage

## Setup

### Prerequisites

- Docker
- Docker Compose
- AWS CLI optional, only if you want to inspect LocalStack manually

### Start everything

```bash
cp .env.example .env
docker compose up --build
```

Services:

- App: `http://localhost:8000`
- MySQL: `localhost:3307`
- LocalStack: `http://localhost:4566`

Terraform is run automatically by the `terraform` service in `docker-compose.yml`.

### Verify infrastructure

Check that the S3 bucket exists:

```bash
aws --endpoint-url=http://localhost:4566 s3 ls
```

Expected bucket:

- `dispatch-bucket`

## Environment

Important defaults from [.env.example](.env.example:1):

- `DB_HOST=db`
- `DB_DATABASE=cidroy`
- `FILESYSTEM_DISK=s3`
- `AWS_BUCKET=dispatch-bucket`
- `AWS_ENDPOINT=http://localstack:4566`
- `OPEN_METEO_FORECAST_URL=https://api.open-meteo.com/v1/forecast`
- `OPEN_METEO_GEOCODING_URL=https://geocoding-api.open-meteo.com/v1/search`

## Infrastructure as Code

Terraform resources in [infra/main.tf](infra/main.tf:1) provision:

- S3 bucket `dispatch-bucket`
- bucket versioning
- public access block configuration

If you prefer to apply manually:

```bash
cd infra
terraform init
terraform apply -auto-approve
```

## API

### 1. Health

```http
GET /api/healthz
```

Example:

```bash
curl http://localhost:8000/api/healthz
```

### 2. Create dispatch batch from JSON

```http
POST /api/dispatch-batches
Content-Type: application/json
```

Example:

```bash
curl -X POST http://localhost:8000/api/dispatch-batches \
  -H "Content-Type: application/json" \
  -d '{
    "orders": [
      {
        "order_id": "O1",
        "placed_at": "2026-05-05T10:00:00+05:30",
        "destination_address": "B-203, Sunshine Apts, near Andheri station, Mumbai 400058",
        "destination_country": "IN",
        "total_value": 100,
        "total_value_currency": "USD",
        "weight_grams": 500,
        "payment_mode": "cod"
      }
    ]
  }'
```

Response:

```json
{
  "batch_id": "9f4f6e69-0f6f-44ac-b9d0-4a1d77f79f8d"
}
```

### 3. Get computed dispatch plan

```http
GET /api/dispatch-batches/{batch_id}
```

Example:

```bash
curl http://localhost:8000/api/dispatch-batches/9f4f6e69-0f6f-44ac-b9d0-4a1d77f79f8d
```

Example response:

```json
{
  "batch_id": "9f4f6e69-0f6f-44ac-b9d0-4a1d77f79f8d",
  "processed_at": "2026-05-07T00:30:00.000000Z",
  "runs": [
    {
      "run_id": 1,
      "city": "Mumbai",
      "country": "IN",
      "dispatch_date": "2026-05-06",
      "orders": [],
      "weather_summary": {
        "blocked_orders": 0,
        "max_rain_mm": 0,
        "max_snow_cm": 0,
        "max_temp_c": 30
      },
      "total_invoiced_value_local": 80,
      "total_invoiced_value_currency": "INR"
    }
  ],
  "deferred_orders": [],
  "failed_orders": []
}
```

### 4. Recompute an existing batch

```http
POST /api/dispatch-batches/{batch_id}/recompute
```

Example:

```bash
curl -X POST http://localhost:8000/api/dispatch-batches/9f4f6e69-0f6f-44ac-b9d0-4a1d77f79f8d/recompute
```

### 5. Upload CSV to S3 intake

```http
POST /api/dispatch-batches/upload-csv
Content-Type: multipart/form-data
```

Required form field:

- `file`

Expected CSV headers:

```text
order_id,placed_at,destination_address,destination_country,total_value,total_value_currency,weight_grams,payment_mode
```

Example:

```bash
curl -X POST http://localhost:8000/api/dispatch-batches/upload-csv \
  -F "file=@orders.csv"
```

Response:

```json
{
  "message": "uploaded",
  "path": "input/1715030000_orders.csv",
  "orders_ingested": 2,
  "status": "processed"
}
```

## End-to-End CSV Flow

1. Upload a CSV through `POST /api/dispatch-batches/upload-csv`.
2. The file is written to the S3 intake bucket path `input/`.
3. The S3 ingestion job reads it and creates pending orders.
4. Processed files are moved to `processed/`.
5. At `06:00` IST, the daily planner creates a dispatch batch from unbatched orders created in the previous 24 hours.
6. The final batch CSV is exported to `output/batch_{batch_id}.csv` in S3.

If you want to trigger the scheduled jobs manually inside the container:

```bash
docker compose exec app php artisan app:process-s3-orders
docker compose exec app php artisan app:process-pending-dispatches
```

## Scheduler

Two jobs are configured in [app/Console/Kernel.php](app/Console/Kernel.php:1):

1. `app:process-s3-orders`
   - runs every minute
   - imports CSV files from S3 intake
   - archives processed files

2. `app:process-pending-dispatches`
   - runs daily at `06:00` IST
   - finds unbatched orders created in the previous 24 hours
   - creates a dispatch batch and computes the plan

## Dispatch Rules Implemented

- Dispatch date is the next working day at the destination.
- Working day means weekday and not a public holiday.
- Weather blocks dispatch when:
  - rain is greater than `20mm`
  - snowfall is greater than `0`
  - max temperature is greater than `45°C`
  - max temperature is less than `-10°C`
- Blocked orders are deferred to the next viable working day.
- COD orders are converted into:
  - `INR` for India
  - `EUR` for Germany
- Orders are grouped into runs by:
  - destination city
  - dispatch date

## Design Decisions

### Why two ingestion modes

- JSON API requests are processed immediately because they already represent an explicit batch.
- S3 CSV ingestion is treated as operational intake, then planned in the daily batch window required by the assignment.

### Why polling for S3

- The assignment allows polling, event-driven, or presigned URL flows.
- For a take-home exercise, polling is simpler to reason about and easier to reproduce locally with LocalStack.

### External dependency fallback choices

- Weather API failure is treated as blocked and causes deferral.
- Holiday API failure falls back to “no holiday” for that lookup.
- FX API failure falls back to the original order value.
- Geocoding failure falls back to a country-specific default location.

## CSV Output

The exported batch CSV includes:

- `batch_id`
- `order_id`
- `country`
- `city`
- `destination_timezone`
- `dispatch_date`
- `payment_mode`
- `is_deferred`
- `invoiced_value_local`
- `invoiced_currency`
- `weather_blocked`
- `weather_rain_mm`
- `weather_snow_cm`
- `weather_temp_max`

## Testing

Run the full suite:

```bash
php artisan test
```

Current coverage includes:

- JSON batch ingestion
- grouping into a single run
- rain-based deferral
- snow-based deferral
- geocoded city and timezone persistence
- health endpoint
- idempotent S3 ingestion
- scheduled pending-order batching
- malformed CSV failure
- weather API failure fallback

## Known Limitations / Next Steps

- Address normalization is improved via geocoding, but still not a full postal-address parser for highly ambiguous addresses.
- The service uses LocalStack S3 only, which satisfies the assignment minimum but does not model a richer AWS event pipeline.
- A more exhaustive timezone-boundary regression test would strengthen confidence further.
- Production hardening could add caching, retry backoff tuning, and structured observability around external APIs.
