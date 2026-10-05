<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Controller;
use Tests\TestCase;

/**
 * Every backend failure message must carry a stable machine code for the
 * translated frontend error map. Mirrors Controller::fail() resolution
 * (explicit `errors.code` payloads, exact match, then prefix match).
 * When adding a fail(), add its code here AND to the frontend
 * API_ERROR_CODES list + ar/en/fr strings (pinned over there).
 */
class ErrorCodesCoverageTest extends TestCase
{
    public function test_every_fail_message_resolves_to_a_code(): void
    {
        $map = $this->errorCodes();
        $missing = [];
        foreach ($this->failMessages() as $file => $messages) {
            foreach ($messages as $message) {
                if ($this->resolve($map, $message) === null) {
                    $missing[] = "$file: $message";
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_explicit_code_payloads_use_known_codes(): void
    {
        $map = $this->errorCodes();
        $known = array_values($map);
        $bad = [];
        foreach (glob(app_path('Http/Controllers/Api/V1/*.php')) as $file) {
            $src = file_get_contents($file);
            preg_match_all("/'code'\s*=>\s*'([A-Z_]+)'/", $src, $m);
            foreach ($m[1] as $code) {
                if (! in_array($code, $known, true) && ! in_array($code, $this->frontendList(), true)) {
                    $bad[] = basename($file) . ": $code";
                }
            }
        }
        $this->assertSame([], $bad);
    }

    /** @return array<string,string> message => code */
    private function errorCodes(): array
    {
        $ref = new \ReflectionClassConstant(Controller::class, 'ERROR_CODES');

        return $ref->getValue();
    }

    private function resolve(array $map, string $message): ?string
    {
        foreach ($map as $text => $code) {
            if ($message === $text || str_starts_with($message, $text)) return $code;
        }

        return null;
    }

    /** @return array<string,array{string}> file => fail() literals */
    private function failMessages(): array
    {
        $out = [];
        foreach (glob(app_path('Http/Controllers/Api/V1/*.php')) as $file) {
            $src = file_get_contents($file);
            // Full fail(...) calls (non-greedy to the closing ");), so we can
            // see whether the call carries an explicit errors.code payload.
            preg_match_all('/->fail\((.*?)\);/s', $src, $calls);
            foreach ($calls[1] as $call) {
                // Explicit machine codes and field-token bags are covered by
                // the second test / the frontend field-token map.
                if (preg_match("/'code'\s*=>\s*'([A-Z_]+)'/", $call)) continue;
                if (str_contains($call, 'email_taken') || str_contains($call, 'in_waiting_room')) continue;
                if (preg_match("/->fail\(\s*'((?:[^'\\\\]|\\\\.)*)'/", '->fail(' . $call, $m)) {
                    $out[basename($file)][] = stripcslashes($m[1]);
                } elseif (preg_match('/->fail\(\s*"((?:[^"\\\\]|\\\\.)*)"/', '->fail(' . $call, $m)) {
                    $out[basename($file)][] = stripcslashes($m[1]);
                }
            }
        }

        return $out;
    }

    /** Codes the suite itself injects (mirrors the frontend list for these). */
    private function frontendList(): array
    {
        return ['NEED_REPLACER', 'ERROR'];
    }
}
