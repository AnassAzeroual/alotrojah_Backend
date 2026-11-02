<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use RuntimeException;

abstract class BaseController
{
    /**
     * Machine codes for the frontend apiErrors map.
     */
    protected const array ERROR_CODES = [
        'Student is in another center.' => 'CROSS_CENTER',
        'Season belongs to another center.' => 'CROSS_CENTER',
        'Group belongs to another center.' => 'CROSS_CENTER',
        'Pupil account belongs to another center.' => 'CROSS_CENTER',
        'Choose a center for the pupil account.' => 'ACCOUNT_CENTER_REQUIRED',
        'Group is in another center.' => 'CROSS_CENTER',
        'Teacher must belong to the same center.' => 'CROSS_CENTER',
        'Only admin can create admins.' => 'ADMIN_ONLY',
        'Only admin can assign admin role.' => 'ADMIN_ONLY',
        'Centers cannot be deleted.' => 'ADMIN_ONLY',
        'Groups cannot be deleted.' => 'ADMIN_ONLY',
        'Level is used by pupils and cannot be deleted.' => 'LEVEL_IN_USE',
        'A level with identical values already exists.' => 'LEVEL_EXISTS',
        'No previous season to copy levels from.' => 'LEVEL_SOURCE_MISSING',
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
        'A season with this name already exists.' => 'SEASON_EXISTS',
        'Module has recorded scores and cannot be deleted.' => 'MODULE_HAS_SCORES',
        'A term is required for this exam type.' => 'TERM_REQUIRED',
        'A current season is required for weekly exams.' => 'SEASON_REQUIRED',
        'A weekly exam already exists for this pupil on this date.' => 'WEEKLY_EXISTS',
        'Review must cover 1 to 3 weeks.' => 'SPAN_INVALID',
        'End hizb must be >= start hizb.' => 'RANGE_INVALID',
        'Surah range needs surah + ayah on both ends.' => 'RANGE_INVALID',
        'Range end must be after range start.' => 'RANGE_INVALID',
        'Group assignment only applies to teachers and students.' => 'GROUP_ROLE',
        'End time must be after start time.' => 'SESSION_TIME_ORDER',
        'Student approval requires a group and a level.' => 'PLACEMENT_REQUIRED',
        'Admin accounts cannot be deleted.' => 'ADMIN_DELETE',
        'Admins cannot deactivate their own account.' => 'ADMIN_SELF_DISABLE',
        'The last active admin cannot be deactivated.' => 'ADMIN_LAST_ACTIVE',
        'Weekly total would be' => 'SCORING_TOTAL',
        'Invalid credentials.' => 'INVALID_CREDENTIALS',
        'Unauthenticated.' => 'UNAUTHENTICATED',
        'This action is unauthorized.' => 'UNAUTHORIZED',
    ];

    protected function ok(mixed $data = null, ?string $message = null): Response
    {
        return Response::ok($data, $message);
    }

    protected function created(mixed $data = null, ?string $message = null): Response
    {
        return Response::created($data, $message);
    }

    protected function fail(string $message, int $code = 400, array $errors = []): Response
    {
        if (!isset($errors['code'])) {
            foreach (static::ERROR_CODES as $text => $mapped) {
                if ($message === $text || str_starts_with($message, $text)) {
                    $errors['code'] = $mapped;
                    break;
                }
            }
            $errors['code'] ??= 'ERROR';
        }

        return Response::fail($message, $code, $errors);
    }

    /** Standard 422 shape used by the request validators in this backend. */
    protected function unprocessable(array $errors): Response
    {
        return $this->fail('The given data was invalid.', 422, $errors);
    }

    /**
     * Pagination payload for endpoints that build rows manually.
     *
     * @param list<array<string,mixed>> $data
     */
    protected function paginated(
        array $data,
        int $total,
        int $page,
        int $perPage,
        string $path = '',
    ): Response {
        $lastPage = (int) max(1, ceil($total / max(1, $perPage)));
        $offset = (max(1, $page) - 1) * max(1, $perPage);
        $from = $total > 0 ? $offset + 1 : null;
        $to = $total > 0 ? min($offset + count($data), $total) : null;

        return $this->ok([
            'data' => $data,
            'current_page' => max(1, $page),
            'per_page' => max(1, $perPage),
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
            'path' => $path,
        ]);
    }

    protected function validate(Request $request, array $rules): ?Response
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->fail('The given data was invalid.', 422, $validator->errors());
        }
        return null;
    }

    protected function user(Request $request): ?array
    {
        return $request->getUser();
    }

    protected function userId(Request $request): ?int
    {
        return $request->userId();
    }

    protected function role(Request $request): ?string
    {
        return $request->role();
    }

    protected function centerId(Request $request, bool $required = false): ?int
    {
        $role = $this->role($request);
        $userCenterId = $request->centerId();

        // Admins can pass center_id in query or body
        if ($role === 'admin') {
            $inputCenter = $request->input('center_id');
            if ($inputCenter !== null && $inputCenter !== '') {
                $id = (int) $inputCenter;
                $exists = Database::fetchValue("SELECT 1 FROM `centers` WHERE id = :id", ['id' => $id]);
                if (!$exists) {
                    throw new RuntimeException('The selected center is invalid.', 422);
                }
                return $id;
            }
            if ($required && $userCenterId === null) {
                throw new RuntimeException('Select a center first.', 422);
            }
        }

        if ($required && $userCenterId === null) {
            throw new RuntimeException('Select a center first.', 422);
        }

        return $userCenterId;
    }

    protected function authorizeRole(Request $request, array $allowedRoles): void
    {
        $role = $this->role($request);
        if (!$role || !in_array($role, $allowedRoles, true)) {
            throw new RuntimeException("This action is unauthorized.");
        }
    }

    protected function applyCenterScope(Request $request, string $table, array &$conditions, array &$params): void
    {
        $role = $this->role($request);
        if (in_array($role, ['admin', 'board'], true)) {
            // Admins can optionally filter by center_id
            $centerFilter = $request->input('center_id');
            if ($centerFilter !== null && $centerFilter !== '') {
                $conditions[] = "`{$table}`.center_id = :center_id_filter";
                $params['center_id_filter'] = (int) $centerFilter;
            }
            return;
        }

        if (in_array($role, ['supervisor', 'teacher'], true)) {
            $centerId = $request->centerId();
            if ($centerId !== null) {
                if ($table === 'centers') {
                    $conditions[] = "`{$table}`.id = :center_scope_id";
                } else {
                    $conditions[] = "`{$table}`.center_id = :center_scope_id";
                }
                $params['center_scope_id'] = $centerId;
            }
        }
    }
}
