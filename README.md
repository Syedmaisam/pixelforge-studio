# Pixelforge Image Studio

A Laravel 13 + Vue 3 + Inertia 3 app where signed-in users generate images through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API and pay Pixelforge's cost **plus 25%**.

- **Generate** with a prompt, size, quality and up to 4 reference image URLs. The price range is shown **before** you submit.
- **History** with live status (Sending → Queued → Processing → Succeeded / Failed), the estimate, the exact amount charged, and the image ("Image expired" once Pixelforge's link runs out).
- **Exact billing**: the user pays the _actual_ cost + 25% once a generation finishes. **Failed generations are free.**
- **Webhooks** are signature-checked and safe to receive twice or out of order. An event delivered twice is never recorded or charged twice.
- **Credits**: every user has a balance (credits = US dollars), starts with $2.00, can "buy" $5 / $10 / $25 (demo, no payment), and can't start a generation they can't afford.
- **Admin report**: per UTC day and per user, what users were charged, what Pixelforge charged us, and the profit. Each day is checked against Pixelforge's `GET /v1/usage`, to the nanodollar.

## How the money works

All money is stored as **integer nanodollars** (1 USD = 1,000,000,000), the same 9 decimals Pixelforge uses. No floats anywhere.

| Moment                                  | What happens to the balance                                                                        |
| --------------------------------------- | -------------------------------------------------------------------------------------------------- |
| You press Generate                      | We **hold** the worst-case price (the top of the estimate range).                                  |
| Pixelforge succeeds                     | We **release** the hold and **charge** the actual cost + 25%, rounded _up_ to the next nanodollar. |
| Pixelforge fails, or rejects the prompt | We **release** the hold. You pay nothing.                                                          |

Every change is one row in `credit_transactions`, so a balance can always be explained line by line.
The database allows at most one hold, one release and one charge per generation.

Worked example (high, 1536×1024, 400-character prompt, 2 reference images):

|                                                             |                Nanodollars |            Dollars |
| ----------------------------------------------------------- | -------------------------: | -----------------: |
| Estimate (user price)                                       | 357,781,250 to 467,343,750 | $0.3578 to $0.4673 |
| Actual Pixelforge cost (103 / 1,530 / 6,450 / 5,000 tokens) |                336,315,000 |       $0.336315000 |
| Charged to the user (+25%)                                  |                420,393,750 |       $0.420393750 |
| Profit                                                      |                 84,078,750 |       $0.084078750 |

## Running it locally

Requirements: PHP 8.3+, Composer, Node 22+. SQLite is the default database.

```bash
composer run setup          # install, create .env, generate key, migrate, build assets
```

Then set the Pixelforge values in `.env` (use a **separate API key per environment**):

```dotenv
QUEUE_CONNECTION=database
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=pf_...
PIXELFORGE_WEBHOOK_SECRET=whsec_...   # from the Pixelforge Webhooks page, for the same key
```

Start everything:

```bash
composer run dev            # web server, queue worker, logs and Vite
php artisan schedule:work   # in a second terminal: runs pixelforge:sync every minute
```

Open <http://localhost:8000>, register, and start generating. New users get $2.00 of credit.

To open the admin report at `/admin/report`, make yourself an admin:

```bash
php artisan app:make-admin you@example.com
```

### Receiving webhooks locally

Pixelforge has to reach your machine, so use a tunnel:

```bash
ngrok http 8000
```

On the Pixelforge Webhooks page, set the URL for your **local** key to `https://<your-tunnel>/webhooks/pixelforge`,
copy its signing secret into `PIXELFORGE_WEBHOOK_SECRET`, and send a test event (expect `204`).
Without webhooks the app still finishes generations: `pixelforge:sync` polls any generation that has been quiet for 2 minutes.

### Pixelforge test keywords

Put these anywhere in a prompt: `[blocked]` (rejected at submit, free), `[overloaded]` (503 at submit, retried),
`[fail]` (accepted, then fails, free), `[succeed]` (never fails randomly), `[slow]` (60–90 s), `[duplicate]` (every webhook sent twice).

## Tests

```bash
php artisan test            # 102 tests, about 4 seconds; never calls the real Pixelforge API
composer test               # also runs Pint and PHPStan (level 7)
composer ci:check           # everything: frontend lint/format, vue-tsc, Pint, PHPStan and the tests
```

The money tests use the worked example above and the examples from the Pixelforge docs, with a saved copy of
`GET /v1/pricing` in `tests/Fixtures/pricing.json`. Webhook tests send correctly signed requests, including duplicates,
out-of-order events, a webhook that arrives before our own POST returns, and bad signatures.

## Design decisions

| #   | Decision                                                                                                                   | Why                                                                                    |
| --- | -------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| D1  | Money is integer **nanodollars** (BIGINT, columns end in `_nanos`).                                                        | Maps 1:1 to Pixelforge's 9 decimals; integers never drift.                             |
| D2  | One `Money` class parses decimal **strings** and formats output. No money library.                                         | Few moving parts to explain.                                                           |
| D3  | Rates become nanodollars per token (`"12.50"` → 12,500); more than 3 decimals throws.                                      | Cost is integer multiplication; precision loss is loud.                                |
| D4  | Actual cost uses the generation's **own** `rates`, never live pricing.                                                     | Pixelforge freezes rates at creation.                                                  |
| D5  | User price = `ceil(cost × 125 / 100)` in integer maths.                                                                    | 25% can need 11 decimals; rounding up by at most 1 nanodollar never undercharges.      |
| D6  | The estimate is a **range**; the max is the ceiling we hold.                                                               | Text and reasoning tokens are only known at completion.                                |
| D7  | The local row is saved **before** calling Pixelforge; its UUID is the `Idempotency-Key` and `metadata.local_id`.           | Retries never duplicate; early webhooks can find the row.                              |
| D8  | Submitting happens in a **queued job** that honours `Retry-After`, with backoff 2/5/10/20/30 s, max 6 attempts.            | The page stays fast; 429/503 are normal.                                               |
| D9  | Non-retryable errors (blocked, invalid, daily limit, bad key) fail immediately.                                            | Retrying can't fix them.                                                               |
| D10 | Statuses `pending → queued → processing → succeeded / failed` only move **forward**; final never changes.                  | Out-of-order events are handled by one comparison.                                     |
| D11 | **One** method, `GenerationStateUpdater::apply`, changes status or money. Webhook, job and poller all call it.             | One place to read and test.                                                            |
| D12 | A webhook is handled in **one DB transaction**: record the event id, then apply. An error rolls both back and returns 500. | "Recorded" and "applied" can never disagree; Pixelforge's retry is processed normally. |
| D13 | Double charging is blocked twice: unique event id **and** unique `(generation_id, type)` on the ledger.                    | The database itself refuses a second charge.                                           |
| D14 | Balances use a **hold**: hold max, then release + charge actual (or just release on failure).                              | "Can't afford it" stays correct with concurrent clicks.                                |
| D15 | `pixelforge:sync` runs every minute and polls generations quiet for 2+ minutes (max 20 per run).                           | Webhook delivery isn't guaranteed.                                                     |
| D16 | If our job gave up but Pixelforge later succeeds: record the provider cost, charge the user $0, keep it failed.            | The user was told it failed; the report must still match `/v1/usage`.                  |
| D17 | Provider cost is grouped by Pixelforge's `completed_at`, in **UTC**.                                                       | That is how `/v1/usage` bills.                                                         |
| D18 | New users get a **$2.00 welcome credit** as a ledger entry.                                                                | Reviewers can try it immediately.                                                      |
| D19 | The page polls our own server every 3 s (Inertia `usePoll`, partial reload) only while something is running.               | Simple, no extra infrastructure.                                                       |
| D20 | Images aren't downloaded; the card says "Image expired" after `image_url_expires_at`.                                      | Allowed by the brief.                                                                  |

## Where things live

| File                                                   | Job                                                                      |
| ------------------------------------------------------ | ------------------------------------------------------------------------ |
| `app/Support/Money.php`                                | Parse and format money, strings and integers only.                       |
| `app/Pricing/CostCalculator.php`                       | Provider cost, user price (+25%), and the estimate range.                |
| `app/Pixelforge/`                                      | HTTP client, error handling, cached price list, webhook signature check. |
| `app/Actions/StartGeneration.php`                      | Estimate, balance check, save the row, hold credits, queue the job.      |
| `app/Jobs/SubmitGenerationToPixelforge.php`            | Submit with retries and the idempotency key.                             |
| `app/Services/GenerationStateUpdater.php`              | The only code that changes a generation's status or money.               |
| `app/Services/CreditLedger.php`                        | The only code that changes a balance.                                    |
| `app/Http/Controllers/PixelforgeWebhookController.php` | Verify, de-duplicate and apply webhooks.                                 |
| `app/Console/Commands/SyncActiveGenerations.php`       | Polling fallback for missed webhooks.                                    |
| `app/Reports/ProfitReport.php`                         | Admin report and the `/v1/usage` comparison.                             |
| `resources/js/pages/generations/Index.vue`             | The studio page; `components/generations/` holds the form and cards.     |

## Known trade-offs

- **Max 6 submit attempts.** Pixelforge allows 3 generations in progress per account. If 3 `[slow]` generations are running, a 4th can use up its ~70 s of retries and fail ("too busy", free). Retrying for a fixed time instead of a fixed count would fix it.
- **The charge isn't capped at the estimate's max.** If Pixelforge ever reported more tokens than its published ranges, we'd charge the real cost + 25%. Pixelforge says this can't happen; capping would make "never more than" strictly true at the cost of slightly breaking "+25%".
- **The balance is shown to 2 decimals** (rounded half up) in the header. The ledger and every charge are exact; the card tooltip shows the exact charge.
- **Webhooks are processed during the request**, not on the queue. It's a few small queries, well inside Pixelforge's 10 s limit, and it keeps "recorded" and "applied" in one transaction.
- **A generation waiting to be sent relies on the queue.** If the queue worker is down, it stays "Sending" with its credit held until the worker runs again; the poller only checks generations Pixelforge has accepted.
- **No pricing, no new generations.** If Pixelforge's price list can't be loaded and we've never cached one (for example, the very first request after a deploy while Pixelforge is down), generating pauses with a clear message. After one successful load, the last good copy is used.
- **Email verification isn't required** for the studio, so reviewers can use it without a mail server.
- **Images aren't stored**, so old images disappear when Pixelforge's links expire.

## Deploying to Laravel Cloud

1. Push to a public GitHub repo and create a Cloud app with a Postgres or MySQL database.
2. Use a **separate production Pixelforge API key** and set the environment variables above.
3. Enable the **Scheduler**, add a **queue worker** on the database connection, and turn **hibernation off** (webhooks need a reply within 10 s).
4. Include `php artisan migrate --force` in the deploy commands.
5. Set the production key's webhook URL to `https://<app>.laravel.cloud/webhooks/pixelforge` and send a test event (expect `204`).
6. Create a reviewer account and run `php artisan app:make-admin <email>`.
