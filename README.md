# OCHA AI Summarization

## TL;DR

Uses AI to summarize PDF files

## Settings

```php
$config['ocha_ai_summarize.settings']['openai_token'] = 'xxx';

$config['ocha_ai_summarize.settings']['azure_endpoint'] = 'https://tst003.openai.azure.com/openai/deployments/tst003/chat/completions?api-version=2023-03-15-preview';
$config['ocha_ai_summarize.settings']['azure_apikey'] = 'yyy';

$config['ocha_ai_summarize.settings']['bedrock_endpoint'] = 'https://bedrock.us-east-1.amazonaws.com/model/amazon.titan-tg1-large/invoke';
$config['ocha_ai_summarize.settings']['bedrock_model'] = 'amazon.titan-tg1-large';
$config['ocha_ai_summarize.settings']['bedrock_access_key'] = 'x1';
$config['ocha_ai_summarize.settings']['bedrock_secret_key'] = 'x2';

$config['ocha_ai_summarize.settings']['claude_endpoint'] = 'https://api.anthropic.com/v1/complete';
$config['ocha_ai_summarize.settings']['claude_version'] = '2023-06-01';
$config['ocha_ai_summarize.settings']['claude_api_key'] = 'zz';
```

## Cron

```bash
drush queue:process ocha_ai_summarize_extract_text
drush queue:process ocha_ai_summarize_summarize
```

We can either use cron to run the queues or run them separatly

## Flow

1. User creates a new *Summary* node providing a title and a PDF file and which brain to use
2. A queue item is created to extract the text
3. `drush queue:process ocha_ai_summarize_extract_text`
4. The node is updated and the extracted text is added
5. A queue item is created to summarize the text
6. `drush queue:process ocha_ai_summarize_summarize`
7. The node is updated and the summary is added
8. User can proof-read and publish the node
