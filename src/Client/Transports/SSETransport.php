<?php

namespace OpenFunctions\Tools\MCP\Client\Transports;

use OpenFunctions\Tools\MCP\Client\Contracts\TransportInterface;
use Exception;
use GuzzleHttp\Client;
use Psr\Http\Message\StreamInterface;

/**
 * A Guzzle‑based implementation of the SSE client transport.
 *
 * This implementation opens a GET request with streaming enabled so that the connection
 * stays open. It then reads from the stream until the required SSE event is detected.
 */
class SSETransport implements TransportInterface
{
    private string $url;
    private ?string $endpoint = null;
    private Client $client;
    private ?StreamInterface $stream = null;
    // Add a messageId property similar to WSTransport.
    private int $messageId = 0;

    public function __construct(string $url, array $options = []) {
        $this->url = $url;
        $this->client = new Client();
        $this->start();
    }

    private function start(): void {
        $headers = [
            'Accept' => 'text/event-stream'
        ];

        $response = $this->client->request('GET', $this->url, [
            'stream'  => true,
            'timeout' => 0, // no timeout (long-lived connection)
            'headers' => $headers,
        ]);
        $this->stream = $response->getBody();

        $buffer = '';
        $timeout = 30; // seconds
        $startTime = microtime(true);

        while (true) {
            if ((microtime(true) - $startTime) > $timeout) {
                throw new Exception("Timeout waiting for 'endpoint' event");
            }
            $chunk = $this->stream->read(1024);
            if ($chunk === '' || $chunk === false) {
                usleep(100000); // 100ms delay
                continue;
            }
            $buffer .= $chunk;

            if (str_contains($buffer, "\n\n")) {
                $parts = explode("\n\n", $buffer, 2);
                $eventBlock = $parts[0];
                $buffer = $parts[1] ?? '';
                $event = $this->parseSSEEvent($eventBlock);
                if ($event && $event['event'] === 'endpoint') {
                    $this->endpoint = trim($event['data']);
                    break;
                }
            }
            usleep(100000);
        }
    }

    private function parseSSEEvent(string $block): ?array {
        $lines = explode("\n", $block);
        $eventName = null;
        $data = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (stripos($line, 'event:') === 0) {
                $eventName = trim(substr($line, strlen('event:')));
            } elseif (stripos($line, 'data:') === 0) {
                $data .= trim(substr($line, strlen('data:')));
            }
        }
        if ($eventName !== null) {
            return ['event' => $eventName, 'data' => $data];
        }
        return null;
    }

    public function close(): void {
        if ($this->stream) {
            $this->stream->close();
            $this->stream = null;
        }
    }

    public function sendMessage(string $method, array $params = []): array {
        if (!$this->endpoint) {
            throw new Exception("Not connected: endpoint not set");
        }

        // Increment message ID and build the command.
        $this->messageId++;
        $message = [
            'jsonrpc' => '2.0',
            'id'      => $this->messageId,
            'method'  => $method,
        ];
        if (!empty($params)) {
            $message['params'] = $params;
        }

        $headers = [
            'Content-Type' => 'application/json'
        ];

        $response = $this->client->request('POST', $this->endpoint, [
            'headers' => $headers,
            'json'    => $message,
        ]);

        if ($response->getStatusCode() >= 300) {
            $body = $response->getBody()->getContents();
            throw new Exception("Error POSTing to endpoint (HTTP " . $response->getStatusCode() . "): " . $body);
        }

        // Wait for and return the response event matching the message id.
        return $this->waitForResponse($message['id']);
    }

    public function sendMessageWithoutResponse(string $method, array $params = []): void {
        if (!$this->endpoint) {
            throw new Exception("Not connected: endpoint not set");
        }

        // Increment message ID and build the command.
        $this->messageId++;
        $message = [
            'jsonrpc' => '2.0',
            'id'      => $this->messageId,
            'method'  => $method,
        ];
        if (!empty($params)) {
            $message['params'] = $params;
        }

        $headers = [
            'Content-Type' => 'application/json'
        ];

        $response = $this->client->request('POST', $this->endpoint, [
            'headers' => $headers,
            'json'    => $message,
        ]);

        if ($response->getStatusCode() >=  300) {
            $body = $response->getBody()->getContents();
            throw new Exception("Error POSTing to endpoint (HTTP " . $response->getStatusCode() . "): " . $body);
        }
    }

    public function waitForResponse(int $commandId, float $timeout = 30.0): array {
        if (!$this->stream) {
            throw new Exception("Stream is not available");
        }

        $buffer = '';
        $startTime = microtime(true);
        $readLength = 128;
        while (true) {
            if ((microtime(true) - $startTime) > $timeout) {
                throw new Exception("Timeout waiting for response with id: {$commandId}");
            }

            $chunk = $this->stream->read($readLength);
            $readLength = 1024;

            if ($chunk === '' || $chunk === false) {
                usleep(10000);
                continue;
            }
            $buffer .= $chunk;

            if (str_contains($buffer, "\n\n")) {
                $parts = explode("\n\n", $buffer, 2);
                $eventBlock = $parts[0];
                $buffer = $parts[1] ?? '';
                $event = $this->parseSSEEvent($eventBlock);
                if ($event) {
                    $data = json_decode($event['data'], true);
                    if (isset($data['id']) && $data['id'] === $commandId) {
                        return $data;
                    }
                }
            }
            usleep(10000);
        }
    }
}