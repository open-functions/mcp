<?php

namespace OpenFunctions\Tools\MCP;

use OpenFunctions\Core\Contracts\AbstractOpenFunction;
use OpenFunctions\Core\Responses\Items\AudioResponseItem;
use OpenFunctions\Core\Responses\Items\ImageResponseItem;
use OpenFunctions\Core\Responses\Items\TextResponseItem;
use OpenFunctions\Core\Responses\OpenFunctionResponse;
use OpenFunctions\Tools\MCP\Client\MCPClient;

class MCPOpenFunction extends AbstractOpenFunction
{
    private MCPClient $client;

    protected ?array $functionDefinitions = null;

    public function __construct(MCPClient $client)
    {
        $this->client = $client;
        $this->client->connect();
    }

    public function callMethod(string $methodName, array $arguments = []): OpenFunctionResponse
    {
        try {
            // If an interceptor callback is set, use its result if not null.
            if ($this->interceptorCallback !== null) {
                $callbackResult = call_user_func($this->interceptorCallback, $methodName, $arguments);
                if ($callbackResult !== null) {
                    return $this->wrapResult($callbackResult);
                }
            }

            $content = [];

            $mcpResponse = $this->client->callTool($methodName, $arguments);

            if (isset($mcpResponse['error'])) {
                $isError = true;
                $content[] = new TextResponseItem($mcpResponse['error']['message']);
            } else {
                $isError = $mcpResponse['result']['isError'] ?? false;

                foreach ($mcpResponse['result']['content'] as $element) {
                    if ($element['type'] === 'text') {
                        $content[] = new TextResponseItem($element['text']);
                    } else if ($element['type'] === 'image') {
                        $content[] = new ImageResponseItem($element['data'], $element['mimeType']);
                    } else if ($element['type'] === 'audio') {
                        $content[] = new AudioResponseItem($element['data'], $element['mimeType']);
                    }
                }
            }

            return new OpenFunctionResponse($isError ? OpenFunctionResponse::STATUS_ERROR : OpenFunctionResponse::STATUS_SUCCESS, $content);
        } catch (\Throwable $e) {
            // Wrap exceptions as error responses.
            $errorItem = new TextResponseItem("An error occurred: " . $e->getMessage());
            return new OpenFunctionResponse(OpenFunctionResponse::STATUS_ERROR, [$errorItem]);
        }
    }

    public function generateFunctionDefinitions(): array
    {
        if (is_array($this->functionDefinitions)) {
            return $this->functionDefinitions;
        }

        $this->functionDefinitions = [];
        $response = $this->client->listTools();

        foreach ($response['result']['tools'] as $toolDefinitions) {

            $definition = [
                'type' => 'function',
                'name' => $toolDefinitions['name'],
                'description' => $toolDefinitions['description'],
                'parameters' => $toolDefinitions['inputSchema'] ?? [],
                'strict' => false
            ];

            if (isset($toolDefinitions['inputSchema']['properties']) && empty($toolDefinitions['inputSchema']['properties'])) {
                unset($definition['parameters']);
            }

            $this->functionDefinitions[] = $definition;
        }

        return $this->functionDefinitions;
    }
}