<?php

/*
 * Plain Pest tests: no Laravel app is booted. They read the manifest and native
 * sources, and drive the PHP facade against the recording bridge below.
 */

/**
 * Stands in for the nativephp_call() function the NativePHP runtime provides on a
 * device: records every call and answers from scripted responses.
 */
final class RecordingBridge
{
    /** @var array<int, array{method: string, params: array<string, mixed>}> */
    public static array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public static array $responses = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$responses = [];
    }

    public static function respondTo(string $method, array $response): void
    {
        self::$responses[$method] = $response;
    }

    /** @return array<string, mixed>|null */
    public static function lastCall(): ?array
    {
        return self::$calls === [] ? null : end(self::$calls);
    }
}

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $method, string $params = '{}'): ?string
    {
        RecordingBridge::$calls[] = ['method' => $method, 'params' => json_decode($params, true) ?? []];

        return json_encode(RecordingBridge::$responses[$method] ?? ['status' => 'success', 'data' => ['status' => 'started']]);
    }
}

beforeEach(fn () => RecordingBridge::reset());
