<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    /** Invite a user to the active tenant (invitation architecture foundation). */
    public function store(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower($data['email']);

        // Link to an existing global user if one already has this email.
        $existing = User::query()->where('email', $email)->first();

        $duplicate = TenantMembership::query()
            ->where(fn ($q) => $q->where('invited_email', $email)
                ->orWhere('user_id', $existing?->getKey()))
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => __('users.already_member')], 422);
        }

        $membership = TenantMembership::query()->create([
            'user_id' => $existing?->getKey(),
            'status' => 'invited',
            'invited_email' => $email,
            'invited_by' => $request->user()->getKey(),
            'invitation_token' => Str::random(48),
        ]);

        $audit->log('user.invited', [
            'actor' => $request->user(),
            'subject' => $membership,
            'metadata' => ['invited_email' => $email],
        ]);

        return response()->json([
            'membership_id' => $membership->id,
            'status' => $membership->status,
            'invited_email' => $membership->invited_email,
        ], 201);
    }
}
