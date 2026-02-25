<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'area' => 'nullable|integer|exists:shipping_charges,id', // Legacy uses shipping_charges.id as area
            'district' => 'nullable|string|max:255',
            'payment_method' => 'nullable|string|in:cod,bkash,shurjopay',
            'email' => 'nullable|email',
            'note' => 'nullable|string',
        ];
    }

    protected function prepareForValidation(): void
    {
        $paymentMethod = $this->input('payment_method');
        if (is_string($paymentMethod)) {
            $normalized = strtolower(trim($paymentMethod));
            if (in_array($normalized, ['cash on delivery', 'cash_on_delivery', 'cash-on-delivery'], true)) {
                $normalized = 'cod';
            }
            $this->merge(['payment_method' => $normalized]);
        }

        $district = $this->input('district');
        if (is_numeric($district)) {
            $this->merge(['district' => (string) $district]);
        }
    }
}
