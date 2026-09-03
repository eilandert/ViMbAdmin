<?php

/** JSON-RPC protocol failure with its wire error code and correlatable id. */
final class ViMbAdmin_Mcp_ProtocolException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $rpcCode,
        private readonly mixed $rpcId = null,
        private readonly bool $respond = true
    ) {
        parent::__construct($message);
    }

    public function rpcCode(): int
    {
        return $this->rpcCode;
    }

    public function rpcId(): mixed
    {
        return $this->rpcId;
    }

    public function shouldRespond(): bool
    {
        return $this->respond;
    }
}
