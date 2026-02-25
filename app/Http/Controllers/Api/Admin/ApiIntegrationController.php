<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\SmsGateway;
use App\Models\Courierapi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ApiIntegrationController extends Controller
{
    public function paymentIndex()
    {
        $bkash = PaymentGateway::where('type', 'bkash')->first();
        $shurjopay = PaymentGateway::where('type', 'shurjopay')->first();

        return response()->json([
            'success' => true,
            'bkash' => $bkash,
            'shurjopay' => $shurjopay,
        ]);
    }

    public function paymentUpdate(Request $request, $id)
    {
        $gateway = PaymentGateway::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->status ? 1 : 0;
        $gateway->update($data);

        return response()->json(['success' => true, 'data' => $gateway]);
    }

    public function smsIndex()
    {
        $sms = SmsGateway::first();
        return response()->json(['success' => true, 'data' => $sms]);
    }

    public function smsUpdate(Request $request, $id)
    {
        $sms = SmsGateway::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->status ? 1 : 0;
        $data['order'] = $request->order ? 1 : 0;
        $data['forget_pass'] = $request->forget_pass ? 1 : 0;
        $data['password_g'] = $request->password_g ? 1 : 0;
        $sms->update($data);

        return response()->json(['success' => true, 'data' => $sms]);
    }

    public function courierIndex()
    {
        $steadfast = Courierapi::where('type', 'steadfast')->first();
        $pathao = Courierapi::where('type', 'pathao')->first();

        return response()->json([
            'success' => true,
            'steadfast' => $steadfast,
            'pathao' => $pathao,
        ]);
    }

    public function courierUpdate(Request $request, $id)
    {
        $courier = Courierapi::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->status ? 1 : 0;
        $courier->update($data);

        return response()->json(['success' => true, 'data' => $courier]);
    }

    public function getPathaoToken(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $response = Http::post('https://api-hermes.pathao.com/aladdin/api/v1/issue-token', [
            'client_id' => $validated['client_id'],
            'client_secret' => $validated['client_secret'],
            'grant_type' => 'password',
            'username' => $validated['username'],
            'password' => $validated['password'],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return response()->json([
                'success' => true,
                'token' => $data['access_token'] ?? null,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $response->body(),
        ], $response->status());
    }
}
