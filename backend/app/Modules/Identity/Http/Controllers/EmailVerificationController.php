<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /** Re-send the verification email. */
    public function send(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => __('auth.already_verified')]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => __('auth.verification_sent')], 202);
    }

    /** Verify via the signed link. */
    public function verify(EmailVerificationRequest $request, AuditLogger $audit): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail() && $request->user()->markEmailAsVerified()) {
            $audit->log('auth.email_verified', [
                'actor' => $request->user(),
                'subject' => $request->user(),
            ]);
        }

        return response()->json(['message' => __('auth.verified')]);
    }
}
