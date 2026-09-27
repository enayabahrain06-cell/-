<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Authentication
 */
class AuthController extends Controller
{
    /**
     * Staff login (phone + password).
     *
     * Only users with a staff role (super_admin, supervisor, teacher) can log in with a password.
     * Students and guardians must use the WhatsApp OTP flow.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $phone = PhoneNumber::normalize($request->string('phone'));
        $user = $phone ? User::where('phone', $phone)->first() : null;

        if (! $user || ! $user->password || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['phone' => __('api.auth.failed')]);
        }

        if (! $user->hasAnyRole(config('ahl.staff_roles'))) {
            throw ValidationException::withMessages(['phone' => __('api.auth.use_otp')]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['phone' => __('api.auth.inactive')]);
        }

        return $this->issueToken($user, $request->string('device', 'web'));
    }

    /**
     * Request a WhatsApp OTP (students and guardians).
     *
     * The code expires after 5 minutes and allows 3 attempts.
     */
    public function requestOtp(OtpRequestRequest $request, OtpService $otp): JsonResponse
    {
        $phone = PhoneNumber::normalize($request->string('phone'));
        $user = $phone ? User::where('phone', $phone)->first() : null;

        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['phone' => __('api.auth.phone_not_registered')]);
        }

        $code = $otp->issue($user);

        $payload = [
            'message' => __('api.otp.sent'),
            'phone' => $phone,
            'expires_at' => $code->expires_at->toIso8601String(),
        ];

        if (app()->environment('local', 'testing') && config('whatsapp.provider') === 'log') {
            $payload['debug_code'] = $code->getAttribute('plain_code');
        }

        return response()->json($payload);
    }

    /** Verify the OTP and receive a token. */
    public function verifyOtp(OtpVerifyRequest $request, OtpService $otp): JsonResponse
    {
        $phone = PhoneNumber::normalize($request->string('phone'));
        $user = $phone ? User::where('phone', $phone)->first() : null;

        if (! $user) {
            throw ValidationException::withMessages(['phone' => __('api.auth.phone_not_registered')]);
        }

        $otp->verify($phone, PhoneNumber::toLatinDigits($request->string('code')));

        if (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return $this->issueToken($user, $request->string('device', 'web'));
    }

    /** Current user with roles, permissions and linked student/children. */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['roles', 'teacher', 'student', 'children']));
    }

    /** Update the current user's preferred language. */
    public function updateLocale(Request $request): UserResource
    {
        $data = $request->validate(['locale' => ['required', Locale::rule()]]);
        $request->user()->update(['locale' => $data['locale']]);

        return new UserResource($request->user()->fresh()->load(['roles', 'teacher', 'student', 'children']));
    }

    /** Revoke the current token. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => __('api.auth.logged_out')]);
    }

    private function issueToken(User $user, string $device): JsonResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($device)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load(['roles', 'teacher', 'student', 'children'])),
        ]);
    }
}
