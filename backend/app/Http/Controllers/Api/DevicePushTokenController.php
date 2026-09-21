<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DevicePushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:ios,android'],
        ]);

        $userId = $request->user()->id;

        // One FCM token belongs to one device — reassign if another account had it.
        DevicePushToken::query()
            ->where('token', $data['token'])
            ->where('user_id', '!=', $userId)
            ->delete();

        DevicePushToken::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'token' => $data['token'],
            ],
            ['platform' => $data['platform']],
        );

        return response()->json(['message' => 'Đã lưu token push.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DevicePushToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['message' => 'Đã xóa token push.']);
    }
}
