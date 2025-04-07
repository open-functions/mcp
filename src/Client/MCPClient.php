<?php

namespace OpenFunctions\Tools\MCP\Client;

use OpenFunctions\Tools\MCP\Client\Contracts\TransportInterface;
use Exception;

class MCPClient
{
    private TransportInterface $connection;

    /**
     * MCPClient constructor.
     *
     * @param string $wsUrl The WebSocket URL for the MCP server.
     */
    public function __construct(TransportInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Establishes the connection and performs the MCP initialization phase.
     *
     * This includes:
     * - Sending an "initialize" request with protocol version, client capabilities, and client info.
     * - Verifying that the server responds with a compatible protocol version and its capabilities.
     * - Sending an "initialized" notification to signal readiness for normal operations.
     *
     * @throws Exception If initialization fails or a protocol mismatch occurs.
     */
    public function connect(): void
    {
        // --- Initialization Phase ---
        $initParams = [
            "protocolVersion" => "2024-11-05",
            "capabilities"    => [
                "tools"    => ["listChanged" => false],
            ],
            "clientInfo"      => [
                "name"    => "OpenFunctionsMCP",
                "version" => "1.0.0"
            ]
        ];

        // Send the initialize request.
        $response = $this->connection->sendMessage("initialize", $initParams);

        // Handle potential error response.
        if (isset($response['error'])) {
            throw new Exception("Initialization error: " . json_encode($response['error']));
        }
        if (!isset($response['result'])) {
            throw new Exception("Initialization error: no result returned.");
        }

        // Check protocol version compatibility.
        if ($response['result']['protocolVersion'] !== "2024-11-05") {
            $this->close(); // Close the connection if versions are incompatible.
            throw new Exception("Protocol version mismatch. Server supports: " . $response['result']['protocolVersion']);
        }

        // Send the "initialized" notification indicating the client is ready.
        $this->connection->sendMessageWithoutResponse("notifications/initialized");

        // --- End Initialization ---
    }

    /**
     * Retrieves a paginated list of available tools from the MCP server.
     *
     * @param string|null $cursor Optional cursor value for pagination.
     * @return array The response containing "tools" and optionally "nextCursor".
     * @throws Exception
     */
    public function listTools(?string $cursor = null): array
    {
        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->connection->sendMessage("tools/list", $params);
    }

    /**
     * Calls a specific tool on the MCP server.
     *
     * @param string $name The name of the tool to invoke.
     * @param array  $arguments The arguments for the tool.
     *
     * @return array The response containing the result of the tool call.
     * @throws Exception
     */
    public function callTool(string $name, array $arguments = []): array
    {
        if (empty($arguments)) {
            $arguments = new \stdClass();
        }

        $params = [
            'name'      => $name,
            'arguments' => $arguments,
        ];

        return $this->connection->sendMessage("tools/call", $params);
    }

    /**
     * Closes the underlying WebSocket connection.
     *
     * This effectively performs the shutdown phase by terminating the transport.
     */
    public function close(): void
    {
        $this->connection->close();
    }
}