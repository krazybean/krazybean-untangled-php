<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled;

/**
 * Something Untangled tells the user about, as spec/diagnostics.md defines it: a level, a stable
 * code for the condition, a message for people, and the project-relative files it is about.
 */
final class Diagnostic
{
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @param list<string> $files project-relative paths with `/`, in the order they are reported */
    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $message,
        public readonly array $files = [],
    ) {
    }

    /** @param list<string> $files */
    public static function warning(string $code, string $message, array $files = []): self
    {
        return new self(self::WARNING, $code, $message, $files);
    }

    /** @param list<string> $files */
    public static function error(string $code, string $message, array $files = []): self
    {
        return new self(self::ERROR, $code, $message, $files);
    }

    /** @return array{level: string, code: string, message: string, files?: list<string>} */
    public function toArray(): array
    {
        $data = ['level' => $this->level, 'code' => $this->code, 'message' => $this->message];
        if ($this->files !== []) {
            $data['files'] = $this->files;
        }
        return $data;
    }

    /** The JSON Lines form: one compact object, no line break. */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /** The text form: `warning: <message>` or `error: <message>`, without the line break. */
    public function toText(): string
    {
        return "{$this->level}: {$this->message}";
    }
}
