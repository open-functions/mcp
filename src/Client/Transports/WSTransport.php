<?php

namespace OpenFunctions\Tools\MCP\Client\Transports;

use OpenFunctions\Tools\MCP\Client\Contracts\TransportInterface;
use Exception;
use WebSocket\Client;

/**
 * A low-level class that handles the raw WebSocket connection,
 * sending JSON‑RPC messages and waiting for responses.
 */
class WSTransport implements TransportInterface
{
    private Client $client;
    private int $messageId = 0;

    /**
     * Connection constructor.
     *
     * @param string $wsUrl The WebSocket URL for the MCP endpoint.
     * @throws Exception
     */
    public function __construct(string $wsUrl)
    {
        $this->client = new Client($wsUrl);
    }

    /**
     * Closes the underlying WebSocket.
     */
    public function close(): void
    {
        if ($this->client) {
            $this->client->close();
        }
    }

    /**
     * Sends a JSON‑RPC message to the MCP server and waits for the matching response.
     *
     * Every message includes the "jsonrpc": "2.0" field so that it conforms to the protocol.
     *
     * @param string $method  The MCP method to invoke.
     * @param array  $params  The parameters for the method.
     * @param float  $timeout How long to wait (in seconds) for the response.
     *
     * @return array The decoded JSON response data.
     * @throws Exception If the timeout is exceeded or an error occurs.
     */
    public function sendMessage(string $method, array $params = [], float $timeout = 30.0): array
    {
        $this->messageId++;
        $command = [
            'jsonrpc' => '2.0',
            'id'      => $this->messageId,
            'method'  => $method,
        ];

        if (!empty($params)) {
            $command['params'] = $params;
        }

        // Send the JSON-encoded message.
        $this->client->text(json_encode($command));

        return $this->waitForResponse($this->messageId, $timeout);
    }

    public function sendMessageWithoutResponse(string $method, array $params = []): void
    {
        $this->messageId++;
        $command = [
            'jsonrpc' => '2.0',
            'id'      => $this->messageId,
            'method'  => $method,
        ];

        if (!empty($params)) {
            $command['params'] = $params;
        }
        // Send the JSON-encoded message.
        $this->client->text(json_encode($command));
    }

    /**
     * Waits for a response message with the specified command ID.
     *
     * @param int   $commandId The ID of the command to wait for.
     * @param float $timeout   Maximum time in seconds to wait.
     *
     * @return array The response data.
     * @throws Exception If the timeout is reached before the response is received.
     */
    public function waitForResponse(int $commandId, float $timeout = 30.0): array
    {
        $startTime = microtime(true);

        while (true) {
            if ((microtime(true) - $startTime) > $timeout) {
                throw new Exception("Timeout waiting for response with id: {$commandId}");
            }

            $message = $this->client->receive();

            $content = is_string($message)
                ? $message
                : (is_object($message) && method_exists($message, 'getContent') ? $message->getContent() : '');
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Failed to decode JSON: " . json_last_error_msg());
            }

            // Check if this message is the response to our command.
            if (isset($data['id']) && $data['id'] === $commandId) {
                return $data;
            }
            // Otherwise, skip event messages.
        }
    }
}