<?php

namespace OpenFunctions\Tools\MCP\Client\Contracts;

use Exception;

/**
 * Transport interface.
 */
interface TransportInterface
{
    /**
     * Closes the transport.
     */
    public function close(): void;

    /**
     * Sends a JSON‑RPC message over the transport.
     *
     * @param string $method
     * @param array $params
     * @return array
     * @throws Exception on error.
     */
    public function sendMessage(string $method, array $params = []): array;

    public function sendMessageWithoutResponse(string $method, array $params = []): void;
}
