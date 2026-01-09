<?php

declare(strict_types=1);

namespace SharpAPI\ContentTranslate;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use SharpAPI\Core\Client\SharpApiClient;

/**
 * @api
 */
class ContentTranslateService extends SharpApiClient
{
    /**
     * Initializes a new instance of the class.
     *
     * @throws InvalidArgumentException if the API key is empty.
     */
    public function __construct()
    {
        parent::__construct(config('sharpapi-content-translate.api_key'));
        $this->setApiBaseUrl(
            config(
                'sharpapi-content-translate.base_url',
                'https://sharpapi.com/api/v1'
            )
        );
        $this->setApiJobStatusPollingInterval(
            (int) config(
                'sharpapi-content-translate.api_job_status_polling_interval',
                5)
        );
        $this->setApiJobStatusPollingWait(
            (int) config(
                'sharpapi-content-translate.api_job_status_polling_wait',
                180)
        );
        $this->setUserAgent('SharpAPILaravelContentTranslate/1.0.0');
    }

    /**
     * Translates text to the specified language.
     * You can set your preferred writing style by providing an optional voice_tone parameter.
     * Additional context can be provided to improve translation quality.
     *
     * @param string $text The text to translate
     * @param string $language The target language for translation
     * @param string|null $voiceTone The tone of voice for the translation (optional)
     * @param string|null $context Additional context for better translation (optional)
     * @return string The translated text or an error message
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function translate(
        string $text,
        string $language,
        ?string $voiceTone = null,
        ?string $context = null
    ): string {
        $response = $this->makeRequest(
            'POST',
            '/content/translate',
            [
                'content' => $text,
                'language' => $language,
                'voice_tone' => $voiceTone,
                'context' => $context,
            ]
        );

        return $this->parseStatusUrl($response);
    }
}