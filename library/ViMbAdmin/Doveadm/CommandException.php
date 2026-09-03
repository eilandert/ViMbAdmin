<?php

/** Structured failure returned by a doveadm command. */
class ViMbAdmin_Doveadm_CommandException extends ViMbAdmin_Exception
{
    public function __construct(
        private readonly string $command,
        private readonly string $errorType,
        private readonly ?int $exitCode,
    ) {
        parent::__construct(sprintf(
            _("doveadm '%s' failed: %s (exit %s)"),
            $command,
            $errorType,
            $exitCode ?? '?',
        ));
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }
}
