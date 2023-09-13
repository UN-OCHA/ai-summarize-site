# OCHA AI Summarization

## TL;DR

Uses AI to summarize PDF files

## Flow

1. User creates a new *Summary* node providing a title and a PDF file
2. A queue item is created to extract the text
3. `drush queue:process ocha_ai_summarize_extract_text`
4. The node is updated and the extracted text is added
5. A queue item is created to summarize the text
6. `drush queue:process ocha_ai_summarize_summarize`
7. The node is updated and the summary is added
8. User can proof-read and publish the node
