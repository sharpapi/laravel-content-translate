---
name: sharpapi-content-translate
description: Translate text into another language with SharpAPI via `SharpAPI\ContentTranslate\ContentTranslateService` (sharpapi/laravel-content-translate). Use when translating or localizing content, filling multilingual/translatable model fields, or touching `translate()`, `fetchResults()` or `config/sharpapi-content-translate.php`.
---

# SharpAPI Content Translate

`sharpapi/laravel-content-translate` wraps one SharpAPI endpoint (`POST /content/translate`) to translate text into another language. The work is async: `translate()` submits a job and returns a status URL, then `fetchResults()` polls until the job finishes.

## When to use this skill

- Translating strings, posts or model fields (e.g. spatie/laravel-translatable columns) into one or more languages.
- Building an artisan command, Nova action or queued job that localizes content.
- Debugging empty translations, timeouts or quota burn from `translate()` / `fetchResults()`.

## Install / wiring checklist

- `composer require sharpapi/laravel-content-translate`. It pulls in `sharpapi/php-core`; this skill assumes php-core ≥ 1.4.1.
- `.env`: `SHARP_API_KEY=...` is required. If it is missing, constructing the service throws `InvalidArgumentException`.
- Optional env keys, shared by every SharpAPI wrapper:
  - `SHARP_API_BASE_URL` (default `https://sharpapi.com/api/v1`)
  - `SHARP_API_JOB_STATUS_POLLING_WAIT` (default `180`): the maximum seconds `fetchResults()` keeps polling.
  - `SHARP_API_JOB_STATUS_POLLING_INTERVAL` (default `10`): seconds between polls when the API sends no `Retry-After`.
  - `SHARP_API_JOB_STATUS_USE_POLLING_INTERVAL` (default `false`): when `true`, the fixed interval above replaces the server's `Retry-After`.
- The config file is optional. To publish it: `php artisan vendor:publish --tag=sharpapi-content-translate` (creates `config/sharpapi-content-translate.php`).
- The service provider is auto-discovered. There is **no facade and no container binding**. Type-hint `ContentTranslateService` (the container builds it) or call `new ContentTranslateService()`. The constructor takes no arguments and reads the config.

## API & config reference

```php
use SharpAPI\ContentTranslate\ContentTranslateService;

public function translate(
    string $text,
    string $language,
    ?string $voiceTone = null,
    ?string $context = null
): string
```

- `$text` — the content to translate. Plain text, Markdown or HTML; ask for the format to be preserved via `$context`.
- `$language` — **required** target language as a full English name (`"Spanish"`, `"German"`, `"Simplified Chinese"`), not an ISO code. The source language is auto-detected.
- `$voiceTone` — optional free-text tone (`"neutral"`, `"formal"`, `"friendly"`).
- `$context` — optional free-text instructions, e.g. `"Source language is English. Keep the Markdown/HTML formatting."`

**Returns the status URL (a string), not the result.** Pass it to the inherited `fetchResults(string $statusUrl): SharpAPI\Core\DTO\SharpApiJob`, which blocks while it polls.

`SharpApiJob` has the public properties `id`, `type` (`"content_translate"`), `status` (a string: `"success"` or `"failed"`) and `result` (`?stdClass`). It also has `getResultJson()`, `getResultArray()` (shallow), `getResultObject()` and `toArray()`.

Example `result` on success (shape from the SharpAPI response template; the values are illustrative):

```json
{
    "content": "¿Hola, cómo estás hoy?",
    "from_language": "English",
    "to_language": "Spanish",
    "output_format": "text",
    "output_html_css": null
}
```

`content` is the translated text (`$job->result->content`, same object as `$job->getResultObject()->content`). `from_language` is the detected source language.

Exceptions:
- `SharpAPI\Core\Exceptions\ApiException`: polling ran past `SHARP_API_JOB_STATUS_POLLING_WAIT`, or HTTP 429 retries ran out.
- `GuzzleHttp\Exception\ClientException` (4xx, e.g. 401 bad key, 422 validation) and other `GuzzleHttp\Exception\GuzzleException`s for transport or 5xx errors.

## Recipes

### Queued job (the default pattern)

