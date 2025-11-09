# Media-Alt-Text

A WordPress plugin that scans your media library for images without alternative text, generates descriptive text with OpenAI vision models, and saves the generated description as the image’s alt attribute.

## Installation

1. Copy the `media-alt-text-enhancer` directory into your WordPress installation under `wp-content/plugins/`.
2. Log in to the WordPress admin area and activate **Media Alt Text Enhancer** from the Plugins screen.

## Configuration

1. Navigate to **Media → Alt Text Enhancer**.
2. Enter your OpenAI API key and choose a vision-capable model (the default is `gpt-4o-mini`).
3. Optionally adjust the language code used for generated alt text.
4. Save your settings.

## Usage

After saving your API credentials, press **Generate Alt Text Now** on the plugin page. The plugin will request captions for every image in the Media Library that has an empty alt attribute and update the alt text automatically.

Any issues encountered during generation (for example, network errors or invalid API responses) are displayed once the process completes.

## Rate limits & retries

To avoid exceeding OpenAI rate limits, the plugin pauses for 1.5 seconds before each request by default and retries failed calls that return HTTP 429 or transient 5xx errors using exponential backoff with jitter. You can fine-tune this behavior without modifying the plugin by hooking into these filters:

* `mate_rate_limit_ms` (default `1500`) — adjust the delay, in milliseconds, before each OpenAI request.
* `mate_max_retries` (default `6`) — change how many times a failed request should be retried before surfacing an error.

## Releases

Create a new release by tagging the desired commit and pushing the tag:

```bash
git tag vX.Y.Z
git push --tags
```
