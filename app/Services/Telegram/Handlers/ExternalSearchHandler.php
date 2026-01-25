<?php

namespace App\Services\Telegram\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Message;

class ExternalSearchHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $content = is_string($text) ? trim($text) : '';

        if (empty($content)) {
            return;
        }

        // Check for "قوقل <query>" pattern
        if (preg_match('/^قوقل\s+(.+)$/u', $content, $matches)) {
            $query = $matches[1];
            $this->handleGoogleSearch($message, $query);

            return;
        }

        // Check for "قيم <query>" pattern
        if (preg_match('/^قيم\s+(.+)$/u', $content, $matches)) {
            $query = $matches[1];
            $this->handleQeeemSearch($message, $query);

            return;
        }
    }

    /**
     * Handle Google search command.
     */
    protected function handleGoogleSearch(Message $message, string $query): void
    {
        $this->trackCommand($message, 'قوقل');

        // URL encode the query for Google search
        $encodedQuery = urlencode($query);
        $googleUrl = "https://www.google.com/search?q={$encodedQuery}";

        // Escape the query for display in HTML
        $escapedQuery = $this->escapeHtml($query);
        $escapedUrl = $this->escapeHtml($googleUrl);

        // Send the response with the Google search link
        $responseText = "🔍 <b>بحث Google عن:</b> {$escapedQuery}\n\n";
        $responseText .= "🔗 <a href=\"{$escapedUrl}\">افتح النتائج</a>";

        $this->replyHtml($message, $responseText);
    }

    /**
     * Handle Qeeem search command.
     */
    protected function handleQeeemSearch(Message $message, string $query): void
    {
        $this->trackCommand($message, 'قيم');

        // Escape the query for display in HTML
        $escapedQuery = $this->escapeHtml($query);

        // Try to fetch results from the API first
        $apiResults = $this->fetchQeeemApiResults($query);

        if ($apiResults !== null && count($apiResults) > 0) {
            // Show the first 3 results with direct links
            $responseText = "⭐ <b>نتائج بحث قيم عن:</b> {$escapedQuery}\n\n";

            $displayedResults = array_slice($apiResults, 0, 3);
            foreach ($displayedResults as $index => $result) {
                $doctorName = $this->escapeHtml($result['doctor_name']);
                $departmentName = $this->escapeHtml($result['department_name']);
                $doctorId = $result['doctor_id'];
                $doctorUrl = "https://qeeem.com/uqu/{$doctorId}";

                $number = $index + 1;
                $responseText .= "{$number}. <a href=\"{$doctorUrl}\">{$doctorName}</a>\n";
                $responseText .= "   📚 {$departmentName}\n\n";
            }

            // Add search link if there might be more results
            if (count($apiResults) > 3) {
                $encodedQuery = urlencode($query);
                $searchUrl = "https://qeeem.com/uqu/search?name={$encodedQuery}";
                $escapedSearchUrl = $this->escapeHtml($searchUrl);
                $responseText .= "🔗 <a href=\"{$escapedSearchUrl}\">عرض جميع النتائج</a>\n\n";
            }

            $responseText .= "⚠️ <i>ليس من الضروري أن يكون تقييم الطلبة صحيح 100%, آراء الطلبة تعكس تجارب شخصية وقد تختلف تجربتك الخاصة.</i>";

            $this->replyHtml($message, $responseText);

            return;
        }

        // Fallback to general search link if API fails or returns no results
        $this->sendQeeemFallbackResponse($message, $query, $escapedQuery);
    }

    /**
     * Fetch search results from the Qeeem API.
     *
     * @return array|null Returns array of results on success, null on failure
     */
    protected function fetchQeeemApiResults(string $query): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->get('https://qeeem.com/api/trpc/doctor.search', [
                    'batch' => 1,
                    'input' => json_encode([
                        '0' => [
                            'json' => [
                                'universitySlug' => 'uqu',
                                'name' => $query,
                            ],
                        ],
                    ]),
                ]);

            if (! $response->successful()) {
                Log::debug('Qeeem API request failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            // Parse the response structure: [{"result":{"data":{"json":[...results...]}}}]
            if (! is_array($data) || empty($data[0]['result']['data']['json'])) {
                return null;
            }

            $results = $data[0]['result']['data']['json'];

            // Filter out non-doctor entries (like "add-doctor" string)
            return array_filter($results, function ($item) {
                return is_array($item)
                    && isset($item['doctor_id'])
                    && isset($item['doctor_name'])
                    && isset($item['department_name']);
            });
        } catch (\Exception $e) {
            Log::debug('Qeeem API exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send fallback response with general search link.
     */
    protected function sendQeeemFallbackResponse(Message $message, string $query, string $escapedQuery): void
    {
        $encodedQuery = urlencode($query);
        $qeeemUrl = "https://qeeem.com/uqu/search?name={$encodedQuery}";
        $escapedUrl = $this->escapeHtml($qeeemUrl);

        $responseText = "⭐ <b>بحث قيم عن:</b> {$escapedQuery}\n\n";
        $responseText .= "🔗 <a href=\"{$escapedUrl}\">افتح النتائج</a>\n\n";
        $responseText .= "⚠️ <i>ليس من الضروري أن يكون تقييم الطلبة صحيح 100%, آراء الطلبة تعكس تجارب شخصية وقد تختلف تجربتك الخاصة.</i>";

        $this->replyHtml($message, $responseText);
    }
}
