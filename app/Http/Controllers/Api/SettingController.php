<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;

class SettingController extends Controller
{
    public function show()
    {
        $setting = GeneralSetting::where('status', 1)
            ->orderByDesc('id')
            ->first();

        if (!$setting) {
            $setting = GeneralSetting::orderByDesc('id')->first();
        }

        return response()->json([
            'success' => true,
            'data' => $setting,
        ]);
    }
}