```php
namespace App\Jobs;

use App\Models\Post;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable; // Laravel 10: Dispatchable, InteractsWithQueue, Queueable, SerializesModels
use Illuminate\Support\Facades\Log;
use SharpAPI\Core\Enums\SharpApiJobStatusEnum;
use SharpAPI\Core\Exceptions\ApiException;
use SharpAPI\ContentTranslate\ContentTranslateService;

class TranslatePost implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240; // must exceed SHARP_API_JOB_STATUS_POLLING_WAIT (180)

    public int $tries = 1;     // every retry re-submits the text and is billed again

    public function __construct(public Post $post, public string $locale, public string $language) {}

    public function handle(ContentTranslateService $service): void
    {
        try {
            $statusUrl = $service->translate($this->post->body, $this->language, null, 'Source language is English. Keep the Markdown/HTML formatting.');
            $job = $service->fetchResults($statusUrl); // blocks and polls; no loop needed
        } catch (ApiException|GuzzleException $e) {
            Log::warning('SharpAPI translate failed: '.$e->getMessage());

            return;
        }

        if ($job->status !== SharpApiJobStatusEnum::SUCCESS->value) {
            Log::warning('SharpAPI translate job did not succeed', $job->toArray());

            return;
        }

        $translated = $job->result->content ?? null;
        if ($translated !== null) {
            $this->post->setTranslation('body', $this->locale, $translated)->save(); // spatie/laravel-translatable
        }
    }
}
```

Resolve the service in `handle()`, as above, and never store it on a job property. It holds a Guzzle client, which cannot be serialized onto the queue.

### Many languages from an artisan command or a queued Nova action

Commands have no HTTP timeout, so calling `fetchResults()` inline is fine there. Submit one call per target language, reuse one service instance, and let a failure skip that language instead of aborting the loop:

```php
foreach (['Spanish', 'German', 'Simplified Chinese'] as $language) {
    try {
        $job = $service->fetchResults($service->translate(
            $text,
            $language,
            null,
            'Translating from English, keep the original Markdown/HTML format'
        ));
        $translations[$language] = $job->status === SharpApiJobStatusEnum::SUCCESS->value
            ? ($job->result->content ?? null)
            : null;
    } catch (GuzzleException|ApiException $e) {
        Log::warning("Translate to {$language} failed: {$e->getMessage()}");
    }
}
```

Each language is a separate billed job. Skip languages that already have a translation unless the user forces a re-translate.

## Gotchas

php-core is a transitive dependency, so these rules are repeated here:

1. **`fetchResults()` already polls.** It sleeps between polls (honouring `Retry-After` and rate-limit headers) until the job succeeds, fails or `SHARP_API_JOB_STATUS_POLLING_WAIT` runs out. Never write your own `while ($status === 'pending')` loop, and never call `translate()` again to "retry": each call is a new billed job.
2. **A failed job does not throw.** Always compare `$job->status` with `SharpApiJobStatusEnum::SUCCESS->value` (`SharpAPI\Core\Enums\SharpApiJobStatusEnum`). On failure `result` can be an empty `stdClass`, so reading `$job->result->field` without `?? null` raises an "Undefined property" `ErrorException` in Laravel.
3. **Never call `fetchResults()` inside an HTTP request.** It can block for up to 180 s. Use a queued job whose `$timeout` exceeds the polling wait, keep `$tries` low, and make the worker/Horizon supervisor `timeout` at least the job timeout, with the queue connection's `retry_after` above it. Artisan commands are fine to run inline.
4. **For arrays, decode the JSON:** `json_decode($job->getResultJson(), true)`. `getResultArray()` only converts the top level, so nested objects stay `stdClass`, and list results arrive as objects with numeric keys.
5. **Language and voice tone are free text.** Pass full English names (`"English"`, `"Spanish"`, `"Simplified Chinese"`), not ISO codes (`"es"`, `"zh"`). Map your locale enum to a label before calling.

## Testing

- Mock the service. It must reach your code through the container (constructor/`handle()` injection or `app(ContentTranslateService::class)`); `new ContentTranslateService()` bypasses the mock.

```php
use SharpAPI\Core\DTO\SharpApiJob;
use SharpAPI\ContentTranslate\ContentTranslateService;

$this->mock(ContentTranslateService::class, function ($mock) {
    $mock->shouldReceive('translate')->once()->andReturn('https://sharpapi.com/api/v1/job/status/fake-id');
    $mock->shouldReceive('fetchResults')->once()->andReturn(new SharpApiJob(
        id: 'fake-id',
        type: 'content_translate',
        status: 'success',
        result: (object) ['content' => 'Hola', 'from_language' => 'English', 'to_language' => 'Spanish'],
    ));
});
```

- Test the failure path too: return `status: 'failed'` with `result: new \stdClass`.
- `Http::fake()` does **not** intercept these calls, because php-core sends them through its own Guzzle client. Mock the service instead. Without a mock, a test with no `SHARP_API_KEY` throws `InvalidArgumentException` as soon as the service is built.
