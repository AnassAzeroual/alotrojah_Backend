<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller extends BaseController
{
    use AuthorizesRequests;

    /**
     * Machine codes for the frontend `apiErrors` map (Item 7). Every 4xx
     * carries `errors.code`; explicit codes (e.g. NEED_REPLACER payloads)
     * are never overwritten. Interpolated messages match by prefix.
     */
    protected const array ERROR_CODES = [
        'Student is in another center.' => 'CROSS_CENTER',
        'Group belongs to another center.' => 'CROSS_CENTER',
        'Group is in another center.' => 'CROSS_CENTER',
        'Teacher must belong to the same center.' => 'CROSS_CENTER',
        'Only admin can create admins.' => 'ADMIN_ONLY',
        'Only admin can assign admin role.' => 'ADMIN_ONLY',
        'Centers cannot be deleted.' => 'ADMIN_ONLY',
        'Groups cannot be deleted.' => 'ADMIN_ONLY',
        'You may only address your own students.' => 'OWN_STUDENTS',
        'The replacer cannot be the deleted teacher.' => 'REPLACER_SELF',
        'The replacer must be an active teacher.' => 'REPLACER_INACTIVE',
        'The replacer must be in the same center.' => 'REPLACER_CENTER',
        'The replacer must have the same type (or both).' => 'REPLACER_TYPE',
        'The replacer already owns an active group.' => 'REPLACER_BUSY',
        'Every weight must belong to this exam.' => 'WEIGHTS_FOREIGN',
        'A recorded score exceeds its new weight.' => 'SCORE_OVER_MAX',
        'Score exceeds the question weight.' => 'SCORE_OVER_MAX',
        'Weights must total 20' => 'WEIGHTS_TOTAL',
        'Season has recorded facts and cannot be deleted.' => 'SEASON_HAS_FACTS',
        'Module has recorded scores and cannot be deleted.' => 'MODULE_HAS_SCORES',
        'A term is required for this exam type.' => 'TERM_REQUIRED',
        'Review must cover 1 to 3 weeks.' => 'SPAN_INVALID',
        'End hizb must be >= start hizb.' => 'RANGE_INVALID',
        'Surah range needs surah + ayah on both ends.' => 'RANGE_INVALID',
        'Range end must be after range start.' => 'RANGE_INVALID',
        'Group assignment only applies to teachers and students.' => 'GROUP_ROLE',
        'Weekly total would be' => 'SCORING_TOTAL',
        'Invalid credentials.' => 'INVALID_CREDENTIALS',
    ];
    protected function ok(mixed $data = null, ?string $message = null): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], 201);
    }

    protected function fail(string $message, int $code = 400, mixed $errors = null): JsonResponse
    {
        if (! is_array($errors)) $errors = [];
        if (! isset($errors['code'])) {
            foreach (static::ERROR_CODES as $text => $mapped) {
                if ($message === $text || str_starts_with($message, $text)) {
                    $errors['code'] = $mapped;
                    break;
                }
            }
            $errors['code'] ??= 'ERROR';
        }

        return response()->json(['success' => false, 'message' => $message, 'errors' => $errors], $code);
    }
}
